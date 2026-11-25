<?php
// Local fixtures for personal album contents and member activity across different authors and owners.
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$g = $table_prefix . 'plus_album_';
function activity_rows($query)
{
	global $db;
	$result = $db->sql_query($query);
	$rows = $db->sql_fetchrowset($result);
	$db->sql_freeresult($result);
	return $rows;
}
$db->sql_transaction('begin');
if ($argv[2] === 'seed')
{
	if (activity_rows("SELECT cat_id FROM {$g}cats WHERE cat_id BETWEEN 720 AND 724") ||
		activity_rows("SELECT pic_id FROM {$g}pics WHERE pic_id BETWEEN 17000 AND 17012") ||
		activity_rows("SELECT comment_id FROM {$g}comments WHERE comment_id BETWEEN 17500 AND 17599") ||
		activity_rows("SELECT setting_name FROM {$g}settings WHERE scope='activity'"))
	{ throw new RuntimeException('Gallery activity fixture identifiers are already in use'); }
	$options = array('rows_per_page' => '2','cols_per_page' => '2','show_all_in_personal_gallery' => '1','rate' => '1','comment' => '1');
	foreach (activity_rows("SELECT setting_name,setting_value FROM {$g}settings WHERE scope='album' AND " . $db->sql_in_set('setting_name', array_keys($options))) as $setting)
	{
		$db->sql_query("INSERT INTO {$g}settings " . $db->sql_build_array('INSERT', array('scope' => 'activity') + $setting));
		$db->sql_query("UPDATE {$g}settings SET setting_value='" . $options[$setting['setting_name']] . "' WHERE scope='album' AND setting_name='" . $setting['setting_name'] . "'");
	}
	$member = (int) activity_rows("SELECT author_id FROM {$g}pics WHERE pic_id=103")[0]['author_id'];
	$category = activity_rows("SELECT * FROM {$g}cats WHERE cat_id=1")[0];
	$photo = activity_rows("SELECT * FROM {$g}pics WHERE pic_id=100")[0];
	foreach (array(720 => 0,721 => 720,722 => 720,723 => 722,724 => 0) as $id => $parent)
	{
		$cat = array_merge($category, array('cat_id' => $id, 'parent_id' => $parent, 'owner_id' => $id === 724 ? 87 : $member,
			'source_owner' => 0, 'view_level' => $id === 722 ? 3 : -1, 'upload_level' => 1, 'approval' => 1, 'title' => 'Activity category ' . $id));
		$db->sql_query("INSERT INTO {$g}cats " . $db->sql_build_array('INSERT', $cat));
	}
	$db->sql_query("INSERT INTO {$g}grants (cat_id,capability,subject_type,subject_id) VALUES (722,'view','g',71)");
	foreach (array(720,721,723,1,724,720,720,1,1,1,1,1,723) as $offset => $cat)
	{
		$row = array_merge($photo, array('pic_id' => 17000 + $offset, 'cat_id' => $cat, 'created_at' => 700000000,
			'author_id' => in_array($offset, array(1,2,3,4,5,6), true) ? $member : 87, 'title' => 'Member listing ' . $offset,
			'approved' => (int) ($offset !== 5), 'legacy_uncategorized' => (int) ($offset === 6), 'author_ip' => '192.0.2.66'));
		$db->sql_query("INSERT INTO {$g}pics " . $db->sql_build_array('INSERT', $row));
	}
	foreach (array(array(7,$member,4,1),array(7,87,10,0),array(8,$member,0,10),array(9,$member,8,0),array(9,87,1,0),array(12,$member,9,0)) as $v)
	{
		$db->sql_query("INSERT INTO {$g}ratings " . $db->sql_build_array('INSERT', array('pic_id' => 17000 + $v[0], 'user_id' => $v[1], 'user_ip' => '', 'points' => $v[2], 'honour_points' => $v[3])));
	}
	foreach (array(17501 => array(10,$member),17502 => array(11,$member),17503 => array(10,$member),17504 => array(12,$member),
		17505 => array(11,87),17506 => array(11,87),17507 => array(11,87)) as $id => $v)
	{
		$db->sql_query("INSERT INTO {$g}comments " . $db->sql_build_array('INSERT', array('comment_id' => $id, 'pic_id' => 17000 + $v[0],
			'cat_id' => $v[0] === 12 ? 723 : 1, 'author_id' => $v[1], 'author_name' => '', 'author_ip' => '', 'created_at' => 750000000, 'body' => 'Activity fixture')));
	}
	echo $member;
}
elseif ($argv[2] === 'gallery_admin' || $argv[2] === 'revoke_admin')
{
	$option = (int) activity_rows('SELECT auth_option_id FROM ' . ACL_OPTIONS_TABLE . " WHERE auth_option='a_plus_gallery'")[0]['auth_option_id'];
	$where = "forum_id=0 AND user_id=87 AND auth_role_id=0 AND auth_option_id=$option";
	if ($argv[2] === 'gallery_admin')
	{
		if (activity_rows('SELECT auth_setting FROM ' . ACL_USERS_TABLE . ' WHERE ' . $where)) { throw new RuntimeException('Gallery admin fixture already exists'); }
		$db->sql_query('INSERT INTO ' . ACL_USERS_TABLE . ' ' . $db->sql_build_array('INSERT', array('forum_id' => 0,
			'user_id' => 87, 'auth_role_id' => 0, 'auth_option_id' => $option, 'auth_setting' => ACL_YES)));
	}
	else { $db->sql_query('DELETE FROM ' . ACL_USERS_TABLE . ' WHERE ' . $where); }
	$auth->acl_clear_prefetch(87);
}
elseif ($argv[2] === 'toggle')
{
	$db->sql_query("UPDATE {$g}settings SET setting_value='0' WHERE scope='album' AND setting_name IN ('rate','comment','show_all_in_personal_gallery')");
}
elseif ($argv[2] === 'cleanup')
{
	foreach (activity_rows("SELECT setting_name,setting_value FROM {$g}settings WHERE scope='activity'") as $row)
	{
		$db->sql_query("UPDATE {$g}settings SET setting_value='" . $db->sql_escape($row['setting_value']) . "' WHERE scope='album' AND setting_name='" . $row['setting_name'] . "'");
	}
	$db->sql_query("DELETE FROM {$g}settings WHERE scope='activity'");
	$db->sql_query("DELETE FROM {$g}comments WHERE comment_id BETWEEN 17500 AND 17599");
	$db->sql_query("DELETE FROM {$g}ratings WHERE pic_id BETWEEN 17000 AND 17012");
	$db->sql_query("DELETE FROM {$g}pics WHERE pic_id BETWEEN 17000 AND 17012");
	$db->sql_query("DELETE FROM {$g}grants WHERE cat_id=722 AND capability='view' AND subject_type='g' AND subject_id=71");
	$db->sql_query("DELETE FROM {$g}cats WHERE cat_id BETWEEN 720 AND 724");
}
else { exit(2); }
$db->sql_transaction('commit');
