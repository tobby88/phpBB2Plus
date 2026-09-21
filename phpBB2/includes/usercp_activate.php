<?php
/***************************************************************************
 *                            usercp_activate.php
 *                            -------------------
 *   begin                : Saturday, Feb 13, 2001
 *   copyright            : (C) 2001 The phpBB Group
 *   email                : support@phpbb.com
 *
 *   $Id: usercp_activate.php,v 1.6.2.7 2003/05/03 23:24:02 acydburn Exp $
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
require_once dirname(__FILE__) . '/functions_account_activation.php';

function usercp_render_password_reset($row, $activation_key, $error_message = '')
{
	global $phpbb_root_path, $phpEx, $template, $lang, $userdata;

	include($phpbb_root_path . 'includes/page_header.' . $phpEx);
	$template->set_filenames(array('body' => 'profile_reset_pass.tpl'));
	$template->assign_vars(array(
		'L_RESET_TITLE' => $lang['Password_reset_title'],
		'L_RESET_EXPLAIN' => $lang['Password_reset_explain'],
		'L_NEW_PASSWORD' => $lang['New_password'],
		'L_PASSWORD_LIMIT' => $lang['Password_long'],
		'L_CONFIRM_PASSWORD' => $lang['Confirm_password'],
		'L_SUBMIT' => $lang['Submit'],
		'S_RESET_ACTION' => append_sid('profile.' . $phpEx . '?mode=activate&amp;' . POST_USERS_URL . '=' . (int) $row['user_id'] . '&amp;act_key=' . rawurlencode($activation_key)),
		'S_HIDDEN_FIELDS' => '<input type="hidden" name="sid" value="' . htmlspecialchars((string) $userdata['session_id'], ENT_QUOTES, 'UTF-8') . '" /><input type="hidden" name="reset_password" value="1" />'
	));
	if ($error_message !== '')
	{
		$template->assign_block_vars('switch_error', array(
			'ERROR_MESSAGE' => $error_message
		));
	}
	$template->pparse('body');
	include($phpbb_root_path . 'includes/page_tail.' . $phpEx);
	exit;
}

$activation_user_id = (isset($_GET[POST_USERS_URL]) && is_scalar($_GET[POST_USERS_URL])) ? intval($_GET[POST_USERS_URL]) : 0;
$activation_key = (isset($_GET['act_key']) && is_scalar($_GET['act_key'])) ? trim((string) $_GET['act_key']) : '';
if ($activation_user_id <= 0 || !preg_match('/^[a-f0-9]{6,32}$/iD', $activation_key))
{
	message_die(GENERAL_MESSAGE, $lang['Wrong_activation']);
}

$sql = "SELECT user_active, user_id, username, user_email, user_password, user_newpasswd, user_lang, user_actkey, ct_last_pw_reset
	FROM " . USERS_TABLE . "
	WHERE user_id = " . $activation_user_id;
if ( !($result = $db->sql_query($sql)) )
{
	message_die(GENERAL_ERROR, 'Could not obtain user information', '', __LINE__, __FILE__, $sql);
}

if ( $row = $db->sql_fetchrow($result) )
{
	if ( $row['user_active'] && trim($row['user_actkey']) == '' )
	{
		$template->assign_vars(array(
			'META' => '<meta http-equiv="refresh" content="10;url=' . append_sid("index.$phpEx") . '">')
		);

		message_die(GENERAL_MESSAGE, $lang['Already_activated']);
	}
	else if (trim($row['user_actkey']) !== '' && hash_equals(trim($row['user_actkey']), $activation_key))
	{
		// A stale reset form must never turn into an account activation after
		// a different operation has replaced the token's purpose.
		if (!empty($_POST['reset_password']) && $row['user_newpasswd'] !== PHPBB_PASSWORD_RESET_PENDING)
		{
			message_die(GENERAL_MESSAGE, $lang['Wrong_activation']);
		}
		if ($row['user_newpasswd'] === PHPBB_PASSWORD_RESET_PENDING)
		{
			$now = time();
			if (intval($row['ct_last_pw_reset']) < $now)
			{
				message_die(GENERAL_MESSAGE, $lang['Password_reset_expired']);
			}

			if (empty($_POST['reset_password']))
			{
				usercp_render_password_reset($row, $activation_key);
			}

			$submitted_sid = (isset($_POST['sid']) && is_scalar($_POST['sid'])) ? (string) $_POST['sid'] : '';
			if ($submitted_sid === '' || !hash_equals((string) $userdata['session_id'], $submitted_sid))
			{
				message_die(GENERAL_ERROR, $lang['Session_invalid']);
			}

			$new_password = (isset($_POST['new_password']) && is_scalar($_POST['new_password'])) ? (string) $_POST['new_password'] : '';
			$password_confirm = (isset($_POST['password_confirm']) && is_scalar($_POST['password_confirm'])) ? (string) $_POST['password_confirm'] : '';
			$error_messages = array();
			if ($new_password === '' || $password_confirm === '')
			{
				$error_messages[] = $lang['Fields_empty'];
			}
			if (!hash_equals($new_password, $password_confirm))
			{
				$error_messages[] = $lang['Password_mismatch'];
			}
			include_once($phpbb_root_path . 'includes/functions_validate.' . $phpEx);
			$password_result = validate_complex_password($row['username'], $new_password);
			if (!empty($password_result['error']))
			{
				$error_messages[] = $password_result['error_msg'];
			}
			if (!empty($error_messages))
			{
				usercp_render_password_reset($row, $activation_key, implode('<br />', array_unique($error_messages)));
			}

			$new_hash = phpbb_password_hash($new_password);
			if ($new_hash === false) { message_die(GENERAL_MESSAGE, $lang['Password_hash_failed']); }
			try
			{
				$activation_result = phpbb_account_activate($db, $row, $activation_key, $new_hash, $submitted_sid);
			}
			catch (PhpbbActivationException $error) { message_die(GENERAL_MESSAGE, htmlspecialchars($error->getMessage(), ENT_QUOTES, 'UTF-8')); }
			phpbb_activation_publish_logout($activation_result);
			message_die(GENERAL_MESSAGE, $lang['Password_reset_complete'] . '<br /><br />' . sprintf($lang['Click_return_login'], '<a href="' . append_sid('login.' . $phpEx) . '">', '</a>'));
		}

		if (intval($board_config['require_activation']) == USER_ACTIVATION_ADMIN && $row['user_newpasswd'] == '')
		{
			if (!$userdata['session_logged_in'])
			{
				redirect(append_sid('login.' . $phpEx . '?redirect=profile.' . $phpEx . '&mode=activate&' . POST_USERS_URL . '=' . $row['user_id'] . '&act_key=' . $activation_key));
			}
			else if ($userdata['user_level'] != ADMIN)
			{
				message_die(GENERAL_MESSAGE, $lang['Not_Authorised']);
			}
		}
		try
		{
			$activation_result = phpbb_account_activate($db, $row, $activation_key);
		}
		catch (PhpbbActivationException $error) { message_die(GENERAL_MESSAGE, htmlspecialchars($error->getMessage(), ENT_QUOTES, 'UTF-8')); }
		phpbb_activation_publish_logout($activation_result);
		$row = $activation_result['row'];
		if ($activation_result['admin'])
		{
			$activation_mail_failed = false;
			try
			{
			require_once($phpbb_root_path . 'includes/emailer.'.$phpEx);
			$emailer = new emailer($board_config['smtp_delivery'], true);

			$emailer->from($board_config['board_email']);
			$emailer->replyto($board_config['board_email']);

			$emailer->use_template('admin_welcome_activated', $row['user_lang']);
			$emailer->email_address($row['user_email']);
			$emailer->set_subject($lang['Account_activated_subject']);

			$emailer->assign_vars(array(
				'SITENAME' => $board_config['sitename'], 
				'USERNAME' => $row['username'],
				'EMAIL_SIG' => (!empty($board_config['board_email_sig'])) ? str_replace('<br />', "\n", "-- \n" . $board_config['board_email_sig']) : '')
			);
			$activation_mail_failed = !$emailer->send();
			$emailer->reset();
			}
			catch (Exception $error) { $activation_mail_failed = true; }
			catch (Throwable $error) { $activation_mail_failed = true; }

			$template->assign_vars(array(
				'META' => '<meta http-equiv="refresh" content="10;url=' . append_sid("index.$phpEx") . '">')
			);

			message_die(GENERAL_MESSAGE, $lang['Account_active_admin'] . ($activation_mail_failed ? '<br /><br />' . $lang['Activation_mail_failed'] : ''));
		}
		else
		{
			$template->assign_vars(array(
				'META' => '<meta http-equiv="refresh" content="10;url=' . append_sid("index.$phpEx") . '">')
			);

			$message = $activation_result['legacy'] ? $lang['Password_activated'] : $lang['Account_active'];
			message_die(GENERAL_MESSAGE, $message);
		}
	}
	else
	{
		message_die(GENERAL_MESSAGE, $lang['Wrong_activation']);
	}
}
else
{
	message_die(GENERAL_MESSAGE, $lang['No_such_user']);
}

?>
