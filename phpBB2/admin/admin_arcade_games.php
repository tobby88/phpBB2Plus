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

function arcade_admin_post_string($name, $default = '')
{
	global $HTTP_POST_VARS;
	if (!isset($HTTP_POST_VARS[$name]) || is_array($HTTP_POST_VARS[$name]))
	{
		return $default;
	}
	return trim(stripslashes((string) $HTTP_POST_VARS[$name]));
}

function arcade_admin_post_int($name, $default = 0)
{
	$value = arcade_admin_post_string($name, null);
	return ($value === null) ? (int) $default : (int) $value;
}

function arcade_admin_rename_game_references($old_name, $new_name)
{
	global $db, $lang;

	$old_sql = $db->sql_escape($old_name);
	$new_sql = $db->sql_escape($new_name);
	$references = array(
		array(iNA_SCORES, 'game_name'), array(iNA_AT_SCORES, 'game_name'),
		array(iNA_FAV, 'fav_game_name'), array(iNA_GAMES_COMMENT, 'comment_game_name'),
		array(iNA_GAMES_COMMENT, 'game_name'), array(iNA_GAMES_RATE, 'rate_game_name'),
		array(iNA_SESSIONS, 'game_name'), array(iNA_HIGHSCORES, 'highscore_game'),
		array(iNA_BANNED, 'game'), array(iNA_TOUR_DATA, 'game_name'),
		array(iNA_TOUR_PLAY, 'last_played_game'), array(iNA_USER_DATA, 'last_played'),
		array(iNA_CAT, 'last_game')
	);
	foreach ($references as $reference)
	{
		$sql = "UPDATE " . $reference[0] . " SET " . $reference[1] . " = '$new_sql' WHERE " . $reference[1] . " = '$old_sql'";
		if (!$db->sql_query($sql))
		{
			message_die(GENERAL_ERROR, $lang['no_game_update'], '', __LINE__, __FILE__, $sql);
		}
	}
}

function arcade_admin_delete_game_references($game_name, $game_id)
{
	global $db, $lang;

	$game_sql = $db->sql_escape($game_name);
	$delete_references = array(
		array(iNA_SCORES, 'game_name'), array(iNA_AT_SCORES, 'game_name'),
		array(iNA_GAMES_COMMENT, 'comment_game_name'), array(iNA_GAMES_COMMENT, 'game_name'),
		array(iNA_GAMES_RATE, 'rate_game_name'),
		array(iNA_SESSIONS, 'game_name'), array(iNA_TOUR_DATA, 'game_name')
	);
	foreach ($delete_references as $reference)
	{
		$sql = "DELETE FROM " . $reference[0] . " WHERE " . $reference[1] . " = '$game_sql'";
		if (!$db->sql_query($sql))
		{
			message_die(GENERAL_ERROR, $lang['no_game_delete'], '', __LINE__, __FILE__, $sql);
		}
	}

	$sql = "DELETE FROM " . iNA_FAV . " WHERE fav_game_id = " . (int) $game_id . " OR fav_game_name = '$game_sql'";
	if (!$db->sql_query($sql))
	{
		message_die(GENERAL_ERROR, $lang['no_game_delete'], '', __LINE__, __FILE__, $sql);
	}

	$clear_references = array(
		array(iNA_TOUR_PLAY, 'last_played_game'), array(iNA_USER_DATA, 'last_played'),
		array(iNA_CAT, 'last_game')
	);
	foreach ($clear_references as $reference)
	{
		$sql = "UPDATE " . $reference[0] . " SET " . $reference[1] . " = NULL WHERE " . $reference[1] . " = '$game_sql'";
		if (!$db->sql_query($sql))
		{
			message_die(GENERAL_ERROR, $lang['no_game_update'], '', __LINE__, __FILE__, $sql);
		}
	}
}

$version = $arcade->version('./../');
$mode = '';
$search_word = '';
$pagination = '&nbsp;';
//
//  Get Start of page info
//
$start = max(0, (int) $arcade->pass_var('start', 0));
//
//  Get Catagory Info
//
$cat_id = max(0, (int) $arcade->pass_var('cat_id', 0));
$admin_page_size = max(0, (int) $arcade->arcade_config['games_per_admin_page']);
//
// See which mode we are to operate in
//
if( isset($HTTP_POST_VARS['mode']) || isset($HTTP_GET_VARS['mode']) )
{
	$mode = ( isset($HTTP_POST_VARS['mode']) ) ? $HTTP_POST_VARS['mode'] : $HTTP_GET_VARS['mode'];
	$mode = is_array($mode) ? '' : stripslashes((string) $mode);
	$allowed_modes = array('add_game', 'edit', 'edit_games', 'import_game', 'sort_submit', 'game_up', 'game_down', 'clear_scores', 'clear_at_scores', 'delete', 'repair_game');
	if (!in_array($mode, $allowed_modes, true))
	{
		$mode = '';
	}
}
else if( isset($HTTP_POST_VARS['search']))
{
  $mode = "edit_games";
  $search_word = substr(arcade_admin_post_string('search_word'), 0, 100);
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

$request_is_post = isset($_SERVER['REQUEST_METHOD']) && strtoupper((string) $_SERVER['REQUEST_METHOD']) === 'POST';
$move_requested = $request_is_post && arcade_admin_post_int('move_to') > 0 && arcade_admin_post_int('id') > 0;
$write_modes = array('game_up', 'game_down', 'clear_scores', 'clear_at_scores', 'delete', 'repair_game');
$write_requested = $request_is_post && (
	in_array($mode, $write_modes, true) || $move_requested ||
	isset($HTTP_POST_VARS['save_game']) || isset($HTTP_POST_VARS['import_submit'])
);
if ($write_requested)
{
	phpbb_admin_require_post_session();
}

if (!$request_is_post && in_array($mode, $write_modes, true))
{
	$mode = 'edit_games';
}
//
//  Build Sort Option
//
if (isset($HTTP_GET_VARS['sort_mode']) || isset($HTTP_POST_VARS['sort_mode']))
{
	$sort_mode = (isset($HTTP_GET_VARS['sort_mode'])) ? $HTTP_GET_VARS['sort_mode'] : $HTTP_POST_VARS['sort_mode'];
	$sort_mode = is_array($sort_mode) ? 'game_id' : stripslashes((string) $sort_mode);
}
else
{
	$sort_mode = 'game_id';
}
$start = max(0, (int) $arcade->pass_var('start', 0));
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
if (!in_array($sort_mode, $mode_types, true))
{
	$sort_mode = 'game_id';
}
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
	$move_to = arcade_admin_post_int('move_to');
	$old_id = arcade_admin_post_int('id');
	$game_id = arcade_admin_post_int('new_id');
	if ($mode === 'game_up' || $mode === 'game_down')
	{
		$move_parts = explode(':', arcade_admin_post_string($mode), 2);
		$old_id = isset($move_parts[0]) ? (int) $move_parts[0] : 0;
		$game_id = isset($move_parts[1]) ? (int) $move_parts[1] : 0;
	}
  
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
	if ($old_id < 1 || $new_id < 1 || $old_id === $new_id)
	{
		message_die(GENERAL_MESSAGE, $lang['admin_move_failed']);
	}

	$sql = "SELECT game_id FROM " . iNA_GAMES . " WHERE game_id = $old_id";
	if (!$result = $db->sql_query($sql))
	{
		message_die(GENERAL_ERROR, $lang['no_game_data'], '', __LINE__, __FILE__, $sql);
	}
	if (!$db->sql_fetchrow($result))
	{
		message_die(GENERAL_MESSAGE, $lang['admin_move_failed']);
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

	$sql = "SELECT game_id FROM " . iNA_GAMES . "
		WHERE game_id = $new_id";
	if(!$result = $db->sql_query($sql))
	{
		message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
	}
	$target_game = $db->sql_fetchrow($result);
	if(!$target_game)
	{
		// ID is free so take it.
		$sql = "UPDATE " . iNA_GAMES . "
			SET game_id = $new_id
			WHERE game_id = $old_id";
		if(!$result = $db->sql_query($sql))
		{
			message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
		}
		$sql = "UPDATE " . iNA_FAV . " SET fav_game_id = $new_id WHERE fav_game_id = $old_id";
		if(!$db->sql_query($sql))
		{
			message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
		}
	}
	else
	{
		// Use slot zero only as a temporary swap value and keep favourites aligned.
		$sql = "SELECT game_id FROM " . iNA_GAMES . "
			WHERE game_id = 0";
		if(!$result = $db->sql_query($sql))
		{
			message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
		}
		if ($db->sql_fetchrow($result))
		{
			message_die(GENERAL_ERROR, $lang['game_move_error']);
		}

		$swap_queries = array(
			"UPDATE " . iNA_GAMES . " SET game_id = 0 WHERE game_id = $new_id",
			"UPDATE " . iNA_FAV . " SET fav_game_id = 0 WHERE fav_game_id = $new_id",
			"UPDATE " . iNA_GAMES . " SET game_id = $new_id WHERE game_id = $old_id",
			"UPDATE " . iNA_FAV . " SET fav_game_id = $new_id WHERE fav_game_id = $old_id",
			"UPDATE " . iNA_GAMES . " SET game_id = $old_id WHERE game_id = 0",
			"UPDATE " . iNA_FAV . " SET fav_game_id = $old_id WHERE fav_game_id = 0"
		);
		foreach ($swap_queries as $sql)
		{
			if(!$db->sql_query($sql))
			{
				message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
			}
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
		$group_type .= '<option value="' . (int) $groups_info['group_id'] . '"' . $selected . '>' . phpbb_profile_text($groups_info['group_name']) . '</option>';
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
		$rank_type .= '<option value="' . (int) $ranks_info['rank_id'] . '"' . $selected . '>' . phpbb_profile_text($ranks_info['rank_title']) . '</option>';
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
		$select_cat .= '<option value="' . (int) $cat_info[$i]['cat_id'] . '"' . $selected . '>' . $space . phpbb_profile_text($cat_info[$i]['cat_name']) . '</option>';
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

		"NAME" => phpbb_profile_text($game_info['game_name']),
		"PATH" => phpbb_profile_text($game_info['game_path']),
		"IMAGE" => phpbb_profile_text($game_info['image_path']),
		"DESC" => phpbb_profile_text($game_info['game_desc']),
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
		"GAME_INSTRUCTIONS" => phpbb_profile_text($game_info['instructions']),
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
		'S_HIDDEN_FIELDS' => '<input type="hidden" name="order" value="'. $sort_order .'"><input type="hidden" name="sort_mode" value="'. $sort_mode .'"><input type="hidden" name="start" value="'. (int) $start .'"><input type="hidden" name="save_game" value="1"><input type="hidden" name="id" value="' . (int) $game_id . '"><input type="hidden" name="cat_id" value="' . (int) $cat_id . '">' . phpbb_admin_session_field(),
		'S_SCORE_ACTION' => append_sid("$file"),
		'S_SCORE_HIDDEN' => '<input type="hidden" name="game_name" value="' . phpbb_profile_text($game_info['game_name']) . '">'));
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
    $cat_list .= '<option value="'.(int) $catrows[$i]['cat_id'].'">'.phpbb_admin_html($catrows[$i]['cat_name']).'</option>';
  }
  $cat_list .= '</select>';


	$template->assign_vars(array(
		"S_ACTION" => append_sid($file),
		"VERSION" => $version,
		"DASH" => $lang['game_dash'],
		"DIR_NAME" => phpbb_admin_html($arcade->arcade_config['games_path']),
		
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
		
		"S_HIDDEN_FIELDS" => '<input type="hidden" name="import_submit" value="1">' . phpbb_admin_session_field()) );
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
	
	$autosize = arcade_admin_post_int('autosize') ? 1 : 0;
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
	
	$online = arcade_admin_post_int('online') ? 1 : 0;
	$cat_id = arcade_admin_post_int('cat_id', -1);
	if ($cat_id > 0)
	{
		$sql = "SELECT cat_id FROM " . iNA_CAT . " WHERE cat_id = $cat_id AND cat_type <> 'l'";
		if (!$result = $db->sql_query($sql))
		{
			message_die(GENERAL_ERROR, $lang['no_cat_data'], '', __LINE__, __FILE__, $sql);
		}
		if (!$db->sql_fetchrow($result))
		{
			$cat_id = -1;
		}
	}

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
						if (@file_exists($main_dir_path . '/' . $new_name[0] . '.gif'))
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
									if (@file_exists($main_dir_path . '/' . $file_name . '/' . $new_name[0] . '.gif'))
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
									insert_ina_game($sub_file_name, $import_path . $file_name . '/', 0, '', $flash, $online, $width, $height, $cat_id);
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
	if($admin_page_size > 0)
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
		if ((int) $total['total'] >= $admin_page_size)
		{
			$total_games = $total['total'];
			$pagination = generate_pagination("$file?mode=edit_games&amp;cat_id=$cat_id&amp;order=$sort_order&amp;sort_mode=$sort_mode", $total_games, $admin_page_size, $start). '&nbsp;';
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
	$search_word_sql = $db->sql_escape($search_word);
    $sql .= " WHERE g.game_desc LIKE '%$search_word_sql%'";
  }
	else if($cat_id > 0)
	{
		$sql .= " WHERE g.cat_id = '" . $cat_id . "'";
	}
	$sql .= " ORDER BY $order_by $sort_order";
	if($admin_page_size > 0)
	{
		$sql .= " LIMIT $start,$admin_page_size";
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
		$game_id = (int) $game_rows[$i]['game_id'];
		$game_name = $game_rows[$i]['game_name'];
		$game_name_sql = $db->sql_escape($game_name);
		$game_name_url = rawurlencode($game_name);
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
			$game_cat_id = phpbb_profile_text($game_rows[$i]['cat_name']);
		}
		else
		{
			$game_cat_id = $lang['None'];
		}
//
// Check path to game for a '/' at the end
//
		if($game_path !== '' && substr($game_path, -1) !== '/')
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
				$image_up = '<button type="submit" name="game_up" value="' . $game_id . ':0"><img src="' . $phpbb_root_path . 'images/arrow_up.gif" border="0" alt="' . $lang['admin_up'] . '" /></button>';
			}
			else
			{
				$image_up = '<button type="submit" name="game_up" value="' . $game_id . ':' . (int) $game_rows[$i - 1]['game_id'] . '"><img src="' . $phpbb_root_path . 'images/arrow_up.gif" border="0" alt="' . $lang['admin_up'] . '" /></button>';
			}
			if ($game_id <= 1 || (!isset($game_rows[$i + 1]['game_id']) && $cat_id))
			{
				$image_down = '';
			}
			else if ( $cat_id == 0 )
			{ 
				$image_down = '<button type="submit" name="game_down" value="' . $game_id . ':0"><img src="' . $phpbb_root_path . 'images/arrow_down.gif" border="0" alt="' . $lang['admin_down_full'] . '" /></button>';
			}
			else
			{ 
				$image_down = '<button type="submit" name="game_down" value="' . $game_id . ':' . (int) $game_rows[$i + 1]['game_id'] . '"><img src="' . $phpbb_root_path . 'images/arrow_down.gif" border="0" alt="' . $lang['admin_down_full'] . '" /></button>';
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
      WHERE game_name = '" . $game_name_sql . "'
        ORDER BY score " . $game_order;
		$scorerows = $db->sql_fetchrowset($db->sql_query($sql));
		if(count($scorerows) > 0)
		{
		  $stats = '<a href="' . append_sid("admin_arcade_scores.$phpEx?mode=scores&amp;game_name=$game_name_url").'">'.count($scorerows) . $lang['admin_score_top_score'] . $arcade->convert_score($scorerows[0]['score']) . '</a>';
    }
    else
    {
      $stats = '';
    }
		$sql = "SELECT count(comment_id) as total FROM " . iNA_GAMES_COMMENT . "
      WHERE comment_game_name = '" . $game_name_sql . "'";
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
      WHERE game_name = '" . $game_name_sql . "'
        ORDER BY score " . $game_order;
		$scorerows = $db->sql_fetchrowset($db->sql_query($sql));
		if(count($scorerows) > 0)
		{
		$stats .= '<br><a href="' . append_sid("admin_arcade_scores.$phpEx?mode=at_scores&amp;game_name=$game_name_url") .'">' . count($scorerows) . $lang['admin_at_score_top_score'] . $arcade->convert_score($scorerows[0]['score']) . '</a>';
    }
    else
    {
      $stats .= '<br>';
    }
    $sql = "SELECT AVG(rate_point) AS rating FROM " . iNA_GAMES_RATE . "
      WHERE rate_game_name = '" . $game_name_sql . "'";
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
			'NAME' => phpbb_profile_text($game_name),
			'PATH' => phpbb_profile_text($game_path),
			'IMAGE' => phpbb_profile_text($image_path),
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
			"DESC" => phpbb_profile_text($game_desc),
			"U_GAME_EDIT" => append_sid("$file?mode=edit&amp;id=" . $game_id . "&amp;cat_id=" . $cat_id . "&amp;order=".$sort_order."&amp;sort_mode=".$sort_mode."&amp;start=".$start), 
			"DELETE_ID" => $game_id)
		);
	}
	$template->assign_vars(array(
		'S_CONFIG_ACTION' => $amod_link,
		'SEARCH_WORD' => phpbb_profile_text($search_word),
		'S_MODE_SELECT' => $select_sort_mode,
		'S_ORDER_SELECT' => $select_sort_order,
		'S_HIDDEN_FIELDS' => '<input type="hidden" name="cat_id" value="'.(int) $cat_id.'">' . phpbb_admin_session_field(),
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
		$table = iNA_AT_SCORES;
		$places_sql = 'at_';
	}
	if(!isset($HTTP_POST_VARS['confirm']))
	{
		if(isset($HTTP_POST_VARS['cancel']))
		{
			redirect(append_sid("$filename"));
			exit;
		}
		$template->set_filenames(array('body' => 'confirm_body.tpl'));
		$confirm_text = ($mode === 'clear_at_scores') ? $lang['arcade_delete_at_sure'] : $lang['arcade_delete_scores_sure'];
		$template->assign_vars(array(
			'MESSAGE_TITLE' => $lang['Confirm'],
			'MESSAGE_TEXT' => $confirm_text,
			'L_NO' => $lang['No'],
			'L_YES' => $lang['Yes'],
			'S_CONFIRM_ACTION' => append_sid("$filename"),
			'S_HIDDEN_FIELDS' => '<input type="hidden" name="mode" value="' . phpbb_admin_html($mode) . '"><input type="hidden" name="cat_id" value="' . (int) $cat_id . '">' . phpbb_admin_session_field(),
		));
		$template->pparse('body');
		exit;
	}

	$cat_id = arcade_admin_post_int('cat_id');
	if($cat_id > 0)
	{
		$sql = "SELECT game_name, reverse_list FROM " . iNA_GAMES . "
				WHERE cat_id = '" . $cat_id . "'";
		if(!$result = $db->sql_query($sql))
		{
			message_die(GENERAL_ERROR, $lang['no_game_data'], '', __LINE__, __FILE__, $sql);
		}
		while ($game_info = $db->sql_fetchrow($result))
		{
			$game_name = $game_info['game_name'];
			$list_type = !empty($game_info['reverse_list']) ? 'ASC' : 'DESC';
			$top_score = best_game_player($table, $game_name, $list_type);
			$game_name_sql = $db->sql_escape($game_name);
			$sql = "DELETE FROM " . $table . "
				WHERE game_name = '" . $game_name_sql . "'";
			if(!$delete = $db->sql_query($sql))
			{
				message_die(GENERAL_ERROR, $lang['no_game_data'], '', __LINE__, __FILE__, $sql);
			}

			$sql = "UPDATE " . iNA_USER_DATA . "
				SET ".$places_sql."first_places = GREATEST(".$places_sql."first_places - 1, 0)
				WHERE user_id = " . (int) $top_score['player_id'];
		    if(!$update = $db->sql_query($sql)) 
			{
				message_die(GENERAL_ERROR, $lang['no_user_update'], "", __LINE__, __FILE__, $sql); 
			}
  		$sql = "UPDATE " . iNA_GAMES . "
  			SET ".$places_sql."highscore_id = 0
				WHERE game_name = '" . $game_name_sql . "'";
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
	$game_id = arcade_admin_post_int('delete');
	if( $game_id )
	{
		$sql = "SELECT game_name, cat_id, played, game_avail, reverse_list FROM " . iNA_GAMES . "
			WHERE game_id = $game_id";
		if( !$result = $db->sql_query($sql) )
		{
			message_die(GENERAL_ERROR, $lang['no_game_delete'], "", __LINE__, __FILE__, $sql);
		}
		$game_info = $db->sql_fetchrow($result);
		if (!$game_info)
		{
			message_die(GENERAL_MESSAGE, $lang['admin_game_not_deleted']);
		}
		$list_type = !empty($game_info['reverse_list']) ? 'ASC' : 'DESC';
		foreach (array(iNA_SCORES => 'first_places', iNA_AT_SCORES => 'at_first_places') as $score_table => $places_column)
		{
			$top_score = best_game_player($score_table, $game_info['game_name'], $list_type);
			$top_player_id = isset($top_score['player_id']) ? (int) $top_score['player_id'] : 0;
			if ($top_player_id > 0)
			{
				$sql = "UPDATE " . iNA_USER_DATA . " SET $places_column = GREATEST($places_column - 1, 0) WHERE user_id = $top_player_id";
				if (!$db->sql_query($sql))
				{
					message_die(GENERAL_ERROR, $lang['no_user_update'], '', __LINE__, __FILE__, $sql);
				}
			}
		}
		arcade_admin_delete_game_references($game_info['game_name'], $game_id);
		$sql = "DELETE FROM " . iNA_GAMES . "
			WHERE game_id = $game_id";
		if( !$result = $db->sql_query($sql) )
		{
			message_die(GENERAL_ERROR, $lang['no_game_delete'], "", __LINE__, __FILE__, $sql);
		}
		if(!empty($game_info['game_avail']))
		{
			$deleted_cat_id = (int) $game_info['cat_id'];
			$deleted_plays = max(0, (int) $game_info['played']);
  		$sql = "UPDATE ". iNA_CAT . "
			SET total_played = GREATEST(total_played - $deleted_plays, 0), total_games = GREATEST(total_games - 1, 0)
			WHERE cat_id = -1";
  		if( !$result = $db->sql_query($sql) )
  		{
  			message_die(GENERAL_ERROR, $lang['no_cat_update'], "", __LINE__, __FILE__, $sql);
  		}
			if($deleted_cat_id > 0)
      {
    		$sql = "UPDATE ". iNA_CAT . "
				SET total_played = GREATEST(total_played - $deleted_plays, 0), total_games = GREATEST(total_games - 1, 0)
				WHERE cat_id = $deleted_cat_id";
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
		$arcade->clear_cache('categories');

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
	$sql = "REPAIR TABLE " . iNA_GAMES . ", " . iNA_CAT . ", " . iNA_FAV . ", " . iNA_SESSIONS . ", " . iNA_SCORES . ", " . iNA_AT_SCORES;
	if(!$result = $db->sql_query($sql))
	{
		message_die(GENERAL_ERROR, $lang['no_game_repair'], "", __LINE__, __FILE__, $sql);
	}
	$sql = "OPTIMIZE TABLE " . iNA_GAMES . ", " . iNA_CAT . ", " . iNA_FAV . ", " . iNA_SESSIONS . ", " . iNA_SCORES . ", " . iNA_AT_SCORES;
	if(!$result = $db->sql_query($sql))
	{
		message_die(GENERAL_ERROR, $lang['no_game_repair'], "", __LINE__, __FILE__, $sql);
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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($HTTP_POST_VARS['save_game']))
{
	$game_id = arcade_admin_post_int('id');
	if($game_id < 0)
	{
		$game_id = 0;
	}

	$game_name = substr(arcade_admin_post_string('game_name'), 0, 50);
	if ($game_name === '' || $game_name === '.' || $game_name === '..' || preg_match('#[\s/\\\\\x00-\x1F\x7F]#', $game_name))
	{
		$message = $lang['admin_game_wrong_name'];
		$message .= sprintf($lang['admin_return_games'], "<a href=\"" . append_sid("$file?mode=edit_games&amp;cat_id=$cat_id&amp;order=$sort_order&amp;sort_mode=$sort_mode&amp;start=$start") . "\">", "</a>") . "<br /><br />" . sprintf($lang['Click_return_admin_index'], "<a href=\"" . append_sid("index.$phpEx?pane=right") . "\">", "</a>");
		message_die(GENERAL_MESSAGE, $message);
	}
	$sql = "SELECT game_id FROM " . iNA_GAMES . " WHERE game_name = '" . $db->sql_escape($game_name) . "'";
	if ($game_id > 0)
	{
		$sql .= " AND game_id <> $game_id";
	}
	if (!$result = $db->sql_query($sql))
	{
		message_die(GENERAL_ERROR, $lang['no_game_data'], '', __LINE__, __FILE__, $sql);
	}
	if ($db->sql_fetchrow($result))
	{
		message_die(GENERAL_MESSAGE, $lang['admin_game_exists']);
	}

	$game_path = phpbb_arcade_local_asset(arcade_admin_post_string('game_path'));
	if ($game_path === '')
	{
		$message = $lang['admin_game_wrong_name'];
		$message .= sprintf($lang['admin_return_games'], "<a href=\"" . append_sid("$file?mode=edit_games") . "\">", "</a>");
		message_die(GENERAL_MESSAGE, $message);
	}
	if (substr($game_path, -1) !== '/')
	{
		$game_path .= '/';
	}
	
	$image_path = substr(arcade_admin_post_string('image_path'), 0, 255);
	if ($image_path !== '' && phpbb_arcade_local_asset($image_path) === '')
	{
		$image_path = '';
	}

	$game_desc = substr(arcade_admin_post_string('game_desc'), 0, 255);

	$game_charge = min(4294967295, max(0, arcade_admin_post_int('game_charge')));
	$game_reward = min(4294967295, max(0, arcade_admin_post_int('game_reward')));
	$game_bonus = min(65535, max(0, arcade_admin_post_int('game_bonus')));
	$at_game_bonus = min(65535, max(0, arcade_admin_post_int('game_at_bonus')));
	$game_use_gl = arcade_admin_post_int('game_use_gl') ? 1 : 0;
	$game_flash = arcade_admin_post_int('game_flash') ? 1 : 0;
	$game_show_score = arcade_admin_post_int('game_show_score') ? 1 : 0;
	$game_avail = arcade_admin_post_int('game_avail') ? 1 : 0;
	$allow_guest = arcade_admin_post_int('allow_guest') ? 1 : 0;
	$game_reverse_list = arcade_admin_post_int('game_reverse_list') ? 1 : 0;
	$game_highscore_limit = max(0, arcade_admin_post_int('highscore_limit'));
	$game_at_highscore_limit = max(0, arcade_admin_post_int('at_highscore_limit'));
	$game_cat_id = arcade_admin_post_int('game_cat_id');
	$game_instructions = substr(arcade_admin_post_string('game_instructions'), 0, 65535);
//
//  Check for the existance of the file on the server (only if local).
//
  if ($game_avail)
  {
    $ext = ( $game_flash == 1) ? '.swf' : '';
	$game_asset = phpbb_arcade_local_asset($game_path . $game_name . $ext);
    if ($game_asset === '' || !is_file($phpbb_root_path . $game_asset))
    {
		$message = sprintf($lang['admin_file_not_found'], phpbb_profile_text($game_path . $game_name . $ext));
    	$message .= "<br />" . sprintf($lang['admin_return_games'], "<a href=\"" . append_sid("$file?mode=edit_games") . "\">", "</a>");
      message_die(GENERAL_MESSAGE, $message);
    } 
  }

	$win_width = min(4096, max(0, arcade_admin_post_int('win_width')));
	$win_height = min(4096, max(0, arcade_admin_post_int('win_height')));
	$reset_scores = arcade_admin_post_int('reset_scores') ? 1 : 0;
	$reset_at_scores = arcade_admin_post_int('reset_at_scores') ? 1 : 0;
	$score_type = min(8, max(0, arcade_admin_post_int('score_type')));
	$group_required = min(16777215, max(0, arcade_admin_post_int('group_required')));
	$rank_required = min(2147483647, max(0, arcade_admin_post_int('rank_required')));
	$level_required = min(255, max(0, arcade_admin_post_int('level_required')));
	$game_autosize = arcade_admin_post_int('game_autosize') ? 1 : 0;
	$game_control = min(3, max(0, arcade_admin_post_int('game_control')));

	$list_type = 'DESC';
	if($game_reverse_list)
	{
		$list_type = 'ASC';
	}
	if($game_cat_id <= 0)
  {
    $game_cat_id = -1;
  }
	else
	{
		$sql = "SELECT cat_id FROM " . iNA_CAT . " WHERE cat_id = $game_cat_id";
		if (!$result = $db->sql_query($sql))
		{
			message_die(GENERAL_ERROR, $lang['no_cat_data'], '', __LINE__, __FILE__, $sql);
		}
		if (!$db->sql_fetchrow($result))
		{
			$game_cat_id = -1;
		}
	}
	if(!$win_width || !$win_height)
	{
		$game_ext = get_ina_extension($game_name);
		$game_size = false;
		if(in_array($game_ext, array('gif', 'jpg', 'jpeg', 'png', 'webp'), true))
		{
			$game_size = @getimagesize($phpbb_root_path . $game_path . $game_name);
		}
		if(is_array($game_size) && !empty($game_size[0]) && !empty($game_size[1]))
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

	$game_name_sql = $db->sql_escape($game_name);
	$game_path_sql = $db->sql_escape($game_path);
	$image_path_sql = $db->sql_escape($image_path);
	$game_desc_sql = $db->sql_escape($game_desc);
	$game_instructions_sql = $db->sql_escape($game_instructions);
//
//  EDIT used (i.e. game ID set)
//
	if( $game_id > 0)
	{
	$sql = "SELECT game_name, cat_id, played, game_avail FROM " . iNA_GAMES . "
      WHERE game_id = $game_id";
    if( !$result = $db->sql_query($sql) )
  	{
   		message_die(GENERAL_ERROR, $lang['no_game_save'], "", __LINE__, __FILE__, $sql);
  	}
		$old_game_info = $db->sql_fetchrow($result);
		if (!$old_game_info)
		{
			message_die(GENERAL_ERROR, $lang['no_game_save']);
		}
		$sql = "UPDATE " . iNA_GAMES . "
			SET game_name = '$game_name_sql', game_path = '$game_path_sql', image_path = '$image_path_sql', game_desc = '$game_desc_sql', game_charge = $game_charge, game_reward = $game_reward, game_bonus = $game_bonus, at_game_bonus = $at_game_bonus, game_use_gl = $game_use_gl, game_flash = $game_flash, game_show_score = $game_show_score, game_avail = $game_avail, allow_guest = $allow_guest, reverse_list = $game_reverse_list, win_width = $win_width, win_height = $win_height, highscore_limit = $game_highscore_limit, at_highscore_limit = $game_at_highscore_limit, instructions = '$game_instructions_sql', cat_id = $game_cat_id, score_type = $score_type, group_required = $group_required, rank_required = $rank_required, level_required = $level_required, game_autosize = $game_autosize, game_control = $game_control
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
//  Insert Game into Database
//
		$sql = "INSERT INTO " . iNA_GAMES . " (date_added, game_name, game_path, image_path, game_desc, game_charge, game_reward, game_bonus, game_use_gl, game_flash, game_show_score, game_avail, allow_guest, reverse_list, win_width, win_height, highscore_limit, instructions, at_game_bonus, at_highscore_limit, cat_id, score_type, group_required, rank_required, level_required, game_autosize, game_control) 
			VALUES (" . time() . ", '$game_name_sql', '$game_path_sql', '$image_path_sql', '$game_desc_sql', $game_charge, $game_reward, $game_bonus, $game_use_gl, $game_flash, $game_show_score, $game_avail, $allow_guest, $game_reverse_list, $win_width, $win_height, $game_highscore_limit, '$game_instructions_sql', $at_game_bonus, $game_at_highscore_limit, $game_cat_id, $score_type, $group_required, $rank_required, $level_required, $game_autosize, $game_control)";
	}

	if( !$result = $db->sql_query($sql) )
	{
		message_die(GENERAL_ERROR, $lang['no_game_save'], "", __LINE__, __FILE__, $sql);
	}
	if ($game_id > 0 && strcmp((string) $old_game_info['game_name'], $game_name) !== 0)
	{
		arcade_admin_rename_game_references($old_game_info['game_name'], $game_name);
	}

	// Keep category totals in sync only after the game write succeeds.
	if ($game_id > 0)
	{
		$old_cat_id = (int) $old_game_info['cat_id'];
		$old_game_avail = !empty($old_game_info['game_avail']);
		if ($old_cat_id !== $game_cat_id || $old_game_avail !== (bool) $game_avail)
		{
			if ($old_game_avail)
			{
				$catsql = "UPDATE " . iNA_CAT . " SET total_games = GREATEST(total_games - 1, 0) WHERE cat_id = -1";
				$db->sql_query($catsql) || message_die(GENERAL_ERROR, $lang['no_cat_update'], '', __LINE__, __FILE__, $catsql);
				if ($old_cat_id > 0)
				{
					$catsql = "UPDATE " . iNA_CAT . " SET total_games = GREATEST(total_games - 1, 0) WHERE cat_id = $old_cat_id";
					$db->sql_query($catsql) || message_die(GENERAL_ERROR, $lang['no_cat_update'], '', __LINE__, __FILE__, $catsql);
				}
			}
			if ($game_avail)
			{
				$catsql = "UPDATE " . iNA_CAT . " SET total_games = total_games + 1 WHERE cat_id = -1";
				$db->sql_query($catsql) || message_die(GENERAL_ERROR, $lang['no_cat_update'], '', __LINE__, __FILE__, $catsql);
				if ($game_cat_id > 0)
				{
					$catsql = "UPDATE " . iNA_CAT . " SET total_games = total_games + 1 WHERE cat_id = $game_cat_id";
					$db->sql_query($catsql) || message_die(GENERAL_ERROR, $lang['no_cat_update'], '', __LINE__, __FILE__, $catsql);
				}
			}
		}
	}
	else
	{
		if ($game_avail)
		{
			$catsql = "UPDATE " . iNA_CAT . " SET total_games = total_games + 1 WHERE cat_id = -1";
			$db->sql_query($catsql) || message_die(GENERAL_ERROR, $lang['no_cat_update'], '', __LINE__, __FILE__, $catsql);
			if ($game_cat_id > 0)
			{
				$catsql = "UPDATE " . iNA_CAT . " SET total_games = total_games + 1 WHERE cat_id = $game_cat_id";
				$db->sql_query($catsql) || message_die(GENERAL_ERROR, $lang['no_cat_update'], '', __LINE__, __FILE__, $catsql);
			}
		}

		if($arcade->arcade_config['games_pm_new'])
		{
			$message = sprintf($lang['games_new_game_added'], $game_desc);
			$usersql = "SELECT user_id FROM " . USERS_TABLE;
			if($user_result = $db->sql_query($usersql))
			{
				$userrows = $db->sql_fetchrowset($user_result);
				foreach ($userrows as $userrow)
				{
					@ina_send_user_pm((int) $userrow['user_id'], $lang['games_new_game_added_info'], $message, (int) $userdata['user_id'], 'YES');
				}
				$arcade->write_cache('user_id', $userrows, './../');
				$db->sql_freeresult($user_result);
			}
		}
	}
//
//  Check to see if the scores need to be reset
//	
	if ($reset_scores)
	{
		$top_score = best_game_player(iNA_SCORES, $game_name, $list_type);

		$sql = "DELETE FROM " . iNA_SCORES . "
			WHERE game_name = '$game_name_sql'";
		if( !$result = $db->sql_query($sql) )
		{
			message_die(GENERAL_ERROR, $lang['no_score_reset'], "", __LINE__, __FILE__, $sql);
		}
		$sql = "UPDATE " . iNA_GAMES . "
		  SET highscore_id = 0
			WHERE game_name = '$game_name_sql'";
		if( !$result = $db->sql_query($sql) )
		{
			message_die(GENERAL_ERROR, $lang['no_score_reset'], "", __LINE__, __FILE__, $sql);
		}
		$top_player_id = (int) $top_score['player_id'];
		$sql = "UPDATE " . iNA_USER_DATA . "
			SET first_places = GREATEST(first_places - 1, 0)
			WHERE user_id = $top_player_id";
	    if(!$result = $db->sql_query($sql)) 
		{
			message_die(GENERAL_ERROR, $lang['no_user_update'], "", __LINE__, __FILE__, $sql); 
		}
	}
	if ($reset_at_scores)
	{
		$top_score = best_game_player(iNA_AT_SCORES, $game_name, $list_type);

		$sql = "DELETE FROM " . iNA_AT_SCORES . "
			WHERE game_name = '$game_name_sql'";
		if( !$result = $db->sql_query($sql) )
		{
			message_die(GENERAL_ERROR, $lang['no_score_reset'], "", __LINE__, __FILE__, $sql);
		}
		$sql = "UPDATE " . iNA_GAMES . "
		  SET at_highscore_id = 0
			WHERE game_name = '$game_name_sql'";
		if( !$result = $db->sql_query($sql) )
		{
			message_die(GENERAL_ERROR, $lang['no_score_reset'], "", __LINE__, __FILE__, $sql);
		}
		$top_player_id = (int) $top_score['player_id'];
		$sql = "UPDATE " . iNA_USER_DATA . "
			SET at_first_places = GREATEST(at_first_places - 1, 0)
			WHERE user_id = $top_player_id";
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
