<?php
/***************************************************************************
 *
 *                              pnFlashGames.php
 *                            ------------------
 *   begin                : Friday, Jan 5th, 20067
 *   copyright            : (c)2005 - 2007 www.phpbb-arcade.com
 *   email                : defenders_realm@yahoo.com
 *
 *   $Id: pnFlashGames.php, v1.1.0 2007/01/05 12:59:59 dEfEndEr Exp $
 *
 ***************************************************************************
 *
 * pnFlashGames Support for the phpBB Arcade Mod (c) 2005/2006 dEfEndEr
 *
 * Based on information from the pnFlashGames mod (c) Lee Easton
 *
 **************************************************************************/

define('IN_PHPBB', true);
$phpbb_root_path = './';
include_once($phpbb_root_path . 'extension.inc');
include_once($phpbb_root_path . 'common.'.$phpEx);
include_once($phpbb_root_path . 'includes/constants_arcade.'.$phpEx);
//
// Start session management
//
$userdata			= session_pagestart($user_ip, PAGE_ACTIVITY);
init_userprefs($userdata);
include_once($phpbb_root_path . 'includes/functions_arcade.'.$phpEx);
if (!phpbb_request_source_is_same_origin())
{
	print "&opSuccess=Missing info&endvar=1";
	exit;
}
$arcade_version = $arcade->arcade_config('version');
//
// End session management
//
//  Build the required information from what we have received.
//
$sql                    = '';
$mode                   = $arcade->pass_var('func', '');
$gameData               = $arcade->pass_var('gameData', '');
$game_id                = (int) $arcade->pass_var('gid' , 0);
$arcade->score          = $arcade->pass_var('score', 0);
$arcade->user_id        = $userdata['user_id'];
$arcade->arcade_hash    = $arcade->pass_var('arcade_hash', '');
$arcade_cookie_name     = $board_config['cookie_name'] . '_arcade';
$arcade->arcade_cookie  = isset($_COOKIE[$arcade_cookie_name]) ? $_COOKIE[$arcade_cookie_name] : '';
//
//  Process the pnFlashGame command
//
switch($mode)
{
  case "storeScore":
//
//  pnFlashGames Score save routine
//
    if($arcade->score > 0 && is_finite((float) $arcade->score) && abs((float) $arcade->score) <= 9999999999.9999 && ($userdata['user_id'] != ANONYMOUS || preg_match('/^[a-f0-9]{32}$/iD', $arcade->arcade_hash)))
    {
      $session_info = $arcade->get_session();
    }

    if(!empty($session_info))
    {
      $arcade->tour_id    = $session_info['tour_id'];
      $arcade->game_name  = $session_info['game_name'];
      $arcade->time_taken = time() - $session_info['start_time'];
      $arcade->score_type = ARCADE_pnFlashGames;
	  $session_hash_sql = $db->sql_escape($session_info['arcade_hash']);
	  $sql = "DELETE FROM " . iNA_SESSIONS . "
		WHERE arcade_hash = '" . $session_hash_sql . "'";
	  if (!$db->sql_query($sql) || (int) $db->sql_affectedrows() !== 1)
	  {
		print "&opSuccess=Missing info&endvar=1";
		break;
	  }
      $score_error = $arcade->newscore();
      if($score_error === '' && $session_info['page'] == PAGE_ARCADE_TOUR)
      {
        $arcade->tour_score();
      }
      if($score_error === '')
      {
        print "&opSuccess=true&endvar=1";
        break;
      }
    }
    print "&opSuccess=Missing info&endvar=1";

    break;

  case "loadGame":
//
//  Load a Saved Game (allows for continue option)
//
    if(($userdata['user_id'] != ANONYMOUS))
    {
	  $session_info = $arcade->get_session();
      if(!$session_info)
      {
        print "&opSuccess=Missing info&endvar=1";
        break;
      }

	  $session_game_name_sql = $db->sql_escape($session_info['game_name']);
      $sql = "SELECT gameData FROM " . iNA_SCORES . "
          WHERE player_id = " . (int) $arcade->user_id . "
          AND game_name = '" . $session_game_name_sql . "'";
      if(!($result = $db->sql_query($sql)))
      {
        $arcade->log_error(__LINE__, __FILE__, $sql);
      }
      $game_info = $db->sql_fetchrow($result);

	  print 'gameData=' . rawurlencode($game_info ? (string) $game_info['gameData'] : '') . "&opSuccess=true&endvar=1";
    }
    else
    {
      print "&opSuccess=Missing info&endvar=1";
    }

    break;

  case "saveGame":
//
//  Save a part, NOT THE SCORE, But the users position etc.
//
    if(($userdata['user_id'] != ANONYMOUS) && ($gameData))
    {
	  $session_info = $arcade->get_session();
      if(!$session_info)
      {
        print "&opSuccess=Missing info&endvar=1";
        break;
      }

	  $game_data_value = substr(html_entity_decode($gameData, ENT_QUOTES, 'UTF-8'), 0, 65535);
	  $game_data_sql = $db->sql_escape($game_data_value);
	  $session_game_name_sql = $db->sql_escape($session_info['game_name']);
	  $sql = "UPDATE " . iNA_SCORES . " SET gameData = '" . $game_data_sql . "'
		  WHERE player_id = " . (int) $userdata['user_id'] . "
		  AND game_name = '" . $session_game_name_sql . "'";
       if(!($result = $db->sql_query($sql)))
      {
        $arcade->log_error(__LINE__, __FILE__, $sql);
      }

      print "&opSuccess=true&endvar=1";
    }
    else
    {
      print "&opSuccess=Missing info&endvar=1";
    }

    break;

  default:
    print "&opSuccess=Missing info&endvar=1";

    break;

}
//
//  Arcade LOG
//
$log = 'pnFlashGames ';
foreach($HTTP_POST_VARS as $key => $value)
{
	if(is_scalar($value))
  {
		$log .= (string) $key . '=>' . (string) $value . ' ';
  }
}
$log = $db->sql_escape(substr($log, 0, 4000));
$sql = "INSERT INTO " . iNA_LOG . " (user_id, name, value, date)
  VALUES (".(int) $userdata['user_id'].", 'GAME', '$log', ".time().")";
$db->sql_query($sql);

?>
