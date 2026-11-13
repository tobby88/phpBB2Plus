<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }

class PhpbbBookmarkNavigationFailure extends RuntimeException {}

// Optional header metadata uses one fresh, coherent read-only snapshot. It
// neither joins a caller transaction nor acquires the preference writer mutex.
class PhpbbBookmarkNavigationReader
{
	private $connection = null;
	private $identity = null;
	private $transaction = false;

	private function fail() { throw new PhpbbBookmarkNavigationFailure('Bookmark navigation unavailable'); }
	private function identity($database)
	{
		$result = $database->sql_query('SELECT CONNECTION_ID() id,DATABASE() schema_name,@@SESSION.autocommit automatic');
		if (!$result) { $this->fail(); }
		try { $rows = $database->sql_fetchrowset($result); }
		finally { $database->sql_freeresult($result); }
		if (!is_array($rows) || count($rows) !== 1 || !isset($rows[0]['id'], $rows[0]['schema_name'], $rows[0]['automatic'])
			|| !is_string($rows[0]['schema_name']) || $rows[0]['schema_name'] === ''
			|| !preg_match('/^[1-9][0-9]*$/D', (string)$rows[0]['id'])) { $this->fail(); }
		return $rows[0];
	}
	function __construct($database)
	{
		try
		{
			if (!is_object($database) || !method_exists($database, 'sql_dedicated_connection')) { $this->fail(); }
			$source = $this->identity($database); $candidate = $database->sql_dedicated_connection();
			if (!is_object($candidate) || $candidate === $database || empty($candidate->db_connect_id)
				|| $candidate->db_connect_id === $database->db_connect_id) { $this->fail(); }
			$own = $this->identity($candidate);
			if ($own['schema_name'] !== $source['schema_name'] || (string)$own['id'] === (string)$source['id']
				|| (string)$own['automatic'] !== '1') { $this->fail(); }
			// This characteristic fails inside a borrowed active transaction;
			// never start, close or roll back that other caller's work.
			if (!$candidate->sql_query('SET TRANSACTION READ ONLY')) { $this->fail(); }
			$this->connection = $candidate; $this->identity = (string)$own['id'];
			$this->control('SET SESSION lock_wait_timeout=3');
			$this->control('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
			$this->control('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY'); $this->transaction = true;
			$tables = array(CONFIG_TABLE, SESSIONS_TABLE, USERS_TABLE, BANLIST_TABLE, BOOKMARK_TABLE,
				FORUMS_TABLE, TOPICS_TABLE, POSTS_TABLE, USER_GROUP_TABLE, AUTH_ACCESS_TABLE);
			$pins = $names = array();
			foreach ($tables as $i => $table)
			{
				if (!is_string($table) || !preg_match('/^[A-Za-z0-9_]{1,64}$/D', $table)) { $this->fail(); }
				$pins[] = $table . ' bookmark_pin_' . $i; $names[] = "'" . $table . "'";
			}
			// Open all participants before checking their metadata. These shared
			// metadata locks last for the snapshot, including empty tables.
			$this->rows('SELECT 1 FROM ' . implode(',', $pins) . ' LIMIT 0');
			$scope = 'TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (' . implode(',', $names) . ')';
			$metadata = $this->rows('SELECT TABLE_NAME,ENGINE,ROW_FORMAT,TABLE_COLLATION FROM information_schema.TABLES WHERE ' . $scope . " AND TABLE_TYPE='BASE TABLE'");
			if (count($metadata) !== count($tables)) { $this->fail(); }
			foreach ($metadata as $row)
			{
				if (!in_array($row['TABLE_NAME'], $tables, true) || $row['ENGINE'] !== 'InnoDB'
					|| strtolower($row['ROW_FORMAT']) !== 'dynamic' || $row['TABLE_COLLATION'] !== 'utf8mb4_unicode_ci') { $this->fail(); }
			}
			if ($this->rows('SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE ' . $scope
				. " AND CHARACTER_SET_NAME IS NOT NULL AND (CHARACTER_SET_NAME<>'utf8mb4' OR COLLATION_NAME<>'utf8mb4_unicode_ci') LIMIT 1")) { $this->fail(); }
		}
		catch (Exception $error) { $this->release(); throw $error; }
		catch (Error $error) { $this->release(); throw $error; }
	}
	private function control($sql)
	{
		if (!$this->connection) { $this->fail(); }
		$result = $this->connection->sql_query($sql); if (!$result) { $this->fail(); }
		return $result;
	}
	function sql_query($sql, $transaction = false)
	{
		if (!$this->transaction || !is_string($sql) || strpos($sql, 'SELECT ') !== 0 || $transaction) { $this->fail(); }
		$result = $this->control('SELECT CONNECTION_ID() id');
		try { $row = $this->connection->sql_fetchrow($result); }
		finally { $this->connection->sql_freeresult($result); }
		if (!$row || (string)$row['id'] !== $this->identity) { $this->fail(); }
		return $this->control($sql);
	}
	function rows($sql)
	{
		$result = $this->sql_query($sql);
		try { $rows = $this->connection->sql_fetchrowset($result); }
		finally { $this->connection->sql_freeresult($result); }
		if (!is_array($rows)) { $this->fail(); }
		return $rows;
	}
	function sql_fetchrow($result) { return $this->connection->sql_fetchrow($result); }
	function sql_fetchrowset($result) { return $this->connection->sql_fetchrowset($result); }
	function sql_freeresult($result) { return $this->connection->sql_freeresult($result); }
	function sql_escape($value) { return $this->connection->sql_escape((string)$value); }
	function release()
	{
		$connection = $this->connection; $this->connection = null;
		if ($connection)
		{
			try { if ($this->transaction) { $connection->sql_query('ROLLBACK'); } } catch (Exception $ignored) {} catch (Error $ignored) {}
			$this->transaction = false;
			try { $connection->sql_close(); } catch (Exception $ignored) {} catch (Error $ignored) {}
		}
	}
}

function phpbb_bookmark_navigation_limit($value)
{
	return (is_int($value) || is_string($value)) && preg_match('/^[1-9][0-9]{0,3}$/D', (string)$value)
		&& (int)$value <= 1000 ? (int)$value : 0;
}
function phpbb_bookmark_navigation_rows($database, $actor, $limit)
{
	$limit = phpbb_bookmark_navigation_limit($limit);
	if (!$limit || !is_array($actor) || !isset($actor['session_logged_in'], $actor['user_id'], $actor['session_id'])
		|| !in_array($actor['session_logged_in'], array(true, 1, '1'), true)
		|| !(is_int($actor['user_id']) || is_string($actor['user_id']))
		|| !preg_match('/^[1-9][0-9]{0,6}$/D', (string)$actor['user_id']) || (int)$actor['user_id'] > 8388607
		|| !is_string($actor['session_id']) || $actor['session_id'] === '' || strlen($actor['session_id']) > 32
		|| strpos($actor['session_id'], "\0") !== false) { return array(); }
	$reader = null;
	try
	{
		$reader = new PhpbbBookmarkNavigationReader($database); $id = (int)$actor['user_id']; $sid = $reader->sql_escape($actor['session_id']);
		$users = $reader->rows('SELECT u.user_id,u.user_level,u.user_active,u.user_blocktime,s.session_time,c.config_value session_length'
			. ' FROM ' . USERS_TABLE . ' u JOIN ' . SESSIONS_TABLE . ' s ON s.session_user_id=u.user_id AND s.session_logged_in=1'
			. " AND s.session_id='$sid' AND HEX(s.session_id)=HEX('$sid') JOIN " . CONFIG_TABLE . " c ON c.config_name='session_length' AND HEX(c.config_name)=HEX('session_length')"
			. ' WHERE u.user_id=' . $id . ' AND NOT EXISTS(SELECT 1 FROM ' . BANLIST_TABLE . ' b WHERE b.ban_userid=u.user_id)');
		if (count($users) !== 1) { return array(); } $user = $users[0];
		if ((string)$user['user_active'] !== '1' || !in_array((string)$user['user_level'], array((string)USER, (string)MOD, (string)ADMIN), true)
			|| !preg_match('/^-?[0-9]+$/D', (string)$user['user_blocktime']) || (float)$user['user_blocktime'] >= time()
			|| !preg_match('/^[1-9][0-9]{0,9}$/D', (string)$user['session_length']) || (float)$user['session_length'] > 2147483647
			|| !preg_match('/^(?:0|[1-9][0-9]{0,9})$/D', (string)$user['session_time']) || (float)$user['session_time'] > 2147483647
			|| (float)$user['session_time'] < time() - (int)$user['session_length']) { return array(); }
		$user['session_logged_in'] = true; $user['session_id'] = $actor['session_id'];
		$permissions = auth(AUTH_ALL, AUTH_LIST_ALL, $user, '', $reader); $forums = array();
		foreach ($permissions as $forum => $access) { if ((int)$forum > 0 && !empty($access['auth_view']) && !empty($access['auth_read'])) { $forums[] = (int)$forum; } }
		if (!$forums) { return array(); }
		return $reader->rows('SELECT t.topic_id,t.topic_title,p2.post_time FROM ' . TOPICS_TABLE . ' t'
			. ' JOIN ' . FORUMS_TABLE . ' f ON f.forum_id=t.forum_id JOIN ' . USERS_TABLE . ' u ON u.user_id=t.topic_poster'
			. ' JOIN ' . POSTS_TABLE . ' p ON p.post_id=t.topic_first_post_id AND p.topic_id=t.topic_id AND p.forum_id=t.forum_id'
			. ' JOIN ' . POSTS_TABLE . ' p2 ON p2.post_id=t.topic_last_post_id AND p2.topic_id=t.topic_id AND p2.forum_id=t.forum_id'
			. ' JOIN ' . USERS_TABLE . ' u2 ON u2.user_id=p2.poster_id WHERE t.topic_id>0 AND t.forum_id IN (' . implode(',', $forums) . ')'
			. ' AND t.topic_moved_id=0 AND t.topic_status IN (' . (int)TOPIC_UNLOCKED . ',' . (int)TOPIC_LOCKED . ')'
			. ' AND EXISTS(SELECT 1 FROM ' . BOOKMARK_TABLE . ' b WHERE b.topic_id=t.topic_id AND b.user_id=' . $id . ')'
			. ' AND EXISTS(SELECT 1 FROM ' . POSTS_TABLE . ' live_post WHERE live_post.topic_id=t.topic_id AND live_post.forum_id=t.forum_id AND live_post.post_deleted=0)'
			. ' ORDER BY p2.post_time DESC,t.topic_id DESC LIMIT ' . ($limit + 1));
	}
	// Bookmark navigation is optional. A failed read must expose no titles and
	// must not prevent the actual forum page from rendering.
	catch (Exception $error) { return array(); }
	catch (Error $error) { return array(); }
	finally { if ($reader) { $reader->release(); } }
}
