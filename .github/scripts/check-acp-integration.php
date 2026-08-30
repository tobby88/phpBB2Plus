<?php

$repository_root = dirname(dirname(__DIR__));
$phpbb_root = $repository_root . '/phpBB2';
$errors = array();

function acp_audit_require_text($path, $needles, &$errors)
{
	$body = @file_get_contents($path);
	if ($body === false)
	{
		$errors[] = 'Missing file: ' . $path;
		return;
	}

	foreach ((array) $needles as $needle)
	{
		if (strpos($body, $needle) === false)
		{
			$errors[] = 'Missing ACP integration marker ' . $needle . ' in ' . $path;
		}
	}
}

// Every executable admin_*.php file is discovered automatically by
// functions_jr_admin.php. Files without their own registration are limited to
// documented helpers reached through another registered module.
$helper_files = array(
	'admin_album_config_clown.php', 'admin_album_config_extra.php',
	'admin_album_config_index.php', 'admin_album_config_personal.php',
	'admin_album_config_settings.php', 'admin_album_config_thumb.php',
	'admin_album_config_upload.php', 'admin_arcade_reset.php',
	'admin_arcade_scores.php', 'admin_arcade_set.php',
	'admin_arcade_tournaments.php', 'admin_xs.php'
);

foreach (glob($phpbb_root . '/admin/admin_*.php') as $admin_file)
{
	$name = basename($admin_file);
	$body = (string) file_get_contents($admin_file);
	if (strpos($body, '$module[') === false && !in_array($name, $helper_files, true))
	{
		$errors[] = 'Unregistered ACP entry point: ' . $name;
	}
	if (strpos($body, "define('IN_PHPBB'") !== false && strpos($body, "if (!defined('IN_PHPBB'))") === false)
	{
		$errors[] = 'ACP module redefines IN_PHPBB during menu discovery: ' . $name;
	}
}

$integrations = array(
	'admin/admin_users_list.php' => array('$module[\'Users\'][\'Users List\']', 'admin/admin_users_list_body.tpl'),
	'admin/admin_color_groups.php' => array('$module[\'Groups\'][\'Color_Groups\']', 'admin/color_groups_manager.tpl'),
	'admin/admin_logs.php' => array('$module[\'Logs\'][\'Logs Actions\']', 'admin/logs_body.tpl'),
	'admin/admin_reg_ip.php' => array('$module[\'Users\'][\'Registration IP\']', 'admin/user_ip_list.tpl'),
	'admin/admin_db_maintenance.php' => array('$module[\'General\'][\'DB_Maintenance\']', 'admin/dbmtnc_list_body.tpl'),
	'admin/admin_album_nuffload_config.php' => array('$module[\'Photo_Album\'][\'Nuffload\']', 'admin/admin_album_nuffload_config_body.tpl'),
	'admin/admin_cracker_tracker.php' => array('$module[\'ctracker_module_category\'][\'ctracker_module_1\']', '?modu=11'),
	'ctracker/admin/acp_module_maintenance.php' => array('CTRACKER_RATE_LIMITS', "version_compare(PHP_VERSION, '5.6.0'", 'password_hashing', 'HTTPS'),
	'admin/admin_board.php' => array('cookie_consent_enable', 'sfs_enable'),
	'admin/admin_arcade.php' => array('$module[\'Arcade\'][\'Configuration\']'),
	'admin/admin_album_config_extended.php' => array('$module[\'Photo_Album\'][\'Configuration\']'),
	'admin/admin_pa_custom.php' => array('phpbb_admin_require_post_session();', 'phpbb_admin_session_field()'),
	'admin/admin_pa_category.php' => array("array('do_add', 'do_delete')", 'phpbb_admin_session_field()'),
	'admin/admin_flags.php' => array('phpbb_admin_require_post_session();', 'phpbb_admin_session_field()', 'function phpbb_flag_image_name')
);

foreach ($integrations as $relative_path => $needles)
{
	acp_audit_require_text($phpbb_root . '/' . $relative_path, $needles, $errors);
}

$language_markers = array(
	'language/lang_english/lang_admin.php' => array('$lang[\'Users List\']', '$lang[\'Logs Actions\']', '$lang[\'Registration IP\']', '$lang[\'cookie_consent_enable\']', '$lang[\'sfs_enable\']'),
	'language/lang_german/lang_admin.php' => array('$lang[\'Users List\']', '$lang[\'Logs Actions\']', '$lang[\'Registration IP\']', '$lang[\'cookie_consent_enable\']', '$lang[\'sfs_enable\']'),
	'language/lang_english/lang_color_groups.php' => array('$lang[\'Color_Groups\']'),
	'language/lang_german/lang_color_groups.php' => array('$lang[\'Color_Groups\']'),
	'language/lang_english/lang_dbmtnc.php' => array('$lang[\'DB_Maintenance\']'),
	'language/lang_german/lang_dbmtnc.php' => array('$lang[\'DB_Maintenance\']'),
	'language/lang_english/lang_cback_ctracker.php' => array('$lang[\'ctracker_module_1\']', '$lang[\'ctracker_module_11\']', '$lang[\'ctracker_settings_m41\']'),
	'language/lang_german/lang_cback_ctracker.php' => array('$lang[\'ctracker_module_1\']', '$lang[\'ctracker_module_11\']', '$lang[\'ctracker_settings_m41\']'),
	'language/lang_english/lang_admin_album.php' => array('Nuffload'),
	'language/lang_german/lang_admin_album.php' => array('Nuffload')
);

foreach ($language_markers as $relative_path => $needles)
{
	acp_audit_require_text($phpbb_root . '/' . $relative_path, $needles, $errors);
}

// The template engine deliberately falls back to this complete base style.
// Every literal ACP template reference must therefore exist there.
$template_sources = array_merge(
	glob($phpbb_root . '/admin/*.php'),
	glob($phpbb_root . '/ctracker/admin/*.php')
);
foreach ($template_sources as $source)
{
	$body = (string) file_get_contents($source);
	if (preg_match_all("~['\"]((?:admin|ctracker/acp)/[A-Za-z0-9_./-]+\\.tpl)['\"]~", $body, $matches))
	{
		foreach (array_unique($matches[1]) as $template_path)
		{
			if (!is_file($phpbb_root . '/templates/subSilver/' . $template_path))
			{
				$errors[] = 'Missing subSilver ACP fallback template ' . $template_path . ' referenced by ' . basename($source);
			}
		}
	}
}

foreach (glob($phpbb_root . '/templates/*', GLOB_ONLYDIR) as $style_dir)
{
	if (basename($style_dir) === 'assets')
	{
		continue;
	}
	$board_template = $style_dir . '/admin/board_config_body.tpl';
	acp_audit_require_text($board_template, array('cookie_consent_enable', 'sfs_enable'), $errors);
	$ctracker_settings = $style_dir . '/ctracker/acp/acp_settings.tpl';
	if (is_file($ctracker_settings))
	{
		acp_audit_require_text($ctracker_settings, array('request_limit_enabled', 'request_limit_login', 'request_limit_register', 'request_limit_write', 'request_limit_upload'), $errors);
	}
}

if ($errors)
{
	fwrite(STDERR, implode("\n", $errors) . "\n");
	exit(1);
}

echo "ACP integration audit passed.\n";
