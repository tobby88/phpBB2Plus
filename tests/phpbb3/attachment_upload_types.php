<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './'; $phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$setting = 'plus_attach_upload_types';
$mode = (string) $config[$setting];
$store = $phpbb_container->get('config_text');
$key = 'test_attachment_upload_types';
$groups = EXTENSION_GROUPS_TABLE; $types = EXTENSIONS_TABLE; $files = ATTACHMENTS_TABLE;
$directory = $phpbb_root_path . $config['upload_path'] . '/';
$physical = 'plus_fixture_archived_note';
$count = static function ($from) use ($db) {
	$result = $db->sql_query('SELECT COUNT(*) AS n FROM ' . $from);
	$found = (int) $db->sql_fetchfield('n'); $db->sql_freeresult($result); return $found;
};
switch ($argv[2])
{
	case 'native':
	case 'native-choice':
		if ($mode !== '-1') { throw new RuntimeException('A native board unexpectedly restricted its configured attachment types'); }
		if ($argv[2] === 'native-choice') { $config->set($setting, 0); }
		break;
	case 'kept':
		if ($mode !== '0') { throw new RuntimeException('The importer overwrote an explicit native attachment policy'); }
		break;
	case 'seed':
		if ($mode !== '1' || $store->get($key) !== null || file_exists($directory . $physical) ||
			$count("$groups WHERE group_id=30411") || $count("$types WHERE extension IN ('py','rnote')") ||
			$count("$files WHERE attach_id=30412") || $count(POSTS_TABLE . ' WHERE post_id=32 AND topic_id=1 AND poster_id=' . ANONYMOUS) !== 1)
		{ throw new RuntimeException('Missing imported upload policy or conflicting attachment type fixture'); }
		$r = $db->sql_query("SELECT group_id FROM $groups WHERE allow_group=1");
		$enabled = array_column($db->sql_fetchrowset($r), 'group_id'); $db->sql_freeresult($r);
		$store->set($key, json_encode(array(scandir($directory), $enabled)));
		$db->sql_query("INSERT INTO $groups " . $db->sql_build_array('INSERT', array('group_id' => 30411,
			'group_name' => 'Classroom notes', 'cat_id' => 0, 'allow_group' => 1, 'allow_in_pm' => 1,
			'download_mode' => 1, 'upload_icon' => '', 'max_filesize' => 400, 'allowed_forums' => '')));
		foreach (array('py','rnote') as $type)
		{
			$db->sql_query("INSERT INTO $types " . $db->sql_build_array('INSERT', array('group_id' => 30411, 'extension' => $type)));
		}
		$note = "Archived classroom note.\n";
		$db->sql_query("INSERT INTO $files " . $db->sql_build_array('INSERT', array('attach_id' => 30412,
			'post_msg_id' => 32, 'topic_id' => 1, 'in_message' => 0, 'poster_id' => ANONYMOUS, 'is_orphan' => 0,
			'physical_filename' => $physical, 'real_filename' => 'previous.py', 'extension' => 'py',
			'mimetype' => 'text/plain', 'attach_comment' => '', 'filesize' => strlen($note), 'filetime' => 1100000000)));
		file_put_contents($directory . $physical, $note);
		$cache->destroy('_extensions');
		break;
	case 'existing-only':
		$db->sql_query("UPDATE $groups SET allow_group=0 WHERE group_id<>30411 AND allow_group=1");
		$db->sql_query("UPDATE $types SET group_id=0 WHERE group_id=30411 AND extension='rnote'");
		$cache->destroy('_extensions');
		break;
	case 'clean':
		$existing = json_decode((string) $store->get($key), true);
		if (!is_array($existing) || count($existing) !== 2) { throw new RuntimeException('Lost the attachment fixture inventory'); }
		$expected = array_merge($existing[0], array($physical)); sort($expected);
		$clean = scandir($directory) === $expected &&
			!$count("$files WHERE real_filename IN ('class-note.py','class-note.rnote') AND is_orphan=1") &&
			$count("$types WHERE group_id=30411 AND extension='py'") === 1 && file_get_contents($directory . $physical) === "Archived classroom note.\n";
		unlink($directory . $physical);
		$db->sql_query("DELETE FROM $files WHERE attach_id=30412 AND physical_filename='$physical'");
		$db->sql_query("DELETE FROM $types WHERE group_id=30411 OR (group_id=0 AND extension='rnote')");
		$db->sql_query("DELETE FROM $groups WHERE group_id=30411");
		if ($existing[1]) { $db->sql_query("UPDATE $groups SET allow_group=1 WHERE " . $db->sql_in_set('group_id', array_map('intval', $existing[1]))); }
		$cache->destroy('_extensions');
		$config->set($setting, '1');
		$store->delete($key);
		if (!$clean) { throw new RuntimeException('The upload policy left an orphan or modified stored file data or source groups'); }
		break;
	default: exit(2);
}
