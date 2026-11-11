<?php 
/*************************************************************************** 
 * 
 *                              newscore.php 
 *                              ------------ 
 *   begin                : Tuesday, Jan 2nd, 2007
 *   copyright            : (c)2003-2007 dEfEndEr www.phpbb-arcade.com 
 *   email                : defenders_realm@yahoo.com 
 * 
 *   $Id: newscore.php, v2.1.8 2007/01/02 12:59:59 dEfEndEr Exp $ 
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
 *   If you have made any changes then please notify me so they can be added 
 *   if they are improvments. You of course will get the credit for helping 
 *   out. If you would like to see other MODs that I have made then check 
 *   out our forum at : www.phpbb-arcade.com 
 * 
 * 	CREDITS: 
 *  Napoleon - Original Activity Mod v2.0.0
 *  Whoo
 *  Painkiller - Add-On & Support
 *  Mark - Add-On & Support
 *  Minesh - Add-On's & Support
 *  ~Maverick~ - Add-On's
 *  Zorial - Add-On's
 *  Madman - Chief Tester :)
 *
 ***************************************************************************
 *
 * Author Notes:
 *
 * v2.0.6 includes 'arcade_hash' data for greater security.
 * v2.0.7 includes favourites code, better error detection and place numbering.
 * v2.0.8 includes support for Invasion Power Arcade (IBProArcade) games
 *			also SMF Games
 * v2.1.1 includes support for pnFlashGames games
 * v2.1.2 Better Session Managment Added 
 * v2.1.3 Auto Prune System Added, moved away from board_config
 * v2.1.4 Tournament feature support and Guest cookie System added 
 *
 ***************************************************************************/

define('IN_PHPBB', true); 

$phpbb_root_path = './'; 
include_once($phpbb_root_path . 'extension.inc'); 
include_once($phpbb_root_path . 'common.'.$phpEx); 
include_once($phpbb_root_path . 'includes/constants_arcade.'.$phpEx);
//
// Start session management
//
$userdata			     = session_pagestart($user_ip, PAGE_ARCADE_SCORE);
init_userprefs($userdata);
include_once($phpbb_root_path . 'includes/functions_arcade.'.$phpEx);

if (!phpbb_request_source_is_same_origin())
{
	message_die(GENERAL_ERROR, 'Cross-site score submissions are not accepted.');
}
phpbb_arcade_enforce_protocol_limit();
$arcade->arcade_config('use_rewards_mod');
//
//  Check Arcade Config and Include extra files.
//
$ip_num				     = decode_ip($userdata['session_ip']); 
$ip_nam				     = $ip_num;
$gen_simple_header = TRUE; 
$cheat_mode			   = FALSE;
$newscore_close    = $lang['newscore_close'];
$saved_text        = '';
$in_tournament     = FALSE;
$win               = '';
$user_name         = '';
$message_highscore = '';
//
// End session management 
//
//
//  Clear Arcade System vars (in case of register_global_vars being on)
//
unset($mode, $game_name, $score, $arcade_hash, $comment_id, $rate_id, $first_place_set, $allow_comment);
$comment_id      = '';
$rate_id         = '';
$first_place_set = FALSE;
$allow_comment   = FALSE;
$detected_score_type = null;
//
//  Collect as much passed data as we can :)
//
$mode             = $arcade->pass_var('mode', '', true);
$gscore           = $arcade->pass_var('gscore', 0, true);
$gname            = $arcade->pass_var('gname', '', true);
$score            = $arcade->pass_var('score', 0, true);
$gamename         = $arcade->pass_var('game_name', '', true);
//
//  Collect Arcade session information
//
$session_info     = $arcade->get_session();
$game_name		    = $session_info['game_name'];
$start_time		    = $session_info['start_time'];
$session_hash     = $session_info['arcade_hash'];
$arcade->user_id  = $session_info['user_id'];
if (($userdata['user_id'] == ANONYMOUS && (int) $session_info['user_id'] != ANONYMOUS) ||
	($userdata['user_id'] != ANONYMOUS && (int) $session_info['user_id'] !== (int) $userdata['user_id']))
{
	$arcade->message_die(GENERAL_ERROR, $lang['no_session_data'] . $lang['newscore_close']);
}
if (!is_scalar($session_hash) || !preg_match('/^[a-f0-9]{32}$/iD', (string) $session_hash))
{
	$arcade->message_die(GENERAL_ERROR, $lang['no_session_data'] . $lang['newscore_close']);
}
$session_game_name_sql = $db->sql_escape($session_info['game_name']);
//
//  Are we in the Tournament System ???
//
if($session_info['page'] == PAGE_ARCADE_TOUR)
{
  $saved_text = $lang['tour_save'];
  $arcade->tour_id = $session_info['tour_id'];
  $in_tournament = TRUE;
}
//
//  Load the Game Details saved in the sessions table
//
$sql = "SELECT * FROM " . iNA_GAMES . "
		WHERE game_name = '" . $session_game_name_sql . "' LIMIT 0,1";
if(!$result = $db->sql_query($sql)) 
{
	$arcade->message_die(GENERAL_ERROR, $lang['no_game_data'] . $lang['newscore_close'], "", __LINE__, __FILE__, $sql); 
}
$game_info	= $db->sql_fetchrow($result);
if (!$game_info || trim((string) $game_info['game_name']) === '')
{
	$arcade->message_die(GENERAL_ERROR, $lang['no_game_data'] . $lang['newscore_close']);
}
//
//  Now check the information passed
//
$get_score = phpbb_request_scalar($HTTP_GET_VARS, 'score');
$get_gscore = phpbb_request_scalar($HTTP_GET_VARS, 'gscore');
if(($get_score !== '' || $get_gscore !== '') && !$mode)
{
  $cheat_mode = TRUE;
}
//
//  Get the Required information for each game type
//
switch($game_info['score_type'])
{
//
//  Old Style Games (only works if cheat mode is off)
//
    case ARCADE_GET:
    	if ($mode && !$arcade->arcade_config['games_cheat_mode'])
    	{
        $arcade->game_name  = $arcade->pass_var('game_name', '', true);
        $arcade->score      = $arcade->pass_var('score', 0, true);
    	}
      else
      {
        $cheat_mode = TRUE;
      }
      break;
//
//  Whoo's Mode
//      
    case ARCADE_POST:
      $arcade->game_name  = $arcade->pass_var('game_name', '', true);
      $arcade->score      = $arcade->pass_var('score', 0, true);
      break;
//
//  dEfEndErs Mode
//      
    case ARCADE_NEW:
      if(!hash_equals((string) $session_hash, (string) $arcade->arcade_hash))
      {
        $cheat_mode = TRUE;
      }
      $arcade->game_name  = $arcade->pass_var('game_name', '', true);
      $arcade->score      = $arcade->pass_var('score', 0, true);
      break;
//
//  IBProArcade Games
// 
    case ARCADE_IBPRO:
      $arcade->game_name  = $arcade->pass_var('gname', '', true);
      $arcade->score      = $arcade->pass_var('gscore', 0, true);
      break;
//
//  IBProArcade v3 Games
//
    case ARCADE_IBPROv3:
      $arcade->score      = $arcade->pass_var('gscore', 0, true);
      break;
//
//  Mixed Mode (Used when game send differing info back)
//
    case ARCADE_MIXED:
      $arcade->game_name  = $arcade->pass_var('game_name', '', true);
      $arcade->score      = $arcade->pass_var('score', 0, true);
      break;
//
//  pnFlashGames Mode, used here for v1 support
//
    case ARCADE_pnFlashGames:
      $arcade->game_name  = $arcade->pass_var('game_name', '', true);
      $arcade->score      = $arcade->pass_var('score', 0, true);
      break;
//
//  VBulletin Arcade v3 Games
//
    case ARCADE_vBULLETIN:
      $arcade->game_name  = $arcade->pass_var('gamename', '', true);
      $arcade->score      = $arcade->pass_var('score', 0, true);
      break;  
//
//  Default will be Zero or Junk, so we need to see if the user is admin or mod
//
    default:
      $score_type = 0;
//
//  Here we have to work out what score saving method we are going
//  to save back to the game..!
//
        $arcade->game_name = $arcade->arcade_session['game_name'];
        $arcade->score = $score;

        if(!empty($gscore) && empty($gname))
        {
          $score_type = ARCADE_IBPROv3;
          $arcade->score = $gscore;
        }
        else if(!empty($gscore) && !empty($gname))
        {
          $score_type = ARCADE_IBPRO;
          $arcade->score = $gscore;
        }
        else if(!empty($arcade->arcade_hash) && preg_match('/^[a-f0-9]{32}$/i', $arcade->arcade_hash))
        {
          $score_type = ARCADE_NEW;
        }
        else if(!empty($HTTP_POST_VARS['gamename']))
        {
          $score_type = ARCADE_vBULLETIN;
        }
        else if(!empty($HTTP_POST_VARS['score']))
        {
          $score_type = ARCADE_POST;
        }
        else if(!empty($HTTP_GET_VARS['score']) && !empty($mode))
        {
          $score_type = ARCADE_GET;
        }
        else
        {
        	$score_type = ARCADE_MIXED;
        }

		$detected_score_type = (int) $score_type;
      break;
}
//  Final Check to see if we are getting the correct info back
//
if(($arcade->game_name != $game_name) && ($arcade->arcade_config['games_cheat_mode']))
{
//
//  OK, Huston we have a problem
//
//  Some games have incorrectly been set-up as WHOO or NEW method.
//
//  Lets Just check and see!!!
//
  $decoded_gname = html_entity_decode($gname, ENT_QUOTES, 'UTF-8');
  if(empty($arcade->game_name) && $decoded_gname !== '' && hash_equals((string) $game_name, (string) $decoded_gname))
  {
//
//  Looks like it...!
//    
	$arcade->game_name = $game_name;
	$detected_score_type = ARCADE_IBPRO;
  }
  else
  {
    $cheat_mode = TRUE;
  }
}
else
{
  $arcade->game_name = $game_name;
  if($arcade->score == 0)
  {
    $arcade->score = $gscore;
  }
}

if (!is_finite((float) $arcade->score) || abs((float) $arcade->score) > 9999999999.9999)
{
	$cheat_mode = TRUE;
}
else
{
	$arcade->score = round((float) $arcade->score, 4);
}

// Validated results claim their capability in the shared owned transaction.
//
//  Check to see if we know what mode to work in
//

if ($session_info['user_win'] == 'SELF')
{
  $win = 'SELF';
  $gen_simple_header = FALSE;
  $newscore_close =  $lang['newscore_return'];
}
//
// This will stop ***_ALL_*** template errors :)
//
$template->set_filenames(array('body' => 'arcade_saved_body.tpl')); 
//
//	Make sure that we have been given a Game Name to play with.
//
if(!empty($game_name))
{
	if(empty($user_name))
	{
		$user_name = $arcade->get_username($arcade->user_id); 
	}
	$arcade_user_id = (int) $arcade->user_id;
	$game_desc	= $game_info['game_desc'];
	$game_id	= (int) $game_info['game_id'];

	if ( ( ($arcade->user_id == ANONYMOUS) && !$game_info['allow_guest'] ) || ( $userdata['user_level'] != ADMIN && !$game_info['game_avail'] ) )
	{
		$cheat_mode = TRUE;
//
//	If a user ends up in here then they ARE cheating for sure :(
//
	}
	else if (($cheat_mode == FALSE) && ($game_info) && ($game_info['game_show_score'])) 
	{
//
//	User 'Test' can NOT submit a score.
//
		if ($arcade->score <> 0 && (strtoupper($user_name) != "TEST")) 
   	{ 
//
            //  All score data and the single-use capability commit together.
            require_once($phpbb_root_path.'includes/functions_arcade_submission.'.$phpEx);
            try
            {
                $submission=phpbb_arcade_submit_score($db,$game_info,$session_info,$arcade->score,'standard',$detected_score_type);
            }
            catch(PhpbbOwnedStorageFailure $error)
            {
                message_die(GENERAL_MESSAGE,$lang['arcade_submission_failed']);
            }
            phpbb_arcade_submission_notify($submission);
            $feedback=phpbb_arcade_submission_feedback($submission);
            $saved_text=$feedback['saved'];$first_place_set=$feedback['first'];$allow_comment=$feedback['comment'];$message_highscore=$feedback['monthly'];
            }
        else if ($arcade->score <= 0)
		{
  		$saved_text = $lang['game_no_score_saved'];
  	}
  	else
  	{
  		$saved_text = $lang['games_test_noscore'];
  	}
	} 
	else if (!$game_info['game_show_score'])
	{
		$saved_text = $lang['game_highscore_off'];
	}
		
	if($cheat_mode == TRUE && $arcade->arcade_config['games_cheat_mode'])
	{
		if (empty($user_name)) 
		{
			$user_name = $userdata['username'];
		}
		$ip_line = "Arcade Cheat @ [ NAME : " . $ip_nam . " ]--[ IP# : " . $ip_num . " ]--[USERNAME : " . $user_name . "][GAME : " . $game_name . "]";
		if ($arcade->arcade_config['report_cheater'])
		{
			mail($board_config['board_email'], str_replace(array("\r", "\n"), '', $game_name), $ip_line);
		}
		if ($arcade->arcade_config['warn_cheater'])
		{
			$saved_text = $lang['admin_cheater_warning'];
		}
		else
		{
			$saved_text .= $lang['error_game_info_data'];
			$log_value = $db->sql_escape(substr($game_name . '=>' . $arcade->game_name . ' ' . $score . '=>' . $arcade->score . ' ' . $gname . '=>' . $gscore, 0, 4000));
      $post_sql = "INSERT INTO " . iNA_LOG . " (user_id, name, value, date) 
          VALUES (" . (int) $userdata['user_id'] . ", 'BAD_GAME', '$log_value', " . time() . ")";
      $db->sql_query($post_sql);
		}
	}
	else if($cheat_mode == TRUE)
	{
		$saved_text .= $lang['error_game_info_data'];
		$log_value = $db->sql_escape(substr($game_name . '=>' . $arcade->game_name . ' ' . $score . '=>' . $arcade->score . ' ' . $gname . '=>' . $gscore, 0, 4000));
    $post_sql = "INSERT INTO " . iNA_LOG . " (user_id, name, value, date) 
       VALUES (" . (int) $userdata['user_id'] . ", 'GAME_ERROR', '$log_value', " . time() . ")";
    $db->sql_query($post_sql);
	}
}
else
{
	$saved_text .= $lang['no_game_data'];
	$log_value = $db->sql_escape(substr($game_name . '=>' . $arcade->game_name . ' ' . $score . '=>' . $arcade->score . ' ' . $gname . '=>' . $gscore, 0, 4000));
  $post_sql = "INSERT INTO " . iNA_LOG . " (user_id, name, value, date) 
     VALUES (" . (int) $userdata['user_id'] . ", 'GAME_ERROR', '$log_value', " . time() . ")";
  $db->sql_query($post_sql);
}
//
//  Build the Available Buttons :)
//
  if($win == 'SELF')
  {
    if(($arcade->arcade_config['games_comments'] == 1) && $arcade->user_id > 0 && $allow_comment == TRUE)
    {
      $comment_id = '&nbsp;<a href="arcade_comment.'.$phpEx.'?game_id='. $game_id  . '&amp;mode=add_comment"><img src="images/comments.gif" alt="'.$lang['games_add_comments'].'" title="'.$lang['games_add_comments'].'" border="0" /></a>';
    }
    if(($arcade->arcade_config['games_rate'] == 1) && $arcade->user_id > 0)
    {
      $rate_id =  '&nbsp;<a href="arcade_rate.'.$phpEx.'?game_id='. $game_id  . '"><img src="images/rate.gif" alt="'.$lang['games_rate'].'" title="'.$lang['games_rate'].'" border="0" /></a>';
    }
  }
  else
  {
    if(($arcade->arcade_config['games_comments'] == 1) && $arcade->user_id > 0 && $allow_comment == TRUE)
    {
      $comment_id = '&nbsp;<a href="javascript:self.close();opener.location=\'arcade_comment.'.$phpEx.'?game_id='. $game_id  . '&amp;mode=add_comment\';"><img src="images/comments.gif" alt="'.$lang['games_add_comments'].'" title="'.$lang['games_add_comments'].'" border="0" /></a>';
    }
    if(($arcade->arcade_config['games_rate'] == 1) && $arcade->user_id > 0)
    {
      $rate_id =  '&nbsp;<a href="javascript:self.close();opener.location=\'arcade_rate.'.$phpEx.'?game_id='. $game_id  . '\';"><img src="images/rate.gif" alt="'.$lang['games_rate'].'" title="'.$lang['games_rate'].'" border="0"></a>';
    }
  }
	$favorite_form = '';
	if ($arcade_user_id != ANONYMOUS)
	{
		$favorite_action = append_sid('activity.' . $phpEx . '?mode=fav&amp;id=' . $game_id);
		$favorite_label = htmlspecialchars($lang['games_add_fav'], ENT_QUOTES, 'UTF-8');
		$favorite_form = '<form method="post" action="' . htmlspecialchars($favorite_action, ENT_QUOTES, 'UTF-8') . '" style="display:inline">'
			. '<input type="hidden" name="sid" value="' . htmlspecialchars($userdata['session_id'], ENT_QUOTES, 'UTF-8') . '" />'
			. '<input type="image" src="images/favorite.gif" alt="' . $favorite_label . '" title="' . $favorite_label . '" />'
			. '</form>';
	}
//
//	Send to the Template the Required Fields.
//
$template->assign_vars(array(
	'SAVED'		=> $saved_text, 
	'GAME_NAME' => phpbb_profile_text($game_desc),
	'GAME_ID'	=> ($gen_simple_header == TRUE) ? "activity.".$phpEx."?mode=game&amp;id=". $game_id : "activity.".$phpEx."?mode=game&amp;id=". $game_id . "&amp;win=".$win,
	'FAV_FORM'	=> $favorite_form,
  'COMMENT_ID' => $comment_id,
  'RATE_ID' => $rate_id,
	'CLOSE'	=> ($first_place_set == TRUE || $in_tournament == TRUE) ? $lang['newscore_close_first'] : $newscore_close, 
	'PLAY_AGAIN' => ($in_tournament == TRUE) ? '' : $lang['games_play_again'],
  'HIGHSCORESAVED' => $message_highscore
	) ); 
//
//	Remove Yesterdays Session Info from Arcade Sessions Table.
//
$sql = "DELETE FROM " . iNA_SESSIONS . "
	WHERE start_time < '" . (time() - 86400) . "'";
if ( !$db->sql_query($sql) )
{
	$arcade->message_die(GENERAL_ERROR, $lang['session_error'] . $lang['newscore_close'], '', __LINE__, __FILE__, $sql);
}
//
// Scores were pruned and safely audited in the accepted transaction.
//
// Generate the page 
//
include($phpbb_root_path . 'includes/page_header.'.$phpEx); 
$template->pparse('body'); 
include($phpbb_root_path . 'includes/page_tail.'.$phpEx); 

?>
