<?php
/***************************************************************************
 *                             usercp_email.php 
 *                            -------------------
 *   begin                : Saturday, Feb 13, 2001
 *   copyright            : (C) 2001 The phpBB Group
 *   email                : support@phpbb.com
 *
 *   $Id: usercp_email.php,v 1.7.2.13 2003/06/06 18:02:15 acydburn Exp $
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
	die("Hacking attempt");
	exit;
}

$error = false;
$error_msg = '';

// Is send through board enabled? No, return to index
if (!$board_config['board_email_form'])
{
	redirect(append_sid("index.$phpEx", true));
}

$get_user_id = (isset($_GET[POST_USERS_URL]) && is_scalar($_GET[POST_USERS_URL])) ? intval($_GET[POST_USERS_URL]) : 0;
$post_user_id = (isset($_POST[POST_USERS_URL]) && is_scalar($_POST[POST_USERS_URL])) ? intval($_POST[POST_USERS_URL]) : 0;
if ( $get_user_id || $post_user_id )
{
	$user_id = $get_user_id ? $get_user_id : $post_user_id;
}
else
{
	message_die(GENERAL_MESSAGE, $lang['No_user_specified']);
}

if ( !$userdata['session_logged_in'] )
{
	redirect(append_sid("login.$phpEx?redirect=profile.$phpEx&mode=email&" . POST_USERS_URL . "=$user_id", true));
}

$sql = "SELECT username, user_email, user_viewemail, user_lang, user_absence, user_absence_mode, user_absence_text  
	FROM " . USERS_TABLE . " 
	WHERE user_id = $user_id";
if ( $result = $db->sql_query($sql) )
{
	if ( $row = $db->sql_fetchrow($result) )
	{

	$username = $row['username'];
	$user_email = $row['user_email']; 
	$user_lang = $row['user_lang'];
	if ( $row['user_absence'] == TRUE && allow_send_to_absent() == FALSE )
	{
		$send_to_user = $row['username'];
		$absence_mode = create_absence_mode($row['user_absence_mode'], $pm_img, $pm, $email_img, $email, $row['username']);
		$safe_send_to_user = htmlspecialchars($send_to_user, ENT_QUOTES, 'UTF-8');
		$safe_absence_text = htmlspecialchars($row['user_absence_text'], ENT_QUOTES, 'UTF-8');
		$error_msg = sprintf($lang['User_absent'], $safe_send_to_user, $absence_mode, $safe_absence_text, $safe_send_to_user);

		include($phpbb_root_path . 'includes/page_header.'.$phpEx);
		$template->set_filenames(array(
			'reg_header' => 'error_body.tpl')
		);
		$template->assign_vars(array(
			'ERROR_MESSAGE' => $error_msg)
		);
		$template->pparse('reg_header');
		include($phpbb_root_path . 'includes/page_tail.'.$phpEx);

		exit;
	}

	if ( $row['user_viewemail'] || $userdata['user_level'] == ADMIN )
	{
		if ( isset($_POST['submit']) )
		{
			$error = FALSE;
			$submitted_sid = (isset($_POST['sid']) && is_scalar($_POST['sid'])) ? (string) $_POST['sid'] : '';
			if ($submitted_sid === '' || !hash_equals((string) $userdata['session_id'], $submitted_sid))
			{
				message_die(GENERAL_ERROR, $lang['Session_invalid']);
			}
			if ( time() - (int) $userdata['user_emailtime'] < (int) $board_config['flood_interval'] )
			{
				message_die(GENERAL_MESSAGE, $lang['Flood_email_limit']);
			}
			if (intval($ctracker_config->settings['massmail_protection']) == 1 && function_exists('ctracker_rate_limit_cooldown_remaining'))
			{
				$mail_minutes = max(1, min(180, intval($ctracker_config->settings['massmail_time'])));
				$mail_remaining = ctracker_rate_limit_cooldown_remaining('mail-user', 'user:' . intval($userdata['user_id']), $mail_minutes * 60);
				if ($mail_remaining !== false && $mail_remaining > 0)
				{
					message_die(GENERAL_MESSAGE, sprintf($lang['ctracker_sendmail_info'], $mail_minutes, intval($mail_remaining)));
				}
			}

			$submitted_subject = (isset($_POST['email_subject']) && is_scalar($_POST['email_subject'])) ? (string) $_POST['email_subject'] : '';
			if ( $submitted_subject !== '' && strlen($submitted_subject) <= 200 )
			{
				$subject = trim(preg_replace('/[\x00-\x1f\x7f]+/', ' ', stripslashes($submitted_subject)));
			}
			else
			{
				$error = TRUE;
				$error_msg = ( !empty($error_msg) ) ? $error_msg . '<br />' . $lang['Empty_subject_email'] : $lang['Empty_subject_email'];
			}

			$submitted_message = (isset($_POST['email_message']) && is_scalar($_POST['email_message'])) ? (string) $_POST['email_message'] : '';
			if ( $submitted_message !== '' && strlen($submitted_message) <= 10000 )
			{
				$message = trim(stripslashes($submitted_message));
			}
			else
			{
				$error = TRUE;
				$error_msg = ( !empty($error_msg) ) ? $error_msg . '<br />' . $lang['Empty_message_email'] : $lang['Empty_message_email'];
			}

			if ( !$error )
			{
				include($phpbb_root_path . 'includes/emailer.'.$phpEx);
					$emailer = new emailer($board_config['smtp_delivery']);

					$emailer->from($board_config['board_email']);
					$emailer->replyto($userdata['user_email']);

					$email_headers = 'X-AntiAbuse: Board servername - ' . $board_config['server_name'] . "\n";
					$email_headers .= 'X-AntiAbuse: User_id - ' . $userdata['user_id'] . "\n";
					$email_headers .= 'X-AntiAbuse: Username - ' . $userdata['username'] . "\n";
					$email_headers .= 'X-AntiAbuse: User IP - ' . decode_ip($user_ip) . "\n";

					$emailer->use_template('profile_send_email', $user_lang);
					$emailer->email_address($user_email);
					$emailer->set_subject($subject);
					$emailer->extra_headers($email_headers);

					$emailer->assign_vars(array(
						'SITENAME' => $board_config['sitename'], 
						'BOARD_EMAIL' => $board_config['board_email'], 
						'FROM_USERNAME' => $userdata['username'], 
						'TO_USERNAME' => $username, 
						'MESSAGE' => $message)
					);
					$emailer->send();
					$emailer->reset();
					if (intval($ctracker_config->settings['massmail_protection']) == 1 && function_exists('ctracker_rate_limit_mark_success'))
					{
						ctracker_rate_limit_mark_success('mail-user', 'user:' . intval($userdata['user_id']));
					}
					$sql = 'UPDATE ' . USERS_TABLE . ' SET user_emailtime = ' . time() . ' WHERE user_id = ' . intval($userdata['user_id']);
					if (!$db->sql_query($sql))
					{
						message_die(GENERAL_ERROR, 'Could not update last email time', '', __LINE__, __FILE__, $sql);
					}

					if ( isset($_POST['cc_email']) && is_scalar($_POST['cc_email']) && $_POST['cc_email'] )
					{
						$emailer->from($board_config['board_email']);
						$emailer->replyto($userdata['user_email']);
						$emailer->use_template('profile_send_email');
						$emailer->email_address($userdata['user_email']);
						$emailer->set_subject($subject);

						$emailer->assign_vars(array(
							'SITENAME' => $board_config['sitename'], 
							'BOARD_EMAIL' => $board_config['board_email'], 
							'FROM_USERNAME' => $userdata['username'], 
							'TO_USERNAME' => $username, 
							'MESSAGE' => $message)
						);
						$emailer->send();
						$emailer->reset();
					}

					$template->assign_vars(array(
						'META' => '<meta http-equiv="refresh" content="5;url=' . append_sid("index.$phpEx") . '">')
					);

					$message = $lang['Email_sent'] . '<br /><br />' . sprintf($lang['Click_return_index'],  '<a href="' . append_sid("index.$phpEx") . '">', '</a>');

				message_die(GENERAL_MESSAGE, $message);
			}
		}

		include($phpbb_root_path . 'includes/page_header.'.$phpEx);

		$template->set_filenames(array(
			'body' => 'profile_send_email.tpl')
		);
		make_jumpbox('viewforum.'.$phpEx);

		if ( $error )
		{
			$template->set_filenames(array(
				'reg_header' => 'error_body.tpl')
			);
			$template->assign_vars(array(
				'ERROR_MESSAGE' => $error_msg)
			);
			$template->assign_var_from_handle('ERROR_BOX', 'reg_header');
		}

		$template->assign_vars(array(
			'USERNAME' => htmlspecialchars($username, ENT_QUOTES, 'UTF-8'),

			'S_HIDDEN_FIELDS' => '<input type="hidden" name="sid" value="' . phpbb_profile_text($userdata['session_id']) . '" />',
			'S_POST_ACTION' => append_sid("profile.$phpEx?mode=email&amp;" . POST_USERS_URL . "=$user_id"), 

			'L_SEND_EMAIL_MSG' => $lang['Send_email_msg'], 
			'L_RECIPIENT' => $lang['Recipient'], 
			'L_SUBJECT' => $lang['Subject'],
			'L_MESSAGE_BODY' => $lang['Message_body'], 
			'L_MESSAGE_BODY_DESC' => $lang['Email_message_desc'], 
			'L_EMPTY_SUBJECT_EMAIL' => $lang['Empty_subject_email'],
			'L_EMPTY_MESSAGE_EMAIL' => $lang['Empty_message_email'],
			'L_OPTIONS' => $lang['Options'],
			'L_CC_EMAIL' => $lang['CC_email'], 
			'L_SPELLCHECK' => $lang['Spellcheck'],
			'L_SEND_EMAIL' => $lang['Send_email'])
		);

		$template->pparse('body');

		include($phpbb_root_path . 'includes/page_tail.'.$phpEx);
	}
	else
	{
		message_die(GENERAL_MESSAGE, $lang['User_prevent_email']);
	}
}
else
	{
		message_die(GENERAL_MESSAGE, $lang['User_not_exist']);
	}
}
else
{
	message_die(GENERAL_ERROR, 'Could not select user data', '', __LINE__, __FILE__, $sql);
}

?>
