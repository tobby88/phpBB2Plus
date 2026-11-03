<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_moderator_identity.php';

/** Read sensitive IP information using the current account and exact session. */
function phpbb_ip_lookup_actor($database, $shoutbox = false)
{
	global $userdata, $lang;
	$user = phpbb_current_moderator_user($database);
	if (!$user || empty($userdata['session_id']) || !is_string($userdata['session_id'])) { message_die(GENERAL_MESSAGE, $lang['Not_Authorised']); }
	$sid = $database->sql_escape($userdata['session_id']);
	$result = $database->sql_query('SELECT session_id FROM ' . SESSIONS_TABLE . " WHERE session_id = '$sid' AND HEX(session_id) = HEX('$sid') AND session_user_id = " . (int)$user['user_id'] . ' AND session_logged_in = 1');
	if (!$result) { message_die(GENERAL_ERROR, $lang['Not_Authorised']); }
	$session = $database->sql_fetchrow($result); $database->sql_freeresult($result);
	if (!$session || ($shoutbox && !in_array((int)$user['user_level'], array(ADMIN, MOD), true))) { message_die(GENERAL_MESSAGE, $lang['Not_Authorised']); }
	return $user;
}

/** A moderator's cross-forum IP totals must not disclose other forums. */
function phpbb_ip_lookup_forum_filter($database, $required_forum = null)
{
	global $lang;
	$user = phpbb_ip_lookup_actor($database);
	$permissions = auth(AUTH_ALL, AUTH_LIST_ALL, $user, '', $database);
	$forums = array();
	foreach ($permissions as $id => $access)
	{
		if ((int)$id > 0 && !empty($access['auth_mod']) && !empty($access['auth_view']) && !empty($access['auth_read'])) { $forums[] = (int)$id; }
	}
	if ($required_forum !== null && !in_array((int)$required_forum, $forums, true)) { message_die(GENERAL_MESSAGE, $lang['Not_Authorised']); }
	return $forums ? ' AND p.forum_id IN (' . implode(',', $forums) . ')' : ' AND 1 = 0';
}

/** Separate literal account names, HTML labels and URL query values. */
function phpbb_ip_lookup_author($id, $name)
{
	global $lang;
	$raw = (int)$id > 0 ? (string)$name : $lang['Guest'];
	return array('name'=>htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
		'title'=>htmlspecialchars(sprintf($lang['Search_user_posts'], $raw), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
		'query'=>urlencode((int)$id > 0 ? (string)$name : 'Anonymous'));
}
