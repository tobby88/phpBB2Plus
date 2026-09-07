<?php
define('IN_PHPBB', true);
define('CTRACKER_ACP', true);
define('CTRACKER_CONFIG', 'fixture_config');
define('GENERAL_ERROR', 1);
define('GENERAL_MESSAGE', 2);
class NumericExit extends RuntimeException {}
function message_die($level, $message) { throw new NumericExit($message); }
function numeric_assert($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
function phpbb_admin_html($value) { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function phpbb_admin_require_post_session()
{
	$GLOBALS['numeric_session_checks']++;
	if (!$GLOBALS['numeric_session_allowed']) { throw new NumericExit('session'); }
}
class NumericDatabase
{
	var $rows = array();
	var $queries = array();
	var $fail_write = false;
	function sql_query($sql)
	{
		$this->queries[] = $sql;
		return !($this->fail_write && strpos($sql, 'INSERT') === 0);
	}
	function sql_fetchrow($result) { return $this->rows ? array_shift($this->rows) : false; }
	function sql_escape($value) { return addslashes($value); }
}
$forum_root = dirname(dirname(__DIR__)) . '/phpBB2/';
require $forum_root . 'ctracker/classes/class_ct_database.php';
$lang = array('ctracker_error_updating_config' => 'invalid', 'ctracker_error_settings_input' => 'invalid %s');
$HTTP_SERVER_VARS = array('REMOTE_ADDR' => '192.0.2.1'); $HTTP_ENV_VARS = array();
$db = new NumericDatabase(); $config = new ct_database();
$defaults = $config->default_settings();
$ranges = $config->setting_ranges();
numeric_assert(count($ranges) === 45 && count($defaults) === 46 && !isset($ranges['global_message']), 'Every numeric default needs exactly one canonical range');
foreach ($ranges as $name => $range)
{
	numeric_assert($config->valid_numeric_setting($name, $defaults[$name]), 'Defaults must pass: ' . $name);
	foreach (array($range[0], $range[1], (string) $range[0], (string) $range[1]) as $boundary)
	{
		$db->queries = array();
		$config->change_configuration($name, $boundary);
		numeric_assert(count($db->queries) === 1 && $config->settings[$name] === (string) $boundary, 'Valid boundary must be written: ' . $name);
	}
	$invalids = array('', 'garbage', '1suffix', '-1', '1.0', '1e0', ' 1', "1\n", '01', str_repeat('9', 40), array('1'), null, true, 1.5, $range[0] - 1);
	if ($range[1] < PHP_INT_MAX) { $invalids[] = $range[1] + 1; }
	foreach ($invalids as $invalid)
	{
		$db->queries = array(); $before = $config->settings; $rejected = false;
		try { $config->change_configuration($name, $invalid); }
		catch (NumericExit $e) { $rejected = true; }
		numeric_assert($rejected && !$db->queries && $config->settings === $before, 'Invalid direct write must not mutate state: ' . $name);
		$db = new NumericDatabase();
		$db->rows = array(array('ct_config_name' => $name, 'ct_config_value' => $invalid));
		$loaded = new ct_database();
		numeric_assert($loaded->settings[$name] === $defaults[$name] && isset($loaded->invalid_settings[$name]) && count($db->queries) === 1 &&
			strpos($db->queries[0], 'SELECT') === 0, 'Damaged persisted number needs a read-only default: ' . $name);
	}
}
$db = new NumericDatabase(); $db->fail_write = true;
$before = $config->settings; $rejected = false;
try { $config->change_configuration('request_limit_enabled', '0'); } catch (NumericExit $e) { $rejected = true; }
numeric_assert($rejected && $config->settings === $before, 'SQL failure must not activate a value in memory');
$db = new NumericDatabase();
$db->rows = array(array('ct_config_name' => 'request_limit_enabled', 'ct_config_value' => '0'),
	array('ct_config_name' => 'request_limit_login', 'ct_config_value' => '41'));
$loaded = new ct_database();
numeric_assert($loaded->settings['request_limit_enabled'] === '0' && $loaded->settings['request_limit_login'] === '41', 'Deliberate valid choices must survive loading');
$old_limit = ini_get('pcre.backtrack_limit'); ini_set('pcre.backtrack_limit', '0');
numeric_assert($loaded->valid_numeric_setting('footer_layout', '3') && !$loaded->valid_numeric_setting('footer_layout', '3junk'), 'Numeric validation must not depend on PCRE');
ini_set('pcre.backtrack_limit', $old_limit);
class FooterStopTemplate
{
	var $blocks = array();
	function set_filenames($values) {}
	function assign_block_vars($name, $values)
	{
		$this->blocks[$name] = $values;
		if ($name === 'infobox') { throw new NumericExit('saved'); }
	}
}
// Invalid submissions stop before rendering; valid ones stop on success. This
// executes the real footer ACP and setter without reading live counters.
foreach (array(null, array('1'), '', '0', '9', '1junk', '1.0', ' 1', '01', '1', '8') as $choice)
{
	$db = new NumericDatabase(); $ctracker_config = new ct_database(); $db->queries = array();
	$before = $ctracker_config->settings; $template = new FooterStopTemplate();
	$phpbb_root_path = $forum_root; $phpEx = 'php';
	$_POST = $HTTP_POST_VARS = array('submit' => '1', 'footer_layout' => $choice);
	$numeric_session_allowed = true; $numeric_session_checks = 0;
	try { include $forum_root . 'ctracker/admin/acp_module_footer.php'; }
	catch (NumericExit $e) { $outcome = $e->getMessage(); }
	$valid = $choice === '1' || $choice === '8';
	numeric_assert($numeric_session_checks === 1 && ($valid ? $outcome === 'saved' && count($db->queries) === 1 &&
		$ctracker_config->settings['footer_layout'] === $choice : strpos($outcome, 'invalid ') === 0 && !$db->queries &&
		$before === $ctracker_config->settings), 'Footer submission must reject malformed choices without coercion');
}
$numeric_session_allowed = false; $db->queries = array(); $before = $ctracker_config->settings;
try { include $forum_root . 'ctracker/admin/acp_module_footer.php'; } catch (NumericExit $e) { $outcome = $e->getMessage(); }
numeric_assert($outcome === 'session' && !$db->queries && $before === $ctracker_config->settings, 'Footer action must retain session protection');
class ct_adminfunctions
{
	function __construct() { throw new NumericExit('render'); }
}
foreach (array('english', 'german') as $language)
{
	include $forum_root . 'language/lang_' . $language . '/lang_cback_ctracker.php';
	$db = new NumericDatabase(); $db->rows = array(array('ct_config_name' => 'request_limit_enabled', 'ct_config_value' => 'garbage'));
	$ctracker_config = new ct_database(); $db->queries = array();
	$template = new FooterStopTemplate(); $HTTP_POST_VARS = $_POST = array();
	try { include $forum_root . 'ctracker/admin/acp_module_settings.php'; } catch (NumericExit $e) { $outcome = $e->getMessage(); }
	numeric_assert($outcome === 'render' && !$db->queries && isset($template->blocks['config_fallback']) &&
		strpos($template->blocks['config_fallback']['MESSAGE'], 'request_limit_enabled') !== false, 'ACP must disclose runtime defaults without mutating persisted configuration');
	$ctracker_config->change_configuration('request_limit_enabled', '1');
	numeric_assert(!$ctracker_config->invalid_settings, 'A successful explicit save resolves the recovery notice');
}
echo "CrackerTracker numeric configuration boundaries passed.\n";
