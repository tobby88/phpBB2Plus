<?php
/***************************************************************************
 *                            usercp_register.php
 *                            -------------------
 *   begin                : Saturday, Feb 13, 2001
 *   copyright            : (C) 2001 The phpBB Group
 *   email                : support@phpbb.com
 *
 *   $Id: usercp_register.php,v 1.20.2.57 2004/03/25 15:57:20 acydburn Exp $
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
function gen_reg_key()
{
	$key = "";
	$max_length_reg_key = 5;
	$chars = "abcdefghijklmnopqrstuvwxyz";
	$random_bytes = phpbb_random_bytes($max_length_reg_key);

	for($i = 0; $i < $max_length_reg_key; $i++)
	{
		$key .= $chars[ord($random_bytes[$i]) % 26];
	}

	return($key);
}

function usercp_post_scalar($name, $default = '')
{
	return (isset($_POST[$name]) && is_scalar($_POST[$name])) ? (string) $_POST[$name] : $default;
}

function usercp_sql_value($value)
{
	global $db;
	return $db->sql_escape((string) $value);
}

function usercp_avatar_file_scalar($name, $default = '')
{
	global $HTTP_POST_FILES;

	return (isset($HTTP_POST_FILES['avatar']) && is_array($HTTP_POST_FILES['avatar']) &&
		isset($HTTP_POST_FILES['avatar'][$name]) && is_scalar($HTTP_POST_FILES['avatar'][$name]))
		? (string) $HTTP_POST_FILES['avatar'][$name]
		: $default;
}

function usercp_installed_language($language, $fallback)
{
	global $phpbb_root_path;

	$language = strtolower(trim((string) $language));
	if (!preg_match('/^[a-z_]{1,30}$/D', $language))
	{
		return $fallback;
	}

	$directory = $phpbb_root_path . 'language/lang_' . $language;
	return (is_dir($directory) && !is_link($directory) && is_file($directory . '/lang_main.php')) ? $language : $fallback;
}

function usercp_installed_style($style, $fallback)
{
	global $db, $phpbb_root_path;

	$style = (int) $style;
	$sql = 'SELECT * FROM ' . THEMES_TABLE . ' WHERE themes_id = ' . $style;
	$result = $db->sql_query($sql);
	if (!$result)
	{
		return (int) $fallback;
	}
	$row = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);
	if (!$row || (isset($row['theme_public']) && !$row['theme_public']))
	{
		return (int) $fallback;
	}
	$template_name = isset($row['template_name']) ? (string) $row['template_name'] : '';
	return preg_match('/^[A-Za-z0-9_-]+$/D', $template_name) && is_dir($phpbb_root_path . 'templates/' . $template_name) ? $style : (int) $fallback;
}

function usercp_timezone($timezone, $fallback)
{
	global $lang;

	$timezone = (float) $timezone;
	foreach ($lang['tz'] as $offset => $label)
	{
		if ((float) $offset === $timezone)
		{
			return $timezone;
		}
	}
	return (float) $fallback;
}

function usercp_dateformat($format, $fallback)
{
	$format = trim((string) $format);
	return ($format !== '' && strlen($format) <= 64 && !preg_match('/[\x00-\x1f\x7f]/', $format)) ? $format : $fallback;
}

$unhtml_specialchars_match = array('#&gt;#', '#&lt;#', '#&quot;#', '#&amp;#');
$unhtml_specialchars_replace = array('>', '<', '"', '&');

// BEGIN CrackerTracker v5.x
include_once($phpbb_root_path . 'ctracker/classes/class_ct_userfunctions.' . $phpEx);
$profile_security = new ct_userfunctions();
$profile_security->handle_profile();
(isset($HTTP_POST_VARS['submit']))? $profile_security->password_functions() : null;
// END CrackerTracker v5.x

// ---------------------------------------
// Load agreement template since user has not yet
// agreed to registration conditions/coppa
//

// BEGIN Disable Registration MOD
if( $board_config['registration_status'] && !$userdata['session_logged_in'] )
{
  if( $board_config['registration_closed'] == '' )
  {
    message_die(GENERAL_MESSAGE, 'registration_status', 'Information');
  }
  else
  {
    message_die(GENERAL_MESSAGE, $board_config['registration_closed'], 'Information'); 
  }  
}
// END Disable Registration MOD

function show_coppa()
{
	global $userdata, $template, $lang, $phpbb_root_path, $phpEx;

	$template->set_filenames(array(
		'body' => 'agreement.tpl')
	);

	$template->assign_vars(array(
		'REGISTRATION' => $lang['Registration'],
		'AGREEMENT' => $lang['Reg_agreement'],
		"AGREE_OVER_13" => $lang['Agree_over_13'],
		"AGREE_UNDER_13" => $lang['Agree_under_13'],
		'DO_NOT_AGREE' => $lang['Agree_not'],

		"U_AGREE_OVER13" => append_sid("profile.$phpEx?mode=register&amp;agreed=true"),
		"U_AGREE_UNDER13" => append_sid("profile.$phpEx?mode=register&amp;agreed=true&amp;coppa=true"))
	);

	$template->pparse('body');

}
//
// Custom Profile Fields MOD
//
include_once($phpbb_root_path . 'includes/functions_profile_fields.'.$phpEx);
//
// END Custom Profile Fields MOD
//
//
// ---------------------------------------

$error = FALSE;
$error_msg = '';
$profile_data = array();
$page_title = ( $mode == 'editprofile' ) ? $lang['Edit_profile'] : $lang['Register'];

if ( $mode == 'register' && !isset($_POST['agreed']) && !isset($_GET['agreed']) )
{
	include($phpbb_root_path . 'includes/page_header.'.$phpEx);

	show_coppa();

	include($phpbb_root_path . 'includes/page_tail.'.$phpEx);
}

$coppa = ( empty($_POST['coppa']) && empty($_GET['coppa']) ) ? 0 : TRUE;

//
// Check and initialize some variables if needed
//

if (
	isset($_POST['submit']) ||
	isset($_POST['avatargallery']) ||
	isset($_POST['submitavatar']) ||
	isset($_POST['cancelavatar']) ||
	$mode == 'register' )
{
	include($phpbb_root_path . 'includes/functions_validate.'.$phpEx);
	include($phpbb_root_path . 'includes/bbcode.'.$phpEx);
	include($phpbb_root_path . 'includes/functions_post.'.$phpEx);

	if ( $mode == 'editprofile' )
	{
		$user_id = intval(usercp_post_scalar('user_id', '0'));
		$current_email = (isset($_POST['current_email']) && is_scalar($_POST['current_email'])) ? trim(htmlspecialchars((string) $_POST['current_email'])) : '';
	}

	$strip_var_list = array('email' => 'email', 'fb' => 'fb', 'ig' => 'ig', 'twr' => 'twr', 'tg' => 'tg', 'li' => 'li', 'tt' => 'tt', 'dc' => 'dc', 'signal' => 'signal', 'threema' => 'threema', 'website' => 'website', 'location' => 'location', 'occupation' => 'occupation', 'interests' => 'interests', 'confirm_code' => 'confirm_code');

	// Strip all tags from data ... may p**s some people off, bah, strip_tags is
	// doing the job but can still break HTML output ... have no choice, have
	// to use htmlspecialchars ... be prepared to be moaned at.
	foreach ($strip_var_list as $var => $param)
	{
		$$var = (!empty($_POST[$param]) && is_scalar($_POST[$param])) ? trim(htmlspecialchars((string) $_POST[$param])) : '';
	}
	foreach (array('fb', 'ig', 'twr', 'tg', 'li', 'tt', 'dc', 'signal', 'threema') as $social_field)
	{
		if (!isset($$social_field))
		{
			$$social_field = '';
		}
	}
	// Retain retired messenger data when a profile is edited. The fields are no
	// longer shown, but silently blanking historic values would be data loss.
	if ($mode == 'editprofile')
	{
		$icq = isset($userdata['user_icq']) ? $userdata['user_icq'] : '';
		$aim = isset($userdata['user_aim']) ? str_replace('+', ' ', $userdata['user_aim']) : '';
		$msn = isset($userdata['user_msnm']) ? $userdata['user_msnm'] : '';
		$yim = isset($userdata['user_yim']) ? $userdata['user_yim'] : '';
		$pt = isset($userdata['user_pt']) ? $userdata['user_pt'] : '';
		$skp = isset($userdata['user_skp']) ? $userdata['user_skp'] : '';
	}
	else
	{
		$icq = $aim = $msn = $yim = $pt = $skp = '';
	}

	$username = ( !empty($HTTP_POST_VARS['username']) && is_scalar($HTTP_POST_VARS['username']) ) ? phpbb_clean_username((string) $HTTP_POST_VARS['username']) : '';
	foreach (array('cur_password', 'new_password', 'password_confirm') as $password_var)
	{
		$$password_var = (isset($_POST[$password_var]) && is_scalar($_POST[$password_var])) ? (string) $_POST[$password_var] : '';
	}
	$signature = (!empty($_POST['signature']) && is_scalar($_POST['signature'])) ? trim((string) $_POST['signature']) : '';

	$signature = (isset($signature)) ? str_replace('<br />', "\n", $signature) : '';
	$signature_bbcode_uid = '';
	// Start add - Gender MOD
	$gender = intval(usercp_post_scalar('gender', '0'));
	// End add - Gender MOD
	// Start add - Birthday MOD
	if (isset($_POST['birthday']) )
	{
		$birthday = intval(usercp_post_scalar('birthday', '0'));
		if ($birthday!=999999)
		{
			$b_day = realdate('j',$birthday); 
			$b_md = realdate('n',$birthday); 
			$b_year = realdate('Y',$birthday);
		}
	} else
	{
		$b_day = intval(usercp_post_scalar('b_day', '0'));
		$b_md = intval(usercp_post_scalar('b_md', '0'));
		$b_year = intval(usercp_post_scalar('b_year', '0'));
		if ($b_day && $b_md && $b_year)
		{
			$birthday = mkrealdate($b_day,$b_md,$b_year);
		} else
		{
			$birthday = 999999;
			$next_birthday_greeting = 0;
		}
	}
// End add - Birthday MOD
	// Run some validation on the optional fields. These are pass-by-ref, so they'll be changed to
	// empty strings if they fail.
	$user_absence_text = htmlspecialchars(usercp_post_scalar('user_absence_text'));
	validate_optional_fields($icq, $aim, $msn, $yim, $website, $location, $occupation, $interests, $signature, $user_absence_text);

	$viewemail = ( isset($_POST['viewemail']) ) ? ( ($_POST['viewemail']) ? TRUE : 0 ) : 0;
	$user_absence_mode = abs(intval(usercp_post_scalar('user_absence_mode', '0')));
	$user_absence = ( isset($_POST['user_absence']) ) ? ( ($_POST['user_absence']) ? TRUE : 0 ) : 0;
	$allowviewonline = ( isset($_POST['hideonline']) ) ? ( ($_POST['hideonline']) ? 0 : TRUE ) : TRUE;
	$notifyreply = ( isset($_POST['notifyreply']) ) ? ( ($_POST['notifyreply']) ? TRUE : 0 ) : 0;
	$notifypm = ( isset($_POST['notifypm']) ) ? ( ($_POST['notifypm']) ? TRUE : 0 ) : TRUE;
	$games_block_pm = ( isset($_POST['games_block_pm']) ) ? ( ($_POST['games_block_pm']) ? TRUE : 0 ) : TRUE;
	$popup_pm = ( isset($_POST['popup_pm']) ) ? ( ($_POST['popup_pm']) ? TRUE : 0 ) : TRUE;
	$setbm = ( isset($_POST['setbm']) ) ? ( ($_POST['setbm']) ? TRUE : 0 ) : 0;
	$sid = usercp_post_scalar('sid');
	
	if ( $mode == 'register' )
	{
		$attachsig = ( isset($_POST['attachsig']) ) ? ( ($_POST['attachsig']) ? TRUE : 0 ) : $board_config['allow_sig'];

		$allowhtml = ( isset($_POST['allowhtml']) ) ? ( ($_POST['allowhtml']) ? TRUE : 0 ) : $board_config['allow_html'];
		$allowbbcode = ( isset($_POST['allowbbcode']) ) ? ( ($_POST['allowbbcode']) ? TRUE : 0 ) : $board_config['allow_bbcode'];
		$allowsmilies = ( isset($_POST['allowsmilies']) ) ? ( ($_POST['allowsmilies']) ? TRUE : 0 ) : $board_config['allow_smilies'];
	}
	else
	{
		$attachsig = ( isset($_POST['attachsig']) ) ? ( ($_POST['attachsig']) ? TRUE : 0 ) : 0;

		$allowhtml = ( isset($_POST['allowhtml']) ) ? ( ($_POST['allowhtml']) ? TRUE : 0 ) : $userdata['user_allowhtml'];
		$allowbbcode = ( isset($_POST['allowbbcode']) ) ? ( ($_POST['allowbbcode']) ? TRUE : 0 ) : $userdata['user_allowbbcode'];
		$allowsmilies = ( isset($_POST['allowsmilies']) ) ? ( ($_POST['allowsmilies']) ? TRUE : 0 ) : $userdata['user_allowsmile'];
	}

	$user_style = usercp_installed_style(usercp_post_scalar('style', (string) $board_config['default_style']), $board_config['default_style']);

	$submitted_language = usercp_post_scalar('language');
	if ( $submitted_language !== '' )
	{
		$installed_language = usercp_installed_language($submitted_language, '');
		if ($installed_language !== '')
		{
			$user_lang = htmlspecialchars($installed_language);
		}
		else
		{
			$error = true;
			$error_msg = $lang['Fields_empty'];
		}
	}
	else
	{
		$user_lang = $board_config['default_lang'];
	}

	$user_timezone = usercp_timezone(usercp_post_scalar('timezone', (string) $board_config['board_timezone']), $board_config['board_timezone']);
	// FLAGHACK-start
	$user_flag_value = usercp_post_scalar('user_flag');
	$user_flag = ( $user_flag_value !== '' ) ? phpbb_profile_image_name($user_flag_value) : '' ;
	// FLAGHACK-end
	$sql = "SELECT config_value
		FROM " . CONFIG_TABLE . "
		WHERE config_name = 'default_dateformat'";
	if ( !($result = $db->sql_query($sql)) )
	{
		message_die(GENERAL_ERROR, 'Could not select default dateformat', '', __LINE__, __FILE__, $sql);
	}
	$row = $db->sql_fetchrow($result);
	$board_config['default_dateformat'] = $row['config_value'];
	$dateformat_value = usercp_post_scalar('dateformat');
	$user_dateformat = htmlspecialchars(usercp_dateformat($dateformat_value, $board_config['default_dateformat']));

	$avatarselect_value = usercp_post_scalar('avatarselect');
	$avatarlocal_value = usercp_post_scalar('avatarlocal');
	$avatarcatname_value = usercp_post_scalar('avatarcatname');
	$user_avatar_local = ( $avatarselect_value !== '' && !empty($_POST['submitavatar']) && $board_config['allow_avatar_local'] ) ? htmlspecialchars($avatarselect_value) : ( ( $avatarlocal_value !== '' ) ? htmlspecialchars($avatarlocal_value) : '' );
	$user_avatar_category = ( $avatarcatname_value !== '' && $board_config['allow_avatar_local'] ) ? htmlspecialchars($avatarcatname_value) : '' ;

	$avatarremoteurl_value = usercp_post_scalar('avatarremoteurl');
	$avatarurl_value = usercp_post_scalar('avatarurl');
	$user_avatar_remoteurl = ( $avatarremoteurl_value !== '' ) ? trim(htmlspecialchars($avatarremoteurl_value)) : '';
	$avatar_tmp_name = usercp_avatar_file_scalar('tmp_name');
	$user_avatar_upload = $avatarurl_value !== '' ? trim($avatarurl_value) :
		($avatar_tmp_name !== '' && $avatar_tmp_name != 'none' ? $avatar_tmp_name : '');
	$user_avatar_name = usercp_avatar_file_scalar('name');
	$user_avatar_size = intval(usercp_avatar_file_scalar('size', '0'));
	$user_avatar_filetype = usercp_avatar_file_scalar('type');

	$user_avatar = ( empty($user_avatar_local) && $mode == 'editprofile' ) ? $userdata['user_avatar'] : '';
	$user_avatar_type = ( empty($user_avatar_local) && $mode == 'editprofile' ) ? $userdata['user_avatar_type'] : '';

	if ( (isset($_POST['avatargallery']) || isset($_POST['submitavatar']) || isset($_POST['cancelavatar'])) && (!isset($_POST['submit'])) )
	{
		$username = stripslashes($username);
		$email = stripslashes($email);
		$cur_password = htmlspecialchars(stripslashes($cur_password));
		$new_password = htmlspecialchars(stripslashes($new_password));
		$password_confirm = htmlspecialchars(stripslashes($password_confirm));

		$icq = stripslashes($icq);
		$aim = stripslashes($aim);
		$msn = stripslashes($msn);
		$yim = stripslashes($yim);
		$fb = stripslashes($fb);
		$ig = stripslashes($ig);
		$pt = stripslashes($pt);
		$twr = stripslashes($twr);
		$skp = stripslashes($skp);
		$tg = stripslashes($tg);
		$signal = stripslashes($signal);
		$threema = stripslashes($threema);
		$li = stripslashes($li);
		$tt = stripslashes($tt);
		$dc = stripslashes($dc);

		$website = stripslashes($website);
		$location = stripslashes($location);
		$occupation = stripslashes($occupation);
		$interests = stripslashes($interests);
		$user_absence_text = stripslashes($user_absence_text);
		$signature = htmlspecialchars(stripslashes($signature));

		$user_lang = stripslashes($user_lang);
		$user_dateformat = stripslashes($user_dateformat);

		if ( !isset($_POST['cancelavatar']))
		{
			$user_avatar = $user_avatar_category . '/' . $user_avatar_local;
			$user_avatar_type = USER_AVATAR_GALLERY;
		}
	}
}

//
// Let's make sure the user isn't logged in while registering,
// and ensure that they were trying to register a second time
// (Prevents double registrations)
//
if ($mode == 'register' && ($userdata['session_logged_in'] || $username == $userdata['username']))
{
	message_die(GENERAL_MESSAGE, $lang['Username_taken'], '', __LINE__, __FILE__);
}

//
// Did the user submit? In this case build a query to update the users profile in the DB
//
if ( isset($_POST['submit']) )
{
	include($phpbb_root_path . 'includes/usercp_avatar.'.$phpEx);
	// session id check
	if ($sid == '' || $sid != $userdata['session_id'])
	{
		$error = true;
		$error_msg .= ( ( isset($error_msg) ) ? '<br />' : '' ) . $lang['Session_invalid'];
	}
	$passwd_sql = '';
	if ( $mode == 'editprofile' )
	{
		if ( $user_id != $userdata['user_id'] )
		{
			$error = TRUE;
			$error_msg .= ( ( isset($error_msg) ) ? '<br />' : '' ) . $lang['Wrong_Profile'];
		}
	}
	else if ( $mode == 'register' )
	{
		if ( empty($username) || empty($new_password) || empty($password_confirm) || empty($email) )
		{
			$error = TRUE;
			$error_msg .= ( ( isset($error_msg) ) ? '<br />' : '' ) . $lang['Fields_empty'];
		}
		
		if ($plus_config['enable_antirobot'])
		{
		
			//
			// Anti Robotic Registration
			//
			$sql = "SELECT * FROM " . ANTI_ROBOT_TABLE . " WHERE session_id = '" . $userdata['session_id'] . "' LIMIT 1";
			if( !$result = $db->sql_query($sql) )
			{
				message_die(GENERAL_ERROR, 'Could not obtain registration information', '', __LINE__, __FILE__, $sql);
			}
	
			$anti_robot_row = $db->sql_fetchrow($result);
	 
			if (( strtolower(usercp_post_scalar('reg_key')) != $anti_robot_row['reg_key'] ) or ($anti_robot_row['reg_key'] == ''))
			{
				$error = TRUE;
				$error_msg .= ( ( isset($error_msg) ) ? '<br />' : '' ) . $lang['Wrong_reg_key'];
			}
			else
			{
				$sql = "DELETE FROM " . ANTI_ROBOT_TABLE . " WHERE session_id = '" . $userdata['session_id'] . "'";
				if( !$result = $db->sql_query($sql) )
				{
					message_die(GENERAL_ERROR, 'Could not delete validation key', '', __LINE__, __FILE__, $sql);
				}
			}
			// --------------------------
			//
		} else
		if ($board_config['enable_confirm'] && !$plus_config['enable_antirobot'])
		{
			if (empty($_POST['confirm_id']))
			{
				$error = TRUE;
				$error_msg .= ( ( isset($error_msg) ) ? '<br />' : '' ) . $lang['Confirm_code_wrong'];
			}
			else
			{
				$confirm_id = htmlspecialchars(usercp_post_scalar('confirm_id'));
				if (!preg_match('/^[A-Za-z0-9]+$/', $confirm_id))
				{
					$confirm_id = '';
				}
				
				$sql = 'SELECT code 
					FROM ' . CONFIRM_TABLE . " 
					WHERE confirm_id = '$confirm_id' 
						AND session_id = '" . $userdata['session_id'] . "'";
				if (!($result = $db->sql_query($sql)))
				{
					message_die(GENERAL_ERROR, 'Could not obtain confirmation code', '', __LINE__, __FILE__, $sql);
				}
	
				if ($row = $db->sql_fetchrow($result))
				{
					if ($row['code'] != $confirm_code)
					{
						$error = TRUE;
						$error_msg .= ( ( isset($error_msg) ) ? '<br />' : '' ) . $lang['Confirm_code_wrong'];
					}
					else
					{
						$sql = 'DELETE FROM ' . CONFIRM_TABLE . " 
							WHERE confirm_id = '$confirm_id' 
								AND session_id = '" . $userdata['session_id'] . "'";
						if (!$db->sql_query($sql))
						{
							message_die(GENERAL_ERROR, 'Could not delete confirmation code', '', __LINE__, __FILE__, $sql);
						}
					}
				}
				else
				{		
					$error = TRUE;
					$error_msg .= ( ( isset($error_msg) ) ? '<br />' : '' ) . $lang['Confirm_code_wrong'];
				}
				$db->sql_freeresult($result);
			}
		}
	} // IF $mode == register
	//
	// Custom Profile Fields MOD
	//
	  $profile_data = get_fields('WHERE users_can_view = '.ALLOW_VIEW);
	  $profile_names = array();
	  
	  foreach($profile_data as $fields)
	  {
		$name = phpbb_profile_field_column($fields);
		if ($name === '')
		{
			continue;
		}
		$type = $fields['field_type'];
		$required = ($fields['is_required'] == REQUIRED) ? true : false;
		
		$temp = phpbb_profile_field_input($fields, $HTTP_POST_VARS);
		$profile_names[$name] = $temp;
		
		if($required && empty($profile_names[$name]))
		{
		  $error = TRUE;
				$error_msg .= ( ( isset($error_msg) ) ? '<br />' : '' ) . $lang['Fields_empty'];
		}
	  }
	//
	// END Custom Profile Fields MOD
	//

	$current_password_valid = ($mode == 'editprofile' && $cur_password !== '' && phpbb_password_verify($cur_password, $userdata['user_password']));
	$passwd_sql = '';
	if ( !empty($new_password) && !empty($password_confirm) )
	{
		// Start add - Protect user account MOD
		// validate that the password is complex
		$result = validate_complex_password ($username, $new_password);
		if ( $result['error'] )
		{
			$error = TRUE;
			$error_msg .= ( ( isset($error_msg) ) ? '<br />' : '' ) . $result['error_msg'];
		
		}
		// End add - Protect user account MOD
		if ( $new_password != $password_confirm )
		{
			$error = TRUE;
			$error_msg .= ( ( isset($error_msg) ) ? '<br />' : '' ) . $lang['Password_mismatch'];
		}
		else
		{
			if ( $mode == 'editprofile' )
			{
				if ( !$current_password_valid )
				{
					$error = TRUE;
					$error_msg .= ( ( isset($error_msg) ) ? '<br />' : '' ) . $lang['Current_password_mismatch'];
				}
			}

			if ( !$error )
			{
				$new_password = phpbb_password_hash($new_password);
				$passwd_sql = "user_password = '$new_password', ";
			}
		}
	}
	else if ( ( empty($new_password) && !empty($password_confirm) ) || ( !empty($new_password) && empty($password_confirm) ) )
	{
		$error = TRUE;
		$error_msg .= ( ( isset($error_msg) ) ? '<br />' : '' ) . $lang['Password_mismatch'];
	}

	if ( $email != $userdata['user_email'] || $mode == 'register' )
	{
		$result = validate_email($email, false);
		if ( $result['error'] )
		{
			$email = $userdata['user_email'];

			$error = TRUE;
			$error_msg .= ( ( isset($error_msg) ) ? '<br />' : '' ) . $result['error_msg'];
		}

		if ( $mode == 'editprofile' )
		{
			if ( !$current_password_valid )
			{
				$email = $userdata['user_email'];

				$error = TRUE;
				$error_msg .= ( ( isset($error_msg) ) ? '<br />' : '' ) . $lang['Current_password_mismatch'];
			}
		}
	}

	$username_sql = '';
	if ( $board_config['allow_namechange'] || $mode == 'register' )
	{
		if ( empty($username) )
		{
			// Error is already triggered, since one field is empty.
			$error = TRUE;
		}
		else if ( $username != $userdata['username'] || $mode == 'register' )
		{
			if ($mode == 'editprofile' && !$current_password_valid)
			{
				$error = TRUE;
				$error_msg .= ( ( isset($error_msg) ) ? '<br />' : '' ) . $lang['Current_password_mismatch'];
			}
			if (strtolower($username) != strtolower($userdata['username']) || $mode == 'register')
			{
				$result = validate_username($username, false);
				if ( $result['error'] )
				{
					$error = TRUE;
					$error_msg .= ( ( isset($error_msg) ) ? '<br />' : '' ) . $result['error_msg'];
				}
			}

			if (!$error)
			{
				$username_sql = "username = '" . usercp_sql_value($username) . "', ";
			}
		}
	}

	// External reputation checks run only after all local validation succeeds.
	// This prevents malformed registration floods from tying up PHP workers on
	// three remote requests each.
	if ($mode == 'register' && !$error && !empty($board_config['sfs_enable']))
	{
		$remote_address = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
		$sfs_values = array(
			'ip' => array($remote_address, $lang['You_been_banned']),
			'email' => array($email, $lang['Email_banned']),
			'username' => array($username, $lang['Username_disallowed'])
		);
		foreach ($sfs_values as $sfs_type => $sfs_data)
		{
			$sfs_result = stopforumspam($sfs_data[0], $sfs_type);
			if ($sfs_result === true)
			{
				$error = TRUE;
				$error_msg .= ( ( isset($error_msg) ) ? '<br />' : '' ) . $sfs_data[1];
				break;
			}
			if (is_array($sfs_result) && !empty($sfs_result['error']))
			{
				$error = TRUE;
				$error_msg .= ( ( isset($error_msg) ) ? '<br />' : '' ) . $sfs_result['error_msg'];
				break;
			}
		}
	}

	if ( $signature != '' )
	{
		if ( strlen($signature) > $board_config['max_sig_chars'] )
		{
			$error = TRUE;
			$error_msg .= ( ( isset($error_msg) ) ? '<br />' : '' ) . $lang['Signature_too_long'];
		}

		if ( !isset($signature_bbcode_uid) || $signature_bbcode_uid == '' )
		{
			$signature_bbcode_uid = ( $allowbbcode ) ? make_bbcode_uid() : '';
		}
		$signature = prepare_message($signature, $allowhtml, $allowbbcode, $allowsmilies, $signature_bbcode_uid);
	}

	if ( $website != '' )
	{
		rawurlencode($website);
	}

	$avatar_sql = '';

	if ( isset($_POST['avatardel']) && $mode == 'editprofile' )
	{
		$avatar_sql = user_avatar_delete($userdata['user_avatar_type'], $userdata['user_avatar']);
	}
		else
	if ( ( !empty($user_avatar_upload) || !empty($user_avatar_name) ) && $board_config['allow_avatar_upload'] )
	{
		if ( !empty($user_avatar_upload) )
		{
			$avatar_mode = (empty($user_avatar_name)) ? 'remote' : 'local'; 
			$avatar_sql = user_avatar_upload($mode, $avatar_mode, $userdata['user_avatar'], $userdata['user_avatar_type'], $error, $error_msg, $user_avatar_upload, $user_avatar_name, $user_avatar_size, $user_avatar_filetype);
		}
		else if ( !empty($user_avatar_name) )
		{
			$l_avatar_size = sprintf($lang['Avatar_filesize'], round($board_config['avatar_filesize'] / 1024));

			$error = true;
			$error_msg .= ( ( !empty($error_msg) ) ? '<br />' : '' ) . $l_avatar_size;
		}
	}
	else if ( $user_avatar_remoteurl != '' && $board_config['allow_avatar_remote'] )
	{
		user_avatar_delete($userdata['user_avatar_type'], $userdata['user_avatar']);
		$avatar_sql = user_avatar_url($mode, $error, $error_msg, $user_avatar_remoteurl);
	}
	else if ( $user_avatar_local != '' && $board_config['allow_avatar_local'] )
	{
		user_avatar_delete($userdata['user_avatar_type'], $userdata['user_avatar']);
		$avatar_sql = user_avatar_gallery($mode, $error, $error_msg, $user_avatar_local, $user_avatar_category);
	}
	// Start add - Birthday MOD
// find the birthday values, reflected by the $lang['Submit_date_format']
	if ($b_day || $b_md || $b_year) //if a birthday is submited, then validate it
	{
		$user_age=(date('md')>=$b_md.(($b_day <= 9) ? '0':'').$b_day) ? date('Y') - $b_year : date('Y') - $b_year - 1 ;
		// Check date, maximum / minimum user age
		if (!checkdate($b_md,$b_day,$b_year))
		{
			$error = TRUE;
			if( isset($error_msg) )$error_msg .= "<br />";
			$error_msg .= $lang['Wrong_birthday_format'];
		} else
		if ($user_age>$board_config['max_user_age'])
		{
			$error = TRUE;
			if( isset($error_msg) )$error_msg .= "<br />";
			$error_msg .= sprintf($lang['Birthday_to_high'],$board_config['max_user_age']);
		} else
		if ($user_age<$board_config['min_user_age'])
		{
			$error = TRUE;
			if( isset($error_msg) )$error_msg .= "<br />";
			$error_msg .= sprintf($lang['Birthday_to_low'],$board_config['min_user_age']);
		} else
		{
			$birthday = ($error) ? $birthday : mkrealdate($b_day,$b_md,$b_year);
			$next_birthday_greeting = (date('md')<$b_md.(($b_day <= 9) ? '0':'').$b_day) ? date('Y'):date('Y')+1 ;
		}
	} else
	{
		if ($board_config['birthday_required'])
		{
			$error = TRUE;
			if( isset($error_msg) )$error_msg .= "<br />";
			$error_msg .= sprintf($lang['Birthday_require']);
		}
		$birthday = 999999;
		$next_birthday_greeting = 0;
	}
// End add - Birthday MOD
	if ( !$error )
	{
		if ( $avatar_sql == '' )
		{
			$avatar_sql = ( $mode == 'editprofile' ) ? '' : "'', " . USER_AVATAR_NONE;
		}

		if ( $mode == 'editprofile' )
		{
			if ( $email != $userdata['user_email'] && $board_config['require_activation'] != USER_ACTIVATION_NONE && $userdata['user_level'] != ADMIN )
			{
				$user_active = 0;

				$user_actkey = gen_rand_string(true);

				if ( $userdata['session_logged_in'] )
				{
					session_end($userdata['session_id'], $userdata['user_id']);
				}
			}
			else
			{
				$user_active = 1;
				$user_actkey = '';
				// Start add - Protect user account MOD
				$passwd_sql .= (empty($passwd_sql)) ? "" : " user_passwd_change=".time().",";
				// End add - Protect user account MOD
			}

			$sql = "UPDATE " . USERS_TABLE . "
				SET " . $username_sql . $passwd_sql . "user_email = '" . usercp_sql_value($email) ."', user_icq = '" . usercp_sql_value($icq) . "', user_website = '" . usercp_sql_value($website) . "', user_occ = '" . usercp_sql_value($occupation) . "', user_from = '" . usercp_sql_value($location) . "', user_from_flag = '" . usercp_sql_value($user_flag) . "', user_interests = '" . usercp_sql_value($interests) . "', user_absence_mode = $user_absence_mode, user_absence = $user_absence, user_absence_text = '" . usercp_sql_value($user_absence_text) . "', user_birthday = '$birthday', user_next_birthday_greeting = '$next_birthday_greeting', user_viewemail = $viewemail, user_aim = '" . usercp_sql_value(str_replace(' ', '+', $aim)) . "', user_yim = '" . usercp_sql_value($yim) . "', user_msnm = '" . usercp_sql_value($msn) . "', user_fb = '" . usercp_sql_value($fb) . "', user_ig = '" . usercp_sql_value($ig) . "', user_pt = '" . usercp_sql_value($pt) . "', user_twr = '" . usercp_sql_value($twr) . "', user_skp = '" . usercp_sql_value($skp) . "', user_tg = '" . usercp_sql_value($tg) . "', user_li = '" . usercp_sql_value($li) . "', user_tt = '" . usercp_sql_value($tt) . "', user_dc = '" . usercp_sql_value($dc) . "', user_signal = '" . usercp_sql_value($signal) . "', user_threema = '" . usercp_sql_value($threema) . "', user_attachsig = $attachsig, user_setbm = $setbm, user_allowsmile = $allowsmilies, user_allowhtml = $allowhtml, user_allowbbcode = $allowbbcode, user_allow_viewonline = $allowviewonline, user_notify = $notifyreply, user_notify_pm = $notifypm, games_block_pm = $games_block_pm, user_popup_pm = $popup_pm, user_timezone = $user_timezone, user_dateformat = '" . usercp_sql_value($user_dateformat) . "', user_lang = '" . usercp_sql_value($user_lang) . "', user_style = $user_style, user_active = $user_active, user_actkey = '" . usercp_sql_value($user_actkey) . "'" . $avatar_sql . ", user_gender = '" . usercp_sql_value($gender) . "'
				WHERE user_id = $user_id";
			if ( !($result = $db->sql_query($sql)) )
			{
				message_die(GENERAL_ERROR, 'Could not update users table', '', __LINE__, __FILE__, $sql);
			}
			if ( !empty($passwd_sql) )
			{
				$profile_security->pw_create_date($user_id);
			}
			if ($username_sql !== '')
			{
				phpbb_sync_username_references($user_id, $userdata['username'], $username);
			}

			// We remove all stored login keys since the password has been updated
			// and change the current one (if applicable)
			if ( !empty($passwd_sql) )
			{
				session_reset_keys($user_id, $user_ip);
			}

			//
			// Custom Profile Fields MOD
			//
			  if (empty($profile_data))
			  	$profile_data = get_fields('WHERE users_can_view = '.ALLOW_VIEW);
			  $profile_names = array();
			  $profile_assignments = phpbb_profile_field_assignments($profile_data, $HTTP_POST_VARS, $profile_names);
			 if ( !empty($profile_assignments) )
			 {
			  $sql2 = "UPDATE " . USERS_TABLE . "
				  SET " . implode(', ', $profile_assignments) . "
				WHERE user_id = " . (int) $userdata['user_id'];
			  
			  if(!$db->sql_query($sql2))
					message_die(GENERAL_ERROR,'Could not update custom profile fields','',__LINE__,__FILE__,$sql2);
			 }
			//
			// END Custom Profile Fields MOD
			//

			if ( !$user_active )
			{
				//
				// The users account has been deactivated, send them an email with a new activation key
				//
				include($phpbb_root_path . 'includes/emailer.'.$phpEx);
				$emailer = new emailer($board_config['smtp_delivery']);

 				if ( $board_config['require_activation'] != USER_ACTIVATION_ADMIN )
 				{
 					$emailer->from($board_config['board_email']);
 					$emailer->replyto($board_config['board_email']);
 
 					$emailer->use_template('user_activate', stripslashes($user_lang));
 					$emailer->email_address($email);
 					$emailer->set_subject($lang['Reactivate']);
  
 					$emailer->assign_vars(array(
 						'SITENAME' => $board_config['sitename'],
 						'USERNAME' => preg_replace($unhtml_specialchars_match, $unhtml_specialchars_replace, substr(str_replace("\'", "'", $username), 0, 25)),
 						'EMAIL_SIG' => (!empty($board_config['board_email_sig'])) ? str_replace('<br />', "\n", "-- \n" . $board_config['board_email_sig']) : '',
  
 						'U_ACTIVATE' => $server_url . '?mode=activate&' . POST_USERS_URL . '=' . $user_id . '&act_key=' . $user_actkey)
 					);
 					$emailer->send();
 					$emailer->reset();
 				}
 				else if ( $board_config['require_activation'] == USER_ACTIVATION_ADMIN )
 				{
 					$sql = 'SELECT user_email, user_lang 
 						FROM ' . USERS_TABLE . '
 						WHERE user_level = ' . ADMIN;
 					
 					if ( !($result = $db->sql_query($sql)) )
 					{
 						message_die(GENERAL_ERROR, 'Could not select Administrators', '', __LINE__, __FILE__, $sql);
 					}
 					
 					while ($row = $db->sql_fetchrow($result))
 					{
 						$emailer->from($board_config['board_email']);
 						$emailer->replyto($board_config['board_email']);
 						
 						$emailer->email_address(trim($row['user_email']));
 						$emailer->use_template("admin_activate", $row['user_lang']);
 						$emailer->set_subject($lang['Reactivate']);
 
 						$emailer->assign_vars(array(
 							'USERNAME' => preg_replace($unhtml_specialchars_match, $unhtml_specialchars_replace, substr(str_replace("\'", "'", $username), 0, 25)),
 							'EMAIL_SIG' => str_replace('<br />', "\n", "-- \n" . $board_config['board_email_sig']),
 
 							'U_ACTIVATE' => $server_url . '?mode=activate&' . POST_USERS_URL . '=' . $user_id . '&act_key=' . $user_actkey)
 						);
 						$emailer->send();
 						$emailer->reset();
 					}
 					$db->sql_freeresult($result);
 				}

				$message = $lang['Profile_updated_inactive'] . '<br /><br />' . sprintf($lang['Click_return_index'],  '<a href="' . append_sid("index.$phpEx") . '">', '</a>');
			}
			else
			{
				$message = $lang['Profile_updated'] . '<br /><br />' . sprintf($lang['Click_return_index'],  '<a href="' . append_sid("index.$phpEx") . '">', '</a>');
			}

			$template->assign_vars(array(
				"META" => '<meta http-equiv="refresh" content="5;url=' . append_sid("index.$phpEx") . '">')
			);

			message_die(GENERAL_MESSAGE, $message);
		}
		else
		{
			$sql = "SELECT MAX(user_id) AS total
				FROM " . USERS_TABLE;
			if ( !($result = $db->sql_query($sql)) )
			{
				message_die(GENERAL_ERROR, 'Could not obtain next user_id information', '', __LINE__, __FILE__, $sql);
			}

			if ( !($row = $db->sql_fetchrow($result)) )
			{
				message_die(GENERAL_ERROR, 'Could not obtain next user_id information', '', __LINE__, __FILE__, $sql);
			}
			$user_id = $row['total'] + 1;
			//
			// Get current date
			//
			$sql = "INSERT INTO " . USERS_TABLE . "	(user_id, username, user_regdate, user_password, user_email, user_icq, user_website, user_occ, user_from, user_from_flag, user_interests, user_absence_mode, user_absence, user_absence_text, user_sig, user_sig_bbcode_uid, user_avatar, user_avatar_type, user_viewemail, user_aim, user_yim, user_msnm, user_fb, user_ig, user_pt, user_twr, user_skp, user_tg, user_li, user_tt, user_dc, user_signal, user_threema, user_attachsig, user_setbm, user_allowsmile, user_allowhtml, user_allowbbcode, user_allow_viewonline, user_notify, user_notify_pm, games_block_pm, user_popup_pm, user_timezone, user_dateformat, user_lang, user_style, user_gender, user_level, user_allow_pm, user_birthday, user_next_birthday_greeting, user_passwd_change, user_active, user_actkey)
				VALUES ($user_id, '" . usercp_sql_value($username) . "', " . time() . ", '" . usercp_sql_value($new_password) . "', '" . usercp_sql_value($email) . "', '" . usercp_sql_value($icq) . "', '" . usercp_sql_value($website) . "', '" . usercp_sql_value($occupation) . "', '" . usercp_sql_value($location) . "', '" . usercp_sql_value($user_flag) . "', '" . usercp_sql_value($interests) . "', $user_absence_mode, $user_absence, '" . usercp_sql_value($user_absence_text) . "', '" . usercp_sql_value($signature) . "', '" . usercp_sql_value($signature_bbcode_uid) . "', $avatar_sql, $viewemail, '" . usercp_sql_value(str_replace(' ', '+', $aim)) . "', '" . usercp_sql_value($yim) . "', '" . usercp_sql_value($msn) . "', '" . usercp_sql_value($fb) . "', '" . usercp_sql_value($ig) . "', '" . usercp_sql_value($pt) . "', '" . usercp_sql_value($twr) . "', '" . usercp_sql_value($skp) . "', '" . usercp_sql_value($tg) . "', '" . usercp_sql_value($li) . "', '" . usercp_sql_value($tt) . "', '" . usercp_sql_value($dc) . "', '" . usercp_sql_value($signal) . "', '" . usercp_sql_value($threema) . "', $attachsig, $setbm, $allowsmilies, $allowhtml, $allowbbcode, $allowviewonline, $notifyreply, $notifypm, $games_block_pm, $popup_pm, $user_timezone, '" . usercp_sql_value($user_dateformat) . "', '" . usercp_sql_value($user_lang) . "', $user_style, '" . usercp_sql_value($gender) . "', 0, 1, '$birthday', '$next_birthday_greeting', ".time().",";
			if ( $board_config['require_activation'] == USER_ACTIVATION_SELF || $board_config['require_activation'] == USER_ACTIVATION_ADMIN || $coppa )
			{
				$user_actkey = gen_rand_string(true);
				$sql .= "0, '" . usercp_sql_value($user_actkey) . "')";
			}
			else
			{
				$sql .= "1, '')";
			}

			if ( !($result = $db->sql_query($sql, BEGIN_TRANSACTION)) )
			{
				message_die(GENERAL_ERROR, 'Could not insert data into users table', '', __LINE__, __FILE__, $sql);
			}

			// Registration IP 1.1.2 (adapted): trust only the address supplied by
			// the web server. Forwarding headers are user-controlled unless a
			// deployment has an explicitly configured trusted proxy.
			$registration_ip = isset($_SERVER['REMOTE_ADDR']) ? trim((string) $_SERVER['REMOTE_ADDR']) : '';
			if (filter_var($registration_ip, FILTER_VALIDATE_IP) === false)
			{
				$registration_ip = '';
			}
			$registration_ip_sql = usercp_sql_value(substr($registration_ip, 0, 45));
			$sql = "UPDATE " . USERS_TABLE . " SET user_reg_ip = '$registration_ip_sql' WHERE user_id = $user_id";
			if (!$db->sql_query($sql))
			{
				message_die(GENERAL_ERROR, 'Could not store registration IP', '', __LINE__, __FILE__, $sql);
			}

			// BEGIN CrackerTracker v5.x
			($mode == 'register')? $profile_security->pw_create_date($user_id) : null;
			// END CrackerTracker v5.x

			$sql = "INSERT INTO " . GROUPS_TABLE . " (group_name, group_description, group_single_user, group_moderator)
				VALUES ('', 'Personal User', 1, 0)";
			if ( !($result = $db->sql_query($sql)) )
			{
				message_die(GENERAL_ERROR, 'Could not insert data into groups table', '', __LINE__, __FILE__, $sql);
			}

			$group_id = $db->sql_nextid();

			$sql = "INSERT INTO " . USER_GROUP_TABLE . " (user_id, group_id, user_pending)
				VALUES ($user_id, $group_id, 0)";
			if( !($result = $db->sql_query($sql, END_TRANSACTION)) )
			{
				message_die(GENERAL_ERROR, 'Could not insert data into user_group table', '', __LINE__, __FILE__, $sql);
			}

			//
			// Custom Profile Fields MOD
			//
			  if (empty($profile_data))
			  	$profile_data = get_fields('WHERE users_can_view = '.ALLOW_VIEW);
			  $profile_names = array();
			  $profile_assignments = phpbb_profile_field_assignments($profile_data, $HTTP_POST_VARS, $profile_names);
			 if ( !empty($profile_assignments) )
			 {
			  $sql2 = "UPDATE " . USERS_TABLE . "
				  SET " . implode(', ', $profile_assignments) . "
				WHERE user_id = " . (int) $user_id;
			  
			  if(!$db->sql_query($sql2))
					message_die(GENERAL_ERROR,'Could not insert(update) custom profile fields','',__LINE__,__FILE__,$sql2);
			 }
			//
			// END Custom Profile Fields MOD
			//

			if ( $coppa )
			{
				$message = $lang['COPPA'];
				$email_template = 'coppa_welcome_inactive';
			}
			else if ( $board_config['require_activation'] == USER_ACTIVATION_SELF )
			{
				$message = $lang['Account_inactive'];
				$email_template = 'user_welcome_inactive';
			}
			else if ( $board_config['require_activation'] == USER_ACTIVATION_ADMIN )
			{
				$message = $lang['Account_inactive_admin'];
				$email_template = 'admin_welcome_inactive';
			}
			else
			{
				$message = $lang['Account_added'];
				$email_template = 'user_welcome';
			}

			include($phpbb_root_path . 'includes/emailer.'.$phpEx);
			$emailer = new emailer($board_config['smtp_delivery']);

			$emailer->from($board_config['board_email']);
			$emailer->replyto($board_config['board_email']);

			$emailer->use_template($email_template, stripslashes($user_lang));
			$emailer->email_address($email);
			$emailer->set_subject(sprintf($lang['Welcome_subject'], $board_config['sitename']));

			if( $coppa )
			{
				$emailer->assign_vars(array(
					'SITENAME' => $board_config['sitename'],
					'WELCOME_MSG' => sprintf($lang['Welcome_subject'], $board_config['sitename']),
					'USERNAME' => preg_replace($unhtml_specialchars_match, $unhtml_specialchars_replace, substr(str_replace("\'", "'", $username), 0, 25)),
					'PASSWORD' => $password_confirm,
					'EMAIL_SIG' => str_replace('<br />', "\n", "-- \n" . $board_config['board_email_sig']),

					'FAX_INFO' => $board_config['coppa_fax'],
					'MAIL_INFO' => $board_config['coppa_mail'],
					'EMAIL_ADDRESS' => $email,
					'ICQ' => $icq,
					'AIM' => $aim,
					'YIM' => $yim,
					'MSN' => $msn,
					'WEB_SITE' => $website,
					'FROM' => $location,
					'OCC' => $occupation,
					'INTERESTS' => $interests,
					'SITENAME' => $board_config['sitename']));
			}
			else
			{
				$emailer->assign_vars(array(
					'SITENAME' => $board_config['sitename'],
					'WELCOME_MSG' => sprintf($lang['Welcome_subject'], $board_config['sitename']),
					'USERNAME' => preg_replace($unhtml_specialchars_match, $unhtml_specialchars_replace, substr(str_replace("\'", "'", $username), 0, 25)),
					'PASSWORD' => $password_confirm,
					'EMAIL_SIG' => str_replace('<br />', "\n", "-- \n" . $board_config['board_email_sig']),

					'U_ACTIVATE' => $server_url . '?mode=activate&' . POST_USERS_URL . '=' . $user_id . '&act_key=' . $user_actkey)
				);
			}

			$emailer->send();
			$emailer->reset();

			if ( $board_config['require_activation'] == USER_ACTIVATION_ADMIN )
			{
				$sql = "SELECT user_email, user_lang 
					FROM " . USERS_TABLE . "
					WHERE user_level = " . ADMIN;
				
				if ( !($result = $db->sql_query($sql)) )
				{
					message_die(GENERAL_ERROR, 'Could not select Administrators', '', __LINE__, __FILE__, $sql);
				}
				
				while ($row = $db->sql_fetchrow($result))
				{
					$emailer->from($board_config['board_email']);
					$emailer->replyto($board_config['board_email']);
					
					$emailer->email_address(trim($row['user_email']));
					$emailer->use_template("admin_activate", $row['user_lang']);
					$emailer->set_subject($lang['New_account_subject']);

					$emailer->assign_vars(array(
						'USERNAME' => preg_replace($unhtml_specialchars_match, $unhtml_specialchars_replace, substr(str_replace("\'", "'", $username), 0, 25)),
						'EMAIL_SIG' => str_replace('<br />', "\n", "-- \n" . $board_config['board_email_sig']),

						'U_ACTIVATE' => $server_url . '?mode=activate&' . POST_USERS_URL . '=' . $user_id . '&act_key=' . $user_actkey)
					);
					$emailer->send();
					$emailer->reset();
				}
				$db->sql_freeresult($result);
			}

			// Start the per-IP registration cooldown only after the account,
			// personal group, profile fields and notification mail were handled.
			$profile_security->reg_done();

			$message = $message . '<br /><br />' . sprintf($lang['Click_return_index'],  '<a href="' . append_sid("index.$phpEx") . '">', '</a>');

			message_die(GENERAL_MESSAGE, $message);
		} // if mode == register
	}
} // End of submit


if ( $error )
{
	//
	// If an error occured we need to stripslashes on returned data
	//
	$username = stripslashes($username);
	$email = stripslashes($email);
	$cur_password = '';
	$new_password = '';
	$password_confirm = '';

	$icq = stripslashes($icq);
	$aim = str_replace('+', ' ', stripslashes($aim));
	$msn = stripslashes($msn);
	$yim = stripslashes($yim);
	$fb = stripslashes($fb);
	$ig = stripslashes($ig);
	$pt = stripslashes($pt);
	$twr = stripslashes($twr);
	$skp = stripslashes($skp);
	$tg = stripslashes($tg);
	$li = stripslashes($li);
	$tt = stripslashes($tt);
	$dc = stripslashes($dc);

	$website = stripslashes($website);
	$location = stripslashes($location);
	$occupation = stripslashes($occupation);
	$interests = stripslashes($interests);
	$user_absence_text = stripslashes($user_absence_text);
	$signature = stripslashes($signature);
	$signature = ($signature_bbcode_uid != '') ? preg_replace("/:(([a-z0-9]+:)?)$signature_bbcode_uid(=|\])/si", '\\3', $signature) : $signature;

	$user_lang = stripslashes($user_lang);
	$user_dateformat = stripslashes($user_dateformat);

}
else if ( $mode == 'editprofile' && !isset($_POST['avatargallery']) && !isset($_POST['submitavatar']) && !isset($_POST['cancelavatar']) && !isset($_GET['second']) )
{
	$user_id = $userdata['user_id'];
	$username = $userdata['username'];
	$email = $userdata['user_email'];
	$cur_password = '';
	$new_password = '';
	$password_confirm = '';

	$icq = $userdata['user_icq'];
	$aim = str_replace('+', ' ', $userdata['user_aim']);
	$msn = $userdata['user_msnm'];
	$yim = $userdata['user_yim'];
	$fb = isset($userdata['user_fb']) ? $userdata['user_fb'] : '';
	$ig = isset($userdata['user_ig']) ? $userdata['user_ig'] : '';
	$pt = isset($userdata['user_pt']) ? $userdata['user_pt'] : '';
	$twr = isset($userdata['user_twr']) ? $userdata['user_twr'] : '';
	$skp = isset($userdata['user_skp']) ? $userdata['user_skp'] : '';
	$tg = isset($userdata['user_tg']) ? $userdata['user_tg'] : '';
	$li = isset($userdata['user_li']) ? $userdata['user_li'] : '';
	$tt = isset($userdata['user_tt']) ? $userdata['user_tt'] : '';
	$dc = isset($userdata['user_dc']) ? $userdata['user_dc'] : '';
	$signal = isset($userdata['user_signal']) ? $userdata['user_signal'] : '';
	$threema = isset($userdata['user_threema']) ? $userdata['user_threema'] : '';

	$website = $userdata['user_website'];
	$location = $userdata['user_from'];
	// FLAGHACK-start
	$user_flag = $userdata['user_from_flag'];	
	// FLAGHACK-end
	$occupation = $userdata['user_occ'];
	$interests = $userdata['user_interests'];
	// Start add - Gender MOD
	$gender = $userdata['user_gender']; 
	// End add - Gender MOD
	// Start add - Birthday MOD
	$birthday = $userdata['user_birthday'];
	// End add - Birthday MOD
	$signature_bbcode_uid = $userdata['user_sig_bbcode_uid'];
	$signature = ($signature_bbcode_uid != '') ? preg_replace("/:(([a-z0-9]+:)?)$signature_bbcode_uid(=|\])/si", '\\3', $userdata['user_sig']) : $userdata['user_sig'];

	$viewemail = $userdata['user_viewemail'];
	$user_absence_mode = $userdata['user_absence_mode'];
	$user_absence = $userdata['user_absence'];
	$user_absence_text = $userdata['user_absence_text'];
	$notifypm = $userdata['user_notify_pm'];
	$games_block_pm = $userdata['games_block_pm'];
	$popup_pm = $userdata['user_popup_pm'];
	$notifyreply = $userdata['user_notify'];
	$attachsig = $userdata['user_attachsig'];
	$setbm = $userdata['user_setbm'];
	$allowhtml = $userdata['user_allowhtml'];
	$allowbbcode = $userdata['user_allowbbcode'];
	$allowsmilies = $userdata['user_allowsmile'];
	$allowviewonline = $userdata['user_allow_viewonline'];

	$user_avatar = ( $userdata['user_allowavatar'] ) ? $userdata['user_avatar'] : '';
	$user_avatar_type = ( $userdata['user_allowavatar'] ) ? $userdata['user_avatar_type'] : USER_AVATAR_NONE;

	$user_style = $userdata['user_style'];
	$user_lang = $userdata['user_lang'];
	$user_timezone = $userdata['user_timezone'];
	$user_dateformat = $userdata['user_dateformat'];
}
else if ( $mode == 'editprofile' && !isset($_POST['avatargallery']) && !isset($_POST['submitavatar']) && !isset($_POST['cancelavatar']) && isset($_GET['second']) )
{
	$strip_var_list = array('user_id' => 'user_id', 'username' => 'username', 'email' => 'email', 'icq' => 'icq', 'aim' => 'aim', 'msn' => 'msn', 'yim' => 'yim', 'website' => 'website', 'location' => 'location', 'occupation' => 'occupation', 'interests' => 'interests');
	foreach ($strip_var_list as $var => $param)
	{
		if ( usercp_post_scalar($param) !== '' )
		{
			$$var = trim(htmlspecialchars(usercp_post_scalar($param)));
		}
	}

	$password_var_list = array('cur_password', 'new_password', 'password_confirm');
	foreach ($password_var_list as $password_var)
	{
		$$password_var = usercp_post_scalar($password_var);
	}
	$signature = trim(usercp_post_scalar('signature'));

	$user_absence = ( isset($_POST['user_absence']) ) ? ( ($_POST['user_absence']) ? TRUE : 0 ) : 0;
	$user_absence_mode = abs(intval(usercp_post_scalar('user_absence_mode', '0')));
	$user_absence_text = htmlspecialchars(usercp_post_scalar('user_absence_text'));
	$gender = intval(usercp_post_scalar('gender', '0'));
	$birthday = intval(usercp_post_scalar('birthday', '0'));
	$b_day = intval(usercp_post_scalar('b_day', '0'));
	$b_md = intval(usercp_post_scalar('b_md', '0'));
	$b_year = intval(usercp_post_scalar('b_year', '0'));
	$viewemail = ( isset($_POST['viewemail']) ) ? ( ($_POST['viewemail']) ? TRUE : 0 ) : 0;
	$allowviewonline = ( isset($_POST['hideonline']) ) ? ( ($_POST['hideonline']) ? 0 : TRUE ) : TRUE;
	$notifyreply = ( isset($_POST['notifyreply']) ) ? ( ($_POST['notifyreply']) ? TRUE : 0 ) : 0;
	$notifypm = ( isset($_POST['notifypm']) ) ? ( ($_POST['notifypm']) ? TRUE : 0 ) : TRUE;
	$games_block_pm = ( isset($_POST['games_block_pm']) ) ? ( ($_POST['games_block_pm']) ? TRUE : 0 ) : TRUE;
	$popup_pm = ( isset($_POST['popup_pm']) ) ? ( ($_POST['popup_pm']) ? TRUE : 0 ) : TRUE;
	$sid = usercp_post_scalar('sid');
	$attachsig = ( isset($_POST['attachsig']) ) ? ( ($_POST['attachsig']) ? TRUE : 0 ) : 0;
	$setbm = ( isset($_POST['setbm']) ) ? ( ($_POST['setbm']) ? TRUE : 0 ) : 0;
	$allowbbcode = ( isset($_POST['allowbbcode']) ) ? ( ($_POST['allowbbcode']) ? TRUE : 0 ) : $userdata['user_allowbbcode'];
	$allowhtml = ( isset($_POST['allowhtml']) ) ? ( ($_POST['allowhtml']) ? TRUE : 0 ) : $userdata['user_allowhtml'];
	$allowsmilies = ( isset($_POST['allowsmilies']) ) ? ( ($_POST['allowsmilies']) ? TRUE : 0 ) : $userdata['user_allowsmile'];
	$user_lang = htmlspecialchars(usercp_installed_language(usercp_post_scalar('language', $board_config['default_lang']), $board_config['default_lang']));
	$user_style = usercp_installed_style(usercp_post_scalar('style', (string) $board_config['default_style']), $board_config['default_style']);
	$user_timezone = usercp_timezone(usercp_post_scalar('timezone', (string) $board_config['board_timezone']), $board_config['board_timezone']);
	$dateformat_value = usercp_post_scalar('dateformat');
	$user_dateformat = htmlspecialchars(usercp_dateformat($dateformat_value, $board_config['default_dateformat']));
	$user_avatar_name = usercp_avatar_file_scalar('name');
	$user_avatar_size = intval(usercp_avatar_file_scalar('size', '0'));
	$user_avatar_filetype = usercp_avatar_file_scalar('type');
	$avatarurl_value = usercp_post_scalar('avatarurl');
	$avatarremoteurl_value = usercp_post_scalar('avatarremoteurl');
	$avatar_tmp_name = usercp_avatar_file_scalar('tmp_name');
	$user_avatar_upload = ( $avatarurl_value !== '' ) ? trim($avatarurl_value) : ( ( $avatar_tmp_name !== '' && $avatar_tmp_name != 'none') ? $avatar_tmp_name : '' );
	$user_avatar_remoteurl = ( $avatarremoteurl_value !== '' ) ? trim(htmlspecialchars($avatarremoteurl_value)) : '';
	$user_flag_value = usercp_post_scalar('user_flag');
	$user_flag = ( $user_flag_value !== '' ) ? phpbb_profile_image_name($user_flag_value) : '' ;
}

//
// Default pages
//
include($phpbb_root_path . 'includes/page_header.'.$phpEx);

make_jumpbox('viewforum.'.$phpEx);

if ( $mode == 'editprofile' )
{
	if ( $user_id != $userdata['user_id'] )
	{
		$error = TRUE;
		$error_msg = $lang['Wrong_Profile'];
	}
}

if( isset($_POST['avatargallery']) && !$error )
{
	include($phpbb_root_path . 'includes/usercp_avatar.'.$phpEx);

	$avatar_category_value = usercp_post_scalar('avatarcategory');
	$avatar_category = ( $avatar_category_value !== '' ) ? htmlspecialchars($avatar_category_value) : '';

	$template->set_filenames(array(
		'body' => 'profile_avatar_gallery.tpl')
	);

	$allowviewonline = !$allowviewonline;

	display_avatar_gallery($mode, $avatar_category, $user_id, $email, $current_email, $coppa, $username, $new_password, $cur_password, $password_confirm, $icq, $aim, $msn, $yim, $fb, $ig, $pt, $twr, $skp, $tg, $li, $tt, $dc, $website, $location, $user_flag, $occupation, $interests, $signature, $viewemail, $notifypm, $games_block_pm, $popup_pm, $notifyreply, $attachsig, $setbm, $allowhtml, $allowbbcode, $allowsmilies, $allowviewonline, $user_style, $user_lang, $user_timezone, $user_dateformat, $user_absence_mode, $user_absence, $user_absence_text, $userdata['session_id'], $birthday, $gender);
}
else
{
	include($phpbb_root_path . 'includes/functions_selects.'.$phpEx);

	if ( !isset($coppa) )
	{
		$coppa = FALSE;
	}

	if ( !isset($user_style) )
	{
		$user_style = $board_config['default_style'];
	}

	$avatar_img = '';
	if ( $user_avatar_type )
	{
		switch( $user_avatar_type )
		{
			case USER_AVATAR_UPLOAD:
				$avatar_img = ( $board_config['allow_avatar_upload'] ) ? '<img src="' . $board_config['avatar_path'] . '/' . $user_avatar . '" alt="" />' : '';
				break;
			case USER_AVATAR_REMOTE:
				$avatar_img = ( $board_config['allow_avatar_remote'] ) ? '<img src="' . htmlspecialchars($user_avatar, ENT_QUOTES, 'UTF-8') . '" alt="" />' : '';
				break;
			case USER_AVATAR_GALLERY:
				$avatar_img = ( $board_config['allow_avatar_local'] ) ? '<img src="' . $board_config['avatar_gallery_path'] . '/' . $user_avatar . '" alt="" />' : '';
				break;
		}
	}

	$s_hidden_fields = '<input type="hidden" name="mode" value="' . $mode . '" /><input type="hidden" name="agreed" value="true" /><input type="hidden" name="coppa" value="' . $coppa . '" />';
	$s_hidden_fields .= '<input type="hidden" name="sid" value="' . $userdata['session_id'] . '" />';
	if( $mode == 'editprofile' )
	{
		$s_hidden_fields .= '<input type="hidden" name="user_id" value="' . $userdata['user_id'] . '" />';
		//
		// Send the users current email address. If they change it, and account activation is turned on
		// the user account will be disabled and the user will have to reactivate their account.
		//
		$s_hidden_fields .= '<input type="hidden" name="current_email" value="' . $userdata['user_email'] . '" />';
	}

	if ( !empty($user_avatar_local) )
	{
		$s_hidden_fields .= '<input type="hidden" name="avatarlocal" value="' . $user_avatar_local . '" /><input type="hidden" name="avatarcatname" value="' . $user_avatar_category . '" />';
	}

	$html_status =  ( $userdata['user_allowhtml'] && $board_config['allow_html'] ) ? $lang['HTML_is_ON'] : $lang['HTML_is_OFF'];
	$bbcode_status = ( $userdata['user_allowbbcode'] && $board_config['allow_bbcode']  ) ? $lang['BBCode_is_ON'] : $lang['BBCode_is_OFF'];
	$smilies_status = ( $userdata['user_allowsmile'] && $board_config['allow_smilies']  ) ? $lang['Smilies_are_ON'] : $lang['Smilies_are_OFF'];
	
	// Start add - Gender MOD
	$gender_no_specify_checked = '';
	$gender_male_checked = '';
	$gender_female_checked = '';
	switch ($gender) 
	{ 
	   case 1: $gender_male_checked="checked=\"checked\"";break; 
	   case 2: $gender_female_checked="checked=\"checked\"";break; 
	   default:$gender_no_specify_checked="checked=\"checked\""; 
	}
	// End add - Gender MOD
	// Start add - Birthday MOD
	if ( $birthday!=999999 )
	{
		$b_day = realdate('j', $birthday);
		$b_md = realdate('n', $birthday);
		$b_year = realdate('Y', $birthday);
		$birthday = realdate($lang['Submit_date_format'], $birthday);
	} else
	{
		$b_day = '';
		$b_md = '';
		$b_year = '';
		$birthday = '';
	}
	// End add - Birthday MOD

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

	$template->set_filenames(array(
		'body' => 'profile_add_body.tpl')
	);
	  //
	  // Custom Profile Fields MOD
	  //
	  if (empty($profile_data))
	  	$profile_data = get_fields('WHERE users_can_view = '.ALLOW_VIEW);
	  
	  if(count($profile_data) > 0)
		$template->assign_block_vars('switch_custom_fields',array(
		  'L_CUSTOM_FIELD_NOTICE' => $lang['custom_field_notice']
		  ));
	  
	  foreach($profile_data as $field)
	  {
		$field_name = $field['field_name'];
		$name = phpbb_profile_field_column($field);
		if ($name === '')
		{
			continue;
		}
		$safe_field_name = phpbb_profile_text($field_name);
		$safe_name = phpbb_profile_text($name);
		
		$required = ($field['is_required'] == REQUIRED) ? ' *' : '';
		
		switch($field['field_type'])
		{
		  case TEXT_FIELD:
			$posted_value = isset($HTTP_POST_VARS[$name]) && is_scalar($HTTP_POST_VARS[$name]) ? (string) $HTTP_POST_VARS[$name] : '';
			$value = ($posted_value === '') ? $userdata[$name] : stripslashes($posted_value);
			$length = $field['text_field_maxlen'];
			$safe_value = phpbb_profile_text($value);
			$field_html_code = "<input type=\"text\" class=\"post\" style=\"width: 200px\" name=\"$safe_name\" size=\"35\" maxlength=\"$length\" value=\"$safe_value\" />";
			break;
		  case TEXTAREA:
			$posted_value = isset($HTTP_POST_VARS[$name]) && is_scalar($HTTP_POST_VARS[$name]) ? (string) $HTTP_POST_VARS[$name] : '';
			$value = ($posted_value === '') ? $userdata[$name] : stripslashes($posted_value);
			$safe_value = phpbb_profile_text($value);
			$field_html_code = "<textarea name=\"$safe_name\" style=\"width: 300px\" rows=\"6\" cols=\"30\" class=\"post\">$safe_value</textarea>";
			break;
		  case RADIO:
			$posted_value = isset($HTTP_POST_VARS[$name]) && is_scalar($HTTP_POST_VARS[$name]) ? (string) $HTTP_POST_VARS[$name] : '';
			$value = ($posted_value === '') ? $userdata[$name] : stripslashes($posted_value);
			$radio_list = explode(',',$field['radio_button_values']);
			$html_list = array();
			foreach($radio_list as $num => $radio_name)
			{
			  $safe_radio_name = phpbb_profile_text($radio_name);
			  $temp = "<input type=\"radio\" name=\"$safe_name\" value=\"$safe_radio_name\"";
			  if($radio_name == $value)
				$temp .= ' checked="checked"';
			  $temp .= " /> <span class=\"gen\">$safe_radio_name</span>";
			  if($num < count($radio_list))
				$temp .= '<br />';
			  $html_list[] = $temp;
			}
			$field_html_code = '';
			foreach($html_list as $line)
			  $field_html_code .= $line . "\n";
			break;
		  case CHECKBOX:
			$posted_values = phpbb_profile_field_input($field, $HTTP_POST_VARS);
			$value_array = ($posted_values === '') ? explode(',', $userdata[$name]) : explode(',', $posted_values);
			$check_list = explode(',',$field['checkbox_values']);
			$html_list = array();
			foreach($check_list as $num => $check_name)
			{
			  $safe_check_name = phpbb_profile_text($check_name);
			  $temp = "<input type=\"checkbox\" name=\"{$safe_name}[]\" value=\"$safe_check_name\"";
			  foreach($value_array as $val)
				if($val == $check_name)
				{
				  $temp .= ' checked="checked"';
				  break;
				}
			  $temp .= " /> <span class=\"gen\">$safe_check_name</span>";
			  if($num < count($check_list))
				$temp .= '<br />';
			  $html_list[] = $temp;
			}
			$field_html_code = '';
			foreach($html_list as $line)
			  $field_html_code .= $line . "\n";
			break;
		}
		
		$template->assign_block_vars('custom_fields',array(
		  'NAME' => $safe_field_name,
		  'FIELD' => $field_html_code,
		  'REQUIRED' => $required)
		  );
		
		if($field['field_description'] != NULL && !empty($field['field_description']))
		  $template->assign_block_vars('custom_fields.switch_description',array(
			'DESCRIPTION' => phpbb_profile_text($field['field_description'])));
	  }
		//
	  // END Custom Profile Fields MOD
	  //

	if ( ($userdata['user_level'] == USER && $board_config['users_allow_absence'] == TRUE) || ($userdata['user_level'] != USER && $userdata['user_level'] != ANONYMOUS) )
	{
		$template->assign_block_vars('allow_absence', array());
	}

	$s_user_absence_mode = '<select name = "user_absence_mode">';
	$s_user_absence_mode .= '<option value = "1">' . $lang['On_holidays'] . '</option>';
	$s_user_absence_mode .= '<option value = "2">' . $lang['User_ill'] . '</option>';
	$s_user_absence_mode .= '<option value = "3">' . $lang['Longer_absenct'] . '</option>';
	$s_user_absence_mode .= '</select>';

	$s_user_absence_mode = str_replace('value = "' . $userdata['user_absence_mode'] . '">', 'value = "' . $userdata['user_absence_mode'] . '" SELECTED>' ,$s_user_absence_mode);
	if ( $mode == 'editprofile' )
	{
		$template->assign_block_vars('switch_edit_profile', array());
	}
	// FLAGHACK-start
	// query to get the list of flags
	$sql = "SELECT *
		FROM " . FLAG_TABLE . "
		ORDER BY flag_id";
	if(!$flags_result = $db->sql_query($sql))
	{
		message_die(GENERAL_ERROR, "Couldn't obtain flags information.", "", __LINE__, __FILE__, $sql);
	}
	$flag_row = $db->sql_fetchrowset($flags_result);
	$num_flags = $db->sql_numrows($flags_result) ;

	// build the html select statement
	$flag_start_image = 'blank.gif' ;
	$selected = ( isset($user_flag) ) ? '' : ' selected="selected"'  ;
	$flag_select = "<select name=\"user_flag\" onChange=\"document.images['user_flag'].src = 'images/flags/'
 + this.value;\" >";
	$flag_select .= "<option value=\"blank.gif\"$selected>" . $lang['Select_Country'] . "</option>";
	for ($i = 0; $i < $num_flags; $i++)
	{
		$flag_name = phpbb_profile_text($flag_row[$i]['flag_name']);
		$flag_image = phpbb_profile_image_name($flag_row[$i]['flag_image']);
		if ($flag_image === '')
		{
			continue;
		}
		$selected = ( isset( $user_flag) ) ? (($user_flag == $flag_image) ? 'selected="selected"' : '' ) : '' ;
		$flag_select .= "\t<option value=\"$flag_image\"$selected>$flag_name</option>";
		if ( isset( $user_flag) && ($user_flag == $flag_image))
		{
			$flag_start_image = $flag_image ;
		}
	}
	$flag_select .= '</select>';
	// FLAGHACK-end
	if ( ($mode == 'register') || ($board_config['allow_namechange']) )
	{
		$template->assign_block_vars('switch_namechange_allowed', array());
	}
	else
	{
		$template->assign_block_vars('switch_namechange_disallowed', array());
	}
	// Start add - Birthday MOD
	$s_b_day = '<span class="genmed">' . $lang['Day'] . '&nbsp;</span><select name="b_day" size="1" class="gensmall"> 
		<option value="0">&nbsp;-&nbsp;</option> 
		<option value="1">&nbsp;1&nbsp;</option>
		<option value="2">&nbsp;2&nbsp;</option>
		<option value="3">&nbsp;3&nbsp;</option>
		<option value="4">&nbsp;4&nbsp;</option>
		<option value="5">&nbsp;5&nbsp;</option>
		<option value="6">&nbsp;6&nbsp;</option>
		<option value="7">&nbsp;7&nbsp;</option>
		<option value="8">&nbsp;8&nbsp;</option>
		<option value="9">&nbsp;9&nbsp;</option>
		<option value="10">&nbsp;10&nbsp;</option>
		<option value="11">&nbsp;11&nbsp;</option>
		<option value="12">&nbsp;12&nbsp;</option>
		<option value="13">&nbsp;13&nbsp;</option>
		<option value="14">&nbsp;14&nbsp;</option>
		<option value="15">&nbsp;15&nbsp;</option>
		<option value="16">&nbsp;16&nbsp;</option>
		<option value="17">&nbsp;17&nbsp;</option>
		<option value="18">&nbsp;18&nbsp;</option>
		<option value="19">&nbsp;19&nbsp;</option>
		<option value="20">&nbsp;20&nbsp;</option>
		<option value="21">&nbsp;21&nbsp;</option>
		<option value="22">&nbsp;22&nbsp;</option>
		<option value="23">&nbsp;23&nbsp;</option>
		<option value="24">&nbsp;24&nbsp;</option>
		<option value="25">&nbsp;25&nbsp;</option>
		<option value="26">&nbsp;26&nbsp;</option>
		<option value="27">&nbsp;27&nbsp;</option>
		<option value="28">&nbsp;28&nbsp;</option>
		<option value="29">&nbsp;29&nbsp;</option>
		<option value="30">&nbsp;30&nbsp;</option>
		<option value="31">&nbsp;31&nbsp;</option>
	  	</select>&nbsp;&nbsp;';
	$s_b_md = '<span class="genmed">' . $lang['Month'] . '&nbsp;</span><select name="b_md" size="1" class="gensmall"> 
     		<option value="0">&nbsp;-&nbsp;</option> 
		<option value="1">&nbsp;'.$lang['datetime']['January'].'&nbsp;</option>
		<option value="2">&nbsp;'.$lang['datetime']['February'].'&nbsp;</option>
		<option value="3">&nbsp;'.$lang['datetime']['March'].'&nbsp;</option>
		<option value="4">&nbsp;'.$lang['datetime']['April'].'&nbsp;</option>
		<option value="5">&nbsp;'.$lang['datetime']['May'].'&nbsp;</option>
		<option value="6">&nbsp;'.$lang['datetime']['June'].'&nbsp;</option>
		<option value="7">&nbsp;'.$lang['datetime']['July'].'&nbsp;</option>
		<option value="8">&nbsp;'.$lang['datetime']['August'].'&nbsp;</option>
		<option value="9">&nbsp;'.$lang['datetime']['September'].'&nbsp;</option>
		<option value="10">&nbsp;'.$lang['datetime']['October'].'&nbsp;</option>
		<option value="11">&nbsp;'.$lang['datetime']['November'].'&nbsp;</option>
		<option value="12">&nbsp;'.$lang['datetime']['December'].'&nbsp;</option>
		</select>&nbsp;&nbsp;';
	$s_b_day= str_replace("value=\"".$b_day."\">", "value=\"".$b_day."\" SELECTED>" ,$s_b_day);
	$s_b_md = str_replace("value=\"".$b_md."\">", "value=\"".$b_md."\" SELECTED>" ,$s_b_md);
	$s_b_year = '<span class="genmed">' . $lang['Year'] . '&nbsp;</span><input type="text" class="post" style="width: 50px" name="b_year" size="4" maxlength="4" value="' . $b_year . '" />&nbsp;&nbsp;'; 
	$i = 0;
	$s_birthday = '';
	for ($i=0; $i<strlen($lang['Submit_date_format']); $i++)
	{
		switch ($lang['Submit_date_format'][$i])
		{
			case 'd':  $s_birthday .= $s_b_day;break;
			case 'm':  $s_birthday .= $s_b_md;break;
			case 'Y':  $s_birthday .= $s_b_year;break;
		}
	}
// End add - Birthday MOD


	// Visual Confirmation
	$confirm_image = '';
	if (!empty($board_config['enable_confirm']) && $mode == 'register' && !$plus_config['enable_antirobot'])
	{
		$expiry_time = time() - $board_config['session_length'];
	
		$sql = 'SELECT session_id 
			FROM ' . SESSIONS_TABLE ." WHERE session_time>$expiry_time"; 
		if (!($result = $db->sql_query($sql)))
		{
			message_die(GENERAL_ERROR, 'Could not select session data', '', __LINE__, __FILE__, $sql);
		}

		if ($row = $db->sql_fetchrow($result))
		{
			$confirm_sql = '';
			do
			{
				$confirm_sql .= (($confirm_sql != '') ? ', ' : '') . "'" . $row['session_id'] . "'";
			}
			while ($row = $db->sql_fetchrow($result));
		
			$sql = 'DELETE FROM ' .  CONFIRM_TABLE . " 
				WHERE session_id NOT IN ($confirm_sql)";
			if (!$db->sql_query($sql))
			{
				message_die(GENERAL_ERROR, 'Could not delete stale confirm data', '', __LINE__, __FILE__, $sql);
			}
		}
		$db->sql_freeresult($result);

		$sql = 'SELECT COUNT(session_id) AS attempts 
			FROM ' . CONFIRM_TABLE . " 
			WHERE session_id = '" . $userdata['session_id'] . "'";
		if (!($result = $db->sql_query($sql)))
		{
			message_die(GENERAL_ERROR, 'Could not obtain confirm code count', '', __LINE__, __FILE__, $sql);
		}

		if ($row = $db->sql_fetchrow($result))
		{
			if ($row['attempts'] > 3)
			{
				message_die(GENERAL_MESSAGE, $lang['Too_many_registers']);
			}
		}
		$db->sql_freeresult($result);
		
		// Generate the required confirmation code
		// NB 0 (zero) could get confused with O (the letter) so we make change it
		$code = dss_rand();
		$code = substr(str_replace('0', 'Z', strtoupper(base_convert($code, 16, 35))), 2, 6);

		$confirm_id = md5(dss_rand() . dss_rand());

		$sql = 'INSERT INTO ' . CONFIRM_TABLE . " (confirm_id, session_id, code) 
			VALUES ('$confirm_id', '". $userdata['session_id'] . "', '$code')";
		if (!$db->sql_query($sql))
		{
			message_die(GENERAL_ERROR, 'Could not insert new confirm code information', '', __LINE__, __FILE__, $sql);
		}

		unset($code);
		
		$confirm_image = '<img src="' . append_sid("profile.$phpEx?mode=confirm&amp;id=$confirm_id") . '" alt="" title="" />';
		$s_hidden_fields .= '<input type="hidden" name="confirm_id" value="' . $confirm_id . '" />';

		$template->assign_block_vars('switch_confirm', array());
	}
	//
	// Let's do an overall check for settings/versions which would prevent
	// us from doing file uploads....
	//
	$ini_val = ( phpversion() >= '4.0.0' ) ? 'ini_get' : 'get_cfg_var';
	$form_enctype = ( @$ini_val('file_uploads') == '0' || strtolower(@$ini_val('file_uploads') == 'off') || phpversion() == '4.0.4pl1' || !$board_config['allow_avatar_upload'] || ( phpversion() < '4.0.3' && @$ini_val('open_basedir') != '' ) ) ? '' : 'enctype="multipart/form-data"';
	
	if ($plus_config['enable_antirobot'] == 1)
	{
	
	//
	// Anti Robotic Registration
	//
	if ($mode == 'register')
	{
		$sql = "DELETE FROM " . ANTI_ROBOT_TABLE . " WHERE timestamp < '" . (time() - 3600) . "' OR session_id = '" . $userdata['session_id'] . "'";
		if( !$result = $db->sql_query($sql) )
		{
				message_die(GENERAL_ERROR, 'Could not delete validation key', '', __LINE__, __FILE__, $sql);
		}

		$reg_key = gen_reg_key();

		$sql = "INSERT INTO ". ANTI_ROBOT_TABLE . " VALUES ('" . $userdata['session_id'] . "', '" . $reg_key . "', '" . time() . "')";
		if( !$result = $db->sql_query($sql) )
		{
				message_die(GENERAL_ERROR, 'Could not check registration information', '', __LINE__, __FILE__, $sql);
		}
	}
	//-----------------------
	//
	}
	$template->assign_vars(array(
		'USERNAME' => phpbb_profile_display_text(isset($username) ? $username : ''),
		'CUR_PASSWORD' => '',
		'NEW_PASSWORD' => '',
		'PASSWORD_CONFIRM' => '',
		'EMAIL' => phpbb_profile_display_text(isset($email) ? $email : ''),
		'CONFIRM_IMG' => $confirm_image, 
		//signature editor
		'SIG_DESC' => $lang['sig_description'],
		'SIG_BUTTON_DESC' => $lang['sig_edit'],
		//signature editor
		'YIM' => phpbb_profile_display_text($yim),
		'ICQ' => phpbb_profile_display_text($icq),
		'MSN' => phpbb_profile_display_text($msn),
		'AIM' => phpbb_profile_display_text($aim),
		'FB' => phpbb_profile_display_text($fb),
		'IG' => phpbb_profile_display_text($ig),
		'PT' => phpbb_profile_display_text($pt),
		'TWR' => phpbb_profile_display_text($twr),
		'SKP' => phpbb_profile_display_text($skp),
		'TG' => phpbb_profile_display_text($tg),
		'LI' => phpbb_profile_display_text($li),
		'TT' => phpbb_profile_display_text($tt),
		'DC' => phpbb_profile_display_text($dc),
		'SIGNAL' => phpbb_profile_display_text($signal),
		'THREEMA' => phpbb_profile_display_text($threema),
		'OCCUPATION' => phpbb_profile_display_text($occupation),
		'INTERESTS' => phpbb_profile_display_text($interests),
		'L_USER_ABSENCE' => $lang['User_absence'],
		'L_USER_ABSENCE_MODE' => $lang['User_absence_mode'],
		'L_USER_ABSENCE_TEXT' => $lang['User_absence_text'],
		'USER_ABSENCE_YES' => ($user_absence) ? 'checked="checked"' : '',
		'USER_ABSENCE_NO' => (!$user_absence) ? 'checked="checked"' : '',
		'S_USER_ABSENCE_MODE' => $s_user_absence_mode,
		'S_USER_ABSENCE_TEXT' => phpbb_profile_display_text($user_absence_text),
		// Start add - Birthday MOD
		'S_BIRTHDAY' => $s_birthday,
		'BIRTHDAY_REQUIRED' => ($board_config['birthday_required']) ? '*' : '',
		// End add - Birthday MOD 
		'LOCATION' => phpbb_profile_display_text($location),
		// FLAGHACK-start
		'L_FLAG' => $lang['Country_Flag'],
		'FLAG_SELECT' => $flag_select,
		'FLAG_START' => $flag_start_image,
		// FLAGHACK-end
		'WEBSITE' => phpbb_profile_display_text($website),
		'SIGNATURE' => phpbb_profile_display_text(str_replace('<br />', "\n", $signature)),
		// Start add - Gender MOD
		'LOCK_GENDER' =>($mode!='register') ? 'DISABLED':'', 
		'GENDER' => $gender, 
		'GENDER_NO_SPECIFY_CHECKED' => $gender_no_specify_checked, 
		'GENDER_MALE_CHECKED' => $gender_male_checked, 
		'GENDER_FEMALE_CHECKED' => $gender_female_checked, 
		// End add - Gender MOD
		'VIEW_EMAIL_YES' => ( $viewemail ) ? 'checked="checked"' : '',
		'VIEW_EMAIL_NO' => ( !$viewemail ) ? 'checked="checked"' : '',
		'HIDE_USER_YES' => ( !$allowviewonline ) ? 'checked="checked"' : '',
		'HIDE_USER_NO' => ( $allowviewonline ) ? 'checked="checked"' : '',
		'NOTIFY_PM_YES' => ( $notifypm ) ? 'checked="checked"' : '',
		'NOTIFY_PM_NO' => ( !$notifypm ) ? 'checked="checked"' : '',
		'BLOCK_PM_YES' => ( $games_block_pm ) ? 'checked="checked"' : '',
		'BLOCK_PM_NO' => ( !$games_block_pm ) ? 'checked="checked"' : '',
		'POPUP_PM_YES' => ( $popup_pm ) ? 'checked="checked"' : '',
		'POPUP_PM_NO' => ( !$popup_pm ) ? 'checked="checked"' : '',
		'ALWAYS_ADD_SIGNATURE_YES' => ( $attachsig ) ? 'checked="checked"' : '',
		'ALWAYS_ADD_SIGNATURE_NO' => ( !$attachsig ) ? 'checked="checked"' : '',
		'ALWAYS_SET_BOOKMARK_YES' => ( $setbm ) ? 'checked="checked"' : '',
		'ALWAYS_SET_BOOKMARK_NO' => ( !$setbm ) ? 'checked="checked"' : '',
		'NOTIFY_REPLY_YES' => ( $notifyreply ) ? 'checked="checked"' : '',
		'NOTIFY_REPLY_NO' => ( !$notifyreply ) ? 'checked="checked"' : '',
		'ALWAYS_ALLOW_BBCODE_YES' => ( $allowbbcode ) ? 'checked="checked"' : '',
		'ALWAYS_ALLOW_BBCODE_NO' => ( !$allowbbcode ) ? 'checked="checked"' : '',
		'ALWAYS_ALLOW_HTML_YES' => ( $allowhtml ) ? 'checked="checked"' : '',
		'ALWAYS_ALLOW_HTML_NO' => ( !$allowhtml ) ? 'checked="checked"' : '',
		'ALWAYS_ALLOW_SMILIES_YES' => ( $allowsmilies ) ? 'checked="checked"' : '',
		'ALWAYS_ALLOW_SMILIES_NO' => ( !$allowsmilies ) ? 'checked="checked"' : '',
		'ALLOW_AVATAR' => $board_config['allow_avatar_upload'],
		'AVATAR' => $avatar_img,
		'AVATAR_SIZE' => $board_config['avatar_filesize'],
		'LANGUAGE_SELECT' => language_select($user_lang, 'language'),
		'STYLE_SELECT' => style_select($user_style, 'style'),
		'TIMEZONE_SELECT' => tz_select($user_timezone, 'timezone'),
		'DATE_FORMAT_SELECT' => date_format_select($user_dateformat, $user_timezone),
		'HTML_STATUS' => $html_status,
		'BBCODE_STATUS' => sprintf($bbcode_status, '<a href="' . append_sid("faq.$phpEx?mode=bbcode") . '" target="_phpbbcode">', '</a>'),
		'SMILIES_STATUS' => $smilies_status,

		'L_CURRENT_PASSWORD' => $lang['Current_password'],
		'L_NEW_PASSWORD' => ( $mode == 'register' ) ? $lang['Password'] : $lang['New_password'],
		'L_CONFIRM_PASSWORD' => $lang['Confirm_password'],
		'L_CONFIRM_PASSWORD_EXPLAIN' => ( $mode == 'editprofile' ) ? $lang['Confirm_password_explain'] : '',
		'L_PASSWORD_IF_CHANGED' => ( $mode == 'editprofile' ) ? $lang['password_if_changed'] : '',
		'L_PASSWORD_CONFIRM_IF_CHANGED' => ( $mode == 'editprofile' ) ? $lang['password_confirm_if_changed'] : '',
		'L_SUBMIT' => $lang['Submit'],
		'L_RESET' => $lang['Reset'],
		'L_ICQ_NUMBER' => $lang['ICQ'],
		'L_MESSENGER' => $lang['MSNM'],
		'L_YAHOO' => $lang['YIM'],
		'L_WEBSITE' => $lang['Website'],
		'L_AIM' => $lang['AIM'],
		'L_FACEBOOK' => $lang['FB'],
		'L_INSTAGRAM' => $lang['IG'],
		'L_PINTEREST' => $lang['PT'],
		'L_TWITTER' => $lang['TWR'],
		'L_SKYPE' => $lang['SKP'],
		'L_TELEGRAM' => $lang['TG'],
		'L_LINKEDIN' => $lang['LI'],
		'L_TIKTOK' => $lang['TT'],
		'L_DISCORD' => $lang['DC'],
		'L_SIGNAL' => $lang['SIGNAL'],
		'L_THREEMA' => $lang['THREEMA'],
		'L_FACEBOOK_EXPLAIN' => $lang['Social_facebook_explain'],
		'L_INSTAGRAM_EXPLAIN' => $lang['Social_instagram_explain'],
		'L_LINKEDIN_EXPLAIN' => $lang['Social_linkedin_explain'],
		'L_TWITTER_EXPLAIN' => $lang['Social_twitter_explain'],
		'L_TELEGRAM_EXPLAIN' => $lang['Social_telegram_explain'],
		'L_TIKTOK_EXPLAIN' => $lang['Social_tiktok_explain'],
		'L_DISCORD_EXPLAIN' => $lang['Social_discord_explain'],
		'L_SIGNAL_EXPLAIN' => $lang['Social_signal_explain'],
		'L_THREEMA_EXPLAIN' => $lang['Social_threema_explain'],
		'L_LOCATION' => $lang['Location'],
		'L_OCCUPATION' => $lang['Occupation'],
		'L_BOARD_LANGUAGE' => $lang['Board_lang'],
		'L_BOARD_STYLE' => $lang['Board_style'],
		'L_TIMEZONE' => $lang['Timezone'],
		'L_DATE_FORMAT' => $lang['Date_format'],
		'L_DATE_FORMAT_EXPLAIN' => $lang['Date_format_explain'],
		'L_YES' => $lang['Yes'],
		'L_NO' => $lang['No'],
		'L_INTERESTS' => $lang['Interests'],
		// Start add - Gender MOD
		'L_GENDER' =>$lang['Gender'], 
		'L_GENDER_MALE' =>$lang['Male'], 
		'L_GENDER_FEMALE' =>$lang['Female'], 
		'L_GENDER_NOT_SPECIFY' =>$lang['No_gender_specify'], 
		// End add - Gender MOD
		// Start add - Birthday MOD
		'L_BIRTHDAY' => $lang['Birthday'],
		// End add - Birthday MOD
		'L_ALWAYS_ALLOW_SMILIES' => $lang['Always_smile'],
		'L_ALWAYS_ALLOW_BBCODE' => $lang['Always_bbcode'],
		'L_ALWAYS_ALLOW_HTML' => $lang['Always_html'],
		'L_HIDE_USER' => $lang['Hide_user'],
		'L_ALWAYS_ADD_SIGNATURE' => $lang['Always_add_sig'],
		'L_ALWAYS_SET_BOOKMARK' => $lang['Always_set_bm'],
		
		'L_AVATAR_PANEL' => $lang['Avatar_panel'],
		'L_AVATAR_EXPLAIN' => sprintf($lang['Avatar_explain'], $board_config['avatar_max_width'], $board_config['avatar_max_height'], (round($board_config['avatar_filesize'] / 1024))),
		'L_UPLOAD_AVATAR_FILE' => $lang['Upload_Avatar_file'],
		'L_UPLOAD_AVATAR_URL' => $lang['Upload_Avatar_URL'],
		'L_UPLOAD_AVATAR_URL_EXPLAIN' => $lang['Upload_Avatar_URL_explain'],
		'L_AVATAR_GALLERY' => $lang['Select_from_gallery'],
		'L_SHOW_GALLERY' => $lang['View_avatar_gallery'],
		'L_LINK_REMOTE_AVATAR' => $lang['Link_remote_Avatar'],
		'L_LINK_REMOTE_AVATAR_EXPLAIN' => $lang['Link_remote_Avatar_explain'],
		'L_DELETE_AVATAR' => $lang['Delete_Image'],
		'L_CURRENT_IMAGE' => $lang['Current_Image'],

		'L_SIGNATURE' => $lang['Signature'],
		'L_SIGNATURE_EXPLAIN' => sprintf($lang['Signature_explain'], $board_config['max_sig_chars']),
		'L_NOTIFY_ON_REPLY' => $lang['Always_notify'],
		'L_NOTIFY_ON_REPLY_EXPLAIN' => $lang['Always_notify_explain'],
		'L_NOTIFY_ON_PRIVMSG' => $lang['Notify_on_privmsg'],
		'L_BLOCK_ARCADE_PM' => $lang['Block_Arcade_pm'],
		'L_POPUP_ON_PRIVMSG' => $lang['Popup_on_privmsg'],
		'L_POPUP_ON_PRIVMSG_EXPLAIN' => $lang['Popup_on_privmsg_explain'],
		'L_PREFERENCES' => $lang['Preferences'],
		'L_PUBLIC_VIEW_EMAIL' => $lang['Public_view_email'],
		'L_ITEMS_REQUIRED' => $lang['Items_required'],
		'L_REGISTRATION_INFO' => $lang['Registration_info'],
		'L_PROFILE_INFO' => $lang['Profile_info'],
		'L_PROFILE_INFO_NOTICE' => $lang['Profile_info_warn'],
		'L_EMAIL_ADDRESS' => $lang['Email_address'],
		'L_PASSWORD_MISMATCH' => $lang['Password_mismatch'],
		'L_CONFIRM_CODE_IMPAIRED'	=> sprintf($lang['Confirm_code_impaired'], '<a href="mailto:' . $board_config['board_email'] . '">', '</a>'), 
		'L_CONFIRM_CODE'			=> $lang['Confirm_code'], 
		'L_CONFIRM_CODE_EXPLAIN'	=> $lang['Confirm_code_explain'], 
		// Anti Robotic Registration MOD
		'L_VALIDATION' => $lang['Validation'],
		'L_VALIDATION_EXPLAIN' => $lang['Validation_explain'],
		'S_ANTI_ROBOT1' => append_sid('antirobot_pic.'.$phpEx.'?id=1'),
		'S_ANTI_ROBOT2' => append_sid('antirobot_pic.'.$phpEx.'?id=2'),
		'S_ANTI_ROBOT3' => append_sid('antirobot_pic.'.$phpEx.'?id=3'),
		'S_ANTI_ROBOT4' => append_sid('antirobot_pic.'.$phpEx.'?id=4'),
		'S_ANTI_ROBOT5' => append_sid('antirobot_pic.'.$phpEx.'?id=5'),
		'S_ALLOW_AVATAR_UPLOAD' => $board_config['allow_avatar_upload'],
		'S_ALLOW_AVATAR_LOCAL' => $board_config['allow_avatar_local'],
		'S_ALLOW_AVATAR_REMOTE' => $board_config['allow_avatar_remote'],
		'S_HIDDEN_FIELDS' => $s_hidden_fields,
		'S_FORM_ENCTYPE' => $form_enctype,
		'S_PROFILE_ACTION' => append_sid("profile.$phpEx"))
	);

	//
	// This is another cheat using the block_var capability
	// of the templates to 'fake' an IF...ELSE...ENDIF solution
	// it works well :)
	//
	if ( $mode != 'register' )
	{
		if ( $userdata['user_allowavatar'] && ( $board_config['allow_avatar_upload'] || $board_config['allow_avatar_local'] || $board_config['allow_avatar_remote'] ) )
		{
			$template->assign_block_vars('switch_avatar_block', array() );

			if ( $board_config['allow_avatar_upload'] && file_exists(@phpbb_realpath('./' . $board_config['avatar_path'])) )
			{
				if ( $form_enctype != '' )
				{
					$template->assign_block_vars('switch_avatar_block.switch_avatar_local_upload', array() );
				}
				$template->assign_block_vars('switch_avatar_block.switch_avatar_remote_upload', array() );
			}

			if ( $board_config['allow_avatar_remote'] )
			{
				$template->assign_block_vars('switch_avatar_block.switch_avatar_remote_link', array() );
			}

			if ( $board_config['allow_avatar_local'] && file_exists(@phpbb_realpath('./' . $board_config['avatar_gallery_path'])) )
			{
				$template->assign_block_vars('switch_avatar_block.switch_avatar_local_gallery', array() );
			}
		}
	}
	
	if (($mode == 'register') && ($plus_config['enable_antirobot'] == 1))
	{
		$template->assign_block_vars('switch_validation', array() );
	}
}

$template->pparse('body');

include($phpbb_root_path . 'includes/page_tail.'.$phpEx);

?>
