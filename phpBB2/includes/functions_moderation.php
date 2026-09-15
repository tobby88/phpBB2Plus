<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_moderator_identity.php';
require_once dirname(__FILE__) . '/functions_topic_removal_storage.php';

function phpbb_moderation_query($database, $sql)
{
	global $lang;
	$result = $database->sql_query($sql);
	if (!$result)
	{
		message_die(GENERAL_ERROR, $lang['Moderation_delete_failed'], '', __LINE__, __FILE__, $sql);
	}
	return $result;
}

// Recheck modcp's confirmed POST and current account/permissions on the owner.
// Requalify every parent against that forum on the attachment lock's connection.
// Core deletion is atomic. Physical files are removed only after confirmed
// COMMIT, with retained descriptions reserving any pending cleanup.
function phpbb_delete_moderated_topics($database, $forum_id, $topic_ids)
{
	global $lang;
	require_once dirname(__FILE__) . '/functions_posting_storage.php';
	require_once dirname(__FILE__) . '/functions_search.php';
	$forums = (is_int($forum_id) || is_string($forum_id)) && preg_match('/^[0-9]+$/D', (string) $forum_id) ? attach_delete_id_array($forum_id) : false;
	$topics = is_array($topic_ids) ? attach_delete_id_array($topic_ids) : false;
	if ($forums === false || count($forums) !== 1 || $topics === false || !$topics)
	{
		message_die(GENERAL_MESSAGE, $lang['None_selected']);
	}
	$forum_id = $forums[0];
	$lock = attach_require_mutation_lock($database);
	$storage = null;
	try
	{
		global $userdata;
		if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['sid']) || !is_string($_POST['sid'])
			|| empty($userdata['session_id']) || !hash_equals((string)$userdata['session_id'], $_POST['sid']))
		{
			throw new PhpbbPostSubmitException('Session_invalid');
		}
		$storage = new PhpbbTopicRemovalDatabase($lock->connection, $forum_id, 'moderator');
		$storage->begin();
		$removed = phpbb_delete_moderated_topics_owned($storage, $forum_id, $topics);
		$storage->audit($removed['topic_ids']); $storage->commit();
		$removed['_audit_completed'] = true; $removed['cleanup_pending'] = !$storage->cleanup();
		return $removed;
	}
	catch (PhpbbPostSubmitException $e) { $key = $e->getMessage(); message_die(GENERAL_MESSAGE, isset($lang[$key]) ? $lang[$key] : $lang['Moderation_delete_unconfirmed']); }
	catch (Exception $e) { message_die(GENERAL_ERROR, $lang['Moderation_delete_unconfirmed']); }
	catch (Error $e) { message_die(GENERAL_ERROR, $lang['Moderation_delete_unconfirmed']); }
	finally { if ($storage !== null) { $storage->rollback(); } $lock->release(); }
}

// Internal shared worker. The caller owns the writer lock and has validated
// permissions and numeric selection. Optional guard SQL is constructed only by
// trusted maintenance code, never supplied from a request; repeat it at DELETE.
function phpbb_delete_moderated_topics_owned($storage, $forum_id, $topics, $topic_guard = '')
{
	global $lang;
	if (!($storage instanceof PhpbbTopicRemovalDatabase) || !$storage->transactional || (int)$forum_id !== $storage->forum_id) { throw new PhpbbPostSubmitException('Moderation_delete_unconfirmed'); }
	require_once dirname(__FILE__) . '/functions_posting_storage.php';
	require_once dirname(__FILE__) . '/functions_search.php';
	$removed = array('topic_ids' => array(), 'post_ids' => array());
	$topic_guard .= ' AND NOT EXISTS (SELECT 1 FROM ' . POSTS_TABLE . ' foreign_post WHERE foreign_post.topic_id = ' . TOPICS_TABLE . '.topic_id AND foreign_post.forum_id <> ' . (int)$forum_id . ')';
	$result = phpbb_moderation_query($storage, 'SELECT count_posts FROM ' . FORUMS_TABLE . ' WHERE forum_id = ' . $forum_id);
	$forum = $storage->sql_fetchrow($result); $storage->sql_freeresult($result);
	if (!$forum) { message_die(GENERAL_MESSAGE, $lang['None_selected']); }
	$count_posts = !empty($forum['count_posts']);
	$result = phpbb_moderation_query($storage, 'SELECT topic_id FROM ' . TOPICS_TABLE .
		' WHERE forum_id = ' . $forum_id . ' AND topic_id IN (' . implode(', ', $topics) . ')' . $topic_guard . ' ORDER BY topic_id FOR UPDATE');
	$selected = $storage->sql_fetchrowset($result); $storage->sql_freeresult($result);
	foreach ($selected as $topic)
	{
		$topic_id = (int) $topic['topic_id'];
		phpbb_moderation_query($storage, 'DELETE FROM ' . TOPICS_TABLE . ' WHERE topic_id = ' . $topic_id . ' AND forum_id = ' . $forum_id . $topic_guard);
		if ((int) $storage->sql_affectedrows() !== 1) { continue; }
		$removed['topic_ids'][] = $topic_id;

		$result = phpbb_moderation_query($storage, 'SELECT post_id, poster_id FROM ' . POSTS_TABLE .
			' WHERE topic_id = ' . $topic_id . ' AND forum_id = ' . $forum_id . ' ORDER BY post_id FOR UPDATE');
		$posts = $storage->sql_fetchrowset($result); $storage->sql_freeresult($result);
		foreach ($posts as $post)
		{
			$post_id = (int) $post['post_id']; $poster_id = (int) $post['poster_id'];
			phpbb_moderation_query($storage, 'DELETE FROM ' . POSTS_TABLE . ' WHERE post_id = ' . $post_id .
				' AND topic_id = ' . $topic_id . ' AND forum_id = ' . $forum_id . ' AND poster_id = ' . $poster_id);
			if ((int) $storage->sql_affectedrows() !== 1)
			{
				message_die(GENERAL_ERROR, $lang['Moderation_delete_changed']);
			}
			$removed['post_ids'][] = $post_id;
			if ($count_posts && $poster_id > 0)
			{
				phpbb_moderation_query($storage, 'UPDATE ' . USERS_TABLE .
					' SET user_posts = CASE WHEN user_posts > 0 THEN user_posts - 1 ELSE 0 END WHERE user_id = ' . $poster_id);
			}
			phpbb_moderation_query($storage, 'DELETE FROM ' . POSTS_TEXT_TABLE . ' WHERE post_id = ' . $post_id);
			remove_search_post($post_id, true, true, $storage);
			$storage->detach_post_files($post_id);
		}

		$result = phpbb_moderation_query($storage, 'SELECT vote_id FROM ' . VOTE_DESC_TABLE . ' WHERE topic_id = ' . $topic_id);
		$votes = $storage->sql_fetchrowset($result); $storage->sql_freeresult($result);
		foreach ($votes as $vote)
		{
			$vote_id = (int) $vote['vote_id'];
			phpbb_moderation_query($storage, 'DELETE FROM ' . VOTE_DESC_TABLE . ' WHERE vote_id = ' . $vote_id .
				' AND topic_id = ' . $topic_id . ' AND NOT EXISTS (SELECT 1 FROM ' . TOPICS_TABLE . ' WHERE topic_id = ' . $topic_id . ')');
			if ((int) $storage->sql_affectedrows() !== 1) { continue; }
			foreach (array(VOTE_RESULTS_TABLE, VOTE_USERS_TABLE) as $table)
			{
				phpbb_moderation_query($storage, 'DELETE FROM ' . $table . ' WHERE vote_id = ' . $vote_id .
					' AND NOT EXISTS (SELECT 1 FROM ' . VOTE_DESC_TABLE . ' WHERE vote_id = ' . $vote_id . ')');
			}
		}
		foreach (array(BOOKMARK_TABLE, TOPICS_WATCH_TABLE, TOPIC_VIEW_TABLE) as $table)
		{
			phpbb_moderation_query($storage, 'DELETE FROM ' . $table . ' WHERE topic_id = ' . $topic_id .
				' AND NOT EXISTS (SELECT 1 FROM ' . TOPICS_TABLE . ' WHERE topic_id = ' . $topic_id . ')');
		}
		// Remove only empty redirect stubs; malformed stubs containing posts
		// must not hide another topic's stored content as a side effect.
		phpbb_posting_cleanup_empty_redirects($storage, $topic_id);
	}
	phpbb_posting_sync_forum($storage, $forum_id);
	return $removed;
}
