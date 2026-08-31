<?php
/***************************************************************************
 *
 *                                 loader.php
 *                                 ----------
 *   begin                : Monday, Jan 8th, 2007
 *   copyright            : (c) 2003-2006 dEfEndEr
 *   email                : defenders_realm@yahoo.com
 *
 *   $Id: loader.php,v 2.2.0 2007/01/08 20:59:59 dEfEndEr Exp $
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
 *   This is a MOD for phpbb v2+. The phpbb group has all rights to the 
 *   phpbb source. They can be contacted at :
 *   
 *      I-Net : www.phpbb.com
 *      E-Mail: support@phpbb.com
 * 
 *	Credit to Napoleon for original version.
 ***************************************************************************/

//
//  Make sure we are running from within phpBB
//
if (!defined('IN_PHPBB'))
{
  die();
}
//
// CMake Sure we are being called from within the Arcade System
//
if (!defined('iNA') || !defined('iNA_TOUR_PLAY') || !defined('ARCADE_FUNCTIONS'))
{
	message_die(GENERAL_ERROR, $lang['arcade_incorrect']);
}
//
//  See if we are running in _self mode
//
if ($win != 'SELF')
{
  $gen_simple_header = TRUE; 
	$width = "100%";
	$height = "100%";
	$url = '';
}
unset($game_hash, $arcade_hash);
//
//	Check to see if the Mod is offline, this will allow you to change your activities without taking the whole board down.
//
if($arcade->arcade_config['games_offline'] && $userdata['user_level'] != ADMIN)
{
	message_die(GENERAL_MESSAGE, $lang['games_are_offline']);
}
//
// Game Vars used below, extract from iNA_GAMES data.
//
$game_name		= trim((string) $game_info['game_name']);
$game_desc		= trim((string) $game_info['game_desc']);
$game_width		= (isset($width) && is_numeric($width)) ? intval($width) : intval($game_info['win_width']);
$game_height	= (isset($height) && is_numeric($height)) ? intval($height) : intval($game_info['win_height']);
$game_width		= ($game_width > 0) ? min($game_width, 4096) : 550;
$game_height	= ($game_height > 0) ? min($game_height, 4096) : 450;
$game_path		= trim((string) $game_info['game_path']);
$game_flash		= $game_info['game_flash'];
$game_id		  = intval($game_info['game_id']);
$game_desc		= substr(str_replace("\\'", "'", $game_desc), 0, 255);
$game_desc_html = phpbb_profile_text($game_desc);
$game_name_sql = $db->sql_escape($game_name);
$user_id = (int) $userdata['user_id'];
$now = time();
$arcade_hash	= '';
$license      = !empty($game_info['license']) ? $game_info['license'] : 'None';
$cat_id		    = (intval($game_info['cat_id']) > 0) ? intval($game_info['cat_id']) : -1;
//
//	Update Game Played amount.
//
// Keep counters atomic so simultaneous game starts cannot overwrite each other.
$sql = "UPDATE " . iNA_GAMES . "
	SET played = played + 1
		WHERE game_id = $game_id";
if (!$db->sql_query($sql))
{
	message_die(GENERAL_ERROR, $lang['no_game_update'], __LINE__, __FILE__, $sql);
}

$sql = "SELECT cat_parent FROM " . iNA_CAT . "
		WHERE cat_id = $cat_id";
if (!$result = $db->sql_query($sql))
{
	message_die(GENERAL_ERROR, $lang['no_game_update'], __LINE__, __FILE__, $sql);
}
$played_info = $db->sql_fetchrow($result);
$sql = "UPDATE " . iNA_CAT . "
	SET total_played = total_played + 1, last_game = '$game_name_sql', last_player = $user_id, last_time = $now
		WHERE cat_id = $cat_id";
if (!$db->sql_query($sql))
{
	message_die(GENERAL_ERROR, $lang['no_game_update'], __LINE__, __FILE__, $sql);
}
if($played_info && intval($played_info['cat_parent']) > 0)
{
	$parent_id = (int) $played_info['cat_parent'];
  $sql = "UPDATE " . iNA_CAT . "
    SET total_played = total_played + 1, last_game = '$game_name_sql', last_player = $user_id, last_time = $now
      WHERE cat_id = $parent_id";
  if (!$db->sql_query($sql))
  {
  	message_die(GENERAL_ERROR, $lang['no_cat_update'], __LINE__, __FILE__, $sql);
  }
}
if($cat_id > 0)
{
  $sql = "UPDATE " . iNA_CAT . "
	SET total_played = total_played + 1, last_game = '$game_name_sql', last_player = $user_id, last_time = $now
  		WHERE cat_id = -1";
  if (!$db->sql_query($sql))
  {
  	message_die(GENERAL_ERROR, $lang['no_cat_update'], __LINE__, __FILE__, $sql);
  }
}
//
// Update the users data
//
$sql = "UPDATE " . iNA_USER_DATA . "
	SET last_played = '$game_name_sql', last_played_date = $now
		WHERE user_id = $user_id";
$result = $db->sql_query($sql);
if ( !$result )
{
	message_die(CRITICAL_ERROR, $lang['no_user_update'], '', __LINE__, __FILE__, $sql);
}
$affected_rows = $db->sql_affectedrows();
if ( $affected_rows < 1 )
{
	// A no-op UPDATE also reports zero rows. Check existence before inserting.
	$sql = "INSERT INTO " . iNA_USER_DATA . "
		(user_id, last_played, last_played_date)
		SELECT $user_id, '$game_name_sql', $now
		FROM DUAL
		WHERE NOT EXISTS (SELECT 1 FROM " . iNA_USER_DATA . " WHERE user_id = $user_id)";
	if ( !$db->sql_query($sql) )
	{
		message_die(CRITICAL_ERROR, $lang['no_user_update'], '', __LINE__, __FILE__, $sql);
	}
}
//
// Check the extension of the game to see what we should do with it.
//
$game_file = $game_name;
if ($game_flash && get_ina_extension($game_file) === '')
{
	$game_file .= '.swf';
}
$extension = get_ina_extension($game_file);
$asset_path = phpbb_arcade_local_asset(rtrim($game_path, '/') . '/' . ltrim($game_file, '/'));
if ($asset_path === '' || !is_file($phpbb_root_path . $asset_path))
{
	$missing_asset = ($asset_path !== '') ? $asset_path : $game_path . $game_file;
	message_die(GENERAL_ERROR, sprintf($lang['arcade_file_not_found'], phpbb_profile_text($missing_asset)));
}
$asset_url = phpbb_profile_text($asset_path);
$base_ref = '';
switch ($extension)
{
	// Current browsers can render common media files without external plugins.
	case 'mp3':
	case 'ogg':
	case 'wav':
	case 'm4a':
		$object = '<audio controls preload="metadata" src="' . $asset_url . '"><a href="' . $asset_url . '">' . $lang['arcade_media_fallback'] . '</a></audio>';
		break;

	case 'mp4':
	case 'webm':
	case 'ogv':
	case 'mov':
	case 'flv':
	case 'mpeg':
	case 'mpg':
		$object = '<video controls preload="metadata" width="' . $game_width . '" height="' . $game_height . '" src="' . $asset_url . '"><a href="' . $asset_url . '">' . $lang['arcade_media_fallback'] . '</a></video>';
		break;
		
	case 'gif':
	case 'jpg':
	case 'jpeg':
	case 'png':
	case 'webp':
		$object = '<img src="' . $asset_url . '" alt="' . $game_desc_html . '" />';
		break;
	// Ruffle replaces the retired browser Flash plugin for SWF games.
	case 'swf':
		$arcade_hash = '?arcade_hash='.$session.'&game_id='.$game_id;
    if($game_info['score_type'] == ARCADE_pnFlashGames)
    {
		$checksum = md5_file($phpbb_root_path . $asset_path);
		if ($checksum === false)
		{
			message_die(GENERAL_ERROR, sprintf($lang['arcade_file_not_found'], $asset_url));
		}
      $arcade_hash = '?arcade_hash=' . rawurlencode($session) . '&pn_gid=' . $game_id . '&pn_uname=' . rawurlencode($userdata['username']) . '&pn_licence=' . rawurlencode($license) . '&pn_checksum=' . $checksum . '&pn_domain=' . rawurlencode(str_replace("www.", "", $board_config['server_name'])) . '&pn_script=' . rawurlencode('pnFlashGames.' . $phpEx) . '&pn_modvalue=phpBBArcade&pn_autoupdate=true';
    }
$ruffle_url = phpbb_profile_text($asset_path . $arcade_hash);
$ruffle_title = $game_desc_html;
$ruffle_error = htmlspecialchars($lang['arcade_ruffle_error'], ENT_QUOTES, 'UTF-8');
$object = '<div class="arcade-ruffle-player" data-swf="' . $ruffle_url . '" data-width="' . intval($game_width) . '" data-height="' . intval($game_height) . '" data-title="' . $ruffle_title . '" data-error-message="' . $ruffle_error . '"><p>' . $lang['arcade_ruffle_loading'] . '</p></div>
<script type="text/javascript" src="assets/ruffle/arcade-player.js"></script>';
		break;
	default:
		$unsupported_format = ($extension !== '') ? $extension : $game_file;
		$object = '<p>' . sprintf($lang['arcade_format_unsupported'], phpbb_profile_text($unsupported_format)) . '</p>';
		break;
}
//
//	Set-up template ready for output
//
$game_title	= $game_desc_html . " - " . phpbb_profile_text($board_config['sitename']);
if ($win == 'SELF')
{
  $template->set_filenames(array('body' => 'arcade_game_body_self.tpl'));
}
else
{
  $template->set_filenames(array('body' => 'arcade_game_body.tpl'));
}

$width = ceil(($game_width/1000)*33.33);

$template->assign_vars(array(
  'URL' => $url,
  'WIDTH' => $width.'%',
  'GAME_NAME' => $game_desc_html,
	'OBJECT' => $object,
	'TITLE'  => $game_title,
	'BASE_REF' => $base_ref,
	'ARCADE_MOD' => sprintf($lang['activitiy_mod_info'], $arcade->version),
 ));
//
// Output page
//
if ($win == 'SELF')
{
  $page_title = $game_title;
 require "includes/page_header.".$phpEx;
}
$template->pparse('body');
if ($win == 'SELF')
{
 require "includes/page_tail.".$phpEx;
}

?>
