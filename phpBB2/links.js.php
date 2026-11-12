<?php
/***************************************************************************
*							links.js.php
*                            -------------------
*  MOD add-on page. Contains GPL code copyright of phpBB group.
*  Author: OOHOO < webdev@phpbb-tw.net >
*  Author: Stefan2k1 and ddonker from www.portedmods.com
*  Demo: http://phpbb-tw.net/
*  Version: 1.0.X - 2002/03/22 - for phpBB RC serial, and was named Related_Links_MOD
*  Version: 1.1.0 - 2002/04/25 - Re-packed for phpBB 2.0.0, and renamed to Links_MOD
*  Version: 1.2.0 - 2003/06/15 - Enhanced and Re-packed for phpBB 2.0.4
*  Version: 1.2.1 - 2003/10/15 - Enhanced by CRLin
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
define('SESSION_BACKGROUND', true);

$phpbb_root_path = "./";
include($phpbb_root_path . 'extension.inc');
include($phpbb_root_path . "common.$phpEx");

//
// gzip_compression
//
$do_gzip_compress = FALSE;
$accept_encoding = isset($_SERVER['HTTP_ACCEPT_ENCODING']) && is_scalar($_SERVER['HTTP_ACCEPT_ENCODING']) ? (string) $_SERVER['HTTP_ACCEPT_ENCODING'] : '';
if (!empty($board_config['gzip_compress']) && extension_loaded('zlib') && phpbb_accepts_gzip($accept_encoding))
{
	$do_gzip_compress = TRUE;
	ob_start();
	ob_implicit_flush(0);
	header('Vary: Accept-Encoding', false);
	header('Content-Encoding: gzip');
}

header ("Cache-Control: no-store, no-cache, must-revalidate");
header ("Cache-Control: pre-check=0, post-check=0, max-age=0", false);
header ("Pragma: no-cache");
header("Content-Type: text/html; charset=UTF-8");
header ("Expires: " . gmdate("D, d M Y H:i:s", time()) . " GMT");
header ("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");

//
// Start session management
//
$userdata = session_pagestart($user_ip, PAGE_INDEX);
init_userprefs($userdata);
//
// End session management
//

$template->set_filenames(array(
	'body' => "links_js_body.tpl"
));

//
// Read the optional widget from one fresh read-only snapshot.
require_once $phpbb_root_path . 'includes/functions_link_rotation.' . $phpEx;
try { $rotation = phpbb_link_rotation($db, $phpEx); }
catch (Exception $error) { $rotation = phpbb_link_rotation_empty(); }
catch (Error $error) { $rotation = phpbb_link_rotation_empty(); }
$payload = json_encode($rotation['logos'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$background = isset($theme['td_color1']) && is_string($theme['td_color1']) && preg_match('/^(?:[a-f0-9]{3}|[a-f0-9]{6})$/iD', $theme['td_color1']) ? $theme['td_color1'] : 'eaedf4';
$template->assign_vars(array(
    'S_CONTENT_ENCODING'=>'UTF-8', 'T_BODY_BGCOLOR'=>'#'.$background,
    'DISPLAY_INTERVAL'=>$rotation['interval'], 'DISPLAY_LOGO_NUM'=>$rotation['visible'],
    'SITE_LOGO_WIDTH'=>$rotation['width'], 'SITE_LOGO_HEIGHT'=>$rotation['height'],
    'LINKS_LOGO'=>is_string($payload) ? $payload : '[]'
));
$template->pparse("body");

$db->sql_close();
//
// Compress buffered output if required
// and send to browser
//
if($do_gzip_compress)
{
	//
	// Borrowed from php.net!
	//
	$gzip_contents = ob_get_contents();
	ob_end_clean();

	echo gzencode($gzip_contents, 9);
}

exit;
?>
