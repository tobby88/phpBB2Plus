<?php

date_default_timezone_set('UTC');

$test_root = sys_get_temp_dir() . '/ctracker-log-test-' . getmypid() . '/';
$log_root = $test_root . 'ctracker/logfiles/';
if (!mkdir($log_root, 0700, true))
{
	fwrite(STDERR, "Could not create log-manager test directory.\n");
	exit(1);
}
mkdir($test_root . 'cache', 0700, true);
$test_error_log = $test_root . 'php-errors.log';
ini_set('error_log', $test_error_log);

function phpbb_data_cache_read($filename)
{
	$value = @unserialize(@file_get_contents($filename));
	return is_array($value) ? $value : false;
}

function phpbb_data_cache_write($filename, $value)
{
	return file_put_contents($filename, serialize($value), LOCK_EX) !== false;
}

$files = array(
	'logfile_attempt_counter.txt' => '0',
	'logfile_worms.txt' => "1|||0|||null|||null|||null|||null|||null\n",
	'logfile_blocklist.txt' => "1|||0|||null|||null|||null|||null|||null\n",
	'logfile_malformed_logins.txt' => "1|||0|||null|||null|||null|||null|||null\n",
	'logfile_spammer.txt' => "1|||0|||null|||null|||null|||null|||null\n",
	'logfile_debug_mode.txt' => ''
);
foreach ($files as $name => $contents)
{
	file_put_contents($log_root . $name, $contents);
}

$phpbb_root_path = $test_root;
$HTTP_SERVER_VARS = array(
	'PHP_SELF' => '/index.php',
	'QUERY_STRING' => 'safe=1&password=SuperSecret&sid=SessionSecret&act_key=ActivationSecret&form%5Btoken%5D=NestedSecret',
	'REMOTE_ADDR' => '192.0.2.1',
	'HTTP_USER_AGENT' => "Test\r\nInjected: no",
	'HTTP_REFERER' => 'https://forum.example/profile.php?token=RefererSecret&mode=editprofile'
);
$HTTP_ENV_VARS = array();
require dirname(dirname(__DIR__)) . '/phpBB2/ctracker/classes/class_log_manager.php';

$manager = new log_manager();
$manager->write_general_logfile(2, 3);
$manager->write_general_logfile(2, 3);
$manager->write_general_logfile(2, 3);

$errors = array();
if ($manager->get_counter_value() !== 3)
{
	$errors[] = 'Rotated log entries were not counted exactly once.';
}
$counter_cache = $test_root . 'cache/ctracker_counter.cache';
if (!is_file($counter_cache))
{
	$errors[] = 'The footer counter did not create its bounded cache.';
}
file_put_contents($log_root . 'logfile_worms.txt', "1|||0|||null|||null|||null|||null|||null\n0|||1|||request|||referrer|||agent|||ip|||host\n");
if ($manager->get_counter_value() !== 3)
{
	$errors[] = 'The footer counter ignored its valid cache.';
}
$manager->write_to_log(2, '0|||2|||request|||referrer|||agent|||ip|||host');
if (is_file($counter_cache))
{
	$errors[] = 'Writing a security event did not invalidate the footer counter cache.';
}
if ($manager->get_counter_value() !== 5)
{
	$errors[] = 'The invalidated footer counter was not recomputed.';
}
$stored_lines = file($log_root . 'logfile_blocklist.txt');
if (!is_array($stored_lines) || count($stored_lines) !== 2)
{
	$errors[] = 'The configured log-size cap was not enforced exactly.';
}
$stored_log = implode('', (array) $stored_lines);
if (strpos($stored_log, "\r") !== false || substr_count($stored_log, "\n") !== 2)
{
	$errors[] = 'Header newline sanitization failed.';
}
if (strpos($stored_log, 'SuperSecret') !== false || strpos($stored_log, 'SessionSecret') !== false ||
	strpos($stored_log, 'ActivationSecret') !== false || strpos($stored_log, 'NestedSecret') !== false ||
	strpos($stored_log, 'RefererSecret') !== false || substr_count($stored_log, 'REDACTED') < 5)
{
	$errors[] = 'Sensitive query-value redaction failed.';
}

if (!$manager->write_to_log(6, "debug\r\ninjected\0value"))
{
	$errors[] = 'A valid debug-log write was rejected.';
}
$debug_contents = file_get_contents($log_root . 'logfile_debug_mode.txt');
if ($debug_contents !== "debuginjectedvalue\n")
{
	$errors[] = 'The low-level log writer allowed multiline or NUL injection.';
}
if ($manager->write_to_log(99, 'invalid') !== false)
{
	$errors[] = 'An invalid logfile identifier was accepted.';
}

$manager->write_to_log(5, '0|||1|||first|||referrer|||agent|||ip|||host');
$manager->write_to_log(5, '0|||2|||second|||referrer|||agent|||ip|||host');
if (!$manager->delete_logfile(5) || $manager->last_deleted_entries !== 2 || $manager->check_log_size(5) !== 0)
{
	$errors[] = 'Log reset did not count and truncate entries under one lock.';
}

$large_debug = '';
for ($i = 0; $i < 1100; $i++)
{
	$large_debug .= 'line-' . $i . "\n";
}
file_put_contents($log_root . 'logfile_debug_mode.txt', $large_debug);
$bounded_lines = $manager->read_log_lines(6, 1000);
if (count($bounded_lines) !== 1000 || trim($bounded_lines[0]) !== 'line-100' || trim($bounded_lines[999]) !== 'line-1099')
{
	$errors[] = 'ACP logfile reads are not bounded to the newest requested lines.';
}

$outside_log = tempnam(sys_get_temp_dir(), 'ctracker-log-outside-');
$debug_path = $log_root . 'logfile_debug_mode.txt';
unlink($debug_path);
$log_link_created = function_exists('symlink') ? @symlink($outside_log, $debug_path) : false;
if ($log_link_created)
{
	if ($manager->write_to_log(6, 'must-not-follow') !== false || $manager->read_log_lines(6, 10) !== array())
	{
		$errors[] = 'A symbolic-link logfile target was followed.';
	}
	unlink($debug_path);
}
file_put_contents($debug_path, '');
unlink($outside_log);

foreach (array_keys($files) as $name)
{
	unlink($log_root . $name);
}
if (is_file($counter_cache))
{
	unlink($counter_cache);
}
rmdir($log_root);
rmdir($test_root . 'cache');
rmdir($test_root . 'ctracker');
if (is_file($test_error_log))
{
	unlink($test_error_log);
}
rmdir($test_root);

if ($errors)
{
	fwrite(STDERR, implode("\n", $errors) . "\n");
	exit(1);
}

$htaccess = file_get_contents(dirname(dirname(__DIR__)) . '/phpBB2/ctracker/logfiles/.htaccess');
if (strpos($htaccess, 'Require all denied') === false || strpos($htaccess, 'Allow from localhost') !== false)
{
	fwrite(STDERR, "CrackerTracker log files are not fully denied over HTTP.\n");
	exit(1);
}

$logmanager_source = file_get_contents(dirname(dirname(__DIR__)) . '/phpBB2/ctracker/admin/acp_module_logmanager.php');
$download_source = file_get_contents(dirname(dirname(__DIR__)) . '/phpBB2/admin/admin_cracker_tracker.php');
if (strpos($logmanager_source, 'read_log_lines(') === false || strpos($logmanager_source, '@file(') !== false)
{
	fwrite(STDERR, "The ACP still performs an unbounded direct logfile read.\n");
	exit(1);
}
if (strpos($download_source, '@is_link($log_filepath)') === false)
{
	fwrite(STDERR, "The debug-log download can follow a symbolic link.\n");
	exit(1);
}

echo "CrackerTracker log rotation and counter checks passed.\n";

?>
