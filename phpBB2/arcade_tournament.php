<?php
/***************************************************************************
 *                             arcade_tournament.php
 *                            -----------------------
 *   begin                : Thuesday, May 11, 2006
 *   copyright            : (C) 2003-2006 dEfEndEr
 *   email                : defenders_realm@yahoo.com
 *   support              : http://www.phpbb-arcade.com
 *
 *   $Id: arcade__tournament.php, v2.1.3 2006/05/11 23:59:02 dEfEndEr Exp $
 *
 ***************************************************************************/

/***************************************************************************
 *
 *   This program is shareware; you can redistribute it under the terms of
 *   the License as published by the Arcade Support Site above.
 *
 *   You may evaluate this software for 30 days, after this period you should
 *   make a donation to help support the software via the support site above.
 *
 ***************************************************************************/

define('IN_PHPBB', true);
define('ARCADE_ADMIN', 1);
// Set the defaults.
$phpbb_root_path = './';
$filename = basename(__FILE__);
// required for people with footer stats...
include($phpbb_root_path . 'extension.inc');
// phpBB 3 routine to get true extension info
$phpEx    = substr(strrchr(__FILE__, '.'), 1);
// include standard files
include($phpbb_root_path . 'common.'.$phpEx);
include_once($phpbb_root_path . 'includes/constants_arcade.'.$phpEx);
//
// Start session management
//
$userdata = session_pagestart($user_ip, PAGE_ARCADE_TOUR);
init_userprefs($userdata);
include_once($phpbb_root_path . 'includes/functions_arcade.'.$phpEx);
$version = $arcade->version();
$page_title = $lang['Arcade'] . ' ' . $lang['tournaments']; 
//
// End session management
//-----------------------//
// Check User Logged in
//-----------------------//
if (!$userdata['session_logged_in'])
{
	redirect(append_sid("login.$phpEx?redirect=arcade_tournament.$phpEx"));
}
//
//  Initialize Arcade Config and Check System ONLINE.
//
if($arcade->arcade_config('games_offline') && ($userdata['user_level'] != ADMIN) && ($userdata['user_level'] != MOD))
{
	message_die(GENERAL_MESSAGE, $lang['games_are_offline'], $lang['Information']);
}
//  Get inputed data / set-up remaining defaults.
//
$mode       = $arcade->pass_var('mode', '');
$tour_id    = (int) $arcade->pass_var('id', 0);
$game_id    = (int) $arcade->pass_var('game_id', 0);
$join_tour  = $arcade->pass_var('join_tour', array());
$s_options  = '<input type="Submit" name="start" value="'. $lang['Start'] . '">&nbsp;&nbsp;<input type="Submit" name="join" value="'. $lang['Join'] . '">';
$GameData   = array("game_name" => '', "played" => 1, "score" => 0, "time_taken" => 0);
$in_tour = false;
$games_played_list = array();
$tour = array('tour_name' => '', 'tour_desc' => '', 'tour_max_players' => 2, 'tour_player_turns' => 1);
$url		    = '&nbsp;&raquo;&nbsp;<a href="activity.'.$phpEx.'" class="nav">' . $lang['games_catagories'] . '</a>&nbsp;&raquo;&nbsp;' . $lang['tournaments'];

function arcade_public_tournament_require_token($userdata, $lang)
{
  global $HTTP_POST_VARS;
  if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($HTTP_POST_VARS['sid']) || is_array($HTTP_POST_VARS['sid']) ||
    !hash_equals((string) $userdata['session_id'], stripslashes((string) $HTTP_POST_VARS['sid'])))
  {
    message_die(GENERAL_MESSAGE, $lang['Not_Authorised']);
  }
}

function arcade_public_tournament_game_token($tour_id, $game_id, $session_id)
{
  return hash_hmac('sha256', (int) $tour_id . ':' . (int) $game_id, (string) $session_id);
}
//
//  Set the template so we don't get any errors.
//
$template->set_filenames(array('body' => 'arcade_tour_body.tpl') );
//
//  Does the user want to join a tournament?
//
if(!empty($HTTP_POST_VARS['join']))
{
  arcade_public_tournament_require_token($userdata, $lang);
  if(is_array($join_tour))
  {
    $joining = '';
    for($i=0; $i < (count($join_tour)); $i++)
    {
      $tour_id = intval($join_tour[$i]);
      
      if ($tour_id <= 0)
      {
        continue;
      }
      $sql = "SELECT * FROM " . iNA_TOUR . "
        WHERE tour_id = ". $tour_id . "
          AND tour_active = 2";
      if( !$result = $db->sql_query($sql) )
      {
      	message_die(GENERAL_ERROR, $lang['no_tour_data'], "", __LINE__, __FILE__, $sql);
      }
      $tour = $db->sql_fetchrow($result);
      if (!$tour)
      {
        continue;
      }

      $sql = "SELECT * FROM " . iNA_TOUR_PLAY . "
        WHERE tour_id = " . $tour_id;
      if( !$result = $db->sql_query($sql) )
      {
      	message_die(GENERAL_ERROR, $lang['no_tour_play_data'], "", __LINE__, __FILE__, $sql);
      }
      $tourplayers = $db->sql_fetchrowset($result);
      $total_tourplayers = count($tourplayers);

      if($total_tourplayers >= $tour['tour_max_players'])
      {
        $joining .= sprintf($lang['tour_full'], htmlspecialchars($tour['tour_name'], ENT_QUOTES, 'UTF-8'));
      }
      else
      {
        $sql = "SELECT user_id FROM " . iNA_TOUR_PLAY . "
          WHERE tour_id = $tour_id
            AND user_id = ". $userdata['user_id'];
  		  if( !$result = $db->sql_query($sql) )
  		  {
    			message_die(GENERAL_ERROR, $lang['no_tour_play_data'], "", __LINE__, __FILE__, $sql);
    		}
    		$tour_total = $db->sql_numrows($result);
        if($tour_total < 1)
        {
          $joining .= sprintf($lang['tour_joined'], htmlspecialchars($tour['tour_name'], ENT_QUOTES, 'UTF-8'));

          $sql = "INSERT IGNORE INTO ". iNA_TOUR_PLAY . "
            (tour_id, user_id) VALUES ($tour_id, ".$userdata['user_id'].")";
      		if( !$result = $db->sql_query($sql) )
      		{
      			message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
      		}
        }
        else
        {
          $joining .= sprintf($lang['tour_member'], htmlspecialchars($tour['tour_name'], ENT_QUOTES, 'UTF-8'));
        }
      }
    }
    message_die(GENERAL_MESSAGE, ($joining !== '' ? $joining : $lang['tour_no_join']).$lang['tour_return']);
  }
  else
  {
    message_die(GENERAL_MESSAGE, $lang['tour_no_join'].$lang['tour_return']);
  }
}
//
//  Start a new Tournament...
//
else if(!empty($HTTP_POST_VARS['submit']))
{
  arcade_public_tournament_require_token($userdata, $lang);
//
//  Check to make sure a user isn't trying to create a tournament when the option is OFF
//
  if($arcade->arcade_config['games_tournament_user'] == 0)
  {
    message_die(GENERAL_MESSAGE, $lang['arcade_admin_only'].$lang['tour_return']);
  }
  $tour_name = isset($HTTP_POST_VARS['tour_name']) ? trim(stripslashes((string) $HTTP_POST_VARS['tour_name'])) : '';
  $tour_desc = isset($HTTP_POST_VARS['tour_desc']) ? trim(stripslashes((string) $HTTP_POST_VARS['tour_desc'])) : '';
  $tour_name = function_exists('mb_substr') ? mb_substr($tour_name, 0, 25, 'UTF-8') : substr($tour_name, 0, 25);
  $tour_desc = function_exists('mb_substr') ? mb_substr($tour_desc, 0, 255, 'UTF-8') : substr($tour_desc, 0, 255);
  if(strlen($tour_name) < 1 || strlen($tour_desc) < 3)
  {
    message_die(GENERAL_MESSAGE, $lang['error_no_tour_info'].$lang['tour_return']);
  }
  $sql = "SELECT COUNT(*) AS total FROM " . iNA_TOUR . " WHERE tour_active <> 3";
  if (!$result = $db->sql_query($sql))
  {
    message_die(GENERAL_ERROR, $lang['no_tour_data'], '', __LINE__, __FILE__, $sql);
  }
  $active_tours = $db->sql_fetchrow($result);
  if ((int) $active_tours['total'] >= (int) $arcade->arcade_config['games_tournament_max'])
  {
    message_die(GENERAL_MESSAGE, $lang['Not_Authorised'] . $lang['tour_return']);
  }
  $block_plays = $arcade->pass_var('block_plays', 0);
  $tour_max_players = $arcade->pass_var('tour_max_players', 0);
  $tour_active = 0;
  if($tour_max_players < 2)
  {
    $tour_max_players = $arcade->arcade_config['games_tournament_players'];
  }
  if($tour_max_players > $arcade->arcade_config['games_tournament_players'])
  {
    $tour_max_players = $arcade->arcade_config['games_tournament_players'];
  }
  $tour_player_turns = $arcade->pass_var('tour_player_turns', 0);
  if($tour_player_turns < 1)
  {
    $tour_player_turns = 1;
  }
  $block_plays = $block_plays ? 1 : 0;
  $tour_max_players = (int) $tour_max_players;
  $tour_player_turns = (int) $tour_player_turns;
  $sql = "INSERT INTO " . iNA_TOUR . " (tour_name, tour_desc, block_plays, tour_active, tour_max_players, tour_player_turns, start_id, start_date)
      VALUES ('" . $db->sql_escape($tour_name) . "', '" . $db->sql_escape($tour_desc) . "', $block_plays, $tour_active, $tour_max_players, $tour_player_turns, ".(int) $userdata['user_id'].", ". time() .")";
	if( !$result = $db->sql_query($sql) )
	{
		message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
	}

  $tour_id = (int) $db->sql_nextid();
  if ($tour_id <= 0)
  {
    message_die(GENERAL_ERROR, $lang['no_tour_data']);
  }
  
  $sql = "INSERT INTO ". iNA_TOUR_PLAY . " (tour_id, user_id) 
      VALUES ($tour_id, ".(int) $userdata['user_id'].")";
	if( !$result = $db->sql_query($sql) )
	{
		message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
	}

 	$message = $lang['tour_added'];
  $message .= ($userdata['user_level'] == ADMIN) ? $lang['tour_invite_players'] : $lang['tour_invite_players'];
  $message .= $lang['tour_add_games'].$lang['tour_return'];
	message_die(GENERAL_MESSAGE, $message);
}
//
//  Does the user want to Start a New Tournament ?
//
else if(!empty($HTTP_POST_VARS['start']))
{
//
//  Can USERS start a Tournament???
//
  if($arcade->arcade_config['games_tournament_user'] == 0)
  {
    message_die(GENERAL_MESSAGE, $lang['arcade_admin_only'].$lang['tour_return']);
  }

  $s_options = '<input type="Submit" name="submit" value="'. $lang['Submit'] . '">';

  $template->assign_block_vars('tournament_add', array(
      'NAME' => $tour['tour_name'],
      'DESC' => $tour['tour_desc'],
      'PLAYERS' => $tour['tour_max_players'],
      'TURNS' => $tour['tour_player_turns'],
      
      'S_BLOCK_YES' => '',
      'S_BLOCK_NO' => '',
      'S_SELECT_END' => '',
    ));
}
//
//  Admin END Tournament Feature.
//
else if(!empty($HTTP_POST_VARS['end']))
{
  arcade_public_tournament_require_token($userdata, $lang);
  $is_a_draw = false;
  
  if($userdata['user_level'] != ADMIN)
  {
    message_die(GENERAL_MESSAGE, $lang['arcade_admin_only'].$lang['tour_return']);
  }
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
	  $page_title = $lang['arcade_comment_delete'];
	  include($phpbb_root_path . 'includes/page_header.'.$phpEx);

    $template->set_filenames(array(
		  'body' => 'confirm_body.tpl')
  	);

  	$template->assign_vars(array(
	   	'MESSAGE_TITLE' => $lang['Confirm'],

  		'MESSAGE_TEXT' => $lang['arcade_tournament_end_sure'],

  		'L_NO' => $lang['No'],
	   	'L_YES' => $lang['Yes'],

  		'S_CONFIRM_ACTION' => append_sid("$filename"),
		'S_HIDDEN_FIELDS' => '<input type="hidden" name="end" value="1"><input type="hidden" name="id" value="'.(int) $tour_id.'"><input type="hidden" name="sid" value="' . htmlspecialchars($userdata['session_id'], ENT_QUOTES, 'UTF-8') . '">',
	   	));

	//
	// Generate the page
	//
  	$template->pparse('body');

  	include($phpbb_root_path . 'includes/page_tail.'.$phpEx);
    exit;    
  }
	$sql = "SELECT * FROM " . iNA_TOUR . "
     WHERE tour_id = " . $tour_id . "
     AND tour_active <> 3";
	if( !$result = $db->sql_query($sql) )
	{
		message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
	}
	$tour = $db->sql_fetchrow($result);
  if(!is_array($tour))
  {
    message_die(GENERAL_ERROR, $lang['no_tour_data']); 
  }
  
  $message = sprintf($lang['arcade_tournament_end'], htmlspecialchars($tour['tour_name'], ENT_QUOTES, 'UTF-8'));
//
//  Get Tournament Players
//
	$sql = "SELECT * FROM " . iNA_TOUR_PLAY . " AS p
	  LEFT JOIN " . USERS_TABLE . " AS u
	    ON p.user_id = u.user_id
    WHERE tour_id = " . $tour_id;
	if( !$result = $db->sql_query($sql) )
	{
		message_die(GENERAL_ERROR, $lang['no_tour_player_data'], "", __LINE__, __FILE__, $sql);
	}
	$tour_players = $db->sql_fetchrowset($result);
	$total_players = count($tour_players);
//
//  Get the Top Scores.
//
  $sql = "SELECT top_player, COUNT(top_player) as total FROM " . iNA_TOUR_DATA . "
    WHERE top_score IS NOT NULL
      AND tour_id = $tour_id
      GROUP BY top_player
      ORDER BY total DESC";
 	if( !$result = $db->sql_query($sql) )
 	{
 		message_die(GENERAL_ERROR, $lang['no_tour_data'], "", __LINE__, __FILE__, $sql);
 	}
  $tour_data = $db->sql_fetchrowset($result);
  $row_count = count($tour_data);
//
//  Check to make sure it's not a DRAW
//
  if ($row_count > 1 && (int) $tour_data[0]['total'] === (int) $tour_data[1]['total'])
  {
    $is_a_draw = true;
    message_die(GENERAL_MESSAGE, $message . $lang['tour_msg_draw'] . $lang['tour_return']);
  }
  $winner_id = ($row_count > 0) ? (int) $tour_data[0]['top_player'] : 0;
  if ($winner_id > 0)
  {
    $message .= sprintf($lang['tour_msg_winner'], htmlspecialchars($arcade->get_username($winner_id), ENT_QUOTES, 'UTF-8'));
  }
  $pm_message = '';

  if($is_a_draw == false)
  {
    if($winner_id > 0)
    {
      $sql = "UPDATE " . iNA_TOUR . "
        SET tour_active = 3, end_date = " . time() . "
          , champion = " . $winner_id . "
        WHERE tour_id = $tour_id";
    }
    else
    {
      $sql = "DELETE FROM " . iNA_TOUR . "
        WHERE tour_id = $tour_id";
    } 
    $result = $db->sql_query($sql);
    if(!$result)
    {
      message_die(GENERAL_ERROR, $lang['no_tour_update'], "", __LINE__, __FILE__, $sql );
    }
    $sql = "DELETE FROM " . iNA_TOUR_DATA . "
      WHERE tour_id = " . $tour_id;
    $result = $db->sql_query($sql);
    if(!$result)
    {
      message_die(GENERAL_ERROR, $lang['no_tour_delete_data'], "", __LINE__, __FILE__, $sql );
    }
    $sql = "DELETE FROM " . iNA_TOUR_PLAY . "
      WHERE tour_id = " . $tour_id;
    if( !$result = $db->sql_query($sql) )
    {
    	message_die(GENERAL_ERROR, $lang['no_tour_delete_data'], "", __LINE__, __FILE__, $sql);
    }
    $sql = "DELETE FROM " . iNA_TOUR_INVITE . "
      WHERE tour_id = " . $tour_id;
    if( !$result = $db->sql_query($sql) )
    {
      message_die(GENERAL_ERROR, $lang['no_tour_delete_data'], "", __LINE__, __FILE__, $sql);
    }
    
  }
//
//  Send a PM to all the users to let them know the tournie has finished
//
  for($i = 0; $i < $total_players; $i++)
  {
    ina_send_user_pm((int) $tour_players[$i]['user_id'], $lang['tour_msg_subject'], $message.$pm_message, (int) $userdata['user_id']);
  }

  message_die(GENERAL_MESSAGE, $message.$lang['tour_return']);
}
//
//	View Tournament Mode.
//
else if ($mode == 'tour')
{
  $played_games = 0;
  $GameData = array();
  
  $s_options = '<input type="Submit" name="join" value="'. $lang['Join'] . '"><input type="hidden" name="join_tour[]" value="'.(int) $tour_id.'">';
//
//  Get Tournament details
//
	$sql = "SELECT * FROM " . iNA_TOUR . "
     WHERE tour_id = " . $tour_id;
	if( !$result = $db->sql_query($sql) )
	{
		message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
	}
	$tour = $db->sql_fetchrow($result);
  if (!$tour)
  {
    message_die(GENERAL_MESSAGE, $lang['no_tour_data'] . $lang['tour_return']);
  }
//
//  Get Tournament Games
//
	$sql = "SELECT * FROM " . iNA_TOUR_DATA . "
    WHERE tour_id = " . $tour_id;
	if( !$result = $db->sql_query($sql) )
	{
		message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
	}
	$tour_games = $db->sql_fetchrowset($result);
	$total_games = count($tour_games);
//
//  Get Tournament Players
//
	$sql = "SELECT * FROM " . iNA_TOUR_PLAY . " AS p
	  LEFT JOIN " . USERS_TABLE . " AS u
	    ON p.user_id = u.user_id
    WHERE tour_id = " . $tour_id;
	if( !$result = $db->sql_query($sql) )
	{
		message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
	}
	$tour_players = $db->sql_fetchrowset($result);
	$total_players = count($tour_players);

  $template->assign_block_vars('player_head', array());

  for($i = 0; $i < $total_players; $i++)
  {
//
//  Get Game Info for This user.
//
    if($tour_players[$i]['user_id'] == $userdata['user_id'])
    {
      $in_tour = true;
      $GameData = phpbb_safe_unserialize(stripslashes($tour_players[$i]['gamedata']));
      if (!is_array($GameData))
      {
        $GameData = array();
      }
      $played_games = count($GameData);
    }
//
//  Now we have that, let's build a list of what games each person has played for some stats
// 
    $temp_gamedata = phpbb_safe_unserialize(stripslashes($tour_players[$i]['gamedata']));
    $played_games_count = is_array($temp_gamedata) ? count($temp_gamedata) : 0;

    $played_games_list = ' [<i>';
    for($count = 0; $count < $played_games_count; $count++)
    {
      if($count > 0)
      {
        $played_games_list .= ', ';
      }
      $played_name = isset($temp_gamedata[$count]['game_name']) ? (string) $temp_gamedata[$count]['game_name'] : '';
      if ($played_name === '')
      {
        continue;
      }
      $played_count = isset($temp_gamedata[$count]['played']) ? (int) $temp_gamedata[$count]['played'] : 0;
      $played_score = isset($temp_gamedata[$count]['score']) ? (int) $temp_gamedata[$count]['score'] : 0;
      $played_games_list .= htmlspecialchars($played_name, ENT_QUOTES, 'UTF-8') . '(P:<b>' . $played_count . '</b>:S:<b>' . $played_score . '</b>)';
      if (!isset($games_played_list[$played_name]))
      {
        $games_played_list[$played_name] = '';
      }
      $games_played_list[$played_name] .= (int) $tour_players[$i]['user_id'] . '|';
    }
    $played_games_list .= '</i>]';

    $template->assign_block_vars('player', array(
      'ID' => (int) $tour_players[$i]['user_id'],
      'ROW_CLASS' => ( !($i % 2) ) ? 'row1' : 'row2',
      'NAME' => '<a href="'.append_sid('profile.'.$phpEx.'?mode=viewprofile&amp;u='.(int) $tour_players[$i]['user_id']).'" class="gensmall">' . htmlspecialchars($tour_players[$i]['username'], ENT_QUOTES, 'UTF-8') . '</a>',
      'PLAYED_GAMES' => ($played_games_count > 0 && $userdata['user_level'] == ADMIN) ? $played_games_list : '',

       ));

    if($userdata['user_level'] == ADMIN)
    {
      $s_options = '<input type="Submit" name="end" value="'. $lang['End'] . '"><input type="Hidden" name="id" value="'.$tour_id.'">';
    }
  }
//
//  Thats the Player Info sorted, now get the Games Info
//
  $template->assign_block_vars('game_head', array());

  for($i = 0; $i < $total_games; $i++)
  {
    $played = 0;
    $played_text = $lang['has'];
//
//  Build a list of those that have played this game
//
    $tour_game_name = (string) $tour_games[$i]['game_name'];
    $temp_games_players = explode('|', isset($games_played_list[$tour_game_name]) ? $games_played_list[$tour_game_name] : '');
    $played_players = (count($temp_games_players) > 1) ? '' : $lang['No-One'];

    for($count = 0; $count < (count($temp_games_players)-1); $count++)
    {
      if($count > 0)
      {
        $played_players .= ', ';
        $played_text = $lang['have'];
      }
      $player_id = (int) $temp_games_players[$count];
      $played_players .= '<a href="'.append_sid('profile.'.$phpEx.'?mode=viewprofile&amp;u='.$player_id).'" class="gensmall">'.htmlspecialchars($arcade->get_username($player_id), ENT_QUOTES, 'UTF-8') .'</a>';
    }
//
//  Check to see what THIS user has played
//
    for($count = 0; $count < $played_games; $count++)
    {
      if(isset($GameData[$count]['game_name']) && $GameData[$count]['game_name'] == $tour_game_name)
      {
        $played = isset($GameData[$count]['played']) ? (int) $GameData[$count]['played'] : 0;
      }
    }
//
//  Get the Game Info
//  
    $sql = "SELECT * FROM " . iNA_GAMES . "
      WHERE game_name = '" . $db->sql_escape($tour_game_name) . "'";
		if( !$result = $db->sql_query($sql) )
 		{
 			message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
 		}
		$game_info = $db->sql_fetchrow($result);
		if (!$game_info)
		{
			continue;
		}
 		$game_id = $game_info['game_id'];

  	$image_path = $game_info['image_path'];
		if ( $image_path == "" )
		{
			if( @file_exists("./". $game_info['game_path'] . $game_info['game_name'] .".gif") )
			{
				$image_path = './' . $game_info['game_path'] . $game_info['game_name'] . '.gif';
			}
			else
			{
				$image_path = $arcade->arcade_config['games_default_img'];
			}
		}
		else if ( strlen( $image_path ) < 5 )
		{
			$image_path = './' . $game_info['game_path'] . $game_info['game_name'] . $game_info['image_path'];
			if( @file_exists($image_path) )
			{
  			$image_path = './' . $game_info['game_path'] . $game_info['game_name'] . $game_info['image_path'];
			}
			else
			{
				$image_path = $arcade->arcade_config['games_default_img'];
			}
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
    if($in_tour == true && (((int) $tour['tour_player_turns']-$played) > 0) && (int) $tour['tour_active'] === 2)
    { 
		  $tour_token = arcade_public_tournament_game_token($tour_id, $game_info['game_id'], $userdata['session_id']);
		  $game_url = append_sid($filename . '?mode=game&amp;id=' . (int) $tour_id . '&amp;game_id=' . (int) $game_info['game_id'] . '&amp;tour_token=' . rawurlencode($tour_token));
		  $game_link = "<a href=\"javascript:Gk_PopTart('" . $game_url . "', 'Game_Window', '" . (int) $game_info['win_width'] . "', '" . (int) $game_info['win_height'] . "', 'no')\" class=\"forumlink\" onClick=\"blur()\">&nbsp;&laquo;&nbsp;" . htmlspecialchars($game_info['game_desc'], ENT_QUOTES, 'UTF-8') . "&nbsp;&raquo;&nbsp;</a>";
    }
    else
    {
      $game_link = '<span class="gensmall">' . htmlspecialchars($game_info['game_desc'], ENT_QUOTES, 'UTF-8') . '</span>';
    }

    $highest_scorer = $lang['No-One'];

    $sql = "SELECT top_score, top_player FROM " . iNA_TOUR_DATA . "
      WHERE top_score IS NOT NULL
        AND tour_id = $tour_id 
        AND game_name = '" . $db->sql_escape($game_info['game_name']) . "'";
   	if( !$result = $db->sql_query($sql) )
   	{
   		message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
   	}
    $row_count = $db->sql_numrows($result);
    if($row_count > 0)
    {
   	  $tour_data = $db->sql_fetchrow($result);
	  $top_player_id = (int) $tour_data['top_player'];
	  $highest_scorer = '<a href="'.append_sid('profile.'.$phpEx.'?mode=viewprofile&amp;u='.$top_player_id).'" class="gensmall">'.htmlspecialchars($arcade->get_username($top_player_id), ENT_QUOTES, 'UTF-8').'</a>';
   	}
    
        
    $template->assign_block_vars('game', array(
        'ID' => (int) $game_info['game_id'],
        'ROW_CLASS' => ( !($i % 2) ) ? 'row1' : 'row2',
        'DESC' => $game_link,
        'IMAGE' => '<img src="'.htmlspecialchars($image_path, ENT_QUOTES, 'UTF-8').'" width="'.(int) $arcade->arcade_config['games_image_width'].'" height="'.(int) $arcade->arcade_config['games_image_height'].'" alt="" />',
        'CONTROL' => $game_control,
        'INFO' => ($in_tour == true) ? sprintf($lang['tour_play_stats'], $tour['tour_player_turns']-$played, $highest_scorer, $played_players, $played_text) : sprintf($lang['tour_not_part'], $highest_scorer, $played_players)
      ));
    
    
  }
    
	$page_title = htmlspecialchars($tour['tour_name'], ENT_QUOTES, 'UTF-8') . '&nbsp;' . $lang['tournament'];
	$url		= '&nbsp;&raquo;&nbsp;<a href="'. append_sid("activity.$phpEx") .'" class="nav">' . $lang['games_catagories'] . '</a>&nbsp;&raquo;&nbsp;<a href="' . append_sid($filename) . '" class="nav">' . $lang['tournaments'] . '</a>&nbsp;&raquo;&nbsp;' . htmlspecialchars($tour['tour_name'], ENT_QUOTES, 'UTF-8');
}
//
//  Game Mode!!!!!!!!!
//
else if ($mode == 'game')
{
  $tour_token = isset($HTTP_GET_VARS['tour_token']) ? stripslashes((string) $HTTP_GET_VARS['tour_token']) : '';
  $expected_token = arcade_public_tournament_game_token($tour_id, $game_id, $userdata['session_id']);
  if ($tour_id <= 0 || $game_id <= 0 || !hash_equals($expected_token, $tour_token))
  {
    message_die(GENERAL_MESSAGE, $lang['Not_Authorised']);
  }

  $sql = "SELECT g.*, p.gamedata, t.tour_player_turns
    FROM " . iNA_TOUR_DATA . " AS td
    INNER JOIN " . iNA_GAMES . " AS g ON td.game_name = g.game_name
    INNER JOIN " . iNA_TOUR . " AS t ON td.tour_id = t.tour_id
    INNER JOIN " . iNA_TOUR_PLAY . " AS p ON p.tour_id = t.tour_id
    WHERE td.tour_id = " . $tour_id . "
      AND g.game_id = " . $game_id . "
      AND g.game_avail = 1
      AND t.tour_active = 2
      AND p.user_id = " . (int) $userdata['user_id'];
	if( !$result = $db->sql_query($sql) )
 	{
 		message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
 	}
	$game_info = $db->sql_fetchrow($result);
  if (!$game_info)
  {
    message_die(GENERAL_MESSAGE, $lang['Not_Authorised']);
  }

  $old_GameData = phpbb_safe_unserialize(stripslashes((string) $game_info['gamedata']));
  if (!is_array($old_GameData))
  {
    $old_GameData = array();
  }
  $found = false;
  $games_count = count($old_GameData);
  for($i = 0; $i < $games_count; $i++)
  {
    if(isset($old_GameData[$i]['game_name']) && $old_GameData[$i]['game_name'] === $game_info['game_name'])
    {
      $played = isset($old_GameData[$i]['played']) ? (int) $old_GameData[$i]['played'] + 1 : 1;
      if($played > (int) $game_info['tour_player_turns'])
      {
        $gen_simple_header = TRUE;
        message_die(GENERAL_MESSAGE, "Too Many Turns<br>" . $lang['newscore_close_first']);
      }
      $old_GameData[$i]['played'] = $played;
      $found = true;
      break;
    }
  }
  if(!$found)
  {
    $GameData['game_name'] = $game_info['game_name'];
    $old_GameData[] = $GameData;
  }
  $serialized_game_data = serialize($old_GameData);

  $sql = "UPDATE " . iNA_TOUR_PLAY . "
    SET last_played_game = '".$db->sql_escape($game_info['game_name'])."', last_played_time = ".time().", gamedata = '".$db->sql_escape($serialized_game_data)."'
      WHERE user_id = ". (int) $userdata['user_id'] . "
      AND tour_id = $tour_id";
	if( !$result = $db->sql_query($sql) )
 	{
 		message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
 	}

  $session = update_ina_session($userdata['user_id'], $user_ip, PAGE_ARCADE_TOUR, $game_info['game_name'], '', 'NORM', $tour_id);

  require("loader.".$phpEx);
  exit;
}
//
//  View the Chapions List
//
else if ($mode == 'champions')
{
  redirect(append_sid($filename));
  exit;
}
//
//
//
else if($mode == 'add_games')
{
  redirect(append_sid($filename));
  exit;
}
//
//
//
else if($mode == 'invite_players')
{
  redirect(append_sid($filename));
  exit;

}
//
//  Default Tournament View..
//
else
{
  $champ_count = 0;
  
  $template->assign_block_vars('tour_head', array(
    'TOURNAMENT' => $lang['tournament'],
    'INFORMATION' => $lang['Information'],
    'ACTION' => $lang['Action'],
    ));
//
//  last 5 champions
//
  $sql = "SELECT champion, tour_name from " . iNA_TOUR . "
    WHERE tour_active = 3
      ORDER BY tour_id DESC
      LIMIT 0,5";
  if( !$result = $db->sql_query($sql) )
  {
  	message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
  }
  $tour_champs = $db->sql_fetchrowset($result);
  $champ_count = count($tour_champs);
  if(is_array($tour_champs))
  {
    $last_5_champ_name = '';
    $last_5_champions = '';
    for($i = 0; $i < $champ_count; $i++)
    {
      $champion_id = (int) $tour_champs[$i]['champion'];
      $last_5_champ_name .= '<img src="images/crown.gif" alt="" /> <a href="'.append_sid('profile.'. $phpEx .'?mode=viewprofile&amp;u='. $champion_id).'" class="gensmall">'. htmlspecialchars($arcade->get_username($champion_id), ENT_QUOTES, 'UTF-8') . '</a> <img src="images/crown.gif" alt="" /><br />';
      $last_5_champions .= htmlspecialchars($tour_champs[$i]['tour_name'], ENT_QUOTES, 'UTF-8') . ' <br />';
    }
  }  
  
  $template->assign_block_vars('champions', array(
    'NAME' => ($champ_count > 0) ? $last_5_champ_name : '',
    'OF' => ($champ_count > 0) ? $lang['champion_of'] : '',
    'LIST' => ($champ_count > 0) ? $last_5_champions : $lang['None'],
    'LINK' => ($champ_count > 5) ? '<br /><a href="'. append_sid("$filename?mode=champions") .'" class="forumlink">'.$lang['view_champions'].'</a>' : '',
    
    ));

  $sql = "SELECT * FROM " . iNA_TOUR . "
    WHERE tour_active <> 3
    ORDER BY tour_active DESC, tour_id ASC";
  if( !$result = $db->sql_query($sql) )
  {
  	message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
  }
  $tours = $db->sql_fetchrowset($result);
  $total_tournaments = count($tours);

  if($total_tournaments == 0 || !is_array($tours))
  {
    $template->assign_block_vars('tournament_none', array(
      'NONE' => $lang['None'],
      ));
    $s_options = '<input type="Submit" name="start" value="'. $lang['Start'] . '">';
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
    
    $sql = "SELECT user_id FROM " . iNA_TOUR_PLAY . "
      WHERE tour_id = " . $tours[$i]['tour_id'] . "
      AND user_id = " . $userdata['user_id'];
    if( !$result = $db->sql_query($sql) )
    {
    	message_die(GENERAL_ERROR, $lang['no_tour_players_data'], "", __LINE__, __FILE__, $sql);
    }
    $tour_play = $db->sql_fetchrow($result);
    
    $join_text = '<input type="checkbox" name="join_tour[]" value="'.(int) $tours[$i]['tour_id'].'">';
    if((int) $tours[$i]['tour_active'] !== 2)
    {
      $join_text = $lang['inactive'];
    }
    else if($tour_play && (int) $tour_play['user_id'] === (int) $userdata['user_id'])
    {
      $join_text = $lang['None'];
    }
    else if($total_players >= $tours[$i]['tour_max_players'])
    {
      $join_text = $lang['Full'];
    }
    
    $template->assign_block_vars('tour', array(
        'ROW_CLASS' => ( !($i % 2) ) ? 'row1' : 'row2',
        'NAME' => '<a href="'.append_sid($filename.'?mode=tour&amp;id='.(int) $tours[$i]['tour_id']).'" class="forumlink">'.htmlspecialchars($tours[$i]['tour_name'], ENT_QUOTES, 'UTF-8').'</a>',
        'DESC' => htmlspecialchars($tours[$i]['tour_desc'], ENT_QUOTES, 'UTF-8'),

        'TOTAL_GAMES' => sprintf($lang['tournament_games'], $total_games),
        'TOTAL_PLAYERS' => sprintf($lang['tournament_players'], $total_players),

        'JOIN' => $join_text,
        'EDIT' => '',
        'DELETE' => '',

      ));
  }
  if($total_tournaments >= $arcade->arcade_config['games_tournament_max'])
  {

    $s_options = '<input type="Submit" name="join" value="'. $lang['Join'] . '">';
  }

}
  
$template->assign_vars(array(

    'CHAMPIONS' => sprintf($lang['Champions'], 5),

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
    'L_START' => $lang['tournament_start'],
    'L_START_INFO' => $lang['tournament_start_info'],
    'L_END' => $lang['tournament_end'],
    'L_END_INFO' => $lang['tournament_end_info'],

		'NAME' => $lang['Name'],
		'DATA' => $lang['Data'],
		'JOIN' => $lang['Join'],
		'PLAYERS' => isset($lang['Players']) ? $lang['Players'] : 'Spieler',

		'U_CAT' => $url,
		
		'S_ACTION' => append_sid($filename),
		'S_OPTIONS' => $s_options,
		'S_FORM_TOKEN' => '<input type="hidden" name="sid" value="' . htmlspecialchars($userdata['session_id'], ENT_QUOTES, 'UTF-8') . '" />',
		
    'L_YES' => $lang['Yes'],
    'L_NO' => $lang['No'],
		
		'ARCADE_MOD' => sprintf($lang['activitiy_mod_info'], $arcade->version)
        ));

//
//  Generate page
//
include($phpbb_root_path . 'includes/page_header.'.$phpEx);
$template->pparse('body');
include($phpbb_root_path . 'includes/page_tail.'.$phpEx);

?>
