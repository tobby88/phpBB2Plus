<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_moderator_identity.php';

class PhpbbTopicStateException extends RuntimeException {}
function phpbb_topic_state_error($key)
{
	global $lang;
	throw new PhpbbTopicStateException($lang[$key]);
}
class PhpbbTopicStateDatabase
{
	var $connection;
	var $transactional = false;
	var $forum;
	var $mode;
	function __construct($connection, $forum, $mode) { $this->connection = $connection; $this->forum = $forum; $this->mode = $mode; }
	function __call($method, $args) { return call_user_func_array(array($this->connection, $method), $args); }
	function control($sql)
	{
		$result = $this->connection->sql_query($sql);
		if (!$result) { phpbb_topic_state_error('Moderation_state_failed'); }
		return $result;
	}
	function sql_query($sql, $transaction = false)
	{
		// Enlisted audit helpers must not commit or issue implicit-commit DDL.
		if (!is_string($sql) || !preg_match('/^\s*(SELECT|UPDATE|INSERT|DELETE)\b/i', $sql)) { phpbb_topic_state_error('Moderation_state_failed'); }
		if (preg_match('/^\s*(UPDATE|INSERT|DELETE)\b/i', $sql))
		{
			if (!$this->transactional) { phpbb_topic_state_error('Moderation_state_failed'); }
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
		$user = phpbb_current_moderator_user($this);
		if (!$user || empty($userdata['session_id']) || !is_string($userdata['session_id'])) { phpbb_topic_state_error('Not_Moderator'); }
		$sid = $this->sql_escape($userdata['session_id']);
		if (!$this->rows('SELECT session_id FROM ' . SESSIONS_TABLE . " WHERE session_id = '$sid' AND HEX(session_id) = HEX('$sid')"
			. ' AND session_user_id = ' . (int)$user['user_id'] . ' AND session_logged_in = 1')) { phpbb_topic_state_error('Not_Moderator'); }
		return $user; // An ordinary forum session suffices; no ACP flag required.
	}
	function authorize()
	{
		$user = $this->actor();
		$auth = auth(AUTH_ALL, $this->forum, $user, '', $this);
		if (empty($auth['auth_mod']) || empty($auth['auth_view']) || empty($auth['auth_read'])) { phpbb_topic_state_error('Not_Moderator'); }
		if (($this->mode === 'sticky' && empty($auth['auth_sticky'])) || ($this->mode === 'announce' && empty($auth['auth_announce']))) { phpbb_topic_state_error('Moderation_state_denied'); }
	}
	function begin()
	{
		if ($this->transactional) { phpbb_topic_state_error('Moderation_state_failed'); }
		$this->actor();
		$this->control("SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_ALL_TABLES')");
		$this->control('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
		$this->control('START TRANSACTION'); $this->transactional = true;
		foreach (array(USERS_TABLE, SESSIONS_TABLE, FORUMS_TABLE, TOPICS_TABLE, USER_GROUP_TABLE, AUTH_ACCESS_TABLE, LOGS_TABLE) as $table)
		{
			$r = $this->sql_query('SELECT * FROM ' . $table . ' LIMIT 0'); $this->sql_freeresult($r);
			$table_name = $this->sql_escape($table);
			// Keep the column policy and metadata locks, but bound the scan.
			$rows = $this->rows("SELECT ENGINE, ROW_FORMAT, TABLE_COLLATION FROM information_schema.TABLES t WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . $table_name . "'"
				. " AND NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS c WHERE c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME='" . $table_name . "' AND c.CHARACTER_SET_NAME IS NOT NULL AND (c.CHARACTER_SET_NAME <> 'utf8mb4' OR c.COLLATION_NAME <> 'utf8mb4_unicode_ci'))");
			if (count($rows) !== 1 || $rows[0]['ENGINE'] !== 'InnoDB' || strtolower($rows[0]['ROW_FORMAT']) !== 'dynamic' || $rows[0]['TABLE_COLLATION'] !== 'utf8mb4_unicode_ci') { phpbb_topic_state_error('Moderation_state_storage'); }
		}
	}
	function commit()
	{
		global $userdata;
		if (!$this->transactional) { phpbb_topic_state_error('Moderation_state_failed'); }
		$user = $this->actor(); $id = (int)$user['user_id']; $sid = $this->sql_escape($userdata['session_id']);
		$this->rows('SELECT session_id FROM ' . SESSIONS_TABLE . " WHERE session_id = '$sid' AND HEX(session_id) = HEX('$sid') LOCK IN SHARE MODE");
		$this->rows('SELECT user_id FROM ' . USERS_TABLE . ' WHERE user_id = ' . $id . ' LOCK IN SHARE MODE');
		$this->rows('SELECT group_id FROM ' . USER_GROUP_TABLE . ' WHERE user_id = ' . $id . ' ORDER BY group_id LOCK IN SHARE MODE');
		$this->rows('SELECT group_id FROM ' . AUTH_ACCESS_TABLE . ' WHERE forum_id = ' . $this->forum . ' ORDER BY group_id LOCK IN SHARE MODE');
		$this->authorize();
		// Authority stays pinned through COMMIT. Later changes cannot undo
		// an acknowledged operation or its audit/counter updates.
		$this->control('COMMIT'); $this->transactional = false;
	}
	function rollback()
	{
		if (!$this->transactional) { return; }
		try { $this->connection->sql_query('ROLLBACK'); } catch (Exception $e) {} catch (Error $e) {}
		$this->transactional = false;
	}
}

// Internal: callers have verified POST/SID or the signed legacy toolbar link.
// A null forum is allowed only for the single-topic AJAX endpoint; derive that
// forum inside the lock instead of trusting an earlier request snapshot.
function phpbb_moderate_topic_state($database, $forum_id, $topic_ids, $mode)
{
	global $userdata;
	$modes = array('lock' => array('topic_status', TOPIC_LOCKED), 'unlock' => array('topic_status', TOPIC_UNLOCKED),
		'sticky' => array('topic_type', POST_STICKY), 'announce' => array('topic_type', POST_ANNOUNCE), 'normalise' => array('topic_type', POST_NORMAL));
	$ids = is_array($topic_ids) ? attach_delete_id_array($topic_ids) : false;
	if ($ids === false || !$ids || !is_string($mode) || !isset($modes[$mode])) { phpbb_topic_state_error('None_selected'); }
	if ($forum_id === null)
	{
		if (count($ids) !== 1) { phpbb_topic_state_error('None_selected'); }
	}
	else
	{
		$forums = (is_int($forum_id) || is_string($forum_id)) && preg_match('/^[0-9]+$/D', (string) $forum_id) ? attach_delete_id_array($forum_id) : false;
		if ($forums === false || count($forums) !== 1) { phpbb_topic_state_error('None_selected'); }
		$forum_id = $forums[0];
	}
	if (empty($userdata['session_logged_in'])) { phpbb_topic_state_error('Not_Moderator'); }
	$lock = new attach_mutation_lock($database);
	if (!$lock->acquired) { phpbb_topic_state_error('Attachment_storage_busy'); }
	$db = new PhpbbTopicStateDatabase($lock->connection, $forum_id, $mode);
	try
	{
		$db->begin();
		$result = $db->sql_query('SELECT topic_id, forum_id, topic_status, topic_type, topic_moved_id FROM ' . TOPICS_TABLE
			. ' WHERE topic_id IN (' . implode(',', $ids) . ') ORDER BY topic_id FOR UPDATE');
		$rows = $db->sql_fetchrowset($result); $db->sql_freeresult($result);
		if (count($rows) !== count($ids)) { phpbb_topic_state_error('Moderation_state_changed'); }
		if ($forum_id === null) { $forum_id = (int) $rows[0]['forum_id']; }
		$db->forum = $forum_id;
		$db->rows('SELECT forum_id FROM ' . FORUMS_TABLE . ' WHERE forum_id = ' . $forum_id . ' FOR UPDATE');
		// Validate the WHOLE batch before any update. Foreign, moved or missing
		// selections must not mutate a partial set or pollute the audit log.
		foreach ($rows as $row)
		{
			if ((int) $row['forum_id'] !== $forum_id || (int) $row['topic_moved_id'] !== 0 || !in_array((int) $row['topic_status'], array(TOPIC_UNLOCKED, TOPIC_LOCKED), true))
			{
				phpbb_topic_state_error('Moderation_state_changed');
			}
		}
		$db->authorize();
		require_once dirname(__FILE__) . '/functions_log.php';
		$column = $modes[$mode][0]; $value = $modes[$mode][1]; $changed = array();
		foreach ($rows as $row)
		{
			$old_value = (int) $row[$column]; $topic_id = (int) $row['topic_id'];
			if ($old_value === $value)
			{
				$result = $db->sql_query('SELECT topic_id FROM ' . TOPICS_TABLE . " WHERE topic_id = $topic_id AND forum_id = $forum_id AND topic_moved_id = 0 AND $column = $value"
					. ' AND topic_status IN (' . TOPIC_UNLOCKED . ',' . TOPIC_LOCKED . ')'
					. ' AND EXISTS (SELECT 1 FROM ' . FORUMS_TABLE . " WHERE forum_id = $forum_id)");
				$current = $db->sql_fetchrow($result); $db->sql_freeresult($result);
				if (!$current) { phpbb_topic_state_error('Moderation_state_changed'); }
				continue;
			}
			$db->sql_query('UPDATE ' . TOPICS_TABLE . " SET $column = $value WHERE topic_id = $topic_id AND forum_id = $forum_id"
				. " AND topic_moved_id = 0 AND $column = $old_value AND topic_status IN (" . TOPIC_UNLOCKED . ',' . TOPIC_LOCKED . ')'
				. ' AND EXISTS (SELECT 1 FROM ' . FORUMS_TABLE . " WHERE forum_id = $forum_id)");
			if ((int) $db->sql_affectedrows() !== 1) { phpbb_topic_state_error('Moderation_state_changed'); }
			if (!log_action($mode === 'normalise' ? 'normal' : $mode, array($topic_id), $userdata['user_id'], $userdata['username'], $db))
			{
				phpbb_topic_state_error('Moderation_state_failed');
			}
			$changed[] = $topic_id;
		}
		$db->commit();
		return $changed;
	}
	finally { $db->rollback(); $lock->release(); }
}
