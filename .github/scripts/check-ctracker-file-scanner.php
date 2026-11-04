<?php

define('IN_PHPBB', true);
define('CTRACKER_ACP', true);
define('CTRACKER_FILESCANNER', 'phpbb_ctracker_filescanner');
require dirname(dirname(__DIR__)) . '/phpBB2/ctracker/classes/class_ct_adminfunctions.php';

function filescan_test_assert($condition, $message)
{
	if (!$condition)
	{
		throw new RuntimeException("File-scanner test failed: $message");
	}
}

class filescan_test_db
{
	var $queries = array();
	var $rows = array();
	function sql_escape($value) { return addslashes((string) $value); }
	function sql_query($sql)
	{
		$this->queries[] = $sql;
		return (strpos($sql, 'SELECT id, filepath FROM') === 0) ? 'scan-result' : true;
	}
	function sql_fetchrow($result)
	{
		return ($result === 'scan-result' && $this->rows) ? array_shift($this->rows) : false;
	}
}

$scan_root = sys_get_temp_dir() . '/ctracker-scan-test-' . getmypid() . '-' . str_replace('.', '', uniqid('', true));
$cache_root = $scan_root . '/cache';
if (file_exists($scan_root) || !mkdir($scan_root, 0700)) { throw new RuntimeException('Owned scanner fixture unavailable'); }
$outside_file = false;
$link_created = false;
try
{
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
}

$source = file_get_contents(dirname(dirname(__DIR__)) . '/phpBB2/ctracker/classes/class_ct_adminfunctions.php');
filescan_test_assert(strpos($source, 'CTracker_Ignore') === false && strpos(strtolower($source), 'ctracker_ignore') === false, 'source comments must not bypass scanning');
filescan_test_assert(strpos($source, '$this->publish_stage($db, CTRACKER_FILESCANNER,') !== false && strpos($source, 'START TRANSACTION') !== false, 'completed results must publish transactionally');
filescan_test_assert(strpos($source, 'SELECT MAX(id) AS total') === false, 'file indexing must not perform one MAX query per file');

$db->queries = array();
$db->rows = array(
	array('id' => 1, 'filepath' => $scan_root . "/quote's.php"),
	array('id' => 2, 'filepath' => $outside_file)
);
if ($link_created)
{
	$db->rows[] = array('id' => 3, 'filepath' => $link_path);
}
$admin->ScanFile('temporary_scanner_table');
$scan_sql = implode("\n", $db->queries);
filescan_test_assert(strpos($scan_sql, 'SET safety = 4 WHERE id = 1') !== false, 'global code on the PHP opening-tag line must be analyzed');
filescan_test_assert(strpos($scan_sql, 'SET safety = 10 WHERE id = 2') !== false, 'a persisted path outside the forum tree must not be read');
if ($link_created)
{
	filescan_test_assert(strpos($scan_sql, 'SET safety = 10 WHERE id = 3') !== false, 'a persisted symbolic link must not be read');
}

// Scan the real files through the complete production classifier; never execute them.
$guard_cases = array(
	array("if (!defined('IN_PHPBB')) { die('Denied'); }", 0),
	array('if (!defined("IN_PHPBB")) { exit; }', 0),
	array("if (!defined('IN_PHPBB')) die('Denied');", 0),
	array("if (!defined('IN_PHPBB')) { return; }", 0),
	array("if (!defined('IN_PHPBB') || !defined('CTRACKER_ACP')) { die('Denied'); }", 0),
	array("if (!defined('OTHER') || !defined('IN_PHPBB')) { exit; }", 0),
	array("/* before */\nif /* comment */ ( ! DEFINED /* call */ ( 'IN_PHPBB' ) )\n{\n die('Denied');\n}", 0),
	array("if (!defined('IN_PHPBB')) {}", 4),
	array("if (!defined('IN_PHPBB')) { echo 'still running'; }", 4),
	array("if (!defined('IN_PHPBB'));", 4),
	array("if (!defined('IN_PHPBB')) { if (false) { die('Denied'); } }", 4),
	array("if (!defined('IN_PHPBB') && !defined('OTHER')) { die('Denied'); }", 4),
	array("if (!defined('OTHER')) { die('Denied'); }", 4),
	array("if (defined('IN_PHPBB')) { die('Denied'); }", 4),
	array("if (false) { if (!defined('IN_PHPBB')) { die('Denied'); } }", 4),
	array("\$example = \"if (!defined('IN_PHPBB')) { die('Denied'); }\";", 4),
	array("// if (!defined('IN_PHPBB')) { die('Denied'); }\necho 'entry';", 4),
	array("if (!defined('IN_PHPBB') === false) { die('Denied'); }", 4),
	array("if (!defined('IN_PHPBB') || \$optional) { echo 'still running'; }", 4)
);
$declaration_cases = array(
	array('function example() {}', 0),
	array('function first() {} function second() {}', 0),
	array('function &example() { static $value; return $value; }', 0),
	array('class Example { function run() {} }', 0),
	array('abstract class Example { abstract function run(); }', 0),
	array('final class Example { function run() {} }', 0),
	array('interface Example { function run(); }', 0),
	array('trait Example { function run() {} }', 0),
	array('function example($value) { return "{$value}"; }', 0),
	array("function example() {}\n?>\n ", 0),
	array("function example() {}\necho 'global tail';", 4),
	array("function example() {} echo 'global tail';", 4),
	array("class Example { function run() {} }\necho 'global tail';", 4),
	array("\$description = 'class example';\necho 'global tail';", 4),
	array("\$description = 'function example';\necho 'global tail';", 4),
	array("echo 'global prefix';\nfunction example() {}", 4),
	array("(function () { echo 'called'; })();", 4)
);
$bootstrap_source = <<<'BOOTSTRAP'
define('IN_PHPBB', true);
$phpbb_root_path = './';
include($phpbb_root_path . 'extension.inc');
include($phpbb_root_path . 'common.' . $phpEx);
echo 'body';
BOOTSTRAP;
$bootstrap_example = <<<'EXAMPLE'
$example = "define('IN_PHPBB', true);";
$example = '$phpbb_root_path="./";';
$example = "include($phpbb_root_path . 'extension.inc');";
$example = "include($phpbb_root_path . 'common.' . $phpEx);";
echo 'body';
EXAMPLE;
$bootstrap_cases = array(
	array($bootstrap_source, 0),
	array(str_replace("'", '"', $bootstrap_source), 0),
	array(str_replace('include(', 'require(', $bootstrap_source), 0),
	array($bootstrap_example, 4),
	array(preg_replace('/^/m', '// ', $bootstrap_source) . "\necho 'body';", 4),
	array("/*\n" . $bootstrap_source . "\n*/\necho 'body';", 4),
	array('$example = <<<TEXT' . "\n" . $bootstrap_source . "\nTEXT;\necho 'body';", 4),
	array("\$example = <<<'TEXT'\n" . $bootstrap_source . "\nTEXT;\necho 'body';", 4),
	array(var_export($bootstrap_source, true) . ";\necho 'body';", 4),
	array(str_replace('include(', 'not_include(', $bootstrap_source), 3),
	array(str_replace('include(', '$loader->include(', $bootstrap_source), 3),
	array(str_replace('define(', '$loader->define(', $bootstrap_source), 4)
);
$guard_files = array();
try
{
	foreach (array('entry guards' => $guard_cases, 'declarations' => $declaration_cases, 'bootstrap tokens' => $bootstrap_cases) as $group => $cases)
	{
		foreach ($cases as $number => $case)
		{
			$path = $scan_root . '/case-' . count($guard_files) . '.php';
			$guard_files[] = $path;
			file_put_contents($path, "<?php\n" . $case[0] . ($group === 'entry guards' ? "\necho 'reachable body';\n" : "\n"));
			$db->queries = array();
			$db->rows = array(array('id' => 91, 'filepath' => $path));
			$admin->ScanFile('temporary_scanner_table');
			filescan_test_assert(in_array('UPDATE temporary_scanner_table SET safety = ' . $case[1] . ' WHERE id = 91', $db->queries, true),
				'actual scanned ' . $group . ' case ' . $number);
		}
		echo 'CrackerTracker ' . $group . ': ' . count($cases) . " actual scanned-file cases passed.\n";
	}
}
finally
{
	foreach ($guard_files as $path) { @unlink($path); }
}

}
finally
{
@unlink($scan_root . "/quote's.php");
@unlink($cache_root . '/cached.php');
if ($link_created)
{
	@unlink($link_path);
}
@rmdir($cache_root);
@rmdir($scan_root);
if (is_string($outside_file)) { @unlink($outside_file); }
}

echo "CrackerTracker file-scanner tests passed.\n";
