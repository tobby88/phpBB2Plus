<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 || !preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$user->session_begin();
$user->setup();
$p = $table_prefix . 'plus_arc_';
if ($argv[2] === 'notices')
{
	$task = $phpbb_container->get('phpbb2plus.arcade.tournament_notices');
	$task->run();
	$task->run();
	$result = $db->sql_query('SELECT u.username FROM ' . PRIVMSGS_TABLE . ' m JOIN ' . PRIVMSGS_TO_TABLE .
		' r ON r.msg_id=m.msg_id JOIN ' . USERS_TABLE . " u ON u.user_id=r.user_id WHERE m.message_subject IN ('Tournament result','Turnierergebnis')");
	$recipients = $db->sql_fetchrowset($result);
	$db->sql_freeresult($result);
	if (array_column($recipients, 'username') !== array('HistoricMember')) { throw new RuntimeException('Expected one delivery to the opted-in participant'); }
	return;
}
if ($argv[2] !== 'cleanup') { exit(2); }
foreach (array('tours','tour_games','tour_plays','invites','launches','tour_notices') as $suffix)
{
	$result = $db->sql_query("SELECT COUNT(*) AS n FROM $p$suffix WHERE tour_id>34");
	$count = (int) $db->sql_fetchfield('n');
	$db->sql_freeresult($result);
	if ($count) { throw new RuntimeException('Removing a tournament left event data in ' . $suffix); }
}
$result = $db->sql_query("SELECT COUNT(*) AS n FROM {$p}turns s LEFT JOIN {$p}tour_plays p ON p.row_id=s.participant_id WHERE p.row_id IS NULL");
$orphans = (int) $db->sql_fetchfield('n');
$db->sql_freeresult($result);
$result = $db->sql_query("SELECT COUNT(*) AS n FROM {$p}all_scores WHERE game_name='puzzle' AND player_id=2");
$ranking = (int) $db->sql_fetchfield('n');
$db->sql_freeresult($result);
if ($orphans || !$ranking) { throw new RuntimeException('Removing an event damaged unrelated rankings or left personal turns'); }
