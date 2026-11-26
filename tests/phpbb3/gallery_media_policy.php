<?php
// Isolate one shared category and the imported policy in this disposable board.
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 || !preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './'; $phpEx = 'php';
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
$where = "scope IN ('sp','album') AND " . $db->sql_in_set('setting_name', array_keys(\phpbb2plus\gallery\service\settings::defaults('media')));
$dir = realpath($phpbb_root_path . $config['upload_path']);
$mark = \phpbb2plus\gallery\service\raster::watermark_path($dir, $config['plus_gallery_media_key']);
$held = $mark . '.media-test';
$phase = $argv[2];
if ($phase === 'partial_mark')
{
	if (!is_file($held) || file_exists($mark) || file_put_contents($mark, file_get_contents($held, false, null, 0, 33)) !== 33 ||
		!\phpbb2plus\gallery\service\raster::dimensions($mark, 'image/png')) { throw new RuntimeException('Cannot create a partially restored synthetic PNG'); }
	return;
}
if ($phase === 'hide_mark' || $phase === 'return_mark')
{
	$from = $phase === 'hide_mark' ? $mark : $held;
	$to = $phase === 'hide_mark' ? $held : $mark;
	if ($phase === 'return_mark' && is_file($held) && is_file($mark) && !is_link($mark) && filesize($mark) === 33 &&
		file_get_contents($mark) === file_get_contents($held, false, null, 0, 33)) { unlink($mark); }
	if (!is_file($from) || is_link($from) || file_exists($to) || !rename($from, $to)) { throw new RuntimeException('Cannot move the synthetic watermark'); }
	return;
}
if ($phase === 'state')
{
	$settings = array();
	foreach (policy_rows("SELECT scope,setting_name,setting_value FROM {$g}settings WHERE $where") as $row) { $settings[$row['scope']][$row['setting_name']] = $row['setting_value']; }
	$photo = policy_rows("SELECT storage_name FROM {$g}pics WHERE pic_id=100")[0];
	echo json_encode(array('settings' => $settings, 'variants' => count(glob($dir . '/gallery_cache_' . substr($photo['storage_name'], 8) . '_*')),
		'pictures' => policy_rows("SELECT pic_id,title,description FROM {$g}pics WHERE cat_id=930 ORDER BY pic_id")));
	return;
}
$db->sql_transaction('begin');
if ($phase === 'seed')
{
	if (policy_rows("SELECT cat_id FROM {$g}cats WHERE cat_id=930") || policy_rows("SELECT setting_name FROM {$g}settings WHERE scope='mediaqa'") || file_exists($held))
	{ throw new RuntimeException('Media policy fixture already exists'); }
	$backup = policy_rows("SELECT scope,setting_name,setting_value FROM {$g}settings WHERE $where");
	$db->sql_query("INSERT INTO {$g}settings " . $db->sql_build_array('INSERT', array('scope' => 'mediaqa','setting_name' => 'snapshot','setting_value' => json_encode($backup))));
	// The historic settings tables have different namespaces even when an extra mod reuses a key.
	$db->sql_query("DELETE FROM {$g}settings WHERE scope='album' AND setting_name='midthumb_width'");
	$db->sql_query("INSERT INTO {$g}settings (scope,setting_name,setting_value) VALUES ('album','midthumb_width','927')");
	$cat = policy_rows("SELECT * FROM {$g}cats WHERE cat_id=1")[0];
	$cat = array_merge($cat, array('cat_id' => 930,'parent_id' => 0,'owner_id' => 0,'source_owner' => 0,'title' => 'Policy fixture',
		'view_level' => -1,'upload_level' => 0,'delete_level' => 0,'approval' => 0));
	$db->sql_query("INSERT INTO {$g}cats " . $db->sql_build_array('INSERT', $cat));
	$db->sql_query("INSERT INTO {$g}grants (cat_id,capability,subject_type,subject_id) VALUES (930,'moderator','g',71)");
}
elseif ($phase === 'cleanup')
{
	if (file_exists($held) || policy_rows("SELECT pic_id FROM {$g}pics WHERE cat_id=930"))
	{ throw new RuntimeException('Expected the input file and test-picture deletion to have been restored'); }
	$backup = json_decode(policy_rows("SELECT setting_value FROM {$g}settings WHERE scope='mediaqa' AND setting_name='snapshot'")[0]['setting_value'], true);
	if (!is_array($backup)) { throw new RuntimeException('Media policy backup missing'); }
	$db->sql_query("DELETE FROM {$g}settings WHERE $where");
	if ($backup) { $db->sql_multi_insert($g . 'settings', $backup); }
	$db->sql_query("DELETE FROM {$g}settings WHERE scope='mediaqa' AND setting_name='snapshot'");
	$db->sql_query("DELETE FROM {$g}grants WHERE cat_id=930 AND capability='moderator' AND subject_type='g' AND subject_id=71");
	$db->sql_query("DELETE FROM {$g}cats WHERE cat_id=930");
}
else { exit(2); }
$db->sql_transaction('commit');
