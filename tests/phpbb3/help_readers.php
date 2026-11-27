<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 || !preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './'; $phpEx = 'php'; require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$settings = $table_prefix . 'plus_dl_settings';
$text = $phpbb_container->get('config_text');
$key = 'test_plus_help_readers';
$flags = array('allow_bbcode', 'allow_attachments', 'allow_pm_attach', 'allow_privmsg');
$set_downloads = static function ($value) use ($db, $settings) {
	$db->sql_query("INSERT INTO $settings (config_name,config_value) VALUES ('allow_bbcode'," . (int) $value . ')
		ON DUPLICATE KEY UPDATE config_value=VALUES(config_value)');
};
switch ($argv[2])
{
	case 'prepare':
		if ($text->get($key) !== null) { throw new RuntimeException('A reader-help fixture is already active'); }
		$original = array();
		foreach ($flags as $flag) { $original[$flag] = $config[$flag]; }
		$result = $db->sql_query("SELECT config_value FROM $settings WHERE config_name='allow_bbcode'");
		$row = $db->sql_fetchrow($result); $db->sql_freeresult($result);
		$text->set($key, json_encode(array($original, $row ? $row['config_value'] : null)));
		foreach ($flags as $flag) { $config->set($flag, 1); }
		$set_downloads(1);
		break;
	case 'downloads-off':
		$set_downloads(0);
		break;
	case 'board-off':
		foreach (array('allow_bbcode','allow_attachments','allow_pm_attach') as $flag) { $config->set($flag, 0); }
		$set_downloads(1);
		break;
	case 'restore':
		list($original, $download) = json_decode($text->get($key), true);
		foreach ($original as $flag=>$value) { $config->set($flag, $value); }
		if ($download === null) { $db->sql_query("DELETE FROM $settings WHERE config_name='allow_bbcode'"); }
		else { $set_downloads($download); }
		$text->delete($key);
		break;
	default: exit(2);
}
