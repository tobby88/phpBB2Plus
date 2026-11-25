<?php
// Disposable descendants and pictures for the legacy category preview switches.
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$g = $table_prefix . 'plus_album_';
function preview_rows($sql)
{
	global $db;
	$result = $db->sql_query($sql);
	$rows = $db->sql_fetchrowset($result);
	$db->sql_freeresult($result);
	return $rows;
}
$settings = array('show_recent_in_subcats','show_recent_instead_of_nopics','personal_show_recent_in_subcats','personal_show_recent_instead_of_nopics');
$db->sql_transaction('begin');
if ($argv[2] === 'seed')
{
	if (preview_rows("SELECT cat_id FROM {$g}cats WHERE cat_id BETWEEN 650 AND 659") ||
		preview_rows("SELECT pic_id FROM {$g}pics WHERE pic_id BETWEEN 9600 AND 9606") ||
		preview_rows("SELECT setting_name FROM {$g}settings WHERE scope='prevtest'"))
	{ throw new RuntimeException('Gallery preview fixture identifiers are already in use'); }
	$configured = preview_rows("SELECT setting_name,setting_value FROM {$g}settings WHERE scope='album' AND " . $db->sql_in_set('setting_name', $settings));
	if (count($configured) !== 4) { throw new RuntimeException('The converter lost a category preview setting'); }
	foreach ($configured as $option)
	{
		$db->sql_query("INSERT INTO {$g}settings " . $db->sql_build_array('INSERT', array('scope' => 'prevtest') + $option));
	}
	$db->sql_query("UPDATE {$g}settings SET setting_value='1' WHERE scope='album' AND " . $db->sql_in_set('setting_name', $settings));
	$shared = preview_rows("SELECT * FROM {$g}cats WHERE cat_id=1")[0];
	$personal = preview_rows("SELECT c.* FROM {$g}cats c JOIN {$g}pics p ON p.cat_id=c.cat_id WHERE p.pic_id=103")[0];
	foreach (array(650 => 654, 651 => 650, 652 => 650, 653 => 652, 654 => 0, 655 => 0, 656 => 655, 657 => 655, 658 => 657, 659 => 0) as $id => $parent)
	{
		$owned = $id >= 655 && $id <= 658;
		$cat = array_merge($owned ? $personal : $shared, array('cat_id' => $id, 'parent_id' => $parent, 'title' => 'Preview album ' . $id,
			'view_level' => $owned ? 0 : ($id === 652 ? 3 : -1), 'approval' => 2));
		$db->sql_query("INSERT INTO {$g}cats " . $db->sql_build_array('INSERT', $cat));
	}
	$db->sql_query("INSERT INTO {$g}grants (cat_id,capability,subject_type,subject_id) VALUES (652,'view','g',71)");
	$source = preview_rows("SELECT * FROM {$g}pics WHERE pic_id=100")[0];
	foreach (array(650,651,652,653,650,656,659) as $index => $category)
	{
		$id = 9600 + $index;
		$db->sql_query("INSERT INTO {$g}pics " . $db->sql_build_array('INSERT', array_merge($source,
			array('pic_id' => $id, 'cat_id' => $category, 'title' => 'Preview picture ' . $id, 'created_at' => 20000, 'approved' => (int) ($index !== 4)))));
	}
}
elseif ($argv[2] === 'cleanup')
{
	foreach (preview_rows("SELECT setting_name,setting_value FROM {$g}settings WHERE scope='prevtest'") as $option)
	{
		$db->sql_query("UPDATE {$g}settings SET setting_value='" . $db->sql_escape($option['setting_value']) .
			"' WHERE scope='album' AND setting_name='" . $db->sql_escape($option['setting_name']) . "'");
	}
	$db->sql_query("DELETE FROM {$g}settings WHERE scope='prevtest'");
	$db->sql_query("DELETE FROM {$g}pics WHERE pic_id BETWEEN 9600 AND 9606");
	$db->sql_query("DELETE FROM {$g}grants WHERE cat_id=652 AND subject_type='g' AND subject_id=71 AND capability='view'");
	$db->sql_query("DELETE FROM {$g}cats WHERE cat_id BETWEEN 650 AND 659");
}
else { exit(2); }
$db->sql_transaction('commit');
