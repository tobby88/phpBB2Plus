<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }

class PhpbbTopicSplitException extends RuntimeException {}
function phpbb_topic_split_error($key)
{
	global $lang;
	throw new PhpbbTopicSplitException($lang[$key]);
}
class PhpbbTopicSplitDatabase
{
	var $connection;
	function __construct($connection) { $this->connection = $connection; }
	function __call($method, $args) { return call_user_func_array(array($this->connection, $method), $args); }
	function sql_query($sql, $transaction = false)
	{
		$result = $this->connection->sql_query($sql, $transaction);
		if (!$result) { phpbb_topic_split_error('Moderation_split_failed'); }
		return $result;
	}
}
function phpbb_topic_split_id($value)
{
	$ids = (is_int($value) || is_string($value)) && preg_match('/^[0-9]+$/D', (string) $value) ? attach_delete_id_array($value) : false;
	if ($ids === false || count($ids) !== 1) { phpbb_topic_split_error('None_selected'); }
	return $ids[0];
}
function phpbb_split_sync_topic($db, $topic_id, $forum_id)
{
	$where = ' WHERE topic_id = ' . $topic_id;
	$count = '(SELECT COUNT(*) FROM ' . POSTS_TABLE . $where . ')';
	$first = '(SELECT MIN(post_id) FROM ' . POSTS_TABLE . $where . ')';
	$last = '(SELECT MAX(post_id) FROM ' . POSTS_TABLE . $where . ')';
	$scope = $where . ' AND forum_id = ' . $forum_id . ' AND topic_moved_id = 0';
	$db->sql_query('UPDATE ' . TOPICS_TABLE . ' SET topic_replies = ' . $count . ' - 1, topic_first_post_id = ' . $first . ', topic_last_post_id = ' . $last . $scope . ' AND ' . $count . ' > 0');
	$result = $db->sql_query('SELECT topic_id FROM ' . TOPICS_TABLE . $scope . ' AND topic_replies = ' . $count . ' - 1 AND topic_first_post_id = ' . $first . ' AND topic_last_post_id = ' . $last);
	$row = $db->sql_fetchrow($result); $db->sql_freeresult($result);
	if (!$row) { phpbb_topic_split_error('Moderation_split_changed'); }
}

// Internal: modcp verifies POST/session. Preserve the source's actual first post
// and poll; selected posts must all belong to that one current source topic.
function phpbb_split_topic($database, $source_forum, $source_topic, $target_forum, $post_ids, $mode, $subject)
{
	global $userdata;
	$source_forum = phpbb_topic_split_id($source_forum); $source_topic = phpbb_topic_split_id($source_topic); $target_forum = phpbb_topic_split_id($target_forum);
	$requested = is_array($post_ids) ? attach_delete_id_array($post_ids) : false;
	if ($requested === false || !$requested || !in_array($mode, array('selected', 'after'), true)) { phpbb_topic_split_error('None_selected'); }
	if (!is_string($subject) || strlen($subject) > 4096) { phpbb_topic_split_error('Moderation_split_subject'); }
	require_once dirname(__FILE__) . '/functions_post_subject.php';
	$subject = phpbb_storage_subject(trim($subject));
	if ($subject === false) { phpbb_topic_split_error('Moderation_split_subject'); }
	if ($subject === '') { phpbb_topic_split_error('Empty_subject'); }
	if (empty($userdata['session_logged_in'])) { phpbb_topic_split_error('Not_Moderator'); }
	$lock = new attach_mutation_lock($database);
	if (!$lock->acquired) { phpbb_topic_split_error('Attachment_storage_busy'); }
	$new_topic_id = 0;
	try
	{
		$db = new PhpbbTopicSplitDatabase($lock->connection);
		$result = $db->sql_query('SELECT forum_id, forum_status, forum_link, count_posts FROM ' . FORUMS_TABLE . " WHERE forum_id IN ($source_forum,$target_forum)");
		$forums = array(); while ($row = $db->sql_fetchrow($result)) { $forums[(int) $row['forum_id']] = $row; } $db->sql_freeresult($result);
		if (!isset($forums[$source_forum], $forums[$target_forum]) || !empty($forums[$target_forum]['forum_link'])) { phpbb_topic_split_error('Forum_not_exist'); }
		$source_auth = auth(AUTH_ALL, $source_forum, $userdata, '', $db); $target_auth = auth(AUTH_ALL, $target_forum, $userdata, '', $db);
		if (empty($source_auth['auth_mod']) || empty($source_auth['auth_view']) || empty($source_auth['auth_read'])) { phpbb_topic_split_error('Not_Moderator'); }
		if (empty($target_auth['auth_view']) || empty($target_auth['auth_read']) || (empty($target_auth['auth_post']) && empty($target_auth['auth_mod']))) { phpbb_topic_split_error('Moderation_split_denied'); }
		if ((int) $forums[$target_forum]['forum_status'] === FORUM_LOCKED && empty($target_auth['auth_mod'])) { phpbb_topic_split_error('Forum_locked'); }
		$result = $db->sql_query('SELECT topic_id FROM ' . TOPICS_TABLE . " WHERE topic_id = $source_topic AND forum_id = $source_forum AND topic_moved_id = 0 AND topic_status IN (" . TOPIC_UNLOCKED . ',' . TOPIC_LOCKED . ')');
		$topic = $db->sql_fetchrow($result); $db->sql_freeresult($result);
		if (!$topic) { phpbb_topic_split_error('Moderation_split_changed'); }
		$result = $db->sql_query('SELECT post_id, poster_id, post_time, forum_id FROM ' . POSTS_TABLE . " WHERE topic_id = $source_topic ORDER BY post_time ASC, post_id ASC");
		$rows = $db->sql_fetchrowset($result); $db->sql_freeresult($result);
		$posts = array(); $positions = array(); $position = 0;
		foreach ($rows as $row)
		{
			if ((int) $row['forum_id'] !== $source_forum) { phpbb_topic_split_error('Moderation_split_changed'); }
			$id = (int) $row['post_id']; $posts[$id] = $row; $positions[$id] = $position++;
		}
		if (count($posts) < 2) { phpbb_topic_split_error('Moderation_split_keep_first'); }
		$first_id = min(array_keys($posts)); $cut = count($posts);
		foreach ($requested as $id)
		{
			if (!isset($posts[$id])) { phpbb_topic_split_error('Moderation_split_changed'); }
			$cut = min($cut, $positions[$id]);
		}
		$selected = $mode === 'after' ? array_slice(array_keys($posts), $cut) : $requested;
		if (in_array($first_id, $selected, true)) { phpbb_topic_split_error('Moderation_split_keep_first'); }
		sort($selected, SORT_NUMERIC); $list = implode(',', $selected);
		$first_selected = $posts[$selected[0]]; $posters = array();
		foreach ($selected as $id) { if ((int) $posts[$id]['poster_id'] > 0) { $posters[(int) $posts[$id]['poster_id']] = (int) $posts[$id]['poster_id']; } }
		$guard = empty($target_auth['auth_mod']) ? ' AND destination.forum_status <> ' . FORUM_LOCKED : '';
		$guard .= " AND COALESCE(destination.forum_link, '') = ''";
		$source_state = ' AND source.topic_moved_id = 0 AND source.topic_status IN (' . TOPIC_UNLOCKED . ',' . TOPIC_LOCKED . ')';
		$source_guard = $source_state
			. ' AND EXISTS (SELECT 1 FROM ' . POSTS_TABLE . " original WHERE original.post_id = $first_id AND original.topic_id = $source_topic AND original.forum_id = $source_forum)";
		$db->sql_query('INSERT INTO ' . TOPICS_TABLE . ' (topic_title, topic_poster, topic_time, forum_id, topic_status, topic_type)'
			. " SELECT '" . $db->sql_escape($subject) . "', p.poster_id, p.post_time, $target_forum, " . TOPIC_UNLOCKED . ', ' . POST_NORMAL
			. ' FROM ' . POSTS_TABLE . ' p JOIN ' . TOPICS_TABLE . " source ON source.topic_id = p.topic_id AND source.forum_id = p.forum_id JOIN " . FORUMS_TABLE . ' origin ON origin.forum_id = source.forum_id'
			. ' JOIN ' . FORUMS_TABLE . " destination ON destination.forum_id = $target_forum WHERE p.post_id = " . $selected[0] . " AND p.topic_id = $source_topic AND p.forum_id = $source_forum"
			. ' AND p.poster_id = ' . (int) $first_selected['poster_id'] . ' AND p.post_time = ' . (int) $first_selected['post_time'] . $source_guard . $guard);
		if ((int) $db->sql_affectedrows() !== 1) { phpbb_topic_split_error('Moderation_split_changed'); }
		$new_topic_id = (int) $db->sql_nextid();
		if ($new_topic_id <= 0 || $new_topic_id === $source_topic) { phpbb_topic_split_error('Moderation_split_failed'); }
		// Update only the complete, previously selected set, not a fresh broad
		// timestamp predicate that could include an intervening unrelated post.
		$db->sql_query('UPDATE ' . POSTS_TABLE . ' p JOIN ' . TOPICS_TABLE . ' source ON source.topic_id = p.topic_id AND source.forum_id = p.forum_id'
			. ' JOIN ' . FORUMS_TABLE . ' origin ON origin.forum_id = source.forum_id JOIN ' . FORUMS_TABLE . " destination ON destination.forum_id = $target_forum"
			. ' JOIN ' . TOPICS_TABLE . " target ON target.topic_id = $new_topic_id AND target.forum_id = $target_forum AND target.topic_moved_id = 0"
			. ' JOIN ' . POSTS_TABLE . " original ON original.post_id = $first_id AND original.topic_id = $source_topic AND original.forum_id = $source_forum"
			. " SET p.topic_id = $new_topic_id, p.forum_id = $target_forum WHERE p.post_id IN ($list) AND p.topic_id = $source_topic AND p.forum_id = $source_forum" . $source_state . $guard);
		if ((int) $db->sql_affectedrows() !== count($selected)) { phpbb_topic_split_error('Moderation_split_changed'); }
		phpbb_split_sync_topic($db, $source_topic, $source_forum); phpbb_split_sync_topic($db, $new_topic_id, $target_forum);
		attachment_sync_topic($source_topic, $db); attachment_sync_topic($new_topic_id, $db);
		if ($posters)
		{
			$db->sql_query('INSERT INTO ' . TOPICS_WATCH_TABLE . ' (topic_id, user_id, notify_status)'
				. " SELECT DISTINCT $new_topic_id, w.user_id, 0 FROM " . TOPICS_WATCH_TABLE . ' w LEFT JOIN ' . TOPICS_WATCH_TABLE
				. " existing ON existing.topic_id = $new_topic_id AND existing.user_id = w.user_id WHERE w.topic_id = $source_topic AND w.user_id IN (" . implode(',', $posters) . ') AND existing.topic_id IS NULL ORDER BY w.user_id');
			if ((bool) $forums[$source_forum]['count_posts'] !== (bool) $forums[$target_forum]['count_posts'])
			{
				$db->sql_query('UPDATE ' . USERS_TABLE . ' SET user_posts = (SELECT COUNT(*) FROM ' . POSTS_TABLE . ' p JOIN ' . FORUMS_TABLE
					. ' f ON f.forum_id = p.forum_id WHERE p.poster_id = ' . USERS_TABLE . '.user_id AND f.count_posts = 1) WHERE user_id IN (' . implode(',', $posters) . ') AND user_id > 0');
			}
		}
		require_once dirname(__FILE__) . '/functions_posting_storage.php'; require_once dirname(__FILE__) . '/functions_log.php';
		phpbb_posting_sync_forum($db, $source_forum); if ($target_forum !== $source_forum) { phpbb_posting_sync_forum($db, $target_forum); }
		if (!log_action('split', array($source_topic, $new_topic_id), $userdata['user_id'], $userdata['username'], $db)) { phpbb_topic_split_error('Moderation_split_failed'); }
		return array('topic_id' => $new_topic_id, 'post_ids' => $selected);
	}
	catch (PhpbbTopicSplitException $error)
	{
		// Remove only our newly allocated EMPTY topic after a failed publication.
		// Never roll back/delete a destination that already owns any user posts.
		if ($new_topic_id > 0 && !$lock->connection->sql_query('DELETE FROM ' . TOPICS_TABLE . " WHERE topic_id = $new_topic_id AND forum_id = $target_forum AND NOT EXISTS (SELECT 1 FROM " . POSTS_TABLE . " WHERE topic_id = $new_topic_id)"))
		{
			phpbb_topic_split_error('Moderation_split_failed');
		}
		throw $error;
	}
	finally { $lock->release(); }
}
