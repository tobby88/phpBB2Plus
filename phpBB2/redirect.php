<?php
/***************************************************************************
 *                               redirect.php
 *                            -------------------
 *   begin                :  Feb, 2003
 *   author               : Niels Chr. Denmark <ncr@db9.dk> (http://mods.db9.dk)
 *
 * version 1.0.0.
 *
 * History:
 *   0.9.0. - initial BETA
 *   0.9.1. - header added
 *   0.9.2. - added language support
 *   0.9.3. - corrected banner_id
 *   0.9.4. - added banner location to who-is online, if "topic in who-is-online MOD" is installed
 *   1.0.0. - changed cookie store procedure
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
require_once($phpbb_root_path . 'extension.inc');
require_once($phpbb_root_path . 'common.'.$phpEx);

$banner_id_value = isset($_POST['banner_id']) ? $_POST['banner_id'] : (isset($_GET['banner_id']) ? $_GET['banner_id'] : 0);
$banner_id = is_scalar($banner_id_value) ? max(0, (int) $banner_id_value) : 0;

//
// Start session management
//
$userdata = session_pagestart($user_ip, PAGE_REDIRECT, $banner_id);
init_userprefs($userdata);
//
// End session management
//

if ( $banner_id <= 0 )
{
	message_die(GENERAL_ERROR, "No banner id specified", "", __LINE__, __FILE__,"banner_id='".$banner_id."'"); 
}
$sql ="select * FROM ".BANNERS_TABLE." WHERE banner_id=".$banner_id;
if ( !($result = $db->sql_query($sql)) )
{
	message_die(GENERAL_ERROR, "Couldn't retrieve banner data", "", __LINE__, __FILE__, $sql);
}
$banner_data = $db->sql_fetchrow($result);
if (!$banner_data)
{
	message_die(GENERAL_ERROR, 'Unknown banner', '', __LINE__, __FILE__);
}
$redirect_url = trim((string) $banner_data['banner_url']);
$redirect_parts = @parse_url($redirect_url);
if (!$redirect_parts || empty($redirect_parts['host']) || empty($redirect_parts['scheme']) ||
	!in_array(strtolower($redirect_parts['scheme']), array('http', 'https'), true) ||
	isset($redirect_parts['user']) || isset($redirect_parts['pass']) ||
	preg_match('/[\x00-\x20\x7F\\\\]/', $redirect_url))
{
	message_die(GENERAL_ERROR, 'Invalid banner URL', '', __LINE__, __FILE__);
}
$cookie_name = $board_config['cookie_name'] . '_b_' . $banner_id;
if (!isset($HTTP_COOKIE_VARS[$cookie_name]))
{
	$filter_seconds = max(1, intval($banner_data['banner_filter_time']) ?: 600);
	$banner_filter_time = time() + $filter_seconds;
	phpbb_setcookie($cookie_name , 1 ,$banner_filter_time , $board_config['cookie_path'], $board_config['cookie_domain'], $board_config['cookie_secure']);
	$sql = "UPDATE " . BANNERS_TABLE . " SET banner_click = banner_click + 1 WHERE banner_id = " . intval($banner_id);
	if ( !($result = $db->sql_query($sql)) )
	{
		message_die(GENERAL_ERROR, "Couldn't update banner data", "", __LINE__, __FILE__, $sql);
	}
}
$click_ip_sql = $db->sql_escape($userdata['session_ip']);
$click_user_id = intval($userdata['user_id']);
$user_duration = max(0, intval($userdata['session_time']) - intval($userdata['session_start']) + intval($board_config['session_length']));
$sql = "INSERT INTO " . BANNER_STATS_TABLE . " (banner_id, click_date, click_ip, click_user, user_duration)
	VALUES (" . intval($banner_id) . ", " . time() . ", '$click_ip_sql', $click_user_id, $user_duration)";
if ( !($result = $db->sql_query($sql)) )
{
	message_die(GENERAL_ERROR, "Couldn't insert banner stats", "", __LINE__, __FILE__, $sql);
}

$db->sql_close();
header('Location: ' . $redirect_url, true, 302);
exit;
?>
