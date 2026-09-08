<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_topic_merge.php';
require_once dirname(__FILE__) . '/functions_post_subject.php';

class PhpbbTopicMergeException extends RuntimeException {}
function phpbb_merge_error($key)
{
	global $lang;
	throw new PhpbbTopicMergeException(isset($lang[$key]) ? $lang[$key] : $key);
}
class PhpbbTopicMergeDatabase
{
	var $connection;
	function __construct($connection) { $this->connection = $connection; }
	function __call($method, $args) { return call_user_func_array(array($this->connection, $method), $args); }
	function sql_query($sql, $transaction = false)
	{
		$result = $this->connection->sql_query($sql, $transaction);
		if (!$result) { phpbb_merge_error('Merge_storage_failed'); }
		return $result;
	}
}
function phpbb_merge_rows($db, $sql)
{
	$result = $db->sql_query($sql); $rows = $db->sql_fetchrowset($result); $db->sql_freeresult($result); return $rows;
}
function phpbb_merge_subject($subject)
{
	if (!is_string($subject) || strlen($subject) > 4096) { phpbb_merge_error('Merge_invalid_subject'); }
	$subject = phpbb_storage_subject(trim($subject));
	if ($subject === false) { phpbb_merge_error('Merge_invalid_subject'); }
	return $subject;
}

// Called only on the shared lock's own connection. Actual poll topology, not
// cached topic_vote flags, determines the warning and destructive decision.
function phpbb_merge_context($db, $from, $to)
{
	$from = phpbb_merge_id($from); $to = phpbb_merge_id($to);
	if (!$from || !$to) { phpbb_merge_error('Merge_changed'); }
	if ($from === $to) { phpbb_merge_error('Merge_topics_equals'); }
	$topics = array(); $forums = array();
	foreach (phpbb_merge_rows($db, 'SELECT topic_id, forum_id, topic_title, topic_status, topic_type, topic_moved_id, topic_views FROM ' . TOPICS_TABLE . " WHERE topic_id IN ($from,$to) ORDER BY topic_id") as $row)
	{
		if ((int) $row['topic_moved_id'] !== 0 || !in_array((int) $row['topic_status'], array(TOPIC_UNLOCKED, TOPIC_LOCKED), true)) { phpbb_merge_error('Merge_changed'); }
		$forum = (int) $row['forum_id'];
		if (!phpbb_merge_forum_allowed($db, $forum)) { phpbb_merge_error('Not_Authorised'); }
		$forums[$forum] = phpbb_merge_read_row($db, 'SELECT forum_id, forum_status, count_posts FROM ' . FORUMS_TABLE . ' WHERE forum_id = ' . $forum);
		if (!$forums[$forum]) { phpbb_merge_error('Merge_changed'); }
		$topics[(int) $row['topic_id']] = $row;
	}
	if (!isset($topics[$from], $topics[$to])) { phpbb_merge_error('Merge_changed'); }
	$polls = array($from => array(), $to => array()); $poll_ids = array();
	foreach (phpbb_merge_rows($db, 'SELECT vote_id, topic_id, vote_text, vote_start, vote_length FROM ' . VOTE_DESC_TABLE . " WHERE topic_id IN ($from,$to) ORDER BY vote_id") as $row)
	{
		$id = (int) $row['topic_id'];
		if ($polls[$id] || !phpbb_merge_id($row['vote_id'])) { phpbb_merge_error('Merge_changed'); }
		$polls[$id] = array_map('strval', $row); $poll_ids[] = (int) $row['vote_id'];
	}
	$options = $poll_ids ? phpbb_merge_rows($db, 'SELECT vote_id, vote_option_id, vote_option_text FROM ' . VOTE_RESULTS_TABLE . ' WHERE vote_id IN (' . implode(',', $poll_ids) . ') ORDER BY vote_id, vote_option_id') : array();
	$options = array_map(function($row) { return array_map('strval', $row); }, $options);
	// Views and newly arriving replies/votes need not invalidate confirmation.
	// Topic placement/type/title and poll questions/options must remain the same.
	$identity = array();
	foreach ($topics as $id => $row) { unset($row['topic_views']); $identity[$id] = array_map('strval', $row); }
	$forum_identity = array_map(function($row) { return array_map('strval', $row); }, $forums);
	return array('from'=>$from, 'to'=>$to, 'topics'=>$topics, 'forums'=>$forums, 'polls'=>$polls,
		'snapshot'=>hash('sha256', serialize(array($identity, $forum_identity, $polls, $options))));
}
function phpbb_merge_confirmation($context, $subject, $shadow)
{
	global $userdata;
	if (!is_bool($shadow) || empty($userdata['session_id'])) { phpbb_merge_error('Session_invalid'); }
	$intent = hash('sha256', serialize(array($context['snapshot'], $context['from'], $context['to'], phpbb_merge_subject($subject), $shadow)));
	return phpbb_session_action_token('topic-merge', $intent, $context['from'], $userdata['session_id']);
}
function phpbb_prepare_topic_merge($database, $from, $to, $subject, $shadow)
{
	$lock = new attach_mutation_lock($database);
	if (!$lock->acquired) { phpbb_merge_error('Attachment_storage_busy'); }
	try
	{
		$context = phpbb_merge_context(new PhpbbTopicMergeDatabase($lock->connection), $from, $to);
		$context['token'] = phpbb_merge_confirmation($context, $subject, $shadow);
		return $context;
	}
	finally { $lock->release(); }
}

// Endpoint checks POST/SID; this worker also binds the confirmed destructive
// choice to fresh topic/poll state. No network I/O takes place under this lock.
// Like the other legacy MyISAM writers, this is not crash-recovery journaling.
function phpbb_merge_topics($database, $from, $to, $subject, $shadow, $token)
{
	global $userdata;
	$subject = phpbb_merge_subject($subject);
	$lock = new attach_mutation_lock($database);
	if (!$lock->acquired) { phpbb_merge_error('Attachment_storage_busy'); }
	try
	{
		$db = new PhpbbTopicMergeDatabase($lock->connection);
		$context = phpbb_merge_context($db, $from, $to);
		// Confirmation canonicalizes plain text; do not escape it a second time.
		$plain_subject = html_entity_decode($subject, ENT_COMPAT, 'UTF-8');
		$expected = phpbb_merge_confirmation($context, $plain_subject, $shadow);
		if (!is_string($token) || !hash_equals($expected, $token)) { phpbb_merge_error('Merge_changed'); }
		$from = $context['from']; $to = $context['to'];
		$source_forum = (int) $context['topics'][$from]['forum_id']; $target_forum = (int) $context['topics'][$to]['forum_id'];
		$posts = phpbb_merge_rows($db, 'SELECT post_id, topic_id, forum_id, poster_id, post_time FROM ' . POSTS_TABLE . " WHERE topic_id IN ($from,$to) ORDER BY post_id");
		$selected = array(); $target_ids = array(); $posters = array(); $target_count = 0;
		foreach ($posts as $post)
		{
			$is_source = (int) $post['topic_id'] === $from;
			if ((int) $post['forum_id'] !== ($is_source ? $source_forum : $target_forum)) { phpbb_merge_error('Merge_changed'); }
			if ($is_source) { $selected[] = (int) $post['post_id']; if ((int) $post['poster_id'] > 0) { $posters[(int) $post['poster_id']] = (int) $post['poster_id']; } }
			else { $target_count++; $target_ids[] = (int) $post['post_id']; }
		}
		if (!$selected || !$target_count || count($posts) > 16777216) { phpbb_merge_error('Merge_changed'); }
		$first = $posts[0]; $last = $posts[count($posts)-1]; $list = implode(',', $selected); $target_list = implode(',', $target_ids);
		// Resolve dependent data before publishing any change. Keep source rows
		// until successful copies; never delete post text, index or attachment IDs.
		$views = phpbb_merge_rows($db, 'SELECT user_id, view_time, view_count FROM ' . TOPIC_VIEW_TABLE . " WHERE topic_id = $from ORDER BY user_id");
		$view_totals = array();
		foreach ($views as $view)
		{
			$id = (int) $view['user_id']; if (!isset($view_totals[$id])) { $view_totals[$id] = array(0, 0); }
			$view_totals[$id][0] = max($view_totals[$id][0], (int) $view['view_time']);
			$view_totals[$id][1] = min(2147483647, $view_totals[$id][1] + max(0, (int) $view['view_count']));
		}
		$db->sql_query('UPDATE ' . POSTS_TABLE . ' p JOIN ' . TOPICS_TABLE . ' source ON source.topic_id = p.topic_id AND source.forum_id = p.forum_id'
			. ' JOIN ' . TOPICS_TABLE . " target ON target.topic_id = $to AND target.forum_id = $target_forum"
			// Aggregate derived tables are materialized, including on MySQL.
			// A changed/incomplete post set therefore rejects the whole UPDATE,
			// not just one row after other selected posts have already moved.
			. " JOIN (SELECT topic_id, COUNT(*) AS total, SUM(CASE WHEN post_id IN ($list) THEN 1 ELSE 0 END) AS selected_total, MIN(forum_id) AS first_forum, MAX(forum_id) AS last_forum FROM " . POSTS_TABLE
			. " WHERE topic_id = $from GROUP BY topic_id) source_posts ON source_posts.topic_id = source.topic_id AND source_posts.total = " . count($selected)
			. ' AND source_posts.selected_total = ' . count($selected)
			. " AND source_posts.first_forum = $source_forum AND source_posts.last_forum = $source_forum"
			. " JOIN (SELECT topic_id, COUNT(*) AS total, SUM(CASE WHEN post_id IN ($target_list) THEN 1 ELSE 0 END) AS selected_total, MIN(forum_id) AS first_forum, MAX(forum_id) AS last_forum FROM " . POSTS_TABLE
			. " WHERE topic_id = $to GROUP BY topic_id) target_posts ON target_posts.topic_id = target.topic_id AND target_posts.total = $target_count"
			. " AND target_posts.selected_total = $target_count"
			. " AND target_posts.first_forum = $target_forum AND target_posts.last_forum = $target_forum"
			. ' JOIN ' . FORUMS_TABLE . ' origin ON origin.forum_id = source.forum_id JOIN ' . FORUMS_TABLE . ' destination ON destination.forum_id = target.forum_id'
			. " SET p.topic_id = $to, p.forum_id = $target_forum WHERE p.post_id IN ($list) AND p.topic_id = $from AND p.forum_id = $source_forum"
			. ' AND source.topic_moved_id = 0 AND target.topic_moved_id = 0 AND source.topic_status IN (' . TOPIC_UNLOCKED . ',' . TOPIC_LOCKED . ')'
			. ' AND target.topic_status IN (' . TOPIC_UNLOCKED . ',' . TOPIC_LOCKED . ") AND COALESCE(origin.forum_link, '') = '' AND COALESCE(destination.forum_link, '') = ''");
		if ((int) $db->sql_affectedrows() !== count($selected)) { phpbb_merge_error('Merge_changed'); }
		foreach (array(BOOKMARK_TABLE, TOPICS_WATCH_TABLE) as $table)
		{
			$watch = $table === TOPICS_WATCH_TABLE;
			$db->sql_query('INSERT INTO ' . $table . ' (topic_id, user_id' . ($watch ? ', notify_status' : '') . ')'
				. " SELECT DISTINCT $to, old.user_id" . ($watch ? ', 0' : '') . ' FROM ' . $table . ' old LEFT JOIN ' . $table
				. " existing ON existing.topic_id = $to AND existing.user_id = old.user_id WHERE old.topic_id = $from AND old.user_id > 0 AND existing.user_id IS NULL");
		}
		// Preserve existing target rows, including legacy duplicates. Distribute
		// the source total once, rather than multiplying it by those duplicates.
		foreach ($view_totals as $id => $totals)
		{
			$existing = phpbb_merge_rows($db, 'SELECT view_count FROM ' . TOPIC_VIEW_TABLE . " WHERE topic_id = $to AND user_id = $id");
			if (!$existing) { $db->sql_query('INSERT INTO ' . TOPIC_VIEW_TABLE . " (topic_id,user_id,view_time,view_count) VALUES ($to,$id," . $totals[0] . ',' . $totals[1] . ')'); }
			else
			{
				$old_total = 0; foreach ($existing as $row) { $old_total += max(0, (int) $row['view_count']); }
				$add = min($totals[1], max(0, 2147483647 - $old_total)); $each = (int) floor($add / count($existing)); $remainder = $add % count($existing);
				$db->sql_query('UPDATE ' . TOPIC_VIEW_TABLE . ' SET view_time = CASE WHEN view_time < ' . $totals[0] . ' THEN ' . $totals[0] . ' ELSE view_time END, view_count = CASE WHEN view_count < 0 THEN 0 ELSE view_count END + ' . $each . " WHERE topic_id = $to AND user_id = $id");
				if ($remainder) { $db->sql_query('UPDATE ' . TOPIC_VIEW_TABLE . ' SET view_count = view_count + ' . $remainder . " WHERE topic_id = $to AND user_id = $id LIMIT 1"); }
			}
		}
		$source_poll = $context['polls'][$from]; $target_poll = $context['polls'][$to];
		if ($source_poll && !$target_poll)
		{
			$db->sql_query('UPDATE ' . VOTE_DESC_TABLE . " SET topic_id = $to WHERE topic_id = $from AND vote_id = " . (int) $source_poll['vote_id']);
			if ((int) $db->sql_affectedrows() !== 1) { phpbb_merge_error('Merge_changed'); }
		}
		$title = $subject === '' ? $context['topics'][$to]['topic_title'] : $subject;
		$views_total = min(16777215, max(0, (int) $context['topics'][$from]['topic_views']) + max(0, (int) $context['topics'][$to]['topic_views']));
		$db->sql_query('UPDATE ' . TOPICS_TABLE . " SET topic_title = '" . $db->sql_escape($title) . "', topic_replies = " . (count($posts)-1)
			. ', topic_first_post_id = ' . (int) $first['post_id'] . ', topic_last_post_id = ' . (int) $last['post_id']
			. ', topic_poster = ' . (int) $first['poster_id'] . ', topic_time = ' . (int) $first['post_time']
			. ', topic_views = ' . $views_total . ', topic_vote = ' . (($source_poll || $target_poll) ? 1 : 0) . " WHERE topic_id = $to AND forum_id = $target_forum AND topic_moved_id = 0");
		attachment_sync_topic($to, $db);
		if ((bool) $context['forums'][$source_forum]['count_posts'] !== (bool) $context['forums'][$target_forum]['count_posts'] && $posters)
		{
			$db->sql_query('UPDATE ' . USERS_TABLE . ' SET user_posts = (SELECT COUNT(*) FROM ' . POSTS_TABLE . ' p JOIN ' . FORUMS_TABLE
				. ' f ON f.forum_id = p.forum_id WHERE p.poster_id = ' . USERS_TABLE . '.user_id AND f.count_posts = 1) WHERE user_id IN (' . implode(',', $posters) . ') AND user_id > 0');
		}
		// Only the explicitly confirmed conflicting source poll is discarded,
		// after the posts and destination metadata have been published.
		if ($source_poll && $target_poll)
		{
			$vote = (int) $source_poll['vote_id'];
			foreach (array(VOTE_USERS_TABLE, VOTE_RESULTS_TABLE, VOTE_DESC_TABLE) as $table) { $db->sql_query('DELETE FROM ' . $table . ' WHERE vote_id = ' . $vote); }
		}
		foreach (array(BOOKMARK_TABLE, TOPICS_WATCH_TABLE, TOPIC_VIEW_TABLE) as $table) { $db->sql_query('DELETE FROM ' . $table . " WHERE topic_id = $from"); }
		// Retarget only empty redirects; malformed nonempty topics are untouched.
		$db->sql_query('UPDATE ' . TOPICS_TABLE . " SET topic_moved_id = $to WHERE topic_moved_id = $from AND topic_status = " . TOPIC_MOVED
			. ' AND NOT EXISTS (SELECT 1 FROM ' . POSTS_TABLE . ' WHERE ' . POSTS_TABLE . '.topic_id = ' . TOPICS_TABLE . '.topic_id)');
		$empty_guard = " WHERE topic_id = $from AND forum_id = $source_forum AND topic_moved_id = 0 AND NOT EXISTS (SELECT 1 FROM " . POSTS_TABLE . " WHERE topic_id = $from)";
		if ($shadow)
		{
			// Keep valid first/last post pointers so the legacy topic-list joins
			// can still render the redirect (the posts now belong to the target).
			$db->sql_query('UPDATE ' . TOPICS_TABLE . ' SET topic_status = ' . TOPIC_MOVED . ', topic_type = ' . POST_NORMAL . ", topic_moved_id = $to, topic_vote = 0, topic_attachment = 0" . $empty_guard);
		}
		else { $db->sql_query('DELETE FROM ' . TOPICS_TABLE . $empty_guard); }
		if ((int) $db->sql_affectedrows() !== 1) { phpbb_merge_error('Merge_changed'); }
		require_once dirname(__FILE__) . '/functions_posting_storage.php'; require_once dirname(__FILE__) . '/functions_log.php';
		phpbb_posting_sync_forum($db, $source_forum); if ($target_forum !== $source_forum) { phpbb_posting_sync_forum($db, $target_forum); }
		if (!log_action('merge', array($from, $to), $userdata['user_id'], $userdata['username'], $db)) { phpbb_merge_error('Merge_storage_failed'); }
		return $to;
	}
	finally { $lock->release(); }
}
