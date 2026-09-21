<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_acl_storage.php';

function phpbb_board_config_fields()
{
	// Only controls actually rendered by board_config_body.tpl and its selects.
	// Internal version, recovery, maintenance and other modules' keys are not inputs.
	return array(
		'absent_button','allow_autologin','allow_avatar_local','allow_avatar_remote','allow_avatar_upload',
		'allow_bbcode','allow_html','allow_html_tags','allow_namechange','allow_sig','allow_smilies',
		'avatar_filesize','avatar_gallery_path','avatar_max_height','avatar_max_width','avatar_path',
		'birthday_check_day','birthday_greeting','birthday_required','block_time','bluecard_limit','bluecard_limit_2',
		'board_disable','board_disable_msg','board_email','board_email_form','board_email_sig','board_timezone',
		'cookie_consent_enable','cookie_domain','cookie_name','cookie_path','cookie_secure','coppa_fax','coppa_mail',
		'default_style','default_lang','default_dateformat','enable_confirm','flood_interval','force_complex_password',
		'gzip_compress','hidde_last_logon','hot_threshold','max_autologin_time','max_inbox_privmsgs','max_link_bookmarks',
		'max_password_age','max_poll_options','max_savebox_privmsgs','max_sentbox_privmsgs','max_sig_chars',
		'max_user_age','max_user_bancard','min_password_len','min_user_age','mod_able_sent_absent','override_user_style',
		'password_not_login','posts_per_page','privmsg_disable','prune_enable','prune_shouts','registration_closed',
		'registration_status','report_forum','require_activation','script_path','search_flood_interval','server_name',
		'server_port','session_length','sfs_enable','sfs_fail_closed','site_desc','sitename','smilies_path',
		'smtp_delivery','smtp_host','smtp_password','smtp_username','topics_per_page','users_allow_absence'
	);
}

function phpbb_board_config_values($request, $current)
{
	global $phpbb_root_path, $ctracker_config;
	$fields = array_flip(phpbb_board_config_fields()); $values = array();
	$booleans = array('absent_button','allow_autologin','allow_avatar_local','allow_avatar_remote','allow_avatar_upload',
		'allow_bbcode','allow_html','allow_namechange','allow_sig','allow_smilies','birthday_greeting','birthday_required',
		'board_disable','board_email_form','cookie_consent_enable','cookie_secure','enable_confirm','force_complex_password',
		'gzip_compress','hidde_last_logon','mod_able_sent_absent','override_user_style','password_not_login',
		'privmsg_disable','prune_enable','registration_status','sfs_enable','sfs_fail_closed','smtp_delivery','users_allow_absence');
	$integers = array('avatar_filesize','avatar_max_height','avatar_max_width','birthday_check_day','block_time',
		'bluecard_limit','bluecard_limit_2','default_style','flood_interval','hot_threshold','max_autologin_time',
		'max_inbox_privmsgs','max_link_bookmarks','max_password_age','max_poll_options','max_savebox_privmsgs',
		'max_sentbox_privmsgs','max_sig_chars','max_user_age','max_user_bancard','min_user_age','posts_per_page',
		'prune_shouts','report_forum','search_flood_interval','session_length','topics_per_page');
	foreach ($request as $key => $encoded)
	{
		if ($key === 'sid' || $key === 'submit') { continue; }
		if (!isset($fields[$key]) || !is_string($encoded)) { phpbb_acl_error('Board_config_invalid'); }
		// common.php applies its legacy quoting adapter. Decode exactly once,
		// including SMTP passwords; SQL escaping happens only at the write boundary.
		$value = stripslashes($encoded);
		if (strlen($value) > 255 || strpos($value, "\0") !== false || preg_match('//u', $value) !== 1)
		{ phpbb_acl_error('Board_config_invalid'); }
		if (!array_key_exists($key, $current)) { phpbb_acl_error('Board_config_failed'); }
		if (in_array($key, $booleans, true) && !in_array($value, array('0','1'), true)) { phpbb_acl_error('Board_config_invalid'); }
		if (in_array($key, $integers, true) && (!preg_match('/^[0-9]{1,10}$/D', $value) || (float)$value > 2147483647))
		{ phpbb_acl_error('Board_config_invalid'); }
		if (in_array($key, array('posts_per_page','topics_per_page','session_length','default_style'), true) && (int)$value < 1)
		{ phpbb_acl_error('Board_config_invalid'); }
		if ($key === 'require_activation' && !in_array($value, array('0','1','2'), true)) { phpbb_acl_error('Board_config_invalid'); }
		if ($key === 'min_password_len' && (!preg_match('/^[0-9]{1,2}$/D', $value) || (int)$value > 72))
		{ phpbb_acl_error('Password_minimum_invalid'); }
		if ($key === 'board_timezone' && (!preg_match('/^-?[0-9]{1,2}(?:\.[0-9]{1,2})?$/D', $value) || (float)$value < -12 || (float)$value > 14))
		{ phpbb_acl_error('Board_config_invalid'); }
		if ($key === 'cookie_name') { $value = str_replace('.', '_', $value); }
		if ($key === 'server_name')
		{
			$server_name_candidate = preg_replace('#^https?://#i', '', trim($value));
			$value = phpbb_normalize_host($server_name_candidate, $current[$key]);
		}
		if ($key === 'server_port') { $value = (string)phpbb_normalize_port($value, $current[$key]); }
		if ($key === 'script_path') { $value = phpbb_normalize_script_path($value, $current[$key]); }
		if ($key === 'avatar_path')
		{
			$value = str_replace('\\', '/', trim($value));
			if (preg_match('#(?:^|/)\.\.(?:/|$)#', $value) || substr($value, 0, 1) === '/'
				|| !is_dir($phpbb_root_path . $value) || !is_writable($phpbb_root_path . $value)) { $value = $current[$key]; }
		}
		$values[$key] = (string)$value;
	}
	if (!$values) { phpbb_acl_error('Board_config_invalid'); }
	if (!empty($ctracker_config->settings['detect_misconfiguration']))
	{
		if (isset($values['server_port']) && $values['server_port'] === '21') { phpbb_acl_error('ctracker_gmb_pu_1'); }
		if (isset($values['session_length']) && (int)$values['session_length'] < 100) { phpbb_acl_error('ctracker_gmb_pu_2'); }
	}
	return $values;
}

class PhpbbBoardConfigWriter extends PhpbbAclDatabase
{
	var $transactional = false;
	function __construct($database)
	{
		if (!method_exists($database, 'sql_dedicated_connection')) { phpbb_acl_error('Board_config_failed'); }
		try { $connection = $database->sql_dedicated_connection(); }
		catch (Exception $error) { phpbb_acl_error('Board_config_failed'); }
		catch (Error $error) { phpbb_acl_error('Board_config_failed'); }
		if (!$connection || !$connection->db_connect_id) { phpbb_acl_error('Board_config_failed'); }
		parent::__construct($connection, 'Board_config_failed');
		register_shutdown_function(array($this, 'release'));
	}
	function actor() { global $phpEx; return phpbb_acp_actor($this, 'admin_board.' . $phpEx); }
	function sql_query($sql, $transaction = false)
	{
		if (!$this->connection || !is_string($sql)) { phpbb_acl_error('Board_config_failed'); }
		$command = strtoupper(trim($sql));
		// Keep the existing internal query interface for independently deployed
		// callers, but never allow autocommit writes or implicit-commit DDL.
		if ($command === 'START TRANSACTION')
		{
			if ($this->transactional) { phpbb_acl_error('Board_config_failed'); }
		}
		elseif ($command === 'COMMIT')
		{
			if (!$this->transactional) { phpbb_acl_error('Board_config_failed'); }
			$this->actor();
		}
		elseif ($command === 'ROLLBACK')
		{
			if (!$this->transactional) { return true; }
		}
		elseif (preg_match('/^\\s*UPDATE\\b/i', $sql))
		{
			if (!$this->transactional) { phpbb_acl_error('Board_config_failed'); }
			$this->actor();
		}
		elseif (!preg_match('/^\\s*SELECT\\b/i', $sql))
		{
			// These two setup statements must precede the owned transaction.
			if ($this->transactional || !in_array($sql, array(
				"SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_ALL_TABLES')",
				'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED'), true))
			{ phpbb_acl_error('Board_config_failed'); }
		}
		$result = parent::sql_query($sql, $transaction);
		if ($command === 'START TRANSACTION') { $this->transactional = true; }
		elseif ($command === 'COMMIT' || $command === 'ROLLBACK') { $this->transactional = false; }
		return $result;
	}
	function rollback()
	{
		if (!$this->transactional) { return; }
		try { $this->connection->sql_query('ROLLBACK'); }
		catch (Exception $error) {}
		catch (Error $error) {}
		$this->transactional = false;
	}
	function release()
	{
		$this->rollback();
		$connection = $this->connection; $this->connection = null;
		if ($connection)
		{
			try { $connection->sql_close(); }
			catch (Exception $error) {}
			catch (Error $error) {}
		}
	}
}

function phpbb_board_config_read($db)
{
	$rows = phpbb_acl_rows($db, 'SELECT config_name, config_value FROM ' . CONFIG_TABLE);
	$values = array(); foreach ($rows as $row) { $values[$row['config_name']] = $row['config_value']; }
	return $values;
}

function phpbb_board_config_save($database, $request)
{
	global $userdata, $phpbb_root_path, $phpEx, $ctracker_config, $board_config;
	if (!is_array($request) || !isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST'
		|| empty($userdata['session_id']) || !is_string($userdata['session_id']) || !isset($request['sid'])
		|| !is_string($request['sid']) || !hash_equals($userdata['session_id'], $request['sid'])) { phpbb_acl_error('Session_invalid'); }
	$db = new PhpbbBoardConfigWriter($database);
	$invalidate = false;
	try
	{
		$db->actor();
		// Validate the COMPLETE request before even refreshing an automatic backup.
		phpbb_board_config_values($request, phpbb_board_config_read($db));
		if (!empty($ctracker_config->settings['auto_recovery']))
		{
			if (!defined('CTRACKER_ACP')) { define('CTRACKER_ACP', true); }
			require_once $phpbb_root_path . 'ctracker/classes/class_ct_adminfunctions.' . $phpEx;
			$backup = new ct_adminfunctions(); $backup->recover_configuration('board');
		}
		$db->sql_query("SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_ALL_TABLES')");
		$db->sql_query('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
		$db->sql_query('START TRANSACTION');
		// Pin metadata first; an ALTER cannot replace transactional storage while
		// the checks/writes/short authority locks below are in progress.
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
		// Only requested controls are locked/written. Unsubmitted fields and all
		// internal metadata retain their current values, not an earlier snapshot.
		$names = array();
		foreach (phpbb_board_config_fields() as $key) { if (isset($request[$key])) { $names[] = "'" . $key . "'"; } }
		$rows = phpbb_acl_rows($db, 'SELECT config_name, config_value FROM ' . CONFIG_TABLE . ' WHERE config_name IN (' . implode(',', $names) . ') ORDER BY config_name FOR UPDATE');
		$current = array(); foreach ($rows as $row) { $current[$row['config_name']] = $row['config_value']; }
		$values = phpbb_board_config_values($request, $current);
		foreach ($values as $key => $value)
		{
			$actor = $db->actor();
			$invalidate = true;
			$db->sql_query('UPDATE ' . CONFIG_TABLE . " SET config_value='" . $db->sql_escape($value) . "' WHERE config_name='" . $db->sql_escape($key) . "' AND " . $actor['guard']);
		}
		// Serialize the actual commit against account/session/module revocation.
		$actor = $db->actor(); $sid = $db->sql_escape($userdata['session_id']);
		foreach (array('SELECT session_id FROM ' . SESSIONS_TABLE . " WHERE session_id='" . $sid . "' AND HEX(session_id)=HEX('" . $sid . "')",
			'SELECT user_id FROM ' . USERS_TABLE . ' WHERE user_id=' . (int)$actor['user_id'],
			'SELECT user_id FROM ' . JR_ADMIN_TABLE . ' WHERE user_id=' . (int)$actor['user_id']) as $sql)
		{ $result = $db->sql_query($sql . ' LOCK IN SHARE MODE'); $db->sql_freeresult($result); }
		$db->actor();
		$stored = phpbb_board_config_read($db);
		foreach ($values as $key => $value) { if (!isset($stored[$key]) || (string)$stored[$key] !== $value) { phpbb_acl_error('Board_config_failed'); } }
		// Authority remains pinned through COMMIT; a later revocation cannot
		// turn this acknowledged save into a misleading failure.
		$db->sql_query('COMMIT');
		foreach ($values as $key => $value) { $board_config[$key] = $value; }
	}
	finally
	{
		$db->release();
		// Lost acknowledgement may still mean durable new settings. Evict the
		// legacy cache after attempted writes, including rollback/uncertainty.
		if ($invalidate) { @unlink($phpbb_root_path . 'cache/config_data.cache'); }
	}
}
