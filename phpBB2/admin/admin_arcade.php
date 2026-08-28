<?php
/***************************************************************************
 *
 *                             admin_arcade.php
 *                             ----------------
 *   begin                : Monday, Jan 2nd, 2007
 *   copyright            : (c)2003-2007 www.phpbb-arcade.com
 *   email                : defenders_realm@yahoo.com
 *
 *   $Id : admin_arcade.php, v 2.1.8 2007/01/02 dEfEndEr Exp $
 *   Support @ http://www.phpbb-arcade.com
 *   Expanded Activity Mod v2.0.0 2003/12/18 by Napoleon
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
 *   This is a MOD for phpbb v2.0.6+. The phpbb group has all rights to the 
 *   phpbb source. They can be contacted at :
 *   
 *      I-Net : www.phpbb.com
 *      E-Mail: support@phpbb.com
 *
 ***************************************************************************/

if (!defined('IN_PHPBB')) { define('IN_PHPBB', true); }
if (!defined('ARCADE_ADMIN')) { define('ARCADE_ADMIN', 1); }
$file = basename(__FILE__);

if( !empty($setmodules) )
{
//
//	Load DB
//
  $build_array = array();
	$sql = "SELECT * FROM phpbb_ina_data";
 	$result = $db->sql_query($sql);
 	$ina_info = $db->sql_fetchrowset($result);
  foreach ($ina_info as $key => $value)
  {
    $build_array[$value['config_name']]  = $value['config_value'];
  }      
	unset($ina_info);
 	$arcade_config = $build_array;
//
//  Main Arcade Options
//
	$module['Arcade']['Configuration'] = $file;
	$module['Arcade']['Switches'] = $file."?mode=switches";
	if($arcade_config['games_moderators_mode'])
	{
		$module['Arcade']['Moderators'] = $file."?mode=moderators";
	}
	if($arcade_config['games_use_pms'])
	{
		$module['Arcade']['Private MS'] = $file."?mode=messages";
	}
	if($arcade_config['games_tournament_mode'])
	{
		$module['Arcade']['Tournaments'] = "admin_arcade_tournaments.$phpEx";
	}
	return;
}

$phpbb_root_path = './../';

require($phpbb_root_path . 'extension.inc');
require('pagestart.' . $phpEx);
include_once($phpbb_root_path . 'includes/functions_arcade.'.$phpEx);

$version = $arcade->version($phpbb_root_path);

$reset_navbar = '';
//
// Check to see what mode we should operate in.
// 
if( isset($HTTP_POST_VARS['new_mode']) || isset($HTTP_GET_VARS['new_mode']) )
{
	$new_mode = ( isset($HTTP_POST_VARS['new_mode']) ) ? $HTTP_POST_VARS['new_mode'] : $HTTP_GET_VARS['new_mode'];
	$new_mode = htmlspecialchars($new_mode);
}
$mode = $arcade->pass_var('mode', '');
//
//  Main Config Menu
//
if ( !$mode || $mode == 'switches' || $mode == 'moderators' || $mode == 'messages' )
{
  if (($HTTP_POST_VARS['use_point_system'] == 1 && $HTTP_POST_VARS['use_cash_system'] == 1) || ($HTTP_POST_VARS['use_point_system'] == 1 && $HTTP_POST_VARS['use_allowance_system'] == 1) || ($HTTP_POST_VARS['use_cash_system'] == 1 && $HTTP_POST_VARS['use_allowance_system'] == 1))
  {
    $message = $lang['admin_arcade_reward_error'];
		$message .= sprintf($lang['admin_return_arcade'], "<a href=\"" . append_sid("admin_arcade.$phpEx?mode=".$new_mode) . "\">", "</a>") . "<br /><br />" . sprintf($lang['Click_return_admin_index'], "<a href=\"" . append_sid("index.$phpEx?pane=right") . "\">", "</a>");
		message_die(GENERAL_MESSAGE, $message, '', __LINE__, __FILE__, $sql);
  }  

	$sql = "SELECT * FROM " . iNA . "
		WHERE config_name IN('use_point_system','use_gamelib','games_path','gamelib_path','use_gk_shop','games_per_page','warn_cheater','report_cheater','use_cash_system','use_rewards_mod','use_allowance_system','default_reward_dbfield','default_cash','games_default_img','games_default_txt','games_offline','games_no_guests','games_tournament_mode','games_default_id','games_cheat_mode','games_per_admin_page','games_image_width','games_image_height','games_auto_size','games_guest_highscore','games_at_highscore','games_show_stats','games_tournament_max','games_tournament_games','games_tournament_players','games_moderators_mode','games_mod_offline','games_mod_ban_users','games_posts_required','games_rank_required','games_group_required','games_use_pms', 'games_total_top', 'games_new_games', 'games_cat_zero', 'games_comments', 'games_rate', 'games_cat_image_width', 'games_cat_image_height', 'games_show_played', 'games_show_fav', 'games_show_all', 'games_pm_new', 'games_pm_highscore', 'games_pm_at_highscore', 'games_pm_comment','default_sort','default_sort_order','games_new_for','games_show_mhm', 'games_default_rate', 'games_use_log')";

	if( !$result = $db->sql_query($sql) )
	{
    $sql_error = $db->sql_error();
    if($sql_error['code'] == 1054)
    {
      $work = explode(' ', $sql_error['message']);
      if((strtoupper($work[0]) == 'UNKNOWN') && (strtoupper($work[1]) == 'COLUMN'))
      {
      	$sql = "INSERT INTO " . iNA . " (`config_name`) VALUES (" . $work[2] . ")";
        echo('<center>'.$sql.'</center>');
        if(!($result = $db->sql_query($sql)))
        {
          message_die(CRITICAL_ERROR, '', '', __LINE__, __FILE__, $sql);
        }
      }
    }
    else
    {
		  message_die(CRITICAL_ERROR, $lang['no_config_data'], '', __LINE__, __FILE__, $sql);
		}
	}
	else
	{
		if (($arcade->arcade_config['default_reward_dbfield'] != 'user_money') && ($arcade->arcade_config['use_allowance_system']))
		{
			$sql = "UPDATE " . iNA . "
				SET config_value = 'user_money'
				WHERE config_name = 'default_reward_dbfield'";
			if( !$db->sql_query($sql) )
			{
				message_die(GENERAL_ERROR, $lang['no_config_update'] . 'default_reward_dbfield', '', __LINE__, __FILE__, $sql);
			}
		}
		
		if (($board_config['default_reward_dbfield'] != $board_config['default_cash']) && ($arcade->arcade_config['use_cash_system']))
		{
			$sql = "UPDATE " . iNA . "
				SET config_value = '" . $board_config['default_cash'] . "'
				WHERE config_name = 'default_reward_dbfield'";
			if( !$db->sql_query($sql) )
			{
				message_die(GENERAL_ERROR, $lang['no_config_update'] . 'default_reward_dbfield', '', __LINE__, __FILE__, $sql);
			}
		}

		while( $row = $db->sql_fetchrow($result) )
		{
			$config_name = $row['config_name'];
			$config_value = $row['config_value'];
			$default_config[$config_name] = $config_value;
     
			$new[$config_name] = ( isset($HTTP_POST_VARS[$config_name]) ) ? $HTTP_POST_VARS[$config_name] : $default_config[$config_name];

			if(((isset($HTTP_POST_VARS['games_tournament_mode']) && $HTTP_POST_VARS['games_tournament_mode'] != $arcade->arcade_config['games_tournament_mode'])) || (isset($HTTP_POST_VARS['games_moderators_mode']) && ($HTTP_POST_VARS['games_moderators_mode'] != $arcade->arcade_config['games_moderators_mode'])) || (isset($HTTP_POST_VARS['games_use_pms']) && ($HTTP_POST_VARS['games_use_pms'] != $arcade->arcade_config['games_use_pms'])))
			{
//
//	Navbar reload by xore.
//
				$reset_navbar = "\n<script language=\"JavaScript\" type=\"text/javascript\">\n<!--\nparent.nav.location.reload();\n//-->\n</script>";	
			}

			if(isset($HTTP_POST_VARS['submit']))
			{
				if ( $new['games_path'][strlen($new['games_path'])-1] != '\\' && $new['games_path'][strlen($new['games_path'])-1] != '/')
				{
					$new['games_path'] .= '/';
				}
				if ( $new['games_path'][0] == '\\' || $new['games_path'][0] == '/')
				{
					$new['games_path'][0] = ' ';
					$new['games_path'] = trim($new['games_path']);
				}
		
				$sql = "UPDATE " . iNA . "
					SET config_value = '" . str_replace("\'", "''", $new[$config_name]) . "'
					WHERE config_name = '$config_name'";

				if( !$db->sql_query($sql) )
				{
					message_die(GENERAL_ERROR, $lang['no_config_update'] . $config_name, '', __LINE__, __FILE__, $sql);
				}
			}
		}
		if( isset($HTTP_POST_VARS['submit']) )
		{	
//
//  Force update of cache files
//
      $arcade->clear_cache('config');
      
			$message = $lang['admin_config_updated'] . $reset_navbar;
			$message .= sprintf($lang['admin_return_arcade'], "<a href=\"" . append_sid("$file?mode=".$new_mode) . "\">", "</a>") . "<br /><br />" . sprintf($lang['Click_return_admin_index'], "<a href=\"" . append_sid("index.$phpEx?pane=right") . "\">", "</a>");
			message_die(GENERAL_MESSAGE, $message);
		}
	}
//
//  Get Ranks Info
//
	$select_rank = '<select name="games_rank_required">';
	$sql = "SELECT rank_id, rank_title FROM " . RANKS_TABLE . "
		WHERE rank_special = 0
		ORDER BY rank_id";
	if( !$result = $db->sql_query($sql) )
	{
		message_die(CRITICAL_ERROR, $lang['no_config_data'], '', __LINE__, __FILE__, $sql);
	}
	$select_rank .= '<option value="' . 0 . '">' . $lang['All'] . '</option>';
	while ($ranks_info = $db->sql_fetchrow($result))
	{
		$selected = ( $ranks_info['rank_id'] == $arcade->arcade_config['games_rank_required'] ) ? ' selected="selected"' : '';
		$select_rank .= '<option value="' . $ranks_info['rank_id'] . '"' . $selected . '>' . $ranks_info['rank_title'] . '</option>';
	}	
	$select_rank .= '</select>';
//
//  Get Groups Info
//
	$select_group = '<select name="games_group_required">';
  $sql = "SELECT group_id, group_name FROM " . GROUPS_TABLE . "
    WHERE group_single_user <> " . TRUE . "
    ORDER BY group_name";
	if( !$result = $db->sql_query($sql) )
	{
		message_die(CRITICAL_ERROR, $lang['no_config_data'], '', __LINE__, __FILE__, $sql);
	}
	$select_group .= '<option value="' . 0 . '">' . $lang['All'] . '</option>';
	while ($group_info = $db->sql_fetchrow($result))
	{
		$selected = ( $group_info['group_id'] == $arcade->arcade_config['games_group_required'] ) ? ' selected="selected"' : '';
		$select_group .= '<option value="' . $group_info['group_id'] . '"' . $selected . '>' . $group_info['group_name'] . '</option>';
	}	
	$select_group .= '</select>';
//
//  Built Default Sort & Sort Type 
//
  $select_sort = '<select name="default_sort">';
  for($i=0 ; $i < (count($mode_types_text)); $i++)
  {
		$selected = ( $mode_types[$i] == $arcade->arcade_config['default_sort'] ) ? ' selected="selected"' : '';
    $select_sort .= '<option value="'. $mode_types[$i] .'"' . $selected . '>' . $mode_types_text[$i] . '</option>';
  }
  $select_sort .= '</select>';

  if($arcade->arcade_config['default_sort_order'] == 'ASC')
  {  
    $select_sort_type = '<select name="default_sort_order"><option value="ASC" selected="selected">'.$lang['Sort_Ascending'].'</option><option value="DESC">'.$lang['Sort_Descending'].'</option></select>';
  }
  else
  {
    $select_sort_type = '<select name="default_sort_order"><option value="ASC">'.$lang['Sort_Ascending'].'</option><option value="DESC" selected="selected">'.$lang['Sort_Descending'].'</option></select>';
  }
//
//  Build the 'Show *NEW* for' option
//
  $new_for = '<select name="games_new_for">';
  for($i = 0 ; $i < 32; $i++)
  {
		$selected = ( ($i*86400) == $arcade->arcade_config['games_new_for'] ) ? ' selected="selected"' : '';
    $new_for .= '<option value="' . ($i*86400) . '"' . $selected . '>' . $i . '</option>';
  }
  $new_for .= '</select>';
//
// Set other Config Options
//	
	$games_offline_yes = ( $new['games_offline'] ) ? 'checked="checked"' : '';
	$games_offline_no  = ( !$new['games_offline'] ) ? 'checked="checked"' : '';

	$games_no_guests_yes = ( $new['games_no_guests'] ) ? 'checked="checked"' : '';
	$games_no_guests_no  = ( !$new['games_no_guests'] ) ? 'checked="checked"' : '';

	$use_gk_shop_yes = ( $new['use_gk_shop'] ) ? 'checked="checked"' : '';
	$use_gk_shop_no  = ( !$new['use_gk_shop'] ) ? 'checked="checked"' : '';

	$use_gamelib_yes = ( $new['use_gamelib'] ) ? 'checked="checked"' : '';
	$use_gamelib_no  = ( !$new['use_gamelib'] ) ? 'checked="checked"' : '';

	$use_point_system_yes = ( $new['use_point_system'] ) ? 'checked="checked"' : '';
	$use_point_system_no  = ( !$new['use_point_system'] ) ? 'checked="checked"' : '';

	$use_cash_system_yes = ( $new['use_cash_system'] ) ? 'checked="checked"' : '';
	$use_cash_system_no  = ( !$new['use_cash_system'] ) ? 'checked="checked"' : '';

	$use_allowance_system_yes = ( $new['use_allowance_system'] ) ? 'checked="checked"' : '';
	$use_allowance_system_no  = ( !$new['use_allowance_system'] ) ? 'checked="checked"' : '';

	$use_rewards_yes = ( $new['use_rewards_mod'] ) ? 'checked="checked"' : '';
	$use_rewards_no  = ( !$new['use_rewards_mod'] ) ? 'checked="checked"' : '';

	$use_cheat_yes = ( $new['games_cheat_mode'] ) ? 'checked="checked"' : '';
	$use_cheat_no  = ( !$new['games_cheat_mode'] ) ? 'checked="checked"' : '';

	$warn_cheater_yes = ( $new['warn_cheater'] ) ? 'checked="checked"' : '';
	$warn_cheater_no  = ( !$new['warn_cheater'] ) ? 'checked="checked"' : '';

	$report_cheater_yes = ( $new['report_cheater'] ) ? 'checked="checked"' : '';
	$report_cheater_no  = ( !$new['report_cheater'] ) ? 'checked="checked"' : '';

	$use_tournament_yes = ( $new['games_tournament_mode'] ) ? 'checked="checked"' : '';
	$use_tournament_no  = ( !$new['games_tournament_mode'] ) ? 'checked="checked"' : '';
	
	$use_moderators_yes = ( $new['games_moderators_mode'] ) ? 'checked="checked"' : '';
	$use_moderators_no  = ( !$new['games_moderators_mode'] ) ? 'checked="checked"' : '';

	$auto_size_yes = ( $new['games_auto_size'] ) ? 'checked="checked"' : '';
	$auto_size_no  = ( !$new['games_auto_size'] ) ? 'checked="checked"' : '';

	$guest_highscore_yes = ( $new['games_guest_highscore'] ) ? 'checked="checked"' : '';
	$guest_highscore_no  = ( !$new['games_guest_highscore'] ) ? 'checked="checked"' : '';

	$at_highscore_yes = ( $new['games_at_highscore'] ) ? 'checked="checked"' : '';
	$at_highscore_no  = ( !$new['games_at_highscore'] ) ? 'checked="checked"' : '';

	$show_stats_yes = ( $new['games_show_stats'] ) ? 'checked="checked"' : '';
	$show_stats_no  = ( !$new['games_show_stats'] ) ? 'checked="checked"' : '';

	$games_new_yes = ( $new['games_new_games'] ) ? 'checked="checked"' : '';
	$games_new_no  = ( !$new['games_new_games'] ) ? 'checked="checked"' : '';

	$show_played_yes = ( $new['games_show_played'] ) ? 'checked="checked"' : '';
	$show_played_no  = ( !$new['games_show_played'] ) ? 'checked="checked"' : '';

	$games_show_fav_yes = ( $new['games_show_fav'] ) ? 'checked="checked"' : '';
	$games_show_fav_no  = ( !$new['games_show_fav'] ) ? 'checked="checked"' : '';

	$show_all_yes = ( $new['games_show_all'] ) ? 'checked="checked"' : '';
	$show_all_no  = ( !$new['games_show_all'] ) ? 'checked="checked"' : '';

	$show_mhm_yes = ( $new['games_show_mhm'] ) ? 'checked="checked"' : '';
	$show_mhm_no  = ( !$new['games_show_mhm'] ) ? 'checked="checked"' : '';

	$use_pms_yes = ( $new['games_use_pms'] ) ? 'checked="checked"' : '';
	$use_pms_no = ( !$new['games_use_pms'] ) ? 'checked="checked"' : '';

	$pm_new_yes = ( $new['games_pm_new'] ) ? 'checked="checked"' : '';
	$pm_new_no = ( !$new['games_pm_new'] ) ? 'checked="checked"' : '';
	$pm_high_yes = ( $new['games_pm_highscore'] ) ? 'checked="checked"' : '';
	$pm_high_no = ( !$new['games_pm_highscore'] ) ? 'checked="checked"' : '';
	$pm_ahigh_yes = ( $new['games_pm_at_highscore'] ) ? 'checked="checked"' : '';
	$pm_ahigh_no = ( !$new['games_pm_at_highscore'] ) ? 'checked="checked"' : '';
	$pm_comment_yes = ( $new['games_pm_comment'] ) ? 'checked="checked"' : '';
	$pm_comment_no = ( !$new['games_pm_comment'] ) ? 'checked="checked"' : '';
	$use_log_yes = ( $new['games_use_log'] ) ? 'checked="checked"' : '';
	$use_log_no = ( !$new['games_use_log'] ) ? 'checked="checked"' : '';
	
	$mod_take_offline_yes = ( $new['games_mod_offline'] ) ? 'checked="checked"' : '';
	$mod_take_offline_no  = ( !$new['games_mod_offline'] ) ? 'checked="checked"' : '';

	$mod_ban_users_yes = ( $new['games_mod_ban_users'] ) ? 'checked="checked"' : '';
	$mod_ban_users_no  = ( !$new['games_mod_ban_users'] ) ? 'checked="checked"' : '';

	$mod_rate_yes = ( $new['games_rate'] ) ? 'checked="checked"' : '';
	$mod_rate_no  = ( !$new['games_rate'] ) ? 'checked="checked"' : '';
	
	$mod_comment_yes = ( $new['games_comments'] ) ? 'checked="checked"' : '';
	$mod_comment_no  = ( !$new['games_comments'] ) ? 'checked="checked"' : '';

	if( $mode == 'switches')
	{
		$template->set_filenames(array('body' => 'admin/arcade_switches_body.tpl'));
	}
	else if ( $mode == 'moderators' )
	{
		$template->set_filenames(array('body' => 'admin/arcade_moderators_body.tpl') );
	}
	else if ( $mode == 'messages' )
	{
		$template->set_filenames(array('body' => 'admin/arcade_messages_body.tpl') );
	}
	else
	{
		$template->set_filenames(array('body' => 'admin/arcade_config_body.tpl'));
	}
	
	if ($arcade->arcade_config['use_gamelib'])
	{
		$template->assign_block_vars('display_gamelib_menu', array());
	}
	
	if ($arcade->arcade_config['use_gk_shop'])
	{
		$template->assign_block_vars('display_shop_menu', array());
	}

	if ($arcade->arcade_config['use_rewards_mod'])
	{
		$template->assign_block_vars('rewards_menu_on', array());

		if ($arcade->arcade_config['use_cash_system'])
		{
			$template->assign_block_vars('cash_default_menu', array());
		}
	}
	if ($arcade->arcade_config['games_cheat_mode'])
	{
		$template->assign_block_vars('cheat_menu', array());
	}
	if ($arcade->arcade_config['games_tournament_mode'])
	{
		$template->assign_block_vars('tournament_menu', array());
	}
  
	$template->assign_vars(array(
		"S_CONFIG_ACTION" => append_sid($file),
		"VERSION" => $version,
		"DASH" => $lang['game_dash'],
		"DEFAULT_CASH" => $arcade->arcade_config['default_cash'],

		'L_CONFIG_MENU' => $lang['admin_config_menu'],
    'L_INA_HEADER' => $lang['admin_main_header'],
		'L_TOGGLES' => $lang['admin_toggles'],
		'L_REWARDS' => $lang['admin_rewards'],
		'L_ARCADE_CONFIG' => $lang['admin_arcade_config'],
  	'L_MOD_HEADER' => $lang['admin_moderators_header'],
		'L_MOD_INFO' => $lang['admin_moderators_info'],
		'L_MODERATORS_OPTIONS' => $lang['moderators_options'],
 		'L_MESS_HEADER' => $lang['admin_messages_header'],
		'L_MESS_INFO' => $lang['admin_messages_info'],

		"L_MOD_OFFLINE" => $lang['admin_games_offline'],
		"L_MOD_OFFLINE_INFO" => $lang['admin_games_offline_info'],
		"L_BLOCK_GUEST" => $lang['admin_no_guests'],
		"L_BLOCK_GUEST_INFO" => $lang['admin_no_guests_info'],
		"L_USE_ADAR_SHOP" => $lang['admin_use_adar_shop'], 
		"L_USE_ADAR_INFO" => $lang['admin_use_adar_info'],
		"L_USE_GAMELIB" => $lang['admin_use_gamelib'],
		"L_USE_GL_INFO" => $lang['admin_use_gl_info'],
		"L_USE_POINTS" => $lang['admin_use_points'],
		"L_USE_POINTS_INFO" => $lang['admin_use_pts_info'],
		"L_CASH" => $lang['admin_cash'],
		"L_USE_CASH" => $lang['admin_use_cash'],
		"L_USE_CASH_INFO" => $lang['admin_use_cash_info'],
		"L_CASH_DEFAULT_INFO" => $lang['admin_cash_default_info'],
		"L_USE_ALLOWANCE" => $lang['admin_use_allowance'],
		"L_USE_ALLOWANCE_INFO" => $lang['admin_use_allowance_info'],
		"L_USE_REWARDS" => $lang['admin_use_rewards'],
		"L_USE_REWARDS_INFO" => $lang['admin_use_rewards_info'],
		"L_GAMES_PATH" => $lang['admin_games_path'],
		"L_GAMES_PATH_INFO" => $lang['admin_games_path_info'],
		"L_GL_GAME_PATH" => $lang['admin_gl_game_path'],
		"L_GL_PATH_INFO" => $lang['admin_gl_path_info'],
		"L_GL_LIB_PATH" => $lang['admin_gl_lib_path'],
		"L_GL_LIB_INFO" => $lang['admin_gl_lib_info'],
		"L_GAMES_PER_PAGE" => $lang['admin_games_per_page'],
		"L_GAMES_PER_INFO" => $lang['admin_games_per_info'],
		"L_ADAR_SHOP_CONFIG" => $lang['admin_adar_config'],
		"L_ADAR_SHOP" => $lang['admin_adar_shop'],
		"L_ADAR_INFO" => $lang['admin_no_adar_info'],

		'L_PAGE' => $lang['admin_page'],
		'L_PATH' => $lang['admin_path'],
		'L_EDIT' => $lang['Edit'],
		'L_ADD' => $lang['Add_new'],
		'L_YES' => $lang['Yes'],
		'L_NO' => $lang['No'],
		'L_SUBMIT' => $lang['Submit'],
		'L_RESET' => $lang['Reset'],

		'L_CHEAT_TXT' => $lang['admin_cheat'],
		'L_CHEAT_TXT_INFO' => $lang['admin_cheat_info'],
		'L_WARN_CHEATER' => $lang['admin_warn_cheater'],
		'L_WARN_INFO' => $lang['admin_warn_cheater_info'],
		'L_REPORT_CHEATER' => $lang['admin_warn_admin'],
		'L_REPORT_INFO' => $lang['admin_warn_admin_info'],
		'L_DEFAULT_IMG' => $lang['admin_default_img'],
		'L_DEFAULT_IMG_INFO' => $lang['admin_default_img_info'],
		'L_DEFAULT_TXT' => $lang['admin_default_txt'],
		'L_DEFAULT_TXT_INFO' => $lang['admin_default_txt_info'],
		'L_GAMES_ZERO_TXT' => $lang['admin_games_zero_txt'],
		'L_GAMES_ZERO_INFO' => $lang['admin_games_zero_txt_info'],
		'L_TOURNAMENT_TXT' => $lang['admin_tournament_txt'],
		'L_TOURNAMENT_TXT_INFO' => $lang['admin_tournament_txt_info'],
		'L_MODERATORS_TXT' => $lang['admin_moderators_txt'],
		'L_MODERATORS_TXT_INFO' => $lang['admin_moderators_txt_info'],
		'L_DEFAULT_GAME_ID' => $lang['admin_default_game_id'],
		'L_DEFAULT_GAME_ID_INFO' => $lang['admin_default_game_id_info'],
		'L_DEFAULT_SORT' => $lang['admin_default_sort'],
		'L_DEFAULT_SORT_INFO' => $lang['admin_default_sort_info'],
		'L_DEFAULT_SORT_TYPE' => $lang['admin_default_sort_type'],
		'L_DEFAULT_SORT_TYPE_INFO' => $lang['admin_default_sort_type_info'],
		'L_MIN_POSTS' => $lang['admin_min_posts_txt'],
		'L_MIN_POSTS_INFO' => $lang['admin_min_posts_txt_info'],
		'L_GAMES_PER_ADMIN_INFO' => $lang['admin_games_per_admin_info'],
		'L_IMAGE_SIZE' => $lang['admin_games_image_txt'],
		'L_IMAGE_SIZE_INFO' => $lang['admin_games_image_txt_info'],
		'L_CAT_IMAGE_SIZE' => $lang['admin_cat_image_txt'],
		'L_CAT_IMAGE_SIZE_INFO' => $lang['admin_cat_image_txt_info'],
		'L_GUEST_SCORE' => $lang['admin_guest_high_txt'],
		'L_GUEST_SCORE_INFO' => $lang['admin_guest_high_txt_info'],
		'L_AUTO_SIZE' => $lang['admin_auto_size_txt'],
		'L_AUTO_SIZE_INFO' => $lang['admin_auto_size_txt_info'],
		'L_AT_HIGHSCORE' => $lang['admin_at_highscore_txt'],
		'L_AT_HIGHSCORE_INFO' => $lang['admin_at_highscore_txt_info'],
		'L_SHOW_STATS' => $lang['admin_show_stats_txt'],
		'L_SHOW_STATS_INFO' => $lang['admin_show_stats_txt_info'],
		'L_SHOW_PLAYED' => $lang['admin_show_played_txt'],
		'L_SHOW_PLAYED_INFO' => $lang['admin_show_played_txt_info'],
		'L_SHOW_FAV' => $lang['admin_show_fav_txt'],
		'L_SHOW_FAV_INFO' => $lang['admin_show_fav_txt_info'],
		'L_SHOW_ALL' => $lang['admin_show_all_txt'],
		'L_SHOW_ALL_INFO' => $lang['admin_show_all_txt_info'],
		'L_PMS_TXT' => $lang['admin_use_pms_txt'],
		'L_PMS_TXT_INFO' => $lang['admin_use_pms_txt_info'],
		'L_RANK_TXT' => $lang['admin_min_rank_txt'],
		'L_RANK_TXT_INFO' => $lang['admin_min_rank_txt_info'],
		'L_GROUP_TXT' => $lang['admin_min_group_txt'],
		'L_GROUP_TXT_INFO' => $lang['admin_min_group_txt_info'],
		'L_NEW_GAMES' => $lang['admin_show_new_txt'],
		'L_NEW_GAMES_INFO' => $lang['admin_show_new_txt_info'],
		'L_TOP_X_TXT' => $lang['admin_num_top_games_txt'],
		'L_TOP_X_INFO' => $lang['admin_num_top_games_txt_info'],
		'L_NEW_FOR' => $lang['admin_new_for_txt'],
		'L_NEW_FOR_INFO' => $lang['admin_new_for_txt_info'],
		'L_RATE' => $lang['admin_rate_txt'],
		'L_RATE_INFO' => $lang['admin_rate_txt_info'],
		'L_BAN_USERS' => $lang['admin_ban_users_txt'],
		'L_BAN_USERS_INFO' => $lang['admin_ban_users_txt_info'],
		'L_MOD_RATE' => $lang['admin_use_rate_txt'],
		'L_MOD_RATE_INFO' => $lang['admin_use_rate_txt_info'],
		'L_MOD_COMMENTS' => $lang['admin_use_comment_txt'],
		'L_MOD_COMMENTS_INFO' => $lang['admin_use_comment_txt_info'],
		'L_SHOW_MHM' => $lang['admin_show_mhm'],
		'L_SHOW_MHM_INFO' => $lang['admin_show_mhm_info'],
		'L_USE_LOG' => $lang['admin_use_log'],
		'L_USE_LOG_INFO' => $lang['admin_use_log_info'],

		'L_GAME_ID' => $lang['admin_game_id'],
		'L_POSTS' => $lang['Posts'],
		'L_WIDTH' => $lang['admin_width'],
		'L_HEIGHT' => $lang['admin_height'],
		'L_TOTAL' => $lang['Total'],
		'L_EXTRAS' => $lang['Extras'],
		'L_AMOD_OFFLINE' => $lang['amod_admin_offline'],
		'L_AMOD_SCORES' => $lang['amod_admin_scores'],
		'L_AMOD_GAMES' => $lang['amod_admin_games'],
		'L_AMOD_BAN' => $lang['amod_admin_ban'],
		
		'L_MESS_NEW' => $lang['amod_mess_new'],
		'L_MESS_HIGHSCORE' => $lang['amod_mess_highscore'],
		'L_MESS_AT_HIGHSCORE' => $lang['amod_mess_at_highscore'],
		'L_MESS_COMMENT' => $lang['amod_mess_comment'],
		
		'S_MOD_TAKE_OFFLINE_YES' => $mod_take_offline_yes,
		'S_MOD_TAKE_OFFLINE_NO' => $mod_take_offline_no,
		'S_MOD_BAN_YES' => $mod_ban_users_yes,
		'S_MOD_BAN_NO' => $mod_ban_users_no,
		'S_MOD_COMMENT_YES' => $mod_comment_yes,
		'S_MOD_COMMENT_NO' => $mod_comment_no,
		'S_MOD_RATE_YES' => $mod_rate_yes,
		'S_MOD_RATE_NO' => $mod_rate_no,
		'S_MOD_OFFLINE_YES' => $games_offline_yes,
		'S_MOD_OFFLINE_NO' => $games_offline_no,
		'S_NO_GUESTS_YES' => $games_no_guests_yes,
		'S_NO_GUESTS_NO' => $games_no_guests_no,
		'S_USE_GKS_YES' => $use_gk_shop_yes,
		'S_USE_GKS_NO' => $use_gk_shop_no,
		'S_USE_GL_YES' => $use_gamelib_yes,
		'S_USE_GL_NO' => $use_gamelib_no,
		'S_USE_PSM_YES' => $use_point_system_yes,
		'S_USE_PSM_NO' => $use_point_system_no,
		'S_USE_CASH_YES' => $use_cash_system_yes,
		'S_USE_CASH_NO' => $use_cash_system_no,
		'S_USE_ASM_YES' => $use_allowance_system_yes,
		'S_USE_ASM_NO' => $use_allowance_system_no,
		'S_USE_REWARDS_YES' => $use_rewards_yes,
		'S_USE_REWARDS_NO' => $use_rewards_no,
		'S_WARN_CHEATER_YES' => $warn_cheater_yes,
		'S_WARN_CHEATER_NO' => $warn_cheater_no,
		'S_USE_CHEAT_YES' => $use_cheat_yes,
		'S_USE_CHEAT_NO' => $use_cheat_no,
		'S_REPORT_CHEATER_YES' => $report_cheater_yes,
		'S_REPORT_CHEATER_NO' => $report_cheater_no,
		'S_USE_PMS_YES' => $use_pms_yes,
		'S_USE_PMS_NO' => $use_pms_no,
		'S_PM_NEW_YES' => $pm_new_yes,
		'S_PM_NEW_NO' => $pm_new_no,
		'S_PM_HIGH_YES' => $pm_high_yes,
		'S_PM_HIGH_NO' => $pm_high_no,
		'S_PM_AHIGH_YES' => $pm_ahigh_yes,
		'S_PM_AHIGH_NO' => $pm_ahigh_no,
		'S_PM_COMMENT_YES' => $pm_comment_yes,
		'S_PM_COMMENT_NO' => $pm_comment_no,
		'S_USE_LOG_YES' => $use_log_yes,
		'S_USE_LOG_NO' => $use_log_no,
		'S_USE_TOURNAMENT_YES' => $use_tournament_yes,
		'S_USE_TOURNAMENT_NO' => $use_tournament_no,
		'S_AUTO_SIZE_YES' => $auto_size_yes,
		'S_AUTO_SIZE_NO' => $auto_size_no,
		'S_GUEST_SCORE_YES' => $guest_highscore_yes,
		'S_GUEST_SCORE_NO' => $guest_highscore_no,
		'S_AT_HIGHSCORE_YES' => $at_highscore_yes,
		'S_AT_HIGHSCORE_NO' => $at_highscore_no,
		'S_SHOW_STATS_YES' => $show_stats_yes,
		'S_SHOW_STATS_NO' => $show_stats_no,
		'S_SHOW_PLAYED_YES' => $show_played_yes,
		'S_SHOW_PLAYED_NO' => $show_played_no,
		'S_SHOW_FAV_YES' => $games_show_fav_yes,
		'S_SHOW_FAV_NO' => $games_show_fav_no,
		'S_SHOW_ALL_YES' => $show_all_yes,
		'S_SHOW_ALL_NO' => $show_all_no,
		'S_SHOW_MHM_YES' => $show_mhm_yes,
		'S_SHOW_MHM_NO' => $show_mhm_no,
		'S_NEW_GAMES_YES' => $games_new_yes,
		'S_NEW_GAMES_NO' => $games_new_no,
		'S_USE_MODERATORS_YES' => $use_moderators_yes,
		'S_USE_MODERATORS_NO' => $use_moderators_no,

		'S_RANK' => $select_rank,
		'S_GROUP' => $select_group,
		'S_DEFAULT_SORT' => $select_sort,
		'S_DEFAULT_SORT_TYPE' => $select_sort_type,
    'S_RATE' => (($arcade->arcade_config['games_default_rate'] < 2) || ($arcade->arcade_config['games_default_rate'] > 20)) ? 20 : $arcade->arcade_config['games_default_rate'],
		'S_NEW_FOR' => $new_for,

		'S_POSTS_REQUIRED' => intval($arcade->arcade_config['games_posts_required']),
		'S_GAMES_PER_ADMIN_PAGE' => intval($arcade->arcade_config['games_per_admin_page']),
		'S_WIDTH' => intval($arcade->arcade_config['games_image_width']),
		'S_HEIGHT' => intval($arcade->arcade_config['games_image_height']),
		'S_CAT_WIDTH' => intval($arcade->arcade_config['games_cat_image_width']),
		'S_CAT_HEIGHT' => intval($arcade->arcade_config['games_cat_image_height']),
		'S_GAMES_PER_PAGE' => intval($arcade->arcade_config['games_per_page']),
		'S_DEFAULT_GAME_ID' => intval($arcade->arcade_config['games_default_id']),
		'S_TOP_X' => intval($arcade->arcade_config['games_total_top']),
		'S_DEFAULT_IMG' => $arcade->arcade_config['games_default_img'],
		'S_GUEST_TXT' => $arcade->arcade_config['games_default_txt'],
		'S_GAMES_ZERO_TXT' => $arcade->arcade_config['games_cat_zero'],
		'S_GAMES_PATH' => $arcade->arcade_config['games_path'],
		'S_GL_GAMES_PATH' => $arcade->arcade_config['games_gl_path'],
		'S_GAMELIB_PATH' => $arcade->arcade_config['gamelib_path'],
		'S_HIDDEN_FIELDS' => '<input type="hidden" name="new_mode" value="'.$mode.'">')
	);
}
//
// Generate the page
//
$template->pparse('body');
include('page_footer_admin.' . $phpEx);

?>
