<?php
// Synthetic search results in the disposable converted forum. No new physical image files.
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$pics = $table_prefix . 'plus_album_pics';
function search_fixture_row($sql)
{
	global $db;
	$result = $db->sql_query($sql);
	$row = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);
	return $row;
}
if ($argv[2] === 'seed')
{
	if (search_fixture_row("SELECT pic_id FROM $pics WHERE pic_id BETWEEN 20000 AND 21211"))
	{ throw new RuntimeException('Gallery search fixture identifiers are already in use'); }
	$base = search_fixture_row("SELECT * FROM $pics WHERE pic_id=100");
	$personal = search_fixture_row("SELECT cat_id,author_id FROM $pics WHERE pic_id=103");
	$photos = array(
		20000 => array('cat_id' => 1, 'title' => 'Küstenlicht & Nebel', 'description' => "Leuchtspur 'Welle' – dokumentiert", 'author_name' => 'Früherer Uploadname'),
		20001 => array('cat_id' => 8, 'title' => 'Küstenlicht im Club'),
		20002 => array('cat_id' => 9, 'title' => 'Küstenlicht im Unteralbum'),
		20003 => array('cat_id' => 1, 'title' => 'Küstenlicht vor Freigabe', 'approved' => 0),
		20004 => array('cat_id' => $personal['cat_id'], 'title' => 'Küstenlicht im persönlichen Album', 'author_id' => $personal['author_id']),
		20005 => array('cat_id' => 1, 'title' => 'Küstenlicht am Ufer', 'author_id' => ANONYMOUS, 'author_removed' => 1, 'author_name' => 'Frühere Fotografin'),
		20006 => array('cat_id' => 1, 'title' => 'Küstenlicht am Kai', 'author_id' => ANONYMOUS, 'author_name' => 'Ria & Ada'),
		20007 => array('cat_id' => 9, 'title' => 'Küstenlicht ohne Freigabepflicht', 'approved' => 0),
	);
	$db->sql_transaction('begin');
	foreach ($photos as $id => $data)
	{
		$db->sql_query("INSERT INTO $pics " . $db->sql_build_array('INSERT', array_merge($base, $data, array('pic_id' => $id, 'created_at' => 30000))));
	}
	// A full page of private matches is newer than every public match.
	foreach (array(20100 => 1, 20700 => 9) as $first => $category)
	{
		$batch = array();
		for ($i = 0; $i < 512; $i++)
		{
			$batch[] = array_merge($base, array('pic_id' => $first + $i, 'cat_id' => $category, 'title' => 'Bilderbogen ' . $i,
				'description' => 'Serienaufnahme', 'author_id' => ANONYMOUS, 'author_name' => 'Serienarchiv',
				'created_at' => 40000 + $first + intdiv($i, 3)));
			if (count($batch) === 50 || $i === 511) { $db->sql_multi_insert($pics, $batch); $batch = array(); }
		}
	}
	$db->sql_transaction('commit');
	echo json_encode(array('limit' => max(1, min(100, (int) $config['topics_per_page'])), 'personal' => (int) $personal['cat_id']));
}
elseif ($argv[2] === 'cleanup')
{
	$db->sql_query("DELETE FROM $pics WHERE pic_id BETWEEN 20000 AND 20007 OR pic_id BETWEEN 20100 AND 20611 OR pic_id BETWEEN 20700 AND 21211");
}
else { exit(2); }
