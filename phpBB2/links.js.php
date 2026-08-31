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

$phpbb_root_path = "./";
include($phpbb_root_path . 'extension.inc');
include($phpbb_root_path . "common.$phpEx");

//
// gzip_compression
//
$do_gzip_compress = FALSE;
$accept_encoding = isset($_SERVER['HTTP_ACCEPT_ENCODING']) && is_scalar($_SERVER['HTTP_ACCEPT_ENCODING']) ? (string) $_SERVER['HTTP_ACCEPT_ENCODING'] : '';
if (!empty($board_config['gzip_compress']) && extension_loaded('zlib') && preg_match('/(?:^|,)\s*gzip\s*(?:;|,|$)/i', $accept_encoding))
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
// Grab data
//
$sql = "SELECT *
		FROM ". LINK_CONFIG_TABLE;
	if(!$result = $db->sql_query($sql))
	{
		message_die(GENERAL_ERROR, "Could not query Link config information", "", __LINE__, __FILE__, $sql);
	}
	
	$link_config = array();
	while( $row = $db->sql_fetchrow($result) )
	{
		$link_config_name = $row['config_name'];
		$link_config_value = $row['config_value'];
		$link_config[$link_config_name] = $link_config_value;
	}
	$link_self_img = isset($link_config['site_logo']) ? $link_config['site_logo'] : '';
	$site_logo_height = isset($link_config['height']) ? max(1, min(1000, intval($link_config['height']))) : 31;
	$site_logo_width = isset($link_config['width']) ? max(1, min(1000, intval($link_config['width']))) : 88;
	$display_interval = isset($link_config['display_interval']) ? max(250, intval($link_config['display_interval'])) : 5000;
	$display_logo_num = isset($link_config['display_logo_num']) ? max(1, intval($link_config['display_logo_num'])) : 1;

$sql = "SELECT link_id, link_title, link_logo_src
	FROM " . LINKS_TABLE . "
	WHERE link_active = 1
	ORDER BY link_hits DESC";

$links_logo = array();
// If failed just render an empty list.
if( $result = $db->sql_query($sql) )
{
	while($row = $db->sql_fetchrow($result))
	{
		$logo = trim((string) $row['link_logo_src']);
		$parts = @parse_url($logo);
		if ($parts && !empty($parts['host']) && !empty($parts['scheme']) &&
			in_array(strtolower($parts['scheme']), array('http', 'https'), true) &&
			!preg_match('/[\x00-\x20\x7f]/', $logo))
		{
			$links_logo[] = '<a href="' . htmlspecialchars(append_sid("links.$phpEx?action=go&amp;link_id=" . intval($row['link_id'])), ENT_QUOTES, 'UTF-8', false)
				. '" target="_blank" rel="noopener noreferrer"><img src="' . htmlspecialchars($logo, ENT_QUOTES, 'UTF-8')
				. '" alt="' . htmlspecialchars($row['link_title'], ENT_QUOTES, 'UTF-8', false) . '" width="' . $site_logo_width
				. '" height="' . $site_logo_height . '" border="0" hspace="1" /></a>';
		}
	}

}

$template->assign_vars(array(
	'S_CONTENT_ENCODING' => $lang['ENCODING'],
	'T_BODY_BGCOLOR' => '#'.$theme['td_color1'],
	'DISPLAY_INTERVAL' => $display_interval,
	'DISPLAY_LOGO_NUM' => $display_logo_num,
	'LINKS_LOGO' => json_encode($links_logo, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
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
