<?php
// Disposable random-rating categories and settings in the synthetic converted board.
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$g = $table_prefix . 'plus_album_';
function hot_rows($query)
{
	global $db;
	$result = $db->sql_query($query);
	$rows = $db->sql_fetchrowset($result);
	$db->sql_freeresult($result);
	return $rows;
}
function hot_setting($scope, $name, $value)
{
	global $g, $db;
	$db->sql_query("UPDATE {$g}settings SET setting_value='" . $db->sql_escape($value) . "' WHERE scope='$scope' AND setting_name='$name'");
}
$sp = array('hon_rate_where' => '670', 'hon_rate_users' => '1', 'hon_rate_times' => '0', 'hon_rate_sep' => '1');
$album = array('comment' => '1', 'rate' => '1', 'rate_scale' => '10');
$db->sql_transaction('begin');
switch ($argv[2])
{
	case 'seed':
		if (hot_rows("SELECT cat_id FROM {$g}cats WHERE cat_id BETWEEN 670 AND 675") ||
			hot_rows("SELECT pic_id FROM {$g}pics WHERE pic_id BETWEEN 16000 AND 16006") ||
			hot_rows("SELECT setting_name FROM {$g}settings WHERE scope IN ('hotalbum','hotsp')"))
		{ throw new RuntimeException('Gallery random-rating fixture identifiers are already in use'); }
		if (array_map('intval', array_column(hot_rows("SELECT pic_id FROM {$g}pics WHERE legacy_uncategorized=1 AND pic_id BETWEEN 100 AND 106 ORDER BY pic_id"), 'pic_id')) !== array(103,106))
		{ throw new RuntimeException('The converter lost the original personal picture scope'); }
		foreach (array('sp' => $sp, 'album' => $album) as $scope => $options)
		{
			$previous = hot_rows("SELECT setting_name,setting_value FROM {$g}settings WHERE scope='$scope' AND " . $db->sql_in_set('setting_name', array_keys($options)));
			if (count($previous) !== count($options)) { throw new RuntimeException('A source gallery option was not converted'); }
			foreach ($previous as $row)
			{
				$db->sql_query("INSERT INTO {$g}settings " . $db->sql_build_array('INSERT', array('scope' => 'hot' . $scope) + $row));
				hot_setting($scope, $row['setting_name'], $options[$row['setting_name']]);
			}
		}
		$category = hot_rows("SELECT * FROM {$g}cats WHERE cat_id=1")[0];
		$picture = hot_rows("SELECT * FROM {$g}pics WHERE pic_id=100")[0];
		$member = (int) hot_rows("SELECT author_id FROM {$g}pics WHERE pic_id=103")[0]['author_id'];
		foreach (array(670,671,672,673,674,675) as $id)
		{
			$cat = array_merge($category, array('cat_id' => $id, 'parent_id' => $id === 671 ? 8 : 0, 'view_level' => -1, 'rate_level' => -1,
				'title' => 'Draw album ' . $id, 'owner_id' => $id >= 673 ? $member : 0));
			$db->sql_query("INSERT INTO {$g}cats " . $db->sql_build_array('INSERT', $cat));
		}
		foreach (array(670,671,672,673,674,675,675) as $offset => $cat)
		{
			$id = 16000 + $offset;
			$row = array_merge($picture, array('pic_id' => $id, 'cat_id' => $cat, 'approved' => (int) ($cat !== 672), 'legacy_uncategorized' => (int) ($cat === 673),
				'title' => 'Draw picture ' . $id, 'views' => 21, 'description' => 'Birken & Buchen'));
			if ($id === 16005) { $row['storage_name'] = 'gallery_' . hash_hmac('sha256', 'picture:' . $id, $config['plus_gallery_media_key']); }
			$db->sql_query("INSERT INTO {$g}pics " . $db->sql_build_array('INSERT', $row));
		}
		// Simulate an upgrade from a converter that had stored no provenance in mixed personal roots.
		$migration = new \phpbb2plus\gallery\migrations\v111_random_ratings($config, $db, $phpbb_container->get('dbal.tools'), './', 'php', $table_prefix);
		if ($migration->protect_previous_imports(16004) !== true ||
			array_map('intval', array_column(hot_rows("SELECT legacy_uncategorized FROM {$g}pics WHERE cat_id=675 ORDER BY pic_id"), 'legacy_uncategorized')) !== array(1,0))
		{ throw new RuntimeException('An older conversion exposed personal root photos or excluded native uploads'); }
		$db->sql_query("INSERT INTO {$g}ratings (pic_id,user_id,user_ip,points,honour_points) VALUES (16000,$member,'',8,-1)");
		$db->sql_query("INSERT INTO {$g}comments " . $db->sql_build_array('INSERT', array('pic_id' => 16000, 'cat_id' => 670,
			'author_id' => $member, 'author_name' => '', 'author_ip' => '', 'created_at' => 111, 'body' => 'Gallery ballot fixture')));
		break;
	case 'numbers':
		echo json_encode(array('views' => (int) hot_rows("SELECT views FROM {$g}pics WHERE pic_id=16000")[0]['views'],
			'votes' => hot_rows("SELECT user_id,points,honour_points FROM {$g}ratings WHERE pic_id=16000 ORDER BY user_id")));
		break;
	case 'locked': $db->sql_query("UPDATE {$g}pics SET locked=1 WHERE pic_id=16000"); break;
	case 'guests_off': hot_setting('sp', 'hon_rate_users', '0'); break;
	case 'together': hot_setting('sp', 'hon_rate_sep', '0'); hot_setting('sp', 'hon_rate_times', '1'); break;
	case 'silent': hot_setting('album', 'rate', '0'); hot_setting('album', 'comment', '0'); break;
	case 'restricted': hot_setting('sp', 'hon_rate_where', '0 / 671'); break;
	case 'pending': hot_setting('sp', 'hon_rate_where', '672'); break;
	case 'old_personal': hot_setting('sp', 'hon_rate_where', '673'); break;
	case 'personal': hot_setting('sp', 'hon_rate_where', '674'); break;
	case 'all': hot_setting('sp', 'hon_rate_where', '0'); break;
	case 'public': hot_setting('sp', 'hon_rate_where', '670'); break;
	case 'cleanup':
		foreach (array('sp','album') as $scope)
		{
			foreach (hot_rows("SELECT setting_name,setting_value FROM {$g}settings WHERE scope='hot$scope'") as $row)
			{ hot_setting($scope, $row['setting_name'], $row['setting_value']); }
		}
		$db->sql_query("DELETE FROM {$g}settings WHERE scope IN ('hotalbum','hotsp')");
		$db->sql_query("DELETE FROM {$g}ratings WHERE pic_id=16000");
		$db->sql_query("DELETE FROM {$g}comments WHERE pic_id=16000");
		$db->sql_query("DELETE FROM {$g}pics WHERE pic_id BETWEEN 16000 AND 16006");
		$db->sql_query("DELETE FROM {$g}cats WHERE cat_id BETWEEN 670 AND 675");
		break;
	default: exit(2);
}
$db->sql_transaction('commit');
