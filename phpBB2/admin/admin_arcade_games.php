<?php
/*************************************************************************** 
 *                          admin_arcade_games.php 
 *                          ---------------------- 
 *   begin                : Monday, Jan 2nd, 2007
 *   copyright            : (c) 2003-2007 dEfEndEr
 *   email                : defenders_realm@yahoo.com
 *
 *   $Id: admin_arcade_games.php,v 2.1.8 2007/01/02 12:59:59 dEfEndEr Exp $
 ***************************************************************************
 * 
 *   This program is free software; you can redistribute it and/or modify 
 *   it under the terms of the GNU General Public License as published by 
 *   the Free Software Foundation; either version 2 of the License, or 
 *   (at your option) any later version. 
 * 
 ***************************************************************************/
 
if (!defined('IN_PHPBB')) { define('IN_PHPBB', true); }
if (!defined('ARCADE_ADMIN')) { define('ARCADE_ADMIN', 1); }
$file = basename(__FILE__);

if( !empty($setmodules) )
{
//
//  Arcade Games Options
//	
	$module['Arcade_Games']['Add'] = "admin_arcade_games.".$phpEx."?mode=add_game";
	$module['Arcade_Games']['Edit'] = "admin_arcade_games.".$phpEx."?mode=edit_games";
	$module['Arcade_Games']['Import'] = "admin_arcade_games.".$phpEx."?mode=import_game";
	$module['Arcade_Games']['View'] = "../activity.".$phpEx;
	$module['Arcade_Games']['Reset'] = "admin_arcade_reset.".$phpEx."?mode=home";
	$module['Arcade_Games']['Set'] = "admin_arcade_set.".$phpEx;

	return;
}
$phpbb_root_path = './../';

require($phpbb_root_path . 'extension.inc');
require('pagestart.' . $phpEx);
include_once($phpbb_root_path . 'includes/functions_arcade.'.$phpEx);

$version = $arcade->version('./../');
$mode = '';
$search_word = '';
$pagination = '&nbsp;';
//
//  Get Start of page info
//
$start = $arcade->pass_var('start', 0);
//
//  Get Catagory Info
//
$cat_id = $arcade->pass_var('cat_id', 0);
//
// See which mode we are to operate in
//
if( isset($HTTP_POST_VARS['mode']) || isset($HTTP_GET_VARS['mode']) )
{
	$mode = ( isset($HTTP_POST_VARS['mode']) ) ? $HTTP_POST_VARS['mode'] : $HTTP_GET_VARS['mode'];
	$mode = htmlspecialchars($mode);
}
else if( isset($HTTP_POST_VARS['search']))
{
  $mode = "edit_games";
  $search_word = $HTTP_POST_VARS['search_word'];
}
else if( isset($HTTP_POST_VARS['add_game']) )
{
	$mode = "add_game";
}
else if( isset($HTTP_POST_VARS['edit']) )
{
	$mode = "edit";
}
else if( isset($HTTP_POST_VARS['clear_scores']) )
{
	$mode = "clear_scores";
}
else if( isset($HTTP_POST_VARS['clear_at_scores']) )
{
	$mode = "clear_at_scores";
}
else if( isset($HTTP_POST_VARS['delete']) )
{
	$mode = "delete";
}
else if( isset($HTTP_POST_VARS['repair_game']) )
{
	$mode = "repair_game";
}
else if( isset($HTTP_POST_VARS['game_up']) )
{
	$mode = "game_up";
}
else if( isset($HTTP_POST_VARS['game_down']) )
{
	$mode = "game_down";
}
else if( isset($HTTP_POST_VARS['edit_games']) )
{
	$mode = "edit_games";
}
else if( isset($HTTP_POST_VARS['sort_submit']) )
{
	$mode = "sort_submit";
}
//
//  Build Sort Option
//
if (isset($HTTP_GET_VARS['sort_mode']) || isset($HTTP_POST_VARS['sort_mode']))
{
	$sort_mode = (isset($HTTP_GET_VARS['sort_mode'])) ? $HTTP_GET_VARS['sort_mode'] : $HTTP_POST_VARS['sort_mode'];
	$sort_mode = htmlspecialchars($sort_mode);
}
else
{
	$sort_mode = 'game_id';
}
$start = $arcade->pass_var('start', 0);
if(isset($HTTP_POST_VARS['order']))
{
	$sort_order = ($HTTP_POST_VARS['order'] == 'DESC') ? 'DESC' : 'ASC';
}
else if(isset($HTTP_GET_VARS['order']))
{
	$sort_order = ($HTTP_GET_VARS['order'] == 'DESC') ? 'DESC' : 'ASC';
}
else
{
	$sort_order = 'DESC';
}
$mode_types_text = array('ID', $lang['admin_allow_guests'], $lang['admin_date_added'], $lang['admin_alphabetically'], $lang['admin_category'], $lang['admin_available'], $lang['admin_game_played']);
$mode_types = array('game_id','allow_guest', 'date_added','alphabetical','cat_id','offline','game_played');
$select_sort_mode = '<select name="sort_mode">';
for($i = 0; $i < count($mode_types_text); $i++)
{
	$selected = ( $sort_mode == $mode_types[$i] ) ? ' selected="selected"' : '';
	$select_sort_mode .= '<option value="' . $mode_types[$i] . '"' . $selected . '>' . $mode_types_text[$i] . '</option>';
}
$select_sort_mode .= '</select>';
$select_sort_order = '<select name="order">';
if($sort_order == 'ASC')
{
	$select_sort_order .= '<option value="DESC">' . $lang['Sort_Descending'] . '</option><option value="ASC" selected="selected">' . $lang['Sort_Ascending'] . '</option>';
}
else
{
	$select_sort_order .= '<option value="DESC" selected="selected">' . $lang['Sort_Descending'] . '</option><option value="ASC">' . $lang['Sort_Ascending'] . '</option>';
}
$select_sort_order .= '</select>';
switch( $sort_mode )
{
	case 'alphabetical':
		$order_by = "game_desc";
		break;
		
	case 'offline':
		$order_by = "game_avail, game_desc";
		break;

	case 'allow_guest':
		$order_by = "allow_guest";
		break;

	case 'cat_id':
		$order_by = "cat_id";
		break;

	case 'game_played':
		$order_by = "played";
		break;
		
	case 'date_added':
		$order_by = "date_added";
		break;

	default:
		$order_by = "game_id";
		break;
}
//
//  Start Processing the Operatation
//
if( $mode == "game_down" || $mode == "game_up" || !empty($HTTP_POST_VARS['move_to']))
{
  $move_to = $arcade->pass_var('move_to',0);
	$old_id  = $arcade->pass_var('id',0);
	$game_id = $arcade->pass_var('new_id', 0);
  
	if( $mode == "game_up" )
	{
		$new_id = $game_id ? $game_id : ($old_id + 1);
	}
	elseif( $mode == "game_down" )
	{
		$new_id = $game_id ? $game_id : ($old_id - 1);
	}
	else
	{
  	if($move_to == 0)
  	{
      message_die(GENERAL_MESSAGE, $lang['admin_move_failed']);
    }
    $new_id = $move_to;
  }
	$sql = "SELECT game_id FROM " . iNA_GAMES . "
		ORDER BY game_id DESC LIMIT 0,1";
	if( !$result = $db->sql_query($sql) )
	{
		message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
	}
	$game_count = $db->sql_fetchrow($result);

	if ($new_id > $game_count['game_id'])
	{
		message_die(GENERAL_ERROR, $lang['game_at_top']);
	}
	if ($new_id < 1)
	{
		message_die(GENERAL_ERROR, $lang['game_at_bottom']);
	}

	$sql = "SELECT * FROM " . iNA_GAMES . "
		WHERE game_id = $new_id";
	if(!$result = $db->sql_query($sql))
	{
		// ID is free so take it.
		$sql = "UPDATE " . iNA_GAMES . "
			SET game_id = $new_id
			WHERE game_id = $old_id";
		if(!$result = $db->sql_query($sql))
		{
			message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
		}
	}
	else
	{
//
// Check that slot 0 is free, if not, remove it and re-add it to the database.
//
		$sql = "SELECT * FROM " . iNA_GAMES . "
			WHERE game_id = 0";
		if(!$result = $db->sql_query($sql))
		{
			message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
		}
		$game_data = $db->sql_fetchrow($result);
		if (!$game_data)
		{
//
// Slot Zero is free, get on with it
//
			$sql = "UPDATE " . iNA_GAMES . "
				SET game_id = 0
				WHERE game_id = $new_id";
			if(!$result = $db->sql_query($sql))
			{
				message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
			}
			$sql = "UPDATE " . iNA_GAMES . "
				SET game_id = $new_id
				WHERE game_id = $old_id";
			if(!$result = $db->sql_query($sql))
			{
				message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
			}
			$sql = "UPDATE " . iNA_GAMES . "
				SET game_id = $old_id
				WHERE game_id = 0";
			if(!$result = $db->sql_query($sql))
			{
				message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
			}
		}
		else
		{
//
// Slot Zero is taken, move to new position
//		
			$sql = "INSERT INTO " . iNA_GAMES . " (game_name, game_path, image_path, game_desc, game_charge, game_reward, game_bonus, game_use_gl, game_flash, game_show_score, game_avail, allow_guest, reverse_list, win_width, win_height, highscore_limit, instructions, at_game_bonus, at_highscore_limit, cat_id) 
				VALUES ('" . $game_data['game_name'] . "', '" . $game_data['game_path'] . "', '" . $game_data['image_path'] . "', '" . $game_data['game_desc'] . "', '" . $game_data['game_charge'] . "', '" . $game_data['game_reward'] . "', '" . $game_data['game_bonus'] . "', '" . $game_data['game_use_gl']
				 . "', '" . $game_data['game_flash'] . "', '" . $game_data['game_show_score'] . "', " . $game_data['game_avail'] . ", " . $game_data['allow_guest'] . ", '" . $game_data['reverse_list'] . "', '" . $game_data['win_width'] . "', '" . $game_data['win_height'] . "', '" . $game_data['highscore_limit']
				  . "', '" . $game_data['instructions'] . "', '" . $game_data['at_game_bonus'] . "', '" . $game_data['at_highscore_limit'] . "', '" . $game_data['cat_id'] . "')";
			if(!$result = $db->sql_query($sql))
			{
				message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
			}
			$sql = "DELETE FROM " . iNA_GAMES . "
				WHERE game_id = 0";
			if(!$result = $db->sql_query($sql))
			{
				message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
			}
			message_die(GENERAL_ERROR, $lang['game_move_error']);
		}		
	}
	$template->set_filenames(array('body' => 'admin/arcade_games_edit_body.tpl'));
}

else if( $mode == "edit" || $mode == "add_game" )
{
	$game_id = $arcade->pass_var('id', 0);
	$game_id = intval($game_id);
	if(!$game_id)
	{
		$game_id = intval($arcade->arcade_config['games_default_id']);
	}
	$sql = "SELECT * FROM " . iNA_GAMES . "
		WHERE game_id = $game_id";
	if(!$result = $db->sql_query($sql))
	{
		message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
	}
	$game_info = $db->sql_fetchrow($result);
	$game_info = array_merge(array(
		'game_use_gl' => 0, 'game_flash' => 0, 'game_show_score' => 0,
		'game_avail' => 0, 'allow_guest' => 0, 'reverse_list' => 0,
		'game_autosize' => 0, 'score_type' => 0, 'group_required' => 0,
		'rank_required' => 0, 'level_required' => 0, 'cat_id' => 0,
		'game_control' => 0, 'game_name' => '', 'game_path' => '',
		'image_path' => '', 'game_desc' => '', 'instructions' => '',
		'game_charge' => 0, 'game_reward' => 0, 'game_bonus' => 0,
		'at_game_bonus' => 0, 'highscore_limit' => 0, 'at_highscore_limit' => 0,
		'win_width' => 0, 'win_height' => 0
	), is_array($game_info) ? $game_info : array());
	if($mode == "add_game")
	{
		if($game_id == 0)
		{
			$game_info['game_flash'] =
			$game_info['game_show_score'] = 1;
		}
		$game_info['game_path'] = $arcade->arcade_config['games_path'];
		$game_info['game_name'] =
		$game_info['image_path'] =
		$game_info['game_desc'] =
		$game_info['instructions'] = '';
		$game_info['score_type'] =
		$game_id = 0;
	}
	$use_gamelib_yes = ( $game_info['game_use_gl'] ) ? 'checked="checked"' : '';
	$use_gamelib_no  = ( !$game_info['game_use_gl'] ) ? 'checked="checked"' : '';

	$use_flash_yes = ( $game_info['game_flash'] ) ? 'checked="checked"' : '';
	$use_flash_no  = ( !$game_info['game_flash'] ) ? 'checked="checked"' : '';

	$show_score_yes = ( $game_info['game_show_score'] ) ? 'checked="checked"' : '';
	$show_score_no  = ( !$game_info['game_show_score'] ) ? 'checked="checked"' : '';

	$offline_yes = ( $game_info['game_avail'] ) ? 'checked="checked"' : '';
	$offline_no  = ( !$game_info['game_avail'] ) ? 'checked="checked"' : '';

	$allow_guest_yes = ( $game_info['allow_guest'] ) ? 'checked="checked"' : '';
	$allow_guest_no  = ( !$game_info['allow_guest'] ) ? 'checked="checked"' : '';

	$reverse_list_yes = ( $game_info['reverse_list'] ) ? 'checked="checked"' : '';
	$reverse_list_no  = ( !$game_info['reverse_list'] ) ? 'checked="checked"' : '';

	$autosize_yes = ( $game_info['game_autosize'] ) ? 'checked="checked"' : '';
	$autosize_no  = ( !$game_info['game_autosize'] ) ? 'checked="checked"' : '';
//
// Score Save type
//
	$save_types_text = array($lang['Auto'], $lang['admin_get_method'], $lang['admin_post_method'], $lang['admin_new_method'], $lang['admin_ibPro_method'], $lang['admin_mixed_method'], $lang['admin_pnflashgames_method'], $lang['admin_vbulletin_method'], $lang['admin_ibprov3_method']);
	$save_types = array(ARCADE_AUTO, ARCADE_GET, ARCADE_POST, ARCADE_NEW, ARCADE_IBPRO, ARCADE_MIXED, ARCADE_pnFlashGames, ARCADE_vBULLETIN, ARCADE_IBPROv3);
	$score_type = '<select name="score_type">';
	for($i = 0; $i < count($save_types_text); $i++)
	{
		$selected = ( $game_info['score_type'] == $save_types[$i] ) ? ' selected="selected"' : '';
		$score_type .= '<option value="' . $save_types[$i] . '"' . $selected . '>' . $save_types_text[$i] . '</option>';
	}
	$score_type .= '</select>';
//
// Game Group Required
//
  $group_type =  '<select name="group_required">';
  $sql = "SELECT group_id, group_name FROM " . GROUPS_TABLE . "
    WHERE group_single_user <> " . TRUE . "
    ORDER BY group_name";
	if( !$result = $db->sql_query($sql) )
	{
		message_die(CRITICAL_ERROR, $lang['no_config_data'], '', __LINE__, __FILE__, $sql);
	}
  $group_type .= '<option value="' . 0 . '">' . $lang['All'] . '</option>';
	while ($groups_info = $db->sql_fetchrow($result))
	{
		$selected = ( $groups_info['group_id'] == $game_info['group_required'] ) ? ' selected="selected"' : '';
		$group_type .= '<option value="' . $groups_info['group_id'] . '"' . $selected . '>' . $groups_info['group_name'] . '</option>';
	}	
	$group_type .= '</select>';
//
// Game Min Rank
//
	$rank_type = '<select name="rank_required">';
	$sql = "SELECT rank_id, rank_title FROM " . RANKS_TABLE . "
		WHERE rank_special = 0
		ORDER BY rank_id";
	if( !$result = $db->sql_query($sql) )
	{
		message_die(CRITICAL_ERROR, $lang['no_config_data'], '', __LINE__, __FILE__, $sql);
	}
	$rank_type .= '<option value="' . 0 . '">' . $lang['All'] . '</option>';
	while ($ranks_info = $db->sql_fetchrow($result))
	{
		$selected = ( $ranks_info['rank_id'] == $game_info['rank_required'] ) ? ' selected="selected"' : '';
		$rank_type .= '<option value="' . $ranks_info['rank_id'] . '"' . $selected . '>' . $ranks_info['rank_title'] . '</option>';
	}	
	$rank_type .= '</select>';
//
// Game Level Required
//
	$level_types_text = array($lang['All'], $lang['Auth_Registered_Users'], $lang['Auth_Moderators'], $lang['Auth_Administrators']);
	$level_types = array('0', USER, MOD, ADMIN);
	$level_type = '<select name="level_required">';
	for($i = 0; $i < count($level_types_text); $i++)
	{
		$selected = ( $game_info['level_required'] == $level_types[$i] ) ? ' selected="selected"' : '';
		$level_type .= '<option value="' . $level_types[$i] . '"' . $selected . '>' . $level_types_text[$i] . '</option>';
	}
	$level_type .= '</select>';

	$template->set_filenames(array( "body" => "admin/arcade_games_edit_body.tpl") );

	if($arcade->arcade_config['use_point_system'] && !empty($board_config['points_name']))
	{
		$money_name = $board_config['points_name'];
	}
	else if($arcade->arcade_config['use_cash_system'])
	{
		include($phpbb_root_path . 'includes/rewards_api.'.$phpEx);
		$money_name = get_cash_name();
	}
	else
	{
		$money_name = $lang['admin_points'];
	}
	$cat_info = $arcade->read_cat();
	$select_cat = '<select name="game_cat_id">';
	$select_cat .= '<option value="' . 0 . '">' . $lang['None'] . '</option>';
	for($i = 1; $i < count($cat_info); $i++)
	{
	  $space = '';
    if($cat_info[$i]['cat_type'] == 'l')
	  {
       continue;
    }
    if($cat_info[$i]['cat_type'] == 's')
	  {
       $space = '|_';
    }
		$selected = ( $cat_info[$i]['cat_id'] == intval($game_info['cat_id']) ) ? ' selected="selected"' : '';
		$select_cat .= '<option value="' . $cat_info[$i]['cat_id'] . '"' . $selected . '>' . $space . $cat_info[$i]['cat_name'] . '</option>';
	}	
	$select_cat .= '</select>';

  $game_control = array($lang['None'], $lang['admin_mouse'], $lang['admin_keyboard'], $lang['admin_both']);
	$select_control = '<select name="game_control">';
	for($i = 0; $i < 4 ; $i++)
	{
		$selected = ( $game_info['game_control'] == $i ) ? ' selected="selected"' : '';
		$select_control .= '<option value="' . $i . '"' . $selected . '>' . $game_control[$i] . '</option>';
	}	
	$select_control .= '</select>';

	$template->assign_vars(array(
		"S_GAME_ACTION" => append_sid("$file"),
		"VERSION" => $version,
		"DASH" => $lang['game_dash'],

		"NAME" => $game_info['game_name'],
		"PATH" => $game_info['game_path'],
		"IMAGE" => $game_info['image_path'],
		"DESC" => $game_info['game_desc'],
		"CHARGE" => $game_info['game_charge'],
		"REWARD" => $game_info['game_reward'],
		"BONUS" => $game_info['game_bonus'],
		"AT_BONUS" => $game_info['at_game_bonus'],
		"CATAGORY" => $select_cat,
		"CONTROL" => $select_control,
		"HIGHSCORE_LIMIT" => $game_info['highscore_limit'],
		"AT_HIGHSCORE_LIMIT" => $game_info['at_highscore_limit'],
		"WIDTH" => $game_info['win_width'],
		"HEIGHT" => $game_info['win_height'],
		"GAME_INSTRUCTIONS" => $game_info['instructions'],
		"SCORE_TYPE" => $score_type,
		"GROUP_TYPE" => $group_type,
		"RANK_TYPE" => $rank_type,
		"LEVEL_TYPE" => $level_type,

		"L_MENU_HEADER" => $lang['admin_game_editor'],
		"L_MENU_INFO" => $lang['admin_editor_info'],
		"L_GAME_CAT" => $lang['admin_cat'],
		"L_GAME_CAT_INFO" => $lang['admin_cat_info'],
		"L_GAME_CONTROL" => $lang['admin_control'],
		"L_GAME_CONTROL_INFO" => $lang['admin_control_info'],
		"L_NAME" => $lang['admin_name'],
		"L_NAME_INFO" => $lang['admin_name_info'],
		"L_GAME_PATH" => $lang['admin_game_path'],
		"L_IMAGE_PATH" => $lang['admin_image_path'],
		"L_IMAGE_PATH_INFO" => $lang['admin_image_path_info'],
		"L_GAME_PATH_INFO" => $lang['admin_game_path_info'],
		"L_GAME_DESC" => $lang['admin_game_desc'],
		"L_GAME_DESC_INFO" => $lang['admin_game_desc_info'],
		"L_GAME_CHARGE" => $lang['admin_game_charge'],
		"L_GAME_CHARGE_INFO" => $lang['admin_game_charge_info'],
		"L_GAME_PER" => $lang['admin_game_per'],
		"L_GAME_PER_INFO" => $lang['admin_game_per_info'],
		"L_GAME_BONUS" => $lang['admin_game_bonus'],
		"L_GAME_BONUS_INFO" => $lang['admin_game_bonus_info'],
		"L_GAME_GAMELIB" => $lang['admin_game_gamelib'],
		"L_GAME_GAMELIB_INFO" => $lang['admin_game_gamelib_info'],
		"L_GAME_FLASH" => $lang['admin_game_flash'],
		"L_GAME_FLASH_INFO" => $lang['admin_game_flash_info'],
		"L_GAME_SHOW_SCORE" => $lang['admin_game_show_score'],
		"L_GAME_SHOW_INFO" => $lang['admin_game_show_info'],
		"L_GAME_REVERSE" => $lang['admin_game_reverse'],
		"L_GAME_REVERSE_INFO" => $lang['admin_game_reverse_info'],
		"L_GAME_OFFLINE" => $lang['admin_game_offline'],
		"L_GAME_OFFLINE_INFO" => $lang['admin_game_offline_info'],

		"L_GAME_GUEST" => $lang['admin_game_guest'],
		"L_GAME_GUEST_INFO" => $lang['admin_game_guest_info'],
		"L_GAME_LEVEL" => $lang['admin_game_level'],
		"L_GAME_LEVEL_INFO" => $lang['admin_game_level_info'],
		"L_GAME_RANK" => $lang['admin_game_rank'],
		"L_GAME_RANK_INFO" => $lang['admin_game_rank_info'],
		"L_GAME_GROUP" => $lang['admin_game_group'],
		"L_GAME_GROUP_INFO" => $lang['admin_game_group_info'],

		"L_GAME_AUTO_SIZE" => $lang['admin_game_autosize'],
		"L_GAME_AUTO_SIZE_INFO" => $lang['admin_game_autosize_info'],
		"L_GAME_SCORE_TYPE" => $lang['admin_game_score_type'],
		"L_GAME_SCORE_TYPE_INFO" => $lang['admin_game_score_type_info'],
		"L_GAME_GROUP_REQUIRED" => isset($lang['admin_group_required']) ? $lang['admin_group_required'] : $lang['admin_game_group'],
		"L_GAME_RANK_REQUIRED" => $lang['admin_rank_required'],
		"L_GAME_LEVEL_REQUIRED" => $lang['admin_level_required'],

		"L_HIGHSCORE_LIMIT" => $lang['admin_game_highscore'],
		"L_HIGHSCORE_INFO" => $lang['admin_game_highscore_info'],
		"L_GAME_SIZE" => $lang['admin_game_size'],
		"L_GAME_SIZE_INFO" => $lang['admin_game_size_info'],
		"L_INSTRUCTIONS" => $lang['game_instructions'],
		"L_INSTRUCTIONS_INFO" => $lang['instructions_info'],
		"L_GAME_RESET_SCORE" => $lang['admin_game_reset_hs'],
		"L_GAME_RESET_SCORE_INFO" => $lang['admin_game_reset_hs_info'],
		"L_GAME_RESET_AT_SCORE" => $lang['admin_game_reset_at_hs'],
		"L_GAME_RESET_AT_SCORE_INFO" => $lang['admin_game_reset_at_hs_info'],
		"L_WIDTH" => $lang['admin_width'],
		"L_HEIGHT" => $lang['admin_height'],
		"L_MONEY" => $money_name,
		"L_REWARD" => $lang['admin_reward'],
		"L_CHARGE" => $lang['admin_charge'],
		"L_BONUS" => $lang['admin_bonus'],
		"L_AT_BONUS" => $lang['admin_at_bonus'],
		"L_LIMIT" => $lang['admin_limit'],
		"L_AT_LIMIT" => $lang['admin_at_limit'],
		"L_NO" => $lang['No'],
		"L_YES" => $lang['Yes'],
		"L_SUBMIT" => $lang['Submit'],
		"L_RESET" => $lang['Reset'],

		'S_USE_GL_YES' => $use_gamelib_yes,
		'S_USE_GL_NO' => $use_gamelib_no,
		'S_USE_FLASH_YES' => $use_flash_yes,
		'S_USE_FLASH_NO' => $use_flash_no,
		'S_SHOW_SCORE_YES' => $show_score_yes,
		'S_SHOW_SCORE_NO' => $show_score_no,
		'S_OFFLINE_YES' => $offline_yes,
		'S_OFFLINE_NO' => $offline_no,
		'S_ALLOW_GUEST_YES' => $allow_guest_yes,
		'S_ALLOW_GUEST_NO' => $allow_guest_no,
		'S_REVERSE_LIST_YES' => $reverse_list_yes,
		'S_REVERSE_LIST_NO' => $reverse_list_no,
		'S_AUTO_SIZE_YES' => $autosize_yes,
		'S_AUTO_SIZE_NO' => $autosize_no,
		'S_HIDDEN_FIELDS' => '<input type="hidden" name="order" value="'. $sort_order .'"><input type="hidden" name="sort_mode" value="'. $sort_mode .'"><input type="hidden" name="start" value="'. $start .'"><input type="hidden" name="save_game"><input type="hidden" name="id" value="' . $game_id . '"><input type="hidden" name="cat_id" value="' . $cat_id . '">',
		'S_SCORE_ACTION' => append_sid("$file"),
		'S_SCORE_HIDDEN' => '<input type="hidden" name="game_name" value="' . $game_info['game_name'] . '">'));
}

else if( $mode == "import_game")
{
	$template->set_filenames(array( "body" => "admin/arcade_import_body.tpl") );

  $catrows = $arcade->read_cat('./../');
  $cat_list =  '<select name="cat_id">';
  for($i = 0; $i < count($catrows); $i++)
  {
    if($catrows[$i]['cat_type'] == 'l')
    {
      continue;
    }
    $cat_list .= '<option value="'.$catrows[$i]['cat_id'].'">'.$catrows[$i]['cat_name'].'</option>';
  }
  $cat_list .= '</select>';


	$template->assign_vars(array(
		"S_ACTION" => append_sid($file),
		"VERSION" => $version,
		"DASH" => $lang['game_dash'],
		"DIR_NAME" => $arcade->arcade_config['games_path'],
		
		"L_MENU_HEADER" => $lang['admin_game_import'],
		"L_MENU_INFO" => $lang['admin_import_info'],
		"L_IN_INFO" => $lang['admin_import_path'],
		"L_IN_PATH_INFO" => $lang['admin_import_dir'],
		"L_SIZE_INFO" => $lang['admin_auto_size'],
		
		"L_AMOD_INFO" => $lang['admin_import_amod'],
		"L_ONLINE_INFO" => $lang['admin_import_online'],
		"L_CAT_INFO" => $lang['admin_import_cat'],
		"CATS" => $cat_list,
		
		"L_SUBMIT" => $lang['Submit'],
		
		"S_HIDDEN_FIELDS" => '<input type="hidden" name="import_submit">') );
}

//
// Import Routines.
//
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($HTTP_POST_VARS['import_submit']))
{
	$import_path = (isset($HTTP_POST_VARS['import_path']) && !is_array($HTTP_POST_VARS['import_path'])) ? phpbb_arcade_local_asset($HTTP_POST_VARS['import_path']) : '';
	if ($import_path !== '' && substr($import_path, -1) !== '/')
	{
		$import_path .= '/';
	}
	
	if(empty($import_path))
	{	
		$message = $lang['no_game_import'];
		$message .= sprintf($lang['admin_return_import'], "<a href=\"" . append_sid("$file?mode=import_game") . "\">", "</a>") . "<br /><br />" . sprintf($lang['Click_return_admin_index'], "<a href=\"" . append_sid("index.$phpEx?pane=right") . "\">", "</a>");
		message_die(GENERAL_MESSAGE, $message, '', __LINE__, __FILE__, $sql);
	}

	$i = $skipped = 
	$check_for_gif = 
	$flash = 0;
	
	$file_type = (isset($HTTP_POST_VARS['file_type']) && !is_array($HTTP_POST_VARS['file_type'])) ? $HTTP_POST_VARS['file_type'] : '';
	switch($file_type)
	{
		case 'flash':
			$get_name = 'swf';
			break;
			
		case 'gif':
			$get_name = 'gif';
			break;
			
		case 'jpg':
			$get_name = 'jpg';
			break;
			
		default:
			$get_name = 'swf';
			$check_for_gif = 1;
			$flash = 1;
			break;
	}
	
	$autosize = ( isset($HTTP_POST_VARS['autosize']) ) ? intval($HTTP_POST_VARS['autosize']) : 0;
	if ( $autosize )
	{
		$sql = "SELECT win_width, win_height FROM " . iNA_GAMES . "
			WHERE game_id = ". intval($arcade->arcade_config['games_default_id']);
		if(!$result = $db->sql_query($sql))
		{
			message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
		}
		$game_info = $db->sql_fetchrow($result);
		$width = $game_info['win_width'];
		$height = $game_info['win_height'];
		if($width == 0)
		{
			$width = 550;
		}
		if($height == 0)
		{
			$height = 450;
		}
	}
	else
	{
		$width = 0;
		$height = 0;
	}
	
	$online = ( isset($HTTP_POST_VARS['online']) ) ? intval($HTTP_POST_VARS['online']) : 0;
	$cat_id = ( isset($HTTP_POST_VARS['cat_id']) ) ? intval($HTTP_POST_VARS['cat_id']) : -1;

	$forum_root = realpath($phpbb_root_path);
	$main_dir_path = realpath($phpbb_root_path . $import_path);
	if ($forum_root === false || $main_dir_path === false ||
		strpos(str_replace('\\', '/', $main_dir_path) . '/', rtrim(str_replace('\\', '/', $forum_root), '/') . '/') !== 0)
	{
		message_die(GENERAL_MESSAGE, $lang['no_game_import']);
	}

	if($main_dir = opendir($main_dir_path))
	{
		while($file_name = readdir($main_dir))
		{
			if (is_file($main_dir_path . '/' . $file_name) && ($extension = get_ina_extension($file_name)) == $get_name)
			{
				$new_name = explode(".", $file_name);
				$checked_game = check_ina_game($new_name[0]);
				if ((check_ina_game($file_name) == FALSE) && ($checked_game == FALSE))
				{
					if($check_for_gif == 1)
					{
						//
						// Check for GIF is used for the Arcade Games Only Import
						//
						if( @file_exists($arcade->arcade_config['games_path'] . $new_name[0] .".gif") || @file_exists($main_dir_path . $new_name[0] . '.gif') )
						{
							insert_ina_game($new_name[0], $import_path, 0, '', $flash, $online, $width, $height, $cat_id);
							$i++;
						}
						else
						{
							//
							// No GIF (default arcade image file), then skip the file.
							//
							$skipped++;
						}
					}
					else
					{
						//
						// Everything checks out, so add it to the database with as much detail as posible.
						//
						insert_ina_game($file_name, $import_path, 0, '', $flash, $online, $width, $height, $cat_id);
						$i++;
					}
				}
				else
				{
					//
					// Debug information really, but it does help to know what we have skipped.
					//
					echo sprintf($lang['game_skipped'], phpbb_profile_text($file_name), (int) $checked_game);
					$skipped++;
				}
			}
			else if (is_dir($main_dir_path . '/' . $file_name) && ($file_name != ".") && ($file_name != ".."))
			{
				//
				// We've hit a directory, read it's contents.
				//
				if($sub_dir = opendir($main_dir_path.'/'.$file_name))
				{
					while($sub_file_name = readdir($sub_dir))
					{
						if(is_file($main_dir_path . '/' . $file_name . '/' . $sub_file_name) && ($extension = get_ina_extension($sub_file_name)) == $get_name)
						{
							$new_name = explode('.', $sub_file_name);
							if ((check_ina_game($sub_file_name) == FALSE) && (check_ina_game($new_name[0]) == FALSE))
							{
								if($check_for_gif == 1)
								{	
									if( @file_exists($arcade->arcade_config['games_path'] . $new_name[0] . ".gif") || @file_exists($main_dir_path . $file_name . '/' . $new_name[0] . '.gif') )
									{	
										insert_ina_game($new_name[0], $import_path.$file_name.'/', 0, '', $flash, $online, $width, $height, $cat_id);
										$i++;
									}
									else
									{
										$skipped++;
									}
								}
								else
								{
									insert_ina_game($sub_file_name, $import_path.'/'.$file_name, 0, '', $flash, $online, $width, $height, $cat_id);
									$i++;
								}
							}
							else
							{
								$skipped++;
							}
						}
					}
					closedir($sub_dir);
				}
				else if (is_dir($main_dir_path . '/' . $sub_file_name) && ($sub_file_name != ".") && ($sub_file_name != ".."))
				{
					//
					// It's a sub sub directory, 
					//
					echo "-&gt; Skipped: " . phpbb_profile_text($sub_file_name) . '<br />';
					$skipped++;
				}
			}
		}
		closedir($main_dir);
	}
//
//  Force update of cache files
//
  $arcade->clear_cache('games');

	$message = sprintf($lang['admin_game_import_ok'], $i, $skipped);
	$message .= sprintf($lang['admin_return_games'], "<a href=\"" . append_sid("$file?mode=edit_games") . "\">", "</a>") . "<br /><br />" . sprintf($lang['Click_return_admin_index'], "<a href=\"" . append_sid("index.$phpEx?pane=right") . "\">", "</a>");
	message_die(GENERAL_MESSAGE, $message, '', __LINE__, __FILE__, $sql);
}
//
//  Main Games page (and Default)
//
else if( !$mode || $mode == "edit_games" || $mode == "game_down" || $mode == "game_up" || $mode == 'sort_submit')
{
	$amod_link = append_sid("$file");
	
	if($arcade->arcade_config['use_point_system'])
	{
		$money_name = $board_config['points_name'];
	}
	else
	{
		$money_name = $lang['admin_charge'];
	}
	if($arcade->arcade_config['games_per_admin_page'] > 0)
	{
		$sql = "SELECT count(*) AS total FROM " . iNA_GAMES;
		if($cat_id > 0)
		{
			$sql .= " WHERE cat_id = '" . $cat_id . "'";
		}
		if ( !($result = $db->sql_query($sql)) )
		{
			message_die(GENERAL_ERROR, $lang['no_game_total'], '', __LINE__, __FILE__, $sql);
		}
		$total = $db->sql_fetchrow($result);
		if ( $total['total'] >= $arcade->arcade_config['games_per_admin_page'])
		{
			$total_games = $total['total'];
			$pagination = generate_pagination("$file?mode=edit_games&amp;cat_id=$cat_id&amp;order=$sort_order&amp;sort_mode=$sort_mode", $total_games, $arcade->arcade_config['games_per_admin_page'], $start). '&nbsp;';
		}
	}

	$template->set_filenames(array('body' => 'admin/arcade_games_body.tpl'));

  $sql = "SELECT game_id FROM ". iNA_GAMES;
	if( !$result = $db->sql_query($sql) )
	{
		message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
	}
	$game_rows = $db->sql_fetchrowset($result);
  $move_item = '<select name="move_to"><option value="0"></option>';
	for($i = count($game_rows)-1; $i > -1; $i--)
	{
    $move_item .= '<option value="'.$game_rows[$i]['game_id'].'">'.$game_rows[$i]['game_id'].'</option>';
  }		
  $move_item .= '</select>';

	$sql = "SELECT g.*, c.cat_name FROM " . iNA_GAMES . " g
		LEFT JOIN  " . iNA_CAT . " c ON g.cat_id = c.cat_id";
	if($search_word != "")
	{
    $sql .= " WHERE g.game_desc LIKE '%$search_word%'"; 
  }
	else if($cat_id > 0)
	{
		$sql .= " WHERE g.cat_id = '" . $cat_id . "'";
	}
	$sql .= " ORDER BY $order_by $sort_order";
	if($arcade->arcade_config['games_per_admin_page'] > 0)
	{
		$sql .= " LIMIT $start," . $arcade->arcade_config['games_per_admin_page'];
	}
	if( !$result = $db->sql_query($sql) )
	{
		message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
	}
	$game_count = $db->sql_numrows($result);
	$game_rows = $db->sql_fetchrowset($result);
//
// Come on baby, lets do the loop, oh baby..!
//
	for($i = 0; $i < $game_count; $i++)
	{
		$game_id = $game_rows[$i]['game_id'];
		$game_name = $game_rows[$i]['game_name'];
		$game_charge = $game_rows[$i]['game_charge'];
		$game_reward = $game_rows[$i]['game_reward'] ? $game_rows[$i]['game_reward'] : ' ';
		$game_bonus = $game_rows[$i]['game_bonus'] ? $game_rows[$i]['game_bonus'] : ' ';
		$game_bonus .= '<br /><br />';
		$game_bonus .= $game_rows[$i]['at_game_bonus'] ? $game_rows[$i]['at_game_bonus'] : ' ';
		$game_desc = $game_rows[$i]['game_desc'];
		$game_path = $game_rows[$i]['game_path'];
		$image_path = $game_rows[$i]['image_path'];
		$game_avail = $game_rows[$i]['game_avail'];
		$game_order = ($game_rows[$i]['reverse_list']) ? 'ASC' : 'DESC';
		
		if($game_rows[$i]['game_control'] == 1)
		{
      $game_control = $lang['admin_mouse'];
    }
		else if($game_rows[$i]['game_control'] == 2)
		{
      $game_control = $lang['admin_keyboard'];
    }
		else if($game_rows[$i]['game_control'] == 3)
		{
      $game_control = $lang['admin_mouse_keyboard'];
    }
		else
		{
      $game_control = '';
    }
		$played = $game_rows[$i]['played'];

		$image_path = ina_find_image($game_rows[$i]['game_path'], $game_rows[$i]['game_name'], $game_rows[$i]['image_path'], './../');

		$game_cat_id = intval($game_rows[$i]['cat_id']);
		if ($game_cat_id > 0)
		{
			$game_cat_id = $game_rows[$i]['cat_name'] ;
		}
		else
		{
			$game_cat_id = $lang['None'];
		}
//
// Check path to game for a '/' at the end
//
		if( $game_path[strlen( $game_path ) -1] != '/' )
		{
			$game_path = $game_rows[$i]['game_path'] . '/';
		}

		if($sort_order == 'DESC' && $order_by == 'game_id')
		{
			if($i == 0 && $start == 0)
			{
				$image_up = '';
			}
			else if ( $cat_id == 0 )
			{
				$image_up = '<a href="' . $file . '?mode=game_up&amp;start=' . $start . '&amp;id=' . $game_id . '&amp;' . $SID . '"><img src="' . $phpbb_root_path . 'images/arrow_up.gif" border="0" alt="' . $lang['admin_up'] . '"/></a>' ;
			}
			else
			{
				$image_up = '<a href="' . $file . '?mode=game_up&amp;start=' . $start . '&amp;id=' . $game_id . '&amp;new_id=' . $game_rows[$i-1]['game_id'] . '&amp;cat_id=' . $cat_id . '&amp;' . $SID . '"><img src="' . $phpbb_root_path . 'images/arrow_up.gif" border="0" alt="' . $lang['admin_up'] . '"/></a>' ;
			}
			if ( $game_id <= 1 || (!($game_rows[$i+1]['game_id']) && $cat_id) )
			{
				$image_down = '';
			}
			else if ( $cat_id == 0 )
			{ 
				$image_down = '<a href="' . $file . '?mode=game_down&amp;start=' . $start . '&amp;id=' . $game_id . '&amp;' . $SID . '"><img src="' . $phpbb_root_path . 'images/arrow_down.gif" border="0" alt="' . $lang['admin_down_full'] . '"/></a>' ;
			}
			else
			{ 
				$image_down = '<a href="' . $file . '?mode=game_down&amp;start=' . $start . '&amp;id=' . $game_id . '&amp;new_id=' . $game_rows[$i+1]['game_id'] . '&amp;cat_id=' . $cat_id . '&amp;' . $SID . '"><img src="' . $phpbb_root_path . 'images/arrow_down.gif" border="0" alt="' . $lang['admin_down_full'] . '"/></a>' ;
			}
		}
		else
		{
			$image_up = '';
			$image_down = '';
		}
//
//  Build each game STATS
//		
		$sql = "SELECT player_id, score FROM " . iNA_SCORES . "
      WHERE game_name = '" . $game_name . "'
        ORDER BY score " . $game_order;
		$scorerows = $db->sql_fetchrowset($db->sql_query($sql));
		if(count($scorerows) > 0)
		{
		  $stats = '<a href="' . append_sid("admin_arcade_scores.$phpEx?mode=scores&amp;game_name=$game_name").'">'.count($scorerows) . $lang['admin_score_top_score'] . $arcade->convert_score($scorerows[0]['score']) . '</a>';
    }
    else
    {
      $stats = '';
    }
		$sql = "SELECT count(comment_id) as total FROM " . iNA_GAMES_COMMENT . "
      WHERE comment_game_name = '" . $game_name . "'";
		$total_comments = $db->sql_fetchrow($db->sql_query($sql));
		if($total_comments['total'] > 0)
		{
		  $comment = ($total_comments['total'] == 1) ? $lang['admin_comment'] : $lang['admin_comments'];
      $stats .= '<br>' . $total_comments['total'] . $comment;
	  }
	  else
	  {
      $stats .= '<br>';
    }

		$sql = "SELECT player_id, score FROM " . iNA_AT_SCORES . "
      WHERE game_name = '" . $game_name . "'
        ORDER BY score " . $game_order;
		$scorerows = $db->sql_fetchrowset($db->sql_query($sql));
		if(count($scorerows) > 0)
		{
  		$stats .= '<br><a href="' . append_sid("admin_arcade_scores.$phpEx?mode=at_scores&amp;game_name=$game_name") .'">' . count($scorerows) . $lang['admin_at_score_top_score'] . $arcade->convert_score($scorerows[0]['score']) . '</a>';
    }
    else
    {
      $stats .= '<br>';
    }
    $sql = "SELECT AVG(rate_point) AS rating FROM " . iNA_GAMES_RATE . "
      WHERE rate_game_name = '" . $game_name . "'";
		$total_rate = $db->sql_fetchrow($db->sql_query($sql));
		if($total_rate['rating'] > 0)
		{
      $stats .= $lang['admin_rated'] . round($total_rate['rating']);
	  }
	  else
	  {
      $stats .= '<br>';
    }
//
//  End of STATS - Start Building the Page :)
//
		$template->assign_block_vars("game", array(
			'ROW_CLASS' => ( !($i % 2) ) ? 'row1' : 'row2',

			'ID' => $game_id,
			'CHARGE' => $game_charge,
			'NAME' => $game_name,
			'PATH' => $game_path,
			'IMAGE' => $image_path,
			'CAT_ID' => $game_cat_id,
			'WIDTH' => $game_rows[$i]['win_width'],
			'HEIGHT' => $game_rows[$i]['win_height'],
			'CONTROL' => $game_control,
	
     	'IMAGE_UP' => $image_up,
     	'IMAGE_DOWN' => $image_down,

			'IMAGE_WIDTH' => $arcade->arcade_config['games_image_width'],
			'IMAGE_HEIGHT' => $arcade->arcade_config['games_image_height'],

			"STATS" => (isset($stats)) ? $stats : ' ',
			"GUEST" => ($game_rows[$i]['allow_guest']) ? $lang['Yes'] : ' ',
			"AVAIL" => ($game_rows[$i]['game_avail']) ? ' ' : $lang['Yes'],
			"GAMELIB" => ($game_rows[$i]['game_use_gl']) ? $lang['Yes'] : ' ',
			"FLASH" => ($game_rows[$i]['game_flash']) ? $lang['Yes'] : ' ',
			"SHOW_SCORE" => ($game_rows[$i]['game_show_score'] ? $lang['Yes'] : ' '),
			"PLAYED" => $played,
			"REWARD" => $game_reward,
			"BONUS" => $game_bonus,
			"DESC" => $game_desc,
			"U_GAME_EDIT" => append_sid("$file?mode=edit&amp;id=" . $game_id . "&amp;cat_id=" . $cat_id . "&amp;order=".$sort_order."&amp;sort_mode=".$sort_mode."&amp;start=".$start), 
			"U_GAME_UP" => append_sid("$file?mode=game_up&amp;id=" . $game_id), 
			"U_GAME_DOWN" => append_sid("$file?mode=game_down&amp;id=" . $game_id), 
			"U_GAME_DELETE" => append_sid("$file?mode=delete&amp;id=" . $game_id . "&amp;cat_id=" . $cat_id . "&amp;order=".$sort_order."&amp;sort_mode=".$sort_mode."&amp;start=".$start))
		);
	}
	$template->assign_vars(array(
		'S_CONFIG_ACTION' => $amod_link,
		'SEARCH_WORD' => $search_word,
		'S_MODE_SELECT' => $select_sort_mode,
		'S_ORDER_SELECT' => $select_sort_order,
		'S_HIDDEN_FIELDS' => '<input type="hidden" name="cat_id" value="'.$cat_id.'">',
		'VERSION' => $version,
		'PAGINATION' => $pagination,

   	'IMAGE_DEL' => '<img src="./../' . $images['icon_delpost'] . '" alt="' . $lang['Delete'] . '" title="' . $lang['Delete'] . '" border="0" />',
   	'IMAGE_EDIT' => '<img src="./../' . $images['icon_edit'] . '" alt="' . $lang['Edit'] . '" title="' . $lang['Edit'] . '" border="0" />',
   	'MOVE_ITEM' => $move_item,
   	'MOVE_TO' => $lang['admin_move_to'],
   	'MOVE' => $lang['admin_move'],
  	
    "L_SEARCH" => $lang['Search'],
		"L_HEADER" => $lang['admin_main_header'],
		"L_GAME_MENU" => $lang['admin_game_menu'],
		"L_BUTTON" => $lang['admin_button'],
		"L_DESC" => $lang['admin_description'],
		"L_STATS" => $lang['admin_stats'],
		"L_GUEST" => $lang['admin_guest'],
		"L_AVAIL" => $lang['admin_available'],
		"L_REWARD" => $lang['admin_reward'],
		"L_BONUS" => $lang['admin_bonus'],
		"L_FLASH" => $lang['admin_flash'],
		"L_SCORE" => $lang['admin_score'],
		"L_PLAYED" => $lang['admin_played'],
		"L_GAMELIB" => $lang['admin_gamelib'],
		"L_ACTION" => $lang['admin_action'],
		"L_MOVE" => $lang['admin_move'],
		"L_MONEY" => $money_name,
		"L_GAMES" => $lang['admin_games'],
		"L_EDIT" => $lang['Edit'],
		"L_UP" => $lang['admin_up'],
		"L_DOWN" => $lang['admin_down_full'],
		"L_DEL" => $lang['admin_delete_full'],
		"L_REPAIR" => $lang['admin_repair'],
		"L_RESET_SCORE" => $lang['admin_reset'],
		"L_RESET_AT_SCORE" => $lang['admin_at_reset'],
		"L_ADD" => $lang['Add_new'] . ' ' . $lang['admin_games'],
		"L_YES" => $lang['Yes'],
		"L_NO" => $lang['No'],
		"L_SUBMIT" => $lang['Submit'],
		"L_RESET" => $lang['Reset'],
		'L_SORT' => $lang['Sort'])
	);
}
//
//  Clear the Scores Table
//
else if( $mode == "clear_scores" || $mode == "clear_at_scores" )
{
	$table = iNA_SCORES;
	$places_sql = '';
	if( $mode == "clear_at_scores" )
	{
    if(!isset($HTTP_POST_VARS['confirm']))
    {
    	 if( isset($HTTP_POST_VARS['cancel']) )
  	   {
  		    redirect(append_sid("$filename"));
  		    exit;
  	   }
//
// Start output of page
//
      $template->set_filenames(array(
  		  'body' => 'confirm_body.tpl')
      	);

    	$template->assign_vars(array(
  	   	'MESSAGE_TITLE' => $lang['Confirm'],
    		'MESSAGE_TEXT' => $lang['arcade_delete_at_sure'],

    		'L_NO' => $lang['No'],
	    	'L_YES' => $lang['Yes'],

    		'S_CONFIRM_ACTION' => append_sid("$filename"),
    		'S_HIDDEN_FIELDS' => '<input type="hidden" name="mode" value="clear_at_scores"><input type="hidden" name="cat_id" value="'.$cat_id.'">',
	   	));

	//
	// Generate the page
	//
  	$template->pparse('body');
    exit;    
  }
	  
		$table = iNA_AT_SCORES;
		$places_sql = 'at_';
	}
	
	if (isset($HTTP_GET_VARS['cat_id']) || isset($HTTP_POST_VARS['cat_id']))
	{
		$cat_id = (isset($HTTP_GET_VARS['cat_id'])) ? $HTTP_GET_VARS['cat_id'] : $HTTP_POST_VARS['cat_id'];
		$cat_id = intval(htmlspecialchars($cat_id));
	}
	if($cat_id > 0)
	{
		$sql = "SELECT game_name FROM " . iNA_GAMES . "
				WHERE cat_id = '" . $cat_id . "'";
		if(!$result = $db->sql_query($sql))
		{
			message_die(GENERAL_ERROR, $lang['no_game_data'], '', __LINE__, __FILE__, $sql);
		}
		while ($game_info = $db->sql_fetchrow($result))
		{
			$game_name = $game_info['game_name'];
			$top_score = best_game_player($table, $game_name, $list_type);
			$game_name_sql = $db->sql_escape($game_name);
			$sql = "DELETE FROM " . $table . "
				WHERE game_name = '" . $game_name_sql . "'";
			if(!$delete = $db->sql_query($sql))
			{
				message_die(GENERAL_ERROR, $lang['no_game_data'], '', __LINE__, __FILE__, $sql);
			}

			$sql = "UPDATE " . iNA_USER_DATA . "
				SET ".$places_sql."first_places = ".$places_sql."first_places-1
				WHERE user_id = " . (int) $top_score['player_id'];
		    if(!$update = $db->sql_query($sql)) 
			{
				message_die(GENERAL_ERROR, $lang['no_user_update'], "", __LINE__, __FILE__, $sql); 
			}
  		$sql = "UPDATE " . iNA_GAMES . "
  			SET ".$places_sql."highscore_id = 0
				WHERE game_name = '" . $game_name . "'";
  		if(!$update = $db->sql_query($sql)) 
  		{
  			message_die(GENERAL_ERROR, $lang['no_game_update'], "", __LINE__, __FILE__, $sql); 
  		}
		}
	}
	else
	{
		$sql = "TRUNCATE " . $table;
		if( !$result = $db->sql_query($sql) )
		{
			message_die(GENERAL_ERROR, $lang['no_score_reset'], "", __LINE__, __FILE__, $sql);
		}
		$sql = "UPDATE " . iNA_USER_DATA . "
			SET ".$places_sql."first_places = 0 , ".$places_sql."second_places = 0 , ".$places_sql."third_places = 0";
		if(!$update = $db->sql_query($sql)) 
		{
			message_die(GENERAL_ERROR, $lang['no_user_update'], "", __LINE__, __FILE__, $sql); 
		}
		$sql = "UPDATE " . iNA_GAMES . "
			SET ".$places_sql."highscore_id = 0";
		if(!$update = $db->sql_query($sql)) 
		{
			message_die(GENERAL_ERROR, $lang['no_game_update'], "", __LINE__, __FILE__, $sql); 
		}
	}
//
//  Force update of cache files
//
  $arcade->clear_cache('games');
  
	$message = $lang['admin_score_reset'];
	$message .= sprintf($lang['admin_return_games'], "<a href=\"" . append_sid("$file?mode=edit_games&amp;cat_id=$cat_id&amp;order=$sort_order&amp;sort_mode=$sort_mode&amp;start=$start") . "\">", "</a>") . "<br /><br />" . sprintf($lang['Click_return_admin_index'], "<a href=\"" . append_sid("index.$phpEx?pane=right") . "\">", "</a>");
	message_die(GENERAL_MESSAGE, $message, '', __LINE__, __FILE__, $sql);
}
//
//  Delete Game
//
else if( $mode == "delete")
{
	$game_id = ( !empty($HTTP_POST_VARS['id']) ) ? intval($HTTP_POST_VARS['id']) : intval($HTTP_GET_VARS['id']);
	$game_id = intval($game_id);
	if( $game_id )
	{
		$sql = "SELECT cat_id, played, game_avail FROM " . iNA_GAMES . "
			WHERE game_id = $game_id";
		if( !$result = $db->sql_query($sql) )
		{
			message_die(GENERAL_ERROR, $lang['no_game_delete'], "", __LINE__, __FILE__, $sql);
		}
		$game_info = $db->sql_fetchrow($result);
		$sql = "DELETE FROM " . iNA_GAMES . "
			WHERE game_id = $game_id";
		if( !$result = $db->sql_query($sql) )
		{
			message_die(GENERAL_ERROR, $lang['no_game_delete'], "", __LINE__, __FILE__, $sql);
		}
		if($game_avail == 1)
		{
  		$sql = "UPDATE ". iNA_CAT . "
        SET total_played = total_played - '" . $game_info['played'] . ", total_games = total_games-1
          WHERE cat_id = '". $game_info['cat_id'] ."'";
  		if( !$result = $db->sql_query($sql) )
  		{
  			message_die(GENERAL_ERROR, $lang['no_cat_update'], "", __LINE__, __FILE__, $sql);
  		}
      if($game_info['cat_id'] > 0)
      {
    		$sql = "UPDATE ". iNA_CAT . "
          SET total_played = total_played - '" . $game_info['played'] . ", total_games = total_games-1
            WHERE cat_id = -1";
    		if( !$result = $db->sql_query($sql) )
    		{
    			message_die(GENERAL_ERROR, $lang['no_cat_update'], "", __LINE__, __FILE__, $sql);
    		}
  		}
		}
//
//  Force update of cache files
//
    $arcade->clear_cache('games');

		$message = $lang['admin_game_deleted'];
		$message .= sprintf($lang['admin_return_games'], "<a href=\"" . append_sid("$file?mode=edit_games&amp;cat_id=$cat_id&amp;order=$sort_order&amp;sort_mode=$sort_mode&amp;start=$start") . "\">", "</a>") . "<br /><br />" . sprintf($lang['Click_return_admin_index'], "<a href=\"" . append_sid("index.$phpEx?pane=right") . "\">", "</a>");
		message_die(GENERAL_MESSAGE, $message, '', __LINE__, __FILE__, $sql);
	}
	else
	{
		$message = $lang['admin_game_not_deleted'];
		$message .= sprintf($lang['admin_return_arcade'], "<a href=\"" . append_sid("admin_arcade.$phpEx") . "\">", "</a>") . "<br /><br />" . sprintf($lang['Click_return_admin_index'], "<a href=\"" . append_sid("index.$phpEx?pane=right") . "\">", "</a>");
		message_die(GENERAL_MESSAGE, $message, '', __LINE__, __FILE__, $sql);
	}
}
//
//  Repair Index's
//
else if( $mode == "repair_game" )
{
  if (stristr($dbms, 'mysql'))
  {
    $sql = "REPAIR TABLE " . iNA_GAMES . ", " . iNA_CAT . ", " . iNA_FAV . ", " . iNA_SESSIONS . ", " . iNA_SCORES . ", " . iNA_AT_SCORES;
  	if(!$result = $db->sql_query($sql))
  	{
  		message_die(GENERAL_ERROR, $lang['no_game_repair'], "", __LINE__, __FILE__, $sql);
  	}
  	$sql = "ALTER TABLE " . iNA_GAMES . " DROP game_id";
  	$result = $db->sql_query($sql);

  	$sql = "ALTER TABLE `" . iNA_GAMES . "` ADD `game_id` MEDIUMINT(9) NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST";
  	if(!$result = $db->sql_query($sql))
  	{
  		message_die(GENERAL_ERROR, $lang['game_repair_critical'], "", __LINE__, __FILE__, $sql);
  	}
    $sql = "OPTIMIZE TABLE  " . iNA_GAMES . ", " . iNA_CAT . ", " . iNA_FAV . ", " . iNA_SESSIONS . ", " . iNA_SCORES . ", " . iNA_AT_SCORES;
  	if(!$result = $db->sql_query($sql))
  	{
  		message_die(GENERAL_ERROR, $lang['no_game_repair'], "", __LINE__, __FILE__, $sql);
  	}
  }
  else
  {
  	$sql = "ALTER TABLE " . iNA_GAMES . " DROP game_id";
  	if(!$result = $db->sql_query($sql))
  	{
  		message_die(GENERAL_ERROR, $lang['no_game_repair'], "", __LINE__, __FILE__, $sql);
  	}

  	$sql = "ALTER TABLE " . iNA_GAMES . " ADD game_id INT(9) NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST";
  	if(!$result = $db->sql_query($sql))
  	{
  		message_die(GENERAL_ERROR, $lang['game_repair_critical'], "", __LINE__, __FILE__, $sql);
  	}
	}
//
//  Force update of cache files
//
  $arcade->clear_cache('games');
  $arcade->clear_cache('categories');

	$message = $lang['admin_game_repaired'];
	$message .= sprintf($lang['admin_return_games'], "<a href=\"" . append_sid("$file?mode=edit_games") . "\">", "</a>") . "<br /><br />" . sprintf($lang['Click_return_admin_index'], "<a href=\"" . append_sid("index.$phpEx?pane=right") . "\">", "</a>");
	message_die(GENERAL_MESSAGE, $message, '', __LINE__, __FILE__, $sql);
}
//
//  Save the Game Information
//
if( isset($HTTP_POST_VARS['save_game']) || isset($HTTP_GET_VARS['save_game']) )
{
	$game_id = $arcade->pass_var('id', 0);
	if($game_id < 0)
	{
		$game_id = 0;
	}

	$game_name = ( isset($HTTP_POST_VARS['game_name']) ) ? trim($HTTP_POST_VARS['game_name']) : "";
	$game_name = trim(htmlspecialchars($game_name));
	$game_name = substr(str_replace("\\'", "'", $game_name), 0, 50);
	$game_name = str_replace("'", "\\'", $game_name);
	$new_name = explode(" ", $game_name);
	if($new_name[1])
	{
		$message = $lang['admin_game_wrong_name'];
		$message .= sprintf($lang['admin_return_games'], "<a href=\"" . append_sid("$file?mode=edit_games&amp;cat_id=$cat_id&amp;order=$sort_order&amp;sort_mode=$sort_mode&amp;start=$start") . "\">", "</a>") . "<br /><br />" . sprintf($lang['Click_return_admin_index'], "<a href=\"" . append_sid("index.$phpEx?pane=right") . "\">", "</a>");
		message_die(GENERAL_MESSAGE, $message);
	}

	$game_path = ( isset($HTTP_POST_VARS['game_path']) ) ? trim($HTTP_POST_VARS['game_path']) : "";
	if ( $game_path[0] == '/' || $game_path[0] == '\\' )
	{
		$game_path[0] = ' ';
	}
	if( $game_path[strlen( $game_path )-1] != '/' )
	{
		$game_path .= '/';
	}
	$game_path = trim($game_path);
	
	$image_path = ( isset($HTTP_POST_VARS['image_path']) ) ? trim($HTTP_POST_VARS['image_path']) : "";

	$game_desc = ( isset($HTTP_POST_VARS['game_desc']) ) ? addslashes(trim(stripslashes($HTTP_POST_VARS['game_desc']))) : "";

	$game_charge = ( isset($HTTP_POST_VARS['game_charge']) ) ? intval($HTTP_POST_VARS['game_charge']) : 0;
	$game_reward = ( isset($HTTP_POST_VARS['game_reward']) ) ? intval($HTTP_POST_VARS['game_reward']) : 0;
	$game_bonus  = ( isset($HTTP_POST_VARS['game_bonus']) )  ? intval($HTTP_POST_VARS['game_bonus'])  : 0;
	$at_game_bonus  = ( isset($HTTP_POST_VARS['game_at_bonus']) )  ? intval($HTTP_POST_VARS['game_at_bonus'])  : 0;
	$game_use_gl = ( isset($HTTP_POST_VARS['game_use_gl']) ) ? intval($HTTP_POST_VARS['game_use_gl']) : 0;
	$game_flash  = ( isset($HTTP_POST_VARS['game_flash']) ) ? intval($HTTP_POST_VARS['game_flash']) : 0;
	$game_show_score  = ( isset($HTTP_POST_VARS['game_show_score']) ) ? intval($HTTP_POST_VARS['game_show_score']) : 0;
	$game_avail  = ( isset($HTTP_POST_VARS['game_avail']) ) ? intval($HTTP_POST_VARS['game_avail']) : 0;
	$allow_guest  = ( isset($HTTP_POST_VARS['allow_guest']) ) ? intval($HTTP_POST_VARS['allow_guest']) : 0;
	$game_reverse_list = ( isset($HTTP_POST_VARS['game_reverse_list']) ) ? intval($HTTP_POST_VARS['game_reverse_list']) : 0;
	$game_highscore_limit = ( isset($HTTP_POST_VARS['highscore_limit']) ) ? intval($HTTP_POST_VARS['highscore_limit']) : 0;
	$game_at_highscore_limit = ( isset($HTTP_POST_VARS['at_highscore_limit']) ) ? intval($HTTP_POST_VARS['at_highscore_limit']) : 0;
	$game_cat_id = ( isset($HTTP_POST_VARS['game_cat_id']) )  ? intval($HTTP_POST_VARS['game_cat_id'])  : 0;
	$game_instructions = ( isset($HTTP_POST_VARS['game_instructions']) ) ? addslashes(trim(stripslashes($HTTP_POST_VARS['game_instructions']))) : "";
//
//  Check for the existance of the file on the server (only if local).
//
  if (!stristr($game_path, 'http:') && $game_avail)
  {
    $ext = ( $game_flash == 1) ? '.swf' : '';
    if (!(@file_exists("./../". $game_path . $game_name . $ext)))
    {
   		$message .= sprintf($lang['admin_file_not_found'] ,$game_path . $game_name . $ext);
    	$message .= "<br />" . sprintf($lang['admin_return_games'], "<a href=\"" . append_sid("$file?mode=edit_games") . "\">", "</a>");
      message_die(GENERAL_MESSAGE, $message);
    } 
  }

	$win_width  = $arcade->pass_var('win_width', 0);
	$win_height = $arcade->pass_var('win_height', 0);
	$reset_scores = $arcade->pass_var('reset_scores', 0);
	$reset_at_scores = $arcade->pass_var('reset_at_scores', 0);
	$score_type = $arcade->pass_var('score_type', 0);
	$group_required = $arcade->pass_var('group_required', 0);
	$rank_required = $arcade->pass_var('rank_required', 0);
	$level_required = $arcade->pass_var('level_required', 0);
	$game_autosize = $arcade->pass_var('game_autosize', 0);
	$game_control = $arcade->pass_var('game_control', 0);

	$list_type = 'DESC';
	if($game_reverse_list)
	{
		$list_type = 'ASC';
	}
  if($game_cat_id == 0)
  {
    $game_cat_id = -1;
  }
	if(!$win_width || !$win_height)
	{
		$game_ext = get_ina_extension($game_name);
		if($game_flash)
		{
			$game_size = &getimagesize($phpbb_root_path.$game_path.$game_name.'.swf'); 
		}
		else if($game_ext == 'swf' || $game_ext == 'jpg' || $game_ext == 'gif')
		{
			$game_size = &getimagesize($phpbb_root_path.$game_path.$game_name); 
		}
		if($game_size[0] > 0)
		{
			$win_width = $game_size[0];
			$win_height = $game_size[1];
		}
		else
		{
			$win_width = 550;
			$win_height = 400;
		}
	}
//
//  EDIT used (i.e. game ID set)
//
	if( $game_id > 0)
	{
    $sql = "SELECT cat_id, played, game_avail FROM " . iNA_GAMES . "
      WHERE game_id = $game_id";
    if( !$result = $db->sql_query($sql) )
  	{
   		message_die(GENERAL_ERROR, $lang['no_game_save'], "", __LINE__, __FILE__, $sql);
  	}
  	$old_game_info = $db->sql_fetchrow($result);
		$sql = "UPDATE " . iNA_GAMES . "
			SET game_name = '$game_name', game_path = '$game_path', image_path = '$image_path', game_desc = '$game_desc', game_charge = '$game_charge', game_reward = '$game_reward', game_bonus = '$game_bonus', at_game_bonus = '$at_game_bonus', game_use_gl = '$game_use_gl', game_flash = '$game_flash', game_show_score = '$game_show_score', game_avail = '$game_avail', allow_guest = '$allow_guest', reverse_list = '$game_reverse_list', win_width = '$win_width', win_height = '$win_height', highscore_limit = '$game_highscore_limit', at_highscore_limit = '$game_at_highscore_limit', instructions = '$game_instructions', cat_id = $game_cat_id, score_type = $score_type, group_required = $group_required, rank_required = $rank_required, level_required = $level_required, game_autosize = $game_autosize, game_control = $game_control
			WHERE game_id = $game_id";
	}
	else
	{
//
// We need to check the game does not exist, or things become VERY difficult to find whats what.
//
		if(check_ina_game($game_name) == TRUE)
		{
			$message = $lang['admin_game_exists'];
			$message .= sprintf($lang['admin_return_games'], "<a href=\"" . append_sid("$file?mode=edit_games?cat_id=$cat_id&amp;order=$sort_order&amp;sort_mode=$sort_mode&amp;start=$start") . "\">", "</a>") . "<br /><br />";
			$message .= sprintf($lang['Click_return_admin_index'], "<a href=\"" . append_sid("index.$phpEx?pane=right") . "\">", "</a>");
			message_die(GENERAL_MESSAGE, $message);
		}
//
//  Update the Categories
//
    $catsql = "UPDATE " . iNA_CAT . "
        SET total_games = total_games+1
          WHERE cat_id = '" . $game_cat_id . "'";
    if(!$result = $db->sql_query($catsql))
    {
    	message_die(GENERAL_ERROR, $lang['no_cat_update'], "", __LINE__, __FILE__, $catsql);
    }
    if($game_cat_id > 0)
    {
      $catsql = "UPDATE " . iNA_CAT . "
        SET total_games = total_games+1
          WHERE cat_id = -1";
      if(!$result = $db->sql_query($catsql))
      {
      	message_die(GENERAL_ERROR, $lang['no_cat_update'], "", __LINE__, __FILE__, $catsql);
      }
    }
//
//  Insert Game into Database
//
		$sql = "INSERT INTO " . iNA_GAMES . " (date_added, game_name, game_path, image_path, game_desc, game_charge, game_reward, game_bonus, game_use_gl, game_flash, game_show_score, game_avail, allow_guest, reverse_list, win_width, win_height, highscore_limit, instructions, at_game_bonus, at_highscore_limit, cat_id, score_type, group_required, rank_required, level_required, game_autosize, game_control) 
			VALUES ('" . (time()) . "', '$game_name', '$game_path', '$image_path', '$game_desc', '$game_charge', '$game_reward', '$game_bonus', '$game_use_gl' , '$game_flash', '$game_show_score', $game_avail, $allow_guest, '$game_reverse_list', '$win_width', '$win_height', '$game_highscore_limit', '$game_instructions', '$at_game_bonus', '$game_at_highscore_limit', $game_cat_id, $score_type, $group_required, $rank_required, $level_required, $game_autosize, $game_control)";
    if($arcade->arcade_config['games_pm_new'])
    {
      $message = sprintf($lang['games_new_game_added'], $game_desc );
      $usersql = "SELECT user_id FROM " . USERS_TABLE;
    	if( $user_result = $db->sql_query($usersql) )
    	{
      	$user_count = $db->sql_numrows($user_result);
      	$userrows = $db->sql_fetchrowset($user_result);
        for($i = 0; $i < $user_count; $i++)
        {      
          @ina_send_user_pm($userrows[$i]['user_id'], $lang['games_new_game_added_info'], $message, $userdata['user_id'], 'YES');
        }
        $arcade->write_cache('user_id', $userrows, './../');
        if($user_result) { $db->sql_freeresult($user_result); }
      }
    }
	}

	if( !$result = $db->sql_query($sql) )
	{
		message_die(GENERAL_ERROR, $lang['no_game_save'], "", __LINE__, __FILE__, $sql);
	}
//
//  Check to see if the scores need to be reset
//	
	if ($reset_scores)
	{
		$top_score = best_game_player(iNA_SCORES, $game_name, $list_type);

		$sql = "DELETE FROM " . iNA_SCORES . "
			WHERE game_name = '$game_name'";
		if( !$result = $db->sql_query($sql) )
		{
			message_die(GENERAL_ERROR, $lang['no_score_reset'], "", __LINE__, __FILE__, $sql);
		}
		$sql = "UPDATE " . iNA_GAMES . "
		  SET highscore_id = 0
			WHERE game_name = '$game_name'";
		if( !$result = $db->sql_query($sql) )
		{
			message_die(GENERAL_ERROR, $lang['no_score_reset'], "", __LINE__, __FILE__, $sql);
		}
		$sql = "UPDATE " . iNA_USER_DATA . "
			SET first_places = first_places-1
			WHERE user_id = '" . $top_score['player_id'] . "'";
	    if(!$result = $db->sql_query($sql)) 
		{
			message_die(GENERAL_ERROR, $lang['no_user_update'], "", __LINE__, __FILE__, $sql); 
		}
	}
	if ($reset_at_scores)
	{
		$top_score = best_game_player(iNA_AT_SCORES, $game_name, $list_type);

		$sql = "DELETE FROM " . iNA_AT_SCORES . "
			WHERE game_name = '$game_name'";
		if( !$result = $db->sql_query($sql) )
		{
			message_die(GENERAL_ERROR, $lang['no_score_reset'], "", __LINE__, __FILE__, $sql);
		}
		$sql = "UPDATE " . iNA_GAMES . "
		  SET at_highscore_id = 0
			WHERE game_name = '$game_name'";
		if( !$result = $db->sql_query($sql) )
		{
			message_die(GENERAL_ERROR, $lang['no_score_reset'], "", __LINE__, __FILE__, $sql);
		}
		$sql = "UPDATE " . iNA_USER_DATA . "
			SET at_first_places = at_first_places-1
			WHERE user_id = '" . $top_score['player_id'] . "'";
	    if(!$result = $db->sql_query($sql)) 
		{
			message_die(GENERAL_ERROR, $lang['no_user_update'], "", __LINE__, __FILE__, $sql); 
		}
	}
//
//  Force update of cache files
//
  $arcade->clear_cache('games');
  $arcade->clear_cache('categories');
//
// Build up message line by line to aid bug fixing.
//
	$message = $lang['admin_game_saved'];
	$message .= sprintf($lang['admin_return_games'], "<a href=\"" . append_sid("$file?mode=edit_games&amp;cat_id=$cat_id&amp;order=$sort_order&amp;sort_mode=$sort_mode&amp;start=$start") . "\">", "</a>") . "<br /><br />";
	if($game_cat_id != $cat_id)
	{
		$message .= sprintf($lang['admin_return_games_new'], "<a href=\"" . append_sid("$file?mode=edit_games&amp;cat_id=$game_cat_id&amp;order=$sort_order&amp;sort_mode=$sort_mode") . "\">", "</a>") . "<br /><br />";
	}
	$message .= sprintf($lang['Click_return_admin_index'], "<a href=\"" . append_sid("index.$phpEx?pane=right") . "\">", "</a>");
	message_die(GENERAL_MESSAGE, $message);
}
//
//  Generate the page
//
$template->pparse('body');
//
//  Generate footer
//
include('page_footer_admin.' . $phpEx);

?>
