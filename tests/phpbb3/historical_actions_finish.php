<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 2 || !preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
require dirname(__DIR__, 2) . '/phpBB3/install/convertors/plus_actions.php';
function action_test_count($db)
{
	$result = $db->sql_query('SELECT COUNT(*) AS total FROM ' . LOG_TABLE);
	$count = (int) $db->sql_fetchfield('total');
	$db->sql_freeresult($result);
	return $count;
}
$before = action_test_count($db);
plus_finish_action_log();
if (!$before || action_test_count($db) !== $before) { throw new RuntimeException('A repeated conversion finish lost or duplicated native log entries'); }
$source = 'action_fixture_';
if (plus_action_schema($db, $source, $table_prefix) !== false) { throw new RuntimeException('A missing optional log table prevented conversion'); }
$db->sql_query('CREATE TEMPORARY TABLE ' . $source . 'logs (id_log INT PRIMARY KEY, mode VARCHAR(32), topic_id INT, user_id INT, user_ip VARCHAR(45), log_time INT)');
$schema = plus_action_schema($db, $source, $table_prefix);
if (!is_array($schema)) { throw new RuntimeException('The older log table was skipped'); }
foreach ($schema as $mapping)
{
	if (is_array($mapping) && ($mapping[0] ?? '') === 'details')
	{
		if (in_array('logs.username', $mapping[1], true) || count($mapping[1]) !== 6) { throw new RuntimeException('Older action logs require a missing actor name'); }
		$stored = unserialize(plus_action_details(1, 2, 99, 'edit', '', 0), array('allowed_classes' => false));
		if ($stored['name'] !== '') { throw new RuntimeException('An absent recorded name was fabricated'); }
		return;
	}
}
throw new RuntimeException('The older log table was skipped');
