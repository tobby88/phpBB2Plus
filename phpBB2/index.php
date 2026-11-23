<?php
/***************************************************************************
 *                                index.php
 *                            -------------------
 *   begin                : Saturday, Feb 13, 2001
 *   copyright            : (C) 2001 The phpBB Group
 *   email                : support@phpbb.com
 *
 *   $Id: index.php,v 1.99.2.2 2004/03/01 15:56:52 psotfx Exp $
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
require_once $phpbb_root_path . 'includes/functions_daily_activity.' . $phpEx;
//-- add
include_once($phpbb_root_path . 'includes/functions_announces.'. $phpEx);
//-- fin mod : announces ---------------------------------------------------------------------------

// Arcade score protocol handlers
if (!empty($HTTP_GET_VARS['act']) && $HTTP_GET_VARS['act'] == 'Arcade')
{
	require 'newscore.' . $phpEx;
	exit;
}

if ((!empty($HTTP_GET_VARS['autocom']) && $HTTP_GET_VARS['autocom'] == 'arcade') ||
	(!empty($HTTP_POST_VARS['autocom']) && $HTTP_POST_VARS['autocom'] == 'arcade'))
{
	require 'IBProArcade.' . $phpEx;
	exit;
}

if (!empty($HTTP_POST_VARS['func']))
{
	require 'pnFlashGames.' . $phpEx;
	exit;
}

//
// Start session management
//
$userdata = session_pagestart($user_ip, PAGE_INDEX);
init_userprefs($userdata);
//
// End session management
//

include_once($phpbb_root_path.'includes/functions_color_groups.'.$phpEx);
color_groups_setup_list();

$viewcat = ( !empty($_GET[POST_CAT_URL]) ) ? $_GET[POST_CAT_URL] : -1;
$viewcat = intval($viewcat);
if ($viewcat <= 0) $viewcat = -1;
$viewcatkey = ($viewcat < 0) ? 'Root' : POST_CAT_URL . $viewcat;
if( isset($_GET['mark']) || isset($_POST['mark']) )
{
	$mark_read = ( isset($_POST['mark']) ) ? $_POST['mark'] : $_GET['mark'];
}
else
{
	$mark_read = '';
}

//
// Handle marking posts
//
if( $mark_read == 'forums' )
{
	if ( $viewcat < 0 )
	{
		if( $userdata['session_logged_in'] )
		{
			phpbb_setcookie($board_config['cookie_name'] . '_f_all', time(), 0, $board_config['cookie_path'], $board_config['cookie_domain'], $board_config['cookie_secure']);
		}
	
		$template->assign_vars(array(
			"META" => '<meta http-equiv="refresh" content="3;url='  .append_sid("index.$phpEx") . '">')
		);
	}
	else
	{
		if( $userdata['session_logged_in'] )
		{
			// get the list of object authorized
			$keys = array();
			$keys = get_auth_keys($viewcatkey);

			// mark each forums
			for ($i=0; $i < count($keys['id']); $i++) if ($tree['type'][ $keys['idx'][$i] ] == POST_FORUM_URL)
			{
				$forum_id = $tree['id'][ $keys['idx'][$i] ];
				$sql = "SELECT MAX(post_time) AS last_post FROM " . POSTS_TABLE . " WHERE forum_id = $forum_id";
				if ( !($result = $db->sql_query($sql)) ) message_die(GENERAL_ERROR, 'Could not obtain forums information', '', __LINE__, __FILE__, $sql);
				if ( $row = $db->sql_fetchrow($result) )
				{
					$tracking_forums = ( isset($HTTP_COOKIE_VARS[$board_config['cookie_name'] . '_f']) ) ? phpbb_tracking_cookie_array($HTTP_COOKIE_VARS[$board_config['cookie_name'] . '_f']) : array();
					$tracking_topics = ( isset($HTTP_COOKIE_VARS[$board_config['cookie_name'] . '_t']) ) ? phpbb_tracking_cookie_array($HTTP_COOKIE_VARS[$board_config['cookie_name'] . '_t']) : array();

					if ( ( count($tracking_forums) + count($tracking_topics) ) >= 150 && empty($tracking_forums[$forum_id]) )
					{
						asort($tracking_forums);
						unset($tracking_forums[key($tracking_forums)]);
					}

					if ( $row['last_post'] > $userdata['user_lastvisit'] )
					{
						$tracking_forums[$forum_id] = time();
						phpbb_setcookie($board_config['cookie_name'] . '_f', serialize($tracking_forums), 0, $board_config['cookie_path'], $board_config['cookie_domain'], $board_config['cookie_secure']);
					}
				}
			}
		}

		$template->assign_vars(array(
			"META" => '<meta http-equiv="refresh" content="3;url='  .append_sid("index.$phpEx?" . POST_CAT_URL . "=$viewcat") . '">')
		);
	}
	$message = $lang['Forums_marked_read'] . '<br /><br />' . sprintf($lang['Click_return_index'], '<a href="' . append_sid("index.$phpEx") . '">', '</a> ');

	message_die(GENERAL_MESSAGE, $message);
}
//
// End handle marking posts
//

//-- mod : categories hierarchy --------------------------------------------------------------------
//-- delete
// $tracking_topics = ( isset($HTTP_COOKIE_VARS[$board_config['cookie_name'] . '_t']) ) ? unserialize($HTTP_COOKIE_VARS[$board_config['cookie_name'] . "_t"]) : array();
// $tracking_forums = ( isset($HTTP_COOKIE_VARS[$board_config['cookie_name'] . '_f']) ) ? unserialize($HTTP_COOKIE_VARS[$board_config['cookie_name'] . "_f"]) : array();
//-- fin mod : categories hierarchy ----------------------------------------------------------------

//
// If you don't use these stats on your index you may want to consider
// removing them
//
//-- mod : categories hierarchy --------------------------------------------------------------------
//-- delete
// $total_posts = get_db_stat('postcount');
// $total_users = get_db_stat('usercount');
//-- add
include_once($phpbb_root_path . 'includes/mods_settings/mod_categories_hierarchy.' . $phpEx);
if ( ($board_config['display_viewonline'] == 2) || ( ($viewcat < 0) && ($board_config['display_viewonline'] == 1) ) )
{
	if ( empty($board_config['max_posts']) || empty($board_config['max_users']) )
	{
		board_stats();
	}
	$total_posts = $board_config['max_posts'];
	$total_users = $board_config['max_users'];
//-- fin mod : categories hierarchy ----------------------------------------------------------------
	$newest_userdata = get_db_stat('newestuser');
	$newest_user = $newest_userdata['username'];
	$newest_uid = $newest_userdata['user_id'];
	
	if( $total_posts == 0 )
	{
		$l_total_post_s = $lang['Posted_articles_zero_total'];
	}
	else if( $total_posts == 1 )
	{
		$l_total_post_s = $lang['Posted_article_total'];
	}
	else
	{
		$l_total_post_s = $lang['Posted_articles_total'];
	}
	
	if( $total_users == 0 )
	{
		$l_total_user_s = $lang['Registered_users_zero_total'];
	}
	else if( $total_users == 1 )
	{
		$l_total_user_s = $lang['Registered_user_total'];
	}
	else
	{
		$l_total_user_s = $lang['Registered_users_total'];
	}

//-- mod : categories hierarchy --------------------------------------------------------------------
//-- add
}
//-- fin mod : categories hierarchy ----------------------------------------------------------------

//
// Start page proper
//
//-- mod : categories hierarchy --------------------------------------------------------------------
//-- delete
//-- fin mod : categories hierarchy ----------------------------------------------------------------
require_once $phpbb_root_path . 'includes/functions_link_rotation.' . $phpEx;
$link_index = phpbb_link_index_logo($db, !empty($plus_config['show_links']));
$link_self_img = $link_index['logo'];
$site_logo_width = $link_index['width'];
$site_logo_height = $link_index['height'];
if (!empty($plus_config['show_links']))
{
	if (file_exists($phpbb_root_path . 'language/lang_' . $board_config['default_lang'] . "/lang_main_link.".$phpEx))
	{
		include_once($phpbb_root_path . 'language/lang_' . $board_config['default_lang'] . "/lang_main_link.".$phpEx);
	}
	elseif (file_exists($phpbb_root_path . "language/lang_english/lang_main_link.".$phpEx))
	{
		include_once($phpbb_root_path . "language/lang_english/lang_main_link.".$phpEx);
	}
	else
	{
		message_die(GENERAL_ERROR, "Unable to find a suitable language file for Advanced Links Mod", '');
	}

    $template->assign_block_vars('switch_show_links', array());
}
$l_today_text = '';
$l_today_users = '';
$users_today_list = '';
$birthday_today_list = '';
$birthday_week_list = '';

// Birthday Mod, Show users with birthday
$show_online_stats = (int) $board_config['display_viewonline'] === 2
	|| ($viewcat < 0 && (int) $board_config['display_viewonline'] === 1);
if ($show_online_stats && $board_config['birthday_check_day'] > 0)
{
	$cache_data_file = $phpbb_root_path . 'cache/birthday_data_' . md5((string) $board_config['board_timezone']) . '.cache';
	if (@is_file($cache_data_file)  && empty($SID))
	{
		$valid = (date('YmdH',time()) - date('YmdH',@filemtime($cache_data_file))<1) ? true : false;
	} else
	{
	   $valid = false;
	}
	
	if ($valid )
	{
	   $birthday_cache = phpbb_data_cache_read($cache_data_file);
	   if (is_array($birthday_cache) && isset($birthday_cache['today'], $birthday_cache['week']))
	   {
		  $birthday_today_list = (string) $birthday_cache['today'];
		  $birthday_week_list = (string) $birthday_cache['week'];
	   }
	   else
	   {
		  $valid = false;
	   }
	}
	if (!$valid)
	{
	   $sql = ($board_config['birthday_check_day']) ? "SELECT user_id, username, user_birthday,user_level FROM " . USERS_TABLE. " WHERE user_birthday!=999999 ORDER BY username" :"";
	   if($result = $db->sql_query($sql))
	   {
		  if (!empty($result))
		  {
			 $time_now = time();
			 $this_year = create_date('Y', $time_now, $board_config['board_timezone']);
			 $date_today = create_date('Ymd', $time_now, $board_config['board_timezone']);
			 $date_forward = create_date('Ymd', $time_now+($board_config['birthday_check_day']*86400), $board_config['board_timezone']);
				while ($birthdayrow = $db->sql_fetchrow($result))
			 {
				   $user_birthday2 = $this_year.($user_birthday = realdate("md",$birthdayrow['user_birthday'] ));
				   if ( $user_birthday2 < $date_today ) $user_birthday2 += 10000;
				if ( $user_birthday2 > $date_today  && $user_birthday2 <= $date_forward )
				{
				   // user are having birthday within the next days
				   $user_age = ( $this_year.$user_birthday < $date_today ) ? $this_year - realdate ('Y',$birthdayrow['user_birthday'])+1 : $this_year- realdate ('Y',$birthdayrow['user_birthday']);
				   $style_color = color_group_colorize_name($birthdayrow['user_id'],true);
								 $birthday_week_list .= ' <a href="' . append_sid("profile.$phpEx?mode=viewprofile&amp;" . POST_USERS_URL . "=" . $birthdayrow['user_id']) . '" class="gensmall">' . $style_color . ' ('.$user_age.')</a>,'; 
				} else if ( $user_birthday2 == $date_today )
				   {
				   //user have birthday today
				   $user_age = $this_year - realdate ( 'Y',$birthdayrow['user_birthday'] );
				   $style_color = color_group_colorize_name($birthdayrow['user_id'],true);
				   $birthday_today_list .= ' <a href="' . append_sid("profile.$phpEx?mode=viewprofile&amp;" . POST_USERS_URL . "=" . $birthdayrow['user_id']) . '" class="gensmall">' . $style_color . ' ('.$user_age.')</a>,';
				   }
			 }
			 if ($birthday_today_list) $birthday_today_list[ strlen( $birthday_today_list)-1] = ' ';
			 if ($birthday_week_list) $birthday_week_list[ strlen( $birthday_week_list)-1] = ' ';
		  }
		  $db->sql_freeresult($result);
		  if (empty($SID))
		  {
			 // stores the data set in a cache file
			 phpbb_data_cache_write($cache_data_file, array(
				'today' => $birthday_today_list,
				'week' => $birthday_week_list,
			 ));
		  }
	   }
	}
	$template->assign_block_vars('switch_show_birthday', array());
} 

$birthday_today_list = stripslashes($birthday_today_list);
$birthday_week_list = stripslashes($birthday_week_list);

if ($show_online_stats && (int) $plus_config['show_last_visit'] !== 0)
{
	$visitors = phpbb_daily_visitors($db, time(), $board_config['board_timezone'],
		(int) $plus_config['show_last_visit'] === 2, (int) $userdata['user_level'] === ADMIN);
	$users_today_list = $visitors['names'];
	$l_today_users = $visitors['summary'];
	$l_today_text = $visitors['last_hour'];
	$template->assign_block_vars('switch_show_lastvisit', array());
}

if ($plus_config['index_layout'] == 'index_body_plus.tpl')
{
	$registrations = phpbb_daily_registrations($db, time(), $userdata['user_timezone']);
	$today_registered_users = $registrations['today'];
	$yesterday_registered_users = $registrations['yesterday'];
}
	//
	// Start output of page
	//
	//-- mod : categories hierarchy --------------------------------------------------------------------
//-- add
// set the parm of the mark read func
$mark = ($viewcat == -1 ) ? '' : '&' . POST_CAT_URL . '=' . $viewcat;
// monitor the board statistic
if (($board_config['display_viewonline'] == 2) || (($viewcat < 0) && ($board_config['display_viewonline'] == 1)))
{
//-- fin mod : categories hierarchy ---------------------------------------------------------------- 
	define('SHOW_ONLINE', true);
//-- mod : categories hierarchy --------------------------------------------------------------------
//-- add
}
//-- fin mod : categories hierarchy ----------------------------------------------------------------
	$page_title = $lang['Index'];
	include($phpbb_root_path . 'includes/page_header.'.$phpEx);

	$template->set_filenames(array(
		'body' => $plus_config['index_layout'])
	);
	
	if ($plus_config['index_layout'] == 'index_body_plus.tpl')
	{
        	$template->assign_vars(array(
				// Start add - Fully integrated shoutbox MOD
				'U_SHOUTBOX' => append_sid("shoutbox.$phpEx"),
				'L_SHOUTBOX' => $lang['Shoutbox'],
				'U_SHOUTBOX_MAX' => append_sid("shoutbox_max.$phpEx"),
				'TOTAL_USERS' => $total_users,
				'TOTAL_POSTS' => $total_posts,
				'NEWEST_USER' => sprintf($lang['Newest_user_plus'], '<a href="' . append_sid("profile.$phpEx?mode=viewprofile&amp;" . POST_USERS_URL . "=$newest_uid") . '">', $newest_user, '</a>'),
				'TODAY_USERS' => $today_registered_users,
				'YESTERDAY_USERS' => $yesterday_registered_users,
				'USERS_TODAY_LIST' => $users_today_list,
				'GUESTS_ONLINE' => $guests_online,
				'REGGED_ONLINE' => $logged_visible_online,
				'L_FORUM' => $lang['Forum'],
				'L_TOPICS' => $lang['Topics'],
				'L_REPLIES' => $lang['Replies'],
				'L_VIEWS' => $lang['Views'],
				'L_POSTS' => $lang['Posts'],
				'L_LASTPOST' => $lang['Last_Post'], 
				'L_LAST_VISIT' => $lang['Last_Visit'],
				'L_NO_NEW_POSTS' => $lang['No_new_posts'],
				'L_NEW_POSTS' => $lang['New_posts'],
				'L_NO_NEW_POSTS_LOCKED' => $lang['No_new_posts_locked'], 
				'L_NEW_POSTS_LOCKED' => $lang['New_posts_locked'], 
				'L_ONLINE_EXPLAIN' => $lang['Online_explain'], 
				'FORUM_IMG' => $images['forum'],
				'FORUM_NEW_IMG' => $images['forum_new'],
				'FORUM_LOCKED_IMG' => $images['forum_locked'],
				'L_WHOSBIRTHDAY_WEEK' => ($board_config['birthday_check_day'] > 1) ? sprintf( (($birthday_week_list) ? $lang['Birthday_week'] : $lang['Nobirthday_week']), $board_config['birthday_check_day']).$birthday_week_list : '',
				'L_WHOSBIRTHDAY_TODAY' => ($board_config['birthday_check_day']) ? ($birthday_today_list) ? $lang['Birthday_today'].$birthday_today_list : $lang['Nobirthday_today'] : '',
				'L_USERS_LASTHOUR' => $l_today_text,
				'L_USERS_TODAY' =>$l_today_users,
				'L_LINKS' => $lang['Site_links'],
				'U_LINKS' => append_sid("links.$phpEx"),
				'U_LINKS_JS' => "links.js.$phpEx",
				'U_SITE_LOGO' => $link_self_img,
				'SITE_LOGO_WIDTH' => $site_logo_width,
				'SITE_LOGO_HEIGHT' => $site_logo_height,
				'L_LIVE_STATS' => $lang['Live_Statistics'],
				'L_MEMBERS' => $lang['Top_Member'],
				'L_LATEST' => $lang['Latest_Member'],
				'L_NEW_TODAY' => $lang['New_Today'],
				'L_NEW_YESTERDAY' => $lang['New_Yesterday'],
				'L_MEMBERS_OVERALL' => $lang['Members_Overall'],
				'L_ONLINE_NOW' => $lang['Online_Now'],
				'L_GUESTS' => $lang['Guests_P'],
				'L_MEMBERS' => $lang['Members_P'],
				'L_STATS' => $lang['Box_Stats'],
				'L_USER_RECORD' => $lang['User_Record'],
				'L_TOTAL_POSTS' => $lang['Total_posts'],
				'L_BIRTHDAYS' => $lang['Birthdays_P'],
				'L_FORUM_LOCKED' => $lang['Forum_is_locked'],
				'L_MODERATOR' => $lang['Moderators'],
				'L_MARK_FORUMS_READ' => $lang['Mark_all_forums'],
				'U_MARK_READ' => append_sid("index.$phpEx?mark=forums$mark"),
				'U_SEND_PASSWORD' => append_sid("profile.$phpEx?mode=sendpassword"),
				'L_ONLINE_MEMBERS' => $lang['Online_Members_P']) 
        	);
        }
	else
	{
	$template->assign_vars(array(
		'TOTAL_POSTS' => sprintf($l_total_post_s, $total_posts),
		// Start add - Fully integrated shoutbox MOD
		'U_SHOUTBOX' => append_sid("shoutbox.$phpEx"),
		'L_SHOUTBOX' => $lang['Shoutbox'],
		'U_SHOUTBOX_MAX' => append_sid("shoutbox_max.$phpEx"),
		'TOTAL_USERS' => sprintf($l_total_user_s, $total_users),
		'NEWEST_USER' => sprintf($lang['Newest_user'], '<a href="' . append_sid("profile.$phpEx?mode=viewprofile&amp;" . POST_USERS_URL . "=$newest_uid") . '">', $newest_user, '</a>'), 
		'L_USERS_LASTHOUR' => $l_today_text,
		'FORUM_IMG' => $images['forum'],
		'FORUM_NEW_IMG' => $images['forum_new'],
		'FORUM_LOCKED_IMG' => $images['forum_locked'],
		// Start add - Last visit MOD
		'USERS_TODAY_LIST' => $users_today_list,
		
		'L_USERS_TODAY' =>$l_today_users,
		// End add - Last visit MOD
		
		// Start add - Birthday MOD
		'L_WHOSBIRTHDAY_WEEK' => ($board_config['birthday_check_day'] > 1) ? sprintf( (($birthday_week_list) ? $lang['Birthday_week'] : $lang['Nobirthday_week']), $board_config['birthday_check_day']).$birthday_week_list : '',
		'L_WHOSBIRTHDAY_TODAY' => ($board_config['birthday_check_day']) ? ($birthday_today_list) ? $lang['Birthday_today'].$birthday_today_list : $lang['Nobirthday_today'] : '',
		// End add - Birthday MOD
		
		'L_FORUM' => $lang['Forum'],
		'L_TOPICS' => $lang['Topics'],
		'L_REPLIES' => $lang['Replies'],
		'L_VIEWS' => $lang['Views'],
		'L_POSTS' => $lang['Posts'],
		'L_LASTPOST' => $lang['Last_Post'], 
		'L_NO_NEW_POSTS' => $lang['No_new_posts'],
		'L_NEW_POSTS' => $lang['New_posts'],
		'L_NO_NEW_POSTS_LOCKED' => $lang['No_new_posts_locked'], 
		'L_NEW_POSTS_LOCKED' => $lang['New_posts_locked'], 
		'L_ONLINE_EXPLAIN' => $lang['Online_explain'], 
		'L_LINKS' => $lang['Site_links'],
		'U_LINKS' => append_sid("links.$phpEx"),
		'U_LINKS_JS' => "links.js.$phpEx",
		'U_SITE_LOGO' => $link_self_img,
		'SITE_LOGO_WIDTH' => $site_logo_width,
		'SITE_LOGO_HEIGHT' => $site_logo_height,
		'L_MODERATOR' => $lang['Moderators'], 
		'L_FORUM_LOCKED' => $lang['Forum_is_locked'],
		'L_MARK_FORUMS_READ' => $lang['Mark_all_forums'], 
		//-- mod : categories hierarchy --------------------------------------------------------------------
		// here we added
		//	$mark
		//-- modify
		'U_MARK_READ' => append_sid("index.$phpEx?mark=forums$mark"))
	);
}
//-- mod : announces -------------------------------------------------------------------------------
//-- add
	// categories hierarchy v 2 compliancy
	if (empty($viewcatkey) && ($viewcat > -1))
	{
		$viewcatkey = POST_CAT_URL . $viewcat;
	}
	else
	{
		if (empty($viewcatkey)) $viewcatkey = 'Root';
	}
	announces_from_forums($viewcatkey);
//-- fin mod : announces ---------------------------------------------------------------------------

	//
	// Okay, let's build the index
	//
	//-- mod : categories hierarchy --------------------------------------------------------------------

// don't display the board statistics
if ( ($board_config['display_viewonline'] == 2) || ( ($viewcat < 0) && ($board_config['display_viewonline'] == 1) ) )
{
	$template->assign_block_vars('disable_viewonline', array());
}

// display the index
$display = display_index($viewcatkey);
if ( ($plus_config['show_shoutbox'] == 1 || $plus_config['show_shoutbox'] == 5 ) || ( ($plus_config['show_shoutbox'] == 2 || $plus_config['show_shoutbox'] == 6 ) && ( $userdata['session_user_id'] != ANONYMOUS ) ) )
{
        $template->assign_block_vars('switch_show_shoutbox', array());
}
if ( !$display )
//-- fin mod : categories hierarchy ----------------------------------------------------------------
{
	message_die(GENERAL_MESSAGE, $lang['No_forums']);
}

//
// Generate the page
//
$template->pparse('body');

include($phpbb_root_path . 'includes/page_tail.'.$phpEx);

?>
