<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$g = $table_prefix . 'plus_album_';
function bookmark_rows($sql)
{
	global $db;
	$result = $db->sql_query($sql);
	$rows = $db->sql_fetchrowset($result);
	$db->sql_freeresult($result);
	return $rows;
}
$db->sql_transaction('begin');
switch ($argv[2])
{
	case 'seed':
		if (bookmark_rows("SELECT cat_id FROM {$g}cats WHERE (cat_id BETWEEN 780 AND 782) OR owner_id=87") ||
			bookmark_rows("SELECT setting_name FROM {$g}settings WHERE scope='booktest'"))
		{ throw new RuntimeException('Personal administration bookmark fixtures are already in use'); }
		foreach (array('personal_gallery' => 0, 'personal_allow_gallery_mod' => 1, 'personal_allow_sub_categories' => 1, 'personal_sub_category_limit' => 10) as $key => $value)
		{
			$row = bookmark_rows("SELECT setting_value FROM {$g}settings WHERE scope='album' AND setting_name='$key'");
			$db->sql_query("INSERT INTO {$g}settings " . $db->sql_build_array('INSERT', array('scope' => 'booktest', 'setting_name' => $key,
				'setting_value' => json_encode($row ? $row[0]['setting_value'] : null))));
			if ($row) { $db->sql_query("UPDATE {$g}settings SET setting_value='$value' WHERE scope='album' AND setting_name='$key'"); }
			else { $db->sql_query("INSERT INTO {$g}settings (scope,setting_name,setting_value) VALUES ('album','$key','$value')"); }
		}
		echo (int) bookmark_rows("SELECT author_id FROM {$g}pics WHERE pic_id=103")[0]['author_id'];
		break;
	case 'albums':
		$base = bookmark_rows("SELECT * FROM {$g}cats WHERE cat_id=1")[0];
		$member = (int) bookmark_rows("SELECT author_id FROM {$g}pics WHERE pic_id=103")[0]['author_id'];
		foreach (array(780 => 87, 781 => 87, 782 => $member) as $id => $owner)
		{
			$cat = array_merge($base, array('cat_id' => $id, 'parent_id' => $id === 781 ? 780 : 0, 'owner_id' => $owner,
				'source_owner' => 0, 'title' => 'Personal bookmark ' . $id, 'view_level' => -1, 'upload_level' => 0));
			$db->sql_query("INSERT INTO {$g}cats " . $db->sql_build_array('INSERT', $cat));
		}
		break;
	case 'disabled':
		$db->sql_query("UPDATE {$g}settings SET setting_value='0' WHERE scope='album' AND setting_name='personal_allow_sub_categories'");
		break;
	case 'cleanup':
		foreach (bookmark_rows("SELECT setting_name,setting_value FROM {$g}settings WHERE scope='booktest'") as $row)
		{
			$where = " WHERE scope='album' AND setting_name='" . $db->sql_escape($row['setting_name']) . "'";
			$value = json_decode($row['setting_value'], true);
			$db->sql_query($value === null ? "DELETE FROM {$g}settings" . $where : "UPDATE {$g}settings SET setting_value='" . $db->sql_escape($value) . "'" . $where);
		}
		$db->sql_query("DELETE FROM {$g}settings WHERE scope='booktest'");
		$db->sql_query("DELETE FROM {$g}cats WHERE cat_id BETWEEN 780 AND 782");
		break;
	default: exit(2);
}
$db->sql_transaction('commit');
