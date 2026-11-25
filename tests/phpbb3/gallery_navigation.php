<?php
// Disposable navigation fixtures in the converted loopback board.
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$g = $table_prefix . 'plus_album_';
function nav_row($sql)
{
	global $db;
	$result = $db->sql_query($sql);
	$row = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);
	return $row;
}
if ($argv[2] === 'seed')
{
	if (nav_row("SELECT cat_id FROM {$g}cats WHERE cat_id BETWEEN 601 AND 603") ||
		nav_row("SELECT pic_id FROM {$g}pics WHERE pic_id BETWEEN 7000 AND 8500") || nav_row("SELECT setting_name FROM {$g}settings WHERE scope='navtest'"))
	{ throw new RuntimeException('Gallery navigation fixture IDs are already in use'); }
	$db->sql_transaction('begin');
	$db->sql_query("INSERT INTO {$g}settings (scope,setting_name,setting_value) SELECT 'navtest',setting_name,setting_value
		FROM {$g}settings WHERE scope='album' AND setting_name IN ('rate','comment','rows_per_page')");
	$cat = nav_row("SELECT * FROM {$g}cats WHERE cat_id=1");
	foreach (array(601 => 'Navigation sequence', 602 => 'A single published photograph', 603 => 'A large photograph collection') as $id => $title)
	{
		$db->sql_query("INSERT INTO {$g}cats " . $db->sql_build_array('INSERT', array_merge($cat,
			array('cat_id' => $id, 'title' => $title, 'description' => '', 'approval' => $id === 603 ? 0 : 2))));
	}
	$picture = nav_row("SELECT * FROM {$g}pics WHERE pic_id=100");
	$member = nav_row('SELECT user_id FROM ' . USERS_TABLE . " WHERE username='HistoricMember'");
	foreach (array(601 => array(7000, 12), 602 => array(7900, 2), 603 => array(8000, 412)) as $category => $volume)
	{
		$batch = array();
		for ($i = 0; $i < $volume[1]; $i++)
		{
			$former = $category === 601 && ($i === 4 || $i === 5);
			$title = $category === 601 && $i === 7 ? "A photo's title" : sprintf('Navigation %02d', $i % 5);
			$batch[] = array_merge($picture, array('pic_id' => $volume[0] + $i, 'cat_id' => $category,
				'title' => $title, 'description' => 'Navigation fixture ' . ($volume[0] + $i),
				'created_at' => 20000 + intdiv($i, 3), 'views' => $i % 4, 'approved' => (int) ($category === 603 ? $i !== 201 : $i !== 1),
				'author_id' => $former ? ANONYMOUS : ($i % 2 ? $member['user_id'] : $picture['author_id']),
				'author_removed' => (int) $former, 'author_name' => $former ? ($i === 4 ? 'Zulu old name' : 'Alpha old name') : ''));
			if (count($batch) === 50 || $i + 1 === $volume[1]) { $db->sql_multi_insert($g . 'pics', $batch); $batch = array(); }
		}
	}
	$comment = nav_row("SELECT * FROM {$g}comments WHERE comment_id=95");
	$limit = max(1, (int) $config['posts_per_page']);
	$comments = array();
	for ($i = 0; $i < $limit + 4; $i++)
	{
		$comments[] = array_merge($comment, array('comment_id' => 9000 + $i, 'pic_id' => 7010, 'cat_id' => 601,
			'created_at' => 40000 - $i, 'body' => 'Navigation comment ' . $i));
	}
	$comments[] = array_merge($comment, array('comment_id' => 9900, 'pic_id' => 7002, 'cat_id' => 601, 'author_id' => $member['user_id'], 'body' => 'Latest comment identity'));
	$comments[] = array_merge($comment, array('comment_id' => 9901, 'pic_id' => 7001, 'cat_id' => 601, 'author_id' => $member['user_id'], 'body' => 'Awaiting approval'));
	$db->sql_query("UPDATE {$g}cats SET comment_level=0,edit_level=0,delete_level=0 WHERE cat_id=601");
	$db->sql_multi_insert($g . 'comments', $comments);
	$rating = nav_row("SELECT * FROM {$g}ratings WHERE pic_id=100 AND points>0");
	unset($rating['rating_id']);
	foreach (array(7008 => 8, 7009 => 9) as $id => $points)
	{
		$db->sql_query("INSERT INTO {$g}ratings " . $db->sql_build_array('INSERT', array_merge($rating, array('pic_id' => $id, 'points' => $points))));
	}
	$db->sql_transaction('commit');
	echo json_encode(array('comment_limit' => $limit));
}
elseif ($argv[2] === 'controls-off')
{
	$db->sql_query("UPDATE {$g}settings SET setting_value='0' WHERE scope='album' AND setting_name IN ('rate','comment')");
	$db->sql_query("UPDATE {$g}settings SET setting_value='25' WHERE scope='album' AND setting_name='rows_per_page'");
}
elseif ($argv[2] === 'controls-on')
{
	$result = $db->sql_query("SELECT setting_name,setting_value FROM {$g}settings WHERE scope='navtest'");
	$saved = $db->sql_fetchrowset($result);
	$db->sql_freeresult($result);
	foreach ($saved as $row)
	{
		$db->sql_query("UPDATE {$g}settings SET setting_value='" . $db->sql_escape($row['setting_value']) .
			"' WHERE scope='album' AND setting_name='" . $db->sql_escape($row['setting_name']) . "'");
	}
}
elseif ($argv[2] === 'cleanup')
{
	// Pictures share the existing synthetic file; only their fixture database rows belong to this check.
	$db->sql_transaction('begin');
	$db->sql_query("DELETE FROM {$g}comments WHERE cat_id=601 AND pic_id BETWEEN 7000 AND 7011");
	$db->sql_query("DELETE FROM {$g}ratings WHERE pic_id IN (7008,7009)");
	$db->sql_query("DELETE FROM {$g}pics WHERE cat_id BETWEEN 601 AND 603 AND pic_id BETWEEN 7000 AND 8411");
	$db->sql_query("DELETE FROM {$g}cats WHERE cat_id BETWEEN 601 AND 603");
	$db->sql_query("DELETE FROM {$g}settings WHERE scope='navtest'");
	$db->sql_transaction('commit');
}
else { exit(2); }
