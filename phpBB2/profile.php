<?php
/***************************************************************************
 *                                profile.php
 *                            -------------------
 *   begin                : Saturday, Feb 13, 2001
 *   copyright            : (C) 2001 The phpBB Group
 *   email                : support@phpbb.com
 *
 *   $Id: profile.php,v 1.193.2.3 2003/03/02 23:16:17 acydburn Exp $
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

if (!defined('IN_PHPBB'))
{
    define( 'IN_PHPBB', true);
}
$phpbb_root_path = './';
include($phpbb_root_path . 'extension.inc');
include($phpbb_root_path . 'common.'.$phpEx);

//
// Start session management
//
$userdata = session_pagestart($user_ip, PAGE_PROFILE);
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
//
// Set default email variables
//
$server_url = phpbb_board_url('profile.' . $phpEx);

// -----------------------
// Page specific functions
//
function gen_rand_string($hash)
{
	$rand_str = dss_rand();

	// dss_rand() is backed by phpbb_random_bytes(). Keep the full 128-bit
	// activation token and use all 64 random bits for temporary passwords.
	return ( $hash ) ? md5($rand_str) : substr($rand_str, 0, 16);
}
//
// End page specific functions
// ---------------------------

//
// Start of program proper
//
if ( isset($_GET['mode']) || isset($_POST['mode']) )
{
	$get_mode = (isset($_GET['mode']) && is_scalar($_GET['mode'])) ? (string) $_GET['mode'] : '';
	$post_mode = (isset($_POST['mode']) && is_scalar($_POST['mode'])) ? (string) $_POST['mode'] : '';
	$mode = htmlspecialchars($get_mode !== '' ? $get_mode : $post_mode);

	$mode = (!empty($_POST['signature'])) ? 'signature' : $mode;
	$mode = (!empty($_GET['signature'])) ? 'signature' : $mode;

	if ( $mode == 'viewprofile' )
	{
		//--- Album Category Hierarchy : begin
//--- version : 1.2.0
	 	$album_root_path = $phpbb_root_path.'album_mod/';
//--- version : 1.3.0		
	 	include_once($album_root_path.'album_constants.'.$phpEx);
//--- Album Category Hierarchy : end
		include($phpbb_root_path . 'includes/usercp_viewprofile.'.$phpEx);
		exit;
	}
	else if ( $mode == 'editprofile' || $mode == 'register' )
	{
		if ( !$userdata['session_logged_in'] && $mode == 'editprofile' )
		{
			redirect(append_sid("login.$phpEx?redirect=profile.$phpEx&mode=editprofile", true));
		}

		include($phpbb_root_path . 'includes/usercp_register.'.$phpEx);
		exit;
	}
	else if ( $mode == 'confirm' )
	{
		// Visual Confirmation
		$confirm_id = (isset($_GET['id']) && is_scalar($_GET['id'])) ? htmlspecialchars((string) $_GET['id']) : '';
		if ( $userdata['session_logged_in'] && ($confirm_id != 'Admin'))
		{
			exit;
		}

		if (function_exists('imagettftext') && defined('ADV_CAPTCHA'))
			include($phpbb_root_path . 'includes/usercp_confirm_adv.'.$phpEx);
		else
			include($phpbb_root_path . 'includes/usercp_confirm.'.$phpEx);
		exit;
	}
	else if ( $mode == 'signature' )
	{
		if ( !$userdata['session_logged_in'] && $mode == 'signature' )
		{
			redirect(append_sid("login.$phpEx?redirect=profile.$phpEx&mode=signature", true));
		}

		include($phpbb_root_path . 'includes/usercp_signature.'.$phpEx);
		exit;
	}
	else if ( $mode == 'sendpassword' )
	{
		include($phpbb_root_path . 'includes/usercp_sendpasswd.'.$phpEx);
		exit;
	}
	else if ( $mode == 'activate' )
	{
		include($phpbb_root_path . 'includes/usercp_activate.'.$phpEx);
		exit;
	}
	else if ( $mode == 'email' )
	{
		include($phpbb_root_path . 'includes/usercp_email.'.$phpEx);
		exit;
	}
}

redirect(append_sid("index.$phpEx", true));

?>
