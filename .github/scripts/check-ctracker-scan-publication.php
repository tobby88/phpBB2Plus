<?php
$forum_root = dirname(dirname(__DIR__)) . '/phpBB2/';
define('IN_PHPBB', true);
define('CTRACKER_ACP', true);
define('CTRACKER_FILECHK', 'test_filechk');
define('CTRACKER_FILESCANNER', 'test_filescan');
define('CRITICAL_ERROR', 1);
require __DIR__ . '/ctracker-admin-authority-fixture.php';
class ScanExit extends RuntimeException {}
function message_die($level, $message) { throw new ScanExit($message); }
function phpbb_admin_post_string($key) { return isset($_POST[$key]) ? $_POST[$key] : ''; }
function phpbb_admin_require_post_session() { $GLOBALS['scan_session_checks']++; }
function scan_assert($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
class ScanDatabase
{
	var $server = 'fixture';
	var $user = 'fixture';
	var $password = '';
	var $dbname = 'fixture';
	var $queries = array();
	var $fail = '';
	var $modern_storage = '1';
	var $stage_count = 0;
	var $timestamp = 0;
	function sql_query($sql)
	{
		if ($authority = ct_fixture_authority_query($sql)) { return $authority; }
		if (preg_match("/^INSERT INTO fixture_ct_config .* SELECT '([^']+)','([0-9]+)' WHERE /",$sql,$m)) { $this->timestamp=$m[2]; }
		if (strpos($sql,'SELECT ct_config_value FROM fixture_ct_config WHERE ')===0) { return new CtFixtureAuthorityResult(array(array('ct_config_value'=>$this->timestamp))); }
		if (strpos($sql, 'INSERT INTO test_filechk_new ') === 0 || strpos($sql, 'INSERT INTO test_filescan_new ') === 0) { $this->stage_count++; }
		if (strpos($sql, 'SELECT COUNT(*) AS stage_count FROM ') === 0) { return new CtFixtureAuthorityResult(array(array('stage_count'=>$this->stage_count))); }
		$this->queries[] = $sql;
		$GLOBALS['scan_events'][] = $sql;
		if (strpos($sql, 'SELECT COUNT(*) AS modern_storage') === 0 && $this->fail === '') { return 'storage'; }
		return $this->fail === '' || strpos($sql, $this->fail) !== 0;
	}
	function sql_escape($value) { return str_replace("'", "''", $value); }
	function sql_fetchrow($result) { if ($result instanceof CtFixtureAuthorityResult) { return $result->rows ? array_shift($result->rows) : false; } return $result === 'storage' ? array('modern_storage' => $this->modern_storage) : false; }
	function sql_affectedrows() { return $this->stage_count; }
}
// Lock transport fixture; concurrency and connection failure behavior are
// covered separately by check-ctracker-scan-lock.php.
class sql_db
{
	var $db_connect_id = true;
	var $database;
	function __construct($server, $user, $password, $dbname, $persistent) { $this->database = $GLOBALS['db']; }
	function sql_query($sql) { return strpos($sql, 'SELECT GET_LOCK(') === 0 ? 'lock' : $this->database->sql_query($sql); }
	function sql_fetchrow($result) { return $result === 'lock' ? array('acquired' => '1') : $this->database->sql_fetchrow($result); }
	function sql_escape($value) { return $this->database->sql_escape($value); }
	function sql_freeresult($result) {}
	function sql_fetchrowset($result) { $rows=array(); while ($row=$this->sql_fetchrow($result)) { $rows[]=$row; } return $rows; }
	function sql_affectedrows() { return $this->database->sql_affectedrows(); }
	function sql_close() {}
}
require $forum_root . 'ctracker/classes/class_ct_adminfunctions.php';
class FailingScanAdmin extends ct_adminfunctions
{
	var $failure = '';
	function recursive_filechk($dir, $prefix = '', $extension = '', $target_table = '')
	{
		if ($this->failure === 'top') { $this->filechk_count = 1; return false; }
		if (($this->failure === 'nested' && basename($dir) === 'blocked') ||
			($this->failure === 'excluded' && basename($dir) === 'cache')) { return false; }
		return parent::recursive_filechk($dir, $prefix, $extension, $target_table);
	}
	function CreateFileList($dir, $prefix = '', $extension = '', $target_table = '')
	{
		if ($this->failure === 'top') { $this->filescan_count = 1; return false; }
		if (($this->failure === 'nested' && basename($dir) === 'blocked') ||
			($this->failure === 'excluded' && basename($dir) === 'cache')) { return false; }
		return parent::CreateFileList($dir, $prefix, $extension, $target_table);
	}
	function file_checksum($path, $required_root = '')
	{
		if ($this->failure === 'hash' && basename($path) === 'broken.php') { return false; }
		return parent::file_checksum($path, $required_root);
	}
}
class ScanConfig
{
	var $settings = array('last_checksum_scan' => 123);
	function change_configuration($key, $value)
	{
		$GLOBALS['scan_events'][] = 'timestamp';
		$this->settings[$key] = $value;
	}
}
class ScanTemplate
{
	function assign_block_vars($name, $vars)
	{
		if ($name === 'akt_complete') { $GLOBALS['scan_events'][] = 'complete'; throw new ScanExit('complete'); }
	}
}
function scan_controller($root)
{
	global $db, $lang, $phpbb_root_path, $phpEx, $forum_root, $scan_events, $scan_session_checks, $ctracker_config;
	$phpbb_root_path = $root; $phpEx = 'php';
	$db = new ScanDatabase(); $scan_events = array(); $scan_session_checks = 0;
	$ctracker_config = new ScanConfig(); $template = new ScanTemplate();
	$_POST = array('action' => 'akt', 'sid'=>'fixture-admin'); $_GET = array();
	try { include $forum_root . 'ctracker/admin/acp_module_changedfiles.php'; }
	catch (ScanExit $error) { $outcome = $error->getMessage(); }
	return array($outcome, $ctracker_config->settings, $scan_events);
}
$root = sys_get_temp_dir() . '/ct-scan-publication-' . md5(uniqid('', true));
mkdir($root, 0700); mkdir($root . '/blocked'); mkdir($root . '/cache');
file_put_contents($root . '/good.php', "<?php echo 'good';");
file_put_contents($root . '/broken.php', "<?php echo 'broken fixture';");
file_put_contents($root . '/blocked/inside.php', "<?php echo 'inside';");
file_put_contents($root . '/cache/ignored.php', "<?php echo 'cache';");
$lang = array('ctracker_error_fileop' => 'file failure', 'ctracker_error_database_op' => 'database failure', 'ctracker_fchk_update_action' => 'complete');
$lang['ctracker_error_storage_migration'] = 'migration required';
$phpEx = 'php'; $phpbb_root_path = $root;
try
{
	foreach (array('checksum', 'scanner') as $kind)
	{
		foreach (array('0', null) as $legacy)
		{
			$db = new ScanDatabase(); $db->modern_storage = $legacy; $scan_events = array(); $admin = new FailingScanAdmin();
			$outcome = 'success';
			try { if ($kind === 'checksum') { $admin->do_filechk(); } else { $admin->RunFileScan($root, 'php'); } }
			catch (ScanExit $error) { $outcome = $error->getMessage(); }
			scan_assert($outcome === 'migration required' && !preg_match('/(?:CREATE|DROP|RENAME) TABLE/', implode("\n", $db->queries)), 'Legacy or unavailable storage metadata must stop before modifying any scan table');
		}
	}
	foreach (array('checksum', 'scanner') as $kind)
	{
		foreach (array('top', 'nested', 'hash', '', 'excluded') as $failure)
		{
			if ($kind === 'scanner' && $failure === 'hash') { continue; }
			$db = new ScanDatabase(); $scan_events = array();
			$admin = new FailingScanAdmin(); $admin->failure = $failure;
			$outcome = 'success';
			try { if ($kind === 'checksum') { $admin->do_filechk(); } else { $admin->RunFileScan($root, 'php'); } }
			catch (ScanExit $error) { $outcome = $error->getMessage(); }
			$published = in_array('COMMIT', $db->queries, true);
			$valid = $failure === '' || $failure === 'excluded';
			scan_assert($valid ? $outcome === 'success' && $published : $outcome === 'file failure' && !$published,
				$kind . ' must preserve the old report after a traversal/hash failure: ' . $failure);
			if ($valid) { scan_assert(($kind === 'checksum' ? $admin->filechk_count : $admin->filescan_count) === 3, 'Expected complete file coverage excluding cache'); }
		}
	}
	if (DIRECTORY_SEPARATOR === '/')
	{
		// On a non-root Unix CLI, also reproduce actual OS read failures.
		chmod($root . '/blocked', 0000); clearstatcache();
		if (!is_readable($root . '/blocked'))
		{
			foreach (array('checksum', 'scanner') as $kind)
			{
				$db = new ScanDatabase(); $scan_events = array(); $admin = new ct_adminfunctions();
				$outcome = 'success';
				try { if ($kind === 'checksum') { $admin->do_filechk(); } else { $admin->RunFileScan($root, 'php'); } }
				catch (ScanExit $error) { $outcome = $error->getMessage(); }
				scan_assert($outcome === 'file failure' && !in_array('COMMIT', $db->queries, true), 'Real unreadable directory must preserve ' . $kind . ' report');
			}
		}
		chmod($root . '/blocked', 0700);
		chmod($root . '/broken.php', 0000); clearstatcache();
		if (!is_readable($root . '/broken.php'))
		{
			$db = new ScanDatabase(); $scan_events = array(); $admin = new ct_adminfunctions();
			$outcome = 'success';
			try { $admin->do_filechk(); } catch (ScanExit $error) { $outcome = $error->getMessage(); }
			scan_assert($outcome === 'file failure' && !in_array('COMMIT', $db->queries, true), 'Real unreadable file must preserve integrity baseline');
		}
		chmod($root . '/broken.php', 0600);
	}
	list($outcome, $settings, $events) = scan_controller($root . '/missing');
	scan_assert($outcome === 'file failure' && $settings['last_checksum_scan'] === 123 && !in_array('timestamp', $events, true), 'Failed baseline rebuild must retain its previous timestamp');
	list($outcome, $settings, $events) = scan_controller($root);
	$commit_index = null; $timestamp_index = null;
	foreach ($events as $index => $event) { if ($event === 'COMMIT') { $commit_index = $index; } if (strpos($event,'INSERT INTO fixture_ct_config ')===0) { $timestamp_index=$index; } }
	scan_assert($outcome === 'complete' && $scan_session_checks === 1 && $commit_index !== null && $timestamp_index !== null &&
		array_search('START TRANSACTION',$events,true)<$timestamp_index && $timestamp_index<$commit_index && $settings['last_checksum_scan']>123 && end($events)==='complete', 'Report and timestamp must commit together before success');
	echo "CrackerTracker scan publication runtime tests passed.\n";
}
finally
{
	chmod($root . '/blocked', 0700); chmod($root . '/broken.php', 0600);
	unlink($root . '/good.php'); unlink($root . '/broken.php');
	unlink($root . '/blocked/inside.php'); unlink($root . '/cache/ignored.php');
	rmdir($root . '/blocked'); rmdir($root . '/cache'); rmdir($root);
}
