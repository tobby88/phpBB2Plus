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

function plus_password_change_time($value)
{
	// The old enforced-renewal field also used a negative sentinel.
	return max(0, (int) $value);
}

function plus_login_settings()
{
	global $config, $convert, $src_db;
	$table = $convert->src_table_prefix . 'ctracker_config';
	$keys = array('login_history' => 'plus_login_history', 'login_history_count' => 'plus_login_limit',
		'login_ip_check' => 'plus_login_network_check', 'pw_control' => 'plus_pw_reminder', 'pw_validity' => 'plus_pw_reminder_days');
	$settings = array('login_history' => '0', 'login_history_count' => '10', 'login_ip_check' => '0', 'pw_control' => '0', 'pw_validity' => '30');
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
			if (isset($keys[$name]) && \phpbb2plus\community\service\tracker_settings::accepts($keys[$name], $value))
			{
				$settings[$name] = $value;
			}
		}
		$src_db->sql_freeresult($result);
	}
	foreach ($keys as $source => $target) { $config->set($target, (int) $settings[$source]); }
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
		$result = $db->sql_query('SELECT plus_login_binding FROM ' . USERS_TABLE . " WHERE user_id=$id AND plus_legacy_id=$source_user FOR UPDATE");
		$binding = $db->sql_fetchfield('plus_login_binding');
		$db->sql_freeresult($result);
		if ($binding === false) { throw new \RuntimeException('The source login history has no matching destination account.'); }
		$binding = \phpbb2plus\community\service\account_logins::bind_owner($db, $id, $binding);
		$db->sql_query("DELETE FROM $table WHERE user_id=$id");
		$after = null;
		do
		{
			$result = $src_db->sql_query_limit("SELECT ct_login_id,ct_login_time,BINARY ct_login_ip AS ct_login_ip FROM $source WHERE ct_user_id=$source_user" .
				($after === null ? '' : ' AND ct_login_id>' . $after) . ' ORDER BY ct_login_id', 200);
			$rows = array();
			while ($row = $src_db->sql_fetchrow($result))
			{
				$after = (string) $row['ct_login_id'];
				if (!ctype_digit($after)) { throw new \RuntimeException('Invalid numeric key in CrackerTracker login history.'); }
				// The historical viewer displayed this field as text. Preserve an old
				// label verbatim, independently of the two validated network addresses.
				$address = utf8_encode_ucr(htmlspecialchars((string) $row['ct_login_ip'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
				$rows[] = array('user_id' => $id, 'owner_binding' => $binding,
					'login_time' => (int) $row['ct_login_time'], 'login_ip' => $address);
			}
			$src_db->sql_freeresult($result);
			if ($rows) { $db->sql_multi_insert($table, $rows); }
		}
		while (count($rows) === 200);
		$db->sql_transaction('commit');
	}
	catch (\Throwable $error) { $db->sql_transaction('rollback'); throw $error; }
}
