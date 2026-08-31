<?php

$test_root = sys_get_temp_dir() . '/ctracker-log-test-' . getmypid() . '/';
$log_root = $test_root . 'ctracker/logfiles/';
if (!mkdir($log_root, 0700, true))
{
	fwrite(STDERR, "Could not create log-manager test directory.\n");
	exit(1);
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

foreach (array_keys($files) as $name)
{
	unlink($log_root . $name);
}
rmdir($log_root);
rmdir($test_root . 'ctracker');
rmdir($test_root);

if ($errors)
{
	fwrite(STDERR, implode("\n", $errors) . "\n");
	exit(1);
}

echo "CrackerTracker log rotation and counter checks passed.\n";

?>
