<?php
/***************************************************************************
 *
 *                               activity.php
 *                            -------------------
 *   begin                : Tuesday, Jan 2nd, 2007
 *   copyright            : (c) 2003-2007 dEfEndEr www.phpbb-arcade.com
 *   email                : support@phpbb-arcade.com
 *
 *   $Id: activity.php,v 2.1.8 2007/01/02 dEfEndEr Exp $
 *   Support @ http://www.phpbb-arcade.com
 *   v 2.0.0 2003/12/12 12:59:59 Napoleon
 *
 ***************************************************************************
 *
 *   This program is free software; you can redistribute it and/or modify
 *   it under the terms of the GNU General Public License as published by
 *   the Free Software Foundation; either version 2 of the License, or
 *   (at your option) any later version.
 *
 ***************************************************************************
 *
 *   This is a MOD for phpbb v2.0.+ The phpbb group has all rights to the 
 *   phpbb source. They can be contacted at :
 *   
 *      I-Net : www.phpbb.com
 *      E-Mail: support@phpbb.com
 *
 ***************************************************************************
 * 	CREDITS: 
 *  Napoleon - Original Activity Mod v2.0.0
 *  Painkiller - Add-On's, Alpha Testing & Support
 *  wizzzzzzzz - Dutch Support/Translation & Alpha Testing
 *  femu - German Support/Translation
 *  Mark - Add-On & Support
 *  Minesh - Add-On's & Support
 *  
 ***************************************************************************/

define('IN_PHPBB', true);
if(isset($_GET['phpbb_root_path']))
{
	die("Hacking attempt");
}
$phpbb_root_path = './';

include($phpbb_root_path . 'extension.inc');

$filename = basename(__FILE__);
$phpEx    = substr(strrchr(__FILE__, '.'), 1);

include_once($phpbb_root_path . 'common.'.$phpEx);
include_once($phpbb_root_path . 'includes/functions_arcade.'.$phpEx);
include_once($phpbb_root_path . 'includes/bbcode.' .$phpEx);
//
// Check the mod has been installed correctly.
//
if (!defined('iNA') || !defined('iNA_LOG'))
{
	message_die(GENERAL_ERROR, $lang['arcade_incorrect_install']);
}
//
//  Check Version Info
//
if ($arcade->version() < ARCADE_VERSION)
{
	$arcade->message_die(GENERAL_ERROR, sprintf($lang['arcade_incorrect_version'], $arcade->version, ARCADE_VERSION));
}
// Start session management
$userdata = session_pagestart($user_ip, PAGE_ACTIVITY);
init_userprefs($userdata);
$page_title = $lang['Arcade']; // This is Hard Coded as it is a NAME.
$user_id = $userdata['user_id'];
// End session management
//
//  Check System ONLINE.
//
if($arcade->arcade_config['games_offline'] && $userdata['user_level'] != ADMIN && $userdata['user_level'] != MOD)
{
	$arcade->message_die(GENERAL_MESSAGE, $lang['games_are_offline'], $lang['Information']);
}
//
//  Check for Guest Access
//
if (!$userdata['session_logged_in'] && $user_id == ANONYMOUS && $arcade->arcade_config['games_no_guests'])
{
	$header_location = ( @preg_match("/Microsoft|WebSTAR|Xitami/", getenv("SERVER_SOFTWARE")) ) ? "Refresh: 0; URL=" : "Location: ";
	header($header_location . append_sid("login.$phpEx?redirect=$filename", true));
	exit;
}
//
//  Check for a Banned User
//
if($arcade->arcade_config['games_mod_ban_users'] && $userdata['user_level'] != ADMIN)
{
  switch($userdata['arcade_banned'])
  {
    case 1:
    	$arcade->message_die(GENERAL_ERROR, $lang['arcade_user_held']);
      break;

    case 2:
     	$arcade->message_die(GENERAL_ERROR, $lang['arcade_user_banned']);
      break;

    default:
      break;
  }
}
//
//  Check MAIN Arcade Rank and Group Access :_)
//
if(intval($userdata['user_rank']) < intval($arcade->arcade_config['games_rank_required']))
{
	$arcade->message_die(GENERAL_MESSAGE, $lang['games_group_rank_limit'], $lang['Information']);
}
if(!empty($arcade->arcade_config['games_group_required']))
{
	$sql = "SELECT user_id FROM " . USER_GROUP_TABLE . "
		WHERE group_id = " . $arcade->arcade_config['games_group_required'] . "
    AND user_id = " . $userdata['user_id'];
  $result = $db->sql_query($sql);
  $group_info = $db->sql_fetchrowset($result);
  if(count($group_info) < 1)
  {
  	$arcade->message_die(GENERAL_MESSAGE, $lang['games_group_rank_limit'], $lang['Information']);
  }
}
//
//  Tell phpBB to show the user
//
define('SHOW_ONLINE', true);
//
//  Set Guest ONLY Access OFF
//
$GUEST_ONLY = FALSE;
//
//  Check for rewards option and Initilize
//
if ($arcade->arcade_config['use_rewards_mod'])
{
	if ( $arcade->arcade_config['use_point_system'] )
	{
		include($phpbb_root_path . 'includes/functions_points.'.$phpEx);
	}
	if ( $arcade->arcade_config['use_cash_system'] || $arcade->arcade_config['use_allowance_system'] )
	{
		include($phpbb_root_path . 'includes/rewards_api.'.$phpEx);
	}
}
//
//  Check to see if we are looking at a Catagory
//
$arcade->cat_id = $arcade->pass_var("cat_id", 0);
//
//  Look for a Search Word and set MODE/GAME
//
if (($search_word = $arcade->pass_var('search_word', '')) != FALSE)
{
	$mode = 'search';
}
else if (($mode = $arcade->pass_var('mode', '')) == FALSE)
{
	if( isset($HTTP_POST_VARS['game']) )
	{
		$mode = 'game';
	}
	else if( isset($HTTP_POST_VARS['stats']) )
	{
		$mode = 'stats';
	}
	else
	{
		$mode = '';
	}
}
//
//  Set Operation Mode and check to see what page we are on, and the listing type etc.
//
$arcade->sort_mode  = $arcade->pass_var('sort_mode', '');
$start              = $arcade->pass_var('start', 0);
$arcade->start      = $start;

if (($arcade->sort_order = $arcade->pass_var('order', '')) == FALSE)
{
  if($mode != 'fav')
  {
  	$arcade->sort_order = $arcade->arcade_config['default_sort_order'];
  }
  else
  {
    $arcade->sort_order = 'ASC';
    $arcade->sort_mode = 'alphabetical';
  }
}
//
//  Now we have a Guest, Set-Up Guest Access
//
if(($userdata['user_id'] == ANONYMOUS) && empty($arcade->sort_mode) && ($arcade->sort_order != 'ASC'))
{
  $arcade->sort_mode = 'allow_guest';
  $arcade->sort_order = 'DESC';
	$GUEST_ONLY = TRUE;
}
else if(empty($arcade->sort_mode))
{
  $arcade->sort_mode = $arcade->arcade_config['default_sort'];
}
$select_sort_mode = '<select name="sort_mode">';
for($i = 0; $i < count($mode_types_text); $i++)
{
	$selected = ( $arcade->sort_mode == $mode_types[$i] ) ? ' selected="selected"' : '';
	$select_sort_mode .= '<option value="' . $mode_types[$i] . '"' . $selected . '>' . $mode_types_text[$i] . '</option>';
}
$select_sort_mode .= '</select>';

$select_sort_order = '<select name="order">';
if($arcade->sort_order == 'ASC')
{
	$select_sort_order .= '<option value="DESC">' . $lang['Sort_Descending'] . '</option><option value="ASC" selected="selected">' . $lang['Sort_Ascending'] . '</option>';
}
else
{
	$select_sort_order .= '<option value="DESC" selected="selected">' . $lang['Sort_Descending'] . '</option><option value="ASC">' . $lang['Sort_Ascending'] . '</option>';
}
$select_sort_order .= '</select>';

$order_by = $arcade->pass_mode($arcade->sort_mode);
//
//  Get User Information (Group Membership, Rank and Level)
//  ready for the Activies Passing
//
$level_required = isset($userdata['user_level']) ? $userdata['user_level'] : 0;
$rank_required  = isset($userdata['user_rank']) ? $userdata['user_rank'] : 0;
$sql = "SELECT g.group_id
	FROM " . GROUPS_TABLE . " g, " . USER_GROUP_TABLE . " ug
   	WHERE ug.user_id = " . $userdata['user_id'] . "  
	    AND ug.group_id = g.group_id
			AND ug.user_pending = 0
			AND g.group_single_user <> " . TRUE . "
 			ORDER BY g.group_name, ug.user_id";
if ( !($result = $db->sql_query($sql)) )
{
	$arcade->message_die(GENERAL_ERROR, 'Error getting group information', '', __LINE__, __FILE__, $sql);
}
$group_ids = $db->sql_fetchrowset($result);
//
//  Build a list of Groups that this user is a member of (add Group Zero)
//
$group_list = '0';
for ($group_count = 0; $group_count < count($group_ids); $group_count++)
{
   $group_list .= ', ' . $group_ids[$group_count]['group_id'];
}
$group_array = explode(', ', $group_list);
$cat_info = array('special_play' => 0, 'cat_name' => $lang['all_games']);
$mod_id = 0;
//
//  If Catagory is set, get Catagory Info.
//
if($arcade->cat_id > 0)
{
	$sql = "SELECT * FROM " . iNA_CAT . "
		WHERE cat_id = " . $arcade->cat_id;
	if(!$result = $db->sql_query($sql))
	{
		$arcade->message_die(GENERAL_ERROR, $lang['no_cat_data'], '', __LINE__, __FILE__, $sql);
	}
	$cat_info = $db->sql_fetchrow($result);
//
//  Does user have access to this Category??
//
  if(!in_array($cat_info['group_required'], $group_array))
  {
    message_die(GENERAL_MESSAGE, $lang['cats_no_access']);
  }
//
//  Is Category a LINK ?
//
	if($cat_info['cat_type'] == 'l')
	{
  	$sql = "UPDATE " . iNA_CAT . "
    	SET total_played = total_played+1
  		WHERE cat_id = " . $arcade->cat_id;
  	if(!$result = $db->sql_query($sql))
  	{
  		$arcade->message_die(GENERAL_ERROR, $lang['no_cat_update'], '', __LINE__, __FILE__, $sql);
  	}
  	$header_location = ( @preg_match("/Microsoft|WebSTAR|Xitami/", getenv("SERVER_SOFTWARE")) ) ? "Refresh: 0; URL=" : "Location: ";
    header($header_location . $cat_info['cat_desc'], true);
    exit;
  }
	$num_rows = $db->sql_numrows($result);
  if($num_rows < 1)
  {
		$arcade->message_die(GENERAL_MESSAGE, $lang['incorrect_category']);
  }
  $catagory_name = $cat_info['cat_name'];
	$mod_id = $cat_info['mod_id'];
	$page_title = $cat_info['cat_name'] . ' - ' . $page_title;
//
//  Is Category a SUB-Category ?
//
  if($cat_info['cat_type'] == 's')
  {
    $sql = "SELECT * FROM " . iNA_CAT . "
      WHERE cat_id = '" . $cat_info['cat_parent'] . "'";
  	if(!$result = $db->sql_query($sql))
  	{
  		$arcade->message_die(GENERAL_ERROR, $lang['no_cat_data'], '', __LINE__, __FILE__, $sql);
  	}
  	$cat_parent = $db->sql_fetchrow($result);

    $url = '&nbsp;&raquo;&nbsp;<a href="'. append_sid($filename) . '" class="nav">' . $lang['games_catagories'] . '</a> &raquo; <a href="' . $filename . '?mode=cat&amp;cat_id=' . $cat_parent['cat_id'] . '" class="nav">' . $cat_parent['cat_name'] . '</a> &raquo; <a href="' . $filename . '?mode=cat&amp;cat_id=' . $arcade->cat_id . '&amp;start=' . $start . '&amp;sort_mode=' . $arcade->sort_mode . '&amp;order=' . $arcade->sort_order . $SID .'" class="nav">' . $catagory_name . '</a>';
  }
  else
  {
    $url = '&nbsp;&raquo;&nbsp;<a href="'. append_sid($filename) . '" class="nav">' . $lang['games_catagories'] . '</a> &raquo; <a href="' . $filename . '?mode=cat&amp;cat_id=' . $arcade->cat_id . '&amp;start=' . $start . '&amp;sort_mode=' . $arcade->sort_mode . '&amp;order=' . $arcade->sort_order . '" class="nav">' . $catagory_name . '</a>';
  }
}
else
{
  $url = '&nbsp;&raquo;&nbsp;<a href="'. append_sid($filename) . '" class="nav">' . $lang['games_catagories'] . '</a> &raquo; <a href="' . $filename . '?mode=cat&amp;cat_id=' . $arcade->cat_id . '&amp;start=' . $start . '&amp;sort_mode=' . $arcade->sort_mode . '&amp;order=' . $arcade->sort_order . '" class="nav">' . $arcade->arcade_config['games_cat_zero'] . '</a>';
}
//
//	Mode will tell me what the user wants me to do.
//
if( $mode != '' )
{
  $sql_and = '';

	if(($game_id = $arcade->pass_var('id', 0)) > ($arcade->last_game_id()))
  {
    $arcade->message_die(GENERAL_ERROR, $lang['game_id_error']);
  }
//
//  Grab Game info from game_id
//
  if($game_id > 0)
  {
  	$sql = "SELECT g.*, f.fav_game_name FROM " . iNA_GAMES . " AS g
    	  LEFT JOIN " . iNA_FAV . " AS f ON g.game_name = f.fav_game_name AND f.user_id = " . $userdata['user_id'] . "
  		WHERE game_id = " . $game_id;
  	if(!$result = $db->sql_query($sql))
  	{
  		$arcade->message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
  	}
  	$game_info = $db->sql_fetchrow($result);
    $game_name = $game_info['game_name'];
  }
//
//	OK, you wanna play :)
//
	if ($mode == 'game')
	{
    if (($win = $arcade->pass_var('win', '')) == FALSE)
	  {
  		$gen_simple_header = TRUE;
      $win = '';   
    } 
    $session = update_ina_session($userdata['user_id'], $user_ip, PAGE_PLAYING_GAMES, $game_info['game_name'], '', $win);
		$game_charge = $game_info['game_charge'];
		$gamepath = 'loader.' . $phpEx . '?gid=' . $session;

		if(!$game_id)
		{
			$arcade->message_die(GENERAL_MESSAGE, $lang['no_game_data']);
		}
		if($userdata['user_id'] < 0)
		{
      $cookie = phpbb_setcookie($board_config['cookie_name'].'_arcade',$session,0,$board_config['cookie_path'],$board_config['cookie_domain'],$board_config['cookie_secure']);
      if($cookie == FALSE)
      {
      	$arcade->message_die(GENERAL_ERROR, $lang['no_cookie_data']); 
      }
    }

		if(((!$game_info['allow_guest'] && ($userdata['user_id'] == ANONYMOUS)) || (intval($userdata['user_posts']) < intval($arcade->arcade_config['games_posts_required'])) || (intval($userdata['user_rank']) < intval($game_info['rank_required']))) && $userdata['user_level'] != ADMIN && $userdata['user_id'] != MOD)
		{
			message_die(GENERAL_MESSAGE, $lang['games_no_guests'], $lang['Information']);
		}
		
		if($arcade->arcade_config['use_point_system'] && $arcade->arcade_config['use_rewards_mod'] && $game_charge > 0)
		{
			if ($userdata['user_points'] >= $game_charge && ( $userdata['user_id'] != ANONYMOUS ))
			{
				subtract_points($user_id,$game_charge);
      }
			else
			{
				message_die(GENERAL_MESSAGE, $lang['not_enough_points'], '', __LINE__, __FILE__, $sql);
			}
		}
		
		if(((($arcade->arcade_config['use_cash_system'] || $arcade->arcade_config['use_allowance_system']) && $arcade->arcade_config['use_rewards_mod'])) && $game_charge > 0 )
		{
			if ((get_reward($userdata['user_id'])) >= $game_charge && ( $userdata['user_id'] != ANONYMOUS ))
			{
				subtract_reward($user_id,$game_charge);
      }
			else
			{
				if(isset($arcade->arcade_config['use_cash_system']))
				{
          $cash_name = get_cash_name();
				  $not_enough_points = sprintf($lang['not_enough_points'], $cash_name);
				  
					message_die(GENERAL_MESSAGE, $not_enough_points, '', __LINE__, __FILE__, $sql);
				}
				else if(isset($board_config['points_name']))
				{
				  $not_enough_points = sprintf($lang['not_enough_points'], $board_config['points_name']);
					message_die(GENERAL_MESSAGE, $not_enough_points, '', __LINE__, __FILE__, $sql);
				}
				else
				{
					message_die(GENERAL_MESSAGE, $lang['not_enough_reward'], '', __LINE__, __FILE__, $sql);
				}
			}
		}
//
//	Stop unauth users from playing games.
//
    if (intval($userdata['user_posts']) < intval($arcade->arcade_config['games_posts_required']))
    {
      message_die(GENERAL_MESSAGE, $lang['games_not_enough_posts'], 'Information');
    }
    if(((!$game_info['allow_guest'] && ($userdata['user_id'] == ANONYMOUS)) || (intval($userdata['user_rank']) < intval($game_info['rank_required']))) && $userdata['user_level'] != ADMIN )
    {
    	$arcade->message_die(GENERAL_MESSAGE, $lang['games_no_guests'], 'Information');
    }
//		$header_location = ( @preg_match("/Microsoft|WebSTAR|Xitami/", getenv("SERVER_SOFTWARE")) ) ? "Refresh: 0; URL=" : "Location: ";
//		header($header_location . append_sid($gamepath, true));
		require($phpbb_root_path.'loader.'.$phpEx);
		exit;
	}
//
//	We are looking at the Information for the game.
//
	else if ($mode == 'stats')
	{
		$page_title = $game_info['game_desc'] . ' - ' . $board_config['sitename']; 
		$page_meta_desc = $page_title;
		$page_meta_key = trim(htmlspecialchars(str_replace(",-,",",",str_replace(" ", ",", $game_info['game_desc'] . ',' . $board_config['sitename']))));

		$url .= ' &raquo; <a href="' . $filename . '?mode=game&amp;id=' . $game_info['game_id'] . '&amp;win=self">' . $game_info['game_desc'] . '</a>';

		$arcade->sort_method($game_info['reverse_list']);

		$sql = "SELECT game_name, player_id, score, username FROM " . iNA_SCORES . " s, " . USERS_TABLE . " u
			WHERE s.player_id = u.user_id
			AND game_name = '" . $game_info['game_name'] . "'
			ORDER BY score " . $arcade->sort;
		if(!$result = $db->sql_query($sql))
		{
			$arcade->message_die(GENERAL_ERROR, $lang['no_score_data'], "", __LINE__, __FILE__, $sql);
		}
		$score_info = $db->sql_fetchrow($result);
		$sql = "SELECT game_name, player_id, score, username FROM " . iNA_AT_SCORES . " s, " . USERS_TABLE . " u
			WHERE s.player_id = u.user_id
			AND game_name = '" . $game_info['game_name'] . "'
			ORDER BY score " . $arcade->sort;
		if(!$result = $db->sql_query($sql))
		{
			$arcade->message_die(GENERAL_ERROR, $lang['no_score_data'], "", __LINE__, __FILE__, $sql);
		}
		$score_at_info = $db->sql_fetchrow($result);
		if($game_info['game_charge'])
		{
			$cost = $game_info['game_charge'] . " "; 
		}
		else
		{
			$cost = $lang['game_free']; 
		}

		if($arcade->arcade_config['use_point_system'] && ($game_info['game_charge'] > 0))
		{
			$cost .= $board_config['points_name'];
		}

		if($game_info['instructions'])
		{
			$instructions = $game_info['instructions'];
		}
		else
		{
			$instructions = $lang['game_no_instructions'];
		}
	
    $image_path = ina_find_image($game_info['game_path'], $game_info['game_name'], $game_info['image_path']);

		$best_player = $score_info['username'];
		if( $best_player == 'Anonymous' )
		{
			$best_player = $lang['Guest'];
		}
//
// Requested thousands displayed by Painkiller
//
		$display_at_score  = $arcade->convert_score($score_at_info['score']) ? $arcade->score : '';
    $display_score = $arcade->convert_score($score_info['score']) ? $arcade->score : '';

		if($game_info['game_reward'] > 0)
		{
			$reward = sprintf($lang['games_reward_givem'], $game_info['game_reward']);
		}
		else
		{
			$reward = $lang['None'];
		}

		if ($arcade->arcade_config['games_show_fav'] && $userdata['user_id'] != ANONYMOUS)
		{
			if($game_info['fav_game_name'])
			{
				$add_fav = '<br /><a href="'.$filename.'?mode=fav_del&amp;id='.$game_id.'"><img src="images/remove_favorite.gif" border="0"></a>';
			}
			else
			{
				$add_fav = '<br /><a href="'.$filename.'?mode=fav&amp;cat_id='.$arcade->cat_id.'&amp;id='.$game_id.'"><img src="images/favorite.gif" border="0"></a>';
			}
		}
		
		if ($arcade->arcade_config['games_at_highscore'])
		{
			$template->assign_block_vars('at_highscore', array());
		}
    if($game_info['game_control'] == 1)
    {
      $game_control = '&nbsp;<img src="images/mouse.gif" alt="'. $lang['arcade_mouse'] .'">&nbsp;';
    } 
    else if($game_info['game_control'] == 2)
    {
      $game_control = '&nbsp;<img src="images/keyboard.gif" alt="'.$lang['arcade_keyboard'].'">&nbsp;';
    } 
    else if($game_info['game_control'] == 3)
    {
      $game_control = '&nbsp;<img src="images/mouse.gif" alt="'.$lang['arcade_mouse'].'">&nbsp;<img src="images/keyboard.gif" alt="'.$lang['arcade_keyboard'].'">&nbsp;';
    }
    else
    {
      $game_control = '';
    }
		
		$template->set_filenames(array('body' => 'arcade_stats.tpl') );

		$template->assign_vars(array(
			'NAME' => $game_info['game_name'],
			'PATH' => $game_info['game_path'],
			'U_ADD_FAV' => $add_fav,
			'L_ADD_FAV' => $lang['games_add_fav'],
			'IMAGE' => $image_path,
      'CONTROL' => $game_control,
      'CATEGORY' => $lang['None'],
      'TIMES' => $lang['times'],
			'DESC' => $game_info['game_desc'],
			'PLAYED' => $game_info['played'],
			'COST' => $cost,
			'BONUS' => $game_info['game_bonus'],
			'AT_BONUS' => $game_info['at_game_bonus'],
			'REWARD' => $reward,
			'BEST_PLAYER' => $best_player,
			'BEST_SCORE' => $display_score,
			'BEST_AT_PLAYER' => $score_at_info['username'],
			'BEST_AT_SCORE' => $display_at_score,
			'INSTRUCTIONS' => $instructions,
			'ARCADE_MOD' => sprintf($lang['activitiy_mod_info'], $arcade->version),
			
			"L_INSTRUCTIONS" => $lang['game_instructions'],
			"L_GAME_STATS" => $lang['game_statistics'],
			"L_CONTROL" => $lang['control'],
			"L_CATEGORY" => $lang['category'],
			"L_PLAYED" => $lang['game_played'],
			"L_PRICE" => $lang['game_stat_price'],
			"L_HIGHSCORE" => $lang['game_stat_highscore'],
			"L_AT_HIGHSCORE" => $lang['game_stat_at_highscore'],
			"L_SCORE_REWARD" => $lang['game_score_reward'],
			"L_BEST_PLAYER" => $lang['game_best_player'],
			"L_ALL_TIME_SCORE" => $lang['game_all_time_score'],
			"L_CURRENT_BEST" => $lang['game_current_best'],
			"L_HIGHEST_SCORE" => $lang['game_highest_score'],
			"L_TOURNAMENT" => $lang['game_tournament'],
			
			"U_CAT" => $url) );
	}
//
//	We are looking for the highscores.
//
	else if ($mode == "highscore" || $mode == 'at_highscore')
	{ 
		$page_title			= $game_info['game_desc'] . ' - ' . $cat_info['cat_name'] . ' - ' . $board_config['sitename'] ; 
		$game_name			= $game_info['game_name'];

		$highscore_limit	= $game_info['highscore_limit']; 
		$high_score_text	= $lang['game_highscores'];
		$high_score_table	= iNA_SCORES;

		if( $mode == "at_highscore" )
		{
			$high_score_text = $lang['game_at_highscores'] . ' ' . $lang['game_highscores'];
			$high_score_table = iNA_AT_SCORES;
			$highscore_limit = $game_info['at_highscore_limit'];
		}

		$url .= "&nbsp;&raquo;&nbsp;" . $game_info['game_desc'];

		$template->set_filenames(array('body' => 'arcade_scores.tpl') ); 
		$template->assign_vars(array(
			'TITLE' => $game_info['game_desc'], 
			
			'L_HIGHSCORE' => $high_score_text, 
			'L_SCORE' => $lang['game_score'], 
			'L_PLAYED' => $lang['game_played'],
			'L_TIME_TAKEN' => $lang['games_time_taken'],
			'L_TIME_HELD' => $lang['games_time_held'],
			
	    'DASH' => $lang['game_dash'],
			'ARCADE_MOD' => sprintf($lang['activitiy_mod_info'], $arcade->version),
				
			'U_CAT' => $url)); 

		$arcade->sort_method($game_info['reverse_list']);
		$arcade->prune_scores($game_info['game_id']);

		$sql = "SELECT game_name, player_id, score, date, time_taken, username, user_allow_viewonline FROM " . $high_score_table . " s, " . USERS_TABLE . " u
			WHERE s.player_id = u.user_id
			AND game_name = '" . $game_name . "'
			ORDER BY score " . $arcade->sort . ", date ASC";

		if(!$result = $db->sql_query($sql)) 
		{
			$arcade->message_die(GENERAL_ERROR, $lang['no_score_data'], "", __LINE__, __FILE__, $sql); 
		}
		$last_score = null;
		if ($row = $db->sql_fetchrow($result)) 
		{ 
			$i = 1; 
	        do // Loop until we hit the end :) to output the page..
		    { 
				$user_name = $row['username'];
				if($last_score != $row['score'])
				{
					$pos = $i;
				}
				else
				{
					$pos = ' ';
				}

				if($pos == 1)
				{
					$pos = '<img src="images/trophy.gif">';
				}
				else if($pos == 2)
				{
					$pos = '<img src="images/trophy2.gif">';
				}
				else if($pos == 3)
				{
					$pos = '<img src="images/trophy3.gif">';
				}

				if($user_name == 'Anonymous')
				{
					$user_name = $lang['Guest'];
				}
				else if(!$row['user_allow_viewonline'] && $userdata['user_level'] != ADMIN && $userdata['user_level'] != MOD)
				{
					$user_name = $lang['game_hidden'];
				}
//
// Requested thousands displayed by Painkiller
//
// v2.0.7 removed the link to games with no score, but this will now tell the user, in case they play
//
          $display_score = $arcade->convert_score($row['score']) ? $arcade->score : $lang['None'];
//
//  Work out the Time to a better format (Req by Painkiller)
//     
          $time_taken = $arcade->convert_time($row['time_taken']);
          $time_held = $arcade->convert_time((time() - $row['date']));

	        $template->assign_block_vars("scores", array(
				    'ROW_CLASS' => ( !($i % 2) ) ? 'row1' : 'row2', 
         		'POS' => $pos, 
	         	'NAME' => $user_name, 
	         	'SCORE' => $display_score,                                  
		       	'DATE' => create_date($board_config['default_dateformat'], $row['date'], $board_config['board_timezone']),
		       	'TIME_TAKEN' => $time_taken,
		       	'TIME_HELD' => $time_held
	       	));
					$i++; 

				$last_score = $row['score'];
			} 
			while ($row = $db->sql_fetchrow($result)); 
		}
	}
//
//	User Game Stats
//
	else if ($mode == "game_stats")
	{
		$url		= ' &raquo; <a href="' . $filename . '" class="nav">' . $lang['games_catagories'] . '</a>';

		if (isset($HTTP_GET_VARS['user_id']) || isset($HTTP_POST_VARS['user_id']))
		{
			$user_id = (isset($HTTP_GET_VARS['user_id'])) ? $HTTP_GET_VARS['user_id'] : $HTTP_POST_VARS['user_id'];
			$user_id	= intval(htmlspecialchars($user_id));
//
//	Send PM to user, to tell them thier highscores are under attack :)
//
			$sql = "SELECT username FROM " . USERS_TABLE . "
				WHERE user_id = '" . $userdata['user_id'] . "'";
			if(!$result = $db->sql_query($sql)) 
			{
				$arcade->message_die(GENERAL_ERROR, $lang['no_score_data'], "", __LINE__, __FILE__, $sql); 
			}
			$user_row = $db->sql_fetchrow($result); 
				
			if ((ina_check_last_pm($user_id, $userdata['user_id']) == FALSE))
			{
				$message = sprintf($lang['games_pm_info'], $user_row['username']);
				ina_send_user_pm($user_id, $lang['games_important_info'], $message, $userdata['user_id']);
			}
		}

		$stats_id	= intval(htmlspecialchars($game_id));
		if($stats_id > 0 && $stats_id < 4)
		{
  		$scores = total_highscores($user_id, iNA_AT_SCORES);
//  		$scores = $arcade->total_highscores($user_id, iNA_AT_SCORES);
			$stats_id = $stats_id + 3;
			$table = iNA_AT_SCORES;
		}
		else
		{
			$table = iNA_SCORES;
  		$scores = total_highscores($user_id);
//  		$scores = $arcade->total_highscores($user_id);
		}

		$template->set_filenames(array('body' => 'arcade_stats_body.tpl') );
		$template->assign_vars(array(
			'L_GAME' => $lang['Game'],
			'L_HIGHSCORE'=> $lang['game_highscore'],
			'L_PLAYED' => $lang['game_played'],
			'L_TIME_TAKEN' => $lang['games_time_taken'],
			
			'U_CAT' => $url,
			'ARCADE_MOD' => sprintf($lang['activitiy_mod_info'], $arcade->version)));

    $list = str_replace("|", "', '", $scores[$stats_id]);

		$sql = "SELECT g.game_id, g.win_width, g.win_height, g.game_desc, g.level_required, g.rank_required, g.allow_guest, s.score, s.date, s.time_taken 
        FROM ". iNA_GAMES . " g, " . $table . " s
				WHERE g.game_name IN ('" . $list . "')
					AND g.game_name = s.game_name
					AND player_id = " . $user_id;
					
		if(!$result = $db->sql_query($sql)) 
		{
			$arcade->message_die(GENERAL_ERROR, $lang['no_score_data'], "", __LINE__, __FILE__, $sql); 
		}
		$rows = $db->sql_fetchrowset($result); 
    $row_count = count($rows);
    
    for($i = 0; $i < $row_count; $i++)
    {
			if(($userdata['user_id'] != ANONYMOUS && ($rows[$i]['level_required'] < $userdata['user_level']) && ($rows[$i]['rank_required'] < $userdata['user_rank'])) || ($userdata['user_id'] == ANONYMOUS && $rows[$i]['allow_guest']))
			{
				$game_link = "<a href=\"javascript:Gk_PopTart('$filename?mode=game&amp;id=" . $rows[$i]['game_id'] . "&amp;ex_user=" . $user_id . "$SID', 'Game_Window', '" . $rows[$i]['win_width'] . "', '" . $rows[$i]['win_height'] . "', 'no')\" onClick=\"blur()\">" . $rows[$i]['game_desc'] . "</a>";
			}
			else
			{
				$game_link = $rows[$i]['game_desc'];
			}
      $display_score = $arcade->convert_score($rows[$i]['score']) ? $arcade->score : '';
      $time_taken = $arcade->convert_time($rows[$i]['time_taken']);

			$template->assign_block_vars("stats", array(
				'ROW_CLASS' => ( !($i % 2) ) ? $theme['td_class1'] : 'row2', 
				'COUNT' => $i+1,
				'GAME' => $game_link,
				'SCORE' => $display_score,
				'LAST_PLAYED' => create_date($board_config['default_dateformat'], $rows[$i]['date'], $board_config['board_timezone']),
				'TIME_TAKEN' => $time_taken
				 ) ); 
    
    }
	}
//
//	Delete from Favorities Mode
//
	else if ($mode == 'fav_del')
	{
		$sql = "DELETE FROM " . iNA_FAV . "
			WHERE user_id = '" . $user_id . "'
				AND fav_game_name = '" . $game_info['game_name'] . "'";
		if(!$result = $db->sql_query($sql)) 
		{
			$arcade->message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
		}

		$message = $lang['del_fav'];
		$message .= '<a href="' . append_sid("$filename?mode=fav&amp;cat_id=$arcade->cat_id") . '">Return</a>';
		message_die(GENERAL_MESSAGE, $message);
	}
//
// Guest Mode..
//
	else
	{	
		if(((intval($userdata['user_posts']) < intval($arcade->arcade_config['games_posts_required'])) || (intval($userdata['user_rank']) < intval($arcade->arcade_config['games_rank_required']))) && $userdata['user_level'] != ADMIN && $userdata['user_id'] != ANONYMOUS)
		{
			if(empty($HTTP_GET_VARS['warning']))
			{
				$warning_url = '<a href="' . $filename . '?mode=cat&amp;cat_id=' . $arcade->cat_id . '&amp;start=' . $start . '&amp;sort_mode=' . $arcade->sort_mode . '&amp;order=' . $arcade->sort_order . '&amp;warning=true">OK</a>';
				message_die(GENERAL_MESSAGE, sprintf($lang['games_not_enough_posts'], $warning_url), $lang['Information']);
			}
			else
			{
				$GUEST_ONLY = TRUE;
			}
		}
//
// Now lets start building the list of Activities for the catagory :)
//
		$game_count = 0;
		$sql_and = '';
		$pagination = '';
		$page_number = '';
		$select_game = '';
		$total_tournaments = 0;
		$moderate = '';
		$top_games_list = '';
		$bottom_games_list = '';
		$bottom_games_header = '';
		$sort_mode_link_info = append_sid("$filename?mode=cat&amp;cat_id=$arcade->cat_id&amp;");
		
		$template->set_filenames(array('body' => 'arcade_body.tpl') );
		
		if ($arcade->arcade_config['use_gamelib'] == 1)
		{
			$gamelib_link = "<div align=\"center\"><span class=\"copyright\">" . $lang['game_lib_link'] . "</span></div>";
		}
		if ($arcade->arcade_config['use_gamelib'] == 0)
		{
			$gamelib_link = '';		
		}
	
		if ($arcade->arcade_config['games_tournament_mode'])
		{
				$total_tournaments = get_total_tour();
				$template->assign_block_vars('tournament_menu', array());
		}
		if ($arcade->arcade_config['use_point_system'] && $arcade->arcade_config['use_rewards_mod'])
		{
			$template->assign_vars(array("L_MONEY" => $board_config['points_name']));
		}
		else if (($arcade->arcade_config['use_cash_system'] || $arcade->arcade_config['use_allowance_system']) && $arcade->arcade_config['use_rewards_mod'])
		{
			$template->assign_vars(array("L_MONEY" => $lang['game_cost']));
		}
		else
		{
			$template->assign_vars(array("L_MONEY" => $lang['game_number']));
		}
		$user_id = $userdata['user_id'];
//
//	User Favourites Mode
//
		if ($mode == 'fav')
		{
			if ($game_id)
			{
				if (isset($HTTP_GET_VARS['quiet']) || isset($HTTP_POST_VARS['quiet']))
				{
					$gen_simple_header	= TRUE; 
				}
				$sql = "SELECT * FROM " . iNA_FAV . "
					WHERE user_id = " . $user_id . "
						AND fav_game_name = '" . $game_info['game_name'] . "'";
				if(!$result = $db->sql_query($sql)) 
				{
					$arcade->message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
				}
				$row_count = $db->sql_numrows($result);
				if($row_count == 0 && $user_id != ANONYMOUS)
				{
					$sql = "INSERT INTO " . iNA_FAV . " (user_id, fav_game_name)
						VALUES ($user_id, '".$game_info['game_name']."')";
					if ( !$db->sql_query($sql) )
					{
						$arcade->message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
					}
				}
				else if($gen_simple_header != TRUE)
				{
					$message = $lang['already_fav'];
					$message .= '<a href="' . append_sid("$filename?mode=stats&amp;cat_id=$arcade->cat_id&amp;id=$game_id") . '">Return</a>';
					message_die(GENERAL_MESSAGE, $message);
				}
			}
			if ($gen_simple_header == TRUE)
			{
				$message = '<a href="javascript:parent.window.close();">' . $lang['game_score_close'] . '</a>';
				message_die(GENERAL_MESSAGE, $message);
			}

			$sql_and = " AND f.user_id = " . $userdata['user_id'] . " AND f.fav_game_name = g.game_name";		

			$sort_mode_link_info = append_sid("$filename?mode=fav&amp;");

		}
//
//	Seach Mode
//
 		else if ($mode == 'search')
 		{
 			$search_word	= (isset($search_word)) ? htmlspecialchars($search_word) : $lang['None'];
  
      $sql_and = "AND game_desc LIKE '%$search_word%'";
  
   		$page_title = sprintf($lang['arcade_searched'], $board_config['sitename'], $search_word);
 		}
 		$cat_head = false;
//
//  If user is not ADMIN or MOD then make sure we only get the games this user can view
//
		if( $userdata['user_level'] != ADMIN && $userdata['user_level'] != MOD )
		{
	    $sql_and .= " AND game_avail = 1 AND level_required <= ".$level_required." AND rank_required <= " . $rank_required . " AND g.group_required IN (" . $group_list . ")";
		}
		if($arcade->cat_id > 0)
		{
			$sql_and .= " AND g.cat_id = $arcade->cat_id";
//
//  Check for any SUB-Categories for this category
//
			$catrows = $arcade->read_cat();
			for($i = 1; $i < count($catrows); $i++)
			{
        if($catrows[$i]['cat_type'] == 's' && $catrows[$i]['cat_parent'] == $arcade->cat_id)
        {
          $cat_head = true;
//
//  Check for an Icon
//
      		$icon = '';
      		if($catrows[$i]['cat_icon'])
      		{
      			if($arcade->arcade_config['games_cat_image_width'])
      			{
      				$icon = "<img src=\"" . $catrows[$i]['cat_icon'] . "\" width=\"" . $arcade->arcade_config['games_cat_image_width'] . "\" height=\"" . $arcade->arcade_config['games_cat_image_height'] . "\" border=\"0\">";
      			}
      			else
      			{
      				$icon = "<img src=\"" . $catrows[$i]['cat_icon'] . "\" border=\"0\">";
      			}
		      }
//
//  Check for Moderator
//
      		$moderator = '';
      		if($catrows[$i]['mod_id'])
    		  {
      			$moderator = '<br /><b>'.$lang['Moderator'].':</b>';
      			$temp_url = append_sid("profile.$phpEx?mode=viewprofile&amp;" . POST_USERS_URL . "=". $catrows[$i]['mod_id'] . "");
      			$profile = ' <a href="' . $temp_url . '"><span class="gentbl">' . $arcade->get_username($catrows[$i]['mod_id']) . '</span></a>';
      			$moderator .= $profile;
      		}
//
//  Check for Last Played Info
//
      		$last_played = '';
      		if($catrows[$i]['last_player'])
      		{
       			if($catrows[$i]['last_player'] != ANONYMOUS)
       			{
       				$temp_url = append_sid("profile.$phpEx?mode=viewprofile&amp;" . POST_USERS_URL . "=". $catrows[$i]['last_player'] . "");
       				if($catrows[$i]['user_allow_viewonline'] || $userdata['user_level'] == ADMIN || $userdata['user_level'] == MOD)
       				{
       					$profile = '<a href="' . $temp_url . '" class="gensmall"><span class="gentbl">' . $catrows[$i]['username'] . '</span></a>';
       				}
       				else
       				{
       					$profile = $lang['game_hidden'];
       				}
       			}
       			else
       			{
       				$profile = $lang['Guest'];
       			}
//
//  Last Game (check length and output short version if required)
//    
            $last_game_desc = $catrows[$i]['game_desc'];
       			if(strlen($catrows[$i]['game_desc']) > 25)
       			{
       				$last_game_desc =	trim(substr_replace ( $catrows[$i]['game_desc'], ' ', 23, strlen($catrows[$i]['game_desc']) )) . '..';
       			}
//
//  Build the Last Played Info
//
      			$last_played = '<a href="' . $filename . '?mode=cat&amp;cat_id=' . $catrows[$i]['cat_id'] . '#'.$catrows[$i]['game_id'].'" class="gensmall">' . $last_game_desc;
       			$last_played .= '</a><br />' . create_date($board_config['default_dateformat'], $catrows[$i]['last_time'], $board_config['board_timezone']);
       			$last_played .= '<br />' . $profile;
      		}

       		$template->assign_block_vars("cats", array(
      			'ROW_CLASS' => ( !($i % 2) ) ? 'row2' : 'row1', 
   		      'CAT_ID' => $catrows[$i]['cat_id'], 

      			'TOTAL_GAMES' => ($catrows[$i]['cat_type'] == 'l' ) ? '' : $arcade->convert_score($catrows[$i]['total_games']),
      			'TOTAL_PLAYED' => ($catrows[$i]['cat_type'] == 'l' ) ? '' : $arcade->convert_score($catrows[$i]['total_played']),
      			'LAST_PLAYED' => ($catrows[$i]['cat_type'] == 'l' ) ? sprintf($lang['arcade_link_visted'],$arcade->convert_score($catrows[$i]['total_played'])) : $last_played,
            'MODERATOR' => $moderator,
 
            'ICON' => $icon, 
            'LINK' => append_sid("$filename?mode=cat&amp;cat_id=".$catrows[$i]['cat_id']),
      	    'NAME' => $catrows[$i]['cat_name'],                                  
      		  'DESC' => ( $catrows[$i]['cat_type'] == 'l' ) ? '' : smilies_pass($catrows[$i]['cat_desc'])
           )); 
        }
      }
      if($cat_head == true)
      {
     		$template->assign_block_vars("cat_head", array(
      		'L_CATS' => $lang['games_section'],
      		'L_TOTAL_GAMES' => $lang['cat_total_games'],
      		'L_TOTAL_PLAYED' => $lang['cat_total_played'],
      		'L_LAST_PLAYED' => $lang['cat_last_played'],
         ));
      }
		}
		$sql_order = " GROUP BY game_id ORDER BY " . $order_by . " " . $arcade->sort_order;
//
//  If the Games Per Page is set, then add a LIMIT
//
		if($arcade->arcade_config['games_per_page'] > 0)
		{
			$sql_order .= " LIMIT $start," . $arcade->arcade_config['games_per_page'];
		}
//
// Main Query to get as much Information as we can.
//
 		$sql = "SELECT g.*, f.fav_game_name, c.cat_name, c.total_games, c.total_played, AVG(r.rate_point) AS rating, COUNT(comment_id) AS comment_count, s.score, u.username, u.user_allow_viewonline, a.score AS at_score, a.player_name
      FROM " . iNA_CAT . " c, 
           " . iNA_GAMES . " g
        LEFT JOIN " . iNA_SCORES . " s ON g.highscore_id = s.player_id AND g.game_name = s.game_name
        LEFT JOIN " . USERS_TABLE . " u ON g.highscore_id = u.user_id
        LEFT JOIN " . iNA_AT_SCORES . " a ON g.at_highscore_id = a.player_id AND g.game_name = a.game_name
			  LEFT JOIN " . iNA_FAV . " f ON g.game_name = f.fav_game_name AND f.user_id = " . $userdata['user_id'] . "
        LEFT JOIN " . iNA_GAMES_RATE . " r ON g.game_name = r.rate_game_name
        LEFT JOIN " . iNA_GAMES_COMMENT . " ON g.game_name = comment_game_name
      WHERE c.cat_id = g.cat_id $sql_and $sql_order";
  	if( !$result = $db->sql_query($sql) )
		{
			$arcade->message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $listsql . '<br>' . $sql);
		}
		$game_rows = $db->sql_fetchrowset($result);
    $game_count = count($game_rows);
//
//  Now build the list of games for displaying.
//
		for($i = 0; $i < $game_count; $i++) 
		{
		  $best_player = $best_at_player = '';
		  
			$game_id = $game_rows[$i]['game_id'];
			$game_name = $game_rows[$i]['game_name'];
			$game_path = $game_rows[$i]['game_path'];

      $image_path = ina_find_image($game_path, $game_name, $game_rows[$i]['image_path']);

			if($arcade->arcade_config['use_rewards_mod'])
			{
				if($game_rows[$i]['game_charge'])
				{
					$game_charge = $game_rows[$i]['game_charge'];
				}
				else
				{
					$game_charge = $lang['game_free']; 
				}
			}
			else
			{
				$game_charge = $start+$i+1;
			}
//
//  Check if Game is OFFLINE
//
			$game_desc = $game_rows[$i]['game_desc'];
			if ( $game_rows[$i]['game_avail'] == 0 )
			{
				$game_desc .= " (**********OFFLINE**********)";
			}
			$extension = get_ina_extension($game_name);
			$check_name = $game_path . $game_name;
			if($game_rows[$i]['game_flash'])
			{
				$check_name .= '.swf'; 
			}
			if ( ($game_rows[$i]['game_flash'] || $extension == 'swf' || $extension == 'gif' || $extension == 'jpg' || $extension == 'png' ) && $arcade->arcade_config['games_auto_size'] && !$game_rows[$i]['game_autosize'])
			{
				$game_size = getimagesize($check_name); 
				$win_width = $game_size[0] + 20; 
				$win_height = $game_size[1] + 25; 
			}
			else
			{
				$win_width = $game_rows[$i]['win_width'] + 20;
				$win_height = $game_rows[$i]['win_height'] + 25;
			}
			$arcade->sort_method($game_rows[$i]['reverse_list']);
//
//  Get Highscore Info
//
			if ($game_rows[$i]['game_show_score'] == '1')
			{
				$bonus_info = $game_rows[$i]['game_bonus'] ? $bonus_info = $game_rows[$i]['game_bonus'] : '';
        $best_player_id = $game_rows[$i]['highscore_id'];
        $best_player = ($game_rows[$i]['highscore_id'] == ANONYMOUS) ? $lang['Guest'] : $game_rows[$i]['username'];
        
				if ($best_player != '')
				{
//
// Requested thousands by Painkiller
//
          $best_score = $arcade->convert_score($game_rows[$i]['score']) ? $arcade->score : '';
					if($best_player_id != ANONYMOUS)
					{
						if($game_rows[$i]['user_allow_viewonline'] || $userdata['user_level'] == ADMIN || $userdata['user_level'] == MOD)
						{
							$temp_url = append_sid("profile.$phpEx?mode=viewprofile&amp;" . POST_USERS_URL . "=$best_player_id");
							$profile = '<a href="' . $temp_url . '"><span class="gentbl">' . $best_player . '</span></a>';
							$best_player = $profile;
						}
						else
						{
							$best_player = $lang['game_hidden'];
						}
					}
					else
					{
						$best_player = $lang['Guest'];
					}
					$highscore_link = "<a href=\"" . append_sid("$filename?mode=highscore&amp;id=$game_id&amp;cat_id=$arcade->cat_id&amp;start=$start&amp;order=$arcade->sort_order&amp;sort_mode=$arcade->sort_mode") . "\"> " . $lang['game_highscores'] . "</a>";
				}
				else
				{
					$highscore_link = 
					$best_score = '';
				}
				$bonus_info = $game_rows[$i]['game_bonus'] ? $bonus_info = $game_rows[$i]['game_bonus'] : '';
//
//  Get All Time Highscore Info
//
				if($arcade->arcade_config['games_at_highscore'])
				{
				  if($game_rows[$i]['at_highscore_id'] > 0)
				  {
//
// Requested thousands by Painkiller
//
				    $best_at_player = ($game_rows[$i]['player_name'] == '') ? '?????' : $game_rows[$i]['player_name'];
            $best_at_score = $arcade->convert_score($game_rows[$i]['at_score']) ? '<br /><br />' . $arcade->score : '';
						$temp_url = append_sid("profile.$phpEx?mode=viewprofile&amp;" . POST_USERS_URL . "=".$game_rows[$i]['at_highscore_id']);
						$profile = '<a href="' . $temp_url . '"><span class="gentbl">' . $best_at_player . '</span></a>';
						$best_at_player = '<br /><br />'.$profile;
						if($best_player != '')
						{
							$at_highscore_link = " <br />-:-<br /><a href=\"" . append_sid("$filename?mode=at_highscore&amp;id=$game_id&amp;cat_id=$arcade->cat_id&amp;start=$start&amp;order=$arcade->sort_order&amp;sort_mode=$arcade->sort_mode") . "\">" . $lang['game_at_highscores'] . "</a>";
						}
						else
						{
							$at_highscore_link = " <br /> <br /><a href=\"" . append_sid("$filename?mode=at_highscore&amp;id=$game_id&amp;cat_id=$arcade->cat_id&amp;start=$start&amp;order=$arcade->sort_order&amp;sort_mode=$arcade->sort_mode") . "\">" . $lang['game_at_highscores'] . "</a>";
						}
					}
					else 
					{
						$at_highscore_link =
						$best_at_score = '';
					}
					$bonus_info .= '<br /><br />';
					$bonus_info .= $game_rows[$i]['at_game_bonus'] ? $game_rows[$i]['at_game_bonus'] : '<br />';
				}
				$dash = $lang['game_dash'];
			}
			else
			{
				$highscore_link = 
				$at_highscore_link = 
				$best_score = 
				$best_at_score = 
				$best_player = 
				$best_at_player =
				$bonus_info = '';
			}
			if ($arcade->arcade_config['games_show_played'])
			{
				$played = sprintf('<br >' . $lang['Played_Times'], $game_rows[$i]['played']);
			}
			if (($arcade->arcade_config['games_show_fav'] == 1) && ($userdata['user_id'] != ANONYMOUS))
			{
				if($mode != 'fav')
				{
					if($game_rows[$i]['fav_game_name'])
					{
						$add_fav = '<br /><a href="'.$filename.'?mode=fav_del&amp;id='.$game_id.'"><img src="images/remove_favorite.gif" border="0"></a>';
					}
					else
					{
						$add_fav = '<br /><a href="'.$filename.'?mode=fav&amp;cat_id='.$arcade->cat_id.'&amp;id='.$game_id.'"><img src="images/favorite.gif" border="0"></a>';
					}
				}
				else
				{
					$add_fav = '<br /><a href="'.$filename.'?mode=fav_del&amp;id='.$game_id.'"><img src="images/remove_favorite.gif" border="0"></a>';
				}
			}
			if ( $game_rows[$i]['date_added'] > (time() - ($arcade->arcade_config['games_new_for'])))
			{
				$new_game = '<img src="images/new.gif" border="0"> ';
			}
			else
			{
				$new_game = '';
			}

			if ($arcade->arcade_config['games_rate'] && ($userdata['user_id'] != ANONYMOUS))
			{
				$game_rate = ' &nbsp; <a href="arcade_rate.' . $phpEx . '?game_id=' . $game_id . '")"><img src="images/rate.gif" border="0"></a> &nbsp; ';
				if(!empty($arcade->arcade_config['games_rate_extra']))
        {

  			  $sql ="SELECT * FROM ". iNA_GAMES_RATE ."	
            WHERE rate_game_name = '$game_name'	
              AND rate_user_id = '". $userdata['user_id'] ."'
              LIMIT 1";
          if( !$result = $db->sql_query($sql) )
          {
  	         $arcade->message_die(GENERAL_ERROR, $land['no_rate_data'], '', __LINE__, __FILE__, $sql);
          }
          if ($db->sql_numrows($result) != 0)
          {
            $game_rate = ' &nbsp; ';
          }
        }
        for ($rate_count = round($game_rows[$i]['rating'], 2); $rate_count > 0; $rate_count--)
        {
          if (($rate_count >= .5) && ($rate_count <= .9))
          {
            $game_rate .= '<img src="images/icon_half_star.gif">';
          }
          else
          {
            $game_rate .= '<img src="images/icon_star.gif">';
          }
        }
      }

//
//  Display the Filesize of the game on the menu (Req: Mark)
//
 			$filesize = (@filesize($check_name)/1024);
 			$filesize = $filesize > 1 ? sprintf($lang['game_size'] , $filesize) : sprintf($lang['game_size'] , 1);
//
//  As we have a new line of info, lets add it it :)
//  Category Name displayed in ALL GAMES (Req: Minesh)
//			
      if($arcade->cat_id == 0 && $game_rows[$i]['cat_name'])
      {
        $filesize .= '&nbsp; &nbsp;<font color="red"><span class="gensmall"><i>'. $lang['category'] .': </span></font><a href="' . $filename . '?mode=cat&amp;cat_id='. $game_rows[$i]['cat_id'] . '">' . $game_rows[$i]['cat_name']. '</i></a>';
      }
			if ( $user_id == ANONYMOUS && ($game_rows[$i]['allow_guest'] != 1))
			{
				$image_info = '<img src ="'. $image_path .'" border="0" align="middle" width="'.$arcade->arcade_config['games_image_width'].'" height="'.$arcade->arcade_config['games_image_height'].'" alt="REGISTER" /></a>';
        $desc_info = $lang['register_to_play'];
			}
			else
			{
				$image_info = '<a href="'. append_sid("$filename?mode=game&amp;id=$game_id") .'" onClick="Gk_PopTart(\''. append_sid("$filename?mode=game&amp;id=$game_id") .'\', \'Activity_Window\', \''.$win_width.'\', \''.$win_height.'\', \'no\'); return false; blur();"><img src ="'. $image_path .'" border="0" align="middle" width="'.$arcade->arcade_config['games_image_width'].'" height="'.$arcade->arcade_config['games_image_height'].'" alt="Popup" /></a>';
				$desc_info = '<a href="'. append_sid("$filename?mode=game&amp;id=$game_id") .'" class="forumlink" onClick="Gk_PopTart(\''. append_sid("$filename?mode=game&amp;id=$game_id") .'\', \'Activity_Window\', \''.$win_width.'\', \''.$win_height.'\', \'no\'); return false; blur();">&nbsp;&laquo;&nbsp;' . smilies_pass($game_desc) . '&nbsp;&raquo;&nbsp;</a>';
      }
      if($game_rows[$i]['game_control'] == 1)
      {
        $game_control = '&nbsp;<img src="images/mouse.gif" alt="'.$lang['arcade_mouse'].'">&nbsp;';
      } 
      else if($game_rows[$i]['game_control'] == 2)
      {
        $game_control = '&nbsp;<img src="images/keyboard.gif" alt="'.$lang['arcade_keyboard'].'">&nbsp;';
      } 
      else if($game_rows[$i]['game_control'] == 3)
      {
        $game_control = '&nbsp;<img src="images/mouse.gif" alt="'.$lang['arcade_mouse'].'">&nbsp;<img src="images/keyboard.gif" alt="'.$lang['arcade_keyboard'].'">&nbsp;';
      }
      else
      {
        $game_control = '';
      }

			$tournament = '';
			$template->assign_block_vars("game", array(
				'ROW_CLASS' => ( !($i % 2) ) ? 'row1' : 'row2',

				'BEST_SCORE' => $best_score,
				'BEST_PLAYER' => $best_player,
				'BEST_AT_SCORE' => $best_at_score,
				'BEST_AT_PLAYER' => $best_at_player,
				'TOURNAMENT' => $tournament,
				'ID' => $game_rows[$i]['game_id'],
				'NAME' => $game_name,
				'PATH' => $game_path,
				'IMAGE' => $image_info,
				'CONTROL' => $game_control,
				'CHARGE' => $game_charge,
				'DESC' => $desc_info,
				'INST' => $game_rows[$i]['instructions'],
				'COMMENT' => (($arcade->arcade_config['games_comments'] == 1) && ($userdata['user_id'] != ANONYMOUS) && ($game_rows[$i]['comment_count'] > 0)) ? ' &nbsp; <a href="arcade_comment.' . $phpEx . '?game_id=' . $game_id . '"><img src="images/comment.gif" border="0"></a>' : '',
				'ADD_FAV' => $add_fav,
				'RATE' => ($arcade->arcade_config['games_rate'] == 1) ? $game_rate : '',
				'NEW' => $new_game,
				'PLAYED' => $played,
				'SIZE' => $filesize,
				'STATS' => append_sid("$filename?mode=stats&amp;id=$game_id&amp;cat_id=$arcade->cat_id&amp;start=$start&amp;order=$arcade->sort_order&amp;sort_mode=$arcade->sort_mode"),
				'ALT_STATS' => $lang['game_stats'],
				'DASH' => $dash,
				'BONUS' => $bonus_info,
				'LIST' => $highscore_link,
				'AT_LIST' => $at_highscore_link) );
		}
		
		$extra = 'cat_id = ' . $arcade->cat_id;
		if($arcade->cat_id == 0)
		{
			$total_all_games = get_games_total('COUNT(*)','');
			$total_games_played = get_games_total('SUM(played)','');
		}
		else
		{
			$total_all_games = $game_rows[0]['total_games'];
			$total_games_played = $game_rows[0]['total_played'];
		}
		
		if ($arcade->arcade_config['games_show_stats'] == 1)
		{
			$bottom_games_header = $lang['games_bum_header'];
			$top_games_list = games_list('DESC', $arcade->arcade_config['games_total_top'], $arcade->cat_id);
			if($cat_info['special_play'] && !$arcade->arcade_config['games_new_games'])
			{
				if(get_games_total('COUNT(*)',$extra) > $cat_info['special_play'])
				{
					$bottom_games_list = games_list('ASC',$arcade->arcade_config['games_total_top'],$arcade->cat_id);
				}
				else 
				{
					$bottom_games_list = $lang['no_special_play_games'];
				}
			}
			else if ($arcade->arcade_config['games_new_games'])
			{
				$bottom_games_list = games_list('DESC', $arcade->arcade_config['games_total_top'], $arcade->cat_id, 'date_added DESC, game_id');
				$bottom_games_header = sprintf($lang['games_new_header'], $arcade->arcade_config['games_total_top']);
			}
			else 
			{
				$bottom_games_list = $lang['no_special_play_games'];
			}
		}
//
//	Get Pagination Total
//
		if($arcade->arcade_config['games_per_page'] > 0)
		{
			$sql = "SELECT count(*) AS total FROM " . iNA_GAMES . " g
					WHERE game_id <> 0";
			if ($mode == 'search')
			{
				$sql = "SELECT count(*) as total from " . iNA_GAMES . "
					WHERE game_desc LIKE '%$search_word%'
						AND game_id <> 0";
			}
			else if ($mode == 'fav')
			{
				$sql = "SELECT count(*) AS total FROM " . iNA_FAV . " f, " . iNA_GAMES . " g
					WHERE f.user_id = '" . $user_id . "'
						AND f.fav_game_name = g.game_name";
			}
			if($arcade->cat_id > 0)
			{
				$sql .= " AND cat_id = '$arcade->cat_id'";
			}
			if($userdata['user_level'] != ADMIN && $userdata['user_level'] != MOD)
			{
				$sql .= " AND game_avail = 1 AND level_required <= ".$level_required." AND rank_required <= " . $rank_required . " AND group_required IN (" . $group_list . ")";
			}
			if ( !($result = $db->sql_query($sql)) )
			{
				$arcade->message_die(GENERAL_ERROR, $lang['no_game_total'], '', __LINE__, __FILE__, $sql);
			}
			$total = $db->sql_fetchrow($result);
			if ( $total['total'] >= $arcade->arcade_config['games_per_page'] )
			{
				$total_games = $total['total'];
				$pagination = generate_pagination("$filename?mode=$mode&amp;sort_mode=$arcade->sort_mode&amp;order=$arcade->sort_order&amp;cat_id=$arcade->cat_id", $total_games, $arcade->arcade_config['games_per_page'], $start). '&nbsp;';
				$page_number = sprintf($lang['Page_of'], ( floor( $start / $arcade->arcade_config['games_per_page'] ) + 1 ), ceil( $total_games / $arcade->arcade_config['games_per_page'] ));
			}
		}
		if ($arcade->arcade_config['use_rewards_mod'] && $arcade->arcade_config['default_cash'] && $arcade->arcade_config['use_cash_system'] && $userdata['user_id'] != ANONYMOUS)
		{
      $cash_name = get_cash_name();
			$player_points = sprintf($lang['games_total_points'], $userdata[$arcade->arcade_config['default_cash']], $cash_name);
		}
		else if ($arcade->arcade_config['use_rewards_mod'] && $board_config['points_name'] && $arcade->arcade_config['use_point_system'] && $userdata['user_id'] != ANONYMOUS)
		{
			$player_points = sprintf($lang['games_total_points'], $userdata[$board_config['default_reward_dbfield']], $board_config['points_name']);
		}
		else if ($arcade->arcade_config['use_rewards_mod'] && $arcade->arcade_config['default_reward_dbfield'] && $arcade->arcade_config['use_point_system'] && $userdata['user_id'] != ANONYMOUS)
		{
			$player_points = sprintf($lang['games_total_points'], $userdata[$arcade->arcade_config['default_reward_dbfield']], $lang['game_points']);
		}
		else
		{
      $player_points = '';
    }
		if((($mod_id && $mod_id == $user_id) || $userdata['user_level'] == ADMIN) && $arcade->arcade_config['games_moderators_mode'])
		{
			$moderate_label = isset($lang['Rules_moderate2']) ? $lang['Rules_moderate2'] : 'Moderieren';
			$moderate = '<a href="arcade_modcp.'.$phpEx.'?mode=mod&amp;cat_id='.$arcade->cat_id.'"><img src="images/moderate.gif" border="0" alt="'.$moderate_label.'"></a> ';
		}
		if ($arcade->arcade_config['games_show_stats'] == 1)
		{
			$template->assign_block_vars('stats_menu', array());
		}

		$monthly_highscore = ($arcade->arcade_config['games_show_mhm']) ? highscore_jump_box() : '';

		$template->assign_vars(array(
			"GAMELIB_LINK" => $gamelib_link,
			"PAGINATION" => $pagination,
			"PAGE_NUMBER" => $page_number, 
			"TOP_HEADER" => sprintf($lang['games_top_header'], $arcade->arcade_config['games_total_top']),
			"BOTTOM_HEADER" => $bottom_games_header,
			"TOTAL_GAMES" => sprintf($lang['total_games'], $total_all_games),
			"TOTAL_GAMES_PLAYED" => sprintf($lang['total_games_played'], $total_games_played),
			"TOP_TEN_LIST" => smilies_pass($top_games_list),
			"BOTTOM_TEN_LIST" => smilies_pass($bottom_games_list),
			"PLAYER_POINTS" => $player_points,
			"LAST_PLAYED" => last_played($userdata['user_id']),
			"LAST_PLAYED_SCORE" => last_played_score(),

			"S_GAME_SELECT" => $select_game,
			"S_MODE_SELECT" => $select_sort_mode,
			"S_ORDER_SELECT" => $select_sort_order,
			"S_MODE_ACTION" => $sort_mode_link_info,
   		"HIGHSCORE_INPUT" => $monthly_highscore,
			"S_GAMES_REGISTER" => $lang['games_register'],
	
			"L_ACTIVE_TOURNAMENTS" => sprintf($lang['Active_Tournaments'], $total_tournaments),
			"L_GAMES" => $lang['game_list'],
			"L_TOURNAMENT" => $lang['game_tournament'],
			"L_SCORES" => $lang['game_score'],
			"L_SCORE_BONUS" => $lang['game_bonuses'],
			"L_INFO" => $lang['game_info'],
			"L_PLAYER" => $lang['game_best_player'],
			"L_ORDER" => $lang['Order'],
			"L_SORT" => $lang['Sort'],
			"L_SUBMIT" => $lang['Sort'],
			"L_GAME_SELECT" => $lang['Game_Select'],
			"L_SELECT_SORT_METHOD" => $lang['Select_sort_method'],
			"L_GOTO_PAGE" => $lang['Goto_page'],
			"L_WELCOME" => sprintf($lang['game_welcome'], $board_config['sitename']),
			"L_WELCOME_GUEST" => sprintf($lang['game_guest_welcome'], $board_config['sitename']),
			"L_GUEST_TXT" => $arcade->arcade_config['games_default_txt'],
			"ARCADE_MOD" => sprintf($lang['activitiy_mod_info'], $arcade->version),
			
			"MODERATE" => $moderate,
  		"CAT_JUMP" => $arcade->jump_cat(),
		  "S_HIDDEN_OPTIONS" => '<input type="hidden" name="redirect" value="' . $filename . '?mode=cat&amp;cat_id=' . $arcade->cat_id . '">',
			"U_CAT" => $url) );

	}	
}
//
//	OK, so if mode is not set, then show the standard catagory page :)
//
else
{
//
//  Set the Template
//
	$template->set_filenames(array('body' => 'arcade_cats.tpl') ); 
//
//  Read in the Categories
//
  $catrows = $arcade->read_cat();
//
//  Set the URL information
//
	$url = ' &raquo; <a href="' . append_sid($filename) . '" class="nav">' . $lang['games_catagories'] . '</a>';
//
//  Loop Through Each Category to build page.
//
  for($i = 1; $i < (count($catrows)); $i++)
	{ 
    if(($catrows[$i]['cat_type'] == 's') || (!in_array($catrows[$i]['group_required'], $group_array) && ($userdata['user_level'] != ADMIN)))
    {
      continue;
    }
//
//  Check for an Icon
//
		$icon = '';
		if($catrows[$i]['cat_icon'])
		{
			if($arcade->arcade_config['games_cat_image_width'])
			{
				$icon = "<img src=\"" . $catrows[$i]['cat_icon'] . "\" width=\"" . $arcade->arcade_config['games_cat_image_width'] . "\" height=\"" . $arcade->arcade_config['games_cat_image_height'] . "\" border=\"0\">";
			}
			else
			{
				$icon = "<img src=\"" . $catrows[$i]['cat_icon'] . "\" border=\"0\">";
			}
		}
//
//  Check for Moderator
//
		$moderator = '';
		if($catrows[$i]['mod_id'])
		{
			$moderator = '<br /><b>'.$lang['Moderator'].':</b>';
			$temp_url = append_sid("profile.$phpEx?mode=viewprofile&amp;" . POST_USERS_URL . "=". $catrows[$i]['mod_id'] . "");
			$profile = ' <a href="' . $temp_url . '"><span class="gentbl">' . $arcade->get_username($catrows[$i]['mod_id']) . '</span></a>';
			$moderator .= $profile;
		}
//
//  Check for Last Played Info
//
		$last_played = '';
		if($catrows[$i]['last_player'])
		{
 			if($catrows[$i]['last_player'] != ANONYMOUS)
 			{
 				$temp_url = append_sid("profile.$phpEx?mode=viewprofile&amp;" . POST_USERS_URL . "=". $catrows[$i]['last_player'] . "");
 				if($catrows[$i]['user_allow_viewonline'] || $userdata['user_level'] == ADMIN || $userdata['user_level'] == MOD)
 				{
 					$profile = '<a href="' . $temp_url . '" class="gensmall"><span class="gentbl">' . $catrows[$i]['username'] . '</span></a>';
 				}
 				else
 				{
 					$profile = $lang['game_hidden'];
 				}
 			}
 			else
 			{
 				$profile = $lang['Guest'];
 			}
//
//  Last Game (check length and output short version if required)
//    
      $last_game_desc = $catrows[$i]['game_desc'];
 			if(strlen($catrows[$i]['game_desc']) > 25)
 			{
 				$last_game_desc =	trim(substr_replace ( $catrows[$i]['game_desc'], ' ', 23, strlen($catrows[$i]['game_desc']) )) . '..';
 			}
//
//  Build the Last Played Info
//
			$last_played = '<a href="' . $filename . '?mode=cat&amp;cat_id=' . $catrows[$i]['cat_id'] . '#'.$catrows[$i]['game_id'].'" class="gensmall">' . $last_game_desc;
 			$last_played .= '</a><br />' . create_date($board_config['default_dateformat'], $catrows[$i]['last_time'], $board_config['board_timezone']);
 			$last_played .= '<br />' . $profile;
		}
//
//  Output Each Category
//
		$template->assign_block_vars("cats", array(
			'ROW_CLASS' => ( !($i % 2) ) ? 'row2' : 'row1', 
   		'CAT_ID' => $catrows[$i]['cat_id'], 

			'TOTAL_GAMES' => ($catrows[$i]['cat_type'] == 'l' ) ? '' : $arcade->convert_score($catrows[$i]['total_games']),
			'TOTAL_PLAYED' => ($catrows[$i]['cat_type'] == 'l' ) ? '' :$arcade->convert_score($catrows[$i]['total_played']),
			'LAST_PLAYED' => ($catrows[$i]['cat_type'] == 'l' ) ? sprintf($lang['arcade_link_visted'], $arcade->convert_score($catrows[$i]['total_played'])) : $last_played,

	 		'MODERATOR' => $moderator,
 
      'ICON' => $icon, 
      'LINK' => append_sid("$filename?mode=cat&amp;cat_id=".$catrows[$i]['cat_id']),
	    'NAME' => $catrows[$i]['cat_name'],                                  
		  'DESC' => ( $catrows[$i]['cat_type'] == 'l' ) ? '' : smilies_pass($catrows[$i]['cat_desc'])) ); 
	} 
//
//  Tournaments Output
//
	$tour_link = '';
	if ($arcade->arcade_config['games_tournament_mode'])
	{
		$total_tournaments = get_total_tour();
		if($total_tournaments > 0)
		{
      $tour_link = sprintf($lang['Active_Tournaments_link'], $total_tournaments, "arcade_tournament.".$phpEx);
    }
    else
    {
      $tour_link = sprintf($lang['Active_Tournaments'], $total_tournaments);
    }
    $last_tour = game_get_last_tour();
    $display_last_tour = '';
    if(is_array($last_tour))
    {
      $last_tour_user_id  = $last_tour['user_id'];
      $last_tour_date     = create_date($board_config['default_dateformat'], $last_tour['last_played_time'], $board_config['board_timezone']);
      $display_last_tour = '<a href="arcade_tournament.'.$phpEx.'?mode=tour&amp;id='.$last_tour['tour_id'].'" class="gensmall">'.$last_tour['tour_name'].'</a><br>'.$last_tour_date.'<br><a href="'.append_sid("profile.$phpEx?mode=viewprofile&amp;" . POST_USERS_URL . "=$last_tour_user_id").'" class="gensmall">'. $arcade->get_username($last_tour_user_id) .'</a>';
    }
//
//  Turn Tournament Info ON
//
		$template->assign_block_vars('tournament_menu', array(
		    'TOUR' => $lang['tournaments'],
        'L_TOUR' => append_sid("arcade_tournament.$phpEx"),
        'TOUR_TOTAL' => $total_tournaments,
        'TOUR_PLAYED' => game_tour_played(),
        'TOUR_LAST' => $display_last_tour,
      ));
  }
//
//  All Games Output
//
  if ($arcade->arcade_config['games_show_all'])
	{
 		if($catrows[0]['last_player'] != ANONYMOUS)
 		{
 			$temp_url = append_sid("profile.$phpEx?mode=viewprofile&amp;" . POST_USERS_URL . "=". $catrows[0]['last_player'] . "");
 			if($catrows[0]['user_allow_viewonline'] || $userdata['user_level'] == ADMIN || $userdata['user_level'] == MOD)
 			{
 				$profile = '<a href="' . $temp_url . '" class="gensmall"><span class="gentbl">' . $catrows[0]['username'] . '</span></a>';
 			}
 			else
 			{
 				$profile = $lang['game_hidden'];
 			}
 		}
 		else
 		{
 			$profile = $lang['Guest'];
 		}
    $last_game_desc = $catrows[0]['game_desc'];
   	if(strlen($catrows[0]['game_desc']) > 25)
   	{
   		$last_game_desc =	trim(substr_replace ( $catrows[0]['game_desc'], ' ', 23, strlen($cat_rows[0]['game_desc']) )) . '..';
   	}
//
//  Build the Last Played Info
//
   	$last_played_all = '<a href="' . $filename . '?mode=cat&amp;cat_id=0#'.$catrows[0]['game_id'].'" class="gensmall">' . $last_game_desc;
    $last_played_all .= '</a><br />' . create_date($board_config['default_dateformat'], $catrows[0]['last_time'], $board_config['board_timezone']);
    $last_played_all .= '<br />' . $profile;
//
//  Turn the All Games Section ON
//
  	$template->assign_block_vars("all_games", array(
   		'LINK' => append_sid("$filename?mode=cat&amp;cat_id=0"),
  		'DESC' => $arcade->arcade_config['games_cat_zero'],
  		'TOTAL_GAMES' => $arcade->convert_score($catrows[0]['total_games']),
  		'TOTAL_PLAYED' => $arcade->convert_score($catrows[0]['total_played']),
  		'LAST_ALL' => ($catrows[0]['last_player']) ? $last_played_all : '',
  		'ICON' => '<img src="images/games.gif" border="0">',
		));
	}
//
//  Statistics Output
//
	if($arcade->arcade_config['games_show_stats'] == 1)
	{
		$top_games_list = games_list('DESC', $arcade->arcade_config['games_total_top'], 0);
	
		if($arcade->arcade_config['games_new_games'])
		{
			$bottom_games_list = games_list('ASC', $arcade->arcade_config['games_total_top'], 0, 'date_added DESC, game_id');
			$bottom_games_header = sprintf($lang['games_new_header'], $arcade->arcade_config['games_total_top']);
		}
		else
		{
			$bottom_games_list = games_list('ASC', $arcade->arcade_config['games_total_top'],0);
			$bottom_games_header = $lang['games_bum_header'];
		}
		
		$total_scores = $arcade->total_highscores($user_id);
		$score_text = sprintf($lang['games_highscore'] . "<img src=\"images/trophy.gif\">" . $lang['highscore_you_have'] . "<b>%d</b> <a href=\"$filename?mode=game_stats&amp;id=4\">". $lang['1st'] . $lang['places'] . "</a><br /><b>%d</b> <a href=\"$filename?mode=game_stats&amp;id=5\">" . $lang['2nd'] . $lang['places'] . "</a><br /><b>%d</b> <a href=\"$filename?mode=game_stats&amp;id=6\">" . $lang['3rd'] . $lang['places'] . "</a><br />", $total_scores[1] ,$total_scores[2] ,$total_scores[3] );
		if($userdata['user_id'] != ANONYMOUS)
		{
			$at_scores = $arcade->total_highscores($user_id, iNA_AT_SCORES);
			$at_score_text = sprintf($lang['games_athighscore'] . "<img src=\"images/trophy.gif\">" . $lang['highscore_you_have'] . "<b>%d</b> <a href=\"$filename?mode=game_stats&amp;id=1\">" . $lang['1st'] . $lang['places'] . "</a><br /><b>%d</b> <a href=\"$filename?mode=game_stats&amp;id=2\">" . $lang['2nd'] . $lang['places'] . "</a><br /><b>%d</b> <a href=\"$filename?mode=game_stats&amp;id=3\">" . $lang['3rd'] . $lang['places'] . "</a><br />", $at_scores[1] ,$at_scores[2] ,$at_scores[3] );
		}
//
//  Turn Stats Info ON
//	
		$template->assign_block_vars('stats_menu', array(
			'L_INFO_STATS' => $lang['cat_info_stats'],
			'TOP_HEADER' => sprintf($lang['games_top_header'], $arcade->arcade_config['games_total_top']),
			'BOTTOM_HEADER' => $bottom_games_header,

			'BEST_PLAYER' => best_player(),
			'BEST_AT_PLAYER' => best_player('at_first_places'),
			'LEADER' => $score_text, 
			'AT_LEADER' => $at_score_text, 

			'TOP_TEN_LIST' => smilies_pass($top_games_list),
			'BOTTOM_TEN_LIST' => smilies_pass($bottom_games_list)
		));
	}
//
//  Basics for Page
//
	$template->assign_vars(array(
		'L_WELCOME' => sprintf($lang['game_welcome'], $board_config['sitename']),
		'L_WELCOME_GUEST' => sprintf($lang['game_guest_welcome'], $board_config['sitename']),
		'L_SEARCH' => $lang['Search'],
		'L_SUBMIT' => $lang['Submit'],
		'L_LIST_FAV' => $lang['play_favorites'],
		'L_ACTIVE_TOURNAMENTS' => ($arcade->arcade_config['games_tournament_mode']) ? game_list_tour() : '',
		'L_CAT_ID' => "#",
		'L_CATS' => $lang['games_section'],
		'L_TOTAL_GAMES' => $lang['cat_total_games'],
		'L_TOTAL_PLAYED' => $lang['cat_total_played'],
		'L_LAST_PLAYED' => $lang['cat_last_played'],
		
		'U_CAT' => $url,
		'U_LIST_FAV' =>  append_sid("$filename?mode=fav"),

		'S_HIDDEN_OPTIONS' => '<input type="hidden" name="redirect" value="' . append_sid($filename) . '">',

		'ACTIVE_TOURNAMENTS' => $tour_link,
		'LAST_PLAYED' => last_played($userdata['user_id']),
		'LAST_PLAYED_SCORE' => last_played_score(),
		'CAT_JUMP' => $arcade->jump_cat(),
		'ARCADE_MOD' => sprintf($lang['activitiy_mod_info'], $arcade->version)));
}
//
//  Only used for dEfEndErs META Mod
//
$page_meta_desc = $page_title;
//
// Generate page
//
include($phpbb_root_path . 'includes/page_header.'.$phpEx);
$template->pparse('body');
include($phpbb_root_path . 'includes/page_tail.'.$phpEx);
//
//  That's All Folks
//
?>
