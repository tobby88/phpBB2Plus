<?php
/***************************************************************************
 *                           usercp_sendpasswd.php
 *                            -------------------
 *   begin                : Saturday, Feb 13, 2001
 *   copyright            : (C) 2001 The phpBB Group
 *   email                : support@phpbb.com
 *
 *   $Id: usercp_sendpasswd.php,v 1.6.2.11 2003/05/03 23:24:03 acydburn Exp $
 *
 *
 ***************************************************************************/

/***************************************************************************
 *
 *   This program is free software; you can redistribute it and/or modify
 *   it under the terms of the GNU General Public License as published by
 *   the Free Software Foundation; either version 2 of the License, or
 *   (at your option) any later version.
 *
 *
 ***************************************************************************/

if ( !defined('IN_PHPBB') )
{
	die('Hacking attempt');
	exit;
}

if ( isset($_POST['submit']) )
{
	$submitted_sid = (isset($_POST['sid']) && is_scalar($_POST['sid'])) ? (string) $_POST['sid'] : '';
	if ($submitted_sid === '' || !hash_equals((string) $userdata['session_id'], $submitted_sid))
	{
		message_die(GENERAL_ERROR, $lang['Session_invalid']);
	}

	$username_value = (isset($_POST['username']) && is_scalar($_POST['username'])) ? (string) $_POST['username'] : '';
	$email_value = (isset($_POST['email']) && is_scalar($_POST['email'])) ? (string) $_POST['email'] : '';
	$username = ( $username_value !== '' ) ? phpbb_clean_username($username_value) : '';
	$email = ( $email_value !== '' ) ? trim(strip_tags(htmlspecialchars($email_value))) : '';
	$username_sql = $db->sql_escape($username);
	$email_sql = $db->sql_escape($email);

	$sql = "SELECT user_id, username, user_email, user_active, user_lang, ct_last_pw_reset
		FROM " . USERS_TABLE . " 
		WHERE user_email = '$email_sql'
			AND username = '$username_sql'";
	if ( !($result = $db->sql_query($sql)) )
	{
		message_die(GENERAL_ERROR, 'Could not obtain user information for sendpassword', '', __LINE__, __FILE__, $sql);
	}
	$row = $db->sql_fetchrow($result);
	$pwreset_minutes = isset($ctracker_config->settings['pwreset_time']) ? intval($ctracker_config->settings['pwreset_time']) : 20;
	$pwreset_minutes = ($pwreset_minutes > 0) ? min(180, $pwreset_minutes) : 20;
	$reset_throttling = isset($ctracker_config->settings['pw_reset_feature']) && $ctracker_config->settings['pw_reset_feature'] == 1;
	$reset_allowed = $row && !empty($row['user_active']) && (!$reset_throttling || intval($row['ct_last_pw_reset']) < time());

	// Use the same public response for unknown, inactive and temporarily
	// throttled accounts. This prevents the form from becoming an account and
	// activation-status oracle while a legitimate matching account still gets
	// the normal activation email.
	if ($reset_allowed)
	{
		$username = $row['username'];
		$user_id = (int) $row['user_id'];

		$user_actkey = gen_rand_string(true);
		$user_password = gen_rand_string(false);
		$new_time = time() + ($pwreset_minutes * 60);
		$new_password_hash = phpbb_password_hash($user_password);
		$new_password_hash_sql = $db->sql_escape($new_password_hash);
		$user_actkey_sql = $db->sql_escape($user_actkey);
		$sql = "UPDATE " . USERS_TABLE . "
			SET user_newpasswd = '$new_password_hash_sql', user_actkey = '$user_actkey_sql', ct_last_pw_reset = $new_time WHERE user_id = $user_id";
		if ( !$db->sql_query($sql) )
		{
			message_die(GENERAL_ERROR, 'Could not update new password information', '', __LINE__, __FILE__, $sql);
		}

		include($phpbb_root_path . 'includes/emailer.'.$phpEx);
		$emailer = new emailer($board_config['smtp_delivery']);

		$emailer->from($board_config['board_email']);
		$emailer->replyto($board_config['board_email']);

		$emailer->use_template('user_activate_passwd', $row['user_lang']);
		$emailer->email_address($row['user_email']);
		$emailer->set_subject($lang['New_password_activation']);

		$emailer->assign_vars(array(
			'SITENAME' => $board_config['sitename'],
			'USERNAME' => $username,
			'PASSWORD' => $user_password,
			'EMAIL_SIG' => (!empty($board_config['board_email_sig'])) ? str_replace('<br />', "\n", "-- \n" . $board_config['board_email_sig']) : '',

			'U_ACTIVATE' => $server_url . '?mode=activate&' . POST_USERS_URL . '=' . $user_id . '&act_key=' . $user_actkey)
		);
		$emailer->send();
		$emailer->reset();
	}

	$template->assign_vars(array(
		'META' => '<meta http-equiv="refresh" content="15;url=' . append_sid("index.$phpEx") . '">')
	);

	$reset_response = isset($lang['Password_reset_requested']) ? $lang['Password_reset_requested'] : $lang['Password_updated'];
	$message = $reset_response . '<br /><br />' . sprintf($lang['Click_return_index'],  '<a href="' . append_sid("index.$phpEx") . '">', '</a>');
	message_die(GENERAL_MESSAGE, $message);
}
else
{
	$username = '';
	$email = '';
}

//
// Output basic page
//
include($phpbb_root_path . 'includes/page_header.'.$phpEx);

$template->set_filenames(array(
	'body' => 'profile_send_pass.tpl')
);
make_jumpbox('viewforum.'.$phpEx);

$template->assign_vars(array(
	'USERNAME' => $username,
	'EMAIL' => $email,

	'L_SEND_PASSWORD' => $lang['Send_password'], 
	'L_ITEMS_REQUIRED' => $lang['Items_required'],
	'L_EMAIL_ADDRESS' => $lang['Email_address'],
	'L_SUBMIT' => $lang['Submit'],
	'L_RESET' => $lang['Reset'],
	
	'S_HIDDEN_FIELDS' => '<input type="hidden" name="sid" value="' . phpbb_profile_text($userdata['session_id']) . '" />',
	'S_PROFILE_ACTION' => append_sid("profile.$phpEx?mode=sendpassword"))
);

$template->pparse('body');

include($phpbb_root_path . 'includes/page_tail.'.$phpEx);

?>
