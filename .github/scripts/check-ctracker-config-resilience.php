<?php

define('CTRACKER_CONFIG', 'phpbb_ctracker_config');
define('CTRACKER_LOGINHISTORY', 'phpbb_ctracker_loginhistory');
define('GENERAL_ERROR', 0);

function message_die()
{
	$arguments = func_get_args();
	throw new Exception(isset($arguments[1]) ? (string) $arguments[1] : 'CrackerTracker error');
}

class config_resilience_db
{
	var $rows = array(array('ct_config_name' => 'request_limit_login', 'ct_config_value' => '41'));
	var $queries = array();

	function sql_query($sql)
	{
		$this->queries[] = $sql;
		if (stripos($sql, 'SELECT * FROM ') === 0) { return 'settings'; }
		if (stripos($sql, 'SELECT ct_login_id, ct_login_time FROM ') === 0) { return 'history-boundary'; }
		return true;
	}

	function sql_fetchrow($result)
	{
		if ($result === 'history-boundary')
		{
			return array('ct_login_id' => 17, 'ct_login_time' => 1700000000);
		}
		return ($result === 'settings' && $this->rows) ? array_shift($this->rows) : false;
	}

	function sql_escape($value)
	{
		return addslashes((string) $value);
	}
}

$db = new config_resilience_db();
$lang = array(
	'ctracker_error_loading_config' => 'load failed',
	'ctracker_error_updating_config' => 'update failed'
);
$HTTP_SERVER_VARS = array('REMOTE_ADDR' => '2001:db8::42');
$HTTP_ENV_VARS = array();

require dirname(dirname(__DIR__)) . '/phpBB2/ctracker/classes/class_ct_database.php';

$config = new ct_database();
if (count($config->settings) !== 45 || $config->settings['request_limit_login'] !== '41' ||
	$config->settings['request_limit_enabled'] !== '1' || $config->settings['global_message'] !== '' ||
	$config->user_ip_value !== '2001:db8::42')
{
	fwrite(STDERR, "CrackerTracker did not recover a partial configuration safely.\n");
	exit(1);
}

$config->change_configuration('request_limit_enabled', '0');
$last_query = end($db->queries);
if (stripos($last_query, 'INSERT INTO phpbb_ctracker_config') === false ||
	stripos($last_query, 'ON DUPLICATE KEY UPDATE') === false ||
	$config->settings['request_limit_enabled'] !== '0')
{
	fwrite(STDERR, "CrackerTracker settings are not repaired through an upsert.\n");
	exit(1);
}

$unknown_rejected = false;
try
{
	$config->change_configuration('unknown_setting', '1');
}
catch (Exception $exception)
{
	$unknown_rejected = true;
}
if (!$unknown_rejected)
{
	fwrite(STDERR, "CrackerTracker accepted an unknown configuration key.\n");
	exit(1);
}

$db->queries = array();
$config->settings['login_history_count'] = '10';
$config->update_login_history(23);
$history_sql = implode("\n", $db->queries);
if (strpos($history_sql, 'ORDER BY ct_login_time DESC, ct_login_id DESC LIMIT 9,1') === false ||
	strpos($history_sql, 'ct_login_time = 1700000000 AND ct_login_id < 17') === false)
{
	fwrite(STDERR, "Login-history retention is not deterministic for equal timestamps.\n");
	exit(1);
}

$basic = file_get_contents(dirname(dirname(__DIR__)) . '/phpBB2/install/schemas/mysql_basic.sql');
if (strpos($basic, "('global_message', 'Hello world!')") !== false ||
	strpos($basic, "('last_file_scan', '1156000091')") !== false ||
	strpos($basic, "('last_checksum_scan', '1156000082')") !== false)
{
	fwrite(STDERR, "Fresh installs still inherit stale CrackerTracker sample state.\n");
	exit(1);
}

echo "CrackerTracker configuration resilience passed.\n";
