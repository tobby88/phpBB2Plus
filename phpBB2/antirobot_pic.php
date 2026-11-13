<?php
/***************************************************************************
 *                           antirobot_pic.php
 *                           -------------------
 *   begin                : Friday, August 02, 2002
 *   copyright            : (C) 2002 Smartor
 *   email                : smartor_xp@hotmail.com
 *
 *   $Id: antirobot_pic.php,v 1.0.2 2003/02/07, 13:11:45 hoangngoctu Exp $
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


define('IN_PHPBB', true);
$phpbb_root_path = './';
include($phpbb_root_path . 'extension.inc');
include($phpbb_root_path . 'common.'.$phpEx);

//
// Start session management
//
$userdata = session_pagestart($user_ip, PAGE_PROFILE);
init_userprefs($userdata);
//
// End session management
//

require_once $phpbb_root_path . 'includes/functions_antirobot.' . $phpEx;
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
if (!isset($_GET['id'],$_GET['robot_id']) || !is_string($_GET['id']) || !preg_match('/^[1-5]$/D',$_GET['id']) || !is_string($_GET['robot_id']))
{
    http_response_code(404); exit;
}
$id=(int)$_GET['id'];
try { $code=phpbb_antirobot_code($db,$userdata,$_GET['robot_id']); }
catch (Exception $failure) { $code=false; } catch (Error $failure) { $code=false; }
if ($code===false) { http_response_code(404); exit; }
$char=$code[$id-1];

$image_directory = @realpath($phpbb_root_path . 'images/anti_robotic_reg');
$image_file = ($image_directory !== false) ? @realpath($image_directory . DIRECTORY_SEPARATOR . 'anti_robotic_reg_' . $char . '.gif') : false;
$image_directory_prefix = ($image_directory !== false) ? rtrim(str_replace('\\', '/', $image_directory), '/') . '/' : '';
$image_file_normalized = ($image_file !== false) ? str_replace('\\', '/', $image_file) : '';
if ($image_file === false || !@is_file($image_file) || strpos($image_file_normalized, $image_directory_prefix) !== 0)
{
	http_response_code(404); exit;
}

// No Cache
header ("Expires: Sat, 10 Dec 1983 07:00:00 GMT");
header ("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
header ("Cache-Control: no-store, private, max-age=0");  // HTTP/1.1
header ("Pragma: no-cache");                          // HTTP/1.0

// Send Image
header('Content-Disposition: inline; filename=smartor.gif');
header('Content-type: image/gif');
header('X-Content-Type-Options: nosniff');
readfile($image_file);

?>
