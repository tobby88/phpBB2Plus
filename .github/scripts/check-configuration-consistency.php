<?php
define('IN_PHPBB', true);
define('CONFIG_TABLE', 'fixture_config'); define('PLUS_TABLE', 'fixture_plus');
$forum_root = dirname(dirname(__DIR__)) . '/phpBB2/';
require $forum_root . 'includes/functions.php';
function config_check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
class ConfigReadFixture
{
	var $values = array('fixture_config' => array('config_id' => '1', 'site_desc' => 'current'), 'fixture_plus' => array('setting' => 'current-plus'));
	var $queries = array(); var $freed = 0; var $fail = false;
	function sql_query($sql)
	{
		$this->queries[] = $sql;
		if ($this->fail) { return false; }
		$table = substr($sql, strlen('SELECT config_name, config_value FROM '));
		config_check(isset($this->values[$table]), 'Only expected configuration tables are read');
		$result = new stdClass(); $result->rows = array();
		foreach ($this->values[$table] as $key => $value) { $result->rows[] = array('config_name' => $key, 'config_value' => $value); }
		return $result;
	}
	function sql_fetchrow($result) { return $result->rows ? array_shift($result->rows) : false; }
	function sql_freeresult($result) { $this->freed++; }
}
$db = new ConfigReadFixture();
$board_config = array('config_id' => '1', 'site_desc' => 'obsolete');
$plus_config = array('setting' => 'obsolete-plus');
$common = file_get_contents($forum_root . 'common.php');
$start = strpos($common, '// Keep the unrelated colour-group cache enabled.');
$end = strpos($common, '$board_config = phpbb_normalize_board_config(');
config_check($start !== false && $end > $start, 'Locate actual configuration bootstrap');
$bootstrap = substr($common, $start, $end - $start);
config_check(strpos($bootstrap, 'phpbb_data_cache_') === false, 'Bootstrap must not read or republish unversioned configuration files');
eval($bootstrap);
config_check($board_config['site_desc'] === 'current' && $plus_config['setting'] === 'current-plus', 'Bootstrap must replace stale configuration even with config_id present');
config_check(count($db->queries) === 2 && $db->freed === 2, 'Exactly one buffered read per table, both results released');
// An older request can retain its own snapshot, but cannot publish it for the
// next request after an ACP change/restore commits.
$older_request = phpbb_load_config_table($db, CONFIG_TABLE);
$db->values[CONFIG_TABLE]['site_desc'] = "Restored Grüße 😀";
$newer_request = phpbb_load_config_table($db, CONFIG_TABLE);
config_check($older_request['site_desc'] === 'current' && $newer_request['site_desc'] === "Restored Grüße 😀", 'Requests use current committed settings without stale cache resurrection');
$db->fail = true;
config_check(phpbb_load_config_table($db, CONFIG_TABLE) === false, 'Query errors must fail, not use old settings');
$db->fail = false; $db->values[CONFIG_TABLE] = array();
config_check(phpbb_load_config_table($db, CONFIG_TABLE) === false, 'Empty configuration must fail cleanly');

// Exercise the actual idempotent migration planner without touching a database.
function update_config_engine($connection, $database, $table) { return $GLOBALS['fixture_engine']; }
function update_scalar($connection, $sql) { return 'DEFAULT'; }
function update_quote_identifier($name) { return '`' . str_replace('`', '``', $name) . '`'; }
$updater = file_get_contents(dirname($forum_root) . '/update/update_from_153a.php');
$start = strpos($updater, 'function update_queue_config_engine(');
$end = strpos($updater, 'function update_column_max_length(', $start);
config_check($start !== false && $end > $start, 'Locate real migration planner');
eval(substr($updater, $start, $end - $start));
$fixture_engine = 'MyISAM'; $operations = array();
update_queue_config_engine($operations, null, 'fixture', 'custom_config');
config_check($operations === array('ALTER TABLE `custom_config` ENGINE=InnoDB'), 'Convert only the exact main configuration table without column/data rewrites');
$fixture_engine = 'InnoDB'; $operations = array();
update_queue_config_engine($operations, null, 'fixture', 'custom_config');
config_check(!$operations, 'An already converted installation must not rebuild the table again');
$schema = file_get_contents($forum_root . 'install/schemas/mysql_schema.sql');
config_check(preg_match('/CREATE TABLE phpbb_config \([^;]*ENGINE=InnoDB/s', $schema) === 1, 'Fresh installations must support transactional restore');
config_check(strpos($updater, "update_queue_config_engine(\$operations, \$connection, \$dbname, \$table_prefix . 'config');") !== false, 'Existing installations must use the same migration');
echo "Configuration consistency and migration checks passed.\n";
