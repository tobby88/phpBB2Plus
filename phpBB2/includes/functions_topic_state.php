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
	function __construct($connection) { $this->connection = $connection; }
	function __call($method, $args) { return call_user_func_array(array($this->connection, $method), $args); }
	function sql_query($sql, $transaction = false)
	{
		$result = $this->connection->sql_query($sql, $transaction);
		if (!$result) { phpbb_topic_state_error('Moderation_state_failed'); }
		return $result;
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
	try
	{
		$db = new PhpbbTopicStateDatabase($lock->connection);
		$user=phpbb_current_moderator_user($db);
		if (!$user) { phpbb_topic_state_error('Not_Moderator'); }
		$result = $db->sql_query('SELECT topic_id, forum_id, topic_status, topic_type, topic_moved_id FROM ' . TOPICS_TABLE
			. ' WHERE topic_id IN (' . implode(',', $ids) . ') ORDER BY topic_id');
		$rows = $db->sql_fetchrowset($result); $db->sql_freeresult($result);
		if (count($rows) !== count($ids)) { phpbb_topic_state_error('Moderation_state_changed'); }
		if ($forum_id === null) { $forum_id = (int) $rows[0]['forum_id']; }
		// Validate the WHOLE batch before any update. Foreign, moved or missing
		// selections must not mutate a partial set or pollute the audit log.
		foreach ($rows as $row)
		{
			if ((int) $row['forum_id'] !== $forum_id || (int) $row['topic_moved_id'] !== 0 || !in_array((int) $row['topic_status'], array(TOPIC_UNLOCKED, TOPIC_LOCKED), true))
			{
				phpbb_topic_state_error('Moderation_state_changed');
			}
		}
		$auth = auth(AUTH_ALL, $forum_id, $user, '', $db);
		if (empty($auth['auth_mod']) || empty($auth['auth_view']) || empty($auth['auth_read'])) { phpbb_topic_state_error('Not_Moderator'); }
		if (($mode === 'sticky' && empty($auth['auth_sticky'])) || ($mode === 'announce' && empty($auth['auth_announce']))) { phpbb_topic_state_error('Moderation_state_denied'); }
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
		return $changed;
	}
	finally { $lock->release(); }
}
