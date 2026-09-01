<?php

define('IN_PHPBB', true);
define('CTRACKER_ACP', true);
define('CTRACKER_BACKUP', 'phpbb_ctracker_backup');
define('CONFIG_TABLE', 'phpbb_config');
require dirname(dirname(__DIR__)) . '/phpBB2/ctracker/classes/class_ct_adminfunctions.php';

function recovery_test_assert($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, "Recovery test failed: $message\n");
		exit(1);
	}
}

class recovery_test_db
{
	var $queries = array();
	var $rows = array(
		array('config_name' => 'server_name', 'config_value' => 'forum.example'),
		array('config_name' => 'site_desc', 'config_value' => "A board's description")
	);
	function sql_escape($value) { return addslashes((string) $value); }
	function sql_query($sql)
	{
		$this->queries[] = $sql;
		return (strpos($sql, 'SELECT * FROM phpbb_config') === 0) ? 'config-result' : true;
	}
	function sql_fetchrow($result)
	{
		return ($result === 'config-result' && $this->rows) ? array_shift($this->rows) : false;
	}
}

class recovery_restore_test_db
{
	var $queries = array();
	var $restore_rows = array(
		array('config_name' => 'server_name', 'config_value' => 'restored.example'),
		array('config_name' => 'site_desc', 'config_value' => "Restored board's description")
	);
	var $marker_returned = false;
	function sql_escape($value) { return addslashes((string) $value); }
	function sql_query($sql)
	{
		$this->queries[] = $sql;
		if (strpos($sql, "config_name = 'ct_last_backup'") !== false) return 'marker-result';
		if (strpos($sql, "config_name <> 'ct_last_backup'") !== false) return 'restore-result';
		return true;
	}
	function sql_fetchrow($result)
	{
		if ($result === 'marker-result' && !$this->marker_returned)
		{
			$this->marker_returned = true;
			return array('config_value' => '123456');
		}
		return ($result === 'restore-result' && $this->restore_rows) ? array_shift($this->restore_rows) : false;
	}
	function sql_freeresult($result) { return true; }
}

$db = new recovery_test_db();
$lang = array('ctracker_error_database_op' => 'database error', 'ctracker_error_loading_config' => 'config error');
$admin = new ct_adminfunctions();
$admin->recover_configuration();
$sql = implode("\n", $db->queries);

recovery_test_assert(strpos($sql, 'CREATE TABLE phpbb_ctracker_backup_new LIKE phpbb_ctracker_backup') !== false, 'snapshot must be written to a staging table');
recovery_test_assert(strpos($sql, 'INSERT INTO phpbb_ctracker_backup_new') !== false, 'configuration values must target the staging table');
recovery_test_assert(strpos($sql, 'RENAME TABLE phpbb_ctracker_backup TO phpbb_ctracker_backup_old, phpbb_ctracker_backup_new TO phpbb_ctracker_backup') !== false, 'completed snapshot must be swapped atomically');
recovery_test_assert(strpos($sql, 'DELETE FROM phpbb_ctracker_backup') === false, 'the previous snapshot must not be emptied first');
recovery_test_assert(strpos($sql, "board\\'s description") !== false, 'snapshot values must be SQL escaped');

$source = file_get_contents(dirname(dirname(__DIR__)) . '/phpBB2/ctracker/classes/class_ct_adminfunctions.php');
recovery_test_assert(strpos($source, "@unlink(\$phpbb_root_path . 'cache/config_data.cache')") !== false, 'restore must invalidate the configuration cache');
recovery_test_assert(strpos($source, '$restored_values < 1') !== false, 'an empty snapshot must not report a successful restore');

$restore_root = sys_get_temp_dir() . '/ctracker-recovery-test-' . getmypid() . '/';
mkdir($restore_root . 'cache', 0700, true);
file_put_contents($restore_root . 'cache/config_data.cache', 'stale');
$phpbb_root_path = $restore_root;
$db = new recovery_restore_test_db();
$admin->restore_configuration();
$restore_sql = implode("\n", $db->queries);
recovery_test_assert(strpos($restore_sql, "SELECT config_value FROM phpbb_ctracker_backup WHERE config_name = 'ct_last_backup' LIMIT 1") !== false, 'restore must require a completed snapshot marker');
recovery_test_assert(strpos($restore_sql, "WHERE config_name <> 'ct_last_backup'") !== false, 'restore must exclude its metadata row at the query boundary');
recovery_test_assert(strpos($restore_sql, "Restored board\\'s description") !== false, 'restored configuration values must be SQL escaped');
recovery_test_assert(!file_exists($restore_root . 'cache/config_data.cache'), 'restore must remove the stale configuration cache');
@rmdir($restore_root . 'cache');
@rmdir($restore_root);

echo "CrackerTracker configuration-recovery tests passed.\n";
