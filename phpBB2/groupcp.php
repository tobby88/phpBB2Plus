<?php
/***************************************************************************
 *                               groupcp.php
 *                            -------------------
 *   begin                : Saturday, Feb 13, 2001
 *   copyright            : (C) 2001 The phpBB Group
 *   email                : support@phpbb.com
 *
 *   $Id: groupcp.php,v 1.58.2.19 2003/12/30 14:17:49 psotfx Exp $
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
$select_sort_mode = '';
$select_sort_order = '';

// -------------------------
//
function generate_user_info(&$row, $date_format, $group_mod, &$from, &$posts, &$joined, &$poster_avatar, &$profile_img, &$profile, &$search_img, &$search, &$pm_img, &$pm, &$email_img, &$email, &$www_img, &$www, &$icq_status_img, &$icq_img, &$icq, &$aim_img, &$aim, &$msn_img, &$msn, &$yim_img, &$yim)
{
	global $lang, $images, $board_config, $phpEx;

	$from = ( !empty($row['user_from']) ) ? phpbb_profile_text($row['user_from']) : '&nbsp;';
	$joined = create_date($date_format, $row['user_regdate'], $board_config['board_timezone']);
	$posts = ( $row['user_posts'] ) ? $row['user_posts'] : 0;

	$poster_avatar = '';
	if ( !empty($row['user_avatar_type']) && $row['user_id'] != ANONYMOUS && $row['user_allowavatar'] )
	{
		$poster_avatar = phpbb_avatar_image($row['user_avatar'], $row['user_avatar_type']);
	}

	if ( !empty($row['user_viewemail']) || $group_mod )
	{
		$email_form_url = ( $board_config['board_email_form'] ) ? append_sid("profile.$phpEx?mode=email&amp;" . POST_USERS_URL . '=' . (int) $row['user_id']) : '';
		$email_uri = phpbb_profile_email_uri($row['user_email'], $email_form_url);

		$email_img = ($email_uri !== '') ? '<a href="' . $email_uri . '"><img src="' . $images['icon_email'] . '" alt="' . $lang['Send_email'] . '" title="' . $lang['Send_email'] . '" border="0" /></a>' : '&nbsp;';
		$email = ($email_uri !== '') ? '<a href="' . $email_uri . '">' . $lang['Send_email'] . '</a>' : '&nbsp;';
	}
	else
	{
		$email_img = '&nbsp;';
		$email = '&nbsp;';
	}

	$temp_url = append_sid("profile.$phpEx?mode=viewprofile&amp;" . POST_USERS_URL . "=" . $row['user_id']);
	$profile_img = '<a href="' . $temp_url . '"><img src="' . $images['icon_profile'] . '" alt="' . $lang['Read_profile'] . '" title="' . $lang['Read_profile'] . '" border="0" /></a>';
	$profile = '<a href="' . $temp_url . '">' . $lang['Read_profile'] . '</a>';

	$temp_url = append_sid("privmsg.$phpEx?mode=post&amp;" . POST_USERS_URL . "=" . $row['user_id']);
	$pm_img = '<a href="' . $temp_url . '"><img src="' . $images['icon_pm'] . '" alt="' . $lang['Send_private_message'] . '" title="' . $lang['Send_private_message'] . '" border="0" /></a>';
	$pm = '<a href="' . $temp_url . '">' . $lang['Send_private_message'] . '</a>';

	$website_url = phpbb_profile_http_url($row['user_website']);
	$www_img = ( $website_url ) ? '<a href="' . $website_url . '" target="_userwww" rel="noopener noreferrer"><img src="' . $images['icon_www'] . '" alt="' . $lang['Visit_website'] . '" title="' . $lang['Visit_website'] . '" border="0" /></a>' : '';
	$www = ( $website_url ) ? '<a href="' . $website_url . '" target="_userwww" rel="noopener noreferrer">' . $lang['Visit_website'] . '</a>' : '';

	$icq_status_img = '';
	$icq_img = '';
	$icq = '';
	$aim_img = '';
	$aim = '';
	$msn_img = '';
	$msn = '';
	$yim_img = '';
	$yim = '';

	$temp_url = append_sid("search.$phpEx?search_author=" . urlencode($row['username']) . "&amp;showresults=posts");
	$search_img = '<a href="' . $temp_url . '"><img src="' . $images['icon_search'] . '" alt="' . sprintf($lang['Search_user_posts'], $row['username']) . '" title="' . sprintf($lang['Search_user_posts'], $row['username']) . '" border="0" /></a>';
	$search = '<a href="' . $temp_url . '">' . sprintf($lang['Search_user_posts'], $row['username']) . '</a>';

	return;
}

function groupcp_require_post_session($sid, $userdata, $lang)
{
	$request_method = isset($_SERVER['REQUEST_METHOD']) && is_scalar($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : '';
	if ($request_method !== 'POST' || $sid === '' || !hash_equals((string) $userdata['session_id'], (string) $sid))
	{
		message_die(GENERAL_ERROR, $lang['Session_invalid']);
	}
}

function groupcp_change($action, $value = null)
{
	global $db, $phpbb_root_path, $phpEx;
	require_once $phpbb_root_path . 'includes/functions_group_storage.' . $phpEx;
	$original_group = isset($_POST[POST_GROUPS_URL]) ? $_POST[POST_GROUPS_URL] : (isset($_GET[POST_GROUPS_URL]) ? $_GET[POST_GROUPS_URL] : null);
	try { return phpbb_group_change($db, $action, $original_group, $value); }
	catch (PhpbbGroupException $error) { message_die(GENERAL_MESSAGE, htmlspecialchars($error->getMessage(), ENT_QUOTES, 'UTF-8')); }
}
function groupcp_notify($outcome)
{
	global $phpbb_root_path, $phpEx, $lang;
	require_once $phpbb_root_path . 'includes/functions_group_notifications.' . $phpEx;
	return phpbb_group_notify($outcome) ? '' : '<br /><br />' . $lang['Group_mail_failed'];
}
//
// --------------------------

//
// Start session management
//
$userdata = session_pagestart($user_ip, PAGE_GROUPCP);
init_userprefs($userdata);
//
// End session management
//

if ( !$userdata['session_logged_in'] )
{
	redirect(append_sid("login.$phpEx?redirect=groupcp.$phpEx", true));
}

$server_url = phpbb_board_url('groupcp.' . $phpEx);

if ( isset($_GET[POST_GROUPS_URL]) || isset($_POST[POST_GROUPS_URL]) )
{
	$post_group_id = (isset($_POST[POST_GROUPS_URL]) && is_scalar($_POST[POST_GROUPS_URL])) ? intval($_POST[POST_GROUPS_URL]) : 0;
	$get_group_id = (isset($_GET[POST_GROUPS_URL]) && is_scalar($_GET[POST_GROUPS_URL])) ? intval($_GET[POST_GROUPS_URL]) : 0;
	$group_id = $post_group_id ? $post_group_id : $get_group_id;
}
else
{
	$group_id = '';
}
if ($group_id !== '' && intval($group_id) <= 0)
{
	message_die(GENERAL_ERROR, $lang['No_groups_exist']);
}

if ( isset($_POST['mode']) || isset($_GET['mode']) )
{
	$post_mode = (isset($_POST['mode']) && is_scalar($_POST['mode'])) ? (string) $_POST['mode'] : '';
	$get_mode = (isset($_GET['mode']) && is_scalar($_GET['mode'])) ? (string) $_GET['mode'] : '';
	$mode = htmlspecialchars($post_mode !== '' ? $post_mode : $get_mode);
}
else
{
	$mode = '';
}

$confirm = ( isset($_POST['confirm']) ) ? TRUE : 0;
$cancel = ( isset($_POST['cancel']) ) ? TRUE : 0;
$sid = (isset($_POST['sid']) && is_scalar($_POST['sid'])) ? (string) $_POST['sid'] : '';
$start = (isset($_GET['start']) && is_scalar($_GET['start'])) ? intval($_GET['start']) : 0;
$start = ($start < 0) ? 0 : $start;

//
// Default var values
//
$is_moderator = FALSE;

if ( isset($_POST['groupstatus']) && $group_id )
{
	groupcp_require_post_session($sid, $userdata, $lang);
	groupcp_change('status', isset($_POST['group_type']) ? $_POST['group_type'] : null);
	message_die(GENERAL_MESSAGE, $lang['Group_type_updated'] . '<br /><br />' . sprintf($lang['Click_return_group'], '<a href="' . append_sid("groupcp.$phpEx?" . POST_GROUPS_URL . "=$group_id") . '">', '</a>'));
}
else if ( isset($_POST['joingroup']) && $group_id )
{
	groupcp_require_post_session($sid, $userdata, $lang);
	$outcome = groupcp_change('join');
	$notice = groupcp_notify($outcome);
	$message = $lang[$outcome['changed'] ? 'Group_joined' : 'Already_member_group'] . $notice . '<br /><br />' . sprintf($lang['Click_return_group'], '<a href="' . append_sid("groupcp.$phpEx?" . POST_GROUPS_URL . "=$group_id") . '">', '</a>');
	message_die(GENERAL_MESSAGE, $message);
}
else if ( (isset($_POST['unsub']) || isset($_POST['unsubpending'])) && $group_id )
{
	//
	// Second, unsubscribing from a group
	// Check for confirmation of unsub.
	//
	if ( $cancel )
	{
		redirect(append_sid("groupcp.$phpEx", true));
	}
	else if ( !$userdata['session_logged_in'] )
	{
		redirect(append_sid("login.$phpEx?redirect=groupcp.$phpEx&" . POST_GROUPS_URL . "=$group_id", true));
	}
	groupcp_require_post_session($sid, $userdata, $lang);


	if ( $confirm )
	{
		groupcp_change(isset($_POST['unsubpending']) ? 'unsubscribe_pending' : 'unsubscribe');

		$template->assign_vars(array(
			'META' => '<meta http-equiv="refresh" content="3;url=' . append_sid("index.$phpEx") . '">')
		);

		$message = $lang['Unsub_success'] . '<br /><br />' . sprintf($lang['Click_return_group'], '<a href="' . append_sid("groupcp.$phpEx?" . POST_GROUPS_URL . "=$group_id") . '">', '</a>') . '<br /><br />' . sprintf($lang['Click_return_index'], '<a href="' . append_sid("index.$phpEx") . '">', '</a>');

		message_die(GENERAL_MESSAGE, $message);
	}
	else
	{
		$unsub_msg = ( isset($_POST['unsub']) ) ? $lang['Confirm_unsub'] : $lang['Confirm_unsub_pending'];

		$s_hidden_fields = '<input type="hidden" name="' . POST_GROUPS_URL . '" value="' . $group_id . '" /><input type="hidden" name="' . (isset($_POST['unsubpending']) ? 'unsubpending' : 'unsub') . '" value="1" />';
		$s_hidden_fields .= '<input type="hidden" name="sid" value="' . $userdata['session_id'] . '" />';

		$page_title = $lang['Group_Control_Panel'];
		include($phpbb_root_path . 'includes/page_header.'.$phpEx);

		$template->set_filenames(array(
			'confirm' => 'confirm_body.tpl')
		);

		$template->assign_vars(array(
			'MESSAGE_TITLE' => $lang['Confirm'],
			'MESSAGE_TEXT' => $unsub_msg,
			'L_YES' => $lang['Yes'],
			'L_NO' => $lang['No'],
			'S_CONFIRM_ACTION' => append_sid("groupcp.$phpEx"),
			'S_HIDDEN_FIELDS' => $s_hidden_fields)
		);

		$template->pparse('confirm');

		include($phpbb_root_path . 'includes/page_tail.'.$phpEx);
	}

}
else if ( $group_id )
{
	//
	// Did the group moderator get here through an email?
	// If so, check to see if they are logged in.
	//
	if ( isset($_GET['validate']) )
	{
		if ( !$userdata['session_logged_in'] )
		{
			redirect(append_sid("login.$phpEx?redirect=groupcp.$phpEx&" . POST_GROUPS_URL . "=$group_id", true));
		}
	}

	//
	// For security, get the ID of the group moderator.
	//
	$sql = "SELECT group_moderator, group_type FROM " . GROUPS_TABLE . " WHERE group_id = $group_id AND group_single_user = 0";
	if ( !($result = $db->sql_query($sql)) )
	{
		message_die(GENERAL_ERROR, 'Could not get moderator information', '', __LINE__, __FILE__, $sql);
	}

	$group_info = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);
	if ( $group_info )
	{
		$group_moderator = $group_info['group_moderator'];
	
		if ( $group_moderator == $userdata['user_id'] || $userdata['user_level'] == ADMIN )
		{
			$is_moderator = TRUE;
		}
			
		//
		// Handle Additions, removals, approvals and denials
		//
		if ( isset($_POST['add']) || isset($_POST['remove']) || isset($_POST['approve']) || isset($_POST['deny']) )
		{
			groupcp_require_post_session($sid, $userdata, $lang);
			$action = isset($_POST['add']) ? 'add' : (isset($_POST['approve']) ? 'approve' : (isset($_POST['deny']) ? 'deny' : 'remove'));
			$field = $action === 'add' ? 'username' : ($action === 'remove' ? 'members' : 'pending_members');
			$outcome = groupcp_change($action, isset($_POST[$field]) ? $_POST[$field] : null);
			$notice = groupcp_notify($outcome);
			$message = sprintf($lang['Group_members_changed'], count($outcome['changed'])) . $notice . '<br /><br />' . sprintf($lang['Click_return_group'], '<a href="' . append_sid("groupcp.$phpEx?" . POST_GROUPS_URL . "=$group_id") . '">', '</a>');
			message_die(GENERAL_MESSAGE, $message);
		}
		//
		// END approve or deny
		//
	}
	else
	{
		message_die(GENERAL_MESSAGE, $lang['No_groups_exist']);
	}

	//
	// Get group details
	//
	$sql = "SELECT *
		FROM " . GROUPS_TABLE . "
		WHERE group_id = $group_id
			AND group_single_user = 0";
	if ( !($result = $db->sql_query($sql)) )
	{
		message_die(GENERAL_ERROR, 'Error getting group information', '', __LINE__, __FILE__, $sql);
	}

	if ( !($group_info = $db->sql_fetchrow($result)) )
	{
		message_die(GENERAL_MESSAGE, $lang['Group_not_exist']); 
	}

	//
	// Get moderator details for this group
	//
	$sql = "SELECT username, user_absence, user_absence_mode, user_id, user_viewemail, user_posts, user_regdate, user_from, user_website, user_email, user_icq, user_aim, user_yim, user_msnm, user_fb, user_ig, user_pt, user_twr, user_skp, user_tg, user_li, user_tt, user_dc
		FROM " . USERS_TABLE . " 
		WHERE user_id = " . $group_info['group_moderator'];
	if ( !($result = $db->sql_query($sql)) )
	{
		message_die(GENERAL_ERROR, 'Error getting user list for group', '', __LINE__, __FILE__, $sql);
	}

	$group_moderator = $db->sql_fetchrow($result); 

	//
	// Get user information for this group
	//
	$sql = "SELECT u.username, u.user_absence, u.user_absence_mode, u.user_id, u.user_viewemail, u.user_posts, u.user_regdate, u.user_from, u.user_website, u.user_email, u.user_icq, u.user_aim, u.user_yim, u.user_msnm, u.user_fb, u.user_ig, u.user_pt, u.user_twr, u.user_skp, u.user_tg, u.user_li, u.user_tt, u.user_dc, ug.user_pending
		FROM " . USERS_TABLE . " u, " . USER_GROUP_TABLE . " ug
		WHERE ug.group_id = $group_id
			AND u.user_id = ug.user_id
			AND ug.user_pending = 0 
			AND ug.user_id <> " . $group_moderator['user_id'] . " 
		ORDER BY u.username"; 
	if ( !($result = $db->sql_query($sql)) )
	{
		message_die(GENERAL_ERROR, 'Error getting user list for group', '', __LINE__, __FILE__, $sql);
	}

	$group_members = $db->sql_fetchrowset($result); 
	$members_count = count($group_members);
	$db->sql_freeresult($result);

	$sql = "SELECT u.username, u.user_id, u.user_viewemail, u.user_posts, u.user_regdate, u.user_from, u.user_website, u.user_email, u.user_icq, u.user_aim, u.user_yim, u.user_msnm, u.user_fb, u.user_ig, u.user_pt, u.user_twr, u.user_skp, u.user_tg, u.user_li, u.user_tt, u.user_dc
		FROM " . GROUPS_TABLE . " g, " . USER_GROUP_TABLE . " ug, " . USERS_TABLE . " u
		WHERE ug.group_id = $group_id
			AND g.group_id = ug.group_id
			AND ug.user_pending = 1
			AND u.user_id = ug.user_id
		ORDER BY u.username"; 
	if ( !($result = $db->sql_query($sql)) )
	{
		message_die(GENERAL_ERROR, 'Error getting user pending information', '', __LINE__, __FILE__, $sql);
	}

	$modgroup_pending_list = $db->sql_fetchrowset($result);
	$modgroup_pending_count = count($modgroup_pending_list);
	$db->sql_freeresult($result);

	$is_group_member = 0;
	if ( $members_count )
	{
		for($i = 0; $i < $members_count; $i++)
		{
			if ( $group_members[$i]['user_id'] == $userdata['user_id'] && $userdata['session_logged_in'] )
			{
				$is_group_member = TRUE; 
			}
		}
	}

	$is_group_pending_member = 0;
	if ( $modgroup_pending_count )
	{
		for($i = 0; $i < $modgroup_pending_count; $i++)
		{
			if ( $modgroup_pending_list[$i]['user_id'] == $userdata['user_id'] && $userdata['session_logged_in'] )
			{
				$is_group_pending_member = TRUE;
			}
		}
	}

	if ( $userdata['user_level'] == ADMIN )
	{
		$is_moderator = TRUE;
	}

	if ( $userdata['user_id'] == $group_info['group_moderator'] )
	{
		$is_moderator = TRUE;

		$group_details =  $lang['Are_group_moderator'];

		$s_hidden_fields = '<input type="hidden" name="' . POST_GROUPS_URL . '" value="' . $group_id . '" />';
	}
	else if ( $is_group_member || $is_group_pending_member )
	{
		$template->assign_block_vars('switch_unsubscribe_group_input', array());

		$group_details =  ( $is_group_pending_member ) ? $lang['Pending_this_group'] : $lang['Member_this_group'];

		$s_hidden_fields = '<input type="hidden" name="' . POST_GROUPS_URL . '" value="' . $group_id . '" />';
	}
	else if ( $userdata['user_id'] == ANONYMOUS )
	{
		$group_details =  $lang['Login_to_join'];
		$s_hidden_fields = '';
	}
	else
	{
		if ( $group_info['group_type'] == GROUP_OPEN )
		{
			$template->assign_block_vars('switch_subscribe_group_input', array());

			$group_details =  $lang['This_open_group'];
			$s_hidden_fields = '<input type="hidden" name="' . POST_GROUPS_URL . '" value="' . $group_id . '" />';
		}
		else if ( $group_info['group_type'] == GROUP_CLOSED )
		{
			$group_details =  $lang['This_closed_group'];
			$s_hidden_fields = '';
		}
		else if ( $group_info['group_type'] == GROUP_HIDDEN )
		{
			$group_details =  $lang['This_hidden_group'];
			$s_hidden_fields = '';
		}
	}

	$page_title = $lang['Group_Control_Panel'];
	include($phpbb_root_path . 'includes/page_header.'.$phpEx);

	//
	// Load templates
	//
	$template->set_filenames(array(
		'info' => 'groupcp_info_body.tpl', 
		'pendinginfo' => 'groupcp_pending_info.tpl')
	);
	make_jumpbox('viewforum.'.$phpEx);

	//
	// Add the moderator
	//
	$username = $group_moderator['username'];
	$user_id = $group_moderator['user_id'];

	generate_user_info($group_moderator, $board_config['default_dateformat'], $is_moderator, $from, $posts, $joined, $poster_avatar, $profile_img, $profile, $search_img, $search, $pm_img, $pm, $email_img, $email, $www_img, $www, $icq_status_img, $icq_img, $icq, $aim_img, $aim, $msn_img, $msn, $yim_img, $yim);
	$mod_social = phpbb_social_profile_links($group_moderator);

	$s_hidden_fields .= '<input type="hidden" name="sid" value="' . $userdata['session_id'] . '" />';
	if ( $group_moderator['user_absence'] == TRUE )
	{
		$absence_mode = create_absence_mode($group_moderator['user_absence_mode'], $pm_img, $pm, $email_img, $email, $username);
	}
	$template->assign_vars(array(
		'L_GROUP_INFORMATION' => $lang['Group_Information'],
		'L_GROUP_NAME' => $lang['Group_name'],
		'L_GROUP_DESC' => $lang['Group_description'],
		'L_GROUP_TYPE' => $lang['Group_type'],
		'L_GROUP_MEMBERSHIP' => $lang['Group_membership'],
		'L_SUBSCRIBE' => $lang['Subscribe'],
		'L_UNSUBSCRIBE' => $lang['Unsubscribe'],
		'L_JOIN_GROUP' => $lang['Join_group'], 
		'L_UNSUBSCRIBE_GROUP' => $lang['Unsubscribe'], 
		'L_GROUP_OPEN' => $lang['Group_open'],
		'L_GROUP_CLOSED' => $lang['Group_closed'],
		'L_GROUP_HIDDEN' => $lang['Group_hidden'], 
		'L_UPDATE' => $lang['Update'], 
		'L_GROUP_MODERATOR' => $lang['Group_Moderator'], 
		'L_GROUP_MEMBERS' => $lang['Group_Members'], 
		'L_PENDING_MEMBERS' => $lang['Pending_members'], 
		'L_SELECT_SORT_METHOD' => $lang['Select_sort_method'], 
		'L_PM' => $lang['Private_Message'], 
		'L_EMAIL' => $lang['Email'], 
		'L_POSTS' => $lang['Posts'], 
		'L_WEBSITE' => $lang['Website'],
		'L_FROM' => $lang['Location'],
		'L_ORDER' => $lang['Order'],
		'L_SORT' => $lang['Sort'],
		'L_SUBMIT' => $lang['Sort'],
		'L_AIM' => $lang['AIM'],
		'L_YIM' => $lang['YIM'],
		'L_MSNM' => $lang['MSNM'],
		'L_ICQ' => $lang['ICQ'],
		'L_SELECT' => $lang['Select'],
		'L_REMOVE_SELECTED' => $lang['Remove_selected'],
		'L_ADD_MEMBER' => $lang['Add_member'],
		'L_FIND_USERNAME' => $lang['Find_username'],

		'GROUP_NAME' => $group_info['group_name'],
		'GROUP_DESC' => $group_info['group_description'],
		'GROUP_DETAILS' => $group_details,
		'MOD_ROW_COLOR' => '#' . $theme['td_color1'],
		'MOD_ROW_CLASS' => $theme['td_class1'],
		'MOD_USERNAME' => $username,
		'MOD_FROM' => $from,
		'MOD_JOINED' => $joined,
		'MOD_POSTS' => $posts,
		'MOD_AVATAR_IMG' => $poster_avatar,
		'MOD_PROFILE_IMG' => $profile_img, 
		'MOD_PROFILE' => $profile, 
		'MOD_SEARCH_IMG' => $search_img,
		'MOD_SEARCH' => $search,
		'MOD_PM_IMG' => $pm_img,
		'MOD_PM' => $pm,
		'MOD_EMAIL_IMG' => $email_img,
		'MOD_EMAIL' => $email,
		'MOD_WWW_IMG' => $www_img,
		'MOD_WWW' => $www,
		'MOD_ICQ_STATUS_IMG' => $icq_status_img,
		'MOD_ICQ_IMG' => $icq_img, 
		'MOD_ICQ' => $icq, 
		'MOD_AIM_IMG' => $aim_img,
		'MOD_AIM' => $aim,
		'MOD_MSN_IMG' => $msn_img,
		'MOD_MSN' => $msn,
		'MOD_YIM_IMG' => $yim_img,
		'MOD_YIM' => $yim,
		'MOD_FB_IMG' => $mod_social['FB_IMG'],
		'MOD_FB' => $mod_social['FB'],
		'MOD_IG_IMG' => $mod_social['IG_IMG'],
		'MOD_IG' => $mod_social['IG'],
		'MOD_PT_IMG' => $mod_social['PT_IMG'],
		'MOD_PT' => $mod_social['PT'],
		'MOD_TWR_IMG' => $mod_social['TWR_IMG'],
		'MOD_TWR' => $mod_social['TWR'],
		'MOD_SKP_IMG' => $mod_social['SKP_IMG'],
		'MOD_SKP' => $mod_social['SKP'],
		'MOD_TG_IMG' => $mod_social['TG_IMG'],
		'MOD_TG' => $mod_social['TG'],
		'MOD_LI_IMG' => $mod_social['LI_IMG'],
		'MOD_LI' => $mod_social['LI'],
		'MOD_TT_IMG' => $mod_social['TT_IMG'],
		'MOD_TT' => $mod_social['TT'],
		'MOD_DC_IMG' => $mod_social['DC_IMG'],
		'MOD_DC' => $mod_social['DC'],

		'U_MOD_VIEWPROFILE' => append_sid("profile.$phpEx?mode=viewprofile&amp;" . POST_USERS_URL . "=$user_id"), 
		'U_SEARCH_USER' => append_sid("search.$phpEx?mode=searchuser"), 

		'S_GROUP_OPEN_TYPE' => GROUP_OPEN,
		'S_GROUP_CLOSED_TYPE' => GROUP_CLOSED,
		'S_GROUP_HIDDEN_TYPE' => GROUP_HIDDEN,
		'S_GROUP_OPEN_CHECKED' => ( $group_info['group_type'] == GROUP_OPEN ) ? ' checked="checked"' : '',
		'S_GROUP_CLOSED_CHECKED' => ( $group_info['group_type'] == GROUP_CLOSED ) ? ' checked="checked"' : '',
		'S_GROUP_HIDDEN_CHECKED' => ( $group_info['group_type'] == GROUP_HIDDEN ) ? ' checked="checked"' : '',
		'S_HIDDEN_FIELDS' => $s_hidden_fields, 
		'S_MODE_SELECT' => $select_sort_mode,
		'S_ORDER_SELECT' => $select_sort_order,
		'S_GROUPCP_ACTION' => append_sid("groupcp.$phpEx?" . POST_GROUPS_URL . "=$group_id"))
	);

	//
	// Dump out the remaining users
	//
	for($i = $start; $i < min($board_config['topics_per_page'] + $start, $members_count); $i++)
	{
		$username = $group_members[$i]['username'];
		$user_id = $group_members[$i]['user_id'];

		generate_user_info($group_members[$i], $board_config['default_dateformat'], $is_moderator, $from, $posts, $joined, $poster_avatar, $profile_img, $profile, $search_img, $search, $pm_img, $pm, $email_img, $email, $www_img, $www, $icq_status_img, $icq_img, $icq, $aim_img, $aim, $msn_img, $msn, $yim_img, $yim);
		$social = phpbb_social_profile_links($group_members[$i]);

		if ( $group_info['group_type'] != GROUP_HIDDEN || $is_group_member || $is_moderator )
		{
			$row_color = ( !($i % 2) ) ? $theme['td_color1'] : $theme['td_color2'];
			$row_class = ( !($i % 2) ) ? $theme['td_class1'] : $theme['td_class2'];
			if ( $group_members[$i]['user_absence'] == TRUE )
			{
				$absence_mode = create_absence_mode($group_members[$i]['user_absence_mode'], $pm_img, $pm, $email_img, $email, $username);
			}
			$template->assign_block_vars('member_row', array(
				'ROW_COLOR' => '#' . $row_color,
				'ROW_CLASS' => $row_class,
				'USERNAME' => $username,
				'FROM' => $from,
				'JOINED' => $joined,
				'POSTS' => $posts,
				'USER_ID' => $user_id, 
				'AVATAR_IMG' => $poster_avatar,
				'PROFILE_IMG' => $profile_img, 
				'PROFILE' => $profile, 
				'SEARCH_IMG' => $search_img,
				'SEARCH' => $search,
				'PM_IMG' => $pm_img,
				'PM' => $pm,
				'EMAIL_IMG' => $email_img,
				'EMAIL' => $email,
				'WWW_IMG' => $www_img,
				'WWW' => $www,
				'ICQ_STATUS_IMG' => $icq_status_img,
				'ICQ_IMG' => $icq_img, 
				'ICQ' => $icq, 
				'AIM_IMG' => $aim_img,
				'AIM' => $aim,
				'MSN_IMG' => $msn_img,
				'MSN' => $msn,
				'YIM_IMG' => $yim_img,
				'YIM' => $yim,
				'FB_IMG' => $social['FB_IMG'],
				'FB' => $social['FB'],
				'IG_IMG' => $social['IG_IMG'],
				'IG' => $social['IG'],
				'PT_IMG' => $social['PT_IMG'],
				'PT' => $social['PT'],
				'TWR_IMG' => $social['TWR_IMG'],
				'TWR' => $social['TWR'],
				'SKP_IMG' => $social['SKP_IMG'],
				'SKP' => $social['SKP'],
				'TG_IMG' => $social['TG_IMG'],
				'TG' => $social['TG'],
				'LI_IMG' => $social['LI_IMG'],
				'LI' => $social['LI'],
				'TT_IMG' => $social['TT_IMG'],
				'TT' => $social['TT'],
				'DC_IMG' => $social['DC_IMG'],
				'DC' => $social['DC'],
				
				'U_VIEWPROFILE' => append_sid("profile.$phpEx?mode=viewprofile&amp;" . POST_USERS_URL . "=$user_id"))
			);

			if ( $is_moderator )
			{
				$template->assign_block_vars('member_row.switch_mod_option', array());
			}
		}
	}

	if ( !$members_count )
	{
		//
		// No group members
		//
		$template->assign_block_vars('switch_no_members', array());
		$template->assign_vars(array(
			'L_NO_MEMBERS' => $lang['No_group_members'])
		);
	}

	$current_page = ( !$members_count ) ? 1 : ceil( $members_count / $board_config['topics_per_page'] );

	$template->assign_vars(array(
		'PAGINATION' => generate_pagination("groupcp.$phpEx?" . POST_GROUPS_URL . "=$group_id", $members_count, $board_config['topics_per_page'], $start),
		'PAGE_NUMBER' => sprintf($lang['Page_of'], ( floor( $start / $board_config['topics_per_page'] ) + 1 ), $current_page ), 

		'L_GOTO_PAGE' => $lang['Goto_page'])
	);

	if ( $group_info['group_type'] == GROUP_HIDDEN && !$is_group_member && !$is_moderator )
	{
		//
		// No group members
		//
		$template->assign_block_vars('switch_hidden_group', array());
		$template->assign_vars(array(
			'L_HIDDEN_MEMBERS' => $lang['Group_hidden_members'])
		);
	}

	//
	// We've displayed the members who belong to the group, now we 
	// do that pending memebers... 
	//
	if ( $is_moderator )
	{
		//
		// Users pending in ONLY THIS GROUP (which is moderated by this user)
		//
		if ( $modgroup_pending_count )
		{
			for($i = 0; $i < $modgroup_pending_count; $i++)
			{
				$username = $modgroup_pending_list[$i]['username'];
				$user_id = $modgroup_pending_list[$i]['user_id'];

				generate_user_info($modgroup_pending_list[$i], $board_config['default_dateformat'], $is_moderator, $from, $posts, $joined, $poster_avatar, $profile_img, $profile, $search_img, $search, $pm_img, $pm, $email_img, $email, $www_img, $www, $icq_status_img, $icq_img, $icq, $aim_img, $aim, $msn_img, $msn, $yim_img, $yim);
				$social = phpbb_social_profile_links($modgroup_pending_list[$i]);

				$row_color = ( !($i % 2) ) ? $theme['td_color1'] : $theme['td_color2'];
				$row_class = ( !($i % 2) ) ? $theme['td_class1'] : $theme['td_class2'];

				$user_select = '<input type="checkbox" name="member[]" value="' . $user_id . '">';
				if ( $modgroup_pending_list[$i]['user_absence']== TRUE )
				{
					$absence_mode = create_absence_mode($modgroup_pending_list[$i]['user_absence_mode'], $pm_img, $pm, $email_img, $email, $username);
				}
				$template->assign_block_vars('pending_members_row', array(
					'ROW_CLASS' => $row_class,
					'ROW_COLOR' => '#' . $row_color, 
					'USERNAME' => $username,
					'FROM' => $from,
					'JOINED' => $joined,
					'POSTS' => $posts,
					'USER_ID' => $user_id, 
					'AVATAR_IMG' => $poster_avatar,
					'PROFILE_IMG' => $profile_img, 
					'PROFILE' => $profile, 
					'SEARCH_IMG' => $search_img,
					'SEARCH' => $search,
					'PM_IMG' => $pm_img,
					'PM' => $pm,
					'EMAIL_IMG' => $email_img,
					'EMAIL' => $email,
					'WWW_IMG' => $www_img,
					'WWW' => $www,
					'ICQ_STATUS_IMG' => $icq_status_img,
					'ICQ_IMG' => $icq_img, 
					'ICQ' => $icq, 
					'AIM_IMG' => $aim_img,
					'AIM' => $aim,
					'MSN_IMG' => $msn_img,
					'MSN' => $msn,
					'YIM_IMG' => $yim_img,
					'YIM' => $yim,
					'FB_IMG' => $social['FB_IMG'],
					'FB' => $social['FB'],
					'IG_IMG' => $social['IG_IMG'],
					'IG' => $social['IG'],
					'PT_IMG' => $social['PT_IMG'],
					'PT' => $social['PT'],
					'TWR_IMG' => $social['TWR_IMG'],
					'TWR' => $social['TWR'],
					'SKP_IMG' => $social['SKP_IMG'],
					'SKP' => $social['SKP'],
					'TG_IMG' => $social['TG_IMG'],
					'TG' => $social['TG'],
					'LI_IMG' => $social['LI_IMG'],
					'LI' => $social['LI'],
					'TT_IMG' => $social['TT_IMG'],
					'TT' => $social['TT'],
					'DC_IMG' => $social['DC_IMG'],
					'DC' => $social['DC'],
					
					'U_VIEWPROFILE' => append_sid("profile.$phpEx?mode=viewprofile&amp;" . POST_USERS_URL . "=$user_id"))
				);
			}

			$template->assign_block_vars('switch_pending_members', array() );

			$template->assign_vars(array(
				'L_SELECT' => $lang['Select'],
				'L_APPROVE_SELECTED' => $lang['Approve_selected'],
				'L_DENY_SELECTED' => $lang['Deny_selected'])
			);

			$template->assign_var_from_handle('PENDING_USER_BOX', 'pendinginfo');
		
		}
	}

	if ( $is_moderator )
	{
		$template->assign_block_vars('switch_mod_option', array());
		$template->assign_block_vars('switch_add_member', array());
	}

	$template->pparse('info');
}
else
{
	//
	// Show the main groupcp.php screen where the user can select a group.
	//
	// Select all group that the user is a member of or where the user has
	// a pending membership.
	//
	$in_group = array();
	$s_member_groups_opt = '';
	$s_pending_groups_opt = '';
	$s_member_groups = '';
	$s_pending_groups = '';
	if ( $userdata['session_logged_in'] ) 
	{
		$sql = "SELECT g.group_id, g.group_name, g.group_type, ug.user_pending 
			FROM " . GROUPS_TABLE . " g, " . USER_GROUP_TABLE . " ug
			WHERE ug.user_id = " . $userdata['user_id'] . "  
				AND ug.group_id = g.group_id
				AND g.group_single_user <> " . TRUE . "
			ORDER BY g.group_name, ug.user_id";
		if ( !($result = $db->sql_query($sql)) )
		{
			message_die(GENERAL_ERROR, 'Error getting group information', '', __LINE__, __FILE__, $sql);
		}

		if ( $row = $db->sql_fetchrow($result) )
		{
			$in_group = array();
			$s_member_groups_opt = '';
			$s_pending_groups_opt = '';

			do
			{
				$in_group[] = $row['group_id'];
				if ( $row['user_pending'] )
				{
					$s_pending_groups_opt .= '<option value="' . $row['group_id'] . '">' . $row['group_name'] . '</option>';
				}
				else
				{
					$s_member_groups_opt .= '<option value="' . $row['group_id'] . '">' . $row['group_name'] . '</option>';
				}
			}
			while( $row = $db->sql_fetchrow($result) );

			$s_pending_groups = '<select name="' . POST_GROUPS_URL . '">' . $s_pending_groups_opt . "</select>";
			$s_member_groups = '<select name="' . POST_GROUPS_URL . '">' . $s_member_groups_opt . "</select>";
		}
	}

	//
	// Select all other groups i.e. groups that this user is not a member of
	//
	$ignore_group_sql =	( count($in_group) ) ? "AND group_id NOT IN (" . implode(', ', $in_group) . ")" : ''; 
	$sql = "SELECT group_id, group_name, group_type 
		FROM " . GROUPS_TABLE . " g 
		WHERE group_single_user <> " . TRUE . " 
			$ignore_group_sql 
		ORDER BY g.group_name";
	if ( !($result = $db->sql_query($sql)) )
	{
		message_die(GENERAL_ERROR, 'Error getting group information', '', __LINE__, __FILE__, $sql);
	}

	$s_group_list_opt = '';
	while( $row = $db->sql_fetchrow($result) )
	{
		if  ( $row['group_type'] != GROUP_HIDDEN || $userdata['user_level'] == ADMIN )
		{
			$s_group_list_opt .='<option value="' . $row['group_id'] . '">' . $row['group_name'] . '</option>';
		}
	}
	$s_group_list = '<select name="' . POST_GROUPS_URL . '">' . $s_group_list_opt . '</select>';

	if ( $s_group_list_opt != '' || $s_pending_groups_opt != '' || $s_member_groups_opt != '' )
	{
		//
		// Load and process templates
		//
		$page_title = $lang['Group_Control_Panel'];
		include($phpbb_root_path . 'includes/page_header.'.$phpEx);

		$template->set_filenames(array(
			'user' => 'groupcp_user_body.tpl')
		);
		make_jumpbox('viewforum.'.$phpEx);

		if ( $s_pending_groups_opt != '' || $s_member_groups_opt != '' )
		{
			$template->assign_block_vars('switch_groups_joined', array() );
		}

		if ( $s_member_groups_opt != '' )
		{
			$template->assign_block_vars('switch_groups_joined.switch_groups_member', array() );
		}

		if ( $s_pending_groups_opt != '' )
		{
			$template->assign_block_vars('switch_groups_joined.switch_groups_pending', array() );
		}

		if ( $s_group_list_opt != '' )
		{
			$template->assign_block_vars('switch_groups_remaining', array() );
		}

		$s_hidden_fields = '<input type="hidden" name="sid" value="' . $userdata['session_id'] . '" />';

		$template->assign_vars(array(
			'L_GROUP_MEMBERSHIP_DETAILS' => $lang['Group_member_details'],
			'L_JOIN_A_GROUP' => $lang['Group_member_join'],
			'L_YOU_BELONG_GROUPS' => $lang['Current_memberships'],
			'L_SELECT_A_GROUP' => $lang['Non_member_groups'],
			'L_PENDING_GROUPS' => $lang['Memberships_pending'],
			'L_SUBSCRIBE' => $lang['Subscribe'],
			'L_UNSUBSCRIBE' => $lang['Unsubscribe'],
			'L_VIEW_INFORMATION' => $lang['View_Information'], 

			'S_USERGROUP_ACTION' => append_sid("groupcp.$phpEx"), 
			'S_HIDDEN_FIELDS' => $s_hidden_fields, 

			'GROUP_LIST_SELECT' => $s_group_list,
			'GROUP_PENDING_SELECT' => $s_pending_groups,
			'GROUP_MEMBER_SELECT' => $s_member_groups)
		);

		$template->pparse('user');
	}
	else
	{
		message_die(GENERAL_MESSAGE, $lang['No_groups_exist']);
	}

}

include($phpbb_root_path . 'includes/page_tail.'.$phpEx);

?>
