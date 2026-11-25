<?php
// Configure the converted fixture and verify the native album records on loopback only.
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$g = $table_prefix . 'plus_album_';
function album_fixture_value($sql)
{
	global $db;
	$result = $db->sql_query($sql);
	$row = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);
	return $row ? reset($row) : false;
}
function album_fixture_config($name, $value)
{
	global $g, $db;
	$db->sql_query("UPDATE {$g}settings SET setting_value='" . $db->sql_escape($value) . "' WHERE scope='album' AND setting_name='" . $db->sql_escape($name) . "'");
}
if ($argv[2] === 'restricted')
{
	album_fixture_config('personal_gallery', '3');
	album_fixture_config('personal_sub_category_limit', '2');
	$option = (int) album_fixture_value('SELECT auth_option_id FROM ' . ACL_OPTIONS_TABLE . " WHERE auth_option='u_plus_album_personal'");
	$member = (int) album_fixture_value('SELECT user_id FROM ' . USERS_TABLE . " WHERE username='HistoricMember'");
	if (!$option || (int) album_fixture_value('SELECT COUNT(*) FROM ' . ACL_GROUPS_TABLE . " WHERE auth_option_id=$option AND group_id=71 AND auth_setting=1") !== 1 ||
		(int) album_fixture_value('SELECT COUNT(*) FROM ' . ACL_USERS_TABLE . " WHERE auth_option_id=$option AND user_id=$member AND auth_setting=1") !== 1)
	{ throw new RuntimeException('Named and single-user personal album creation grants were not converted'); }
}
elseif ($argv[2] === 'approved' || $argv[2] === 'restore')
{
	$pending = $argv[2] === 'approved' ? 0 : 1;
	$db->sql_query('UPDATE ' . USER_GROUP_TABLE . " SET user_pending=$pending WHERE group_id=71 AND user_id=87");
	$auth->acl_clear_prefetch(87);
	if ($pending)
	{
		album_fixture_config('personal_gallery', '0');
		album_fixture_config('personal_allow_sub_categories', '1');
		album_fixture_config('personal_sub_category_limit', '10');
	}
}
elseif ($argv[2] === 'disable' || $argv[2] === 'enable')
{
	album_fixture_config('personal_allow_sub_categories', $argv[2] === 'disable' ? '0' : '1');
}
elseif ($argv[2] === 'inactive' || $argv[2] === 'active')
{
	$type = $argv[2] === 'inactive' ? USER_INACTIVE : USER_NORMAL;
	$db->sql_query('UPDATE ' . USERS_TABLE . " SET user_type=$type WHERE user_id=87 AND username='DepartingFixture'");
}
elseif ($argv[2] === 'manual' || $argv[2] === 'automatic')
{
	album_fixture_config('gd_version', $argv[2] === 'manual' ? '0' : '2');
	album_fixture_config('gif_allowed', $argv[2] === 'manual' ? '1' : '0');
	if ($argv[2] === 'manual' && !(int) album_fixture_value("SELECT COUNT(*) FROM {$table_prefix}plus_media_issues WHERE asset_kind='album-thumb' AND source_id=101"))
	{ throw new RuntimeException('The converter failed to record a missing referenced album thumbnail'); }
}
elseif ($argv[2] === 'preview_cleanup')
{
	$trash = "{$g}trash";
	$result = $db->sql_query("SELECT storage_name FROM $trash");
	$found = false;
	$dir = realpath($config['upload_path']);
	while ($row = $db->sql_fetchrow($result))
	{
		$name = $row['storage_name'];
		if (!preg_match('/^gallery_([a-f0-9]{64})$/D', $name, $match)) { continue; }
		$thumb = $dir . '/gallery_small_' . $match[1];
		if (is_file($thumb) && is_file($dir . '/' . $name)) { $found = true; break; }
	}
	$db->sql_freeresult($result);
	if (!$found) { throw new RuntimeException('The removed album original and manual thumbnail have no cleanup receipt'); }
	$db->sql_query("UPDATE $trash SET removed_at=1 WHERE storage_name='$name'");
	$phpbb_container->get('phpbb2plus.gallery.cleanup')->run();
	if (is_file($thumb) || is_file($dir . '/' . $name) || (int) album_fixture_value("SELECT COUNT(*) FROM $trash WHERE storage_name='$name'"))
	{ throw new RuntimeException('The expired receipt failed to remove its protected thumbnail and original'); }
}
elseif ($argv[2] === 'complete')
{
	$roots = (int) album_fixture_value("SELECT COUNT(*) FROM {$g}cats WHERE owner_id=87 AND parent_id=0");
	$id = (int) album_fixture_value("SELECT a.pic_id FROM {$g}pics a JOIN {$g}cats c ON c.cat_id=a.cat_id WHERE c.owner_id=87 AND c.parent_id=0 AND a.title='Album transfer'");
	if (!$id || $roots !== 1 || (int) album_fixture_value("SELECT COUNT(*) FROM {$g}cats WHERE owner_id=87 AND parent_id<>0") ||
		(int) album_fixture_value("SELECT COUNT(*) FROM {$g}comments m JOIN {$g}pics p ON p.pic_id=m.pic_id AND p.cat_id=m.cat_id WHERE p.pic_id=$id AND m.body='Kommentar zum Bild'") !== 1 ||
		(int) album_fixture_value("SELECT COUNT(*) FROM {$g}ratings WHERE pic_id=$id AND points=8") !== 1 ||
		(int) album_fixture_value("SELECT COUNT(*) FROM {$g}pics WHERE title='Temporary album picture'") !== 0)
	{ throw new RuntimeException('Personal album deletion lost the retained photo, discussion, rating, or child boundary'); }
}
else { exit(2); }
