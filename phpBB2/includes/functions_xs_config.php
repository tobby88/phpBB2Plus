<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_style_data.php';

class PhpbbXsConfigWriter extends PhpbbStyleDataWriter
{
	function update_tables() { return array(CONFIG_TABLE); }
	function sql_query($sql, $transaction = false)
	{
		if (is_string($sql) && preg_match('/^\s*INSERT\b/i', $sql)) { phpbb_acl_error('xs_config_save_failed'); }
		return parent::sql_query($sql, $transaction);
	}
}

function phpbb_xs_config_fields($mode)
{
	$fields = array('xs_ftp_host','xs_ftp_login','xs_ftp_path');
	if ($mode === 'ftp') { return $fields; }
	if ($mode !== 'config') { phpbb_acl_error('xs_config_save_failed'); }
	return array_merge(array('xs_use_cache','xs_auto_compile','xs_auto_recompile','xs_php','xs_def_template',
		'xs_check_switches','xs_warn_includes','xs_add_comments'), $fields, array('xs_shownav','xs_template_time'));
}

function phpbb_xs_config_plan($request, $mode, $current, $allow_local)
{
	global $phpbb_root_path;
	$fields = phpbb_xs_config_fields($mode); $values = array(); $runtime = array();
	if (!is_bool($allow_local)) { phpbb_acl_error('xs_config_save_failed'); }
	foreach ($fields as $field) { if (!array_key_exists($field, $current)) { phpbb_acl_error('xs_config_save_failed'); } }
	$use_local = false;
	if ($mode === 'ftp')
	{
		if (isset($request['xs_ftp_local']) && (!is_string($request['xs_ftp_local']) || !in_array($request['xs_ftp_local'], array('','0','1'), true))) { phpbb_acl_error('xs_config_save_failed'); }
		$use_local = $allow_local && isset($request['xs_ftp_local']) && $request['xs_ftp_local'] === '1';
		// Validate this request-only secret BEFORE writing any stored connection
		// settings; never trim, log or persist it as configuration.
		if (isset($request['xs_ftp_pass']) && !is_string($request['xs_ftp_pass'])) { phpbb_acl_error('xs_config_save_failed'); }
		$password = isset($request['xs_ftp_pass']) ? stripslashes($request['xs_ftp_pass']) : '';
		if (strlen($password) > 1024 || strpos($password, "\0") !== false) { phpbb_acl_error('xs_config_save_failed'); }
		$runtime = array('xs_ftp_pass' => $password, 'xs_ftp_local' => $use_local);
	}
	$booleans = array('xs_use_cache','xs_auto_compile','xs_auto_recompile','xs_warn_includes','xs_add_comments');
	foreach ($fields as $field)
	{
		if ($field === 'xs_shownav' || $field === 'xs_template_time') { continue; }
		if (!isset($request[$field]) || !is_string($request[$field])) { phpbb_acl_error('xs_config_save_failed'); }
		$value = stripslashes($request[$field]);
		if (preg_match('/[\x00-\x1F\x7F]/', $value) || preg_match_all('/./us', $value, $characters) === false || count($characters[0]) > 255) { phpbb_acl_error('xs_config_save_failed'); }
		$value = trim($value);
		if (in_array($field, $booleans, true) && !in_array($value, array('0','1'), true)) { phpbb_acl_error('xs_config_save_failed'); }
		if ($field === 'xs_check_switches' && !in_array($value, array('0','1','2'), true)) { phpbb_acl_error('xs_config_save_failed'); }
		if ($field === 'xs_php' && !preg_match('/^[a-zA-Z0-9]{1,10}$/D', $value)) { phpbb_acl_error('xs_config_save_failed'); }
		if ($field === 'xs_def_template' && ($value !== 'fisubsilversh' || !is_dir($phpbb_root_path . 'templates/fisubsilversh'))) { phpbb_acl_error('xs_config_save_failed'); }
		if ($field === 'xs_ftp_host' && $value !== '' && !preg_match('/^[a-zA-Z0-9.\-:\[\]]+$/D', $value)) { phpbb_acl_error('xs_config_save_failed'); }
		if ($mode === 'ftp' && $value === '')
		{
			if (!$use_local) { phpbb_acl_error('xs_config_save_failed'); }
			// Local filesystem actions must not erase saved FTP defaults merely
			// because their connection fields were left blank.
			continue;
		}
		$values[$field] = $value;
	}
	if ($mode === 'config')
	{
		$shownav = 0;
		foreach ($request as $key => $value)
		{
			if (in_array($key, array_merge(array_diff($fields, array('xs_shownav','xs_template_time')), array('sid','submit')), true)) { continue; }
			if (!preg_match('/^shownav_([0-9]+)$/D', $key, $match) || (int)$match[1] > 12 || !is_string($value) || !in_array($value, array('0','1'), true)) { phpbb_acl_error('xs_config_save_failed'); }
			$index = (int)$match[1];
			if ($index !== 8 && $value === '1') { $shownav |= 1 << $index; }
		}
		$values['xs_shownav'] = (string)$shownav;
		if ($values['xs_auto_compile'] === '0') { $values['xs_auto_recompile'] = '0'; }
		if ($values['xs_check_switches'] !== $current['xs_check_switches'])
		{
			if (!preg_match('/^[0-9]{1,10}$/D', $current['xs_template_time']) || (float)$current['xs_template_time'] >= PHP_INT_MAX) { phpbb_acl_error('xs_config_save_failed'); }
			// Never move the invalidation clock backwards when a preceding save
			// already placed it in the future for an in-flight template compiler.
			$values['xs_template_time'] = (string)max(time() + 10, (int)$current['xs_template_time'] + 1);
		}
	}
	return array('values' => $values, 'runtime' => $runtime);
}

function phpbb_xs_config_save($database, $request, $mode = 'config', $allow_local = false)
{
	global $userdata, $phpbb_root_path, $board_config;
	if (!is_array($request) || !isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST'
		|| empty($userdata['session_id']) || !is_string($userdata['session_id']) || !isset($request['sid'])
		|| !is_string($request['sid']) || !hash_equals($userdata['session_id'], $request['sid'])) { phpbb_acl_error('Session_invalid'); }
	$fields = phpbb_xs_config_fields($mode); $db = new PhpbbXsConfigWriter($database); $attempted = false;
	try
	{
		$db->actor(); phpbb_style_storage_start($db, array(CONFIG_TABLE, USERS_TABLE, SESSIONS_TABLE, JR_ADMIN_TABLE));
		$where = "config_name IN ('" . implode("','", $fields) . "')";
		$rows = phpbb_acl_rows($db, 'SELECT config_name,config_value FROM ' . CONFIG_TABLE . ' WHERE ' . $where . ' ORDER BY config_name FOR UPDATE');
		$current = array();
		foreach ($rows as $row)
		{
			if (!in_array($row['config_name'], $fields, true) || array_key_exists($row['config_name'], $current)) { phpbb_acl_error('xs_config_save_failed'); }
			$current[$row['config_name']] = $row['config_value'];
		}
		$plan = phpbb_xs_config_plan($request, $mode, $current, $allow_local);
		foreach ($plan['values'] as $field => $value)
		{
			if ($value === $current[$field]) { continue; }
			$actor = $db->actor(); $attempted = true;
			$db->sql_query('UPDATE ' . CONFIG_TABLE . " SET config_value='" . $db->sql_escape($value) . "' WHERE config_name='" . $field . "' AND " . $actor['guard']);
		}
		phpbb_style_storage_lock_authority($db);
		$rows = phpbb_acl_rows($db, 'SELECT config_name,config_value FROM ' . CONFIG_TABLE . ' WHERE ' . $where);
		$stored = array(); foreach ($rows as $row) { $stored[$row['config_name']] = $row['config_value']; }
		$expected = array_merge($current, $plan['values']);
		if (count($rows) !== count($fields) || count($stored) !== count($fields)) { phpbb_acl_error('xs_config_save_failed'); }
		foreach ($expected as $field => $value) { if (!array_key_exists($field, $stored) || $stored[$field] !== $value) { phpbb_acl_error('xs_config_save_failed'); } }
		// Include successful no-op retries in cache eviction.
		$attempted = true; $db->sql_query('COMMIT');
	}
	finally { phpbb_style_data_finish($db, $attempted, $phpbb_root_path . 'cache/config_data.cache'); }
	foreach (array_merge($expected, $plan['runtime']) as $field => $value) { $board_config[$field] = $value; }
}
