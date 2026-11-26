<?php
if (!defined('IN_PHPBB')) { exit; }

function plus_tracker_flag($value)
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

function plus_password_renewal_seen($value)
{
	return (int) ((int) $value < 0);
}

function plus_login_settings()
{
	global $config, $convert, $src_db;
	// get_config_value() conflates the explicit string "0" with an empty value.
	$source_config = get_config();
	if (array_intersect_key($source_config, array_flip(array('min_password_len', 'password_not_login', 'force_complex_password'))))
	{
		$config->set('plus_pw_min_bytes', max(0, min(72, (int) ($source_config['min_password_len'] ?? 6))));
		$config->set('plus_pw_no_name', (int) (bool) ($source_config['password_not_login'] ?? 1));
		$config->set('plus_pw_letters_digits', (int) (bool) ($source_config['force_complex_password'] ?? 0));
		// Plus measured escaped UTF-8 bytes. A second minimum measured in characters
		// would reject e.g. two three-byte letters on a board with a six-byte minimum.
		$config->set('min_pass_chars', 1);
		$config->set('pass_complex', 'PASS_TYPE_ANY');
		$config->set('plus_pw_rules', 1);
	}
	$age = isset($source_config['max_password_age']) ? (string) $source_config['max_password_age'] : '';
	if (preg_match('/^[0-9]{1,10}$/D', $age) && (float) $age <= 2147483647)
	{
		$config->set('chg_passforce', (int) $age);
		$config->set('plus_pw_age_policy', 1);
	}
	$table = $convert->src_table_prefix . 'ctracker_config';
	$tools = (new \phpbb\db\tools\factory())->get($src_db);
	$keys = array('login_history' => 'plus_login_history', 'login_history_count' => 'plus_login_limit',
		'login_ip_check' => 'plus_login_network_check', 'pw_control' => 'plus_pw_reminder', 'pw_validity' => 'plus_pw_reminder_days');
	$settings = array('login_history' => '0', 'login_history_count' => '10', 'login_ip_check' => '0', 'pw_control' => '0', 'pw_validity' => '30');
	$mail = array('massmail_protection'=>'1','massmail_time'=>'20');
	if ($tools->sql_table_exists($table))
	{
		// The installed tracker supplied defaults for missing or invalid numeric settings.
		$settings['login_history'] = $settings['login_ip_check'] = '1';
		$mail = array_fill_keys(array_keys($mail), '');
		$result = $src_db->sql_query("SELECT ct_config_name,ct_config_value FROM $table WHERE " .
			$src_db->sql_in_set('ct_config_name', array_keys($settings + $mail)));
		while ($row = $src_db->sql_fetchrow($result))
		{
			$name = $row['ct_config_name'];
			$value = (string) $row['ct_config_value'];
			if (isset($mail[$name])) { $mail[$name] = $value; }
			if (isset($keys[$name]) && \phpbb2plus\community\service\tracker_settings::accepts($keys[$name], $value))
			{
				$settings[$name] = $value;
			}
		}
		$src_db->sql_freeresult($result);
	}
	foreach ($keys as $source => $target) { $config->set($target, (int) $settings[$source]); }
	// The general recommendation policy belongs to the board, independently of paFileDB and its delegated ACP.
	list($mail_mode,$minutes) = plus_friend_policy($mail);
	$config->set('plus_friend_protection', $mail_mode);
	$config->set('plus_friend_minutes', $minutes);
	$host = (string) ($source_config['server_name'] ?? '');
	$port = (int) ($source_config['server_port'] ?? 0);
	$scheme = !empty($source_config['cookie_secure']) || $port === 443 ? 'https://' : 'http://';
	$path = trim((string) ($source_config['script_path'] ?? ''), '/');
	$origin = $scheme . $host . ($port > 0 && $port !== 80 && $port !== 443 ? ':' . $port : '') . ($path !== '' ? '/' . $path : '');
	$config->set('plus_friend_source_board', strlen($origin) <= 255 && preg_match('/^(?:[a-z0-9][a-z0-9.-]*|\[[a-f0-9:]+\])$/iD',$host)
		&& !preg_match('~[\\\\?#\x00-\x20]~',$path) && filter_var($origin,FILTER_VALIDATE_URL) ? $origin : '');
	plus_friend_recent_mail($tools);
}

function plus_friend_policy($mail)
{
	if (!in_array($mail['massmail_protection'],array('0','1'),true) || !preg_match('/^[0-9]{1,6}$/D',$mail['massmail_time']))
	{
		return array(2,20);
	}
	return array((int)$mail['massmail_protection'],max(1,min(180,(int)$mail['massmail_time'])));
}

function plus_friend_recent_mail($tools)
{
	global $db, $src_db, $convert;
	$users=$convert->src_table_prefix . 'users';
	$limits=$convert->src_table_prefix . 'ctracker_rate_limits';
	$has_limits=$tools->sql_table_exists($limits);
	// Only recent senders can still have a waiting period. Old boards without delivery receipts have their native timestamp.
	$join=$has_limits ? " LEFT JOIN $limits l ON l.bucket_hash=SHA2(CONCAT('mail-user',CHAR(0),'user:',u.user_id),256)" : '';
	$success=$has_limits ? 'COALESCE(l.updated_at,u.user_emailtime)' : 'u.user_emailtime';
	$now=time();
	$after=0;
	do
	{
		$result=$src_db->sql_query_limit("SELECT u.user_id,u.user_emailtime,$success AS last_success FROM $users u$join
			WHERE u.user_id>$after AND u.user_emailtime>=" . ($now-10800) . ' ORDER BY u.user_id',200);
		$rows=$src_db->sql_fetchrowset($result);
		$src_db->sql_freeresult($result);
		foreach ($rows as $row)
		{
			$after=(int)$row['user_id'];
			// A future success is a short in-flight lease. user_emailtime already covers that period.
			$at=(int)$row['last_success'];
			if ($at>$now) { $at=0; }
			$db->sql_query('UPDATE ' . USERS_TABLE . ' SET plus_mail_success=' . max(0,$at) .
				',user_emailtime=' . max(0,(int)$row['user_emailtime']) . ' WHERE plus_legacy_id=' . $after);
		}
	} while (count($rows)===200);
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
