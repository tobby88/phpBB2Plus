<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_topic_preferences.php';

// Optional statistics cooperate with moves/merges/deletions. A stale reader
// cannot recreate view rows for a removed source or overwrite merged totals.
function phpbb_record_topic_view($database, $topic_id)
{
	global $userdata;
	$topic_id = phpbb_topic_preference_id($topic_id);
	if (!$topic_id) { return false; }
	$lock = new attach_mutation_lock($database, false);
	if (!$lock->acquired) { return false; }
	try
	{
		$db = new PhpbbTopicPreferenceDatabase($lock->connection);
		$rows = phpbb_topic_preference_rows($db, 'SELECT forum_id FROM ' . TOPICS_TABLE . ' WHERE topic_id = ' . $topic_id
			. ' AND topic_moved_id = 0 AND topic_status IN (' . TOPIC_UNLOCKED . ',' . TOPIC_LOCKED . ')');
		if (!$rows) { return false; }
		$forum = (int) $rows[0]['forum_id'];
		$user = array('user_id'=>ANONYMOUS, 'user_level'=>0, 'session_logged_in'=>false);
		if (!empty($userdata['session_logged_in']))
		{
			$id = isset($userdata['user_id']) ? phpbb_topic_preference_id($userdata['user_id']) : false;
			if (!$id) { return false; }
			$users = phpbb_topic_preference_rows($db, 'SELECT user_id, user_level, user_active FROM ' . USERS_TABLE . ' WHERE user_id = ' . $id);
			if (!$users || empty($users[0]['user_active'])) { return false; }
			$user = $users[0]; $user['session_logged_in'] = true;
		}
		$rights = auth(AUTH_ALL, $forum, $user, '', $db);
		if (empty($rights['auth_view']) || empty($rights['auth_read'])) { return false; }
		$user_id = (int) $user['user_id']; $now = time();
		$views = phpbb_topic_preference_rows($db, 'SELECT user_id FROM ' . TOPIC_VIEW_TABLE . " WHERE topic_id = $topic_id AND user_id = $user_id");
		if ($views)
		{
			// Legacy duplicates are not multiplied further: one request counts
			// exactly once across the existing user's rows.
			$db->sql_query('UPDATE ' . TOPIC_VIEW_TABLE . " SET view_time = $now, view_count = CASE WHEN view_count < 2147483647 THEN view_count + 1 ELSE view_count END WHERE topic_id = $topic_id AND user_id = $user_id LIMIT 1");
		}
		else { $db->sql_query('INSERT INTO ' . TOPIC_VIEW_TABLE . " (topic_id,user_id,view_time,view_count) VALUES ($topic_id,$user_id,$now,1)"); }
		$db->sql_query('UPDATE ' . TOPICS_TABLE . ' SET topic_views = CASE WHEN topic_views < 16777215 THEN topic_views + 1 ELSE topic_views END WHERE topic_id = ' . $topic_id . ' AND topic_moved_id = 0');
		return true;
	}
	catch (PhpbbTopicPreferenceException $error)
	{
		// Optional counters must not fail an otherwise readable page.
		error_log('phpBB optional topic view statistics failed.');
		return false;
	}
	finally { $lock->release(); }
}
