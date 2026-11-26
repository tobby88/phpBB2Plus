<?php
// Reuse the random-ballot fixture and independently restore all ACP-visible settings.
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
if ($argv[2] === 'base-seed' || $argv[2] === 'base-cleanup')
{
	$argv[2] = substr($argv[2], 5);
	require __DIR__ . '/gallery_hot.php';
	return;
}
define('IN_PHPBB', true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$g = $table_prefix . 'plus_album_';
function policy_rows($query)
{
	global $db;
	$result = $db->sql_query($query);
	$rows = $db->sql_fetchrowset($result);
	$db->sql_freeresult($result);
	return $rows;
}
$names = array_keys(\phpbb2plus\gallery\service\settings::defaults('display') + \phpbb2plus\gallery\service\settings::defaults('ratings'));
$match = $db->sql_in_set('setting_name', $names);
$db->sql_transaction('begin');
switch ($argv[2])
{
	case 'seed':
		if (policy_rows("SELECT cat_id FROM {$g}cats WHERE cat_id=940") || policy_rows("SELECT pic_id FROM {$g}pics WHERE pic_id BETWEEN 29000 AND 29002") ||
			policy_rows("SELECT scope FROM {$g}settings WHERE scope IN ('qasalbum','qassp')"))
		{ throw new RuntimeException('Gallery rating policy fixture identifiers already exist'); }
		foreach (array('album','sp') as $scope)
		{
			$db->sql_query("INSERT INTO {$g}settings (scope,setting_name,setting_value)
				SELECT 'qas$scope',setting_name,setting_value FROM {$g}settings WHERE scope='$scope' AND $match");
		}
		$db->sql_query("DELETE FROM {$g}settings WHERE scope='album' AND setting_name='rate_type'");
		$db->sql_query("INSERT INTO {$g}settings (scope,setting_name,setting_value) VALUES ('album','rate_type','83')");
		$db->sql_query("UPDATE {$g}settings SET setting_value='0 / 670' WHERE scope='sp' AND setting_name='hon_rate_where'");
		$db->sql_query("DELETE FROM {$g}settings WHERE scope='album' AND setting_name='sort_method'");
		$db->sql_query("INSERT INTO {$g}settings (scope,setting_name,setting_value) VALUES ('album','sort_method','pic_user_id')");
		$cat = policy_rows("SELECT * FROM {$g}cats WHERE cat_id=670")[0];
		$cat['cat_id'] = 940;
		$cat['title'] = 'Display policy gallery';
		$db->sql_query("INSERT INTO {$g}cats " . $db->sql_build_array('INSERT', $cat));
		$pic = policy_rows("SELECT * FROM {$g}pics WHERE pic_id=16000")[0];
		foreach (array('Zulu','Alpha','Beta') as $i => $name)
		{
			$row = array_merge($pic, array('pic_id' => 29000 + $i, 'cat_id' => 940, 'author_id' => ANONYMOUS, 'author_removed' => 0,
				'author_name' => $name, 'title' => 'Display photo ' . $name, 'created_at' => 1700000000 + $i));
			$db->sql_query("INSERT INTO {$g}pics " . $db->sql_build_array('INSERT', $row));
		}
		$db->sql_query("INSERT INTO {$g}ratings (pic_id,user_id,user_ip,points,honour_points) VALUES (16000," . ANONYMOUS . ",'192.0.2.8',7,2)");
		break;
	case 'state':
		$settings = array();
		foreach (policy_rows("SELECT scope,setting_name,setting_value FROM {$g}settings WHERE scope IN ('album','sp') AND $match") as $row)
		{ $settings[$row['scope']][$row['setting_name']] = $row['setting_value']; }
		echo json_encode(array('settings' => $settings, 'votes' => policy_rows("SELECT points,honour_points FROM {$g}ratings WHERE pic_id=16000 ORDER BY user_id")));
		break;
	case 'cleanup':
		$db->sql_query("DELETE FROM {$g}pics WHERE pic_id BETWEEN 29000 AND 29002");
		$db->sql_query("DELETE FROM {$g}cats WHERE cat_id=940");
		foreach (array('album','sp') as $scope)
		{
			$db->sql_query("DELETE FROM {$g}settings WHERE scope='$scope' AND $match");
			$db->sql_query("INSERT INTO {$g}settings (scope,setting_name,setting_value)
				SELECT '$scope',setting_name,setting_value FROM {$g}settings WHERE scope='qas$scope'");
		}
		$db->sql_query("DELETE FROM {$g}settings WHERE scope IN ('qasalbum','qassp')");
		break;
	default: exit(2);
}
$db->sql_transaction('commit');
