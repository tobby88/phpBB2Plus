<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_account_activation.php';

function phpbb_password_reset_request($database, $username, $email, $sid)
{
	global $userdata;
	if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST'
		|| !is_string($username) || !is_string($email) || !is_string($sid) || $sid === ''
		|| empty($userdata['session_id']) || !is_string($userdata['session_id']) || !hash_equals($userdata['session_id'], $sid)) { phpbb_activation_error('Session_invalid'); }
	$db = new PhpbbActivationDatabase($database, true);
	try
	{
		$sid_sql = $db->sql_escape($sid);
		$session = $db->rows('SELECT session_id FROM ' . SESSIONS_TABLE . " WHERE session_id='$sid_sql' AND HEX(session_id)=HEX('$sid_sql') AND session_user_id=" . (int)$userdata['user_id'] . ' AND session_logged_in=' . (empty($userdata['session_logged_in']) ? 0 : 1) . ' LOCK IN SHARE MODE');
		if (count($session) !== 1) { phpbb_activation_error('Session_invalid'); }
		$settings = array();
		foreach ($db->rows("SELECT ct_config_name,ct_config_value FROM " . CTRACKER_CONFIG . " WHERE ct_config_name IN ('pwreset_time','pw_reset_feature') LOCK IN SHARE MODE") as $setting) { $settings[$setting['ct_config_name']] = $setting['ct_config_value']; }
		if (count($settings) !== 2 || !preg_match('/^[0-9]{1,3}$/D', $settings['pwreset_time']) || (int)$settings['pwreset_time'] < 1 || (int)$settings['pwreset_time'] > 180 || !in_array($settings['pw_reset_feature'], array('0','1'), true)) { phpbb_activation_error('Activation_storage_upgrade'); }
		$rows = $db->rows('SELECT user_id,username,user_email,user_password,user_active,user_level,user_passwd_change,ct_last_pw_change,user_newpasswd,user_lang,user_actkey,ct_last_pw_reset FROM ' . USERS_TABLE
			. " WHERE user_email='" . $db->sql_escape($email) . "' AND username='" . $db->sql_escape($username) . "' FOR UPDATE");
		// Keep the public response identical for unknown/inactive/throttled or
		// ambiguous legacy accounts. Preserve the forum's lookup collation.
		if (count($rows) !== 1 || (int)$rows[0]['user_id'] <= 0 || (int)$rows[0]['user_active'] !== 1) { return null; }
		$row = $rows[0]; $now = time();
		if ($settings['pw_reset_feature'] === '1' && phpbb_reset_binding_valid($row) && (int)$row['ct_last_pw_reset'] >= $now) { return null; }
		$row['user_actkey'] = bin2hex(phpbb_random_bytes(16));
		$row['ct_last_pw_reset'] = $now + (int)$settings['pwreset_time'] * 60;
		$row['user_newpasswd'] = phpbb_reset_binding($row);
		if ($row['user_newpasswd'] === '') { phpbb_activation_error(); }
		$id = (int)$row['user_id']; $key = $db->sql_escape($row['user_actkey']); $marker = $db->sql_escape($row['user_newpasswd']);
		$db->sql_query('UPDATE ' . USERS_TABLE . " SET user_newpasswd='$marker',user_actkey='$key',ct_last_pw_reset=" . (int)$row['ct_last_pw_reset'] . ' WHERE user_id=' . $id);
		if ((int)$db->sql_affectedrows() !== 1) { phpbb_activation_error(); }
		$db->commit(); return $row;
	}
	finally { $db->release(); }
}

// A failed optional mail must not lock the member out of retrying. Retire only
// this request's token, never a newer request or an account activation token.
function phpbb_password_reset_cancel($database, $request)
{
	$db = new PhpbbActivationDatabase($database);
	try
	{
		$id = (int)$request['user_id'];
		$row = $db->rows('SELECT user_actkey,user_newpasswd FROM ' . USERS_TABLE . ' WHERE user_id=' . $id . ' FOR UPDATE');
		if (count($row) === 1 && hash_equals((string)$request['user_actkey'], (string)$row[0]['user_actkey']) && hash_equals((string)$request['user_newpasswd'], (string)$row[0]['user_newpasswd']))
		{ $db->sql_query('UPDATE ' . USERS_TABLE . " SET user_actkey='',user_newpasswd='',ct_last_pw_reset=0 WHERE user_id=" . $id); }
		$db->commit();
	}
	finally { $db->release(); }
}
