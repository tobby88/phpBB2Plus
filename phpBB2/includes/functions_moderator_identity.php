<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }

// Use the caller's owning connection. Session role/active flags are only a
// snapshot and must not authorize a destructive action after account changes.
function phpbb_current_moderator_user($database)
{
	global $userdata;
	if (empty($userdata['session_logged_in']) || !isset($userdata['user_id'])) { return false; }
	$id=$userdata['user_id'];
	if (!(is_int($id) || is_string($id)) || !preg_match('/^[0-9]+$/D',(string)$id)) { return false; }
	$id=ltrim((string)$id,'0');
	if ($id==='' || strlen($id)>8 || (int)$id>16777215) { return false; }
	$result=$database->sql_query('SELECT user_id, user_level, user_active FROM '.USERS_TABLE.' WHERE user_id = '.(int)$id);
	if (!$result) { message_die(GENERAL_ERROR, 'Could not obtain current moderator account'); }
	$user=$database->sql_fetchrow($result); $database->sql_freeresult($result);
	if (!$user || empty($user['user_active'])) { return false; }
	$user['session_logged_in']=true;
	return $user;
}
