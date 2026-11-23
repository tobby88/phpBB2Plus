<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }

function phpbb_memberlist_page($db, $admin, $requested_mode, $requested_order, $start, $per_page, $profile_fields)
{
	// Keep the choices offered by the form and the actual ordering together.
	$modes = array(
		'joined' => array('Sort_Joined', 'u.user_regdate'),
		'lastlogon' => array('Last_logon', 'u.user_lastlogon'),
		'username' => array('Sort_Username', 'u.username'),
		'location' => array('Sort_Location', 'u.user_from'),
		'posts' => array('Sort_Posts', 'u.user_posts'),
		'email' => array('Sort_Email', $admin ? 'u.user_email' : "CASE WHEN u.user_viewemail = 1 THEN u.user_email ELSE '' END"),
		'website' => array('Sort_Website', 'u.user_website'),
		'topten' => array('Sort_Top_Ten', 'u.user_posts')
	);
	if (!$admin) { unset($modes['lastlogon']); }
	$mode = is_string($requested_mode) && isset($modes[$requested_mode]) ? $requested_mode : 'joined';
	$order = $requested_order === 'DESC' || ($requested_order === '' && $mode === 'topten') ? 'DESC' : 'ASC';
	$per_page = (int) $per_page > 0 ? (int) $per_page : 15;

	$members = ' FROM ' . USERS_TABLE . ' WHERE user_id <> ' . (int) ANONYMOUS;
	$count_from = $mode === 'topten' ? ' FROM (SELECT user_id' . $members . ' LIMIT 10) ranked' : $members;
	$sql = 'SELECT COUNT(*) AS total' . $count_from;
	if (!($result = $db->sql_query($sql)))
	{
		message_die(GENERAL_ERROR, 'Could not count members', '', __LINE__, __FILE__, $sql);
	}
	$count = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);
	$total = (int) $count['total'];
	$pages = max(1, (int) ceil($total / $per_page));
	$page = min($pages - 1, (int) floor(max(0, (int) $start) / $per_page));
	$start = $page * $per_page;
	$rows = array();
	if ($total > 0)
	{
		$from = ' FROM ' . USERS_TABLE . ' u';
		if ($mode === 'topten')
		{
			// Choose the ten highest post counts before applying the requested
			// display direction or page limit. Ties have a stable membership.
			$from .= ' JOIN (SELECT user_id AS ranked_id' . $members
				. ' ORDER BY user_posts DESC, user_id ASC LIMIT 10) ranked ON ranked.ranked_id = u.user_id';
		}
		$sql = 'SELECT u.username, u.user_absence, u.user_absence_mode, u.user_id, u.user_viewemail,
			u.user_posts, u.user_regdate, u.user_lastlogon, u.user_allow_viewonline, u.user_from,
			u.user_from_flag, u.user_website, u.user_email, u.user_fb, u.user_ig, u.user_twr,
			u.user_tg, u.user_li, u.user_tt, u.user_dc, u.user_avatar, u.user_avatar_type,
			u.user_allowavatar, u.user_session_time' . get_udata_txt($profile_fields, 'u.') . $from
			. ' WHERE u.user_id <> ' . (int) ANONYMOUS
			. ' ORDER BY ' . $modes[$mode][1] . ' ' . $order . ', u.user_id ASC'
			. " LIMIT $start, $per_page";
		if (!($result = $db->sql_query($sql)))
		{
			message_die(GENERAL_ERROR, 'Could not list members', '', __LINE__, __FILE__, $sql);
		}
		$rows = $db->sql_fetchrowset($result);
		$db->sql_freeresult($result);
	}
	return array('modes' => $modes, 'mode' => $mode, 'order' => $order, 'rows' => $rows,
		'start' => $start, 'total' => $total, 'per_page' => $per_page, 'page' => $page + 1, 'pages' => $pages);
}
