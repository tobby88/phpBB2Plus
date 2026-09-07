<?php
// Execute the real settings submission with an in-memory database only.
$forum_root = dirname(dirname(__DIR__)) . '/phpBB2/';
define('IN_PHPBB', true);
define('CTRACKER_ACP', true);
define('CTRACKER_CONFIG', 'test_ct_config');
define('GENERAL_ERROR', 1);
define('GENERAL_MESSAGE', 2);
class SettingsExit extends RuntimeException {}
function message_die($level, $message) { throw new SettingsExit($message); }
function phpbb_admin_require_post_session()
{
	$GLOBALS['session_checks']++;
	if (!$GLOBALS['session_allowed']) { throw new SettingsExit('invalid session'); }
}
function phpbb_admin_post_string($key, $default = '')
{
	return isset($_POST[$key]) && is_scalar($_POST[$key]) ? stripslashes((string) $_POST[$key]) : (string) $default;
}
class SettingsDatabase
{
	var $queries = array();
	function sql_query($sql) { $this->queries[] = $sql; return true; }
	function sql_fetchrow($result) { return false; }
	function sql_escape($value) { return str_replace("'", "''", $value); }
}
class ct_adminfunctions
{
	function __construct() { throw new SettingsExit('render'); }
}
require $forum_root . 'ctracker/classes/class_ct_database.php';
function settings_assert($condition, $message)
{
	if (!$condition) { throw new RuntimeException($message); }
}
function submit_settings($post, $allow_session = true)
{
	global $forum_root, $db, $lang, $HTTP_SERVER_VARS, $HTTP_ENV_VARS, $session_checks, $session_allowed;
	$lang = array('ctracker_error_settings_input' => 'invalid %s', 'ctracker_error_updating_config' => 'write failed');
	$HTTP_SERVER_VARS = array('REMOTE_ADDR' => '192.0.2.1');
	$HTTP_ENV_VARS = array();
	$db = new SettingsDatabase();
	$ctracker_config = new ct_database();
	$db->queries = array();
	$before = $ctracker_config->settings;
	$_POST = $HTTP_POST_VARS = array_merge(array('submit' => '1'), $post);
	$session_checks = 0;
	$session_allowed = $allow_session;
	try { include $forum_root . 'ctracker/admin/acp_module_settings.php'; }
	catch (SettingsExit $exception) { $outcome = $exception->getMessage(); }
	settings_assert($session_checks === 1, 'Every submission must validate the administrator session');
	return array($outcome, $db->queries, $before, $ctracker_config->settings, isset($setting_ranges) ? $setting_ranges : array());
}
foreach (array('', 'garbage', '1junk', '-1', '2', '0.0', '1e0', ' 1', "1\n", str_repeat('9', 100), array('1'), null, true, 1.5) as $invalid)
{
	list($outcome, $queries, $before, $after) = submit_settings(array('ipblock_enabled' => '0', 'request_limit_enabled' => $invalid));
	settings_assert(strpos($outcome, 'invalid ') === 0 && !$queries && $before === $after,
		'Invalid late field must reject the whole submission before any configuration write: ' . var_export($invalid, true));
}
foreach (array('0', '201', '10junk', array('60')) as $invalid)
{
	list($outcome, $queries, $before, $after) = submit_settings(array('request_limit_content' => $invalid));
	settings_assert(strpos($outcome, 'invalid ') === 0 && !$queries && $before === $after, 'Numeric limits must reject invalid values');
}
foreach (array('0', '1') as $flag)
{
	list($outcome, $queries, $before, $after) = submit_settings(array('request_limit_enabled' => $flag));
	$expected = $before; $expected['request_limit_enabled'] = $flag;
	settings_assert($outcome === 'render' && count($queries) === 1 && $after === $expected, 'Valid flag must update only the supplied setting');
}
foreach (array('10', '60', '200') as $limit)
{
	list($outcome, $queries, $before, $after) = submit_settings(array('request_limit_content' => $limit));
	$expected = $before; $expected['request_limit_content'] = $limit;
	settings_assert($outcome === 'render' && count($queries) === 1 && $after === $expected, 'Valid numeric boundary must persist without changing unrelated settings');
}
list($outcome, $queries, $before, $after) = submit_settings(array());
settings_assert($outcome === 'render' && !$queries && $before === $after, 'Missing settings must remain untouched');
$defaults = (new ct_database())->default_settings();
$excluded = array('global_message', 'global_message_type', 'footer_layout', 'last_file_scan', 'last_checksum_scan', 'password_timestamps_split');
foreach ($excluded as $name) { unset($defaults[$name]); }
list($outcome, $queries, $before, $after, $ranges) = submit_settings($defaults);
settings_assert($outcome === 'render' && count($queries) === 40 && $before === $after, 'The complete valid 40-setting form must remain usable');
foreach ($ranges as $name => $range)
{
	foreach (array($range[0], $range[1]) as $boundary)
	{
		list($outcome, $queries, $before, $after) = submit_settings(array($name => (string) $boundary));
		settings_assert($outcome === 'render' && count($queries) === 1 && $after[$name] === (string) $boundary, 'Valid boundary rejected for ' . $name);
	}
	foreach (array($range[0] - 1, $range[1] + 1) as $outside)
	{
		list($outcome, $queries, $before, $after) = submit_settings(array($name => (string) $outside));
		settings_assert(strpos($outcome, 'invalid ') === 0 && !$queries && $before === $after, 'Out-of-range value accepted for ' . $name);
	}
}
list($outcome, $queries, $before, $after) = submit_settings($defaults, false);
settings_assert($outcome === 'invalid session' && !$queries && $before === $after, 'An invalid administrator session must prevent all writes');
echo "CrackerTracker settings submission runtime tests passed.\n";
