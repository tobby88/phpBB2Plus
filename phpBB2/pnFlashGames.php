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
phpbb_arcade_enforce_protocol_limit();
$arcade_version = $arcade->arcade_config('version');
//
// End session management
//
//  Build the required information from what we have received.
//
$sql                    = '';
$mode                   = $arcade->pass_var('func', '', true);
$arcade->score          = $arcade->pass_var('score', 0, true);
$arcade->user_id        = $userdata['user_id'];
$arcade->arcade_hash    = $arcade->pass_var('arcade_hash', '', true);
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
	        $score_error = $arcade->newscore();
      if($score_error === '')
      {
        print "&opSuccess=true&endvar=1";
        break;
      }
    }
    print "&opSuccess=Missing info&endvar=1";

    break;

  case "loadGame":
  case "saveGame":
    require_once($phpbb_root_path.'includes/functions_arcade_progress_storage.'.$phpEx);
    try {
      $hash = phpbb_arcade_progress_request($HTTP_GET_VARS, $HTTP_POST_VARS, 'arcade_hash', '');
      $gid = phpbb_arcade_progress_request($HTTP_GET_VARS, $HTTP_POST_VARS, 'gid', '0');
      $data = $mode === 'saveGame' ? phpbb_arcade_progress_request($HTTP_GET_VARS, $HTTP_POST_VARS, 'gameData') : null;
      $saved = phpbb_arcade_progress_transfer($db, $mode, $hash, $gid, $data);
      print ($mode === 'loadGame' ? 'gameData='.rawurlencode($saved) : '')."&opSuccess=true&endvar=1";
    } catch (Exception $error) { print "&opSuccess=Missing info&endvar=1"; }
      catch (Error $error) { print "&opSuccess=Missing info&endvar=1"; }
    break;

  default:
    print "&opSuccess=Missing info&endvar=1";

    break;

}
?>
