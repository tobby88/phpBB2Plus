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
$filename = basename(__FILE__);
include($phpbb_root_path . 'extension.inc'); 
include($phpbb_root_path . 'common.'.$phpEx); 
include_once($phpbb_root_path . 'includes/constants_arcade.'.$phpEx);

// Start session management 
$userdata = session_pagestart($user_ip, PAGE_ARCADE_MOD); 
init_userprefs($userdata); 
include_once($phpbb_root_path . 'includes/functions_arcade.'.$phpEx);
$arcade_version = $arcade->version();
// End session management 

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
  $cat_id = $arcade->pass_var('cat_id', 0);
  
	if ($cat_id > 0)
	{
		$sql = "SELECT * FROM " . iNA_CAT . "
			WHERE cat_id = " . $cat_id;
		if(!$result = $db->sql_query($sql))
		{
			message_die(GENERAL_ERROR, $lang['no_game_data'], '', __LINE__, __FILE__, $sql);
		}
		$cat_info = $db->sql_fetchrow($result);
		$catagory_name = $cat_info['cat_name'];
		$mod_id = intval($cat_info['mod_id']);
		$page_title = $cat_info['cat_name'] . ' - ' . $page_title; 
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

if(!empty($HTTP_POST_VARS['delete_x']))
{
  $action = "delete_score";
}

$game_id    = $arcade->pass_var('game_id', 0);
$score      = $arcade->pass_var('score', 0);
$player_id  = $arcade->pass_var('player_id', 0);
//
//	Moderators Menu
//
if ($mode == 'mod')
{
	$url = ' -> <a href="' . append_sid("activity.$phpEx") . '" class="nav">' . $lang['games_catagories'] . '</a> &raquo; <a href="activity.'.$phpEx.'?mode=cat&amp;cat_id='.$cat_id.'">' . $catagory_name .'</a>';

	if(($mod_id != $userdata['user_id'] || $mod_id < 1) && $userdata['user_level'] != ADMIN)
	{
		message_die(GENERAL_MESSAGE, $lang['Not_Moderator'], $lang['Not_Authorised']);
	}

  if($action == 'user')
  {
  
  }
  else if($action == 'delete_score' || $action == 'delete_at_score')
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

    $sql = "SELECT * from " . iNA_GAMES . " WHERE cat_id = " . $cat_id;
  	if(!$result = $db->sql_query($sql))
    {
		  message_die(GENERAL_ERROR, $lang['no_game_data'], '', __LINE__, __FILE__, $sql);
  	}
  	$i=0;
    while (	$game_info = $db->sql_fetchrow($result))
    {
      if(strlen($game_info['image_path']) < 3)
      {
        $img = '<img src="'.$game_info['game_path'].$game_info['game_name'].'.gif">';
      }
			$template->assign_block_vars("game", array(

        'GAME_ID' => $game_info['game_id'],
				'NAME' => $game_info['game_name'],
        'FLASH' => $game_info['game_flash'] ? $lang['ON'] : $lang['OFF'],
				'DESC' => $game_info['game_desc'] . '<br />' . $game_info['instructions'],
				'PATH' => $game_info['game_path'],
				'IMAGE' => $img,
  			'IMAGE_PATH' => $game_info['image_path'] ? $game_info['image_path'] : $lang['games_image_default'],
				'IMAGE_WIDTH' => $board_config['games_image_width'],
				'IMAGE_HEIGHT' => $board_config['games_image_height'],
				'CHARGE' => $game_charge,
				'INST' => $game_info['instructions'] ? $game_info['instructions'] : $lang['No_Instructions'],
				'WIDTH' => $game_info['win_width'],
				'HEIGHT' => $game_info['win_height'],
				'LINK' => append_sid("$filename?mode=game&amp;id=$game_id"),
					'AUTO' => ($board_config['games_auto_size'] && !$game_info['game_autosize']) ? $lang['ON'] : $lang['OFF'],
				'ALT_STATS' => $lang['game_stats'],
				'DASH' => $dash,
				'BONUS' => $bonus_info,
				'LIST' => $highscore_link,
				'AT_LIST' => $at_highscore_link) );
			$i++;
    }
  }
  else if($action == 'games')
  {
//
//  Show game Edit Section here
//
    $sql = "SELECT * FROM ". iNA_GAMES ."
        WHERE game_id = " . $game_id;
	   if(!$result = $db->sql_query($sql))
	   {
	   	 message_die(GENERAL_ERROR, $lang['no_game_data'], '', __LINE__, __FILE__, $sql);
  	 }
     $thisgame = $db->sql_fetchrow($result);
     
    $template->assign_block_vars("game_edit_menu", array(
      'EDITING_GAME' => 'Editing Game',
      
      'NAME' => $thisgame['game_name'],
      'PATH' => $thisgame['game_path'],
      'DESC' => $thisgame['game_desc']
      
      ));

     
     
     
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

    $sql = "SELECT g.*, s.*, u.username FROM ". iNA_GAMES ." AS g
      LEFT JOIN ". iNA_SCORES ." AS s ON g.game_name = s.game_name
      LEFT JOIN " . USERS_TABLE . " as u ON s.player_id = u.user_id
        WHERE g.game_id = " . (int) $game_id . " AND g.cat_id = " . (int) $cat_id . "
        ORDER by s.score " .$order;
	   if(!$result = $db->sql_query($sql))
	   {
	   	 message_die(GENERAL_ERROR, $lang['no_game_data'], '', __LINE__, __FILE__, $sql);
  	 }
     while (	$score_info = $db->sql_fetchrow($result))
     {
			$template->assign_block_vars("highscores", array(
        'PLAYER' => $score_info['username'],
        'SCORE' => $arcade->convert_score($score_info['score']),
        'DATE' => create_date($board_config['default_dateformat'], $score_info['date'], $board_config['board_timezone']),
        'TIME' => $score_info['time_taken'] ? $arcade->convert_time($score_info['time_taken']) : '',
 				'EDIT_IMG' => '<a href="'. append_sid("arcade_modcp.$phpEx?mode=mod&amp;cat_id=$cat_id&amp;action=edit_score&amp;id=". $score_info['player_id']) .'"><img src="' . $images['icon_edit'] . '" alt="' . $lang['Edit_delete_post'] . '" title="' . $lang['Edit_delete_post'] . '" border="0" /></a></a>',
				'DELETE_IMG' => '<a href="'. append_sid("arcade_modcp.$phpEx?mode=mod&amp;cat_id=$cat_id&amp;action=delete_score&amp;game_id=$game_id&amp;player_id=". (int) $score_info['player_id']) . '&amp;score=' . rawurlencode($score_info['score']) . '"><img src="' . $images['icon_delpost'] . '" alt="' . $lang['Delete_post'] . '" title="' . $lang['Delete_post'] . '" /></a>',
        'IP_IMG' => '<a href="http://network-tools.com/default.asp?host='.$score_info['player_ip'].'" target="_blank"><img src="' . $images['icon_ip'] . '" alt="' . $lang['View_IP'] . '" title="' . $lang['View_IP'] . '" border="0" /></a>'
     ));     
     }
     $hidden_options = '<input type="hidden" name="mode" value="mod"><input type="hidden" name="cat_id" value="'.$cat_id.'"><input type="hidden" name="game_id" value="'.$game_id.'">';
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

    $sql = "SELECT g.*, s.*, u.username FROM ". iNA_GAMES ." AS g
      LEFT JOIN ". iNA_AT_SCORES ." AS s ON g.game_name = s.game_name
      LEFT JOIN " . USERS_TABLE . " as u ON s.player_id = u.user_id
        WHERE g.game_id = " . (int) $game_id . " AND g.cat_id = " . (int) $cat_id . "
        ORDER by s.score " .$order;
	   if(!$result = $db->sql_query($sql))
	   {
	   	 message_die(GENERAL_ERROR, $lang['no_game_data'], '', __LINE__, __FILE__, $sql);
  	 }
     while (	$score_info = $db->sql_fetchrow($result))
     {
			$template->assign_block_vars("highscores", array(
        'PLAYER' => $score_info['username'],
        'SCORE' => $arcade->convert_score($score_info['score']),
        'DATE' => create_date($board_config['default_dateformat'], $score_info['date'], $board_config['board_timezone']),
        'TIME' => $score_info['time_taken'] ? $arcade->convert_time($score_info['time_taken']) : '',
 				'EDIT_IMG' => '<a href="'. append_sid("arcade_modcp.$phpEx?mode=mod&amp;cat_id=$cat_id&amp;action=edit_at_score&amp;id=". $score_info['player_id']) .'"><img src="' . $images['icon_edit'] . '" alt="' . $lang['Edit_delete_post'] . '" title="' . $lang['Edit_delete_post'] . '" border="0" /></a></a>',
				'DELETE_IMG' => '<a href="'. append_sid("arcade_modcp.$phpEx?mode=mod&amp;cat_id=$cat_id&amp;action=delete_at_score&amp;game_id=$game_id&amp;player_id=". (int) $score_info['player_id']) . '&amp;score=' . rawurlencode($score_info['score']) . '"><img src="' . $images['icon_delpost'] . '" alt="' . $lang['Delete_post'] . '" title="' . $lang['Delete_post'] . '" /></a>',
        'IP_IMG' => '<a href="http://network-tools.com/default.asp?host='.$score_info['player_ip'].'" target="_blank"><img src="' . $images['icon_ip'] . '" alt="' . $lang['View_IP'] . '" title="' . $lang['View_IP'] . '" border="0" /></a>'
     ));     
     }
     $hidden_options = '<input type="hidden" name="mode" value="mod"><input type="hidden" name="cat_id" value="'.$cat_id.'"><input type="hidden" name="game_id" value="'.$game_id.'">';
  }
  else if($action == 'comments')
  {
//
//  Show the Comments Edit Window
//
//echo ('C '.$action . $game_id) ;
    $template->assign_block_vars("comments_edit_menu", array());
  
  }
  


$moderators_buttons = '[ &nbsp; GAME &raquo; 
        <input type="radio" name="action" value="games"> &nbsp; &nbsp; - &nbsp; &nbsp; SCORES &raquo; 
        <input type="radio" name="action" value="scores"> &nbsp; &nbsp; - &nbsp; &nbsp; AT SCORES &raquo; 
        <input type="radio" name="action" value="at_scores"> &nbsp; &nbsp; - &nbsp; &nbsp; COMMENTS &raquo; 
        <input type="radio" name="action" value="comments"> &nbsp; &nbsp; - &nbsp; &nbsp; USER &raquo; 
        <input type="radio" name="action" value="user"> &nbsp;]';


	$template->set_filenames(array('body' => 'arcade_mod_body.tpl') );

	$template->assign_vars(array(
    'MOD_MENU' => $lang['arcade_mod_menu'],
    'MOD_BUTTONS' => $moderators_buttons,

    'L_SUBMIT' => $lang['Submit'],

    'S_ACTION' => append_sid("arcade_modcp.$phpEx?mode=mod&cat_id=$cat_id"),

    'SPACER' => $images['spacer'],
		'VERSION' => $arcade->version(),
		'ARCADE_MOD' => sprintf($lang['activitiy_mod_info'], $arcade->version()),
	  'S_HIDDEN_OPTIONS' => $hidden_options,
  	'U_CAT' => $url
	));
	
}
else
{
	message_die(GENERAL_MESSAGE, $lang['Not_Moderator'], $lang['Not_Authorised']);
}

$page_title = 'Arcade Moderators Menu';
// Generate page
include($phpbb_root_path . 'includes/page_header.'.$phpEx);
$template->pparse('body');
include($phpbb_root_path . 'includes/page_tail.'.$phpEx);

?>
