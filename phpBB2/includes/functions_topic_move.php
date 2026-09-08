<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }

class PhpbbTopicMoveException extends RuntimeException {}
function phpbb_topic_move_error($key)
{
	global $lang;
	throw new PhpbbTopicMoveException($lang[$key]);
}
class PhpbbTopicMoveDatabase
{
	var $connection;
	function __construct($connection) { $this->connection = $connection; }
	function __call($method, $args) { return call_user_func_array(array($this->connection, $method), $args); }
	function sql_query($sql, $transaction = false)
	{
		$result = $this->connection->sql_query($sql, $transaction);
		if (!$result) { phpbb_topic_move_error('Moderation_move_failed'); }
		return $result;
	}
}
function phpbb_topic_move_id($id)
{
	$ids = (is_int($id) || is_string($id)) && preg_match('/^[0-9]+$/D', (string) $id) ? attach_delete_id_array($id) : false;
	if ($ids === false || count($ids) !== 1) { phpbb_topic_move_error('None_selected'); }
	return $ids[0];
}

// Internal: modcp has checked the POST confirmation and session. Shared lock
// coordinates current post/poll/attachment/moderator writers on its own DB.
function phpbb_move_topics($database, $source_forum, $target_forum, $topic_ids, $leave_shadow = false)
{
	global $userdata;
	$source_forum = phpbb_topic_move_id($source_forum); $target_forum = phpbb_topic_move_id($target_forum);
	$ids = is_array($topic_ids) ? attach_delete_id_array($topic_ids) : false;
	if ($ids === false || !$ids || !is_bool($leave_shadow)) { phpbb_topic_move_error('None_selected'); }
	sort($ids, SORT_NUMERIC);
	if (empty($userdata['session_logged_in'])) { phpbb_topic_move_error('Not_Moderator'); }
	$lock = new attach_mutation_lock($database);
	if (!$lock->acquired) { phpbb_topic_move_error('Attachment_storage_busy'); }
	try
	{
		$db = new PhpbbTopicMoveDatabase($lock->connection);
		$result = $db->sql_query('SELECT forum_id, forum_status, forum_link, count_posts FROM ' . FORUMS_TABLE . " WHERE forum_id IN ($source_forum,$target_forum)");
		$forums = array(); while ($row = $db->sql_fetchrow($result)) { $forums[(int) $row['forum_id']] = $row; } $db->sql_freeresult($result);
		if (!isset($forums[$source_forum], $forums[$target_forum]) || !empty($forums[$target_forum]['forum_link'])) { phpbb_topic_move_error('Forum_not_exist'); }
		$source_auth = auth(AUTH_ALL, $source_forum, $userdata, '', $db);
		$target_auth = auth(AUTH_ALL, $target_forum, $userdata, '', $db);
		if (empty($source_auth['auth_mod']) || empty($source_auth['auth_view']) || empty($source_auth['auth_read'])) { phpbb_topic_move_error('Not_Moderator'); }
		if (empty($target_auth['auth_view']) || empty($target_auth['auth_read']) || (empty($target_auth['auth_post']) && empty($target_auth['auth_mod']))) { phpbb_topic_move_error('Moderation_move_denied'); }
		if ((int) $forums[$target_forum]['forum_status'] === FORUM_LOCKED && empty($target_auth['auth_mod'])) { phpbb_topic_move_error('Forum_locked'); }
		$list = implode(',', $ids);
		$result = $db->sql_query('SELECT topic_id, forum_id, topic_type, topic_status, topic_moved_id FROM ' . TOPICS_TABLE . " WHERE topic_id IN ($list) ORDER BY topic_id");
		$topics = $db->sql_fetchrowset($result); $db->sql_freeresult($result);
		if (count($topics) !== count($ids)) { phpbb_topic_move_error('Moderation_move_changed'); }
		$type_permissions = array(POST_STICKY => 'auth_sticky', POST_ANNOUNCE => 'auth_announce', POST_GLOBAL_ANNOUNCE => 'auth_global_announce', POST_NEWS => 'auth_news');
		foreach ($topics as $topic)
		{
			if ((int) $topic['forum_id'] !== $source_forum || (int) $topic['topic_moved_id'] !== 0 || !in_array((int) $topic['topic_status'], array(TOPIC_LOCKED, TOPIC_UNLOCKED), true)) { phpbb_topic_move_error('Moderation_move_changed'); }
			$type = (int) $topic['topic_type'];
			if (isset($type_permissions[$type]) && empty($target_auth[$type_permissions[$type]])) { phpbb_topic_move_error('Moderation_move_denied'); }
		}
		$result = $db->sql_query('SELECT post_id, topic_id, forum_id, poster_id FROM ' . POSTS_TABLE . " WHERE topic_id IN ($list) ORDER BY post_id");
		$posts = $db->sql_fetchrowset($result); $db->sql_freeresult($result);
		$counts = array_fill_keys($ids, 0); $posters = array();
		foreach ($posts as $post)
		{
			if ((int) $post['forum_id'] !== $source_forum) { phpbb_topic_move_error('Moderation_move_changed'); }
			$counts[(int) $post['topic_id']]++;
			if ((int) $post['poster_id'] > 0) { $posters[(int) $post['poster_id']] = (int) $post['poster_id']; }
		}
		if (in_array(0, $counts, true)) { phpbb_topic_move_error('Moderation_move_changed'); }
		if ($source_forum === $target_forum) { return array(); }
		$target_lock = empty($target_auth['auth_mod']) ? ' AND destination.forum_status <> ' . FORUM_LOCKED : '';
		// One multi-table UPDATE publishes the new forum on the topic AND all
		// its posts. Native tests exercise this SQL on MyISAM and InnoDB.
		$db->sql_query('UPDATE ' . TOPICS_TABLE . ' t JOIN ' . POSTS_TABLE . ' p ON p.topic_id = t.topic_id AND p.forum_id = t.forum_id'
			. ' JOIN ' . FORUMS_TABLE . ' origin ON origin.forum_id = t.forum_id JOIN ' . FORUMS_TABLE . " destination ON destination.forum_id = $target_forum"
			. " SET t.forum_id = $target_forum, p.forum_id = $target_forum WHERE t.topic_id IN ($list) AND t.forum_id = $source_forum AND t.topic_moved_id = 0"
			. ' AND t.topic_status IN (' . TOPIC_UNLOCKED . ',' . TOPIC_LOCKED . ')' . $target_lock . " AND COALESCE(destination.forum_link, '') = ''");
		if ((int) $db->sql_affectedrows() !== count($topics) + count($posts)) { phpbb_topic_move_error('Moderation_move_changed'); }
		require_once dirname(__FILE__) . '/functions_posting_storage.php';
		require_once dirname(__FILE__) . '/functions_log.php';
		foreach ($topics as $topic)
		{
			$topic_id = (int) $topic['topic_id'];
			// Moving back into a forum makes its empty redirect redundant. Never
			// delete a malformed redirect that actually contains user posts.
			$result = $db->sql_query('SELECT topic_id FROM ' . TOPICS_TABLE . " WHERE forum_id = $target_forum AND topic_moved_id = $topic_id"
				. ' AND NOT EXISTS (SELECT 1 FROM ' . POSTS_TABLE . ' WHERE ' . POSTS_TABLE . '.topic_id = ' . TOPICS_TABLE . '.topic_id)');
			$stubs = $db->sql_fetchrowset($result); $db->sql_freeresult($result);
			foreach ($stubs as $stub)
			{
				$stub_id = (int) $stub['topic_id'];
				$db->sql_query('DELETE FROM ' . TOPICS_TABLE . " WHERE topic_id = $stub_id AND forum_id = $target_forum AND topic_moved_id = $topic_id"
					. ' AND NOT EXISTS (SELECT 1 FROM ' . POSTS_TABLE . " WHERE topic_id = $stub_id)");
				if ((int) $db->sql_affectedrows() !== 1) { phpbb_topic_move_error('Moderation_move_changed'); }
				foreach (array(BOOKMARK_TABLE, TOPICS_WATCH_TABLE, TOPIC_VIEW_TABLE) as $table) { $db->sql_query('DELETE FROM ' . $table . " WHERE topic_id = $stub_id"); }
			}
			if ($leave_shadow)
			{
				// Copy stored title/metadata directly, without re-escaping or
				// slash-normalizing names already in the database.
				$db->sql_query('INSERT INTO ' . TOPICS_TABLE . ' (forum_id, topic_title, topic_desc, topic_poster, topic_time, topic_status, topic_type, topic_vote, topic_views, topic_replies, topic_first_post_id, topic_last_post_id, topic_moved_id, topic_icon, topic_attachment)'
					. " SELECT $source_forum, t.topic_title, t.topic_desc, t.topic_poster, t.topic_time, " . TOPIC_MOVED . ', ' . POST_NORMAL . ', t.topic_vote, t.topic_views, t.topic_replies, t.topic_first_post_id, t.topic_last_post_id, t.topic_id, t.topic_icon, t.topic_attachment'
					. ' FROM ' . TOPICS_TABLE . ' t JOIN ' . FORUMS_TABLE . " f ON f.forum_id = $source_forum LEFT JOIN " . TOPICS_TABLE
					. " existing ON existing.forum_id = $source_forum AND existing.topic_moved_id = $topic_id WHERE t.topic_id = $topic_id AND t.forum_id = $target_forum AND t.topic_moved_id = 0 AND existing.topic_id IS NULL");
				$result = $db->sql_query('SELECT topic_id FROM ' . TOPICS_TABLE . " WHERE forum_id = $source_forum AND topic_moved_id = $topic_id");
				$stub = $db->sql_fetchrow($result); $db->sql_freeresult($result);
				if (!$stub) { phpbb_topic_move_error('Moderation_move_changed'); }
			}
		}
		// Recount only affected real users when crossing the count_posts boundary.
		if ((bool) $forums[$source_forum]['count_posts'] !== (bool) $forums[$target_forum]['count_posts'] && $posters)
		{
			$db->sql_query('UPDATE ' . USERS_TABLE . ' SET user_posts = (SELECT COUNT(*) FROM ' . POSTS_TABLE . ' p JOIN ' . FORUMS_TABLE
				. ' f ON f.forum_id = p.forum_id WHERE p.poster_id = ' . USERS_TABLE . '.user_id AND f.count_posts = 1) WHERE user_id IN (' . implode(',', $posters) . ') AND user_id > 0');
		}
		phpbb_posting_sync_forum($db, $source_forum); phpbb_posting_sync_forum($db, $target_forum);
		if (!log_action('move', $ids, $userdata['user_id'], $userdata['username'], $db)) { phpbb_topic_move_error('Moderation_move_failed'); }
		return $ids;
	}
	finally { $lock->release(); }
}
