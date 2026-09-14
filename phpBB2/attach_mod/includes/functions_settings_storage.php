<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(dirname(__DIR__)) . '/includes/functions_board_config.php';

function phpbb_attach_settings_fields($mode)
{
	if ($mode === 'manage') { return array('upload_dir','upload_img','topic_icon','display_order','max_filesize','attachment_quota',
		'max_filesize_pm','default_upload_quota','default_pm_quota','max_attachments','max_attachments_pm','disable_mod','allow_pm_attach',
		'attachment_topic_review','show_apcp','allow_ftp_upload','ftp_server','ftp_path','download_path','ftp_pasv_mode','ftp_user','ftp_pass'); }
	if ($mode === 'cats') { return array('img_display_inlined','img_create_thumbnail','img_min_thumb_filesize','use_gd2','img_imagick',
		'img_max_width','img_max_height','img_link_width','img_link_height'); }
	phpbb_acl_error('Board_config_invalid');
}

function phpbb_attach_settings_read($db)
{
	$values = array(); $result = $db->sql_query('SELECT config_name, config_value FROM ' . ATTACH_CONFIG_TABLE);
	if (!$result) { phpbb_acl_error('Board_config_failed'); }
	$rows = $db->sql_fetchrowset($result); $db->sql_freeresult($result);
	if (!is_array($rows)) { phpbb_acl_error('Board_config_failed'); }
	foreach ($rows as $row)
	{ $values[$row['config_name']] = $row['config_value']; }
	return $values;
}

function phpbb_attach_settings_values($request, $mode, $current)
{
	$allowed = array_flip(phpbb_attach_settings_fields($mode)); $values = array();
	$booleans = array('display_order','disable_mod','allow_pm_attach','attachment_topic_review','show_apcp','allow_ftp_upload',
		'ftp_pasv_mode','img_display_inlined','img_create_thumbnail','use_gd2');
	$sizes = array('max_filesize'=>'size','attachment_quota'=>'quota_size','max_filesize_pm'=>'pm_size');
	foreach ($request as $key => $encoded)
	{
		if ($key === 'sid' || $key === 'submit') { continue; }
		if ($key === 'mode') { if ($encoded !== $mode) { phpbb_acl_error('Board_config_invalid'); } continue; }
		if ($mode === 'manage' && in_array($key, $sizes, true))
		{ if (!is_string($encoded) || !in_array($encoded, array('b','kb','mb'), true)) { phpbb_acl_error('Board_config_invalid'); } continue; }
		if (!isset($allowed[$key]) || !is_string($encoded)) { phpbb_acl_error('Board_config_invalid'); }
		if (!array_key_exists($key, $current)) { phpbb_acl_error('Board_config_failed'); }
		// Decode common.php's quoting once. HTML escaping belongs to rendering,
		// not storage, and FTP credentials retain their exact surrounding bytes.
		$value = stripslashes($encoded);
		if (strlen($value) > 1020 || preg_match('//u', $value) !== 1 || preg_match('/[\x00\r\n]/', $value)
			|| preg_match_all('/./us', $value, $characters) > 255) { phpbb_acl_error('Board_config_invalid'); }
		if ($key !== 'ftp_user' && $key !== 'ftp_pass') { $value = trim($value); }
		if (in_array($key, $booleans, true) && !in_array($value, array('0','1'), true)) { phpbb_acl_error('Board_config_invalid'); }
		if (isset($sizes[$key]))
		{
			$unit = isset($request[$sizes[$key]]) ? $request[$sizes[$key]] : 'b';
			if (!is_string($unit) || !in_array($unit, array('b','kb','mb'), true) || !preg_match('/^[0-9]{1,16}(?:\.[0-9]{1,20})?$/D', $value))
			{ phpbb_acl_error('Board_config_invalid'); }
			$bytes = round((float)$value * ($unit === 'mb' ? 1048576 : ($unit === 'kb' ? 1024 : 1)));
			// File sizes also enter signed INT extension/attachment columns.
			// Quotas use wider arithmetic, bounded to exact IEEE-754 integers.
			$maximum = $key === 'attachment_quota' ? 9007199254740991.0 : 2147483647;
			if (!is_finite($bytes) || $bytes < 0 || $bytes > $maximum) { phpbb_acl_error('Board_config_invalid'); }
			$value = sprintf('%.0f', $bytes);
		}
		$integers = array('default_upload_quota'=>16777215,'default_pm_quota'=>16777215,'max_attachments'=>999,'max_attachments_pm'=>999,
			'img_max_width'=>9999,'img_max_height'=>9999,'img_link_width'=>9999,'img_link_height'=>9999,'img_min_thumb_filesize'=>2147483647);
		if (isset($integers[$key]))
		{
			if (!preg_match('/^[0-9]{1,10}$/D', $value) || (float)$value > $integers[$key]) { phpbb_acl_error('Board_config_invalid'); }
			$value = (string)(int)$value;
		}
		if (in_array($key, array('ftp_server','ftp_path','download_path'), true) && $value !== '/') { $value = rtrim($value, '/'); }
		$values[$key] = $value;
	}
	if (!$values) { phpbb_acl_error('Board_config_invalid'); }
	return $values;
}

function phpbb_attach_settings_display_size($bytes, $unit)
{
	$factor = $unit === 'mb' ? 1048576 : ($unit === 'kb' ? 1024 : 1);
	// Binary units divide exact integer bytes by powers of two. Preserve the
	// full fractional value, not the old two-decimal round-trip truncation.
	return rtrim(rtrim(number_format((float)$bytes / $factor, 20, '.', ''), '0'), '.');
}

class PhpbbAttachSettingsWriter extends PhpbbBoardConfigWriter
{
	var $mode;
	function __construct($database, $mode) { phpbb_attach_settings_fields($mode); $this->mode = $mode; parent::__construct($database); }
	function actor() { global $phpEx; return phpbb_acp_actor($this, 'admin_attachments.' . $phpEx . '?mode=' . $this->mode); }
}

function phpbb_attach_settings_save($database, $request, $mode)
{
	global $userdata, $attach_config, $phpbb_root_path;
	if (!is_array($request) || !isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST'
		|| empty($userdata['session_id']) || !is_string($userdata['session_id']) || !isset($request['sid'])
		|| !is_string($request['sid']) || !hash_equals($userdata['session_id'], $request['sid'])) { phpbb_acl_error('Session_invalid'); }
	$db = new PhpbbAttachSettingsWriter($database, $mode); $attempted = false;
	try
	{
		$db->actor(); $values = phpbb_attach_settings_values($request, $mode, phpbb_attach_settings_read($db));
		$db->sql_query("SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_ALL_TABLES')");
		$db->sql_query('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED'); $db->sql_query('START TRANSACTION');
		$tables = array(ATTACH_CONFIG_TABLE, USERS_TABLE, SESSIONS_TABLE, JR_ADMIN_TABLE);
		if ($mode === 'manage') { $tables[] = EXTENSION_GROUPS_TABLE; $tables[] = QUOTA_LIMITS_TABLE; }
		foreach ($tables as $table)
		{
			$result = $db->sql_query('SELECT * FROM ' . $table . ' LIMIT 0'); $db->sql_freeresult($result);
			$rows = phpbb_acl_rows($db, "SELECT ENGINE, ROW_FORMAT, TABLE_COLLATION FROM information_schema.TABLES t WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . $db->sql_escape($table) . "'"
				. " AND NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS c WHERE c.TABLE_SCHEMA=t.TABLE_SCHEMA AND c.TABLE_NAME=t.TABLE_NAME AND c.CHARACTER_SET_NAME IS NOT NULL"
				. " AND (c.CHARACTER_SET_NAME <> 'utf8mb4' OR c.COLLATION_NAME <> 'utf8mb4_unicode_ci'))");
			if (count($rows) !== 1 || $rows[0]['ENGINE'] !== 'InnoDB' || strtolower($rows[0]['ROW_FORMAT']) !== 'dynamic'
				|| $rows[0]['TABLE_COLLATION'] !== 'utf8mb4_unicode_ci') { phpbb_acl_error('Board_config_failed'); }
		}
		$db->actor(); $names = array(); foreach ($values as $key => $value) { $names[] = "'" . $db->sql_escape($key) . "'"; }
		$rows = phpbb_acl_rows($db, 'SELECT config_name, config_value FROM ' . ATTACH_CONFIG_TABLE . ' WHERE config_name IN (' . implode(',', $names) . ') ORDER BY config_name FOR UPDATE');
		$current = array(); foreach ($rows as $row) { $current[$row['config_name']] = $row['config_value']; }
		$values = phpbb_attach_settings_values($request, $mode, $current);
		foreach (array('default_upload_quota','default_pm_quota') as $key)
		{
			if (isset($values[$key]) && $values[$key] !== '0'
				&& !phpbb_acl_rows($db, 'SELECT quota_limit_id FROM ' . QUOTA_LIMITS_TABLE . ' WHERE quota_limit_id=' . (int)$values[$key] . ' LOCK IN SHARE MODE'))
			{ phpbb_acl_error('Board_config_invalid'); }
		}
		if (isset($values['max_filesize']) && $values['max_filesize'] !== $current['max_filesize'])
		{
			if (!preg_match('/^[0-9]{1,10}$/D', $current['max_filesize']) || (float)$current['max_filesize'] > 2147483647) { phpbb_acl_error('Board_config_failed'); }
			$old = (int)$current['max_filesize'];
			$result = $db->sql_query('SELECT group_id FROM ' . EXTENSION_GROUPS_TABLE . ' WHERE max_filesize=' . $old . ' ORDER BY group_id FOR UPDATE'); $db->sql_freeresult($result);
			$actor = $db->actor(); $attempted = true;
			$db->sql_query('UPDATE ' . EXTENSION_GROUPS_TABLE . ' SET max_filesize=' . (int)$values['max_filesize'] . ' WHERE max_filesize=' . $old . ' AND ' . $actor['guard']);
		}
		foreach ($values as $key => $value)
		{
			$actor = $db->actor(); $attempted = true;
			$db->sql_query('UPDATE ' . ATTACH_CONFIG_TABLE . " SET config_value='" . $db->sql_escape($value) . "' WHERE config_name='" . $db->sql_escape($key) . "' AND " . $actor['guard']);
		}
		$actor = $db->actor(); $sid = $db->sql_escape($userdata['session_id']);
		foreach (array('SELECT session_id FROM ' . SESSIONS_TABLE . " WHERE session_id='" . $sid . "' AND HEX(session_id)=HEX('" . $sid . "')",
			'SELECT user_id FROM ' . USERS_TABLE . ' WHERE user_id=' . (int)$actor['user_id'],
			'SELECT user_id FROM ' . JR_ADMIN_TABLE . ' WHERE user_id=' . (int)$actor['user_id']) as $sql)
		{ $result = $db->sql_query($sql . ' LOCK IN SHARE MODE'); $db->sql_freeresult($result); }
		$db->actor(); $stored = phpbb_attach_settings_read($db);
		foreach ($values as $key => $value) { if (!isset($stored[$key]) || (string)$stored[$key] !== $value) { phpbb_acl_error('Board_config_failed'); } }
		$db->sql_query('COMMIT'); $db->actor();
		foreach ($values as $key => $value) { $attach_config[$key] = $value; }
	}
	finally { $db->release(); if ($attempted) { @unlink($phpbb_root_path . 'cache/attach_config_data.cache'); } }
}
