<?php
if (!defined('IN_PHPBB')) { exit; }

function plus_login_warning_enabled($value)
{
	return (int) ((string) $value === '1');
}

function plus_login_address($value)
{
	$ip = trim((string) $value);
	return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '';
}

function plus_login_settings()
{
	global $config, $convert, $src_db;
	$table = $convert->src_table_prefix . 'ctracker_config';
	$settings = array('login_history' => '0', 'login_history_count' => '10', 'login_ip_check' => '0');
	if ((new \phpbb\db\tools\factory())->get($src_db)->sql_table_exists($table))
	{
		// The installed tracker supplied defaults for missing or invalid numeric settings.
		$settings['login_history'] = $settings['login_ip_check'] = '1';
		$result = $src_db->sql_query("SELECT ct_config_name,ct_config_value FROM $table WHERE " .
			$src_db->sql_in_set('ct_config_name', array_keys($settings)));
		while ($row = $src_db->sql_fetchrow($result))
		{
			$name = $row['ct_config_name'];
			$value = (string) $row['ct_config_value'];
			if ($name === 'login_history_count' ? (preg_match('/^[1-9][0-9]?$/D', $value) && (int) $value <= 60) : in_array($value, array('0','1'), true))
			{
				$settings[$name] = $value;
			}
		}
		$src_db->sql_freeresult($result);
	}
	$config->set('plus_login_history', (int) $settings['login_history']);
	$config->set('plus_login_limit', (int) $settings['login_history_count']);
	$config->set('plus_login_network_check', (int) $settings['login_ip_check']);
}

function plus_import_account_logins($source_user)
{
	global $db, $src_db, $convert, $table_prefix;
	$source_user = (int) $source_user;
	$id = (int) phpbb_user_id($source_user);
	$table = $table_prefix . 'plus_account_logins';
	$source = $convert->src_table_prefix . 'ctracker_loginhistory';
	// Replace one complete account atomically when the conversion wizard retries.
	// Source IDs can exceed PHP_INT_MAX. They order tied dates but are never public bookmarks.
	$db->sql_transaction('begin');
	try
	{
		$db->sql_query("DELETE FROM $table WHERE user_id=$id");
		$after = null;
		do
		{
			$result = $src_db->sql_query_limit("SELECT ct_login_id,ct_login_time,ct_login_ip FROM $source WHERE ct_user_id=$source_user" .
				($after === null ? '' : ' AND ct_login_id>' . $after) . ' ORDER BY ct_login_id', 200);
			$rows = array();
			while ($row = $src_db->sql_fetchrow($result))
			{
				$after = (string) $row['ct_login_id'];
				if (!ctype_digit($after)) { throw new \RuntimeException('Invalid numeric key in CrackerTracker login history.'); }
				$rows[] = array('user_id' => $id, 'login_time' => (int) $row['ct_login_time'], 'login_ip' => plus_login_address($row['ct_login_ip']));
			}
			$src_db->sql_freeresult($result);
			if ($rows) { $db->sql_multi_insert($table, $rows); }
		}
		while (count($rows) === 200);
		$db->sql_transaction('commit');
	}
	catch (\Throwable $error) { $db->sql_transaction('rollback'); throw $error; }
}
