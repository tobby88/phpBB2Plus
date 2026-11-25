<?php
// Fixtures for the album moderation forms, confined to the disposable converted board.
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$g = $table_prefix . 'plus_album_';
$dir = realpath($phpbb_root_path . $config['upload_path']);
if (!$dir) { exit(2); }
$ids = array_merge(range(26000, 26007), range(26010, 26013));
function mod_rows($sql)
{
	global $db;
	$result = $db->sql_query($sql);
	$rows = $db->sql_fetchrowset($result);
	$db->sql_freeresult($result);
	return $rows;
}
function mod_storage($id) { global $dbname; return 'gallery_' . hash('sha256', $dbname . '/moderation/' . $id); }
$db->sql_transaction('begin');
switch ($argv[2])
{
	case 'seed':
		if (mod_rows("SELECT cat_id FROM {$g}cats WHERE cat_id BETWEEN 760 AND 767") ||
			mod_rows("SELECT pic_id FROM {$g}pics WHERE pic_id BETWEEN 26000 AND 26013") ||
			mod_rows("SELECT setting_name FROM {$g}settings WHERE scope='modtest'"))
		{ throw new RuntimeException('Moderation fixture identifiers already exist'); }
		$db->sql_query("INSERT INTO {$g}settings " . $db->sql_build_array('INSERT',
			array('scope' => 'modtest', 'setting_name' => 'topics_per_page', 'setting_value' => $config['topics_per_page'])));
		$config->set('topics_per_page', 3);
		$base = mod_rows("SELECT * FROM {$g}cats WHERE cat_id=1")[0];
		foreach (range(760, 767) as $id)
		{
			$cat = array_merge($base, array('cat_id' => $id, 'parent_id' => $id === 763 ? 762 : 0,
				'owner_id' => in_array($id, array(764, 766)) ? 87 : 0, 'source_owner' => 0, 'title' => 'Moderation category ' . $id,
				'view_level' => $id === 762 ? 1 : -1, 'upload_level' => $id === 761 ? 3 : 0,
				'delete_level' => $id === 767 ? 1 : 0, 'approval' => $id === 765 ? 1 : 2));
			$db->sql_query("INSERT INTO {$g}cats " . $db->sql_build_array('INSERT', $cat));
		}
		$db->sql_query("INSERT INTO {$g}grants (cat_id,capability,subject_type,subject_id) VALUES
			(760,'moderator','g',71),(765,'moderator','g',71),(767,'moderator','g',71),(761,'upload','g',71),(764,'moderator','u',87)");
		$base = mod_rows("SELECT * FROM {$g}pics WHERE pic_id=100")[0];
		$original = \phpbb2plus\gallery\service\pictures::original_path($dir, $base['storage_name']);
		foreach ($ids as $id)
		{
			$cat = $id < 26010 ? 760 : ($id <= 26011 ? 765 : ($id === 26012 ? 767 : 764));
			$name = mod_storage($id);
			if (!$original || file_exists($dir . '/' . $name) || !copy($original, $dir . '/' . $name))
			{ throw new RuntimeException('Could not prepare a distinct synthetic original'); }
			$pic = array_merge($base, array('pic_id' => $id, 'cat_id' => $cat, 'storage_name' => $name, 'title' => 'Panel photo ' . $id,
				'author_id' => 87, 'created_at' => 700000000, 'locked' => (int) ($id === 26006), 'legacy_uncategorized' => (int) ($id === 26003),
				'approved' => (int) !in_array($id, array(26000, 26010))));
			$db->sql_query("INSERT INTO {$g}pics " . $db->sql_build_array('INSERT', $pic));
			$db->sql_query("INSERT INTO {$g}comments " . $db->sql_build_array('INSERT', array('pic_id' => $id, 'cat_id' => $cat, 'author_id' => 87,
				'author_name' => '', 'author_ip' => '', 'created_at' => 700000001, 'body' => 'Moderation fixture')));
			$db->sql_query("INSERT INTO {$g}ratings " . $db->sql_build_array('INSERT', array('pic_id' => $id, 'user_id' => 87,
				'user_ip' => '', 'points' => 4, 'honour_points' => 0)));
		}
		// Uploads can already have a cleanup receipt for a stored file.
		$db->sql_query("INSERT INTO {$g}trash " . $db->sql_build_array('INSERT', array('storage_name' => mod_storage(26005), 'removed_at' => 2140000000)));
		break;
	case 'state':
		$state = array();
		$where = ' WHERE ' . $db->sql_in_set('pic_id', $ids);
		$photos = array_column(mod_rows("SELECT pic_id,cat_id,locked,approved,legacy_uncategorized FROM {$g}pics" . $where), null, 'pic_id');
		$comments = array();
		foreach (mod_rows("SELECT pic_id,cat_id FROM {$g}comments" . $where . ' ORDER BY comment_id') as $row)
		{ $comments[$row['pic_id']][] = (int) $row['cat_id']; }
		$ratings = array_count_values(array_column(mod_rows("SELECT pic_id FROM {$g}ratings" . $where), 'pic_id'));
		$trash = array_column(mod_rows("SELECT storage_name FROM {$g}trash WHERE " . $db->sql_in_set('storage_name', array_map('mod_storage', $ids))), 'storage_name');
		foreach ($ids as $id)
		{
			$photo = $photos[$id] ?? null;
			if ($photo) { unset($photo['pic_id']); }
			$name = mod_storage($id);
			$state[$id] = array('photo' => $photo ? array_map('intval', $photo) : null, 'comments' => $comments[$id] ?? array(),
				'ratings' => $ratings[$id] ?? 0, 'queued' => in_array($name, $trash, true), 'file' => is_file($dir . '/' . $name));
		}
		echo json_encode($state);
		break;
	case 'deny_destination': case 'allow_destination':
		$level = $argv[2] === 'deny_destination' ? 1 : 3;
		$db->sql_query("UPDATE {$g}cats SET upload_level=$level WHERE cat_id=761");
		break;
	case 'receipts':
		$existing = mod_rows("SELECT removed_at FROM {$g}trash WHERE storage_name='" . mod_storage(26005) . "'");
		if (count($existing) !== 1 || (int) $existing[0]['removed_at'] !== 2140000000)
		{ throw new RuntimeException('Deleting a batch replaced an existing media cleanup receipt'); }
		break;
	case 'revoke': case 'restore':
		if ($argv[2] === 'revoke') { $db->sql_query("DELETE FROM {$g}grants WHERE cat_id=760 AND capability='moderator' AND subject_type='g' AND subject_id=71"); }
		else { $db->sql_query("INSERT INTO {$g}grants (cat_id,capability,subject_type,subject_id) VALUES (760,'moderator','g',71)"); }
		break;
	case 'cleanup':
		$old = mod_rows("SELECT setting_value FROM {$g}settings WHERE scope='modtest' AND setting_name='topics_per_page'");
		if (!$old) { throw new RuntimeException('Missing moderation fixture'); }
		$config->set('topics_per_page', $old[0]['setting_value']);
		$db->sql_query("DELETE FROM {$g}settings WHERE scope='modtest'");
		foreach (array('comments', 'ratings', 'pics') as $table) { $db->sql_query("DELETE FROM $g$table WHERE " . $db->sql_in_set('pic_id', $ids)); }
		$db->sql_query("DELETE FROM {$g}grants WHERE cat_id BETWEEN 760 AND 767");
		$db->sql_query("DELETE FROM {$g}cats WHERE cat_id BETWEEN 760 AND 767");
		foreach ($ids as $id)
		{
			$name = mod_storage($id);
			$db->sql_query("DELETE FROM {$g}trash WHERE storage_name='$name'");
			if (is_file($dir . '/' . $name)) { unlink($dir . '/' . $name); }
		}
		break;
	default: exit(2);
}
$db->sql_transaction('commit');
