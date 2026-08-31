<?php
/*************************************************************************** 
 * 
 *                             arcade_modcp.php 
 *                             ---------------- 
 *   begin                : Tuesday, June 21, 2005 
 *   copyright            : (c)2002/2006 www.phpbb-amod.co.uk 
 *   email                : defenders_realm@yahoo.com 
 * 
 *   $Id: arcade_modcp.php, v3.0.0 2005/06/21 12:59:59 dEfEndEr Exp $ 
 * 
 *************************************************************************** 
 * 
 *   This program is free software; you can redistribute it and/or modify 
 *   it under the terms of the GNU General Public License as published by 
 *   the Free Software Foundation; either version 2 of the License, or 
 *   (at your option) any later version. 
 * 
 ***************************************************************************/

define('IN_PHPBB', true); 
$phpbb_root_path = './'; 
include($phpbb_root_path . 'extension.inc'); 
include($phpbb_root_path . 'common.'.$phpEx); 
include_once($phpbb_root_path . 'includes/constants_arcade.'.$phpEx);

// Start session management 
$userdata = session_pagestart($user_ip, PAGE_ARCADE_MOD); 
init_userprefs($userdata); 
include_once($phpbb_root_path . 'includes/functions_arcade.'.$phpEx);
// End session management 

function arcade_modcp_html($value)
{
	return htmlspecialchars(is_scalar($value) ? (string) $value : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function arcade_modcp_ip($value, $label)
{
	$ip = trim(is_scalar($value) ? (string) $value : '');
	if (filter_var($ip, FILTER_VALIDATE_IP) === false)
	{
		return '&ndash;';
	}

	return '<span class="gensmall" title="' . arcade_modcp_html($label) . '">' . arcade_modcp_html($ip) . '</span>';
}

//
//  Clear all main vars  
//
unset($cat_id, $mode, $mod_id);

$mode = $arcade->pass_var('mode', '');
$cat_id = 0;
$mod_id = 0;
$catagory_name = $lang['all_games'];
$hidden_options = '';

if ($mode != '')
{
  $cat_id = (int) $arcade->pass_var('cat_id', 0);
  
	if ($cat_id > 0)
	{
		$sql = "SELECT * FROM " . iNA_CAT . "
			WHERE cat_id = " . (int) $cat_id;
		if(!$result = $db->sql_query($sql))
		{
			message_die(GENERAL_ERROR, $lang['no_game_data'], '', __LINE__, __FILE__, $sql);
		}
		$cat_info = $db->sql_fetchrow($result);
		if (!$cat_info)
		{
			message_die(GENERAL_MESSAGE, $lang['Not_Moderator'], $lang['Not_Authorised']);
		}
		$catagory_name = $cat_info['cat_name'];
		$mod_id = (int) $cat_info['mod_id'];
	}
}
else
{
	message_die(GENERAL_MESSAGE, $lang['Not_Moderator'], $lang['Not_Authorised']);
}
//
//  Get some basics..!
//
$action = $arcade->pass_var('action', '');

if (!in_array($action, array('', 'scores', 'at_scores', 'delete_score', 'delete_at_score'), true))
{
	$action = '';
}

if(!empty($HTTP_POST_VARS['delete_x']))
{
  $action = "delete_score";
}

$game_id    = (int) $arcade->pass_var('game_id', 0);
$score      = (float) $arcade->pass_var('score', 0);
$player_id  = (int) $arcade->pass_var('player_id', 0);
//
//	Moderators Menu
//
if ($mode == 'mod')
{
	$url = ' &raquo; <a href="' . append_sid("activity.$phpEx") . '" class="nav">' . arcade_modcp_html($lang['games_catagories']) . '</a> &raquo; <a href="' . append_sid("activity.$phpEx?mode=cat&amp;cat_id=" . (int) $cat_id) . '" class="nav">' . arcade_modcp_html($catagory_name) . '</a>';

	if(($mod_id != $userdata['user_id'] || $mod_id < 1) && $userdata['user_level'] != ADMIN)
	{
		message_die(GENERAL_MESSAGE, $lang['Not_Moderator'], $lang['Not_Authorised']);
	}

  if($action == 'delete_score' || $action == 'delete_at_score')
  {
    if( !isset($HTTP_POST_VARS['confirm']) )
    {
//
// Was CANCEL Selected ?
//
  	 if( isset($HTTP_POST_VARS['cancel']) )
	   {
		    redirect(append_sid("arcade_modcp.$phpEx?mode=mod&cat_id=$cat_id"));
		    exit;
	   }
//
// Start output of page
//
	  $page_title = $lang['arcade_comment_delete'];
	  include($phpbb_root_path . 'includes/page_header.'.$phpEx);

    $template->set_filenames(array(
		  'body' => 'confirm_body.tpl')
  	);

  	$template->assign_vars(array(
	   	'MESSAGE_TITLE' => $lang['Confirm'],

		'MESSAGE_TEXT' => sprintf($lang['arcade_score_sure'], $score, $player_id . ' (' . htmlspecialchars($arcade->get_username($player_id), ENT_QUOTES, 'UTF-8') . ')'),

  		'L_NO' => $lang['No'],
	   	'L_YES' => $lang['Yes'],

		'S_CONFIRM_ACTION' => append_sid("arcade_modcp.$phpEx?mode=mod&amp;cat_id=$cat_id&amp;action=$action&amp;game_id=$game_id&amp;score=$score&amp;player_id=$player_id"),
		'S_HIDDEN_FIELDS' => '<input type="hidden" name="sid" value="' . htmlspecialchars($userdata['session_id'], ENT_QUOTES, 'UTF-8') . '" />',
	    ));

	//
	// Generate the page
	//
  	$template->pparse('body');

  	include($phpbb_root_path . 'includes/page_tail.'.$phpEx);
    }
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['sid']) || !is_scalar($_POST['sid']) ||
      !hash_equals((string) $userdata['session_id'], (string) $_POST['sid']))
    {
      message_die(GENERAL_MESSAGE, $lang['Not_Authorised']);
    }

    $sql = "SELECT game_name FROM " . iNA_GAMES . "
      WHERE game_id = " . (int) $game_id . " AND cat_id = " . (int) $cat_id;
    if (!($game_result = $db->sql_query($sql)) || !($game_row = $db->sql_fetchrow($game_result)))
    {
      message_die(GENERAL_MESSAGE, $lang['Not_Authorised']);
    }

    $table = ($action === 'delete_score') ? iNA_SCORES : iNA_AT_SCORES;
    $sql = "DELETE FROM " . $table . "
      WHERE game_name = '" . $db->sql_escape($game_row['game_name']) . "'
      AND score = " . (float) $score . "
      AND player_id = " . (int) $player_id . "
      LIMIT 1";
    if (!$db->sql_query($sql))
    {
      message_die(GENERAL_ERROR, $lang['no_score_data'], '', __LINE__, __FILE__, $sql);
    }

    $return_action = ($action === 'delete_score') ? 'scores' : 'at_scores';
    redirect(append_sid("arcade_modcp.$phpEx?mode=mod&cat_id=$cat_id&action=$return_action&game_id=$game_id", true));
    exit;
//
//  Delete Score Selected.
//  
  }
  else if($action == '' || $game_id == 0)
  {
//
// Moderator has entered the menu, show the games available and information about them.
//
    $template->assign_block_vars("game_menu", array());

    $sql = "SELECT * FROM " . iNA_GAMES . " WHERE cat_id = " . (int) $cat_id . " ORDER BY game_name ASC";
  	if(!$result = $db->sql_query($sql))
    {
		  message_die(GENERAL_ERROR, $lang['no_game_data'], '', __LINE__, __FILE__, $sql);
  	}
    while (	$game_info = $db->sql_fetchrow($result))
    {
			$img = '';
			$image_src = ina_find_image($game_info['game_path'], $game_info['game_name'], $game_info['image_path'], $phpbb_root_path);
			if ($image_src !== false)
			{
				$img = '<img src="' . arcade_modcp_html($image_src) . '" width="' . (int) $board_config['games_image_width'] . '" height="' . (int) $board_config['games_image_height'] . '" alt="" />';
			}
			$template->assign_block_vars("game", array(

				'GAME_ID' => (int) $game_info['game_id'],
				'NAME' => arcade_modcp_html($game_info['game_name']),
        'FLASH' => $game_info['game_flash'] ? $lang['ON'] : $lang['OFF'],
				'DESC' => arcade_modcp_html($game_info['game_desc']),
				'PATH' => arcade_modcp_html($game_info['game_path']),
				'IMAGE' => $img,
				'IMAGE_PATH' => arcade_modcp_html($game_info['image_path'] ? $game_info['image_path'] : $lang['games_image_default']),
				'INST' => arcade_modcp_html($game_info['instructions'] ? $game_info['instructions'] : $lang['No_Instructions']),
				'WIDTH' => (int) $game_info['win_width'],
				'HEIGHT' => (int) $game_info['win_height'],
				'AUTO' => ($board_config['games_auto_size'] && !$game_info['game_autosize']) ? $lang['ON'] : $lang['OFF']) );
    }
  }
  else if($action == 'scores')
  {
//
//  Show the Scores Edit Window
//
//echo ('S '.$action . $game_id) ;
    $template->assign_block_vars("scores_edit_menu", array());

    $sql = "SELECT reverse_list FROM " . iNA_GAMES . "
      WHERE game_id = " . (int) $game_id . " AND cat_id = " . (int) $cat_id;
    if (!($game_result = $db->sql_query($sql)) || !($selected_game = $db->sql_fetchrow($game_result)))
    {
      message_die(GENERAL_MESSAGE, $lang['Not_Authorised']);
    }
    $order = $selected_game['reverse_list'] ? 'ASC' : 'DESC';

    $sql = "SELECT s.player_id, s.player_ip, s.score, s.date, s.time_taken, u.username FROM ". iNA_GAMES ." AS g
      INNER JOIN ". iNA_SCORES ." AS s ON g.game_name = s.game_name
      LEFT JOIN " . USERS_TABLE . " as u ON s.player_id = u.user_id
        WHERE g.game_id = " . (int) $game_id . " AND g.cat_id = " . (int) $cat_id . "
        ORDER by s.score " .$order;
	   if(!$result = $db->sql_query($sql))
	   {
	   	 message_die(GENERAL_ERROR, $lang['no_game_data'], '', __LINE__, __FILE__, $sql);
	   }
     while (	$score_info = $db->sql_fetchrow($result))
     {
			$delete_url = append_sid("arcade_modcp.$phpEx?mode=mod&amp;cat_id=" . (int) $cat_id . "&amp;action=delete_score&amp;game_id=" . (int) $game_id . "&amp;player_id=" . (int) $score_info['player_id']) . '&amp;score=' . rawurlencode((string) $score_info['score']);
			$template->assign_block_vars("highscores", array(
				'PLAYER' => arcade_modcp_html($score_info['username'] !== null ? $score_info['username'] : $lang['Guest']),
				'SCORE' => arcade_modcp_html($arcade->convert_score($score_info['score'])),
				'DATE' => arcade_modcp_html(create_date($board_config['default_dateformat'], $score_info['date'], $board_config['board_timezone'])),
				'TIME' => arcade_modcp_html($score_info['time_taken'] ? $arcade->convert_time($score_info['time_taken']) : ''),
				'DELETE_IMG' => '<a href="' . $delete_url . '"><img src="' . arcade_modcp_html($images['icon_delpost']) . '" alt="' . arcade_modcp_html($lang['Delete_post']) . '" title="' . arcade_modcp_html($lang['Delete_post']) . '" /></a>',
				'IP_IMG' => arcade_modcp_ip($score_info['player_ip'], $lang['View_IP'])
     ));     
     }
		$hidden_options = '<input type="hidden" name="mode" value="mod" /><input type="hidden" name="cat_id" value="' . (int) $cat_id . '" /><input type="hidden" name="game_id" value="' . (int) $game_id . '" />';
  }
  else if($action == 'at_scores')
  {
//
//  Show the Scores Edit Window
//
//echo ('S '.$action . $game_id) ;
    $template->assign_block_vars("scores_edit_menu", array());

    $sql = "SELECT reverse_list FROM " . iNA_GAMES . "
      WHERE game_id = " . (int) $game_id . " AND cat_id = " . (int) $cat_id;
    if (!($game_result = $db->sql_query($sql)) || !($selected_game = $db->sql_fetchrow($game_result)))
    {
      message_die(GENERAL_MESSAGE, $lang['Not_Authorised']);
    }
    $order = $selected_game['reverse_list'] ? 'ASC' : 'DESC';

    $sql = "SELECT s.player_id, s.player_ip, s.score, s.date, s.time_taken, u.username FROM ". iNA_GAMES ." AS g
      INNER JOIN ". iNA_AT_SCORES ." AS s ON g.game_name = s.game_name
      LEFT JOIN " . USERS_TABLE . " as u ON s.player_id = u.user_id
        WHERE g.game_id = " . (int) $game_id . " AND g.cat_id = " . (int) $cat_id . "
        ORDER by s.score " .$order;
	   if(!$result = $db->sql_query($sql))
	   {
	   	 message_die(GENERAL_ERROR, $lang['no_game_data'], '', __LINE__, __FILE__, $sql);
	   }
     while (	$score_info = $db->sql_fetchrow($result))
     {
			$delete_url = append_sid("arcade_modcp.$phpEx?mode=mod&amp;cat_id=" . (int) $cat_id . "&amp;action=delete_at_score&amp;game_id=" . (int) $game_id . "&amp;player_id=" . (int) $score_info['player_id']) . '&amp;score=' . rawurlencode((string) $score_info['score']);
			$template->assign_block_vars("highscores", array(
				'PLAYER' => arcade_modcp_html($score_info['username'] !== null ? $score_info['username'] : $lang['Guest']),
				'SCORE' => arcade_modcp_html($arcade->convert_score($score_info['score'])),
				'DATE' => arcade_modcp_html(create_date($board_config['default_dateformat'], $score_info['date'], $board_config['board_timezone'])),
				'TIME' => arcade_modcp_html($score_info['time_taken'] ? $arcade->convert_time($score_info['time_taken']) : ''),
				'DELETE_IMG' => '<a href="' . $delete_url . '"><img src="' . arcade_modcp_html($images['icon_delpost']) . '" alt="' . arcade_modcp_html($lang['Delete_post']) . '" title="' . arcade_modcp_html($lang['Delete_post']) . '" /></a>',
				'IP_IMG' => arcade_modcp_ip($score_info['player_ip'], $lang['View_IP'])
     ));     
     }
		$hidden_options = '<input type="hidden" name="mode" value="mod" /><input type="hidden" name="cat_id" value="' . (int) $cat_id . '" /><input type="hidden" name="game_id" value="' . (int) $game_id . '" />';
  }

$moderators_buttons = '<label><input type="radio" name="action" value="scores" checked="checked" /> ' . arcade_modcp_html($lang['arcade_mod_current_scores']) . '</label> &nbsp; '
	. '<label><input type="radio" name="action" value="at_scores" /> ' . arcade_modcp_html($lang['arcade_mod_all_time_scores']) . '</label>';


	$template->set_filenames(array('body' => 'arcade_mod_body.tpl') );

	$template->assign_vars(array(
    'MOD_MENU' => $lang['arcade_mod_menu'],
    'MOD_BUTTONS' => $moderators_buttons,

    'L_SUBMIT' => $lang['Submit'],
		'L_ARCADE_MOD_IMAGE' => $lang['arcade_mod_image'],
		'L_ARCADE_MOD_FILENAME' => $lang['arcade_mod_filename'],
		'L_ARCADE_MOD_DESCRIPTION' => $lang['arcade_mod_description'],
		'L_ARCADE_MOD_PATH' => $lang['arcade_mod_path'],
		'L_ARCADE_MOD_STATS' => $lang['arcade_mod_stats'],
		'L_ARCADE_MOD_PLAYER' => $lang['arcade_mod_player'],
		'L_ARCADE_MOD_DATE' => $lang['arcade_mod_date'],
		'L_ARCADE_MOD_TIME' => $lang['arcade_mod_time'],
		'L_ARCADE_MOD_SCORE' => $lang['arcade_mod_score'],
		'L_ARCADE_MOD_ACTIONS' => $lang['arcade_mod_actions'],
		'L_ARCADE_MOD_FLASH' => $lang['arcade_mod_flash'],
		'L_ARCADE_MOD_AUTOSIZE' => $lang['arcade_mod_autosize'],
		'L_ARCADE_MOD_SCORE_EDITOR' => $action === 'at_scores' ? $lang['arcade_mod_all_time_scores'] : $lang['arcade_mod_current_scores'],

    'S_ACTION' => append_sid("arcade_modcp.$phpEx?mode=mod&cat_id=$cat_id"),

		'ARCADE_MOD' => sprintf($lang['activitiy_mod_info'], $arcade->version()),
	  'S_HIDDEN_OPTIONS' => $hidden_options,
  	'U_CAT' => $url
	));
	
}
else
{
	message_die(GENERAL_MESSAGE, $lang['Not_Moderator'], $lang['Not_Authorised']);
}

$page_title = arcade_modcp_html($lang['arcade_mod_menu'] . ' - ' . $catagory_name);
// Generate page
include($phpbb_root_path . 'includes/page_header.'.$phpEx);
$template->pparse('body');
include($phpbb_root_path . 'includes/page_tail.'.$phpEx);

?>
