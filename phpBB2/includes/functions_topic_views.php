<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_topic_preferences.php';

function phpbb_topic_posts_from($topic, $since)
{
	$topic_id = (int) $topic['topic_id'];
	$forum_id = (int) $topic['forum_id'];
	$from = ' FROM ' . POSTS_TABLE . ' p
		JOIN ' . POSTS_TEXT_TABLE . ' pt ON pt.post_id = p.post_id
		JOIN ' . USERS_TABLE . " u ON u.user_id = p.poster_id
		WHERE p.topic_id = $topic_id AND p.forum_id = $forum_id";
	if ($since > 0)
	{
		$from .= ' AND p.post_time >= ' . (int) $since;
	}
	return $from;
}

function phpbb_topic_print_posts($db, $topic, $since, $order)
{
	$direction = $order === 'desc' ? 'DESC' : 'ASC';
	$sql = 'SELECT u.username, u.user_id, p.*, pt.post_text, pt.post_subject, pt.bbcode_uid'
		. phpbb_topic_posts_from($topic, $since)
		. " ORDER BY p.post_time $direction, p.post_id $direction";
	if (!($result = $db->sql_query($sql)))
	{
		message_die(GENERAL_ERROR, 'Could not obtain topic posts', '', __LINE__, __FILE__, $sql);
	}
	return $result;
}

function phpbb_topic_posts_page($db, $topic, $since, $order, $start, $per_page, $profile_fields)
{
	$per_page = (int) $per_page > 0 ? (int) $per_page : 15;
	$direction = $order === 'desc' ? 'DESC' : 'ASC';
	$from = phpbb_topic_posts_from($topic, $since);
	$position = '';
	if (!empty($topic['requested_post_id']) && $topic['requested_post_time'] >= $since)
	{
		// Use the same chronological order, including tied timestamps, as the list below.
		$id = (int) $topic['requested_post_id'];
		$time = (int) $topic['requested_post_time'];
		$op = $direction === 'DESC' ? '>' : '<';
		$position = ", COUNT(CASE WHEN p.post_time $op $time
			OR (p.post_time = $time AND p.post_id $op $id) THEN 1 END) AS preceding_posts";
	}
	$sql = 'SELECT COUNT(*) AS total' . $position . $from;
	if (!($result = $db->sql_query($sql)))
	{
		message_die(GENERAL_ERROR, 'Could not count topic posts', '', __LINE__, __FILE__, $sql);
	}
	$count = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);
	$total = (int) $count['total'];
	$last_page = (int) floor(max(0, $total - 1) / $per_page);
	$offset = isset($count['preceding_posts']) ? (int) $count['preceding_posts'] : max(0, (int) $start);
	$page = min($last_page, (int) floor($offset / $per_page));
	$start = $page * $per_page;
	$rows = array();
	if ($total > 0)
	{
		$sql = 'SELECT u.username, u.user_absence, u.user_absence_mode, u.user_id, u.user_posts, u.user_from,
			u.user_from_flag, u.user_website, u.user_email, u.user_icq, u.user_aim, u.user_yim, u.user_regdate,
			u.user_msnm, u.user_fb, u.user_ig, u.user_pt, u.user_twr, u.user_skp, u.user_tg, u.user_li, u.user_tt,
			u.user_dc, u.user_signal, u.user_threema, u.user_viewemail, u.user_rank, u.user_sig, u.user_sig_bbcode_uid,
			u.user_avatar, u.user_avatar_type, u.user_allowavatar, u.user_allowsmile, u.ct_miserable_user,
			u.user_warnings, u.user_level, u.user_allow_viewonline, u.user_session_time, u.user_birthday,
			u.user_next_birthday_greeting, u.user_gender' . get_udata_txt($profile_fields, 'u.') . ',
			p.*, pt.post_text, pt.post_subject, pt.bbcode_uid' . $from . "
			ORDER BY p.post_time $direction, p.post_id $direction LIMIT $start, $per_page";
		if (!($result = $db->sql_query($sql)))
		{
			message_die(GENERAL_ERROR, 'Could not obtain topic posts', '', __LINE__, __FILE__, $sql);
		}
		$rows = $db->sql_fetchrowset($result);
		$db->sql_freeresult($result);
	}
	return array('rows' => $rows, 'total' => $total, 'start' => $start, 'per_page' => $per_page,
		'page' => $page + 1, 'pages' => $last_page + 1);
}

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
