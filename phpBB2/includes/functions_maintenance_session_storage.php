<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_maintenance_tables.php';

function dbmtnc_session_storage_rows($db, $sql)
{
	$result = $db->sql_query($sql);
	if (!$result) { phpbb_acl_error('Session_storage_failed'); }
	try { return $db->sql_fetchrowset($result); }
	finally { $db->sql_freeresult($result); }
}

function dbmtnc_session_storage_engine($db)
{
	if (!preg_match('/^[A-Za-z0-9_]{1,64}$/D', SESSIONS_TABLE)) { phpbb_acl_error('Session_storage_failed'); }
	$rows = dbmtnc_session_storage_rows($db, "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()"
		. " AND HEX(TABLE_NAME) = HEX('" . $db->sql_escape(SESSIONS_TABLE) . "') AND TABLE_TYPE = 'BASE TABLE'");
	if (count($rows) !== 1 || empty($rows[0]['ENGINE'])) { phpbb_acl_error('Session_storage_failed'); }
	return strtoupper($rows[0]['ENGINE']);
}

function dbmtnc_convert_session_storage($database, $request)
{
	$scope = null; $restore_mode = false; $original_mode = ''; $failure = null; $outcome = 'Session_storage_current';
	try
	{
		$scope = dbmtnc_table_begin($database, $request);
		$engine = dbmtnc_session_storage_engine($database);
		dbmtnc_date_actor($database);
		if ($engine !== 'INNODB')
		{
			// Do not guess at the semantics of custom engines or table types.
			if (!in_array($engine, array('HEAP', 'MEMORY', 'MYISAM'), true)) { phpbb_acl_error('Session_storage_unsupported'); }
			$support = dbmtnc_session_storage_rows($database, "SELECT SUPPORT FROM information_schema.ENGINES WHERE ENGINE = 'InnoDB'");
			if (count($support) !== 1 || !isset($support[0]['SUPPORT']) ||
				!in_array(strtoupper($support[0]['SUPPORT']), array('YES', 'DEFAULT'), true))
			{ phpbb_acl_error('Session_storage_unsupported'); }
			$modes = dbmtnc_session_storage_rows($database, 'SELECT @@SESSION.sql_mode AS sql_mode');
			if (count($modes) !== 1 || !isset($modes[0]['sql_mode'])) { phpbb_acl_error('Session_storage_failed'); }
			$original_mode = $modes[0]['sql_mode'];
			$mode_list = $original_mode === '' ? array() : explode(',', $original_mode);
			$mode_list[] = 'NO_ENGINE_SUBSTITUTION'; $mode_list[] = 'STRICT_ALL_TABLES';
			// Restore even if the SET took effect but its acknowledgment was lost.
			$restore_mode = true;
			$database->sql_query("SET SESSION sql_mode = '" . $database->sql_escape(implode(',', array_unique($mode_list))) . "'");
			// Engine-only conversion preserves all columns, keys, and current rows.
			// Never cap or delete sessions to fit the old 500-row MEMORY setting.
			// The table wrapper rechecks authority before and after submitted DDL.
			$database->sql_query('ALTER TABLE ' . chr(96) . SESSIONS_TABLE . chr(96) . ' ENGINE=InnoDB MAX_ROWS=0');
			if (dbmtnc_session_storage_engine($database) !== 'INNODB') { phpbb_acl_error('Session_storage_failed'); }
			dbmtnc_date_actor($database);
			$outcome = 'Session_storage_converted';
		}
	}
	catch (Exception $error) { $failure = $error; }
	catch (Throwable $error) { $failure = $error; }
	finally
	{
		if ($restore_mode)
		{
			try { $database->sql_query("SET SESSION sql_mode = '" . $database->sql_escape($original_mode) . "'"); }
			catch (Exception $error) { if ($failure === null) { $failure = $error; } }
			catch (Throwable $error) { if ($failure === null) { $failure = $error; } }
		}
		if ($scope !== null) { dbmtnc_table_end($database, $scope); }
	}
	if ($failure instanceof PhpbbAclException) { throw $failure; }
	if ($failure !== null) { phpbb_acl_error('Session_storage_failed'); }
	return $outcome;
}

