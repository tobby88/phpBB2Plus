<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_maintenance_dates.php';

// Internal defaults only, not a submitted configuration map. Never infer a
// completed database upgrade from the version of the currently deployed code.
function dbmtnc_config_defaults($defaults)
{
	global $board_config;
	if (!is_array($defaults)) { phpbb_acl_error('Maintenance_config_failed'); }
	unset($defaults['version']);
	foreach ($defaults as $key => $value)
	{
		if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]{0,254}$/D', $key) || !(is_string($value) || is_int($value))) { phpbb_acl_error('Maintenance_config_failed'); }
	}
	$defaults['board_disable'] = '1'; // A lost availability setting needs review.
	$defaults['cookie_secure'] = phpbb_request_is_https() ? '1' : '0';
	$host = phpbb_request_scalar($_SERVER, 'SERVER_NAME', phpbb_request_scalar($_SERVER, 'HTTP_HOST', ''));
	$defaults['server_name'] = phpbb_normalize_host($host, isset($board_config['server_name']) ? $board_config['server_name'] : $defaults['server_name']);
	$defaults['server_port'] = (string) phpbb_normalize_port(phpbb_request_scalar($_SERVER, 'SERVER_PORT', ''), $defaults['server_port']);
	$script = phpbb_request_scalar($_SERVER, 'SCRIPT_NAME', phpbb_request_scalar($_SERVER, 'PHP_SELF', ''));
	$parent = dirname($script);
	$path = strtolower(basename(str_replace('\\', '/', $parent))) === 'admin' ? dirname($parent) : $parent;
	$defaults['script_path'] = phpbb_normalize_script_path($path, isset($board_config['script_path']) ? $board_config['script_path'] : $defaults['script_path']);
	return $defaults;
}

function dbmtnc_config_version_unknown($database)
{
	$rows = phpbb_acl_rows($database, "SELECT config_value FROM " . CONFIG_TABLE . " WHERE config_name = 'version'");
	return !$rows || in_array($rows[0]['config_value'], array('', '.0.0'), true);
}

function dbmtnc_recover_config($database, $request, $defaults)
{
	global $userdata;
	if (!is_array($request) || !isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST'
		|| empty($userdata['session_id']) || !is_string($userdata['session_id'])
		|| !isset($request['sid']) || !is_string($request['sid']) || !hash_equals($userdata['session_id'], $request['sid']))
	{ phpbb_acl_error('Session_invalid'); }
	$defaults = dbmtnc_config_defaults($defaults);
	$lock = new attach_mutation_lock($database);
	if (!$lock->acquired) { phpbb_acl_error('Attachment_storage_busy'); }
	try
	{
		$db = new PhpbbAclDatabase($lock->connection, 'Maintenance_config_failed');
		dbmtnc_date_actor($db);
		// Topic timestamps are optional recovery hints. Damaged topic storage must
		// not prevent repairing otherwise usable configuration/authority tables.
		$result = $lock->connection->sql_query('SELECT MIN(topic_time) AS startdate FROM ' . TOPICS_TABLE);
		if ($result)
		{
			$row = $lock->connection->sql_fetchrow($result); $lock->connection->sql_freeresult($result);
			if ($row && (int) $row['startdate'] > 0) { $defaults['board_startdate'] = (string) (int) $row['startdate']; }
		}
		$restored = array();
		foreach ($defaults as $key => $value)
		{
			$actor = dbmtnc_date_actor($db);
			$key_sql = $db->sql_escape($key); $value_sql = $db->sql_escape((string) $value);
			$exists = "SELECT 1 FROM " . CONFIG_TABLE . " WHERE config_name = '" . $key_sql . "'";
			if (phpbb_acl_rows($db, $exists)) { continue; }
			// The SELECT is only an optimization. The INSERT rechecks absence and
			// authority; a concurrently restored value is never overwritten.
			$db->sql_query('INSERT INTO ' . CONFIG_TABLE . " (config_name, config_value) SELECT '" . $key_sql . "', '" . $value_sql . "' WHERE " . $actor['guard'] . ' AND NOT EXISTS (' . $exists . ')');
			$changed = (int) $db->sql_affectedrows();
			dbmtnc_date_actor($db);
			if (!phpbb_acl_rows($db, $exists)) { phpbb_acl_error('Maintenance_config_failed'); }
			if ($changed === 1) { $restored[] = $key; }
		}
		dbmtnc_date_actor($db);
		$unknown = dbmtnc_config_version_unknown($db);
		dbmtnc_date_actor($db);
		return array('restored' => $restored, 'version_unknown' => $unknown);
	}
	finally { $lock->release(); }
}
