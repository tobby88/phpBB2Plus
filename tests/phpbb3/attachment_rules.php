<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$store = $phpbb_container->get('config_text');
$key = 'test_plus_attach_rules';
$groups = EXTENSION_GROUPS_TABLE;
$types = EXTENSIONS_TABLE;
$range = 'group_id BETWEEN 30401 AND 30404';
$rows = static function ($sql) use ($db) {
	$result = $db->sql_query($sql);
	$found = $db->sql_fetchrowset($result);
	$db->sql_freeresult($result);
	return $found;
};
switch ($argv[2])
{
	case 'seed':
		$plain = $rows("SELECT g.allow_group,g.allow_in_pm FROM $groups g JOIN $types e ON e.group_id=g.group_id WHERE e.extension='txt'");
		$room = $rows('SELECT forum_password FROM ' . FORUMS_TABLE . ' WHERE forum_id=14');
		if ($store->get($key) !== null || $rows("SELECT group_id FROM $groups WHERE $range") ||
			$rows("SELECT extension FROM $types WHERE $range OR extension IN ('rpanel','prules','frules','offrules')") ||
			$rows('SELECT session_id FROM ' . FORUMS_ACCESS_TABLE . ' WHERE forum_id=14') ||
			count($plain) !== 1 || $plain[0]['allow_group'] || $plain[0]['allow_in_pm'] ||
			count($room) !== 1 || $room[0]['forum_password'] !== '')
		{
			throw new RuntimeException('Unexpected extension groups, legacy private-message policy or protected test forum');
		}
		$options = array('allow_attachments' => 1, 'allow_pm_attach' => 1, 'allow_privmsg' => 1, 'max_filesize' => 307, 'max_filesize_pm' => 601);
		$saved = array();
		foreach ($options as $name => $value) { $saved[$name] = $config[$name]; }
		$enabled_pm = array_column($rows("SELECT group_id FROM $groups WHERE allow_in_pm=1"), 'group_id');
		$store->set($key, json_encode(array($saved, $enabled_pm)));
		foreach ($options as $name => $value) { $config->set($name, $value); }
		foreach (array(
			array(30401, 'Notes &amp; plans', 'rpanel', 1, 1, 77, serialize(array(14))),
			array(30402, 'Message notes', 'prules', 0, 1, 0, ''),
			array(30403, 'Forum notes', 'frules', 1, 0, 0, ''),
			array(30404, 'Disabled notes', 'offrules', 0, 0, 0, ''),
		) as list($id, $title, $extension, $post, $pm, $limit, $forums))
		{
			$db->sql_query("INSERT INTO $groups " . $db->sql_build_array('INSERT', array('group_id' => $id,
				'group_name' => $title, 'cat_id' => 0, 'allow_group' => $post, 'allow_in_pm' => $pm,
				'download_mode' => 1, 'upload_icon' => '', 'max_filesize' => $limit, 'allowed_forums' => $forums)));
			$db->sql_query("INSERT INTO $types " . $db->sql_build_array('INSERT', array('group_id' => $id, 'extension' => $extension)));
		}
		break;
	case 'password':
		$hash = $phpbb_container->get('passwords.manager')->hash('local-attachment-room');
		$db->sql_query('UPDATE ' . FORUMS_TABLE . ' SET ' . $db->sql_build_array('UPDATE', array('forum_password' => $hash)) . ' WHERE forum_id=14');
		break;
	case 'posts_off':
		$config->set('allow_attachments', 0);
		break;
	case 'messages_off':
		$config->set('allow_attachments', 1);
		$config->set('allow_pm_attach', 0);
		break;
	case 'empty':
		$config->set('allow_pm_attach', 1);
		$db->sql_query("UPDATE $groups SET allow_in_pm=0 WHERE allow_in_pm=1");
		break;
	case 'cleanup':
		$state = json_decode((string) $store->get($key), true);
		if (!is_array($state) || count($state) !== 2) { throw new RuntimeException('The attachment rule state is missing'); }
		foreach ($state[0] as $name => $value) { $config->set($name, $value); }
		$db->sql_query("DELETE FROM $types WHERE $range");
		$db->sql_query("DELETE FROM $groups WHERE $range");
		if ($state[1]) { $db->sql_query("UPDATE $groups SET allow_in_pm=1 WHERE " . $db->sql_in_set('group_id', array_map('intval', $state[1]))); }
		$db->sql_query('DELETE FROM ' . FORUMS_ACCESS_TABLE . ' WHERE forum_id=14');
		$db->sql_query('UPDATE ' . FORUMS_TABLE . " SET forum_password='' WHERE forum_id=14");
		$store->delete($key);
		break;
	default: exit(2);
}
$cache->destroy('_extensions');
