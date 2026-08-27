<?php
/*************************************************************************** 
 *                          admin_arcade_reset.php 
 *                            ------------------- 
 *   begin                : 08/01/04
 *   copyright            : (C) 2005 Minesh Mistry & Ebaby
 *   website 1            : Support: www.phpbb-amod.co.uk
 *   website 2            : Demo and live site: www.gamelounge.co.uk
 *   version              : 1.0.1
 *   history              : Original by version Ebaby, Made in ACP panel By Minesh
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
$phpbb_root_path = './../';

if( !empty($setmodules) )
{
	return;
}

require($phpbb_root_path . 'extension.inc');
require('pagestart.' . $phpEx);

//
// Check to see what mode we should operate in.
// 
if( isset($HTTP_POST_VARS['mode']) || isset($HTTP_GET_VARS['mode']) )
{
	$mode = ( isset($HTTP_POST_VARS['mode']) ) ? $HTTP_POST_VARS['mode'] : $HTTP_GET_VARS['mode'];
	$mode = htmlspecialchars($mode);
}
else if( isset($HTTP_POST_VARS['home']) )
{
	$mode = "home";
}
else if( isset($HTTP_POST_VARS['reset_played']) )
{
	$mode = "reset_played";
}
else if( isset($HTTP_POST_VARS['reset_last_player']) )
{
	$mode = "reset_last_player";
}
else if( isset($HTTP_POST_VARS['reset_last_time']) )
{
	$mode = "reset_last_time";
}
else
{
	$mode = "";
}


//home 
if( $mode == "" || $mode == "home")
{
echo '
  <table width="99%" cellpadding="4" cellspacing="1" border="0" align="center" class="bodyline">
    <tr>
      <th width="20%" colspan="2" class="thHead">Reset Arcade Plays </th>
    </tr>
    <tr>
      <td colspan="2" class="row1"><div align="center"><span class=gensmall>Before reseting the arcade plays alway backup your database first.</span><br>
      </div></td>
    </tr>
    <tr>
      <td width="50%" class="row2"><div align="right">Reset Played Number </div></td>
      <td width="50%" class="row3"><a href="'. append_sid('admin_arcade_reset.php?mode=reset_played').'">Go!</a></td>
    </tr>
    <tr>
      <td width="50%" class="row2"><div align="right">Reset Last Player</div></td>
      <td width="50%" class="row3"><a href="'. append_sid('admin_arcade_reset.php?mode=reset_last_player').'" target="_self">Go!</a></td>
    </tr>
    <tr>
      <td width="50%" class="row2"><div align="right">Reset Last Time</div></td>
      <td width="50%" class="row3"><a href="'. append_sid('admin_arcade_reset.php?mode=reset_last_time').'" target="_self">Go!</a></td>
    </tr>
    <tr>
      <td colspan="2" align="center" class="catBottom">&nbsp;</td>
    </tr>
  </table>
  <table width="100%"  border="0" cellspacing="0" cellpadding="0">
  <tr>
    <td><div align="center"><span class=gensmall>Original version By Ebaby | Made in ACP panel By Minesh </SPAN></div></td>
  </tr>
</table>
';
}

//reset_played
if( $mode == "reset_played")
{
echo '<table width="100%" cellspacing="1" cellpadding="2" border="0" class="forumline">';
echo '<tr><th>Updating the database</th></tr><tr><td><span class="genmed"><ul type="circle">';


$sql = array();
$sql[] = "update " . $table_prefix . "ina_games set played = 0";

for( $i = 0; $i < count($sql); $i++ )
{
	if( !$result = $db->sql_query ($sql[$i]) )
	{
		$error = $db->sql_error();

		echo '<li>' . $sql[$i] . '<br /> +++ <font color="#FF0000"><b>Error:</b></font> ' . $error['message'] . '</li><br />';
	}
	else
	{
		echo '<li>' . $sql[$i] . '<br /> +++ <font color="#00AA00"><b>Successfull</b></font></li><br />';
	}
}
echo '<tr><td class="catBottom" height="28" align="center"><span class="genmed">Done</span></td></table>';
}

//reset_last_player
if( $mode == "reset_last_player")
{
echo '<table width="100%" cellspacing="1" cellpadding="2" border="0" class="forumline">';
echo '<tr><th>Updating the database</th></tr><tr><td><span class="genmed"><ul type="circle">';


$sql = array();
$sql[] = "update " . $table_prefix . "ina_cat set last_player = 0";

for( $i = 0; $i < count($sql); $i++ )
{
	if( !$result = $db->sql_query ($sql[$i]) )
	{
		$error = $db->sql_error();

		echo '<li>' . $sql[$i] . '<br /> +++ <font color="#FF0000"><b>Error:</b></font> ' . $error['message'] . '</li><br />';
	}
	else
	{
		echo '<li>' . $sql[$i] . '<br /> +++ <font color="#00AA00"><b>Successfull</b></font></li><br />';
	}
}
echo '<tr><td class="catBottom" height="28" align="center"><span class="genmed">Done</span></td></table>';
}

//reset_last_time
if( $mode == "reset_last_time")
{
echo '<table width="100%" cellspacing="1" cellpadding="2" border="0" class="forumline">';
echo '<tr><th>Updating the database</th></tr><tr><td><span class="genmed"><ul type="circle">';


$sql = array();
$sql[] = "update " . $table_prefix . "ina_cat set last_time = 0000000000";

for( $i = 0; $i < count($sql); $i++ )
{
	if( !$result = $db->sql_query ($sql[$i]) )
	{
		$error = $db->sql_error();

		echo '<li>' . $sql[$i] . '<br /> +++ <font color="#FF0000"><b>Error:</b></font> ' . $error['message'] . '</li><br />';
	}
	else
	{
		echo '<li>' . $sql[$i] . '<br /> +++ <font color="#00AA00"><b>Successfull</b></font></li><br />';
	}
}
echo '<tr><td class="catBottom" height="28" align="center"><span class="genmed">Done</span></td></table>';
}

// Generate footer
include('page_footer_admin.' . $phpEx);

?>
