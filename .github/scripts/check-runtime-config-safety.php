<?php

define('IN_PHPBB', true);
require dirname(dirname(__DIR__)) . '/phpBB2/includes/functions.php';

function runtime_config_assert($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, "Runtime configuration safety failed: $message\n");
		exit(1);
	}
}

$normalized = phpbb_normalize_board_config(array(
	'posts_per_page' => 0,
	'topics_per_page' => 1000000,
	'max_poll_options' => -1,
	'max_inbox_privmsgs' => 'invalid',
	'flood_interval' => 99999999,
	'max_link_bookmarks' => -20,
	'session_length' => 1,
	'max_autologin_time' => 9999,
	'custom_setting' => 'preserved',
));

runtime_config_assert($normalized['posts_per_page'] === 1, 'post page sizes must stay positive');
runtime_config_assert($normalized['topics_per_page'] === 200, 'topic page sizes must be capped');
runtime_config_assert($normalized['max_poll_options'] === 1, 'poll option limits must stay positive');
runtime_config_assert($normalized['max_inbox_privmsgs'] === 1, 'malformed numeric settings must become a bounded integer');
runtime_config_assert($normalized['flood_interval'] === 86400, 'flood intervals must be capped');
runtime_config_assert($normalized['max_link_bookmarks'] === 0, 'optional bookmark links may be disabled but not negative');
runtime_config_assert($normalized['session_length'] === 100, 'session lifetimes must retain a safe minimum');
runtime_config_assert($normalized['max_autologin_time'] === 365, 'auto-login lifetimes must be capped');
runtime_config_assert($normalized['custom_setting'] === 'preserved', 'unrelated configuration must survive normalization');

$common = file_get_contents(dirname(dirname(__DIR__)) . '/phpBB2/common.php');
runtime_config_assert(strpos($common, '$board_config = phpbb_normalize_board_config($board_config);') !== false, 'common bootstrap must normalize cached and database configuration');

$installer = file_get_contents(dirname(dirname(__DIR__)) . '/phpBB2/install/install.php');
runtime_config_assert(strpos($installer, 'isset($available_dbms[stripslashes((string) $_POST[\'dbms\'])])') !== false, 'installer database drivers must use an explicit allowlist');
runtime_config_assert(strpos($installer, 'phpbb_normalize_host(') !== false, 'installer server names must use the shared host validator');
runtime_config_assert(strpos($installer, 'phpbb_normalize_port(') !== false, 'installer server ports must use the shared port validator');
runtime_config_assert(strpos($installer, 'phpbb_normalize_script_path(') !== false, 'installer script paths must use the shared path validator');
runtime_config_assert(strpos($installer, "str_replace('install', '', dirname") === false, 'installer paths must not use substring removal on PHP_SELF');
runtime_config_assert(strpos($installer, '$dmbs') === false, 'installer upgrade handoff must retain the selected database driver');
runtime_config_assert(substr_count($installer, 'install_html(') >= 34, 'installer form values must be escaped before entering HTML attributes');
runtime_config_assert(strpos($installer, 'install_html_raw($config_data)') !== false, 'generated configuration data must be escaped without changing its contents');

echo "Runtime configuration safety checks passed.\n";
