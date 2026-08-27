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

define('IN_PHPBB', 1);
define('ARCADE_ADMIN', 1);
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
if( isset($HTTP_POST_VARS['mode']) || isset($HTTP_GET_VARS['mode']) )
{
	$mode = ( isset($HTTP_POST_VARS['mode']) ) ? $HTTP_POST_VARS['mode'] : $HTTP_GET_VARS['mode'];
	$mode = trim(htmlspecialchars($mode));
}

if( isset($HTTP_POST_VARS['game_charge']) )
{
	$mode = "game_charge";
}
else if( isset($HTTP_POST_VARS['game_bonus']) )
{
	$mode = "game_bonus";
}
else if( isset($HTTP_POST_VARS['at_game_bonus']) )
{
	$mode = "at_game_bonus";
}
else if( isset($HTTP_POST_VARS['game_reward']) )
{
	$mode = "game_reward";
}
else if( isset($HTTP_POST_VARS['highscore_limit']) )
{
	$mode = "highscore_limit";
}
else if( isset($HTTP_POST_VARS['at_highscore_limit']) )
{
	$mode = "at_highscore_limit";
}
else
{
	$mode = "";
}
//
//  Main Menu 
//
if( $mode == "")
{
  $template->set_filenames(array('body' => 'admin/arcade_set_body.tpl'));

  $template->assign_vars(array(
		'L_HEADER' => $lang['admin_set_header'],
		'L_INFO' => $lang['admin_set_info'],
		'SET_INFO' => $lang['admin_set'],
		'L_WARNING' => $lang['admin_set_warning'],
		
		'SET_ARCADE' => $lang['admin_set_arcade'],
		'SET_CHARGE' => $lang['admin_set_charge'],
		'SET_HIGHSCORE' => $lang['admin_set_highscore'],
		'SET_AT_HIGHSCORE' => $lang['admin_set_at_highscore'],
		
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
  echo '<table width="100%" cellspacing="1" cellpadding="2" border="0" class="forumline">';
  echo '<tr><th>Updating the database</th></tr><tr><td><span class="genmed"><ul type="circle">';


  $setcharge = $_POST[$mode];
  $setcharge = intval($setcharge);
  if ($setcharge == "")
  {
    $setcharge = "0";
  }
  
  $sql = "UPDATE `" . $table_prefix . "ina_games` SET $mode = $setcharge";

 	if( !$result = $db->sql_query ($sql) )
 	{
 		$error = $db->sql_error();

	  echo '<li>' . $sql . '<br /> +++ <font color="#FF0000"><b>Error:</b></font> ' . $error['message'] . '</li><br />';
 	}
 	else
 	{
 		echo '<li>' . $sql . '<br /> +++ <font color="#00AA00"><b>Successfull</b></font></li><br />';
 	}
  echo '<tr><td class="catBottom" height="28" align="center"><span class="genmed">Done<br /><br /><br /><form method="POST" action="'. append_sid('admin_arcade_set.php').'"><input type="Submit" name="Back" value="Back" /></form></span></td></table>';
}
//
// Generate footer
//
include('page_footer_admin.' . $phpEx);

?>
