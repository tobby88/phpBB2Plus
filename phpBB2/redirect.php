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

// Keep the counter and detail row in one owned transaction. The caller may
// already have unrelated work on its connection; never commit or roll it back.
function phpbb_record_banner_click($database, $banner_id, $userdata, $session_length)
{
	$db = null; $claimed = false; $transaction = false;
	try
	{
		$result = $database->sql_query('SELECT DATABASE() schema_name,CONNECTION_ID() connection_id');
		if (!$result) { return false; }
		try { $source = $database->sql_fetchrow($result); } finally { $database->sql_freeresult($result); }
		$candidate = $database->sql_dedicated_connection();
		if (!($candidate instanceof sql_db) || $candidate === $database || !isset($candidate->db_connect_id)
			|| $candidate->db_connect_id === $database->db_connect_id) { return false; }
		$db = $candidate;
		$result = $db->sql_query('SELECT DATABASE() schema_name,CONNECTION_ID() connection_id');
		if (!$result) { return false; }
		try { $own = $db->sql_fetchrow($result); } finally { $db->sql_freeresult($result); }
		if (!$source || !$own || !$source['schema_name'] || $own['schema_name'] !== $source['schema_name']
			|| (string) $own['connection_id'] === (string) $source['connection_id']) { return false; }
		// This statement rejects an already active foreign transaction without
		// ending it. Claim the dedicated connection only after that succeeds.
		if (!$db->sql_query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED')) { return false; }
		$claimed = true;
		if (!$db->sql_query('SET SESSION innodb_lock_wait_timeout=5')
			|| !$db->sql_query("SET SESSION sql_mode=CONCAT_WS(',',@@SESSION.sql_mode,'STRICT_ALL_TABLES','NO_ENGINE_SUBSTITUTION')")
			|| !$db->sql_query('START TRANSACTION')) { return false; }
		$transaction = true;
		foreach (array(BANNERS_TABLE, BANNER_STATS_TABLE) as $table)
		{
			if (!is_string($table) || !preg_match('/^[A-Za-z0-9_]{1,64}$/D', $table)) { return false; }
			// Hold metadata locks until commit so the engine cannot change after
			// verification. Legacy nontransactional tables must be upgraded first.
			$result = $db->sql_query('SELECT banner_id FROM ' . $table . ' LIMIT 0');
			if (!$result) { return false; } $db->sql_freeresult($result);
			$result = $db->sql_query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND HEX(TABLE_NAME)=HEX('" . $db->sql_escape($table) . "') AND TABLE_TYPE='BASE TABLE'");
			if (!$result) { return false; }
			try { $rows = $db->sql_fetchrowset($result); } finally { $db->sql_freeresult($result); }
			if (!is_array($rows) || count($rows) !== 1 || strcasecmp($rows[0]['ENGINE'], 'InnoDB') !== 0) { return false; }
		}
		$sql = "UPDATE " . BANNERS_TABLE . " SET banner_click = banner_click + 1 WHERE banner_id = " . intval($banner_id);
		if (!$db->sql_query($sql) || (int) $db->sql_affectedrows() !== 1) { return false; }
		$click_ip_sql = $db->sql_escape($userdata['session_ip']);
		$click_user_id = intval($userdata['user_id']);
		$user_duration = max(0, intval($userdata['session_time']) - intval($userdata['session_start']) + intval($session_length));
		$sql = "INSERT INTO " . BANNER_STATS_TABLE . " (banner_id, click_date, click_ip, click_user, user_duration)
			VALUES (" . intval($banner_id) . ", " . time() . ", '$click_ip_sql', $click_user_id, $user_duration)";
		if (!$db->sql_query($sql) || !$db->sql_query('COMMIT')) { return false; }
		$transaction = false;
		return true;
	}
	catch (Exception $error) { return false; }
	catch (Error $error) { return false; }
	finally
	{
		if ($db && $claimed)
		{
			if ($transaction) { $db->sql_query('ROLLBACK'); }
			$db->sql_close();
		}
	}
}

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
$db->sql_freeresult($result);
if (!$banner_data)
{
	message_die(GENERAL_ERROR, 'Unknown banner', '', __LINE__, __FILE__);
}
$redirect_url = phpbb_normalize_external_url($banner_data['banner_url']);
if ($redirect_url === false)
{
	message_die(GENERAL_ERROR, 'Invalid banner URL', '', __LINE__, __FILE__);
}
// This read-like endpoint writes click statistics. Keep its own bucket so a
// crawler or forged refresh loop cannot grow the statistics table without
// bound and does not consume the user's post/comment allowance.
if (function_exists('ctracker_enforce_request_limit_profile'))
{
	ctracker_enforce_request_limit_profile(array('tracked-redirect', 300, 'request_limit_content', 60));
}
$cookie_name = $board_config['cookie_name'] . '_b_' . $banner_id;
if (!isset($HTTP_COOKIE_VARS[$cookie_name]))
{
	if (!phpbb_record_banner_click($db, $banner_id, $userdata, $board_config['session_length']))
	{
		message_die(GENERAL_ERROR, $lang['Banner_click_failed']);
	}
	// Consume the browser's click filter only after a confirmed complete save.
	$filter_seconds = max(1, intval($banner_data['banner_filter_time']) ?: 600);
	$banner_filter_time = time() + $filter_seconds;
	phpbb_setcookie($cookie_name , 1 ,$banner_filter_time , $board_config['cookie_path'], $board_config['cookie_domain'], $board_config['cookie_secure']);
}

$db->sql_close();
header('Location: ' . $redirect_url, true, 302);
exit;
?>
