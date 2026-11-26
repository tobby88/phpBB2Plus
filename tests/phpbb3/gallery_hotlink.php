<?php
// Both pictures share a synthetic converted original. Keeping the HTML visit local to the fixtures preserves view counts.
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 || !preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './'; $phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$table = $table_prefix . 'plus_album_pics';
if ($argv[2] === 'seed')
{
	$own = array('www.gallery-board.example.invalid');
	foreach (array('https://gallery-board.example.invalid:444/photos' => true, 'http://WWW.GALLERY-BOARD.EXAMPLE.INVALID./' => true,
		'https://people.gallery-board.example.invalid/photos' => false) as $page => $expected)
	{
		if (\phpbb2plus\gallery\service\referrers::allows($page, $own, '') !== $expected) { throw new RuntimeException('Unexpected board-domain match'); }
	}
	$result = $db->sql_query("SELECT * FROM $table WHERE pic_id IN (100,30100,30101) ORDER BY pic_id");
	$rows = $db->sql_fetchrowset($result);
	$db->sql_freeresult($result);
	if (count($rows) !== 1 || (int) $rows[0]['pic_id'] !== 100) { throw new RuntimeException('Cannot seed the gallery referring-site check'); }
	foreach (array(30100 => 0, 30101 => 1) as $id => $approved)
	{
		$pic = array_merge($rows[0], array('pic_id' => $id, 'approved' => $approved, 'title' => 'Referring site fixture'));
		$db->sql_query("INSERT INTO $table " . $db->sql_build_array('INSERT', $pic));
	}
}
elseif ($argv[2] === 'cleanup')
{
	$db->sql_query("DELETE FROM $table WHERE pic_id IN (30100,30101) AND title='Referring site fixture'");
}
else { exit(2); }
