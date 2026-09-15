<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }

class PhpbbPostSubmitException extends RuntimeException {}

// New topics and replies publish content, polls, index and counters together.
// Attachment files and notifications are deliberately handled after COMMIT.
class PhpbbPostSubmitDatabase
{
	var $connection;
	var $mode;
	var $forum_id;
	var $topic_id;
	var $required;
	var $transactional = false;
	var $confirmed = false;
	function __construct($connection, $mode, $forum_id, $topic_id, $required)
	{
		$this->connection = $connection; $this->mode = $mode;
		$this->forum_id = phpbb_posting_scope_id($forum_id);
		$this->topic_id = $mode !== 'newtopic' ? phpbb_posting_scope_id($topic_id) : 0;
		$this->required = $required;
	}
	function __call($method, $args) { return call_user_func_array(array($this->connection, $method), $args); }
	function fail($key) { throw new PhpbbPostSubmitException($key); }
	function control($sql)
	{
		$result = $this->connection->sql_query($sql);
		if (!$result) { $this->fail('Posting_submit_unconfirmed'); }
		return $result;
	}
	function sql_query($sql, $transaction = false)
	{
		if (!is_string($sql) || !preg_match('/^\s*(SELECT|INSERT|UPDATE|DELETE)\b/i', $sql)) { $this->fail('Posting_submit_unconfirmed'); }
		if (preg_match('/^\s*(INSERT|UPDATE|DELETE)\b/i', $sql))
		{
			if (!$this->transactional) { $this->fail('Posting_submit_unconfirmed'); }
			$this->authorize();
		}
		return $this->control($sql);
	}
	function rows($sql)
	{
		$r = $this->sql_query($sql); $rows = $this->sql_fetchrowset($r); $this->sql_freeresult($r); return $rows;
	}
	function actor()
	{
		global $userdata;
		if (!isset($userdata['user_id'], $userdata['session_id']) || !is_string($userdata['session_id']) || $userdata['session_id'] === '') { $this->fail('Session_invalid'); }
		$id = $userdata['user_id'];
		if ((!is_int($id) && !is_string($id)) || !preg_match('/^-?[0-9]+$/D', (string)$id) || ((int)$id !== ANONYMOUS && ((int)$id < 1 || (int)$id > 16777215))) { $this->fail('Session_invalid'); }
		$id = (int)$id; $guest = $id === ANONYMOUS;
		if ($guest === !empty($userdata['session_logged_in'])) { $this->fail('Session_invalid'); }
		$sid = $this->sql_escape($userdata['session_id']);
		if (!$this->rows('SELECT session_id FROM ' . SESSIONS_TABLE . " WHERE session_id = '$sid' AND HEX(session_id) = HEX('$sid') AND session_user_id = " . $id . ' AND session_logged_in = ' . ($guest ? 0 : 1))) { $this->fail('Session_invalid'); }
		$rows = $this->rows('SELECT user_id, user_level, user_active FROM ' . USERS_TABLE . ' WHERE user_id = ' . $id);
		if (count($rows) !== 1 || (!$guest && empty($rows[0]['user_active']))) { $this->fail('Session_invalid'); }
		$user = $rows[0]; $user['session_logged_in'] = !$guest;
		if ($guest) { $user['user_level'] = 0; }
		return $user;
	}
	function authorize()
	{
		$user = $this->actor();
		$rows = $this->rows('SELECT forum_status FROM ' . FORUMS_TABLE . ' WHERE forum_id = ' . $this->forum_id);
		if (count($rows) !== 1) { $this->fail('Topic_post_not_exist'); }
		$auth = auth(AUTH_ALL, $this->forum_id, $user, '', $this);
		foreach (array_merge(array('auth_view', 'auth_read'), $this->required) as $key)
		{
			if (empty($auth[$key])) { $this->fail('Posting_submit_denied'); }
		}
		if (empty($auth['auth_mod']) && (int)$rows[0]['forum_status'] === FORUM_LOCKED) { $this->fail('Forum_locked'); }
		if ($this->mode === 'reply')
		{
			$rows = $this->rows('SELECT topic_status, topic_moved_id FROM ' . TOPICS_TABLE . ' WHERE topic_id = ' . $this->topic_id . ' AND forum_id = ' . $this->forum_id);
			if (count($rows) !== 1 || !empty($rows[0]['topic_moved_id'])) { $this->fail('Topic_post_not_exist'); }
			if (empty($auth['auth_mod']) && (int)$rows[0]['topic_status'] === TOPIC_LOCKED) { $this->fail('Topic_locked'); }
		}
		return $auth;
	}
	function tables()
	{
		return array(USERS_TABLE, SESSIONS_TABLE, FORUMS_TABLE, TOPICS_TABLE, POSTS_TABLE, POSTS_TEXT_TABLE, USER_GROUP_TABLE, AUTH_ACCESS_TABLE, SEARCH_WORD_TABLE, SEARCH_MATCH_TABLE, CONFIG_TABLE, VOTE_DESC_TABLE, VOTE_RESULTS_TABLE);
	}
	function begin()
	{
		$this->actor();
		$this->control("SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_ALL_TABLES')");
		$this->control('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
		$this->control('START TRANSACTION'); $this->transactional = true;
		foreach ($this->tables() as $table)
		{
			$r = $this->sql_query('SELECT * FROM ' . $table . ' LIMIT 0'); $this->sql_freeresult($r);
			$table_name = $this->sql_escape($table);
			$word_binary = "(c.TABLE_NAME = '" . $this->sql_escape(SEARCH_WORD_TABLE) . "' AND c.COLUMN_NAME = 'word_text' AND c.COLLATION_NAME = 'utf8mb4_bin')";
			// Literal schema/table restrictions let MariaDB prune metadata scans.
			// Keep the same column policy and transaction-held metadata locks.
			$rows = $this->rows("SELECT ENGINE, ROW_FORMAT, TABLE_COLLATION FROM information_schema.TABLES t WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . $table_name . "'"
				. " AND NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS c WHERE c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME='" . $table_name . "' AND c.CHARACTER_SET_NAME IS NOT NULL AND (c.CHARACTER_SET_NAME <> 'utf8mb4' OR (c.COLLATION_NAME <> 'utf8mb4_unicode_ci' AND NOT " . $word_binary . ")))");
			if (count($rows) !== 1 || $rows[0]['ENGINE'] !== 'InnoDB' || strtolower($rows[0]['ROW_FORMAT']) !== 'dynamic' || $rows[0]['TABLE_COLLATION'] !== 'utf8mb4_unicode_ci') { $this->fail('Posting_submit_upgrade'); }
		}
		$this->rows('SELECT forum_id FROM ' . FORUMS_TABLE . ' WHERE forum_id = ' . $this->forum_id . ' FOR UPDATE');
		if ($this->topic_id) { $this->rows('SELECT topic_id FROM ' . TOPICS_TABLE . ' WHERE topic_id = ' . $this->topic_id . ' FOR UPDATE'); }
		$this->authorize();
	}
	function commit()
	{
		global $userdata;
		$user = $this->actor(); $id = (int)$user['user_id']; $sid = $this->sql_escape($userdata['session_id']);
		$this->rows('SELECT session_id FROM ' . SESSIONS_TABLE . " WHERE session_id = '$sid' AND HEX(session_id) = HEX('$sid') LOCK IN SHARE MODE");
		$this->rows('SELECT user_id FROM ' . USERS_TABLE . ' WHERE user_id = ' . $id . ' LOCK IN SHARE MODE');
		$this->rows('SELECT group_id FROM ' . USER_GROUP_TABLE . ' WHERE user_id = ' . $id . ' ORDER BY group_id LOCK IN SHARE MODE');
		$this->rows('SELECT group_id FROM ' . AUTH_ACCESS_TABLE . ' WHERE forum_id = ' . $this->forum_id . ' ORDER BY group_id LOCK IN SHARE MODE');
		$this->authorize(); $this->control('COMMIT'); $this->transactional = false; $this->confirmed = true;
	}
	function rollback()
	{
		if (!$this->transactional) { return; }
		try { $this->connection->sql_query('ROLLBACK'); } catch (Exception $e) {} catch (Error $e) {}
		$this->transactional = false;
	}
}
