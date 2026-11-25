<?php
// Isolated album owners keep directory paging independent of the forum's global page size.
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$g = $table_prefix . 'plus_album_';
function directory_fixture($query)
{
	global $db;
	$result = $db->sql_query($query);
	$row = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);
	return $row;
}
$size = max(1, min(100, (int) $config['topics_per_page']));
$db->sql_transaction('begin');
if ($argv[2] === 'seed')
{
	if (directory_fixture('SELECT user_id FROM ' . USERS_TABLE . ' WHERE user_id BETWEEN 41000 AND 41119') ||
		directory_fixture("SELECT cat_id FROM {$g}cats WHERE cat_id BETWEEN 42000 AND 42119") ||
		directory_fixture("SELECT pic_id FROM {$g}pics WHERE pic_id BETWEEN 43000 AND 43119"))
	{ throw new RuntimeException('Gallery directory fixture identifiers are already in use'); }
	$account = directory_fixture('SELECT * FROM ' . USERS_TABLE . ' WHERE user_id=87');
	$base = directory_fixture("SELECT * FROM {$g}cats WHERE cat_id=1");
	$picture = directory_fixture("SELECT * FROM {$g}pics WHERE pic_id=100");
	// The public owners have tied join dates and upload times but names in reverse ID order.
	$owners = $size + 3;
	for ($index = 0; $index < $owners + 3; ++$index)
	{
		$id = 41000 + $index;
		$name = 'ArchiveAlbum' . str_pad($owners + 3 - $index, 3, '0', STR_PAD_LEFT);
		$user = array_merge($account, array('user_id' => $id, 'username' => $name, 'username_clean' => utf8_clean_string($name),
			'user_regdate' => 800000000 + (intdiv($index, 2) % 3) * 86400, 'user_permissions' => '', 'user_password' => '',
			'user_email' => '', 'user_type' => $index === $owners + 2 ? USER_INACTIVE : USER_NORMAL));
		$db->sql_query('INSERT INTO ' . USERS_TABLE . ' ' . $db->sql_build_array('INSERT', $user));
		$cat = array_merge($base, array('cat_id' => 42000 + $index, 'owner_id' => $id, 'source_owner' => 0,
			'parent_id' => 0, 'view_level' => $index === $owners + 1 ? 3 : -1, 'approval' => 2, 'title' => 'Member gallery ' . $index));
		$db->sql_query("INSERT INTO {$g}cats " . $db->sql_build_array('INSERT', $cat));
		if ($index === $owners) { continue; } // An empty gallery has no directory entry.
		$row = array_merge($picture, array('pic_id' => 43000 + $index, 'cat_id' => $cat['cat_id'], 'created_at' => 900000000 - (intdiv($index,2) % 3) * 86400,
			'legacy_uncategorized' => (int) ($index === 2)));
		$db->sql_query("INSERT INTO {$g}pics " . $db->sql_build_array('INSERT', $row));
	}
	// A second public photo, a pending one, and a visible child of a restricted parent all belong to the first owner.
	foreach (array(42110 => array(42000, -1), 42111 => array(42000, 3), 42112 => array(42111, -1)) as $id => $link)
	{
		$cat = array_merge($base, array('cat_id' => $id, 'parent_id' => $link[0], 'owner_id' => 41000, 'source_owner' => 0,
			'view_level' => $link[1], 'approval' => 2, 'title' => 'Member child ' . $id));
		$db->sql_query("INSERT INTO {$g}cats " . $db->sql_build_array('INSERT', $cat));
	}
	$db->sql_query("INSERT INTO {$g}grants (cat_id,capability,subject_type,subject_id) VALUES (42111,'view','g',71)");
	foreach (array(43110 => array(42110,1),43111 => array(42110,0),43112 => array(42112,1)) as $id => $spec)
	{
		$row = array_merge($picture, array('pic_id' => $id, 'cat_id' => $spec[0], 'approved' => $spec[1], 'created_at' => 700000000 + $id));
		$db->sql_query("INSERT INTO {$g}pics " . $db->sql_build_array('INSERT', $row));
	}
	// Give a previously earlier owner the newest upload, opposite to the numeric order of picture IDs.
	$db->sql_query("UPDATE {$g}pics SET created_at=990000000 WHERE pic_id=43001");
	echo json_encode(array('size' => $size, 'owners' => $owners));
}
elseif ($argv[2] === 'cleanup')
{
	$db->sql_query("DELETE FROM {$g}grants WHERE cat_id=42111 AND subject_type='g' AND subject_id=71 AND capability='view'");
	$db->sql_query("DELETE FROM {$g}pics WHERE pic_id BETWEEN 43000 AND 43119");
	$db->sql_query("DELETE FROM {$g}cats WHERE cat_id BETWEEN 42000 AND 42119");
	$db->sql_query('DELETE FROM ' . USERS_TABLE . ' WHERE user_id BETWEEN 41000 AND 41119');
}
else { exit(2); }
$db->sql_transaction('commit');
