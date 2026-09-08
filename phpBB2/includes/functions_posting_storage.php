<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }

function phpbb_posting_query($database, $sql)
{
	global $lang;
	$result = $database->sql_query($sql);
	if (!$result) { message_die(GENERAL_ERROR, $lang['Posting_storage_failed'], '', __LINE__, __FILE__, $sql); }
	return $result;
}

function phpbb_posting_scope_id($id)
{
	global $lang;
	$ids = (is_int($id) || is_string($id)) && preg_match('/^[0-9]+$/D', (string) $id) ? attach_delete_id_array($id) : false;
	if ($ids === false || count($ids) !== 1) { message_die(GENERAL_MESSAGE, $lang['Topic_post_not_exist']); }
	return $ids[0];
}

// Internal: posting.php has checked endpoint permissions and the POST session.
// Re-read the selected parent and destructive-operation flags on the same
// connection used by the protected writer, not from an earlier form snapshot.
function phpbb_posting_revalidate($database, $mode, &$post_data, &$forum_id, &$topic_id, &$post_id, &$poll_id)
{
	global $lang, $userdata, $is_auth;
	if (!in_array($mode, array('newtopic', 'reply', 'editpost', 'delete', 'poll_delete'), true))
	{
		message_die(GENERAL_MESSAGE, $lang['No_valid_mode']);
	}
	$forum_id = phpbb_posting_scope_id($forum_id);
	$is_mod = !empty($is_auth['auth_mod']);
	$result = phpbb_posting_query($database, 'SELECT forum_status FROM ' . FORUMS_TABLE . ' WHERE forum_id = ' . $forum_id);
	$forum = $database->sql_fetchrow($result); $database->sql_freeresult($result);
	if (!$forum) { message_die(GENERAL_MESSAGE, $lang['Topic_post_not_exist']); }
	if (!$is_mod && (int) $forum['forum_status'] === FORUM_LOCKED) { message_die(GENERAL_MESSAGE, $lang['Forum_locked']); }
	if ($mode === 'newtopic') { return; }

	$topic_id = phpbb_posting_scope_id($topic_id);
	$result = phpbb_posting_query($database, 'SELECT topic_status, topic_moved_id FROM ' . TOPICS_TABLE . ' WHERE topic_id = ' . $topic_id . ' AND forum_id = ' . $forum_id);
	$topic = $database->sql_fetchrow($result); $database->sql_freeresult($result);
	if (!$topic || !empty($topic['topic_moved_id'])) { message_die(GENERAL_MESSAGE, $lang['Topic_post_not_exist']); }
	if (!$is_mod && (int) $topic['topic_status'] === TOPIC_LOCKED) { message_die(GENERAL_MESSAGE, $lang['Topic_locked']); }
	if ($mode === 'reply') { return; }

	$post_id = phpbb_posting_scope_id($post_id);
	$result = phpbb_posting_query($database, 'SELECT p.poster_id FROM ' . POSTS_TABLE . ' p WHERE p.post_id = ' . $post_id . ' AND p.topic_id = ' . $topic_id . ' AND p.forum_id = ' . $forum_id);
	$post = $database->sql_fetchrow($result); $database->sql_freeresult($result);
	if (!$post) { message_die(GENERAL_MESSAGE, $lang['Topic_post_not_exist']); }
	$post_data['poster_id'] = (int) $post['poster_id'];
	$post_data['poster_post'] = $post_data['poster_id'] === (int) $userdata['user_id'];
	if (!$is_mod && !$post_data['poster_post']) { message_die(GENERAL_MESSAGE, $lang[$mode === 'delete' ? 'Delete_own_posts' : 'Edit_own_posts']); }
	if ($mode === 'editpost')
	{
		$result = phpbb_posting_query($database, 'SELECT post_id FROM ' . POSTS_TEXT_TABLE . ' WHERE post_id = ' . $post_id);
		$text = $database->sql_fetchrow($result); $database->sql_freeresult($result);
		if (!$text) { message_die(GENERAL_MESSAGE, $lang['Topic_post_not_exist']); }
	}
	$result = phpbb_posting_query($database, 'SELECT MIN(post_id) AS first_post, MAX(post_id) AS last_post FROM ' . POSTS_TABLE . ' WHERE topic_id = ' . $topic_id);
	$bounds = $database->sql_fetchrow($result); $database->sql_freeresult($result);
	$post_data['first_post'] = (int) $bounds['first_post'] === $post_id;
	$post_data['last_post'] = (int) $bounds['last_post'] === $post_id;
	$result = phpbb_posting_query($database, 'SELECT MAX(post_id) AS last_post FROM ' . POSTS_TABLE . ' WHERE forum_id = ' . $forum_id);
	$last = $database->sql_fetchrow($result); $database->sql_freeresult($result);
	$post_data['last_topic'] = (int) $last['last_post'] === $post_id;
	if ($mode === 'delete' && !$is_mod && !$post_data['last_post']) { message_die(GENERAL_MESSAGE, $lang['Cannot_delete_replied']); }

	$result = phpbb_posting_query($database, 'SELECT vote_id FROM ' . VOTE_DESC_TABLE . ' WHERE topic_id = ' . $topic_id);
	$poll = $database->sql_fetchrow($result); $database->sql_freeresult($result);
	if ($post_data['first_post'] && $mode !== 'delete' && ((bool) $poll !== !empty($post_data['has_poll']) || ($poll && (int) $poll['vote_id'] !== (int) $poll_id)))
	{
		message_die(GENERAL_MESSAGE, $lang['Posting_target_changed']);
	}
	$post_data['has_poll'] = (bool) $poll;
	$poll_id = $poll ? (int) $poll['vote_id'] : 0;
	if (!$post_data['first_post']) { $post_data['edit_poll'] = false; }
	if ($mode === 'poll_delete' && (!$poll || !$post_data['first_post'] || (!$is_mod && empty($post_data['edit_poll']))))
	{
		message_die(GENERAL_MESSAGE, $lang['Cannot_delete_poll']);
	}
	if ($poll && !$is_mod && ($mode === 'poll_delete' || ($mode === 'editpost' && !empty($post_data['edit_poll']))))
	{
		$result = phpbb_posting_query($database, 'SELECT SUM(vote_result) AS votes FROM ' . VOTE_RESULTS_TABLE . ' WHERE vote_id = ' . $poll_id);
		$votes = $database->sql_fetchrow($result); $database->sql_freeresult($result);
		if (!empty($votes['votes'])) { message_die(GENERAL_MESSAGE, $lang['Posting_target_changed']); }
	}
}

// Keep forum synchronization inside the shared writer boundary. Redirect stubs
// count as topics, while post totals are derived only from actual post rows.
function phpbb_posting_sync_forum($database, $forum_id)
{
	$forum_id = phpbb_posting_scope_id($forum_id);
	phpbb_posting_query($database, 'UPDATE ' . FORUMS_TABLE . ' SET forum_posts = (SELECT COUNT(*) FROM ' . POSTS_TABLE . ' WHERE forum_id = ' . $forum_id . '), forum_topics = (SELECT COUNT(*) FROM ' . TOPICS_TABLE . ' WHERE forum_id = ' . $forum_id . '), forum_last_post_id = (SELECT COALESCE(MAX(post_id), 0) FROM ' . POSTS_TABLE . ' WHERE forum_id = ' . $forum_id . ') WHERE forum_id = ' . $forum_id);
}

function phpbb_posting_cleanup_empty_redirects($database, $topic_id)
{
	$topic_id = phpbb_posting_scope_id($topic_id);
	$where = ' WHERE topic_moved_id = ' . $topic_id . ' AND NOT EXISTS (SELECT 1 FROM ' . POSTS_TABLE . ' WHERE ' . POSTS_TABLE . '.topic_id = ' . TOPICS_TABLE . '.topic_id)';
	$result = phpbb_posting_query($database, 'SELECT topic_id, forum_id FROM ' . TOPICS_TABLE . $where . ' ORDER BY topic_id');
	$redirects = array(); $forums = array();
	while ($row = $database->sql_fetchrow($result)) { $redirects[] = $row; }
	$database->sql_freeresult($result);
	foreach ($redirects as $redirect)
	{
		$id = (int) $redirect['topic_id']; $forum = (int) $redirect['forum_id'];
		phpbb_posting_query($database, 'DELETE FROM ' . TOPICS_TABLE . $where . ' AND topic_id = ' . $id . ' AND forum_id = ' . $forum);
		if ((int) $database->sql_affectedrows() !== 1) { continue; }
		$forums[$forum] = $forum;
		// A stale/changed stub must not authorize deleting its dependent rows.
		// Recheck absence on every write, including for malformed legacy data.
		foreach (array(TOPICS_WATCH_TABLE, BOOKMARK_TABLE, TOPIC_VIEW_TABLE) as $table)
		{
			phpbb_posting_query($database, 'DELETE FROM ' . $table . ' WHERE topic_id = ' . $id
				. ' AND NOT EXISTS (SELECT 1 FROM ' . TOPICS_TABLE . ' WHERE topic_id = ' . $id . ')');
		}
	}
	foreach ($forums as $forum_id) { phpbb_posting_sync_forum($database, $forum_id); }
}
