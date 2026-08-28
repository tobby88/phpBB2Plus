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
include_once($phpbb_root_path . 'includes/functions_arcade.'.$phpEx);
//
// Start session management
//
$userdata = session_pagestart($user_ip, PAGE_ARCADE_TOUR);
init_userprefs($userdata);
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
$tour_id    = $arcade->pass_var('id', 0);
$game_id    = $arcade->pass_var('game_id', 0);
$join_tour  = $arcade->pass_var('join_tour', array());
$s_options  = '<input type="Submit" name="start" value="'. $lang['Start'] . '">&nbsp;&nbsp;<input type="Submit" name="join" value="'. $lang['Join'] . '">';
$GameData   = array("game_name" => '', "played" => 1, "score" => 0, "time_taken" => 0);
$url		    = '&nbsp;&raquo;&nbsp;<a href="activity.'.$phpEx.'" class="nav">' . $lang['games_catagories'] . '</a>&nbsp;&raquo;&nbsp;' . $lang['tournaments'];
//
//  Set the template so we don't get any errors.
//
$template->set_filenames(array('body' => 'arcade_tour_body.tpl') );
//
//  Does the user want to join a tournament?
//
if(!empty($HTTP_POST_VARS['join']))
{
  if(is_array($join_tour))
  {
    $joining = '';
    for($i=0; $i < (count($join_tour)); $i++)
    {
      $tour_id = intval($join_tour[$i]);
      
      $sql = "SELECT * FROM " . iNA_TOUR . "
        WHERE tour_id = ". $tour_id;
      if( !$result = $db->sql_query($sql) )
      {
      	message_die(GENERAL_ERROR, $lang['no_tour_data'], "", __LINE__, __FILE__, $sql);
      }
      $tour = $db->sql_fetchrow($result);

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
        $joining .= sprintf($lang['tour_full'], $tour['tour_name']);
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
          $joining .= sprintf($lang['tour_joined'], $tour['tour_name']);

          $sql = "INSERT INTO ". iNA_TOUR_PLAY . "
            (tour_id, user_id) VALUES ($tour_id, ".$userdata['user_id'].")";
      		if( !$result = $db->sql_query($sql) )
      		{
      			message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
      		}
        }
        else
        {
          $joining .= sprintf($lang['tour_member'], $tour['tour_name']);
        }
      }
    }
    message_die(GENERAL_MESSAGE, $joining.$lang['tour_return']);
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
//
//  Check to make sure a user isn't trying to create a tournament when the option is OFF
//
  if($arcade->arcade_config['games_tournament_user'] == 0)
  {
    message_die(GENERAL_MESSAGE, $lang['arcade_admin_only'].$lang['tour_return']);
  }
  $tour_name = $arcade->pass_var('tour_name', '');
  $tour_desc = $arcade->pass_var('tour_desc', '');
  if(strlen($tour_name) < 1 || strlen($tour_desc) < 3)
  {
    message_die(GENERAL_MESSAGE, $lang['error_no_tour_info'].$lang['tour_return']);
  }
  $block_plays = $arcade->pass_var('block_plays', 0);
  $tour_max_players = $arcade->pass_var('tour_max_players', 0);
  $tour_active = $arcade->pass_var('tour_active', 0);
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
  $sql = "INSERT INTO " . iNA_TOUR . " (tour_name, tour_desc, block_plays, tour_active, tour_max_players, tour_player_turns, start_id, start_date)
      VALUES ('$tour_name', '$tour_desc', $block_plays, $tour_active, $tour_max_players, $tour_player_turns, ".$userdata['user_id'].", ". time() .")";
	if( !$result = $db->sql_query($sql) )
	{
		message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
	}

  $tour_id = $arcade->get_tour_id($tour_name);
  
  $sql = "INSERT INTO ". iNA_TOUR_PLAY . " (tour_id, user_id) 
      VALUES ($tour_id, ".$userdata['user_id'].")";
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
  		'S_HIDDEN_FIELDS' => '<input type="hidden" name="end" value="'. $lang['End'] . '"><input type="hidden" name="id" value="'.$tour_id.'">',
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
  
  $message = sprintf($lang['arcade_tournament_end'], $tour['tour_name']);
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
  for($i = 0; $i < $row_count; $i++)
  {
    if( $tour_data[$i]['total'] == $tour_data[($i+1)]['total'] && $i == 0)
    {
      $is_a_draw = true;
      $message .= $lang['tour_msg_draw'];
//
//  WE HAVE A DRAW...! remove old players and start with just the drawn players
//
      message_die(GENERAL_MESSAGE, 'NOT WRITTEN');
      break;
    }
    else
    {
      $message .= sprintf($lang['tour_msg_winner'], $arcade->get_username($tour_data[$i]['top_player']));
      break;
    }
  }
  $pm_message = sprintf($lang['tour_msg_message'], $board_config['server_name'] . $board_config['script_path'], $game_info['game_id'] , $game_info['game_desc'] );

  if($is_a_draw == false)
  {
    if($tour_data[0]['top_player'] > 0)
    {
      $sql = "UPDATE " . iNA_TOUR . "
        SET tour_active = 3, end_date = " . time() . "
          , champion = " . $tour_data[0]['top_player'] . "
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
    
  }
//
//  Send a PM to all the users to let them know the tournie has finished
//
  for($i = 0; $i < $total_players; $i++)
  {
    ina_send_user_pm($tour_players[$i]['user_id'], $lang['tour_msg_subject'], $message.$pm_message, $userdata['user_id']);
  }

  message_die(GENERAL_MESSAGE, $message.$lang['tour_return']);
}
//
//	View Tournament Mode.
//
else if ($mode == 'tour')
{
  $played_games = 0;
  
  $s_options = '<input type="Submit" name="join" value="'. $lang['Join'] . '"><input type="hidden" name="join_tour[]" value="'.$tour_id.'"';
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
      $GameData = unserialize(stripslashes($tour_players[$i]['gamedata']));
      $played_games = count($GameData);
    }
//
//  Now we have that, let's build a list of what games each person has played for some stats
// 
    $temp_gamedata = unserialize(stripslashes($tour_players[$i]['gamedata']));
    $played_games_count = is_array($temp_gamedata) ? count($temp_gamedata) : 0;

    $played_games_list = ' [<i>';
    $games_played_list[] = '';
    for($count = 0; $count < $played_games_count; $count++)
    {
      if($count > 0)
      {
        $played_games_list .= ', ';
      }
      $played_games_list .= $temp_gamedata[$count]['game_name'] . '(P:<b>' . $temp_gamedata[$count]['played'] . '</b>:S:<b>' . $temp_gamedata[$count]['score'] . '</b>)';
      $games_played_list[$temp_gamedata[$count]['game_name']] .= $tour_players[$i]['user_id'] . '|';
    }
    $played_games_list .= '</i>]';

    $template->assign_block_vars('player', array(
      'ROW_CLASS' => ( !($i % 2) ) ? 'row1' : 'row2',
      'NAME' => '<a href="profile.'.$phpEx.'?mode=viewprofile&amp;u='. $tour_players[$i]['user_id'] .'" class="gensmall">' . $tour_players[$i]['username'] . '</a>',
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
    $temp_games_players = explode('|', $games_played_list[$tour_games[$i]['game_name']]);  
    $played_players = (count($temp_games_players) > 1) ? '' : $lang['No-One'];

    for($count = 0; $count < (count($temp_games_players)-1); $count++)
    {
      if($count > 0)
      {
        $played_players .= ', ';
        $played_text = $lang['have'];
      }
      $played_games_list .= $temp_gamedata[$count]['game_name'];
      $played_players .= '<a href="profile.'.$phpEx.'?mode=viewprofile&amp;u='.$temp_games_players[$count].'" class="gensmall">'.$arcade->get_username($temp_games_players[$count]) .'</a>';
    }
//
//  Check to see what THIS user has played
//
    for($count = 0; $count < $played_games; $count++)
    {
      if($GameData[$count]['game_name'] == $tour_games[$i]['game_name'])
      {
        $played = ($GameData[$count]['played']);
      }
    }
//
//  Get the Game Info
//  
    $sql = "SELECT * FROM " . iNA_GAMES . "
      WHERE game_name = '" . $tour_games[$i]['game_name'] . "'";
		if( !$result = $db->sql_query($sql) )
 		{
 			message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
 		}
 		$game_info = $db->sql_fetchrow($result);
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
    if($in_tour == true && (($tour['tour_player_turns']-$played) > 0) && $tour['tour_active'])
    { 
		  $game_link = "<a href=\"javascript:Gk_PopTart('$filename?mode=game&amp;id=$tour_id&amp;game_id=" . $game_info['game_id'] . "$SID', 'Game_Window', '" . $game_info['win_width'] . "', '" . $game_info['win_height'] . "', 'no')\" class=\"forumlink\" onClick=\"blur()\">&nbsp;&laquo;&nbsp;" . $game_info['game_desc'] . "&nbsp;&raquo;&nbsp;</a>";
    }
    else
    {
      $game_link = '<span class="gensmall">' . $game_info['game_desc'] . '</span>';
    }

    $highest_scorer = $lang['No-One'];

    $sql = "SELECT top_score, top_player FROM " . iNA_TOUR_DATA . "
      WHERE top_score IS NOT NULL
        AND tour_id = $tour_id 
        AND game_name = '" . $game_info['game_name'] . "'";
   	if( !$result = $db->sql_query($sql) )
   	{
   		message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
   	}
    $row_count = $db->sql_numrows($result);
    if($row_count > 0)
    {
   	  $tour_data = $db->sql_fetchrow($result);
   	  $highest_scorer = '<a href="profile.'.$phpEx.'?mode=viewprofile&amp;u='.$tour_data['top_player'].'" class="gensmall">'.$arcade->get_username($tour_data['top_player']).'</a>';
   	}
    
        
    $template->assign_block_vars('game', array(
        'ROW_CLASS' => ( !($i % 2) ) ? 'row1' : 'row2',
        'DESC' => $game_link,
        'IMAGE' => '<img src="'.$image_path.'" width="'.$arcade->arcade_config['games_image_width'].'" height="'.$arcade->arcade_config['games_image_height'].'"/>',
        'CONTROL' => $game_control,
        'INFO' => ($in_tour == true) ? sprintf($lang['tour_play_stats'], $tour['tour_player_turns']-$played, $highest_scorer, $played_players, $played_text) : sprintf($lang['tour_not_part'], $highest_scorer, $played_players)
      ));
    
    
  }
    
	$page_title = $tour['tour_name'] . '&nbsp;' . $lang['tournament']; 
	$url		= '&nbsp;&raquo;&nbsp;<a href="'. append_sid("activity.$phpEx") .'" class="nav">' . $lang['games_catagories'] . '</a>&nbsp;&raquo;&nbsp;<a href="' . append_sid($filename) . '" class="nav">' . $lang['tournaments'] . '</a>&nbsp;&raquo;&nbsp;' . $tour['tour_name'];
}
//
//  Game Mode!!!!!!!!!
//
else if ($mode == 'game')
{
  $FOUND = FALSE;
  
  $sql = "SELECT * FROM " . iNA_GAMES . "
      WHERE game_id = $game_id";
	if( !$result = $db->sql_query($sql) )
 	{
 		message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
 	}
 	$game_info = $db->sql_fetchrow($result);

  $sql = "SELECT gamedata, tour_player_turns FROM " . iNA_TOUR_PLAY . " AS p
    LEFT JOIN ". iNA_TOUR ." AS t ON p.tour_id = t.tour_id
      WHERE p.user_id = ". $userdata['user_id'] . "
      AND p.tour_id = $tour_id";
	if( !$result = $db->sql_query($sql) )
 	{
 		message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
 	}
 	$user_GameData = $db->sql_fetchrow($result);
  $old_GameData = unserialize(stripslashes($user_GameData['gamedata']));
  $games_count = count($old_GameData );

 	if(is_array($old_GameData))
 	{
    for($i = 0; $i < $games_count; $i++)
    {
      if($old_GameData[$i]['game_name'] == $game_info['game_name'])
      {
        $played = ($old_GameData[$i]['played'])+1;
        if($played > $user_GameData['tour_player_turns'])
        {
          $gen_simple_header = TRUE; 
          message_die(GENERAL_MESSAGE, "Too Many Turns<br>" . $lang['newscore_close_first']);
        }
        $old_GameData[$i]['played'] = $played;
        $FOUND = TRUE;
        continue;
      }
    }
    if(!($FOUND))
    {
      $GameData['game_name'] = $game_info['game_name']; 	
      array_push($old_GameData, $GameData);
    }
  }
  else
  {
    $old_GameData = array();
    $GameData['game_name'] = $game_info['game_name']; 	
    array_push($old_GameData, $GameData);
  }
  $GameData = $old_GameData;

  $sql = "UPDATE " . iNA_TOUR_PLAY . "
    SET last_played_game = '".$game_info['game_name']."', last_played_time = ".time().", gamedata = '".addslashes(serialize($GameData))."'
      WHERE user_id = ". $userdata['user_id'] . "
      AND tour_id = $tour_id";
	if( !$result = $db->sql_query($sql) )
 	{
 		message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
 	}

  $session = update_ina_session($userdata['user_id'], $user_ip, PAGE_ARCADE_TOUR, $game_info['game_name'], '', $win, $tour_id);

  require("loader.".$phpEx);
  exit;
}
//
//  View the Chapions List
//
else if ($mode == 'champions')
{
  $sql = "SELECT champion, tour_name from " . iNA_TOUR . "
    WHERE tour_active = 3
      ORDER BY tour_id ASC";
  if( !$result = $db->sql_query($sql) )
  {
  	message_die(GENERAL_ERROR, $lang['no_game_data'], "", __LINE__, __FILE__, $sql);
  }
  $tour_champs = $db->sql_fetchrowset($result);

  message_die(GENERAL_ERROR, 'Give me a chance');
}
//
//
//
else if($mode == 'add_games')
{
  $s_options = '<input type="Submit" name="add_games" value="'. $lang['Add'] . '">&nbsp;&nbsp;<input type="Submit" name="add_players" value="'. $lang['Invite_Players'] . '">';
}
//
//
//
else if($mode == 'invite_players')
{
  $s_options = '<input type="Submit" name="invite" value="'. $lang['Invite'] . '">&nbsp;&nbsp;<input type="Submit" name="add_games" value="'. $lang['Add_Games'] . '">';

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
      $last_5_champ_name .= '<img src="images/crown.gif" /> <a href="profile.'. $phpEx .'?mode=viewprofile&amp;u='. $tour_champs[$i]['champion'] . '" class="gensmall">'. $arcade->get_username($tour_champs[$i]['champion']) . '</a> <img src="images/crown.gif" /><br />';
      $last_5_champions .= $tour_champs[$i]['tour_name'] . ' <br />';
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
    
    $join_text = '<input type="checkbox" name="join_tour[]" value="'.$tours[$i]['tour_id'].'">';
    if(!$tours[$i]['tour_active'])
    {
      $join_text = $lang['inactive'];
    }
    else if($tour_play['user_id'] == $userdata['user_id'])
    {
      $join_text = $lang['None'];
    }
    else if($total_players >= $tours[$i]['tour_max_players'])
    {
      $join_text = $lang['Full'];
    }
    
    $template->assign_block_vars('tour', array(
        'ROW_CLASS' => ( !($i % 2) ) ? 'row1' : 'row2',
        'NAME' => '<a href="'.$filename.'?mode=tour&amp;id='.$tours[$i]['tour_id'].'" class="forumlink">'.$tours[$i]['tour_name'].'</a>',
        'DESC' => $tours[$i]['tour_desc'],

        'TOTAL_GAMES' => sprintf($lang['tournament_games'], $total_games),
        'TOTAL_PLAYERS' => sprintf($lang['tournament_players'], $total_players),

        'JOIN' => $join_text,
        'EDIT' => append_sid("$filename?mode=edit&amp;id=".$tours[$i]['tour_id']),
        'DELETE' => append_sid("$filename?mode=delete&amp;id=".$tours[$i]['tour_id']),

      ));
  }
  if($total_tournaments == $arcade->arcade_config['games_tournament_max'])
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
