<?php
/*************************************************************************** 
 *                          admin_arcade_tournaments.php 
 *                          ---------------------------- 
 *   begin                : Friday, May 11, 2006
 *   copyright            : (c) 2006 dEfEndEr
 *   email                : defenders_realm@yahoo.com
 *
 *   $Id: admin_arcade_tournaments.php,v 1.0.0 2006/05/11 12:59:59 dEfEndEr Exp $
 ***************************************************************************
 * 
 *   This program is free software; you can redistribute it and/or modify 
 *   it under the terms of the GNU General Public License as published by 
 *   the Free Software Foundation; either version 2 of the License, or 
 *   (at your option) any later version. 
 * 
 ***************************************************************************/
//
//  Make this file apart of the phpBB system files.
//
if (!defined('IN_PHPBB')) { define('IN_PHPBB', true); }
if (!defined('ARCADE_ADMIN')) { define('ARCADE_ADMIN', 1); }
//
//  Make sure the ACP doesn't go and run something.
//
if( !empty($setmodules) )
{
	return;
}
//
//  Set the system ROOT directory
//
$phpbb_root_path = './../';
//
//  Load phpBB System required files
//
$phpEx    = substr(strrchr(__FILE__, '.'), 1);
require('pagestart.' . $phpEx);
//
//  Load the Arcade required files
//
include_once($phpbb_root_path . 'includes/functions_arcade.'.$phpEx);
//
//  Check the phpBB Arcade Mod version
//
$version = $arcade->version($phpbb_root_path);
//
//  Set filename
//
$file = basename(__FILE__);
//
//  Get required Variables
//
$mode       = $arcade->pass_var('mode', '');
$tour_id    = (int) $arcade->pass_var('id', 0);
$tour_name  = $tour_desc = '';
$tour_players = 2;
$tour_turns = 1;
$block_plays = 0;
$tour_active = 0;
$tour_mode = '';
$total_tour_players = '';
$select_active = '';
$select_cat = '';
$tour_info = array('tour_name' => '', 'tour_desc' => '', 'tour_max_players' => 2, 'tour_player_turns' => 1, 'block_plays' => 0, 'tour_active' => 0);

function arcade_tournament_require_token($userdata, $lang)
{
  global $HTTP_POST_VARS;
  if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($HTTP_POST_VARS['sid']) ||
    !hash_equals((string) $userdata['session_id'], (string) $HTTP_POST_VARS['sid']))
  {
    message_die(GENERAL_MESSAGE, $lang['Not_Authorised']);
  }
}

if (isset($HTTP_POST_VARS['delete_tour']))
{
  arcade_tournament_require_token($userdata, $lang);
  $mode = 'delete';
  $tour_id = intval($HTTP_POST_VARS['delete_tour']);
}
//
//  Update Tournament Config
//
if(isset($HTTP_POST_VARS['tour_submit']))
{
  arcade_tournament_require_token($userdata, $lang);
  $sql = "SELECT * FROM " . iNA . "
  	WHERE config_name IN('games_tournament_mode','games_tournament_max','games_tournament_games','games_tournament_players', 'games_tournament_user')";
  if( !$result = $db->sql_query($sql) )
  {
  	message_die(CRITICAL_ERROR, $lang['no_config_data'], '', __LINE__, __FILE__, $sql);
  }
	while( $row = $db->sql_fetchrow($result) )
	{
//
//  Build Array for database updates
//
		$config_name = $row['config_name'];
		$config_value = $row['config_value'];
		$default_config[$config_name] = $config_value;
//
//  next update the different Cache settings
//
		$new[$config_name] = isset($HTTP_POST_VARS[$config_name]) ? max(0, intval($HTTP_POST_VARS[$config_name])) : $default_config[$config_name];
		if (in_array($config_name, array('games_tournament_max', 'games_tournament_games'), true)) { $new[$config_name] = max(1, (int) $new[$config_name]); }
		if ($config_name === 'games_tournament_players') { $new[$config_name] = max(2, (int) $new[$config_name]); }
		if ($config_name === 'games_tournament_user') { $new[$config_name] = $new[$config_name] ? 1 : 0; }

		$sql = "UPDATE " . iNA . "
  			SET config_value = '" . ($new[$config_name]) . "'
			WHERE config_name = '$config_name'";
		
  	if(!$db->sql_query($sql))
  	{
  		message_die(GENERAL_ERROR, $lang['no_config_update'] . $config_name, '', __LINE__, __FILE__, $sql);
  	}
  }
//
//  Clear the cached copy of the config
//
  $arcade->clear_cache('config');

	$message = $lang['admin_config_updated'];
	$message .= sprintf($lang['admin_return_arcade'], "<a href=\"" . append_sid($file) . "\">", "</a>") . "<br /><br />" . sprintf($lang['Click_return_admin_index'], "<a href=\"" . append_sid("index.$phpEx?pane=right") . "\">", "</a>");
	message_die(GENERAL_MESSAGE, $message);
}
//
//  User is Adding a New Tournament / Updating an Old one
//
else if(isset($HTTP_POST_VARS['add_tour_submit']))
{
  arcade_tournament_require_token($userdata, $lang);
//
//  Here we have to ADD the games and save the header information
//
  $tour_name = isset($HTTP_POST_VARS['tour_name']) ? substr(trim(stripslashes($HTTP_POST_VARS['tour_name'])), 0, 25) : '';
  $tour_desc = isset($HTTP_POST_VARS['tour_desc']) ? trim(stripslashes($HTTP_POST_VARS['tour_desc'])) : '';
  if ($tour_name === '')
  {
    message_die(GENERAL_MESSAGE, $lang['error_no_tour_info']);
  }
  $block_plays = $arcade->pass_var('block_plays', 0) ? 1 : 0;
  $tour_active = max(0, min(3, (int) $arcade->pass_var('tour_active', 0)));
  $tour_max_players = (int) $arcade->pass_var('tour_max_players', 0);
	$maximum_players = max(2, (int) $arcade->arcade_config['games_tournament_players']);
  if($tour_max_players < 2)
  {
    $tour_max_players = $maximum_players;
  }
  if($tour_max_players > $maximum_players)
  {
    $tour_max_players = $maximum_players;
  }
  $tour_player_turns = (int) $arcade->pass_var('tour_player_turns', 0);
  if($tour_player_turns < 1)
  {
    $tour_player_turns = 1;
  }

  if($tour_id > 0)
  {
//
//  Update OLD Tournament
//
    $sql = "UPDATE " . iNA_TOUR . "
        SET tour_name = '" . $db->sql_escape($tour_name) . "', tour_desc = '" . $db->sql_escape($tour_desc) . "', block_plays = $block_plays, tour_active = $tour_active, tour_max_players = $tour_max_players, tour_player_turns = $tour_player_turns
      WHERE tour_id = $tour_id";
  	$message = $lang['admin_tournament_updated'];
  }
  else
  {
//
//  New Tournmament
//
    $sql = "INSERT INTO " . iNA_TOUR . " (tour_name, tour_desc, block_plays, tour_active, tour_max_players, tour_player_turns, start_id, start_date)
      VALUES ('" . $db->sql_escape($tour_name) . "', '" . $db->sql_escape($tour_desc) . "', $block_plays, $tour_active, $tour_max_players, $tour_player_turns, ".(int) $userdata['user_id'].", ". time() .")";
 
  	$message = $lang['admin_tournament_added'];
  }
	if( !$result = $db->sql_query($sql) )
	{
		message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
	}
//
//  Force update of cache files
//
  $arcade->clear_cache('tournaments');

	$message .= sprintf($lang['admin_return_arcade'], "<a href=\"" . append_sid($file) . "\">", "</a>") . "<br /><br />" . sprintf($lang['Click_return_admin_index'], "<a href=\"" . append_sid("index.$phpEx?pane=right") . "\">", "</a>");

/*******************************************************************************
//  Add an option to ADD/Edit the Games and Players HERE...!
*******************************************************************************/

	message_die(GENERAL_MESSAGE, $message);
}
//
//  Set-up the mode by user selection
//
else if(isset($HTTP_POST_VARS['add_tour']))
{
  $mode = 'add_tour';
}
else if(isset($HTTP_POST_VARS['add_games']))
{
  $mode = 'add_games';
}
//
//  Do the main processing
//
if(isset($HTTP_POST_VARS['add_game']))
{
  arcade_tournament_require_token($userdata, $lang);
  $games_list = isset($HTTP_POST_VARS['game_id']) ? $HTTP_POST_VARS['game_id'] : 0;

  if($tour_id <= 0)
  {
    message_die(GENERAL_MESSAGE, $lang['Not_Authorised']);
  }
  $sql = "SELECT tour_id FROM " . iNA_TOUR . " WHERE tour_id = " . (int) $tour_id;
  if (!($tour_result = $db->sql_query($sql)) || !$db->sql_fetchrow($tour_result))
  {
    message_die(GENERAL_MESSAGE, $lang['Not_Authorised']);
  }
  if(is_array($games_list))
  {
    for($i = (count($games_list)); $i > 0;$i--)
    {
      $selected_game_id = intval($games_list[($i - 1)]);
      if ($selected_game_id <= 0) { continue; }
      $sql = "SELECT game_name FROM " . iNA_GAMES . "
        WHERE game_id = " . $selected_game_id;
    	if( !$result = $db->sql_query($sql) )
    	{
    		message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
      }
      $game_info = $db->sql_fetchrow($result);
      
//
//
//
      if (!$game_info) { continue; }
      $sql = "INSERT IGNORE INTO " . iNA_TOUR_DATA . "
        (tour_id, game_name) VALUES (" . (int) $tour_id . ", '" . $db->sql_escape($game_info['game_name']) . "')";
    	if( !$result = $db->sql_query($sql) )
    	{
    		message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
      }
    }
  }

  $arcade->clear_cache('tournaments');

}
if ($mode == 'delete')
{
  if (!isset($HTTP_POST_VARS['delete_tour']) || $tour_id <= 0)
  {
    message_die(GENERAL_MESSAGE, $lang['Not_Authorised']);
  }
  $sql = "DELETE FROM " . iNA_TOUR . "
    WHERE tour_id = ". $tour_id;
	if( !$result = $db->sql_query($sql) )
	{
		message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
	}
  $sql = "DELETE FROM " . iNA_TOUR_DATA . "
    WHERE tour_id = ". $tour_id;
	if( !$result = $db->sql_query($sql) )
	{
		message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
	}
  $sql = "DELETE FROM " . iNA_TOUR_PLAY . "
    WHERE tour_id = ". $tour_id;
	if( !$result = $db->sql_query($sql) )
	{
		message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
	}
  $sql = "DELETE FROM " . iNA_TOUR_INVITE . "
    WHERE tour_id = ". $tour_id;
	if( !$result = $db->sql_query($sql) )
	{
		message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
	}
//
//  Force update of cache files
//
  $arcade->clear_cache('tournaments');

	$message = $lang['admin_tournament_deleted'];
	$message .= sprintf($lang['admin_return_arcade'], "<a href=\"" . append_sid($file) . "\">", "</a>") . "<br /><br />" . sprintf($lang['Click_return_admin_index'], "<a href=\"" . append_sid("index.$phpEx?pane=right") . "\">", "</a>");
	message_die(GENERAL_MESSAGE, $message);

}
else if ($mode == 'add_tour' || $mode == 'edit')
{
  $template->set_filenames(array('body' => 'admin/arcade_tournament_add.tpl'));
  if($tour_id > 0)
  {
		$sql = "SELECT * FROM " . iNA_TOUR . "
      WHERE tour_id = " . $tour_id;  
  	if( !$result = $db->sql_query($sql) )
  	{
  		message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
  	}
    $template->assign_block_vars('add', array(
        'ADD_GAMES' => '<input type="Submit" name="add_games" value="'.$lang['admin_add_games'].'" class="mainoption" />'
      ));
	$tour_info = $db->sql_fetchrow($result);
	if (!$tour_info)
	{
		message_die(GENERAL_MESSAGE, $lang['Not_Authorised']);
	}
  }
  $tour_name = $tour_info['tour_name'];
  $tour_desc = $tour_info['tour_desc'];
  $tour_players = intval($tour_info['tour_max_players']) < 2 || intval($tour_info['tour_max_players']) > $arcade->arcade_config['games_tournament_players'] ? 2 : intval($tour_info['tour_max_players']);
  $tour_turns = intval($tour_info['tour_player_turns']) < 1 ? 1 : intval($tour_info['tour_player_turns']);
  $block_plays = $tour_info['block_plays'];
  $tour_active = $tour_info['tour_active'];
  $total_tour_players = $lang['Min'] . ' <b>2</b> - ' . $lang['Max'] . ' <b>' . $arcade->arcade_config['games_tournament_players'] . '</b>';
  $select_active = '<select name="tour_active">';
  for($i = 0; $i < 4;$i++)
  {
  	$selected = ( $tour_info['tour_active'] == $i ) ? ' selected="selected"' : '';
    $select_active .= '<option value="' . $i . '"' . $selected . '>' . $lang['tour_options'][$i] . '</option>';
  }
  $select_active .= '</select>';
}
else if ($mode == 'add_games')
{
  if($tour_id <= 0)
  {
    message_die(GENERAL_MESSAGE, $lang['Not_Authorised']);
  }
  if($tour_id > 0)
  {
		$sql = "SELECT * FROM " . iNA_TOUR . "
      WHERE tour_id = " . $tour_id;  
  	if( !$result = $db->sql_query($sql) )
  	{
  		message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
  	}
    $template->assign_block_vars('add', array(
        'ADD_GAMES' => '<input type="Submit" name="add_games" value="'.$lang['admin_add_games'].'" class="mainoption" />'
      ));
  }
  $tour_info = $db->sql_fetchrow($result);
  if (!$tour_info)
  {
    message_die(GENERAL_MESSAGE, $lang['Not_Authorised']);
  }
  $sql = "SELECT * FROM " . iNA_GAMES . "
    WHERE game_avail = 1
      ORDER by game_id " . $arcade->arcade_config['default_sort_order'];
 	if( !$result = $db->sql_query($sql) )
 	{
 		message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
 	}
  $game_rows = $db->sql_fetchrowset($result);
  if(is_array($game_rows))
  {
    for($i = 0;$i < (count($game_rows)); $i++)
    {
      $cat_row = array('cat_name' => $lang['None']);
      if($game_rows[$i]['cat_id'] > 0)
      {
        $sql = "SELECT * from " . iNA_CAT . "
          WHERE cat_id = " . $game_rows[$i]['cat_id'];
       	if( !$result = $db->sql_query($sql) )
       	{
       		message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
       	}
        $cat_row = $db->sql_fetchrow($result);
      }
      
      $template->assign_block_vars('game', array(
  			'ROW_CLASS' => ( !($i % 2) ) ? 'row1' : 'row2',
  			
			'ID' => (int) $game_rows[$i]['game_id'],
        'NAME' => htmlspecialchars($game_rows[$i]['game_name'], ENT_QUOTES, 'UTF-8'),
        'DESC' => htmlspecialchars($game_rows[$i]['game_desc'], ENT_QUOTES, 'UTF-8'),
        'CAT_ID' => htmlspecialchars(($game_rows[$i]['cat_id'] > 0) ? $cat_row['cat_name'] : $lang['None'], ENT_QUOTES, 'UTF-8'),
		'SELECT' => '<input type="checkbox" name="game_id[]" value="'.(int) $game_rows[$i]['game_id'].'">'
		,'IMAGE' => htmlspecialchars(ina_find_image($game_rows[$i]['game_path'], $game_rows[$i]['game_name'], $game_rows[$i]['image_path'], './../'), ENT_QUOTES, 'UTF-8')
		,'IMAGE_WIDTH' => (int) $arcade->arcade_config['games_image_width']
		,'IMAGE_HEIGHT' => (int) $arcade->arcade_config['games_image_height']
        
          ));
    }
  }

  $template->set_filenames(array('body' => 'admin/arcade_tour_add_games.tpl'));

}
else if ($mode == '')
{

//		$sql = "SELECT * FROM " . iNA_TOUR . "
//			ORDER BY tour_id";
//		if( !$result = $db->sql_query($sql) )
//		{
//			message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
//		}
		
		
  $template->set_filenames(array('body' => 'admin/arcade_tournament_body.tpl'));
}

$sql = "SELECT * FROM " . iNA_TOUR . "
  ORDER BY tour_active ASC, tour_id DESC";
if( !$result = $db->sql_query($sql) )
{
	message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
}
$tours = $db->sql_fetchrowset($result);

$total_tournaments = count($tours);
if($total_tournaments == 0)
{
  $template->assign_block_vars('tournament_none', array());
}
else for($i = 0; $i < $total_tournaments; $i++)
{
  $sql = "SELECT count(game_name) as total FROM " . iNA_TOUR_DATA . "
    WHERE tour_id = " . $tours[$i]['tour_id'];
  if( !$result = $db->sql_query($sql) )
  {
  	message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
  }
  $tour_data_total = $db->sql_fetchrow($result);
  $total_games = $tour_data_total['total']; 
  $sql = "SELECT count(user_id) as total FROM " . iNA_TOUR_PLAY . "
    WHERE tour_id = " . $tours[$i]['tour_id'];
  if( !$result = $db->sql_query($sql) )
  {
  	message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
  }
  $tour_play_total = $db->sql_fetchrow($result);
  $total_players = $tour_play_total['total']; 
  switch($tours[$i]['tour_active'])
  {
    case 1:
      $tour_mode = $lang['inactive'];
      break;

    case 2:
      $tour_mode = $lang['active'];
      break;
    
    case 3:
      $tour_mode = $lang['finished'];
      break;
      
    default:
      $tour_mode = $lang['waiting'];
      break;
  }
    
  $template->assign_block_vars('tour', array(
        'ROW_CLASS' => ( !($i % 2) ) ? 'row1' : 'row2',
        'NAME' => htmlspecialchars($tours[$i]['tour_name'], ENT_QUOTES, 'UTF-8'),
        'DESC' => htmlspecialchars($tours[$i]['tour_desc'], ENT_QUOTES, 'UTF-8'),

        'TOTAL_GAMES' => ($tours[$i]['tour_active'] == 3) ? $lang['finished'] : sprintf($lang['tournament_games'], $total_games),
        'TOTAL_PLAYERS' => ($tours[$i]['tour_active'] == 3) ? '' : '&nbsp;-&nbsp;' . sprintf($lang['tournament_players'], $total_players),
        'MODE' => $tour_mode,
        
        'EDIT' => append_sid("$file?mode=edit&amp;id=".(int) $tours[$i]['tour_id']),
        'DELETE_ID' => (int) $tours[$i]['tour_id'],

      ));
}

$sql = "SELECT * FROM " . iNA_CAT;
if( !$result = $db->sql_query($sql) )
{
	message_die(GENERAL_ERROR, $lang['no_cat_data'], "", __LINE__, __FILE__, $sql);
}
$cat_rows = $db->sql_fetchrowset($result);
if(is_array($cat_rows))
{
  $select_cat = '<select name="category">';
  for($i = 0; $i < count($cat_rows); $i++)
  {
  	$selected = ( $cat_rows[$i]['cat_id'] == -1 ) ? ' selected="selected"' : '';
	$select_cat .= '<option value="' . (int) $cat_rows[$i]['cat_id'] . '"' . $selected . '>' . htmlspecialchars($cat_rows[$i]['cat_name'], ENT_QUOTES, 'UTF-8') . '</option>';
  }
  $select_cat.= '</select>';
}


$select_end = '<select name="tour_end">';
$select_end .= '';
$select_end .= '</select>';

$template->assign_vars(array(
		'VERSION' => $arcade->version,

    'TOURNAMENTS' => $lang['tournaments'],
    'CATEGORY' => $lang['admin_cat'],
    'ADD_TOURNAMENT' => $lang['add_tournament'],
    'TOURNAMENT_SETTINGS' => $lang['tournament_settings'],
    'L_NAME' => $lang['tournament_name'],
    'L_NAME_INFO' => $lang['tournament_name_info'],
    'L_DESC' => $lang['tournament_desc'],
    'L_DESC_INFO' => $lang['tournament_desc_info'],
    'L_PLAYERS' => $lang['tournament_max_player'],
    'L_PLAYERS_INFO' => $lang['tournament_max_player_info'],
    'L_TURNS' => $lang['tournament_turns'],
    'L_TURNS_INFO' => $lang['tournament_turns_info'],
    'L_BLOCK' => $lang['tournament_block'],
    'L_BLOCK_INFO' => $lang['tournament_block_info'],
    'L_ACTIVE' => $lang['tournament_active'],
    'L_ACTIVE_INFO' => $lang['tournament_active_info'],
    'L_END' => $lang['tournament_end'],
    'L_END_INFO' => $lang['tournament_end_info'],
 		'L_TOUR_HEADER' => $lang['admin_tournament_header'],
		'L_TOUR_INFO' => $lang['admin_tournament_info'],
		'L_TOURNAMENT_TXT' => $lang['admin_tournament_txt'],
		'L_TOURNAMENT_TXT_INFO' => $lang['admin_tournament_txt_info'],
		'L_TOURNAMENT_OPTIONS' => $lang['tournament_options'],
		'L_TOURNAMENT_MAX' => $lang['tournament_max_number'],
		'L_TOURNAMENT_GAMES' => $lang['tournament_max_games'],
		'L_TOURNAMENT_PLAYERS' => $lang['tournament_max_players'],
		'L_USER_START' => $lang['tournament_user_start'],
		'L_GAME_MENU' => $lang['admin_tournament_add_games'],
		'L_SELECT_GAMES' => sprintf($lang['admin_tournament_select_games'], htmlspecialchars($tour_info['tour_name'], ENT_QUOTES, 'UTF-8')),

    'L_MAX_TOUR' => $lang['tournament_max_number'],
    'L_MAX_GAMES' => $lang['tournament_max_games'],
    'L_MAX_PLAYERS' => $lang['tournament_max_players'],
		'L_SUBMIT' => $lang['Submit'],
		'L_ADD' => $lang['Add'],
		'L_NONE' => $lang['None'],
		'L_YES' => $lang['Yes'],
		'L_NO' => $lang['No'],
		'L_CONFIRM_DELETE' => $lang['Confirm_delete_tournament'],

    'NAME' => htmlspecialchars($tour_name, ENT_QUOTES, 'UTF-8'),
    'DESC' => htmlspecialchars($tour_desc, ENT_QUOTES, 'UTF-8'),
    'PLAYERS' => $tour_players,
    'TURNS' => $tour_turns,
    'TOUR_ACTIVE' => $tour_active,
    'TOUR_MODE' => $tour_mode,
    'TOTAL_PLAYERS' => $total_tour_players,
    'S_BLOCK_YES' => ($block_plays == 1) ? 'checked="checked"' : '',
    'S_BLOCK_NO' => ($block_plays == 0) ? 'checked="checked"' : '',
    'S_SELECT_ACTIVE' => $select_active,
    'S_SELECT_END' => $select_end,
    
    'CAT_SELECT' => $select_cat,

   	'IMAGE_DEL' => '<img src="./../' . $images['icon_delpost'] . '" alt="' . $lang['Delete'] . '" title="' . $lang['Delete'] . '" border="0" />',
    'IMAGE_EDIT' => '<img src="./../' . $images['icon_edit'] . '" alt="' . $lang['Edit'] . '" title="' . $lang['Edit'] . '" border="0" />',

		'S_MAX_TOUR' => (int) $arcade->arcade_config['games_tournament_max'],
		'S_MAX_GAMES' => (int) $arcade->arcade_config['games_tournament_games'],
		'S_MAX_PLAYERS' => (int) $arcade->arcade_config['games_tournament_players'],
		
		'S_TOURNAMENT_MAX' => $arcade->arcade_config['games_tournament_max'],
		'S_TOURNAMENT_GAMES' => intval($arcade->arcade_config['games_tournament_games']),
		'S_TOURNAMENT_PLAYERS' => intval($arcade->arcade_config['games_tournament_players']),
		'S_USER_START_YES' => ($arcade->arcade_config['games_tournament_user'] ? 'checked=checked' : ''),
		'S_USER_START_NO' => ($arcade->arcade_config['games_tournament_user'] ? '' : 'checked=checked'),
		
		'S_HIDDEN_ADD' => '<input type="hidden" name="id" value="'.(int) $tour_id.'">',
		'S_FORM_TOKEN' => '<input type="hidden" name="sid" value="' . htmlspecialchars($userdata['session_id'], ENT_QUOTES, 'UTF-8') . '" />',
		'S_ACTION' => append_sid($file),
		'S_CONFIG_ACTION' => append_sid($file)
      ));

//
// Generate the page
//
$template->pparse('body');
include('page_footer_admin.' . $phpEx);

?>
