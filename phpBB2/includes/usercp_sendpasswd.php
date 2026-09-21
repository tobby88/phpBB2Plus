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
require_once dirname(__FILE__) . '/functions_password_reset.php';

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
	try
	{
		$reset_request = phpbb_password_reset_request($db, $username, $email, $submitted_sid);
	}
	catch (PhpbbActivationException $error) { message_die(GENERAL_MESSAGE, htmlspecialchars($error->getMessage(), ENT_QUOTES, 'UTF-8')); }
	$reset_allowed = $reset_request !== null;

	// Use the same public response for unknown, inactive and temporarily
	// throttled accounts. This prevents the form from becoming an account and
	// activation-status oracle while a legitimate matching account still gets
	// the normal activation email.
	if ($reset_allowed)
	{
		$row = $reset_request;
		$username = $row['username'];
		$user_id = (int) $row['user_id'];
		$user_actkey = $row['user_actkey'];
		$reset_mail_failed = false;
		try
		{
			require_once($phpbb_root_path . 'includes/emailer.'.$phpEx);
			$emailer = new emailer($board_config['smtp_delivery'], true);

			$emailer->from($board_config['board_email']);
			$emailer->replyto($board_config['board_email']);

			$emailer->use_template('user_activate_passwd', $row['user_lang']);
			$emailer->email_address($row['user_email']);
			$emailer->set_subject($lang['New_password_activation']);

			$emailer->assign_vars(array(
				'SITENAME' => $board_config['sitename'],
				'USERNAME' => $username,
				'EMAIL_SIG' => (!empty($board_config['board_email_sig'])) ? str_replace('<br />', "\n", "-- \n" . $board_config['board_email_sig']) : '',

				'U_ACTIVATE' => $server_url . '?mode=activate&' . POST_USERS_URL . '=' . $user_id . '&act_key=' . $user_actkey)
			);
			$reset_mail_failed = !$emailer->send();
			$emailer->reset();
		}
		catch (Exception $error) { $reset_mail_failed = true; }
		catch (Throwable $error) { $reset_mail_failed = true; }
		if ($reset_mail_failed)
		{
			// Same public response even on delivery failure; do not reveal which
			// account/address matched. Retire only our token to permit a retry.
			try { phpbb_password_reset_cancel($db, $reset_request); }
			catch (Exception $error) { error_log('Password reset notification cleanup failed.'); }
			catch (Throwable $error) { error_log('Password reset notification cleanup failed.'); }
			error_log('Password reset notification delivery failed.');
		}
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
