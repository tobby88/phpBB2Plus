<?php
// Two disposable destinations for real browser uploads, one immediately public and one moderated.
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 || !preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './'; $phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$g = $table_prefix . 'plus_album_';
function progress_rows($query)
{
	global $db;
	$result = $db->sql_query($query);
	$rows = $db->sql_fetchrowset($result);
	$db->sql_freeresult($result);
	return $rows;
}
$where = "scope IN ('album','sp') AND " . $db->sql_in_set('setting_name', array_keys(\phpbb2plus\gallery\service\settings::defaults('media')));
$db->sql_transaction('begin');
switch ($argv[2])
{
	case 'seed':
		if (progress_rows("SELECT cat_id FROM {$g}cats WHERE cat_id IN (950,951)") || progress_rows("SELECT setting_name FROM {$g}settings WHERE scope='pbarqa'"))
		{ throw new RuntimeException('Occupied upload-progress fixture'); }
		$original = progress_rows("SELECT scope,setting_name,setting_value FROM {$g}settings WHERE $where");
		$db->sql_query("INSERT INTO {$g}settings " . $db->sql_build_array('INSERT', array('scope' => 'pbarqa','setting_name' => 'snapshot','setting_value' => json_encode($original))));
		$cat = progress_rows("SELECT * FROM {$g}cats WHERE cat_id=1")[0];
		foreach (array(950,951) as $id)
		{
			$row = array_merge($cat, array('cat_id' => $id, 'parent_id' => 0, 'owner_id' => 0, 'source_owner' => 0, 'title' => 'Transfer ' . $id,
				'view_level' => -1, 'upload_level' => -1, 'approval' => (int) ($id === 951)));
			$db->sql_query("INSERT INTO {$g}cats " . $db->sql_build_array('INSERT', $row));
		}
		break;
	case 'state':
		echo json_encode(progress_rows("SELECT pic_id,cat_id,title,approved,bytes FROM {$g}pics WHERE cat_id IN (950,951) ORDER BY pic_id"));
		break;
	case 'cleanup':
		$backup = progress_rows("SELECT setting_value FROM {$g}settings WHERE scope='pbarqa' AND setting_name='snapshot'");
		if (!$backup) { throw new RuntimeException('Missing upload-progress fixture backup'); }
		$stored = json_decode($backup[0]['setting_value'], true);
		$files = $phpbb_container->get('phpbb2plus.gallery.pictures');
		foreach (progress_rows("SELECT * FROM {$g}pics WHERE cat_id IN (950,951)") as $pic) { $files->discard($pic); }
		$db->sql_query("DELETE FROM {$g}cats WHERE cat_id IN (950,951)");
		$db->sql_query("DELETE FROM {$g}settings WHERE $where");
		if ($stored) { $db->sql_multi_insert($g . 'settings', $stored); }
		$db->sql_query("DELETE FROM {$g}settings WHERE scope='pbarqa' AND setting_name='snapshot'");
		break;
	default: exit(2);
}
$db->sql_transaction('commit');
