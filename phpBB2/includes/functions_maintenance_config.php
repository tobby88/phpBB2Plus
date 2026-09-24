<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_maintenance_dates.php';

// Explicit ACP actions only. Revalidate the live account, delegated module and
// exact admin session inside the same statement that changes these settings.
function dbmtnc_save_controls($database, $request, $action)
{
	global $userdata, $board_config, $phpbb_root_path;
	if (!is_array($request) || !isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST'
		|| empty($userdata['session_id']) || !is_string($userdata['session_id'])
		|| !isset($request['sid']) || !is_string($request['sid']) || !hash_equals($userdata['session_id'], $request['sid']))
	{ phpbb_acl_error('Session_invalid'); }
	if ($action === 'unlock') { $values = array('board_disable' => '0'); }
	elseif ($action === 'settings')
	{
		$values = array();
		foreach (array('disallow_rebuild', 'disallow_postcounter') as $key)
		{
			$value = isset($request[$key]) ? $request[$key] : '0';
			if (!(is_string($value) || is_int($value)) || !in_array((string) $value, array('0', '1'), true))
			{ phpbb_acl_error('Invalid_dbmtnc_request'); }
			$values['dbmtnc_' . $key] = (string) $value;
		}
	}
	else { phpbb_acl_error('Invalid_dbmtnc_request'); }
	$lock = new attach_mutation_lock($database);
	if (!$lock->acquired) { phpbb_acl_error('Attachment_storage_busy'); }
	$attempted = false;
	try
	{
		$db = new PhpbbAclDatabase($lock->connection, 'Maintenance_config_failed');
		$actor = dbmtnc_date_actor($db);
		$keys = array(); $cases = array();
		foreach ($values as $key => $value)
		{
			$keys[] = "'" . $key . "'";
			$cases[] = "WHEN '" . $key . "' THEN '" . $value . "'";
		}
		$where = 'config_name IN (' . implode(',', $keys) . ')';
		$rows = phpbb_acl_rows($db, 'SELECT config_name, config_value FROM ' . CONFIG_TABLE . ' WHERE ' . $where);
		if (count($rows) !== count($values)) { phpbb_acl_error('Maintenance_config_failed'); }
		// One UPDATE for the complete settings form, rather than two writes that
		// could leave half a form saved after a failure or revoked permission.
		$attempted = true;
		$db->sql_query('UPDATE ' . CONFIG_TABLE . ' SET config_value = CASE config_name ' . implode(' ', $cases)
			. ' ELSE config_value END WHERE ' . $where . ' AND ' . $actor['guard']);
		dbmtnc_date_actor($db);
		$rows = phpbb_acl_rows($db, 'SELECT config_name, config_value FROM ' . CONFIG_TABLE . ' WHERE ' . $where);
		if (count($rows) !== count($values)) { phpbb_acl_error('Maintenance_config_failed'); }
		foreach ($rows as $row)
		{
			if (!isset($values[$row['config_name']]) || (string) $row['config_value'] !== $values[$row['config_name']])
			{ phpbb_acl_error('Maintenance_config_failed'); }
		}
		dbmtnc_date_actor($db);
		foreach ($values as $key => $value) { $board_config[$key] = $value; }
	}
	finally
	{
		// A lost acknowledgement can follow a committed write. Never leave the
		// previous cache behind or report that failure as a successful rollback.
		try { if ($attempted) { dbmtnc_config_expire_cache(); } }
		finally { $lock->release(); }
	}
}

function dbmtnc_config_expire_cache()
{
	global $phpbb_root_path;
	$cache = $phpbb_root_path . 'cache/config_data.cache';
	clearstatcache(true, $cache);
	if ((file_exists($cache) || is_link($cache)) && !@unlink($cache)) { phpbb_acl_error('Maintenance_config_failed'); }
}

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
		return dbmtnc_config_restore_rows($db, $defaults, function($connection) { return dbmtnc_date_actor($connection); });
	}
	finally { $lock->release(); }
}

// Standalone recovery has credential authority, not an ACP session/delegation.
// Pin the initially resolved actor through the entire repair, including aliases
// and the explicit database-owner path. Only compiled defaults reach this API.
function dbmtnc_erc_recover_config($defaults, $expected_actor_id)
{
	global $db;
	if (!is_int($expected_actor_id) || $expected_actor_id < 0) { phpbb_acl_error('Auth_failed'); }
	$connection = new PhpbbAclDatabase($db, 'Maintenance_config_failed');
	$authorize = function($unused) use ($expected_actor_id) {
		if (!check_authorisation(false, $guard, $actor_id) || $actor_id !== $expected_actor_id) { phpbb_acl_error('Auth_failed'); }
		return array('guard' => $guard);
	};
	return dbmtnc_config_restore_rows($connection, $defaults, $authorize);
}

// Both entry points share non-overwriting inserts, outcome verification and
// cache cleanup. The private callback supplies the entry point's CURRENT SQL
// authority predicate; it is never populated from submitted form parameters.
function dbmtnc_config_restore_rows($db, $defaults, $authorize)
{
	if (!is_callable($authorize)) { phpbb_acl_error('Maintenance_config_failed'); }
	$defaults = dbmtnc_config_defaults($defaults);
	$attempted = $completed = false;
	try
	{
		call_user_func($authorize, $db);
		// Topic timestamps are optional recovery hints. Damaged topic storage must
		// not prevent repairing otherwise usable configuration/authority tables.
		$result = $db->connection->sql_query('SELECT MIN(topic_time) AS startdate FROM ' . TOPICS_TABLE);
		if ($result)
		{
			$row = $db->connection->sql_fetchrow($result); $db->connection->sql_freeresult($result);
			if ($row && (int) $row['startdate'] > 0) { $defaults['board_startdate'] = (string) (int) $row['startdate']; }
		}
		$restored = array();
		foreach ($defaults as $key => $value)
		{
			$actor = call_user_func($authorize, $db);
			if (!is_array($actor) || !isset($actor['guard']) || !is_string($actor['guard']) || $actor['guard'] === '') { phpbb_acl_error('Maintenance_config_failed'); }
			$key_sql = $db->sql_escape($key); $value_sql = $db->sql_escape((string) $value);
			$exists = "SELECT 1 FROM " . CONFIG_TABLE . " WHERE config_name = '" . $key_sql . "'";
			if (phpbb_acl_rows($db, $exists)) { continue; }
			// The SELECT is only an optimization. The INSERT rechecks absence and
			// authority; a concurrently restored value is never overwritten.
			$attempted = true;
			$db->sql_query('INSERT INTO ' . CONFIG_TABLE . " (config_name, config_value) SELECT '" . $key_sql . "', '" . $value_sql . "' WHERE " . $actor['guard'] . ' AND NOT EXISTS (' . $exists . ')');
			$changed = (int) $db->sql_affectedrows();
			call_user_func($authorize, $db);
			if (!phpbb_acl_rows($db, $exists)) { phpbb_acl_error('Maintenance_config_failed'); }
			if ($changed === 1) { $restored[] = $key; }
		}
		call_user_func($authorize, $db);
		$unknown = dbmtnc_config_version_unknown($db);
		call_user_func($authorize, $db);
		$completed = true;
		return array('restored' => $restored, 'version_unknown' => $unknown);
	}
	finally
	{
		// A retry may find that every insertion already succeeded before its ACK
		// or cache cleanup failed. Successful no-op repairs must refresh it too.
		if ($attempted || $completed) { dbmtnc_config_expire_cache(); }
	}
}
