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
require_once($phpbb_root_path . 'includes/functions_login_storage.' . $phpEx);

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
		// Refuse a controller-first partial update before any login publication.
		if (defined('CTRACKER_CONFIG') && !function_exists('phpbb_login_tracker_write')) { message_die(GENERAL_MESSAGE, $lang['Login_changed']); }
		if ($sid === '' || !hash_equals((string) $userdata['session_id'], $sid))
		{
			message_die(GENERAL_ERROR, $lang['Session_invalid']);
		}

		$username_input = phpbb_request_raw_value($submitted_username);
		$username = phpbb_clean_username($username_input);
		$password_value = (isset($_POST['password']) && is_scalar($_POST['password'])) ? (string) $_POST['password'] : '';
		$password = (strlen($password_value) <= 128) ? $password_value : '';
		$row = phpbb_username_lookup($db, $username_input, array('user_password','user_active','user_level','user_blocktime'));
		if ($row === false)
		{
			message_die(GENERAL_ERROR, 'Error in obtaining userdata');
		}

		if ($row)
		{
			$username_sql = $db->sql_escape($row['username']);
			if( $row['user_level'] != ADMIN && $board_config['board_disable'] )
			{
				redirect(append_sid("portal.$phpEx", true));
			}
			else
			{
				// Keep administrator-imposed blocks separate from rate limiting.
				if ($row['user_blocktime']<time() )
				{
					if( phpbb_password_verify($password, $row['user_password']) && $row['user_active'] )
					{
						$upgraded_password = null;
						if (!empty($board_config['password_hashing']) && phpbb_password_needs_rehash($row['user_password']))
						{
							$upgraded_password = phpbb_password_hash($password);
							if ($upgraded_password === false) { $upgraded_password = null; }
						}

						$autologin = ( isset($_POST['autologin']) ) ? TRUE : 0;
	
						$admin = (isset($HTTP_POST_VARS['admin'])) ? 1 : 0;
						try { $session_id = phpbb_login_session($db, $row, $user_ip, PAGE_INDEX, $autologin, $admin, $upgraded_password, $password, $ctracker_config); }
						catch (PhpbbLoginPasswordExpired $e) {
							$message = $lang['Passwd_have_expired'] . '<br /><br /><a href="'.append_sid("profile.$phpEx?mode=sendpassword").'">'.$lang['Send_new_passwd'].'</a><br /><br />' . sprintf($lang['Click_return_index'], '<a href="' . append_sid("index.$phpEx") . '">', '</a>');
							message_die(GENERAL_MESSAGE, $message);
						}
						catch (PhpbbLoginException $e) { message_die(GENERAL_MESSAGE, $lang['Login_changed']); }
	
						// Enabled CT history/IP writes commit with the owned session.
						// No telemetry failure may follow already-delivered cookies.
	
						if( $session_id )
						{
							// Start add - Protect user account MOD
							// The guarded session publication already reset this account's
							// failed-login count by its locked ID, not a stale name.
							// End add - Protect user account MOD
							$redirect_value = (isset($_POST['redirect']) && is_scalar($_POST['redirect'])) ? (string) $_POST['redirect'] : '';
							$url = ( $redirect_value !== '' ) ? str_replace('&amp;', '&', htmlspecialchars($redirect_value)) : "portal.$phpEx";
							// Policy and any force-change marker were decided under the
							// account lock, before cookies. Never overwrite a later reset.
							if (!empty($session_id['login_change_password']))
							{
								$url .= (strpos($url, '?') !== false) ? '&ch_passwd=1' : '?ch_passwd=1';
							}
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
				// Use the DB-resolved spelling so case/accent aliases of the same
				// account cannot split its per-IP failed-attempt bucket.
				ctracker_enforce_login_identity_limit($row['username']);
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

				$message = $lang['Error_login'] . '<br /><br />' . sprintf($lang['Click_return_login'], '<a href="' . append_sid("login.$phpEx?redirect=$redirect") . '">', '</a>') . '<br /><br />' .  sprintf($lang['Click_return_index'], '<a href="' . append_sid("index.$phpEx") . '">', '</a>');
				message_die(GENERAL_MESSAGE, $message);
			}
		}
		else
		{
			// Keep unknown account names on the same adaptive-hash timing path as
			// real accounts before returning the common login error.
			phpbb_password_verify($password, '');
			// Unknown names must reach the same failed-attempt limiter. Otherwise
			// its throttled response would reveal which account names exist.
			ctracker_enforce_login_identity_limit($username);
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

			$message = $lang['Error_login'] . '<br /><br />' . sprintf($lang['Click_return_login'], '<a href="' . append_sid("login.$phpEx?redirect=$redirect") . '">', '</a>') . '<br /><br />' .  sprintf($lang['Click_return_index'], '<a href="' . append_sid("index.$phpEx") . '">', '</a>');

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
