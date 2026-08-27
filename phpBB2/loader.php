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
if($arcade->arcade_config['games_offline'] && $userdata[user_level] != ADMIN)
{
	message_die(GENERAL_MESSAGE, $lang['games_are_offline']);
}
//
// Game Vars used below, extract from iNA_GAMES data.
//
$game_name		= $game_info['game_name'];
$game_desc		= $game_info['game_desc'];
$game_width		= isset($width) ? $width : intval($game_info['win_width']);
$game_height	= isset($height) ? $height : intval($game_info['win_height']);
$game_path		= $game_info['game_path'];
$game_flash		= $game_info['game_flash'];
$game_id		  = intval($game_info['game_id']);
$game_desc		= trim(htmlspecialchars($game_desc));
$game_desc		= substr(str_replace("\\'", "'", $game_desc), 0, 255);
$game_desc		= str_replace("'", "\\'", $game_desc);
$arcade_hash	= '';
$license      = $game_info['license'] ? $game_info['license'] : 'None';
$cat_id		    = (intval($game_info['cat_id']) > 0) ? intval($game_info['cat_id']) : -1;
//
//	Update Game Played amount.
//
//  Due to errors using the single statement UPDATE played SET player = played+1
//    first we get the number, add one, then update the number
//
$sql = "SELECT played, c.total_played, c.cat_parent FROM " . iNA_GAMES . ",
   " . iNA_CAT . " AS c
		WHERE game_id = $game_id
    AND c.cat_id = $cat_id";
if (!$result = $db->sql_query($sql))
{
	message_die(GENERAL_ERROR, $lang['no_game_update'], __LINE__, __FILE__, $sql);
}
$played_info = $db->sql_fetchrow($result);
$played = (intval($played_info['played']))+1;
$total_played = (intval($played_info['total_played']))+1;
$sql = "UPDATE " . iNA_GAMES . " g, " . iNA_CAT . " c
	SET g.played = " . $played . ", c.total_played = " . $total_played . ",  c.last_game = '" . $game_name . "', c.last_player = '" . $userdata['user_id'] . "', c.last_time = '" . time() . "'
		WHERE g.game_id = $game_id
    AND c.cat_id = $cat_id";
if (!$db->sql_query($sql))
{
	message_die(GENERAL_ERROR, $lang['no_game_update'], __LINE__, __FILE__, $sql);
}
if($played_info['cat_parent'] > 0)
{
  $sql = "SELECT total_played FROM " . iNA_CAT . " 
		WHERE cat_id = " . $played_info['cat_parent'];
  if (!$result = $db->sql_query($sql))
  {
  	message_die(GENERAL_ERROR, $lang['no_cat_update'], __LINE__, __FILE__, $sql);
  }
  $parent_info = $db->sql_fetchrow($result);
  $total_played = (intval($parent_info['total_played']))+1;
  $sql = "UPDATE " . iNA_CAT . "
    SET total_played = ". $total_played .", last_game = '" . $game_name . "', last_player = '" . $userdata['user_id'] . "', last_time = '" . time() . "'
      WHERE cat_id = " . $played_info['cat_parent'];
  if (!$db->sql_query($sql))
  {
  	message_die(GENERAL_ERROR, $lang['no_cat_update'], __LINE__, __FILE__, $sql);
  }
}
if($cat_id > 0)
{
  $sql = "SELECT total_played FROM " . iNA_CAT . " 
		WHERE cat_id = -1";
  if (!$result = $db->sql_query($sql))
  {
  	message_die(GENERAL_ERROR, $lang['no_cat_update'], __LINE__, __FILE__, $sql);
  }
  $played_info = $db->sql_fetchrow($result);
  $total_played = (intval($played_info['total_played']))+1;
  $sql = "UPDATE " . iNA_CAT . "
  	SET total_played = " . $total_played . ",  last_game = '" . $game_name . "', last_player = '" . $userdata['user_id'] . "', last_time = '" . time() . "'
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
	SET last_played = '" . $game_name . "', last_played_date = '" . (time()) . "'
		WHERE user_id = '" . $userdata['user_id'] . "'";
$result = $db->sql_query($sql);
if ( !$result )
{
	message_die(CRITICAL_ERROR, $lang['no_user_update'], '', __LINE__, __FILE__, $sql);
}
$affected_rows = $db->sql_affectedrows();
if ( $affected_rows < 1 )
{
	$sql = "INSERT INTO " . iNA_USER_DATA . "
		(user_id, last_played, last_played_date)
		VALUES ('" . $userdata['user_id'] . "', '$game_name', '" . (time()) . "')";
	if ( !$db->sql_query($sql) )
	{
		message_die(CRITICAL_ERROR, $lang['no_user_update'], '', __LINE__, __FILE__, $sql);
	}
}
//
// Check the extension of the game to see what we should do with it.
//
$extension = get_ina_extension($game_name);
switch ($extension)
{
//
//	Java File, Load Java Applet and Display.
//
	case 'class':
		$base_ref = '<base href="http://' . $board_config['server_name'] . $board_config['script_path'] . '/' . $game_path . '">';
		$object = '<APPLET CODE="'.$game_name.'" WIDTH="100%" HEIGHT=100%"></APPLET>';
		break;
//
//	Media File, Load Windows Media Player, (NOTE, Untested on Linux based Browsers)
//
	case 'mp3':
	case 'wma':
	case 'mpg':
	case 'avi':
	case 'wmv':
	case 'mpeg':
$object = '<object id="wmp" classid="CLSID:22d6f312-b0f6-11d0-94ab-0080c74c7e95" codebase="http://activex.microsoft.com/activex/controls/mplayer/en/nsmp2inf.cab#Version=6,0,0,0" standby="Loading Microsoft Windows Media Player components..." type="application/x-oleobject"> 
<param name="FileName" value="' . $game_path . $game_name . '"> 
<param name="ShowControls" value="1"> 
<param name="ShowDisplay" value="0"> 
<param name="ShowStatusBar" value="1"> 
<param name="AutoSize" value="1"> 
<param name="AutoStart" value="0"> 
<param name="Visible" value="1"> 
<param name="AnimationStart" value="0"> 
<param name="Loop" value="1"> 
<embed type="application/x-mplayer2" pluginspage="http://www.microsoft.com/windows/windowsmedia/download/default.asp" src="' . $game_path . $game_name . '" name=MediaPlayer2 showcontrols=1 showdisplay=0 showstatusbar=1 autosize=1 autostart=0 visible=1 animationatstart=0 loop=0></embed> 
</object>';
		break;

//
//  Not yet supported....!
//		
		case 'flv':
$object = '<object width="320" height="240" classid="clsid:d27cdb6e-ae6d-11cf-96b8-444553540000" codebase="http://fpdownload.macromedia.com/get/flashplayer/current/swflash.cab#version=9,0,47,0> 
<param name="flashvars" value="file=http://www.phpbb-arcade.com' . $game_path . $game_name . '" /> 
<param name="movie" value="http://www.phpbb-arcade.com/flvplayer.swf" /> 
<embed src="http://www.phpbb-arcade.com/flvplayer.swf" width="320" height="240" bgcolor="#FFFFFF" type="application/x-shockwave-flash" pluginspage="http://www.macromedia.com/go/getflashplayer" flashvars="file=http://www.phpbb-arcade.com' . $game_path . $game_name . '" /> 
</object>';
		break;
/*
<object width="320" height="240" classid="clsid:d27cdb6e-ae6d-11cf-96b8-444553540000" codebase="http://fpdownload.macromedia.com/pub/ 
shockwave/cabs/flash/swflash.cab#version=8,0,0,0"> 
<param name="flashvars" value="file=http://www.myhomepage.com/myvideofile.flv" /> 
<param name="movie" value="http://www.myhomepage.com/flvplayer.swf" /> 
<embed src="http://www.myhomepage.com/flvplayer.swf" width="320" height="240" bgcolor="#FFFFFF" type="application/x-shockwave-flash" pluginspage="http://www.macromedia.com/go/getflashplayer" flashvars="file=http://www.myhomepage.com/myvideofile.flv" /> 
</object>
*/
		
//
//	Image file, Simply Display it :)
//
	case 'gif':
	case 'jpg':
	case 'png':
		$object = '<img src="'.$game_path.$game_name.'">';
		break;
//
//	Real Media Networks Player
//
	case 'rpm':
	case 'rm':
$object = '<OBJECT ID=RVOCX CLASSID="clsid:CFCDAA03-8BE4-11cf-B84B-0020AFBBCCFA" width="100%" height="100%">
<PARAM NAME="SRC" VALUE="'.$game_path.$game_name.'">
<PARAM NAME="CONTROLS" VALUE="ImageWindow">
<PARAM NAME="CONSOLE" VALUE="one">
<EMBED SRC="' . $game_path . $game_name . '" width="100%" height="100%" NOJAVA=true CONTROLS=ImageWindow CONSOLE=one>
</EMBED> 
</OBJECT>';
		break;
//
//	QuickTime Player
//
	case 'mov':
$object = '<OBJECT CLASSID="clsid:02BF25D5-8C17-4B23-BC80-D3488ABDDC6B" WIDTH="' . $game_width . '"HEIGHT="' . $game_height . '" CODEBASE="http://www.apple.com/qtactivex/qtplugin.cab"> 
<PARAM name="SRC" VALUE="' . $game_path . $game_name . '"> 
<PARAM name="AUTOPLAY" VALUE="true"> 
<PARAM NAME="type" VALUE="video/quicktime">
<PARAM name="CONTROLLER" VALUE="true"> 
<EMBED SRC="' . $game_path . $game_name . '" width="100%" height="100%" AUTOPLAY="true" CONTROLLER="false" PLUGINSPAGE="http://www.apple.com/quicktime/download/"> 
</EMBED> 
</OBJECT>'; 
		break;
//
//	Macromedia Shockwave File, Load Shockwave and Display.
//
	case 'dcr':
	case 'dir':
$object = '<OBJECT CLASSID="clsid:166B1BCA-3F9C-11CF-8075-444553540000" codebase="http://download.macromedia.com/pub/shockwave/cabs/director/sw.cab#version=10,0,0,0" id="freegames" name="activitygame" width="100%" height="100%">
<param name="src" value="'.$game_path.$game_name.'">
<embed name=activitygame src="'.$game_path.$game_name.'" pluginspage="http://www.macromedia.com/shockwave/download/" width="100%" height="100%">"></embed> 
</object>';
		break;
//
//	Default mode for this is a FLASH game.
//
	default:
//
// To keep compatability with Napoleons Mod the game_flash setting is still used.
//		
		$arcade_hash = '?arcade_hash='.$session.'&game_id='.$game_id;

		if($game_flash)
		{
			$game_name = $game_name . '.swf';
		}
    if($game_info['score_type'] == ARCADE_pnFlashGames)
    {
      if($fp = @fopen($game_path.$game_name, 'r'))
      { 
        $filecontent = fread($fp, filesize($game_path.$game_name)); 
        fclose($fp); 
        $checksum = md5($filecontent); 
      }
      $arcade_hash = '?arcade_hash='.$session . '&pn_gid=' . $game_id . '&pn_uname=' . $userdata['username'] . '&pn_licence=' . $license . '&pn_checksum=' . $checksum . '&pn_domain=' . str_replace("www.", "", $board_config['server_name']) . '&pn_script=pnFlashGames.' . $phpEx . '&pn_modvalue=phpBBArcade' . '&pn_autoupdate=true';
    }

    if(!(@file_exists($game_path.$game_name)))
    {
      message_die(GENERAL_ERROR, sprintf($lang['arcade_file_not_found'],$game_path.$game_name));
    }
$object = '<script type="text/javascript" src="swfobject.js"></script>
<p id="arcade"></p><script type="text/javascript">
	var s1 = new SWFObject("'.$game_path.$game_name.$arcade_hash.'","single","'.$game_width.'","'.$game_height.'","7");
  s1.write("arcade");
</script>';  
/*
$object = '<OBJECT CLASSID="clsid:D27CDB6E-AE6D-11cf-96B8-444553540000" codebase="http://fpdownload.macromedia.com/get/flashplayer/current/swflash.cab#version=9,0,47,0" id="freegames" name="activitygame" width="'.$game_width.'" height="'.$game_height.'">
<param name="movie" value="'.$game_path.$game_name.$arcade_hash.'">
<param name="quality" value="high">
<param name="menu" value="false">
<embed name="'.$game_name.'" src="'.$game_path.$game_name.$arcade_hash.'" width="'.$game_width.'" height="'.$game_height.'" quality="high" menu="false" swliveconnect="true" type="application/x-shockwave-flash" pluginspage="http://www.macromedia.com/shockwave/download/index.cgi?P1_Prod_Version=ShockwaveFlash"></embed>
<noembed>You need the <a href="http://www.macromedia.com/shockwave/download/index.cgi?P1_Prod_Version=ShockwaveFlash" target="_blank">Flash 9</a> plugin to play this game.</noembed>
</object>';
*/
		break;
}
//
//	Set-up template ready for output
//
$game_title	= $game_desc . " - " . $board_config['sitename'];
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
  'GAME_NAME' => $game_desc,
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
