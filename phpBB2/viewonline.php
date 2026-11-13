<?php
/***************************************************************************
 *                              viewonline.php
 *                            -------------------
 *   begin                : Saturday, Feb 13, 2001
 *   copyright            : (C) 2001 The phpBB Group
 *   email                : support@phpbb.com
 *
 *   $Id: viewonline.php,v 1.54.2.2 2002/12/02 10:53:42 bartvb Exp $
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
$userdata = session_pagestart($user_ip, PAGE_VIEWONLINE);
init_userprefs($userdata);
//
// End session management
//

//
// Output page header and load viewonline template
//
$page_title = $lang['Who_is_Online'];
include($phpbb_root_path . 'includes/page_header.'.$phpEx);

$template->set_filenames(array(
	'body' => 'viewonline_body.tpl')
);
make_jumpbox('viewforum.'.$phpEx);

$template->assign_vars(array(
	'L_WHOSONLINE' => $lang['Who_is_Online'],
	'L_ONLINE_EXPLAIN' => $lang['Online_explain'],
	'L_USERNAME' => $lang['Username'],
	'L_FORUM_LOCATION' => $lang['Forum_Location'],
	'L_LAST_UPDATE' => $lang['Last_updated'])
);

//
// Read sessions, current viewer rights and topic locations together. Do not
// reuse a caller's old transaction or the cached hierarchy permissions.
require_once($phpbb_root_path . 'includes/functions_online.' . $phpEx);
try { $online_context = phpbb_online_listing($db, $userdata); }
catch (Exception $error) { message_die(GENERAL_ERROR, $lang['Online_list_unavailable']); }
catch (Error $error) { message_die(GENERAL_ERROR, $lang['Online_list_unavailable']); }
$guest_users = 0;
$registered_users = 0;
$hidden_users = 0;

$reg_counter = 0;
$guest_counter = 0;

foreach ($online_context['rows'] as $row)
{
	$view_online = true;
	$session_page = (int)$row['session_page'];
	$session_topic = (int)$row['session_topic'];
	if ($row['session_logged_in'])
	{
		$user_id = (int)$row['user_id'];
		if (!$row['user_allow_viewonline'])
		{
			$view_online = $online_context['admin'];
			$hidden_users++;
		}
		else { $registered_users++; }
		if ($view_online)
		{
			include_once($phpbb_root_path . 'includes/functions_color_groups.' . $phpEx);
			$username = color_group_colorize_name($user_id, true, $row['username']);
			if (!$row['user_allow_viewonline']) { $username = '<i>' . $username . '</i>'; }
		}
		$which_counter = 'reg_counter';
		$which_row = 'reg_user_row';
	}
	else
	{
		$user_id = ANONYMOUS;
		$username = $lang['Guest'];
		$guest_users++;
		$which_counter = 'guest_counter';
		$which_row = 'guest_user_row';
	}

	if ( $view_online )
	{
		if ($session_page > 0)
		{
			$visible_location = phpbb_online_topic_location($online_context, $session_page, $session_topic);
			$location = $visible_location['label']; $location_url = $visible_location['url'];
		}
		else
		{
			switch($session_page)
			{
				case PAGE_INDEX:
					$location = $lang['Forum_index'];
					$location_url = "index.$phpEx";
					break;
				case PAGE_POSTING:
					$location = $lang['Posting_message'];
					$location_url = "index.$phpEx";
					break;
				case PAGE_LOGIN:
					$location = $lang['Logging_on'];
					$location_url = "index.$phpEx";
					break;
				case PAGE_SEARCH:
					$location = $lang['Searching_forums'];
					$location_url = "search.$phpEx";
					break;
				case PAGE_PROFILE:
					$location = $lang['Viewing_profile'];
					$location_url = "index.$phpEx";
					break;
				case PAGE_VIEWONLINE:
					$location = $lang['Viewing_online'];
					$location_url = "viewonline.$phpEx";
					break;
				case PAGE_VIEWMEMBERS:
					$location = $lang['Viewing_member_list'];
					$location_url = "memberlist.$phpEx";
					break;
				case PAGE_PRIVMSGS:
					$location = $lang['Viewing_priv_msgs'];
					$location_url = "privmsg.$phpEx";
					break;
				case PAGE_FAQ:
					$location = $lang['Viewing_FAQ'];
					$location_url = "faq.$phpEx";
					break;
				case PAGE_KB:
					$location = $lang['Viewing_KB'];
					$location_url = "kb.$phpEx";
					break;	
				case PAGE_RECENT:
					$location = $lang['Recent_topics'];
					$location_url = "recent.$phpEx";
					break;	
				case PAGE_SHOUTBOX:
					$location = $lang['Shoutbox'];
					$location_url = "shoutbox_max.$phpEx";
					break;	
				case PAGE_STAFF:
					$location = $lang['Staff'];
					$location_url = "staff.$phpEx";
					break;	
				case PAGE_ALBUM:
					$location = $lang['Online_album_index'];
					$location_url = "album.$phpEx";
					break;
				case PAGE_ALBUM_PERSONAL:
					$location = $lang['Online_album_personal'];
					$location_url = "album.$phpEx";
					break;
				case PAGE_ALBUM_PICTURE:
					$location = $lang['Online_album_picture'];
					$location_url = "album.$phpEx";
					break;
				case PAGE_ALBUM_SEARCH:
					$location = $lang['Online_album_search'];
					$location_url = "album_search.$phpEx";
					break;
				case PAGE_DOWNLOAD:
					$location = $lang['Viewing_Download'];
					$location_url = "dload.$phpEx";
					break;	
				// Start add - Complete banner MOD
				case PAGE_REDIRECT: 
					require_once($phpbb_root_path . 'language/lang_' . $board_config['default_lang'] . '/lang_banner.' . $phpEx);
				
					if ($session_topic > 0)
					{
						$sql = "SELECT banner_description FROM " . BANNERS_TABLE . " WHERE banner_id=" . $session_topic;
						if ( $result2 = $db->sql_query($sql) )
						{
							$banner_data = $db->sql_fetchrow($result2);
						}
						else
						{	
							message_die(GENERAL_ERROR, 'Could not obtain redirect online information', '', __LINE__, __FILE__, $sql);
						}
						$location_url = append_sid("redirect.$phpEx?banner_id=" . $session_topic);
						$location = $lang['Left_via_banner'] .' --> '. phpbb_profile_text(isset($banner_data['banner_description']) ? $banner_data['banner_description'] : '');
					} else
					{
						$location_url = "";
						$location = $lang['Left_via_banner'];
					}
					break;
				// End add - Complete banner MOD	
				// Start add - Who viewed a topic MOD
				case PAGE_TOPIC_VIEW: 
				$visible_location = phpbb_online_topic_location($online_context, $session_page, $session_topic);
				$location = $visible_location['label']; $location_url = $visible_location['url'];
				break;
				// End add - Who viewed a topic MOD
				default:
					$location = $lang['Forum_index'];
					$location_url = "index.$phpEx";
			}
		}

		$row_color = ( $$which_counter % 2 ) ? $theme['td_color1'] : $theme['td_color2'];
		$row_class = ( $$which_counter % 2 ) ? $theme['td_class1'] : $theme['td_class2'];

		$template->assign_block_vars("$which_row", array(
			'ROW_COLOR' => '#' . $row_color,
			'ROW_CLASS' => $row_class,
			'USERNAME' => $username,
			'LASTUPDATE' => create_date($board_config['default_dateformat'], $row['session_time'], $board_config['board_timezone']),
			'FORUM_LOCATION' => $location,

			'U_USER_PROFILE' => ($user_id != ANONYMOUS) ? append_sid("profile.$phpEx?mode=viewprofile&amp;" . POST_USERS_URL . '=' . intval($user_id)) : '',
			'U_FORUM_LOCATION' => append_sid($location_url))
		);

		$$which_counter++;
	}
}

if( $registered_users == 0 )
{
	$l_r_user_s = $lang['Reg_users_zero_online'];
}
else if( $registered_users == 1 )
{
	$l_r_user_s = $lang['Reg_user_online'];
}
else
{
	$l_r_user_s = $lang['Reg_users_online'];
}

if( $hidden_users == 0 )
{
	$l_h_user_s = $lang['Hidden_users_zero_online'];
}
else if( $hidden_users == 1 )
{
	$l_h_user_s = $lang['Hidden_user_online'];
}
else
{
	$l_h_user_s = $lang['Hidden_users_online'];
}

if( $guest_users == 0 )
{
	$l_g_user_s = $lang['Guest_users_zero_online'];
}
else if( $guest_users == 1 )
{
	$l_g_user_s = $lang['Guest_user_online'];
}
else
{
	$l_g_user_s = $lang['Guest_users_online'];
}

$template->assign_vars(array(
	'TOTAL_REGISTERED_USERS_ONLINE' => sprintf($l_r_user_s, $registered_users) . sprintf($l_h_user_s, $hidden_users), 
	'TOTAL_GUEST_USERS_ONLINE' => sprintf($l_g_user_s, $guest_users))
);

if ( $registered_users + $hidden_users == 0 )
{
	$template->assign_vars(array(
		'L_NO_REGISTERED_USERS_BROWSING' => $lang['No_users_browsing'])
	);
}

if ( $guest_users == 0 )
{
	$template->assign_vars(array(
		'L_NO_GUESTS_BROWSING' => $lang['No_users_browsing'])
	);
}

$template->pparse('body');

include($phpbb_root_path . 'includes/page_tail.'.$phpEx);

?>
