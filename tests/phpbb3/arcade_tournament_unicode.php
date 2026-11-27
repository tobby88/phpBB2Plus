<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 || !preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
$phase = $argv[2];
if ($phase === 'source')
{
	mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
	$source = new mysqli('127.0.0.1', getenv('PLUS_TEST_DB_USER') ?: 'root', getenv('PLUS_TEST_DB_PASSWORD'), $argv[1], (int) (getenv('PLUS_TEST_DB_PORT') ?: 3306));
	$source->set_charset('utf8mb4');
	$source->query("INSERT INTO phpbb_ina_tour (tour_id,tour_name,tour_desc,start_id,start_date,tour_max_players,tour_player_turns,tour_active,end_date) VALUES
		(28,'Museum 🏺 &#128512;','Grüße 𠜎 und &#128512;',1,10000,2,1,3,10100)");
	return;
}
define('IN_PHPBB', true); $phpbb_root_path='./'; $phpEx='php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$rows = static function ($sql) use ($db) {
	$r=$db->sql_query($sql); $v=$db->sql_fetchrowset($r); $db->sql_freeresult($r); return $v;
};
$expect = static function ($ok, $why) { if (!$ok) { throw new \RuntimeException($why); } };
$p = $table_prefix . 'plus_arc_';
$table = $p . 'tours';
$key = 'plus_test_unicode_tournament';
$text = $phpbb_container->get('config_text');
$state = json_decode((string) $text->get($key), true);
if (in_array($phase, array('older','upgraded','reverted'), true))
{
	$types = array_column($rows("SELECT COLUMN_NAME,DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table'"),
		'DATA_TYPE', 'COLUMN_NAME');
	if ($phase === 'older')
	{
		$expect($types['tour_name'] === 'varchar' && $types['tour_desc'] === 'mediumtext', 'Expected original tournament storage');
		$db->sql_query("INSERT INTO $table (tour_id,tour_name,tour_desc) VALUES (27,'Alt &#128512;','Frühling &amp; Schnee')");
	}
	else
	{
		$expect($types['tour_name'] === 'varbinary' && $types['tour_desc'] === 'mediumblob', 'Tournament storage was not widened');
		if ($phase === 'upgraded')
		{
			$expect($rows("SELECT tour_name FROM $table WHERE tour_id=27")[0]['tour_name'] === 'Alt &#128512;', 'Old tournament spelling changed');
			$db->sql_query("UPDATE $table SET tour_desc='Grüße 🔭 &#128512;' WHERE tour_id=27");
		}
		else { $expect($rows("SELECT tour_desc FROM $table WHERE tour_id=27")[0]['tour_desc'] === 'Grüße 🔭 &#128512;', 'Rollback damaged tournament text'); }
	}
	return;
}
if ($phase === 'prepare')
{
	$archive = $rows("SELECT tour_name,tour_desc FROM $table WHERE tour_id=28")[0] ?? array();
	$expect(($archive['tour_name'] ?? '') === 'Museum 🏺 &#128512;' && $archive['tour_desc'] === 'Grüße 𠜎 und &#128512;', 'Historical tournament text or literal reference was lost');
	$expect(!$state, 'An earlier tournament Unicode fixture is still active');
	$state = array('last'=>(int) $rows("SELECT MAX(tour_id) AS last_id FROM $table")[0]['last_id'],
		'maximum'=>$rows("SELECT config_value FROM {$p}settings WHERE config_name='games_tournament_max'")[0]['config_value']);
	$text->set($key, json_encode($state));
	$db->sql_query("UPDATE {$p}settings SET config_value='50' WHERE config_name='games_tournament_max'");
}
elseif ($phase === 'new' || $phase === 'edited')
{
	$r = $rows("SELECT tour_id,tour_name,tour_desc FROM $table WHERE tour_id>" . (int) $state['last'] . ' ORDER BY tour_id');
	$expected = $phase === 'new' ? str_repeat('🎲', 25) : 'Küstenpokal 🪼 &#128512;';
	$expect(count($r) === 1 && $r[0]['tour_name'] === $expected && $r[0]['tour_desc'] === 'Neue Runde 🔭 und &#128512;',
		'Native tournament creation or editing lost Unicode, a literal reference or the 25-character boundary');
	echo (int) $r[0]['tour_id'];
}
elseif ($phase === 'clean')
{
	$names = array(str_repeat('🎲', 25), 'Küstenpokal 🪼 &#128512;', str_repeat('🪐', 25));
	$ids = array_column($rows("SELECT tour_id FROM $table WHERE tour_id>" . (int) $state['last'] . ' AND ' . $db->sql_in_set('tour_name', $names)), 'tour_id');
	if ($ids)
	{
		$db->sql_query("DELETE FROM {$p}tour_plays WHERE " . $db->sql_in_set('tour_id', $ids));
		$db->sql_query("DELETE FROM {$p}proposals WHERE " . $db->sql_in_set('tour_id', $ids));
		$db->sql_query("DELETE FROM $table WHERE " . $db->sql_in_set('tour_id', $ids));
	}
	$db->sql_query("UPDATE {$p}settings SET " . $db->sql_build_array('UPDATE', array('config_value'=>$state['maximum'])) . " WHERE config_name='games_tournament_max'");
	$text->delete($key);
}
else { throw new \RuntimeException('Unknown tournament Unicode test phase'); }
