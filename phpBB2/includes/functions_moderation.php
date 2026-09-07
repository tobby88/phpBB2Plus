<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }

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

// Internal: modcp has checked the moderator's delete permission and POST session.
// Requalify every parent against that forum on the attachment lock's connection.
// MyISAM/filesystem cleanup is not transactional; stop explicitly on a partial
// failure and retain remaining text/file metadata rather than guessing success.
function phpbb_delete_moderated_topics($database, $forum_id, $topic_ids)
{
	global $lang;
	$forums = (is_int($forum_id) || is_string($forum_id)) && preg_match('/^[0-9]+$/D', (string) $forum_id) ? attach_delete_id_array($forum_id) : false;
	$topics = is_array($topic_ids) ? attach_delete_id_array($topic_ids) : false;
	if ($forums === false || count($forums) !== 1 || $topics === false || !$topics)
	{
		message_die(GENERAL_MESSAGE, $lang['None_selected']);
	}
	$forum_id = $forums[0];
	$removed = array('topic_ids' => array(), 'post_ids' => array());
	$lock = attach_require_mutation_lock($database);
	try
	{
		$storage = $lock->connection;
		$result = phpbb_moderation_query($storage, 'SELECT count_posts FROM ' . FORUMS_TABLE . ' WHERE forum_id = ' . $forum_id);
		$forum = $storage->sql_fetchrow($result); $storage->sql_freeresult($result);
		if (!$forum) { message_die(GENERAL_MESSAGE, $lang['None_selected']); }
		$count_posts = !empty($forum['count_posts']);
		$result = phpbb_moderation_query($storage, 'SELECT topic_id FROM ' . TOPICS_TABLE .
			' WHERE forum_id = ' . $forum_id . ' AND topic_id IN (' . implode(', ', $topics) . ') ORDER BY topic_id');
		$selected = $storage->sql_fetchrowset($result); $storage->sql_freeresult($result);
		foreach ($selected as $topic)
		{
			$topic_id = (int) $topic['topic_id'];
			phpbb_moderation_query($storage, 'DELETE FROM ' . TOPICS_TABLE . ' WHERE topic_id = ' . $topic_id . ' AND forum_id = ' . $forum_id);
			if ((int) $storage->sql_affectedrows() !== 1) { continue; }
			$removed['topic_ids'][] = $topic_id;

			$result = phpbb_moderation_query($storage, 'SELECT post_id, poster_id FROM ' . POSTS_TABLE .
				' WHERE topic_id = ' . $topic_id . ' AND forum_id = ' . $forum_id . ' ORDER BY post_id');
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
				attach_delete_selected($storage, array($post_id), array(), 0, 0, false, true);
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
			phpbb_moderation_query($storage, 'DELETE FROM ' . TOPICS_TABLE . ' WHERE topic_moved_id = ' . $topic_id .
				' AND NOT EXISTS (SELECT 1 FROM ' . POSTS_TABLE . ' WHERE ' . POSTS_TABLE . '.topic_id = ' . TOPICS_TABLE . '.topic_id)');
		}
	}
	finally { $lock->release(); }
	return $removed;
}
