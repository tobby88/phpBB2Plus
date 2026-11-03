<?php 
/*************************************************************************** 
*                               album_pic.php 
*                            ------------------- 
*   begin                : Wednesday, February 05, 2003 
*   copyright            : (C) 2003 Smartor 
*   email                : smartor_xp@hotmail.com 
* 
*   $Id: album_pic.php,v 2.0.5 2003/02/28 14:33:12 ngoctu Exp $ 
* 
***************************************************************************/ 

/*************************************************************************** 
* 
*   This program is free software; you can redistribute it and/or modify 
*   it under the terms of the GNU General Public License as published by 
*   the Free Software Foundation; either version 2 of the License, or 
*   (at your option) any later version. 
* 
***************************************************************************/ 

  /***************************************************************************
 *                            MODIFICATIONS
 *                           ---------------
 *   started            : Saturday, January 18, 2004
 *   copyright          : © Volodymyr (CLowN) Skoryk
 *   email              : blaatimmy72@yahoo.com
 *	 version            : 1.5
 *
 *	 MODIFICATIONS:
 *		-added watermark support
 *   
 *
 ***************************************************************************/

define('IN_PHPBB', true); 
$phpbb_root_path = './'; 
$album_root_path = $phpbb_root_path . 'album_mod/'; 
include($phpbb_root_path . 'extension.inc'); 
include($phpbb_root_path . 'common.'.$phpEx); 

// 
// Start session management 
// 
$userdata = session_pagestart($user_ip, PAGE_ALBUM); 
init_userprefs($userdata); 
// 
// End session management 
// 


// 
// Get general album information 
// 
include($album_root_path . 'album_common.'.$phpEx); 

if 	(defined('ALBUM_SP_CONFIG_TABLE'))
{
// 
// Function for watermark 
// 
function mergePics($sourcefile, $insertfile, $pos = 0, $transition = 50, $filetype = '')
{
	if (!is_string($sourcefile) || !is_string($insertfile) || !is_string($filetype) ||
		strpos($sourcefile, "\0") !== false || strpos($insertfile, "\0") !== false ||
		strpos($sourcefile, '://') !== false || strpos($insertfile, '://') !== false ||
		!in_array($filetype, array('.jpg', '.png'), true)) { return false; }
	$decoder = $filetype === '.png' ? 'imagecreatefrompng' : 'imagecreatefromjpeg';
	$encoder = $filetype === '.png' ? 'imagepng' : 'imagejpeg';
	if (!function_exists('imagecreatefrompng') || !function_exists($decoder) || !function_exists($encoder) || !function_exists('imagecopymerge')) { return false; }
	$source_info = @getimagesize($sourcefile); $insert_info = @getimagesize($insertfile);
	if (!$source_info || !$insert_info || $source_info[2] !== ($filetype === '.png' ? IMAGETYPE_PNG : IMAGETYPE_JPEG) || $insert_info[2] !== IMAGETYPE_PNG ||
		!phpbb_image_dimensions_safe($source_info[0], $source_info[1]) || !phpbb_image_dimensions_safe($insert_info[0], $insert_info[1])) { return false; }
	$memory = trim((string) ini_get('memory_limit'));
	if ($memory !== '-1')
	{
		if (!preg_match('/^([0-9]+)\s*([KMG]?)$/iD', $memory, $limit)) { return false; }
		$power = array(''=>0, 'K'=>1, 'M'=>2, 'G'=>3);
		$bytes = (float) $limit[1] * pow(1024, $power[strtoupper($limit[2])]);
		$decoded = ((float) $source_info[0] * $source_info[1] + (float) $insert_info[0] * $insert_info[1]) * 6;
		if (memory_get_usage(true) + $decoded + 10485760 >= $bytes) { return false; }
	}
	$pos = (is_int($pos) || is_string($pos)) && preg_match('/^[0-8]$/D', (string) $pos) ? (int) $pos : 0;
	if (!(is_int($transition) || is_string($transition)) || !preg_match('/^[0-9]{1,3}$/D', (string) $transition)) { return false; }
	$transition = max(0, min(100, (int) $transition));
	$sourcefile_id = $insertfile_id = $buffer = false;
	try
	{
		$sourcefile_id = @$decoder($sourcefile); $insertfile_id = @imagecreatefrompng($insertfile);
		if (!$sourcefile_id || !$insertfile_id) { return false; }
		$dx = $source_info[0] - $insert_info[0]; $dy = $source_info[1] - $insert_info[1];
		$dest_x = in_array($pos, array(2, 3, 6), true) ? $dx : (in_array($pos, array(0, 5, 7), true) ? $dx / 2 : 0);
		$dest_y = in_array($pos, array(3, 4, 7), true) ? $dy : (in_array($pos, array(0, 6, 8), true) ? $dy / 2 : 0);
		// Preserve legacy truncation, including a watermark larger than its image.
		if (!@imagecopymerge($sourcefile_id, $insertfile_id, (int) $dest_x, (int) $dest_y, 0, 0, $insert_info[0], $insert_info[1], $transition)) { return false; }
		$buffer = @fopen('php://temp/maxmemory:2097152', 'w+b');
		if (!$buffer) { return false; }
		$written = $filetype === '.png' ? @imagepng($sourcefile_id, $buffer) : @imagejpeg($sourcefile_id, $buffer, 75);
		if (!$written || !is_resource($buffer) || !rewind($buffer)) { return false; }
		$head = fread($buffer, 8); $end = $filetype === '.png' ? 12 : 2;
		if (fseek($buffer, -$end, SEEK_END) !== 0) { return false; }
		$tail = fread($buffer, $end);
		if ($filetype === '.png' ? ($head !== "\x89PNG\r\n\x1a\n" || $tail !== "\0\0\0\0IEND\xae\x42\x60\x82") : (substr($head, 0, 2) !== "\xff\xd8" || $tail !== "\xff\xd9")) { return false; }
		if (!rewind($buffer)) { return false; }
		// Emit only a complete watermark result. Never append an unmarked fallback.
		fpassthru($buffer);
		return true;
	}
	finally
	{
		if (is_resource($buffer)) { fclose($buffer); }
		phpbb_gd_release($sourcefile_id); phpbb_gd_release($insertfile_id);
	}
} 
// 
// END: Function for watermark 
// 
}

// ------------------------------------ 
// Check the request 
// ------------------------------------ 
if( isset($_GET['pic_id']) && is_scalar($_GET['pic_id']) )
{ 
   $pic_id = intval($_GET['pic_id']); 
} 
else if( isset($_POST['pic_id']) && is_scalar($_POST['pic_id']) )
{ 
   $pic_id = intval($_POST['pic_id']); 
} 
else 
{ 
   message_die(GENERAL_MESSAGE, 'No pics specified'); 
} 


//--- Album category Hierarchy : begin
//--- version : 1.1.0
// ------------------------------------ 
// Get this pic info and current category info
// ------------------------------------ 
$sql = "SELECT p.*, c.*
		FROM ". ALBUM_TABLE ." AS p, ". ALBUM_CAT_TABLE ." AS c
		WHERE pic_id = '$pic_id'
	  		AND c.cat_id = p.pic_cat_id";

if( !$result = $db->sql_query($sql) ) 
{ 
   message_die(GENERAL_ERROR, 'Could not query pic information', '', __LINE__, __FILE__, $sql); 
} 

$thispic = $db->sql_fetchrow($result); 
$db->sql_freeresult($result); 

if( empty($thispic) )
{
   message_die(GENERAL_MESSAGE, $lang['Pic_not_exist']);
}

$cat_id = $thispic['pic_cat_id'];
$album_user_id = $thispic['cat_user_id'];

$stored_pic_filename = str_replace('\\', '/', (string) $thispic['pic_filename']);
$pic_filename = basename($stored_pic_filename);
$stored_pic_thumbnail = str_replace('\\', '/', (string) $thispic['pic_thumbnail']);
$pic_thumbnail = ($stored_pic_thumbnail === '') ? '' : basename($stored_pic_thumbnail);
if ($pic_filename === '' || $pic_filename !== $stored_pic_filename ||
	($stored_pic_thumbnail !== '' && $pic_thumbnail !== $stored_pic_thumbnail))
{
	message_die(GENERAL_MESSAGE, 'The filename data in the DB was corrupted');
}
$pic_filetype = strtolower(substr($pic_filename, -4));

if( !file_exists(ALBUM_UPLOAD_PATH . $pic_filename) )
{ 
   message_die(GENERAL_MESSAGE, $lang['Pic_not_exist']); 
} 


// ------------------------------------ 
// Check the permissions 
// ------------------------------------ 
$album_user_access = album_permissions($album_user_id, $cat_id, ALBUM_AUTH_VIEW, $thispic);

if( $album_user_access['view'] == 0 ) 
{ 
   message_die(GENERAL_MESSAGE, $lang['Not_Authorised']); 
} 


// ------------------------------------ 
// Check Pic Approval 
// ------------------------------------ 

if( $userdata['user_level'] != ADMIN ) 
{ 
   if( ($thispic['cat_approval'] == ADMIN) or (($thispic['cat_approval'] == MOD) and !$album_user_access['moderator']) ) 
   { 
      if( $thispic['pic_approval'] != 1 ) 
      { 
         message_die(GENERAL_MESSAGE, $lang['Not_Authorised']); 
      } 
   } 
} 

//--- Album category Hierarchy : end
// ------------------------------------ 
// Check hotlink 
// ------------------------------------ 
if (($album_config['hotlink_prevent'] == 1) && isset($HTTP_SERVER_VARS['HTTP_REFERER']) &&
   !phpbb_referer_is_allowed($HTTP_SERVER_VARS['HTTP_REFERER'], $board_config['server_name'], $album_config['hotlink_allowed']))
{
   message_die(GENERAL_MESSAGE, $lang['Not_Authorised']);
} 


/* 
+---------------------------------------------------------- 
| Main work here... 
+---------------------------------------------------------- 
*/ 


// ------------------------------------ 
// Increase view counter 
// ------------------------------------ 
$sql = "UPDATE ". ALBUM_TABLE ." 
      SET pic_view_count = pic_view_count + 1 
      WHERE pic_id = '$pic_id'"; 
if( !$result = $db->sql_query($sql) ) 
{ 
   message_die(GENERAL_ERROR, 'Could not update pic information', '', __LINE__, __FILE__, $sql); 
} 


// ------------------------------------ 
// Okay, now we can send image to the browser 
// ------------------------------------ 
switch( $pic_filetype ) 
{ 
   case '.png': 
      header('Content-type: image/png'); 
      break; 

   case '.gif': 
      header('Content-type: image/gif'); 
      break; 

   case '.jpg': 
      header('Content-type: image/jpeg'); 
      break; 

   default: 
      message_die(GENERAL_MESSAGE, 'The filename data in the DB was corrupted'); 
} 

if (defined('ALBUM_SP_CONFIG_TABLE'))
{
// -------------------------------------------------------- 
// Okay, now we insert the watermark for unregistered users 
// -------------------------------------------------------- 
if( $pic_filetype != '.gif' && (!$userdata['session_logged_in'] || $userdata['user_level'] == USER) && $album_sp_config['use_watermark'] == 1) 
{ 
   $position  = $album_sp_config['disp_watermark_at']; 
   $transition = 50; 

   $sourcefile = ALBUM_UPLOAD_PATH . $pic_filename;
   $insertfile = $album_root_path  . 'mark.png'; 
   if (!mergePics($sourcefile, $insertfile, $position, $transition, $pic_filetype))
   {
      http_response_code(503); header('Content-Type: text/plain; charset=UTF-8'); header('Cache-Control: no-store, private');
      echo 'Watermarked image rendering is temporarily unavailable.';
   }
}
else if ($pic_filetype != '.gif' && $album_sp_config['wut_users'] == 1 && $album_sp_config['use_watermark'] == 1)
{
   $position  = $album_sp_config['disp_watermark_at']; 
   $transition = 70; 

   $sourcefile = ALBUM_UPLOAD_PATH . $pic_filename;
   $insertfile = $album_root_path  . 'mark.png'; 
   if (!mergePics($sourcefile, $insertfile, $position, $transition, $pic_filetype))
   {
      http_response_code(503); header('Content-Type: text/plain; charset=UTF-8'); header('Cache-Control: no-store, private');
      echo 'Watermarked image rendering is temporarily unavailable.';
   }
}
else 
{ 
   readfile(ALBUM_UPLOAD_PATH . $pic_filename);
} 
}
else
   readfile(ALBUM_UPLOAD_PATH . $pic_filename);
exit; 


// +-------------------------------------------------------------+
// |  Powered by Photo Album 2.x.x (c) 2002-2003 Smartor         |
// |  with Volodymyr (CLowN) Skoryk's Service Pack 1 © 2003-2004 |
// +-------------------------------------------------------------+

?>
