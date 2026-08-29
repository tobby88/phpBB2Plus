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
$arcade_version = $arcade->arcade_config('version');
//
// End session management
//
//  Build the required information from what we have received.
//
$sql                    = '';
$mode                   = $arcade->pass_var('func', '');
$gameData               = $arcade->pass_var('gameData', '');
$game_id                = $arcade->pass_var('gid' , 0);
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
    if($arcade->score > 0 && ($userdata['user_id'] != ANONYMOUS || preg_match('/^[a-f0-9]{32}$/i', $arcade->arcade_hash)))
    {
      $session_info = $arcade->get_session();
    }

    if(!empty($session_info))
    {
      $arcade->tour_id    = $session_info['tour_id'];
      $arcade->game_name  = $session_info['game_name'];
      $arcade->time_taken = time() - $session_info['start_time'];
      $arcade->score_type = ARCADE_pnFlashGames;
      $score_error = $arcade->newscore();
      if($score_error === '' && $session_info['page'] == PAGE_ARCADE_TOUR)
      {
        $arcade->tour_score();
      }
      if($score_error === '')
      {
        $sql = "DELETE FROM " . iNA_SESSIONS . "
          WHERE arcade_hash = '" . $session_info['arcade_hash'] . "'";
        $db->sql_query($sql);
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
      $sql = "SELECT * FROM " . iNA_SESSIONS . "
        WHERE user_id = '" . $arcade->user_id . "'
          LIMIT 0,1";
      if(!($result = $db->sql_query($sql)))
      {
        $arcade->log_error(__LINE__, __FILE__, $sql);
      }
      $session_info = $db->sql_fetchrow($result);
      if(!$session_info)
      {
        print "&opSuccess=Missing info&endvar=1";
        break;
      }

      $sql = "SELECT gameData FROM " . iNA_SCORES . "
          WHERE player_id = " . $arcade->user_id . "
          AND game_name = '" . $session_info['game_name'] . "'";
      if(!($result = $db->sql_query($sql)))
      {
        $arcade->log_error(__LINE__, __FILE__, $sql);
      }
      $game_info = $db->sql_fetchrow($result);

      print 'gameData=' . rawurlencode($game_info['gameData']) . "&opSuccess=true&endvar=1";
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
      $sql = "SELECT * FROM " . iNA_SESSIONS . "
        WHERE user_id = '" . $userdata['user_id'] . "'
          LIMIT 0,1";
      if(!($result = $db->sql_query($sql)))
      {
        $arcade->log_error(__LINE__, __FILE__, $sql);
      }
      $session_info = $db->sql_fetchrow($result);
      if(!$session_info)
      {
        print "&opSuccess=Missing info&endvar=1";
        break;
      }

      $sql = "UPDATE " . iNA_SCORES . " SET gameData = '" . $gameData . "'
          WHERE player_id = " . $userdata['user_id'] . "
          AND game_name = '" . $session_info['game_name'] . "'";
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
  if(!is_array($value))
  {
    $log .= $key . '=>' . $value . ' ';
  }
}
$log = mysqli_real_escape_string($db->db_connect_id, substr($log, 0, 4000));
$sql = "INSERT INTO " . iNA_LOG . " (user_id, name, value, date)
  VALUES (".(int) $userdata['user_id'].", 'GAME', '$log', ".time().")";
$db->sql_query($sql);

?>
