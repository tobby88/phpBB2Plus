<?php
$forum_root = dirname(dirname(__DIR__)) . '/phpBB2/';
define('IN_PHPBB', true);
define('CTRACKER_ACP', true);
define('CTRACKER_FILECHK', 'test_filechk');
define('CTRACKER_FILESCANNER', 'test_filescan');
define('CRITICAL_ERROR', 1);
class ScanExit extends RuntimeException {}
function message_die($level, $message) { throw new ScanExit($message); }
function phpbb_admin_post_string($key) { return isset($_POST[$key]) ? $_POST[$key] : ''; }
function phpbb_admin_require_post_session() { $GLOBALS['scan_session_checks']++; }
function scan_assert($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
class ScanDatabase
{
	var $queries = array();
	var $fail = '';
	function sql_query($sql)
	{
		$this->queries[] = $sql;
		$GLOBALS['scan_events'][] = $sql;
		return $this->fail === '' || strpos($sql, $this->fail) !== 0;
	}
	function sql_escape($value) { return str_replace("'", "''", $value); }
	function sql_fetchrow($result) { return false; }
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
	global $db, $lang, $phpbb_root_path, $phpEx, $forum_root, $scan_events, $scan_session_checks;
	$phpbb_root_path = $root; $phpEx = 'php';
	$db = new ScanDatabase(); $scan_events = array(); $scan_session_checks = 0;
	$ctracker_config = new ScanConfig(); $template = new ScanTemplate();
	$_POST = array('action' => 'akt'); $_GET = array();
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
$phpEx = 'php'; $phpbb_root_path = $root;
try
{
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
			$published = strpos(implode("\n", $db->queries), 'RENAME TABLE') !== false;
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
				scan_assert($outcome === 'file failure' && strpos(implode("\n", $db->queries), 'RENAME TABLE') === false, 'Real unreadable directory must preserve ' . $kind . ' report');
			}
		}
		chmod($root . '/blocked', 0700);
		chmod($root . '/broken.php', 0000); clearstatcache();
		if (!is_readable($root . '/broken.php'))
		{
			$db = new ScanDatabase(); $scan_events = array(); $admin = new ct_adminfunctions();
			$outcome = 'success';
			try { $admin->do_filechk(); } catch (ScanExit $error) { $outcome = $error->getMessage(); }
			scan_assert($outcome === 'file failure' && strpos(implode("\n", $db->queries), 'RENAME TABLE') === false, 'Real unreadable file must preserve integrity baseline');
		}
		chmod($root . '/broken.php', 0600);
	}
	list($outcome, $settings, $events) = scan_controller($root . '/missing');
	scan_assert($outcome === 'file failure' && $settings['last_checksum_scan'] === 123 && !in_array('timestamp', $events, true), 'Failed baseline rebuild must retain its previous timestamp');
	list($outcome, $settings, $events) = scan_controller($root);
	$rename_index = null;
	foreach ($events as $index => $event) { if (strpos($event, 'RENAME TABLE') === 0) { $rename_index = $index; } }
	scan_assert($outcome === 'complete' && $scan_session_checks === 1 && $rename_index !== null &&
		$rename_index < array_search('timestamp', $events, true) && end($events) === 'complete', 'Timestamp and success must follow baseline publication');
	echo "CrackerTracker scan publication runtime tests passed.\n";
}
finally
{
	chmod($root . '/blocked', 0700); chmod($root . '/broken.php', 0600);
	unlink($root . '/good.php'); unlink($root . '/broken.php');
	unlink($root . '/blocked/inside.php'); unlink($root . '/cache/ignored.php');
	rmdir($root . '/blocked'); rmdir($root . '/cache'); rmdir($root);
}
