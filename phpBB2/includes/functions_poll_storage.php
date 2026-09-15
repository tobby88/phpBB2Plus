<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }

class PhpbbPollStorageException extends RuntimeException {}
function phpbb_poll_error($key)
{
	global $lang;
	throw new PhpbbPollStorageException(isset($lang[$key]) ? $lang[$key] : $key);
}

// auth() must use this connection and report failures through the caller's
// normal HTML or AJAX transport, not terminate inside a locked SQL operation.
class PhpbbPollStorageDatabase
{
	var $connection;
	function __construct($connection) { $this->connection = $connection; }
	function __call($method, $args) { return call_user_func_array(array($this->connection, $method), $args); }
	function sql_query($sql, $transaction = false)
	{
		$result = $this->connection->sql_query($sql, $transaction);
		if (!$result) { phpbb_poll_error('Poll_storage_failed'); }
		return $result;
	}
}

// The ordinary ballot is read-only. Only submission owns a transaction and
// validates persisted authority; no storage conversion runs on a request path.
class PhpbbPollVoteDatabase extends PhpbbPollStorageDatabase
{
	var $topic_id;
	var $transactional = false;
	function __construct($connection, $topic_id) { parent::__construct($connection); $this->topic_id = $topic_id; }
	function rows($sql)
	{
		$r = $this->sql_query($sql); $rows = $this->sql_fetchrowset($r); $this->sql_freeresult($r); return $rows;
	}
	function control($sql) { return parent::sql_query($sql); }
	function sql_query($sql, $transaction = false)
	{
		if (!is_string($sql) || !preg_match('/^\s*(SELECT|UPDATE|INSERT)\b/i', $sql)) { phpbb_poll_error('Poll_storage_failed'); }
		if (preg_match('/^\s*(UPDATE|INSERT)\b/i', $sql))
		{
			if (!$this->transactional) { phpbb_poll_error('Poll_storage_failed'); }
			$this->authorize();
		}
		return $this->control($sql);
	}
	function actor()
	{
		global $userdata;
		if (empty($userdata['session_id']) || !is_string($userdata['session_id'])) { phpbb_poll_error('Poll_vote_denied'); }
		$logged = !empty($userdata['session_logged_in']);
		$id = $logged ? phpbb_poll_id(isset($userdata['user_id']) ? $userdata['user_id'] : null) : ANONYMOUS;
		$sid = $this->sql_escape($userdata['session_id']);
		if (!$this->rows('SELECT session_id FROM ' . SESSIONS_TABLE . " WHERE session_id = '$sid' AND HEX(session_id) = HEX('$sid')"
			. ' AND session_user_id = ' . $id . ' AND session_logged_in = ' . (int)$logged)) { phpbb_poll_error('Poll_vote_denied'); }
		if (!$logged) { return array('user_id'=>ANONYMOUS, 'user_level'=>USER, 'session_logged_in'=>false); }
		$rows = $this->rows('SELECT user_id, user_level, user_active FROM ' . USERS_TABLE . ' WHERE user_id = ' . $id);
		if (count($rows) !== 1 || empty($rows[0]['user_active'])) { phpbb_poll_error('Poll_vote_denied'); }
		$rows[0]['session_logged_in'] = true; return $rows[0];
	}
	function authorize()
	{
		$state = phpbb_poll_state($this, $this->topic_id, $this->actor());
		if ($state['reason'] !== '') { phpbb_poll_error($state['reason']); }
		return $state;
	}
	function begin()
	{
		$this->actor();
		$this->control("SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_ALL_TABLES')");
		$this->control('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
		$this->control('START TRANSACTION'); $this->transactional = true;
		foreach (array(USERS_TABLE, SESSIONS_TABLE, FORUMS_TABLE, TOPICS_TABLE, USER_GROUP_TABLE, AUTH_ACCESS_TABLE, VOTE_DESC_TABLE, VOTE_RESULTS_TABLE, VOTE_USERS_TABLE) as $table)
		{
			$r = $this->sql_query('SELECT * FROM ' . $table . ' LIMIT 0'); $this->sql_freeresult($r);
			$rows = $this->rows("SELECT ENGINE, ROW_FORMAT, TABLE_COLLATION FROM information_schema.TABLES t WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . $this->sql_escape($table) . "'"
				. " AND NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS c WHERE c.TABLE_SCHEMA=t.TABLE_SCHEMA AND c.TABLE_NAME=t.TABLE_NAME AND c.CHARACTER_SET_NAME IS NOT NULL AND (c.CHARACTER_SET_NAME <> 'utf8mb4' OR c.COLLATION_NAME <> 'utf8mb4_unicode_ci'))");
			if (count($rows) !== 1 || $rows[0]['ENGINE'] !== 'InnoDB' || strtolower($rows[0]['ROW_FORMAT']) !== 'dynamic' || $rows[0]['TABLE_COLLATION'] !== 'utf8mb4_unicode_ci') { phpbb_poll_error('Poll_storage_upgrade'); }
		}
	}
	function commit()
	{
		global $userdata;
		$user = $this->actor(); $id = (int)$user['user_id']; $sid = $this->sql_escape($userdata['session_id']);
		$this->rows('SELECT session_id FROM ' . SESSIONS_TABLE . " WHERE session_id = '$sid' AND HEX(session_id) = HEX('$sid') LOCK IN SHARE MODE");
		if ($id !== ANONYMOUS)
		{
			$this->rows('SELECT user_id FROM ' . USERS_TABLE . ' WHERE user_id = ' . $id . ' LOCK IN SHARE MODE');
			$this->rows('SELECT group_id FROM ' . USER_GROUP_TABLE . ' WHERE user_id = ' . $id . ' ORDER BY group_id LOCK IN SHARE MODE');
		}
		$state = $this->authorize(); $forum = (int)$state['poll']['forum_id'];
		$this->rows('SELECT group_id FROM ' . AUTH_ACCESS_TABLE . ' WHERE forum_id = ' . $forum . ' ORDER BY group_id LOCK IN SHARE MODE');
		$this->authorize(); $this->control('COMMIT'); $this->transactional = false;
		$this->authorize();
	}
	function rollback()
	{
		try { $this->connection->sql_query('ROLLBACK'); } catch (Exception $e) {} catch (Error $e) {}
	}
}

function phpbb_poll_id($value)
{
	$ids = (is_int($value) || is_string($value)) && preg_match('/^[0-9]+$/D', (string) $value) ? attach_delete_id_array($value) : false;
	if ($ids === false || count($ids) !== 1) { phpbb_poll_error('No_vote_option'); }
	return $ids[0];
}

// Read-only state for the ballot; writers call this again after taking the lock.
function phpbb_poll_state($database, $topic_id, $actor = null)
{
	global $userdata, $user_ip;
	$topic_id = phpbb_poll_id($topic_id);
	$db = $database instanceof PhpbbPollVoteDatabase ? $database : new PhpbbPollStorageDatabase($database);
	$actor = $actor === null ? $userdata : $actor;
	$row_lock = $db instanceof PhpbbPollVoteDatabase && $db->transactional ? ' FOR UPDATE' : '';
	$result = $db->sql_query('SELECT vd.*, t.forum_id, t.topic_status, f.forum_status FROM ' . VOTE_DESC_TABLE . ' vd'
		. ' JOIN ' . TOPICS_TABLE . ' t ON t.topic_id = vd.topic_id AND t.topic_moved_id = 0'
		. ' JOIN ' . FORUMS_TABLE . ' f ON f.forum_id = t.forum_id WHERE t.topic_id = ' . $topic_id . $row_lock);
	$row = $db->sql_fetchrow($result); $extra = $db->sql_fetchrow($result); $db->sql_freeresult($result);
	if (!$row || $extra) { phpbb_poll_error('Topic_post_not_exist'); }
	$auth = auth(AUTH_ALL, (int) $row['forum_id'], $actor, '', $db);
	if (empty($auth['auth_view']) || empty($auth['auth_read'])) { phpbb_poll_error('Topic_post_not_exist'); }
	$reason = '';
	if (empty($auth['auth_vote'])) { $reason = 'Poll_vote_denied'; }
	else if (empty($auth['auth_mod']) && (int) $row['forum_status'] === FORUM_LOCKED) { $reason = 'Forum_locked'; }
	else if (empty($auth['auth_mod']) && (int) $row['topic_status'] === TOPIC_LOCKED) { $reason = 'Topic_locked'; }
	else if ((int) $row['vote_length'] > 0 && (float) $row['vote_start'] + (float) $row['vote_length'] <= time()) { $reason = 'Poll_expired'; }
	$user_id = empty($actor['session_logged_in']) ? ANONYMOUS : (int) $actor['user_id'];
	if ($user_id !== ANONYMOUS && $user_id <= 0) { phpbb_poll_error('Poll_vote_denied'); }
	if (!is_string($user_ip) || $user_ip === '' || strlen($user_ip) > 45) { phpbb_poll_error('Poll_vote_denied'); }
	$identity = 'vote_user_id = ' . $user_id;
	if ($user_id === ANONYMOUS) { $identity .= " AND vote_user_ip = '" . $db->sql_escape($user_ip) . "'"; }
	$vote_id = (int) $row['vote_id'];
	$result = $db->sql_query('SELECT vote_id FROM ' . VOTE_USERS_TABLE . " WHERE vote_id = $vote_id AND $identity");
	$voted = (bool) $db->sql_fetchrow($result); $db->sql_freeresult($result);
	return array('poll' => $row, 'reason' => $reason, 'voted' => $voted, 'can_vote' => $reason === '' && !$voted, 'identity' => $identity, 'user_id' => $user_id, 'moderator' => !empty($auth['auth_mod']));
}

// Internal: both endpoints verify POST + session token before calling this.
// The existing shared lock also serializes cooperating post/poll edit/delete
// operations. Modern transactional storage is required for every participant.
function phpbb_cast_poll_vote($database, $topic_id, $option_id)
{
	global $user_ip;
	$topic_id = phpbb_poll_id($topic_id); $option_id = phpbb_poll_id($option_id);
	$lock = new attach_mutation_lock($database);
	if (!$lock->acquired) { phpbb_poll_error('Attachment_storage_busy'); }
	$db = new PhpbbPollVoteDatabase($lock->connection, $topic_id);
	try
	{
		$db->begin(); $state = $db->authorize();
		if ($state['voted']) { return 'Already_voted'; }
		$vote_id = (int) $state['poll']['vote_id']; $user_id = $state['user_id']; $identity = $state['identity'];
		$result = $db->sql_query('SELECT vote_option_id FROM ' . VOTE_RESULTS_TABLE . " WHERE vote_id = $vote_id AND vote_option_id = $option_id FOR UPDATE");
		$option = $db->sql_fetchrow($result); $extra = $db->sql_fetchrow($result); $db->sql_freeresult($result);
		if (!$option || $extra) { phpbb_poll_error('No_vote_option'); }
		$db->sql_query('INSERT INTO ' . VOTE_USERS_TABLE . ' (vote_id, vote_user_id, vote_user_ip)'
			. " SELECT $vote_id, $user_id, '" . $db->sql_escape($user_ip) . "' WHERE NOT EXISTS (SELECT 1 FROM " . VOTE_USERS_TABLE . " WHERE vote_id = $vote_id AND $identity)");
		if ((int) $db->sql_affectedrows() !== 1) { return 'Already_voted'; }
		$forum_id = (int) $state['poll']['forum_id'];
		$parent_scope = $state['moderator'] ? '' : ' AND f.forum_status <> ' . FORUM_LOCKED . ' AND t.topic_status <> ' . TOPIC_LOCKED;
		// A removed, expired or saturated option must roll back the voter too.
		$updated = $db->sql_query('UPDATE ' . VOTE_RESULTS_TABLE
			. " SET vote_result = vote_result + 1 WHERE vote_id = $vote_id AND vote_option_id = $option_id AND vote_result >= 0 AND vote_result < 2147483647"
			. ' AND EXISTS (SELECT 1 FROM ' . VOTE_DESC_TABLE . ' vd JOIN ' . TOPICS_TABLE . ' t ON t.topic_id = vd.topic_id'
			. ' JOIN ' . FORUMS_TABLE . " f ON f.forum_id = t.forum_id WHERE vd.vote_id = $vote_id AND t.topic_id = $topic_id AND t.forum_id = $forum_id AND t.topic_moved_id = 0"
			. $parent_scope . ' AND (vd.vote_length = 0 OR vd.vote_start + vd.vote_length > ' . time() . '))');
		if (!$updated || (int) $db->sql_affectedrows() !== 1)
		{
			phpbb_poll_error('Poll_storage_failed');
		}
		$db->commit();
		return 'Vote_cast';
	}
	finally { $db->rollback(); $lock->release(); }
}
