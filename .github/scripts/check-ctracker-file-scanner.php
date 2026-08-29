<?php

define('IN_PHPBB', true);
define('CTRACKER_ACP', true);
define('CTRACKER_FILESCANNER', 'phpbb_ctracker_filescanner');
require dirname(dirname(__DIR__)) . '/phpBB2/ctracker/classes/class_ct_adminfunctions.php';

function filescan_test_assert($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, "File-scanner test failed: $message\n");
		exit(1);
	}
}

class filescan_test_db
{
	var $queries = array();
	function sql_escape($value) { return addslashes((string) $value); }
	function sql_query($sql) { $this->queries[] = $sql; return true; }
}

$scan_root = sys_get_temp_dir() . '/ctracker-scan-test-' . getmypid();
$cache_root = $scan_root . '/cache';
mkdir($cache_root, 0700, true);
file_put_contents($scan_root . "/quote's.php", "<?php echo 'review';\n");
file_put_contents($cache_root . '/cached.php', "<?php echo 'ignored';\n");
$outside_file = tempnam(sys_get_temp_dir(), 'ctracker-outside-');
file_put_contents($outside_file, "<?php echo 'outside';\n");
$link_path = $scan_root . '/outside.php';
$link_created = function_exists('symlink') ? @symlink($outside_file, $link_path) : false;

$db = new filescan_test_db();
$lang = array('ctracker_error_database_op' => 'database error');
$admin = new ct_adminfunctions();
$admin->filescan_root = str_replace('\\', '/', realpath($scan_root));
$admin->CreateFileList($scan_root, '', 'php', 'temporary_scanner_table');

$sql = implode("\n", $db->queries);
filescan_test_assert($admin->filescan_count === 1, 'only the ordinary PHP file should be indexed');
filescan_test_assert(strpos($sql, 'temporary_scanner_table') !== false, 'the requested staging table must be used');
filescan_test_assert(strpos($sql, "quote\\'s.php") !== false, 'stored file paths must be SQL escaped');
filescan_test_assert(strpos($sql, 'cached.php') === false, 'cache files must be excluded');
if ($link_created)
{
	filescan_test_assert(strpos($sql, 'outside.php') === false, 'symbolic links must be excluded');
	@unlink($link_path);
}

$source = file_get_contents(dirname(dirname(__DIR__)) . '/phpBB2/ctracker/classes/class_ct_adminfunctions.php');
filescan_test_assert(strpos($source, 'CTracker_Ignore') === false && strpos(strtolower($source), 'ctracker_ignore') === false, 'source comments must not bypass scanning');
filescan_test_assert(strpos($source, 'RENAME TABLE') !== false, 'completed results must be swapped atomically');
filescan_test_assert(strpos($source, 'SELECT MAX(id) AS total') === false, 'file indexing must not perform one MAX query per file');

@unlink($scan_root . "/quote's.php");
@unlink($cache_root . '/cached.php');
@rmdir($cache_root);
@rmdir($scan_root);
@unlink($outside_file);

echo "CrackerTracker file-scanner tests passed.\n";
