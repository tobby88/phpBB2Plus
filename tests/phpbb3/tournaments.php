<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 || !preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1] || !in_array($argv[2], array('enable','disable','reserve','pause','turns','proposal'), true)) { exit(2); }
$p = $table_prefix . 'plus_arc_';
if ($argv[2] === 'proposal')
{
	$result = $db->sql_query("SELECT t.tour_id,t.tour_name,t.tour_active,t.start_id,t.tour_player_turns,t.block_plays,
		r.owner_id,p.user_id,p.turns_state,u.username FROM {$p}tours t
		LEFT JOIN {$p}proposals r ON r.tour_id=t.tour_id AND r.owner_id=t.founder_id
		LEFT JOIN {$p}tour_plays p ON p.tour_id=t.tour_id AND p.owner_id=t.founder_id
		LEFT JOIN " . USERS_TABLE . " u ON u.user_id=r.owner_id WHERE t.tour_id>34");
	$rows = $db->sql_fetchrowset($result);
	$db->sql_freeresult($result);
	if (count($rows) !== 1) { throw new RuntimeException('Expected one proposed event, receipt and founder registration'); }
	$row = $rows[0];
	if ($row['tour_name'] !== 'Früh &amp; Spät' || (int) $row['tour_active'] !== 0 || (int) $row['tour_player_turns'] !== 2 ||
		(int) $row['block_plays'] !== 1 || (int) $row['user_id'] !== 1 || (int) $row['start_id'] !== 1 ||
		(int) $row['owner_id'] <= 1 || $row['username'] !== 'HistoricMember' || (int) $row['turns_state'] !== 1)
	{ throw new RuntimeException('The tournament proposal changed its source identity, state or terms'); }
	$result = $db->sql_query("SELECT COUNT(*) AS n FROM {$p}log WHERE log_value='Tournament create id=" . (int) $row['tour_id'] . "'");
	$count = (int) $db->sql_fetchfield('n');
	$db->sql_freeresult($result);
	if ($count !== 1) { throw new RuntimeException('Each accepted proposal must have one activity record'); }
	return;
}
if ($argv[2] === 'reserve')
{
	$db->sql_query("UPDATE {$p}tours SET block_plays=1 WHERE tour_id=32");
	return;
}
if ($argv[2] === 'pause')
{
	$db->sql_query("UPDATE {$p}tours SET tour_active=1 WHERE tour_id=32");
	return;
}
if ($argv[2] === 'turns')
{
	$result = $db->sql_query("SELECT p.tour_id,p.user_id,t.game_name,t.played,t.score_units FROM {$p}turns t
		JOIN {$p}tour_plays p ON p.row_id=t.participant_id ORDER BY p.tour_id,p.user_id,t.game_name");
	$rows = array();
	while ($row = $db->sql_fetchrow($result))
	{
		$rows[] = array((int) $row['tour_id'], (int) $row['user_id'], $row['game_name'], (int) $row['played'], $row['score_units']);
	}
	$db->sql_freeresult($result);
	if ($rows !== array(array(32,1,'personal',2,'0'),array(32,1,'puzzle',2,'810123'),array(32,2,'private',1,null),array(34,1,'timer',1,'71256')))
	{ throw new RuntimeException('An extra tournament turn or score was recorded'); }
	$result = $db->sql_query("SELECT w.balance_text FROM {$p}wallets w WHERE w.owner_id=2 AND w.currency_field='user_points'");
	$balance = $db->sql_fetchfield('balance_text');
	$db->sql_freeresult($result);
	if ($balance !== '3') { throw new RuntimeException('A tournament turn charged the normal entry price'); }
	$db->sql_query("UPDATE {$p}tours SET tour_active=2,block_plays=0 WHERE tour_id=32");
	return;
}
if ($argv[2] === 'disable')
{
	$result = $db->sql_query("SELECT p.tour_id,p.user_id,u.username FROM {$p}tour_plays p JOIN " . USERS_TABLE . ' u ON u.user_id=p.owner_id ORDER BY p.tour_id,p.user_id');
	$rows = array();
	while ($row = $db->sql_fetchrow($result)) { $rows[] = array((int) $row['tour_id'], (int) $row['user_id'], $row['username']); }
	$db->sql_freeresult($result);
	if ($rows !== array(array(31,1,'HistoricMember'),array(32,1,'HistoricMember'),array(32,2,'ModernMember'),array(34,1,'HistoricMember'),array(34,87,'DepartingFixture')))
	{ throw new RuntimeException('Tournament registration duplicated a seat or lost the original account identity'); }
	$result = $db->sql_query("SELECT COUNT(*) AS n FROM {$p}log WHERE name='GAME' AND log_value LIKE 'Tournament join id=%'");
	$count = (int) $db->sql_fetchfield('n');
	$db->sql_freeresult($result);
	if ($count !== 2) { throw new RuntimeException('Each successful new registration must have one activity record'); }
}
$value = $argv[2] === 'enable' ? '1' : '0';
$db->sql_query("UPDATE {$p}settings SET config_value='$value' WHERE config_name='games_tournament_mode'");
