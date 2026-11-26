<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 || !preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$p = $table_prefix . 'plus_arc_';
$db->sql_transaction('begin');
if ($argv[2] === 'seed')
{
	$game = static function ($id, $name, $title, $category, $played, $guests) use ($db, $p) {
		$row = array('game_id' => $id, 'game_name' => $name, 'game_desc' => $title, 'cat_id' => $category,
			'played' => $played, 'allow_guest' => $guests, 'date_added' => $id, 'game_bonus' => $played,
			'game_charge' => $guests, 'instructions' => $name);
		$db->sql_query("INSERT INTO {$p}games " . $db->sql_build_array('INSERT', $row));
	};
	$game(900101, 'digit', '09 Probe &amp; Test_100% &#127918;', 10, 12, 0);
	$game(900102, 'ProbeFallback', '', 10, 2, 1);
	$game(900103, 'clubprobe', 'Nur im Verein', 12, 0, 0);
	$db->sql_query("INSERT INTO {$p}ratings (target_game,rate_point) VALUES (900101,2),(900102,9)");
	$db->sql_query("INSERT INTO {$p}comments (comment_id,target_game) VALUES (900101,900102)");
	$catalog = $phpbb_container->get('phpbb2plus.arcade.catalog');
	$limit = max(1, min(100, (int) $catalog->setting('games_per_page', 25) ?: 25));
	$pages = array();
	for ($i = 0; $i <= $limit; $i++)
	{
		$pages[] = array('game_id' => 900200 + $i, 'game_name' => 'page' . $i, 'game_desc' => 'ListFixture ' . $i, 'cat_id' => 10);
	}
	$db->sql_multi_insert($p . 'games', $pages);
	echo $limit;
}
elseif ($argv[2] === 'ambiguous')
{
	$db->sql_query("INSERT INTO {$p}games (game_id,game_name,game_desc,cat_id) VALUES (900104,'ProbeFallback','Hidden duplicate',12)");
}
elseif ($argv[2] === 'cleanup')
{
	$db->sql_query("DELETE FROM {$p}comments WHERE comment_id=900101 AND target_game=900102");
	$db->sql_query("DELETE FROM {$p}ratings WHERE target_game IN (900101,900102)");
	$db->sql_query("DELETE FROM {$p}games WHERE game_id BETWEEN 900101 AND 900104 OR game_id BETWEEN 900200 AND 900300");
}
else { throw new InvalidArgumentException('Unknown Arcade directory fixture phase'); }
$db->sql_transaction('commit');
