<?php
if (!defined('IN_PHPBB')) { exit; }

function plus_arcade_currency_fields()
{
	global $src_db, $convert;
	static $currencies = null;
	if ($currencies !== null) { return $currencies; }
	$currencies = array();
	$p = $convert->src_table_prefix;
	$result = $src_db->sql_query("SELECT config_name,config_value FROM ".$p."config WHERE config_name IN ('points_name','default_reward_dbfield')");
	$board = array();
	while ($row = $src_db->sql_fetchrow($result)) { $board[$row['config_name']] = $row['config_value']; }
	$src_db->sql_freeresult($result);
	$result = $src_db->sql_query("SELECT config_name,config_value FROM ".$p."ina_data WHERE config_name IN ('default_cash','use_point_system')");
	$arcade = array();
	while ($row = $src_db->sql_fetchrow($result)) { $arcade[$row['config_name']] = $row['config_value']; }
	$src_db->sql_freeresult($result);
	$active = !empty($arcade['use_point_system']) ? 'user_points' : ($board['default_reward_dbfield'] ?? '');
	if ($active === '') { $active = $arcade['default_cash'] ?? ''; }
	$candidates = array('user_points' => $board['points_name'] ?? '', 'user_cash' => '', 'user_allowance' => '');
	foreach (array($active, $arcade['default_cash'] ?? '') as $field) { if ($field !== '' && !isset($candidates[$field])) { $candidates[$field] = ''; } }

	// Source installations use MySQL/MariaDB. The Cash MOD table is optional.
	$cash = $src_db->sql_escape($p . 'cash');
	$result = $src_db->sql_query("SELECT COUNT(*) AS present FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$cash' AND COLUMN_NAME IN ('cash_dbfield','cash_name')");
	$exists = (int) $src_db->sql_fetchfield('present') === 2;
	$src_db->sql_freeresult($result);
	if ($exists)
	{
		$result = $src_db->sql_query("SELECT cash_dbfield,cash_name FROM ".$p."cash");
		while ($row = $src_db->sql_fetchrow($result)) { $candidates[$row['cash_dbfield']] = $row['cash_name']; }
		$src_db->sql_freeresult($result);
	}
	$reserved = plus_core_account_columns();
	$users = $src_db->sql_escape($p . 'users');
	$result = $src_db->sql_query("SELECT COLUMN_NAME,DATA_TYPE,NUMERIC_SCALE,COLUMN_DEFAULT,COLUMN_KEY,EXTRA FROM information_schema.COLUMNS
		WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$users' ORDER BY ORDINAL_POSITION");
	while ($row = $src_db->sql_fetchrow($result))
	{
		$field = $row['COLUMN_NAME'];
		if (!isset($candidates[$field]) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D', $field) ||
			in_array(strtolower($field), $reserved, true) || !in_array(strtolower($row['DATA_TYPE']), array('tinyint','smallint','mediumint','int','bigint','decimal','float','double'), true) ||
			$row['COLUMN_KEY'] === 'PRI' || preg_match('/auto_increment|generated/i', $row['EXTRA'])) { continue; }
		$scale = $row['NUMERIC_SCALE'] === null ? 38 : (int) $row['NUMERIC_SCALE'];
		$default = trim((string) $row['COLUMN_DEFAULT'], "'");
		if ($default === '' || strtoupper($default) === 'NULL') { $default = '0'; }
		$units = \phpbb2plus\arcade\service\currency::scaled($default, $scale);
		$currencies[$field] = array('currency_field' => $field, 'currency_name' => utf8_encode_ucr($candidates[$field]),
			'fraction_digits' => $scale, 'initial_amount' => \phpbb2plus\arcade\service\currency::amount($units, $scale), 'is_active' => (int) ($field === $active));
	}
	$src_db->sql_freeresult($result);
	return $currencies;
}

function plus_arcade_currency_setup($anonymous)
{
	global $db, $table_prefix;
	$rows = plus_arcade_currency_fields();
	$table = $table_prefix . 'plus_arc_currencies';
	$db->sql_transaction('begin');
	$db->sql_query("DELETE FROM $table");
	if ($rows) { $db->sql_multi_insert($table, array_values($rows)); }
	$db->sql_transaction('commit');
}

function plus_arcade_balances($source, ...$balances)
{
	global $db, $table_prefix;
	$user = phpbb_user_id($source);
	$rows = array();
	$index = 0;
	foreach (plus_arcade_currency_fields() as $field => $currency)
	{
		$value = $balances[$index++];
		$text = '';
		if ($value !== '' && $value !== null)
		{
			$scale = $currency['fraction_digits'];
			$units = \phpbb2plus\arcade\service\currency::scaled((string) $value, $scale);
			$text = \phpbb2plus\arcade\service\currency::amount($units, $scale);
		}
		$rows[] = array('owner_id' => $user, 'currency_field' => $field, 'balance_text' => $text);
	}
	if ($rows)
	{
		$table = $table_prefix . 'plus_arc_wallets';
		$db->sql_transaction('begin');
		$db->sql_query("DELETE FROM $table WHERE owner_id=" . (int) $user);
		$db->sql_multi_insert($table, $rows);
		$db->sql_transaction('commit');
	}
}

function plus_arcade_wallet_schema($prefix, $truncate)
{
	$currencies = $prefix . 'plus_arc_currencies';
	$wallets = $prefix . 'plus_arc_wallets';
	$steps = array(array('target' => $currencies, 'primary' => 'users.user_id', 'where' => 'users.user_id=-1',
		'query_first' => array(array('target', $truncate . $currencies), array('target', $truncate . $wallets)),
		array('', 'users.user_id', 'plus_arcade_currency_setup')));
	$columns = array('users.user_id');
	foreach (plus_arcade_currency_fields() as $field => $row) { $columns[] = 'users.' . $field; }
	if (count($columns) > 1)
	{
		$steps[] = array('target' => $wallets, 'primary' => 'users.user_id', 'where' => 'users.user_id>0',
			array('', $columns, 'plus_arcade_balances'));
	}
	return $steps;
}
