<?php
/***************************************************************************
 *                                login.php
 *                            -------------------
 *   begin                : Saturday, Feb 13, 2001
 *   copyright            : (C) 2001 The phpBB Group
 *   email                : support@phpbb.com
 *
 *   $Id: login.php,v 1.47.2.15 2004/03/18 18:15:51 acydburn Exp $
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
 ***************************************************************************/

//
// Allow people to reach login page if
// board is shut down
//
define('IN_LOGIN', true);

if (!defined('IN_PHPBB'))
{
    define( 'IN_PHPBB', true);
}
$phpbb_root_path = './';
include($phpbb_root_path . 'extension.inc');
include($phpbb_root_path . 'common.'.$phpEx);

//
// Set page ID for session management
//
$userdata = session_pagestart($user_ip, PAGE_LOGIN);
init_userprefs($userdata);
//
// End session management
//

// session id check
$post_sid = (isset($_POST['sid']) && is_scalar($_POST['sid'])) ? (string) $_POST['sid'] : '';
$get_sid = (isset($_GET['sid']) && is_scalar($_GET['sid'])) ? (string) $_GET['sid'] : '';
if ($post_sid !== '' || $get_sid !== '')
{
	$sid = ($post_sid !== '') ? $post_sid : $get_sid;
}
else
{
	$sid = '';
}

$submitted_username = (isset($_POST['username']) && is_scalar($_POST['username'])) ? (string) $_POST['username'] : '';
if( isset($_POST['login']) || isset($_POST['logout']) || isset($_GET['logout']) )
{
	if( isset($_POST['login']) && (!$userdata['session_logged_in'] || isset($_POST['admin'])) )
	{
		if ($sid === '' || !hash_equals((string) $userdata['session_id'], $sid))
		{
			message_die(GENERAL_ERROR, $lang['Session_invalid']);
		}

		$username = ($submitted_username !== '') ? phpbb_clean_username($submitted_username) : '';
		$username_sql = $db->sql_escape(str_replace("\\'", "'", $username));
		$password_value = (isset($_POST['password']) && is_scalar($_POST['password'])) ? (string) $_POST['password'] : '';
		$password = (strlen($password_value) <= 128) ? $password_value : '';
		$sql = "SELECT user_id, username, user_password, user_active, user_level, user_login_tries, user_last_login_try, ct_login_count, user_badlogin, user_blocktime, user_email, user_lang, user_timezone,user_passwd_change
			FROM " . USERS_TABLE . "
			WHERE username = '" . $username_sql . "'";
		if ( !($result = $db->sql_query($sql)) )
		{
			message_die(GENERAL_ERROR, 'Error in obtaining userdata', '', __LINE__, __FILE__, $sql);
		}

		if( $row = $db->sql_fetchrow($result) )
		{
			if( $row['user_level'] != ADMIN && $board_config['board_disable'] )
			{
				redirect(append_sid("portal.$phpEx", true));
			}
			else
			{
				// Start add - Protect user account MOD
				if ($row['user_blocktime']<time() )
				{
					/*
					// If the last login is more than x minutes ago, then reset the login tries/time
					if ($row['user_last_login_try'] && $board_config['login_reset_time'] && $row['user_last_login_try'] < (time() - ($board_config['login_reset_time'] * 60)))
					{
						$db->sql_query('UPDATE ' . USERS_TABLE . ' SET user_login_tries = 0, user_last_login_try = 0 WHERE user_id = ' . $row['user_id']);
						$row['user_last_login_try'] = $row['user_login_tries'] = 0;
					}
					
					// Check to see if user is allowed to login again... if his tries are exceeded
					if ($row['user_last_login_try'] && $board_config['login_reset_time'] && $board_config['max_login_attempts'] && 
						$row['user_last_login_try'] >= (time() - ($board_config['login_reset_time'] * 60)) && $row['user_login_tries'] >= $board_config['max_login_attempts'] && $userdata['user_level'] != ADMIN)
					{
						message_die(GENERAL_MESSAGE, sprintf($lang['Login_attempts_exceeded'], $board_config['max_login_attempts'], $board_config['login_reset_time']));
					}
					*/
					// End add - Protect user account MOD
					if( phpbb_password_verify($password, $row['user_password']) && $row['user_active'] )
					{
						if (!empty($board_config['password_hashing']) && phpbb_password_needs_rehash($row['user_password']))
						{
							$upgraded_password = phpbb_password_hash($password);
							if ($upgraded_password !== false)
							{
								$db->sql_query("UPDATE " . USERS_TABLE . " SET user_password = '" . str_replace("'", "''", $upgraded_password) . "' WHERE user_id = " . (int) $row['user_id']);
							}
						}

						$autologin = ( isset($_POST['autologin']) ) ? TRUE : 0;
	
						$admin = (isset($HTTP_POST_VARS['admin'])) ? 1 : 0;
						$session_id = session_begin($row['user_id'], $user_ip, PAGE_INDEX, FALSE, $autologin, $admin);
	
						// Start add - Protect user account MOD
						/*
						// Reset login tries
						$db->sql_query('UPDATE ' . USERS_TABLE . ' SET user_login_tries = 0, user_last_login_try = 0 WHERE user_id = ' . $row['user_id']);
						*/
						// End add - Protect user account MOD

						// CrackerTracker v5.x
						if ( $ctracker_config->settings['login_history'] == 1 )
						{
							$ctracker_config->update_login_history($row['user_id']);
						}
						// Clear any legacy account-wide CAPTCHA flag after a valid login.
						$ctracker_config->reset_login_system($row['user_id']);
						if ( $ctracker_config->settings['login_ip_check'] == 1 )
						{
							$ctracker_config->set_user_ip($row['user_id']);
						}
	
						if( $session_id )
						{
							// Start add - Protect user account MOD
							$sql = "UPDATE " . USERS_TABLE . " SET user_badlogin='0'
								WHERE username = '" . $username_sql . "'";
							if ( !($result = $db->sql_query($sql)) )
							{
								message_die(GENERAL_ERROR, 'Error updating correct login data', '', __LINE__, __FILE__, $sql);
							}
							// End add - Protect user account MOD
							$redirect_value = (isset($_POST['redirect']) && is_scalar($_POST['redirect'])) ? (string) $_POST['redirect'] : '';
							$url = ( $redirect_value !== '' ) ? str_replace('&amp;', '&', htmlspecialchars($redirect_value)) : "portal.$phpEx";
							// Start add - Protect user account MOD
							if ($session_id['user_id']!=ANONYMOUS )
							{
								include($phpbb_root_path . "includes/functions_validate.$phpEx");
								$pass_result = validate_complex_password ($username, $password);
								if ( $session_id['user_passwd_change']==0 || $pass_result['error']== true)
								{
									//force a change of password, do not allow a secound login
									$sql = "UPDATE " . USERS_TABLE . " SET user_passwd_change='-9999'
									WHERE user_id = '" . $session_id['user_id'] . "'";
									if ( !($result = $db->sql_query($sql)) )
									{
										message_die(GENERAL_ERROR, 'Error updating correct login data2', '', __LINE__, __FILE__, $sql);
									}
									$url .= ( strpos($url, '?') !== false ) ? '&ch_passwd=1' : '?ch_passwd=1';
								} else
								if ((  intval((time()-$session_id['user_passwd_change']) / 86400) >= $board_config['max_password_age'])&&$board_config['max_password_age'] > 0)
								{
									session_end($session_id['session_id'], $session_id['user_id']);
									$message = $lang['Passwd_have_expired'] . '<br /><br /><a href="'.append_sid("profile.$phpEx?mode=sendpassword").'">'.$lang['Send_new_passwd'].'</a><br /><br />' .  sprintf($lang['Click_return_portal'], '<a href="' . append_sid("portal.$phpEx") . '">', '</a>');
									message_die(GENERAL_MESSAGE, $message);
								} else
								if (( intval((time()-$session_id['user_passwd_change']) / 86400)+(($board_config['max_password_age']<14) ? 1 : 14) >= $board_config['max_password_age'] )&&$board_config['max_password_age'] > 0)
								{
									$url .= ( strpos($url, '?') !== false ) ? '&ch_passwd=1' : '?ch_passwd=1';
								}
							}
							// End add - Protect user account MOD
							redirect(append_sid($url, true));
						}
						else
						{
							message_die(CRITICAL_ERROR, "Couldn't start session : login", "", __LINE__, __FILE__);
						}
					}
					// Only store a failed login attempt for an active user - inactive users can't login even with a correct password
					elseif( $row['user_active'] )
					{
						// Start add - Protect user account MOD
						/*
						// Save login tries and last login
						if ($row['user_id'] != ANONYMOUS)
						{
							$sql = 'UPDATE ' . USERS_TABLE . '
								SET user_login_tries = user_login_tries + 1, user_last_login_try = ' . time() . '
								WHERE user_id = ' . $row['user_id'];
							$db->sql_query($sql);
						}
						*/
						// End add - Protect user account MOD
						if ($row['user_id'] != ANONYMOUS)
						{
							// CrackerTracker v5.x
							include_once($phpbb_root_path . 'ctracker/classes/class_log_manager.' . $phpEx);
							$logfile = new log_manager();
							$logfile->prepare_log($row['username']);
							$logfile->write_general_logfile($ctracker_config->settings['logsize_logins'], 4);
							unset($logfile);
						}
						// Record the event without allowing an unauthenticated attacker
						// to hard-lock another user's account or trigger email floods.
						// CrackerTracker's central and per-IP/account limiters remain
						// responsible for slowing repeated guesses.
						$sql = "UPDATE " . USERS_TABLE . " SET user_badlogin = user_badlogin + 1
							WHERE user_id = " . intval($row['user_id']);
						if (!$db->sql_query($sql))
						{
							message_die(GENERAL_ERROR, 'Error updating bad login data', '', __LINE__, __FILE__, $sql);
						}
					}
				}
				// Apply this to existing and unknown names alike to avoid turning the
				// limiter into an account-enumeration signal.
				ctracker_enforce_login_identity_limit($submitted_username);
				$redirect_value = (isset($_POST['redirect']) && is_scalar($_POST['redirect'])) ? (string) $_POST['redirect'] : '';
				$redirect = ( $redirect_value !== '' ) ? str_replace('&amp;', '&', htmlspecialchars($redirect_value)) : '';
				$redirect = str_replace('?', '&', $redirect);
				
				if (strstr(urldecode($redirect), "\n") || strstr(urldecode($redirect), "\r") || strstr(urldecode($redirect), ';url'))
				{
					message_die(GENERAL_ERROR, 'Tried to redirect to potentially insecure url.');
				}
				
				$template->assign_vars(array(
					'META' => "<meta http-equiv=\"refresh\" content=\"3;url=login.$phpEx?redirect=$redirect\">")
				);

			// Start add - Protect user account MOD
		/*
				$message = $lang['Error_login'] . '<br /><br />' . sprintf($lang['Click_return_login'], "<a href=\"login.$phpEx?redirect=$redirect\">", '</a>') . '<br /><br />' .  sprintf($lang['Click_return_index'], '<a href="' . append_sid("index.$phpEx") . '">', '</a>');
		*/
				$message = $lang['Error_login'] . '<br /><br />' . sprintf($lang['Click_return_login'], '<a href="' . append_sid("login.$phpEx?redirect=$redirect") . '">', '</a>') . '<br /><br />' .  sprintf($lang['Click_return_index'], '<a href="' . append_sid("index.$phpEx") . '">', '</a>');
				// End add - Protect user account MOD
				message_die(GENERAL_MESSAGE, $message);
			}
		}
		else
		{
			$redirect_value = (isset($_POST['redirect']) && is_scalar($_POST['redirect'])) ? (string) $_POST['redirect'] : '';
			$redirect = ( $redirect_value !== '' ) ? str_replace('&amp;', '&', htmlspecialchars($redirect_value)) : "";
			$redirect = str_replace("?", "&", $redirect);
			
			if (strstr(urldecode($redirect), "\n") || strstr(urldecode($redirect), "\r") || strstr(urldecode($redirect), ';url'))
			{
				message_die(GENERAL_ERROR, 'Tried to redirect to potentially insecure url.');
			}
			
			$template->assign_vars(array(
				'META' => "<meta http-equiv=\"refresh\" content=\"3;url=login.$phpEx?redirect=$redirect\">")
			);

			$message = $lang['Error_login'] . '<br /><br />' . sprintf($lang['Click_return_login'], "<a href=\"login.$phpEx?redirect=$redirect\">", '</a>') . '<br /><br />' .  sprintf($lang['Click_return_index'], '<a href="' . append_sid("index.$phpEx") . '">', '</a>');

			message_die(GENERAL_MESSAGE, $message);
		}
	}
	else if( ( isset($_GET['logout']) || isset($_POST['logout']) ) && $userdata['session_logged_in'] )
	{
		// session id check
		if ($sid === '' || !hash_equals((string) $userdata['session_id'], $sid))
		{
			message_die(GENERAL_ERROR, 'Invalid_session');
		}

		if( $userdata['session_logged_in'] )
		{
			session_end($userdata['session_id'], $userdata['user_id']);
		}

		$post_redirect = (isset($_POST['redirect']) && is_scalar($_POST['redirect'])) ? (string) $_POST['redirect'] : '';
		$get_redirect = (isset($_GET['redirect']) && is_scalar($_GET['redirect'])) ? (string) $_GET['redirect'] : '';
		if ($post_redirect !== '' || $get_redirect !== '')
		{
			$url = htmlspecialchars($post_redirect !== '' ? $post_redirect : $get_redirect);
			$url = str_replace('&amp;', '&', $url);
			redirect(append_sid($url, true));
		}
		else
		{
			redirect(append_sid("portal.$phpEx", true));
		}
	}
	else
	{
		redirect(append_sid("portal.$phpEx", true));
	}
}
else
{
	//
	// Do a full login page dohickey if
	// user not already logged in
	//
	include_once($phpbb_root_path . 'includes/functions_jr_admin.' . $phpEx);
	$jr_admin_userdata = jr_admin_get_user_info($userdata['user_id']);
	
	if( !$userdata['session_logged_in'] || (isset($_GET['admin']) && $userdata['session_logged_in'] && (!empty($jr_admin_userdata['user_jr_admin']) || $userdata['user_level'] == ADMIN)))
	{
		$page_title = $lang['Login'];
		include($phpbb_root_path . 'includes/page_header.'.$phpEx);

		$template->set_filenames(array(
			'body' => 'login_body.tpl')
		);

		$forward_page = '';
		if( isset($_POST['redirect']) || isset($_GET['redirect']) )
		{
			$forward_to = $HTTP_SERVER_VARS['QUERY_STRING'];

			if( preg_match("/^redirect=([a-z0-9\.#\/\?&=\+\-_]+)/si", $forward_to, $forward_matches) )
			{
				$forward_to = ( !empty($forward_matches[3]) ) ? $forward_matches[3] : $forward_matches[1];
				$forward_match = explode('&', $forward_to);

				if(count($forward_match) > 1)
				{
					for($i = 1; $i < count($forward_match); $i++)
					{
						if( !preg_match("/sid=/", $forward_match[$i]) )
						{
							if( $forward_page != '' )
							{
								$forward_page .= '&';
							}
							$forward_page .= $forward_match[$i];
						}
					}
					$forward_page = $forward_match[0] . '?' . $forward_page;
				}
				else
				{
					$forward_page = $forward_match[0];
				}
			}
		}

		$username = ( $userdata['user_id'] != ANONYMOUS ) ? $userdata['username'] : '';
		$hidden_form_fields = isset($hidden_form_fields) ? $hidden_form_fields : '';

		$s_hidden_fields = '<input type="hidden" name="redirect" value="' . phpbb_profile_text($forward_page) . '" />';
		$s_hidden_fields .= '<input type="hidden" name="sid" value="' . phpbb_profile_text($userdata['session_id']) . '" />';

		$s_hidden_fields .= (isset($_GET['admin'])) ? '<input type="hidden" name="admin" value="1" />' : '';

		make_jumpbox('viewforum.'.$phpEx);
		$template->assign_vars(array(
			'USERNAME' => $username,

			'L_ENTER_PASSWORD' => (isset($_GET['admin'])) ? $lang['Admin_reauthenticate'] : $lang['Enter_password'],
			'L_SEND_PASSWORD' => $lang['Forgotten_password'],
			'U_SEND_PASSWORD' => append_sid("profile.$phpEx?mode=sendpassword"),

			'S_HIDDEN_FIELDS' => $s_hidden_fields . $hidden_form_fields )
		);

		$template->pparse('body');

		include($phpbb_root_path . 'includes/page_tail.'.$phpEx);
	}
	else
	{
		redirect(append_sid("portal.$phpEx", true));
	}

}

?>
