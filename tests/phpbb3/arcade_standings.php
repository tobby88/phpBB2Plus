<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 || !preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$p = $table_prefix . 'plus_arc_';
$service = $phpbb_container->get('phpbb2plus.arcade.standings');
$db->sql_transaction('begin');
switch ($argv[2])
{
	case 'seed':
		$result = $db->sql_query("SELECT user_id FROM " . USERS_TABLE . " WHERE username_clean='historicmember'");
		$historic = (int) $db->sql_fetchfield('user_id');
		$db->sql_freeresult($result);
		if ($historic <= ANONYMOUS || $historic === 2) { throw new RuntimeException('Missing remapped Arcade member'); }
		$now = $service->month('');
		$previous = $now->modify('-1 month');
		$old = $now->modify('-2 months');
		$next_id = 900100;
		$add = static function ($game, $date, $owner, $original, $name, $units, $time) use ($db, $p, &$next_id) {
			$data = array('highscore_id' => $next_id++, 'highscore_year' => (int) $date->format('Y'), 'highscore_mon' => (int) $date->format('n'),
				'highscore_game' => $game, 'owner_id' => $owner, 'highscore_user_id' => $original, 'author_removed' => (int) ($original === 98),
				'highscore_player' => $name, 'score_units' => $units, 'highscore_date' => $time);
			$db->sql_query("INSERT INTO {$p}monthly " . $db->sql_build_array('INSERT', $data));
		};
		$add('puzzle', $now, 2, 2, 'ModernMember', 210001, 300);
		$add('puzzle', $now, $historic, 1, 'Old champion', 210001, 200);
		$add('puzzle', $now, 2, 2, 'ModernMember', 110001, 100);
		$add('puzzle', $previous, 2, 2, 'Old leader', 270001, 100);
		$add('timer', $now, $historic, 1, 'HistoricMember', 300, 100);
		$add('timer', $now, 2, 2, 'ModernMember', 200, 300);
		$add('timer', $previous, 0, 98, 'EditorFixture', 1, 100);
		$add('timer', $old, 2, 2, 'ModernMember', 1, 100);
		$add('private', $now, 2, 2, 'ModernMember', 350000, 100);
		$add('private', $service->month('1999-09'), 2, 2, 'ModernMember', 350000, 100);
		$add('closed', $now, 2, 2, 'ModernMember', 450000, 100);
		foreach (array('1999-11','1999-12','2000-01') as $key) { $add('timer', $service->month($key), $historic, 1, 'HistoricMember', 1, 100); }
		$user->session_begin();
		$auth->acl($user->data);
		$january = $service->points($service->month('2000-01'));
		$by_owner = array_column($january, 'monthly', 'owner_id');
		if (($by_owner[$historic] ?? 0) !== 2) { throw new RuntimeException('A January points table counted the wrong December'); }
		echo json_encode(array('current' => $now->format('Y-m'), 'previous' => $previous->format('Y-m')));
		break;
	case 'duplicate':
		$db->sql_query("INSERT INTO {$p}games " . $db->sql_build_array('INSERT', array('game_id' => 900100,
			'game_name' => 'puzzle', 'game_desc' => 'Hidden duplicate', 'cat_id' => 12, 'game_avail' => 1, 'game_show_score' => 1)));
		break;
	case 'hide':
		$db->sql_query("UPDATE {$p}settings SET config_value='0' WHERE config_name IN ('games_show_mhm','games_at_highscore')");
		break;
	case 'cleanup':
		$db->sql_query("DELETE FROM {$p}games WHERE game_id=900100");
		$db->sql_query("DELETE FROM {$p}monthly WHERE highscore_id BETWEEN 900100 AND 900119");
		$db->sql_query("UPDATE {$p}settings SET config_value='1' WHERE config_name IN ('games_show_mhm','games_at_highscore')");
		break;
	default:
		throw new InvalidArgumentException('Unknown Arcade standings fixture phase');
}
$db->sql_transaction('commit');
