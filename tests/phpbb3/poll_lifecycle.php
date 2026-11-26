<?php
// Exercise native permanent topic deletion on the disposable converted board.
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 2 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './'; $phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
require_once './includes/functions_admin.php';
$user->session_begin();
$user->setup();
$db->sql_transaction('begin');
try
{
	// A newly created native topic can reuse the former number of a missing source topic.
	$db->sql_query('UPDATE ' . TOPICS_TABLE . ' SET topic_id=14480 WHERE topic_id=702');
	$deleted = delete_topics('topic_id', array(701, 14480), false, false, false);
	if ($deleted['topics'] !== 2) { throw new RuntimeException('The two disposable topics were not removed'); }
	foreach (array('plus_poll_review', 'plus_poll_choices', 'plus_poll_receipts') as $table)
	{
		$result = $db->sql_query('SELECT source_id FROM ' . $table_prefix . $table . ' WHERE source_id=14401');
		$row = $db->sql_fetchrow($result);
		$db->sql_freeresult($result);
		if ($row) { throw new RuntimeException('Permanent topic deletion retained a held ballot in ' . $table); }
	}
	$result = $db->sql_query('SELECT source_id FROM ' . $table_prefix . 'plus_poll_review WHERE source_id IN (14402,14408) ORDER BY source_id');
	$survivors = array_map('intval', array_column($db->sql_fetchrowset($result), 'source_id'));
	$db->sql_freeresult($result);
	if ($survivors !== array(14402,14408)) { throw new RuntimeException('An unrelated or previously unassigned source ballot was removed'); }
}
finally { $db->sql_transaction('rollback'); }
