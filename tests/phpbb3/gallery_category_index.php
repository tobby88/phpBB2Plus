<?php
// Disposable rows for the directory, including photos that need different kinds of approval.
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$g = $table_prefix . 'plus_album_';
function directory_rows($sql)
{
	global $db;
	$result = $db->sql_query($sql);
	$rows = $db->sql_fetchrowset($result);
	$db->sql_freeresult($result);
	return $rows;
}
function directory_settings($changes)
{
	global $db, $g;
	foreach ($changes as $name => $value)
	{
		$where = "scope='album' AND setting_name='" . $db->sql_escape($name) . "'";
		$db->sql_query("DELETE FROM {$g}settings WHERE " . $where);
		$db->sql_query("INSERT INTO {$g}settings " . $db->sql_build_array('INSERT',
			array('scope' => 'album', 'setting_name' => $name, 'setting_value' => (string) $value)));
	}
}
$options = array_fill_keys(array('comment','show_index_subcats','personal_show_subcats_in_index','show_index_pics','show_index_total_pics',
	'show_index_comments','show_index_total_comments','show_index_last_pic','show_index_last_comment','show_index_thumb','line_break_subcats'), 1);
$options += array('fullpic_popup' => 0, 'new_pic_check_interval' => '1D', 'last_pic_title_length' => 8,
	'album_category_sorting' => 'cat_order', 'album_category_sorting_direction' => 'DESC');
$db->sql_transaction('begin');
if ($argv[2] === 'seed')
{
	if (directory_rows("SELECT cat_id FROM {$g}cats WHERE cat_id BETWEEN 880 AND 892") ||
		directory_rows('SELECT group_id FROM ' . GROUPS_TABLE . ' WHERE group_id IN (28881,28882)') ||
		directory_rows("SELECT pic_id FROM {$g}pics WHERE pic_id BETWEEN 28000 AND 28009") ||
		directory_rows("SELECT comment_id FROM {$g}comments WHERE comment_id BETWEEN 28500 AND 28507") ||
		directory_rows("SELECT setting_name FROM {$g}settings WHERE scope='idxtest'"))
	{ throw new RuntimeException('Directory fixture identifiers are already in use'); }
	foreach (directory_rows("SELECT setting_name,setting_value FROM {$g}settings WHERE scope='album' AND " . $db->sql_in_set('setting_name', array_keys($options))) as $setting)
	{
		$db->sql_query("INSERT INTO {$g}settings " . $db->sql_build_array('INSERT', array('scope' => 'idxtest') + $setting));
	}
	directory_settings($options);
	$category = directory_rows("SELECT * FROM {$g}cats WHERE cat_id=1")[0];
	foreach (array(880 => array(0,'Directory',0), 881 => array(880,'Branch Z',20), 882 => array(880,'Branch A',5),
		883 => array(881,'Visible child',10), 884 => array(881,'Restricted child',20), 885 => array(884,'Restricted grandchild',10),
		886 => array(881,'Empty child',30), 887 => array(883,'Grandchild',20), 888 => array(0,'Personal root',10),
		889 => array(888,'Personal preview',20), 890 => array(889,'Personal grandchild',10),
		891 => array(880,'Branch tie',30), 892 => array(880,'Branch tie',40)) as $id => $values)
	{
		$cat = array_merge($category, array('cat_id' => $id, 'parent_id' => $values[0], 'title' => $values[1],
			'sort_order' => $values[2], 'description' => 'Description ' . $id, 'label_encoded' => 0,
			'view_level' => $id === 884 ? 3 : -1, 'upload_level' => 1, 'comment_level' => 1, 'approval' => $id === 883 ? 2 : 1,
			'owner_id' => in_array($id, array(888,889,890), true) ? 87 : 0, 'source_owner' => 0, 'legacy_guest_root' => 0));
		$db->sql_query("INSERT INTO {$g}cats " . $db->sql_build_array('INSERT', $cat));
	}
	$db->sql_query("INSERT INTO {$g}grants (cat_id,capability,subject_type,subject_id) VALUES (884,'view','g',71),(883,'moderator','g',71)");
	$group = directory_rows('SELECT * FROM ' . GROUPS_TABLE . ' WHERE group_id=71')[0];
	foreach (array(28881 => 'Album editors &amp; friends', 28882 => 'Staff photographers') as $id => $name)
	{
		$row = array_merge($group, array('group_id' => $id, 'group_name' => $name, 'group_type' => GROUP_CLOSED));
		$db->sql_query('INSERT INTO ' . GROUPS_TABLE . ' ' . $db->sql_build_array('INSERT', $row));
	}
	$db->sql_query("INSERT INTO {$g}grants (cat_id,capability,subject_type,subject_id) VALUES
		(881,'moderator','g',28882),(881,'moderator','g',71),(881,'moderator','u',28881),
		(881,'moderator','g',28881),(882,'moderator','g',28881)");
	$photo = directory_rows("SELECT * FROM {$g}pics WHERE pic_id=100")[0];
	$now = time();
	foreach (array(array(881,7200),array(881,1728000),array(883,600),array(887,300),array(883,300),array(884,200),array(885,100),
		array(883,80),array(887,50),array(883,172800)) as $offset => $values)
	{
		$row = array_merge($photo, array('pic_id' => 28000 + $offset, 'cat_id' => $values[0], 'created_at' => $now - $values[1],
			'approved' => (int) !in_array($offset, array(7,8), true), 'title' => $offset === 4 ? 'Grüne Ansicht – fünf' : 'Picture ' . $offset,
			'author_id' => ANONYMOUS, 'author_removed' => (int) ($offset === 4), 'author_name' => $offset === 4 ? 'FormerFixture' : 'GuestFixture'));
		$db->sql_query("INSERT INTO {$g}pics " . $db->sql_build_array('INSERT', $row));
	}
	foreach (array(array(0,1800),array(2,900),array(3,500),array(4,500),array(5,200),array(7,100),array(8,50),array(9,99999)) as $offset => $values)
	{
		// The historic comment category may be stale after a picture moves.
		$row = array('comment_id' => 28500 + $offset, 'pic_id' => 28000 + $values[0], 'cat_id' => 884,
			'author_id' => ANONYMOUS, 'author_removed' => (int) ($offset === 3), 'author_name' => $offset === 3 ? 'FormerFixture' : 'CommentFixture',
			'author_ip' => '', 'created_at' => $now - $values[1], 'body' => 'Directory comment ' . $offset);
		$db->sql_query("INSERT INTO {$g}comments " . $db->sql_build_array('INSERT', $row));
	}
}
elseif ($argv[2] === 'volume')
{
	// Measure the directory itself on the second SQL chunk. The bulk rows need never reach the browser or subsequent suites.
	if (directory_rows("SELECT cat_id FROM {$g}cats WHERE cat_id BETWEEN 5000 AND 5509")) { throw new RuntimeException('Bulk fixture identifiers already exist'); }
	$category = directory_rows("SELECT * FROM {$g}cats WHERE cat_id=887")[0];
	$rows = array();
	for ($id = 5000; $id <= 5509; ++$id) { $rows[] = array_merge($category, array('cat_id' => $id, 'parent_id' => 883)); }
	foreach (array_chunk($rows, 100) as $chunk) { $db->sql_multi_insert($g . 'cats', $chunk); }
	$user->data['user_id'] = ANONYMOUS;
	$user->data['is_registered'] = false;
	$directory = $phpbb_container->get('phpbb2plus.gallery.category_index');
	$parent = directory_rows("SELECT * FROM {$g}cats WHERE cat_id=881")[0];
	$before = $db->sql_num_queries();
	$listing = $directory->listing($parent, $directory->options());
	$queries = $db->sql_num_queries() - $before;
	if (count($listing) !== 2 || (int) $listing[0]['category']['cat_id'] !== 883 || count($listing[0]['children']) !== 511 ||
		$listing[0]['total']['pictures'] !== 4 || $queries > 14)
	{ throw new RuntimeException('Unexpected bulk directory results or SQL query growth (' . $queries . ' queries)'); }
	$parent = directory_rows("SELECT * FROM {$g}cats WHERE cat_id=883")[0];
	$before = $db->sql_num_queries();
	$direct = $directory->listing($parent, $directory->options());
	$direct_queries = $db->sql_num_queries() - $before;
	if (count($direct) !== 511 || array_sum(array_column(array_column($direct, 'total'), 'pictures')) !== 1 || $direct_queries > 14)
	{ throw new RuntimeException('Directory queries grew with the number of direct categories (' . $direct_queries . ' queries)'); }
	$db->sql_transaction('rollback');
	echo max($queries, $direct_queries);
	return;
}
elseif ($argv[2] === 'cleanup')
{
	$db->sql_query("DELETE FROM {$g}comments WHERE comment_id BETWEEN 28500 AND 28507");
	$db->sql_query("DELETE FROM {$g}pics WHERE pic_id BETWEEN 28000 AND 28009");
	$db->sql_query("DELETE FROM {$g}grants WHERE cat_id IN (883,884) AND subject_type='g' AND subject_id=71");
	$db->sql_query("DELETE FROM {$g}grants WHERE cat_id IN (881,882) AND capability='moderator'");
	$db->sql_query('DELETE FROM ' . GROUPS_TABLE . ' WHERE group_id IN (28881,28882)');
	$db->sql_query("DELETE FROM {$g}cats WHERE cat_id BETWEEN 880 AND 892");
	$db->sql_query("DELETE FROM {$g}settings WHERE scope='album' AND " . $db->sql_in_set('setting_name', array_keys($options)));
	foreach (directory_rows("SELECT setting_name,setting_value FROM {$g}settings WHERE scope='idxtest'") as $row)
	{ $db->sql_query("INSERT INTO {$g}settings " . $db->sql_build_array('INSERT', array('scope' => 'album') + $row)); }
	$db->sql_query("DELETE FROM {$g}settings WHERE scope='idxtest'");
}
else
{
	$modes = array('hour' => array('new_pic_check_interval' => '1H'), 'month' => array('new_pic_check_interval' => '1hm'),
		'public_only' => array('personal_show_subcats_in_index' => 0, 'show_index_subcats' => 1, 'line_break_subcats' => 0),
		'personal_only' => array('personal_show_subcats_in_index' => 1, 'show_index_subcats' => 0, 'show_index_last_pic' => 0),
		'no_comments' => array('comment' => 0, 'show_index_last_pic' => 1, 'show_index_thumb' => 0),
		'popup' => array('fullpic_popup' => 1, 'show_index_thumb' => 1),
		'title' => array('album_category_sorting' => 'cat_title'), 'id' => array('album_category_sorting' => 'cat_id'),
		'minimal' => array_fill_keys(array('fullpic_popup','show_index_subcats','personal_show_subcats_in_index','show_index_pics','show_index_total_pics',
			'show_index_comments','show_index_total_comments','show_index_last_pic','show_index_last_comment','show_index_thumb','new_pic_check_interval'), 0));
	if (!isset($modes[$argv[2]])) { exit(2); }
	directory_settings($modes[$argv[2]]);
}
$db->sql_transaction('commit');
