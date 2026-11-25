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
	// These represent an interrupted publication in this disposable directory.
	foreach (array($dir . '/' . $name . '.part', $thumb . '.part') as $partial)
	{
		$handle = fopen($partial, 'xb');
		if (!$handle) { throw new RuntimeException('Could not stage fixture remnants'); }
		fwrite($handle, 'partial');
		fclose($handle);
	}
	$db->sql_query("UPDATE $trash SET removed_at=1 WHERE storage_name='$name'");
	$phpbb_container->get('phpbb2plus.gallery.cleanup')->run();
	if (is_file($thumb) || is_file($dir . '/' . $name) || is_file($thumb . '.part') || is_file($dir . '/' . $name . '.part') ||
		(int) album_fixture_value("SELECT COUNT(*) FROM $trash WHERE storage_name='$name'"))
	{ throw new RuntimeException('The expired receipt failed to remove its protected thumbnail and original'); }
}
elseif ($argv[2] === 'resize_rendered')
{
	$dir = realpath($config['upload_path']);
	foreach (array('PNG resized' => array(20,12,'image/png'), 'Portrait resized' => array(2,18,'image/jpeg')) as $title => $want)
	{
		$result = $db->sql_query("SELECT storage_name,media_type,width,height,bytes FROM {$g}pics WHERE title='" . $db->sql_escape($title) . "'");
		$pic = $db->sql_fetchrow($result);
		$db->sql_freeresult($result);
		if (!$pic || !preg_match('/^gallery_[a-f0-9]{64}$/D', $pic['storage_name'])) { throw new RuntimeException('Missing resized upload'); }
		$file = $dir . '/' . $pic['storage_name'];
		$actual = getimagesize($file);
		if (array((int) $pic['width'], (int) $pic['height'], $pic['media_type']) !== $want ||
			array($actual[0],$actual[1],$actual['mime']) !== $want || (int) $pic['bytes'] !== filesize($file))
		{ throw new RuntimeException('Resized size, proportions, MIME type or orientation disagree with the stored file'); }
		if ($title === 'PNG resized')
		{
			$image = imagecreatefrompng($file);
			if ((imagecolorat($image, 0, 0) >> 24) !== 127) { throw new RuntimeException('A transparent uploaded PNG lost its alpha channel'); }
		}
	}
}
elseif (preg_match('/^(category|grants|personal_rules|preview_cache)_(delegate|revoke)$/D', $argv[2], $match))
{
	$options = array('category' => 'a_plus_album_categories', 'grants' => 'a_plus_album_auth', 'personal_rules' => 'a_plus_album_personal_auth',
		'preview_cache' => 'a_plus_album_cache');
	$name = $options[$match[1]];
	$option = (int) album_fixture_value('SELECT auth_option_id FROM ' . ACL_OPTIONS_TABLE . " WHERE auth_option='$name'");
	if (!$option) { throw new RuntimeException('The narrow native gallery permission is unavailable'); }
	$where = "forum_id=0 AND user_id=87 AND auth_role_id=0 AND auth_option_id=$option";
	if ($match[2] === 'delegate')
	{
		if ((int) album_fixture_value('SELECT COUNT(*) FROM ' . ACL_USERS_TABLE . ' WHERE ' . $where)) { throw new RuntimeException('Duplicate fixture category grant'); }
		$db->sql_query('INSERT INTO ' . ACL_USERS_TABLE . ' ' . $db->sql_build_array('INSERT',
			array('forum_id'=>0,'user_id'=>87,'auth_role_id'=>0,'auth_option_id'=>$option,'auth_setting'=>ACL_YES)));
	}
	else { $db->sql_query('DELETE FROM ' . ACL_USERS_TABLE . ' WHERE ' . $where); }
	$auth->acl_clear_prefetch(87);
}
elseif ($argv[2] === 'personal_rules_start')
{
	album_fixture_config('personal_gallery', '3');
	album_fixture_config('personal_gallery_view', '3');
	if ((int) album_fixture_value("SELECT COUNT(*) FROM {$g}grants WHERE cat_id=0") ||
		(int) album_fixture_value("SELECT COUNT(*) FROM {$g}grants WHERE cat_id=81 AND subject_type='g' AND subject_id=71 AND capability='view'") !== 1 ||
		(int) album_fixture_value("SELECT COUNT(*) FROM {$g}grants WHERE cat_id=82 AND subject_type='g' AND subject_id=74 AND capability='view'") !== 1 ||
		(int) album_fixture_value("SELECT COUNT(*) FROM {$g}grants WHERE cat_id=81 AND subject_type='u' AND capability='moderator'") !== 1)
	{ throw new RuntimeException('The converter did not preserve mixed personal category grants and an individual moderator'); }
}
elseif ($argv[2] === 'personal_rules_never' || $argv[2] === 'personal_rules_allow')
{
	$option = (int) album_fixture_value('SELECT auth_option_id FROM ' . ACL_OPTIONS_TABLE . " WHERE auth_option='u_plus_album_personal'");
	if ($argv[2] === 'personal_rules_never')
	{
		$db->sql_query('INSERT INTO ' . ACL_GROUPS_TABLE . ' ' . $db->sql_build_array('INSERT',
			array('forum_id'=>0,'group_id'=>75,'auth_role_id'=>0,'auth_option_id'=>$option,'auth_setting'=>ACL_NEVER)));
	}
	else { $db->sql_query('DELETE FROM ' . ACL_GROUPS_TABLE . " WHERE forum_id=0 AND group_id=75 AND auth_role_id=0 AND auth_option_id=$option AND auth_setting=" . ACL_NEVER); }
	$auth->acl_clear_prefetch(89);
}
elseif ($argv[2] === 'personal_creator_pending' || $argv[2] === 'personal_creator_approved')
{
	$pending = $argv[2] === 'personal_creator_pending' ? 1 : 0;
	$db->sql_query('UPDATE ' . USER_GROUP_TABLE . " SET user_pending=$pending WHERE group_id=75 AND user_id=89");
	$auth->acl_clear_prefetch(89);
}
elseif ($argv[2] === 'personal_rules_future' || $argv[2] === 'personal_rules_existing')
{
	$existing = $argv[2] === 'personal_rules_existing';
	$cats = (int) album_fixture_value("SELECT COUNT(*) FROM {$g}cats WHERE owner_id>" . ANONYMOUS . " AND legacy_guest_root=0");
	$view = (int) album_fixture_value("SELECT COUNT(*) FROM {$g}grants g JOIN {$g}cats c ON c.cat_id=g.cat_id WHERE c.owner_id>" . ANONYMOUS . " AND c.legacy_guest_root=0 AND g.subject_type='g' AND g.subject_id=71 AND g.capability='view'");
	if (($existing ? $view !== $cats : $view >= $cats) ||
		(int) album_fixture_value("SELECT COUNT(*) FROM {$g}grants WHERE cat_id=0 AND subject_type='g' AND subject_id=71") !== ($existing ? 6 : 7) ||
		(int) album_fixture_value("SELECT COUNT(*) FROM {$g}grants WHERE cat_id=81 AND subject_type='u' AND capability='moderator'") !== 1 ||
		(int) album_fixture_value("SELECT COUNT(*) FROM {$g}grants WHERE cat_id=82 AND subject_type='g' AND subject_id=74 AND capability='view'") !== 1 ||
		(int) album_fixture_value("SELECT COUNT(*) FROM {$g}grants WHERE cat_id=8 AND subject_type='g' AND subject_id=71 AND capability='view'") !== 1 ||
		(int) album_fixture_value("SELECT COUNT(*) FROM {$g}grants g JOIN {$g}cats c ON c.cat_id=g.cat_id WHERE c.legacy_guest_root=1 AND g.subject_id=71 AND g.subject_type='g'"))
	{ throw new RuntimeException('Changing personal group defaults altered another principal, scope or collection'); }
}
elseif ($argv[2] === 'personal_rules_finished')
{
	$option = (int) album_fixture_value('SELECT auth_option_id FROM ' . ACL_OPTIONS_TABLE . " WHERE auth_option='u_plus_album_personal'");
	if ((int) album_fixture_value('SELECT COUNT(*) FROM ' . ACL_GROUPS_TABLE . " WHERE group_id=75 AND auth_option_id=$option AND auth_setting=" . ACL_YES) ||
		(int) album_fixture_value('SELECT COUNT(*) FROM ' . ACL_USERS_TABLE . " WHERE auth_option_id=$option AND auth_setting=" . ACL_YES) < 1)
	{ throw new RuntimeException('The member-specific native creation grant or the revoked group grant changed'); }
	album_fixture_config('personal_gallery', '0');
}
elseif ($argv[2] === 'personal_rules_existing_only')
{
	if ((int) album_fixture_value("SELECT COUNT(*) FROM {$g}grants WHERE cat_id=0 AND subject_type='g' AND subject_id=75") ||
		(int) album_fixture_value("SELECT COUNT(*) FROM {$g}grants WHERE cat_id=8 AND capability='delete' AND subject_type='g' AND subject_id=75"))
	{ throw new RuntimeException('An existing-only album update altered a future default or public category'); }
}
elseif ($argv[2] === 'grants_groups')
{
	$result = $db->sql_query('SELECT * FROM ' . GROUPS_TABLE . ' WHERE group_id=71');
	$original = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);
	if (!$original || (int) album_fixture_value('SELECT COUNT(*) FROM ' . GROUPS_TABLE . ' WHERE group_id BETWEEN 9300 AND 9320'))
	{ throw new RuntimeException('The disposable group range is not available'); }
	$rows = array();
	for ($id = 9300; $id <= 9320; ++$id) { $rows[] = array('group_id' => $id, 'group_name' => 'ZZ Gallery ' . $id) + $original; }
	$db->sql_multi_insert(GROUPS_TABLE, $rows);
}
elseif ($argv[2] === 'grants_approved' || $argv[2] === 'grants_pending')
{
	$pending = $argv[2] === 'grants_pending' ? 1 : 0;
	$db->sql_query('UPDATE ' . USER_GROUP_TABLE . " SET user_pending=$pending WHERE group_id=71 AND user_id=87");
}
elseif ($argv[2] === 'grants_complete')
{
	if ((int) album_fixture_value("SELECT COUNT(*) FROM {$g}grants WHERE cat_id=8 AND subject_type='g' AND subject_id=9320 AND capability='delete'") !== 1 ||
		(int) album_fixture_value("SELECT COUNT(*) FROM {$g}grants WHERE cat_id=8 AND subject_type='g' AND subject_id=71 AND capability='view'") !== 1 ||
		(int) album_fixture_value("SELECT COUNT(*) FROM {$g}grants WHERE cat_id=8 AND subject_type='g' AND subject_id IN (71,74)") !== 1 ||
		(int) album_fixture_value("SELECT COUNT(*) FROM {$g}grants WHERE cat_id=8 AND subject_type='u' AND capability='moderator'") !== 1)
	{ throw new RuntimeException('Saving a page altered another group page, an individual grant or the original category grants'); }
	require_once './includes/functions_user.php';
	$user->session_begin();
	$user->setup();
	group_delete(9320, 'ZZ Gallery 9320');
	if ((int) album_fixture_value("SELECT COUNT(*) FROM {$g}grants WHERE subject_type='g' AND subject_id=9320"))
	{ throw new RuntimeException('A deleted group retained a gallery permission'); }
	$db->sql_query('DELETE FROM ' . GROUPS_TABLE . ' WHERE group_id BETWEEN 9300 AND 9319');
}
elseif ($argv[2] === 'category_grants')
{
	$member = (int) album_fixture_value('SELECT user_id FROM ' . USERS_TABLE . " WHERE username='HistoricMember'");
	if ((int) album_fixture_value("SELECT COUNT(*) FROM {$g}grants WHERE cat_id=8 AND ((capability='view' AND subject_type='g' AND subject_id=71) OR (capability='moderator' AND subject_type='u' AND subject_id=$member))") !== 2)
	{ throw new RuntimeException('Editing a public category changed a migrated group or moderator grant'); }
}
elseif ($argv[2] === 'category_moved')
{
	$pic = (int) album_fixture_value("SELECT p.pic_id FROM {$g}pics p JOIN {$g}cats c ON c.cat_id=p.cat_id WHERE p.title='Public category transfer' AND c.title='Category target'");
	if (!$pic || (int) album_fixture_value("SELECT COUNT(*) FROM {$g}comments m JOIN {$g}pics p ON p.pic_id=m.pic_id AND p.cat_id=m.cat_id WHERE p.pic_id=$pic") !== 1 ||
		(int) album_fixture_value("SELECT COUNT(*) FROM {$g}ratings WHERE pic_id=$pic AND points=8") !== 1)
	{ throw new RuntimeException('Public category removal lost the picture, comment category or rating'); }
}
elseif (preg_match('/^category_removed_([1-9][0-9]*)$/D', $argv[2], $match))
{
	$pic = (int) $match[1];
	foreach (array('pics','comments','ratings') as $table)
	{
		if ((int) album_fixture_value("SELECT COUNT(*) FROM {$g}{$table} WHERE pic_id=$pic"))
		{ throw new RuntimeException('Deleting the public category left part of its discarded picture behind'); }
	}
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
