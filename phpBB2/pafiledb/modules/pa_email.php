<?php
/*
  paFileDB 3.0
  ©2001/2002 PHP Arena
  Written by Todd
  todd@phparena.net
  http://www.phparena.net
  Keep all copyright links on the script visible
  Please read the license included with this script for more information.
*/


class pafiledb_email extends pafiledb_public
{
	function main($action = false)
	{
		global $pafiledb_template, $lang, $board_config, $phpEx, $pafiledb_config, $db, $images, $userdata;
		global $_REQUEST, $_POST, $phpbb_root_path;

		if (isset($_REQUEST['file_id']) && is_scalar($_REQUEST['file_id']))
		{
			$file_id = intval($_REQUEST['file_id']);
		}
		else
		{
			message_die(GENERAL_MESSAGE, $lang['File_not_exist']);
		}

		$sql = 'SELECT file_catid, file_name
			FROM ' . PA_FILES_TABLE . "
			WHERE file_id = $file_id";

		if ( !($result = $db->sql_query($sql)) )
		{
			message_die(GENERAL_ERROR, 'Couldnt Query file info', '', __LINE__, __FILE__, $sql);
		}

		if(!$file_data = $db->sql_fetchrow($result))
		{
			message_die(GENERAL_MESSAGE, $lang['File_not_exist']);
		}

		$db->sql_freeresult($result);

		if( (!$this->auth[$file_data['file_catid']]['auth_email']) )
		{
			if ( !$userdata['session_logged_in'] )
			{
				redirect(append_sid("login.$phpEx?redirect=dload.$phpEx?action=email&file_id=" . $file_id, true));
			}

			$message = sprintf($lang['Sorry_auth_email'], $this->auth[$file_data['file_catid']]['auth_email_type']);
			message_die(GENERAL_MESSAGE, $message);
		}

		if ( isset($_POST['submit']) )
		{
			// session id check
			if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['sid']) || !is_scalar($_POST['sid']) || !hash_equals((string) $userdata['session_id'], (string) $_POST['sid']))
			{
				message_die(GENERAL_ERROR, $lang['Not_Authorised']);
			}

			$error = FALSE;
			$error_msg = '';
			$femail = (isset($_POST['femail']) && is_scalar($_POST['femail'])) ? trim((string) $_POST['femail']) : '';
			$fname = (isset($_POST['fname']) && is_scalar($_POST['fname'])) ? trim((string) $_POST['fname']) : '';
			$sname = (isset($_POST['sname']) && is_scalar($_POST['sname'])) ? trim((string) $_POST['sname']) : '';
			$semail = (isset($_POST['semail']) && is_scalar($_POST['semail'])) ? trim((string) $_POST['semail']) : '';
			$posted_subject = (isset($_POST['subject']) && is_scalar($_POST['subject'])) ? trim((string) $_POST['subject']) : '';
			$posted_message = (isset($_POST['message']) && is_scalar($_POST['message'])) ? trim((string) $_POST['message']) : '';

			if ($femail !== '' && filter_var($femail, FILTER_VALIDATE_EMAIL))
			{
				$user_email = stripslashes($femail);
			}
			else
			{
				$error = TRUE;
				$error_msg = ( !empty($error_msg) ) ? $error_msg . '<br />' . $lang['Email_invalid'] : $lang['Email_invalid'];
			}

			$username = trim(str_replace(array("\r", "\n"), '', strip_tags(stripslashes($fname))));
			$sender_name = trim(str_replace(array("\r", "\n"), '', strip_tags(stripslashes($sname))));

			if (!$userdata['session_logged_in'] || ($userdata['session_logged_in'] && $sender_name != $userdata['username']))
			{
				include($phpbb_root_path . 'includes/functions_validate.'.$phpEx);

				$result = validate_username($sender_name);
				if ($result['error'])
				{
					$error = TRUE;
					$error_msg .= (!empty($error_msg)) ? '<br />' . $result['error_msg'] : $result['error_msg'];
				}
			}
			else
			{
				$sender_name = $userdata['username'];
			}


			if(!$userdata['session_logged_in'])
			{
				if ($semail !== '' && filter_var($semail, FILTER_VALIDATE_EMAIL))
				{
					$sender_email = stripslashes($semail);
				}
				else
				{
					$error = TRUE;
					$error_msg = ( !empty($error_msg) ) ? $error_msg . '<br />' . $lang['Email_invalid'] : $lang['Email_invalid'];
				}
			}
			else
			{
				$sender_email = trim((string) $userdata['user_email']);
				if (!filter_var($sender_email, FILTER_VALIDATE_EMAIL))
				{
					$error = TRUE;
					$error_msg = (!empty($error_msg)) ? $error_msg . '<br />' . $lang['Email_invalid'] : $lang['Email_invalid'];
				}
			}

			if ($posted_subject !== '')
			{
				$subject = trim(str_replace(array("\r", "\n"), ' ', stripslashes($posted_subject)));
			}
			else
			{
				$error = TRUE;
				$error_msg = ( !empty($error_msg) ) ? $error_msg . '<br />' . $lang['Empty_subject_email'] : $lang['Empty_subject_email'];
			}

			if ($posted_message !== '')
			{
				$message = trim(stripslashes($posted_message));
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

				$email_headers = 'Return-Path: ' . $sender_email . "\nFrom: " . $sender_email . "\n";

				$email_headers .= 'X-AntiAbuse: Board servername - ' . str_replace(array("\r", "\n"), '', $board_config['server_name']) . "\n";

				$email_headers .= 'X-AntiAbuse: Username - ' . $sender_name . "\n";

				$emailer->use_template('profile_send_email', $board_config['default_lang']);

				$emailer->email_address($user_email);

				$emailer->set_subject($subject);

				$emailer->extra_headers($email_headers);

				$emailer->assign_vars(array(
					'SITENAME' => $board_config['sitename'],
					'BOARD_EMAIL' => $board_config['board_email'],
					'FROM_USERNAME' => $sender_name,
					'TO_USERNAME' => $username,
					'MESSAGE' => $message)
				);

				$emailer->send();

				$emailer->reset();

				$message = $lang['Econf'] . '<br /><br />' . sprintf($lang['Click_return'], '<a href="' . append_sid('dload.'.$phpEx.'?action=file&file_id=' . $file_id) . '">', '</a>') . '<br /><br />' . sprintf($lang['Click_return_forum'], '<a href="' . append_sid('index.'.$phpEx) . '">', '</a>');

				message_die(GENERAL_MESSAGE, $message);
			}

			if ( $error )
			{
				message_die(GENERAL_MESSAGE, $error_msg);
			}

		}


		$this->generate_category_nav($file_data['file_catid']);

		$pafiledb_template->assign_vars(array(
			'USER_LOGGED' => (!$userdata['session_logged_in']) ? TRUE : FALSE,

			'S_EMAIL_ACTION' => append_sid('dload.'.$phpEx),
			'S_HIDDEN_FIELDS' => '<input type="hidden" name="sid" value="' . htmlspecialchars($userdata['session_id'], ENT_QUOTES, 'UTF-8') . '" />',

			'L_INDEX' => sprintf($lang['Forum_Index'], $board_config['sitename']),
			'L_EMAIL' => $lang['Semail'],
			'L_EMAIL' => $lang['Emailfile'],
			'L_EMAILINFO' => $lang['Emailinfo'],
			'L_YNAME' => $lang['Yname'],
			'L_YEMAIL' => $lang['Yemail'],
			'L_FNAME' => $lang['Fname'],
			'L_FEMAIL' => $lang['Femail'],
			'L_ETEXT' => $lang['Etext'],
			'L_DEFAULTMAIL' => $lang['Defaultmail'],
			'L_SEMAIL' => $lang['Semail'],
			'L_ESUB' => $lang['Esub'],
			'L_EMPTY_SUBJECT_EMAIL' => $lang['Empty_subject_email'],
			'L_EMPTY_MESSAGE_EMAIL' => $lang['Empty_message_email'],

			'U_INDEX' => append_sid('index.'.$phpEx),
			'U_DOWNLOAD_HOME' => append_sid('dload.'.$phpEx),
			'U_FILE_NAME' => append_sid('dload.'.$phpEx.'?action=file&file_id=' . $file_id),

			'FILE_NAME' => pafiledb_html($file_data['file_name']),
			'SNAME' => $userdata['username'],
			'SEMAIL' => $userdata['user_email'],
			'DOWNLOAD' => $pafiledb_config['settings_dbname'],
			'FILE_URL' => get_formated_url() . '/dload.'.$phpEx.'?action=file&file_id=' . $file_id,
			'ID' => $file_id)
		);
		$this->display($lang['Download'], 'pa_email_body.tpl');
	}
}

?>
