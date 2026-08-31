<?php
/*************************************************************************** 
 *                          admin_arcade_set.php 
 *                          -------------------- 
 *   begin                : 17/05/06
 *   copyright            : (C) 2005 Minesh Mistry & Ebaby & dEfEndEr
 *   website 1            : Support: www.phpbb-arcade.com
 *   website 2            : Demo and live site: www.gamelounge.co.uk
 *   version              : 1.0.2
 *   history              : Original by version Ebaby,
 *                          Made in ACP panel By Minesh
 *                1.0.1     dEfEndEr - Removed 50% of code as it was redundant.
 *                1.0.2     dEfEndEr - Added Template Support + More Features
 * 
 *   note: removing the original copyright is illegal even you have modified 
 *         the code.  Just append yours if you have modified it. 
 ***************************************************************************/ 

/*************************************************************************** 
 * 
 *   This program is free software; you can redistribute it and/or modify 
 *   it under the terms of the GNU General Public License as published by 
 *   the Free Software Foundation; either version 2 of the License, or 
 *   (at your option) any later version. 
 * 
 ***************************************************************************/

if (!defined('IN_PHPBB')) { define('IN_PHPBB', true); }
if (!defined('ARCADE_ADMIN')) { define('ARCADE_ADMIN', 1); }
$phpbb_root_path = './../';

if( !empty($setmodules) )
{
	return;
}

require($phpbb_root_path . 'extension.inc');
require('pagestart.' . $phpEx);
//
//  Load the Arcade required files
//
include_once($phpbb_root_path . 'includes/functions_arcade.'.$phpEx);
//
//  Check the phpBB Arcade Mod version
//
$version = $arcade->version('./../');
//
//  Set filename
//
$file = basename(__FILE__);
//
// Check to see what mode we should operate in.
// 
$allowed_fields = array('game_charge', 'game_bonus', 'at_game_bonus', 'game_reward', 'highscore_limit', 'at_highscore_limit');
$mode = (isset($_GET['mode']) && is_scalar($_GET['mode'])) ? (string) $_GET['mode'] : '';
if (!in_array($mode, $allowed_fields, true))
{
	$mode = '';
}

$is_update = (isset($_SERVER['REQUEST_METHOD']) && strtoupper((string) $_SERVER['REQUEST_METHOD']) === 'POST' && $mode !== '' &&
	isset($_POST[$mode]) && is_scalar($_POST[$mode]));
if ($is_update)
{
	phpbb_admin_require_post_session();
}
//
//  Main Menu 
//
if (!$is_update)
{
  $template->set_filenames(array('body' => 'admin/arcade_set_body.tpl'));

  $template->assign_vars(array(
		'L_HEADER' => $lang['admin_set_header'],
		'L_INFO' => $lang['admin_set_info'],
		'SET_INFO' => $lang['admin_set'],
		'L_WARNING' => $lang['admin_set_warning'],
		'S_SESSION_FIELD' => phpbb_admin_session_field(),
		
		'SET_ARCADE' => $lang['admin_set_arcade'],
		'SET_CHARGE' => $lang['admin_set_charge'],
		'SET_HIGHSCORE' => $lang['admin_set_highscore'],
		'SET_AT_HIGHSCORE' => $lang['admin_set_at_highscore'],
		'SET_REWARD' => $lang['admin_set_reward'],
		'SET_HIGHSCORE_LIMIT' => $lang['admin_set_highscore_limit'],
		'SET_AT_HIGHSCORE_LIMIT' => $lang['admin_set_at_highscore_limit'],
		'L_SET' => $lang['admin_set_submit'],
		
    'VERSION' => $arcade->version,
		
		'S_SET_CHARGE' => append_sid("$file?mode=game_charge"),
		'S_SET_BONUS' => append_sid("$file?mode=game_bonus"),
		'S_SET_AT_BONUS' => append_sid("$file?mode=at_game_bonus"),
		'S_SET_REWARD' => append_sid("$file?mode=game_reward"),
    'S_SET_LIMIT' => append_sid("$file?mode=highscore_limit"),
    'S_SET_AT_LIMIT' => append_sid("$file?mode=at_highscore_limit")
		));
//
// Generate the Main page
//
  $template->pparse('body');
}
//
//  set DB
//
else //if( $mode == "set_charge")
{
	$value_input = trim((string) $_POST[$mode]);
	$field_maximums = array(
		'game_charge' => 4294967295,
		'game_reward' => 4294967295,
		'game_bonus' => 65535,
		'at_game_bonus' => 65535,
		'highscore_limit' => 2147483647,
		'at_highscore_limit' => 65535
	);
	if (!preg_match('/^\d+$/D', $value_input) || (float) $value_input > $field_maximums[$mode])
	{
		$message = $lang['admin_set_invalid'] . '<br /><br />' .
			sprintf($lang['admin_return_arcade'], '<a href="' . append_sid($file) . '">', '</a>');
		message_die(GENERAL_MESSAGE, $message);
	}

	$setting = (int) $value_input;
	$sql = "UPDATE " . iNA_GAMES . " SET $mode = $setting";
	if (!$db->sql_query($sql))
	{
		message_die(GENERAL_ERROR, $lang['admin_set_failed'], '', __LINE__, __FILE__, $sql);
	}

	$message = sprintf($lang['admin_set_updated'], $setting) . '<br /><br />' .
		sprintf($lang['admin_return_arcade'], '<a href="' . append_sid($file) . '">', '</a>');
	message_die(GENERAL_MESSAGE, $message);
}
//
// Generate footer
//
include('page_footer_admin.' . $phpEx);

?>
