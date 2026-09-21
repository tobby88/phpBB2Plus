<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_board_config.php';

class PhpbbModSettingsWriter extends PhpbbBoardConfigWriter
{
	function actor() { global $phpEx; return phpbb_acp_actor($this, 'admin_board_extend.' . $phpEx); }
}

function phpbb_mod_settings_values($request, $fields, $current)
{
	global $userdata;
	$allowed = array(); $values = array();
	foreach ($fields as $key => $field)
	{
		if (!empty($field['user_only']) || !empty($field['hide'])) { continue; }
		if (!preg_match('/^[a-z][a-z0-9_]*$/D', $key)) { phpbb_acl_error('Board_config_invalid'); }
		$allowed[$key] = $field;
		if (!empty($field['user']) && isset($userdata[$field['user']]))
		{ $allowed[$key . '_over'] = array('type' => 'LIST_RADIO', 'values' => array('0', '1')); }
		// Missing required rows are migration errors, never implicit INSERTs.
		if (!array_key_exists($key, $current) || (!empty($field['user']) && !array_key_exists($key . '_over', $current)))
		{ phpbb_acl_error('Mod_settings_update_required'); }
	}
	foreach ($request as $key => $encoded)
	{
		if (in_array($key, array('sid', 'submit', 'menu_id', 'mod_id', 'sub_id'), true)) { continue; }
		if (!isset($allowed[$key]) || !is_string($encoded)) { phpbb_acl_error('Board_config_invalid'); }
		$value = stripslashes($encoded); $field = $allowed[$key];
		if (strlen($value) > 255 || strpos($value, "\0") !== false || preg_match('//u', $value) !== 1)
		{ phpbb_acl_error('Board_config_invalid'); }
		switch ($field['type'])
		{
			case 'LIST_RADIO': case 'LIST_DROP':
				if (!in_array($value, array_map('strval', $field['values']), true)) { phpbb_acl_error('Board_config_invalid'); }
				break;
			case 'TINYINT': case 'SMALLINT': case 'MEDIUMINT': case 'INT':
				// These legacy type names select form widths, not SQL column types:
				// config_value is VARCHAR. Preserve valid three/five/eight-digit
				// settings (e.g. a 365-day announcement) from the existing form.
				$limits = array('TINYINT' => 999, 'SMALLINT' => 99999, 'MEDIUMINT' => 99999999, 'INT' => 2147483647);
				if (!preg_match('/^[0-9]{1,10}$/D', $value) || (float)$value > $limits[$field['type']]) { phpbb_acl_error('Board_config_invalid'); }
				$value = (string)(int)$value;
				break;
			case 'VARCHAR': case 'TEXT': case 'HTMLVARCHAR': case 'HTMLTEXT': case 'DATEFMT':
				$value = trim($value); break;
			default: phpbb_acl_error('Board_config_invalid');
		}
		$values[$key] = $value;
	}
	if (!$values) { phpbb_acl_error('Board_config_invalid'); }
	return $values;
}

function phpbb_mod_settings_save($database, $request, $fields)
{
	global $userdata, $phpbb_root_path, $board_config;
	if (!is_array($request) || !isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST'
		|| empty($userdata['session_id']) || !is_string($userdata['session_id']) || !isset($request['sid'])
		|| !is_string($request['sid']) || !hash_equals($userdata['session_id'], $request['sid'])) { phpbb_acl_error('Session_invalid'); }
	$db = new PhpbbModSettingsWriter($database);
	$invalidate = false;
	try
	{
		$db->actor();
		phpbb_mod_settings_values($request, $fields, phpbb_board_config_read($db));
		$db->sql_query("SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_ALL_TABLES')");
		$db->sql_query('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
		$db->sql_query('START TRANSACTION');
		foreach (array(CONFIG_TABLE, USERS_TABLE, SESSIONS_TABLE, JR_ADMIN_TABLE) as $table)
		{
			$result = $db->sql_query('SELECT * FROM ' . $table . ' LIMIT 0'); $db->sql_freeresult($result);
			$rows = phpbb_acl_rows($db, "SELECT ENGINE, ROW_FORMAT, TABLE_COLLATION FROM information_schema.TABLES t WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . $db->sql_escape($table) . "'"
				. " AND NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS c WHERE c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME='" . $db->sql_escape($table) . "' AND c.CHARACTER_SET_NAME IS NOT NULL"
				. " AND (c.CHARACTER_SET_NAME <> 'utf8mb4' OR c.COLLATION_NAME <> 'utf8mb4_unicode_ci'))");
			if (count($rows) !== 1 || $rows[0]['ENGINE'] !== 'InnoDB' || strtolower($rows[0]['ROW_FORMAT']) !== 'dynamic'
				|| $rows[0]['TABLE_COLLATION'] !== 'utf8mb4_unicode_ci') { phpbb_acl_error('Board_config_failed'); }
		}
		$db->actor();
		// Lock all required rows in this section, not the entire configuration.
		// This prevents a required override disappearing between validation/write.
		$names = array();
		foreach ($fields as $key => $field)
		{
			if (!empty($field['user_only']) || !empty($field['hide'])) { continue; }
			$names[] = "'" . $db->sql_escape($key) . "'";
			if (!empty($field['user'])) { $names[] = "'" . $db->sql_escape($key . '_over') . "'"; }
		}
		$rows = phpbb_acl_rows($db, 'SELECT config_name, config_value FROM ' . CONFIG_TABLE . ' WHERE config_name IN (' . implode(',', $names) . ') ORDER BY config_name FOR UPDATE');
		$current = array(); foreach ($rows as $row) { $current[$row['config_name']] = $row['config_value']; }
		$values = phpbb_mod_settings_values($request, $fields, $current);
		foreach ($values as $key => $value)
		{
			$actor = $db->actor();
			$invalidate = true;
			$db->sql_query('UPDATE ' . CONFIG_TABLE . " SET config_value='" . $db->sql_escape($value) . "' WHERE config_name='" . $db->sql_escape($key) . "' AND " . $actor['guard']);
		}
		// Recheck and serialize the short commit phase against revocation.
		$actor = $db->actor(); $sid = $db->sql_escape($userdata['session_id']);
		foreach (array('SELECT session_id FROM ' . SESSIONS_TABLE . " WHERE session_id='" . $sid . "' AND HEX(session_id)=HEX('" . $sid . "')",
			'SELECT user_id FROM ' . USERS_TABLE . ' WHERE user_id=' . (int)$actor['user_id'],
			'SELECT user_id FROM ' . JR_ADMIN_TABLE . ' WHERE user_id=' . (int)$actor['user_id']) as $sql)
		{ $result = $db->sql_query($sql . ' LOCK IN SHARE MODE'); $db->sql_freeresult($result); }
		$db->actor(); $stored = phpbb_board_config_read($db);
		foreach ($values as $key => $value) { if (!isset($stored[$key]) || (string)$stored[$key] !== $value) { phpbb_acl_error('Board_config_failed'); } }
		// The shared owner checks authority before the acknowledged COMMIT,
		// not afterwards when a new session/permission change could win.
		$db->sql_query('COMMIT');
		foreach ($values as $key => $value) { $board_config[$key] = $value; }
	}
	finally
	{
		$db->release();
		// A lost COMMIT acknowledgement can mean the new values are durable.
		// Evict even on uncertain completion; doing so after rollback is harmless.
		if ($invalidate) { @unlink($phpbb_root_path . 'cache/config_data.cache'); }
	}
}
