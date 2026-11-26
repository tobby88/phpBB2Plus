<?php
if (!defined('IN_PHPBB')) { exit; }

/** Apply Plus semantics to the core PM and subscription tables. */
function plus_published_pm_condition($src_db, $prefix)
{
	// Type 6 is the Plus read checkpoint. An unfamiliar custom type can
	// retain its original body without receiving any native mailbox entry.
	$condition = 'privmsgs.privmsgs_type <> 6';
	$tools = (new \phpbb\db\tools\factory())->get($src_db);
	if ($tools->sql_column_exists($prefix . 'privmsgs', 'privmsgs_write_payload'))
	{
		$condition .= ' AND privmsgs.privmsgs_write_payload IS NULL';
	}
	return $condition;
}

function plus_pm_recipient($source_id)
{
	$id = plus_existing_author($source_id);
	return $id === ANONYMOUS ? '' : 'u_' . $id;
}

function plus_pm_incoming_folder($type)
{
	return (int) $type === 1 ? PRIVMSGS_NO_BOX : PRIVMSGS_INBOX;
}

function plus_pm_unread($type)
{
	return in_array((int) $type, array(1, 5), true) ? 1 : 0;
}

function plus_sync_new_pms()
{
	global $db, $src_db, $convert, $config;
	$prefix = $convert->src_table_prefix;
	$result = $src_db->sql_query('SELECT COUNT(*) AS unfinished FROM ' . $prefix . 'privmsgs privmsgs WHERE NOT (' .
		plus_published_pm_condition($src_db, $prefix) . ')');
	$unfinished = (int) $src_db->sql_fetchfield('unfinished');
	$src_db->sql_freeresult($result);
	$config->set('plus_pm_unfinished', $unfinished);
	$result = $db->sql_query('SELECT user_id, COUNT(*) AS pending FROM ' . PRIVMSGS_TO_TABLE . '
		WHERE pm_new = 1 AND folder_id = ' . PRIVMSGS_NO_BOX . ' GROUP BY user_id');
	$rows = $db->sql_fetchrowset($result);
	$db->sql_freeresult($result);
	foreach ($rows as $row)
	{
		$db->sql_query('UPDATE ' . USERS_TABLE . ' SET user_new_privmsg = ' . (int) $row['pending']
			. ' WHERE user_id = ' . (int) $row['user_id']);
	}
}

function plus_watch_status($topic_id, $user_id)
{
	global $src_db, $convert;
	static $topic = null, $states = array();
	$topic_id = (int) $topic_id;
	$user_id = (int) $user_id;
	if ($topic !== $topic_id || !isset($states[$user_id]))
	{
		$topic = $topic_id;
		$states = array();
		// Old installations permit duplicate watch rows. A notification already
		// delivered for any of them must not be mailed again after cutover.
		$sql = 'SELECT user_id, MAX(notify_status) AS notified FROM ' . $convert->src_table_prefix . "topics_watch
			WHERE topic_id = $topic_id AND user_id >= $user_id GROUP BY user_id ORDER BY user_id";
		$result = $src_db->sql_query_limit($sql, 128);
		while ($row = $src_db->sql_fetchrow($result))
		{
			$states[(int) $row['user_id']] = $row['notified'] ? NOTIFY_NO : NOTIFY_YES;
		}
		$src_db->sql_freeresult($result);
	}
	if (!isset($states[$user_id])) { throw new \RuntimeException('A topic subscription changed during conversion.'); }
	return $states[$user_id];
}

/** Use the wizard's resumable, ordered pass and the exact text stored by phpBB3. */
function plus_index_native_post($post_id)
{
	global $db, $convert;
	static $rows = array(), $last = 0;
	if (!($convert->fulltext_search instanceof \phpbb\search\fulltext_native)) { return; }

	$post_id = (int) $post_id;
	if ($post_id > $last || !$last)
	{
		$sql = 'SELECT p.post_id, p.post_text, p.post_subject, p.poster_id, p.forum_id, f.enable_indexing
			FROM ' . POSTS_TABLE . ' p JOIN ' . FORUMS_TABLE . ' f ON f.forum_id = p.forum_id
			WHERE p.post_id >= ' . $post_id . ' ORDER BY p.post_id';
		$result = $db->sql_query_limit($sql, 32);
		$rows = array();
		$last = $post_id;
		while ($row = $db->sql_fetchrow($result))
		{
			$last = (int) $row['post_id'];
			if ($row['enable_indexing']) { $rows[$last] = $row; }
		}
		$db->sql_freeresult($result);
	}
	if (isset($rows[$post_id]))
	{
		$row = $rows[$post_id];
		// "edit" reconciles existing terms if the same conversion batch is retried.
		$convert->fulltext_search->index('edit', $post_id, $row['post_text'], $row['post_subject'], $row['poster_id'], $row['forum_id']);
		unset($rows[$post_id]);
	}
}
