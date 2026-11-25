<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 || !preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1] || !in_array($argv[2], array('enable','disable'), true)) { exit(2); }
$p = $table_prefix . 'plus_arc_';
if ($argv[2] === 'disable')
{
	$result = $db->sql_query("SELECT p.tour_id,p.user_id,u.username FROM {$p}tour_plays p JOIN " . USERS_TABLE . ' u ON u.user_id=p.owner_id ORDER BY p.tour_id,p.user_id');
	$rows = array();
	while ($row = $db->sql_fetchrow($result)) { $rows[] = array((int) $row['tour_id'], (int) $row['user_id'], $row['username']); }
	$db->sql_freeresult($result);
	if ($rows !== array(array(31,1,'HistoricMember'),array(32,1,'HistoricMember'),array(32,2,'ModernMember'),array(34,1,'HistoricMember')))
	{ throw new RuntimeException('Tournament registration duplicated a seat or lost the original account identity'); }
	$result = $db->sql_query("SELECT COUNT(*) AS n FROM {$p}log WHERE name='GAME' AND log_value LIKE 'Tournament join id=%'");
	$count = (int) $db->sql_fetchfield('n');
	$db->sql_freeresult($result);
	if ($count !== 2) { throw new RuntimeException('Each successful new registration must have one activity record'); }
}
$value = $argv[2] === 'enable' ? '1' : '0';
$db->sql_query("UPDATE {$p}settings SET config_value='$value' WHERE config_name='games_tournament_mode'");
