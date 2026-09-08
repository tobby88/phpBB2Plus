<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }

class PhpbbTopicPreferenceException extends RuntimeException {}
function phpbb_topic_preference_error($key)
{
	global $lang;
	throw new PhpbbTopicPreferenceException(isset($lang[$key]) ? $lang[$key] : $key);
}
class PhpbbTopicPreferenceDatabase
{
	var $connection;
	function __construct($connection) { $this->connection = $connection; }
	function __call($method, $args) { return call_user_func_array(array($this->connection, $method), $args); }
	function sql_query($sql)
	{
		$result = $this->connection->sql_query($sql);
		if (!$result) { phpbb_topic_preference_error('Topic_preference_failed'); }
		return $result;
	}
}
function phpbb_topic_preference_id($value)
{
	if (!(is_int($value) || is_string($value)) || !preg_match('/^[0-9]+$/D', (string) $value)) { return false; }
	$value = ltrim((string) $value, '0');
	return $value !== '' && strlen($value) <= 8 && (int) $value <= 16777215 ? (int) $value : false;
}
function phpbb_topic_preference_rows($db, $sql)
{
	$result = $db->sql_query($sql);
	$rows = $db->sql_fetchrowset($result); $db->sql_freeresult($result);
	return $rows;
}
function phpbb_topic_preference_can_read($db, $forum_id, $user)
{
	$user['session_logged_in'] = true;
	$view = auth(AUTH_VIEW, $forum_id, $user, '', $db);
	$read = auth(AUTH_READ, $forum_id, $user, '', $db);
	return !empty($view['auth_view']) && !empty($read['auth_read']);
}

// Internal API: endpoints validate their POST/SID or signed toolbar action.
// null reads state (and acknowledges watch notifications), bool sets state.
function phpbb_topic_preference($database, $topic_id, $kind, $state = null)
{
	global $userdata;
	$topic_id = phpbb_topic_preference_id($topic_id);
	$user_id = isset($userdata['user_id']) ? phpbb_topic_preference_id($userdata['user_id']) : false;
	if (!$topic_id || !$user_id || empty($userdata['session_logged_in']) || !in_array($kind, array('watch', 'bookmark'), true) || !in_array($state, array(null, true, false), true))
	{
		phpbb_topic_preference_error('Session_invalid');
	}
	$lock = new attach_mutation_lock($database);
	if (!$lock->acquired) { phpbb_topic_preference_error('Attachment_storage_busy'); }
	try
	{
		$db = new PhpbbTopicPreferenceDatabase($lock->connection);
		$table = $kind === 'watch' ? TOPICS_WATCH_TABLE : BOOKMARK_TABLE;
		$where = "topic_id = $topic_id AND user_id = $user_id";
		// Removing one's own preference must also work after a move to a private
		// forum or deletion. It reveals neither the title nor topic existence.
		if ($state === false)
		{
			$db->sql_query('DELETE FROM ' . $table . ' WHERE ' . $where);
			return false;
		}
		$users = phpbb_topic_preference_rows($db, 'SELECT user_id, user_level, user_active FROM ' . USERS_TABLE . " WHERE user_id = $user_id");
		$topics = phpbb_topic_preference_rows($db, 'SELECT t.forum_id FROM ' . TOPICS_TABLE . ' t JOIN ' . FORUMS_TABLE . ' f ON f.forum_id = t.forum_id'
			. " WHERE t.topic_id = $topic_id AND t.topic_moved_id = 0 AND t.topic_status IN (" . TOPIC_UNLOCKED . ',' . TOPIC_LOCKED . ')');
		if (!$users || empty($users[0]['user_active']) || !$topics || !phpbb_topic_preference_can_read($db, (int) $topics[0]['forum_id'], $users[0]))
		{
			phpbb_topic_preference_error('Topic_post_not_exist');
		}
		$forum_id = (int) $topics[0]['forum_id'];
		if ($state === true)
		{
			$columns = 'topic_id, user_id' . ($kind === 'watch' ? ', notify_status' : '');
			$values = "t.topic_id, u.user_id" . ($kind === 'watch' ? ', 0' : '');
			// Anti-join instead of INSERT target in a subquery (MySQL restriction).
			$db->sql_query('INSERT INTO ' . $table . ' (' . $columns . ') SELECT ' . $values
				. ' FROM ' . TOPICS_TABLE . ' t JOIN ' . FORUMS_TABLE . ' f ON f.forum_id = t.forum_id JOIN ' . USERS_TABLE . " u ON u.user_id = $user_id AND u.user_active = 1"
				. ' LEFT JOIN ' . $table . " w ON w.topic_id = t.topic_id AND w.user_id = u.user_id WHERE t.topic_id = $topic_id AND t.forum_id = $forum_id"
				. ' AND t.topic_moved_id = 0 AND t.topic_status IN (' . TOPIC_UNLOCKED . ',' . TOPIC_LOCKED . ') AND w.user_id IS NULL');
		}
		$rows = phpbb_topic_preference_rows($db, 'SELECT topic_id FROM ' . $table . ' WHERE ' . $where);
		if ($state === true && !$rows) { phpbb_topic_preference_error('Topic_post_not_exist'); }
		if ($kind === 'watch' && $rows)
		{
			// Clear the claim too: a late mail completion must not undo this read.
			$db->sql_query('UPDATE ' . $table . " SET notify_status = 0, notify_claim = '', notify_claimed_at = 0 WHERE " . $where);
		}
		return (bool) $rows;
	}
	finally { $lock->release(); }
}
