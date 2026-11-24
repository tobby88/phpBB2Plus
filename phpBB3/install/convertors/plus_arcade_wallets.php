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
	// Only optional numeric account columns may supply a balance. These are the
	// built-in columns in the public Plus users schema, including legacy MOD settings.
	$reserved = explode(' ',
		'user_id user_active username user_password user_session_time user_session_page user_session_topic user_lastvisit' . ' ' .
		'user_regdate user_reg_ip user_reg_host user_level user_posts user_timezone user_style user_lang user_dateformat' . ' ' .
		'user_new_privmsg user_unread_privmsg user_last_privmsg user_login_tries user_last_login_try user_emailtime' . ' ' .
		'user_viewemail user_attachsig user_setbm user_allowhtml user_allowbbcode user_allowsmile user_allowavatar' . ' ' .
		'user_allow_pm user_allow_viewonline user_notify user_notify_pm user_popup_pm user_rank user_avatar' . ' ' .
		'user_avatar_type user_email user_icq user_website user_from user_from_flag user_sig user_sig_bbcode_uid user_aim' . ' ' .
		'user_yim user_msnm user_fb user_ig user_pt user_twr user_skp user_tg user_li user_tt user_dc user_signal' . ' ' .
		'user_threema user_occ user_interests user_actkey user_newpasswd ct_last_pw_reset ct_enable_ip_warn' . ' ' .
		'ct_last_used_ip ct_last_ip ct_last_pw_change ct_global_msg_read ct_miserable_user user_sub_forum user_split_cat' . ' ' .
		'user_last_topic_title user_sub_level_links user_display_viewonline user_birthday user_next_birthday_greeting' . ' ' .
		'user_gender user_color_group user_lastlogon user_totaltime user_totallogon user_totalpages' . ' ' .
		'user_calendar_display_open user_calendar_header_cells user_calendar_week_start user_calendar_nb_row' . ' ' .
		'user_calendar_birthday user_calendar_forum user_warnings user_passwd_change user_badlogin user_blocktime' . ' ' .
		'user_block_by user_split_global_announce user_split_announce user_split_sticky user_split_news' . ' ' .
		'user_split_topic_split user_absence user_absence_mode user_absence_text user_announcement_date_display' . ' ' .
		'user_announcement_display user_announcement_display_forum user_announcement_split user_announcement_forum' . ' ' .
		'user_use_ajax_preview user_use_ajax_edit games_block_pm arcade_banned');
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
