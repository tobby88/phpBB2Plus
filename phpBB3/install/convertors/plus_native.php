<?php
if (!defined('IN_PHPBB')) { exit; }

/** Apply Plus semantics to the core PM and subscription tables. */
function plus_pm_incoming_folder($type)
{
	return (int) $type === 1 ? PRIVMSGS_NO_BOX : PRIVMSGS_INBOX;
}

function plus_pm_unread($type)
{
	return in_array((int) $type, array(1, 5), true) ? 1 : 0;
}

/** The stock "limited access" role includes voting even when the old public forum required a login. */
function plus_guest_poll_permissions()
{
	global $db, $src_db, $convert, $phpbb_root_path, $phpEx;
	require_once $phpbb_root_path . 'includes/acp/auth.' . $phpEx;
	$admin = new \auth_admin();
	$result = $db->sql_query('SELECT group_id FROM ' . GROUPS_TABLE . " WHERE group_name='GUESTS' AND group_type=" . GROUP_SPECIAL);
	$guest = (int) $db->sql_fetchfield('group_id');
	$db->sql_freeresult($result);
	if (!$guest) { throw new \RuntimeException('The destination guest group is missing.'); }
	$groups = $admin->acl_group_raw_data($guest, 'f_%');
	$result = $src_db->sql_query('SELECT forum_id,auth_vote,auth_pollcreate FROM ' . $convert->src_table_prefix . 'forums');
	$forums = $src_db->sql_fetchrowset($result);
	$src_db->sql_freeresult($result);
	$db->sql_transaction('begin');
	try
	{
		foreach ($forums as $forum)
		{
			$id = (int) $forum['forum_id'];
			$flags = $groups[$guest][$id] ?? array();
			if (!$flags) { continue; }
			$corrected = $flags;
			foreach (array('auth_vote'=>'f_vote','auth_pollcreate'=>'f_poll') as $old=>$native)
			{
				$allowed = (int) $forum[$old] === 0 && (int) ($flags['f_read'] ?? ACL_NO) === ACL_YES;
				if ($allowed !== ((int) ($flags[$native] ?? ACL_NO) === ACL_YES)) { $corrected[$native] = $allowed ? ACL_YES : ACL_NO; }
			}
			if ($corrected !== $flags)
			{
				// Store the complete forum permission set through the ACP writer. Other
				// guest permissions survive and the site's shared role stays editable.
				$admin->acl_set('group', $id, $guest, $corrected, 0, false);
			}
		}
		$db->sql_transaction('commit');
	}
	catch (\Throwable $error) { $db->sql_transaction('rollback'); throw $error; }
	$admin->acl_clear_prefetch();
}

function plus_sync_new_pms()
{
	global $db;
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
