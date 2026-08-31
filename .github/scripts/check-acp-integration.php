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
	'admin_arcade_tournaments.php', 'admin_pa_ug_auth.php', 'admin_xs.php'
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
	'admin/admin_users_list.php' => array('$module[\'Users\'][\'Users List\']', 'admin/admin_users_list_body.tpl', 'phpbb_admin_require_post_session();', 'phpbb_admin_session_field()'),
	'admin/admin_user_ban.php' => array('$module[\'Users\'][\'Ban_Management\']', 'phpbb_admin_require_post_session();', 'phpbb_admin_session_field()'),
	'admin/admin_account.php' => array('$module[\'Users\'][\'Activate_title\']', 'phpbb_admin_require_post_session();', 'phpbb_admin_session_field()'),
	'admin/admin_color_groups.php' => array('$module[\'Groups\'][\'Color_Groups\']', 'admin/color_groups_manager.tpl', 'phpbb_admin_require_post_session();', 'phpbb_admin_session_field()', 'if ($color_groups_changed)'),
	'admin/admin_logs.php' => array('$module[\'Logs\'][\'Logs Actions\']', 'admin/logs_body.tpl'),
	'admin/admin_reg_ip.php' => array('$module[\'Users\'][\'Registration IP\']', 'admin/user_ip_list.tpl'),
	'admin/admin_db_maintenance.php' => array('$module[\'General\'][\'DB_Maintenance\']', 'admin/dbmtnc_list_body.tpl'),
	'admin/admin_album_nuffload_config.php' => array('$module[\'Photo_Album\'][\'Nuffload\']', 'admin/admin_album_nuffload_config_body.tpl'),
	'admin/admin_cracker_tracker.php' => array('$module[\'ctracker_module_category\'][\'ctracker_module_1\']', '?modu=11'),
	'ctracker/admin/acp_module_maintenance.php' => array('CTRACKER_RATE_LIMITS', "version_compare(PHP_VERSION, '5.6.0'", 'password_hashing', 'HTTPS'),
	'admin/admin_board.php' => array('cookie_consent_enable', 'sfs_enable', 'phpbb_admin_require_post_session();', 'phpbb_admin_session_field()', '$db->sql_escape($new[$config_name])'),
	'admin/admin_board_extend.php' => array("\$module['General']['Configuration_extend']", 'phpbb_admin_require_post_session();', 'phpbb_admin_session_field()', '$db->sql_escape((string) $$field_name)', '$dir !== false'),
	'admin/admin_hacks_list.php' => array("\$module['General']['Hacks_List']", 'phpbb_admin_require_post_session();', 'phpbb_admin_session_field()', 'admin_hacks_form_values', "preg_match('/^(delete|update|add)_id_"),
	'includes/functions_hacks_list.php' => array('$dir_handle === false', '$db->sql_escape($val)', 'if (!is_array($file_data))'),
	'includes/functions_jr_admin.php' => array('$module = array();', '$module_list = array();', 'if ($dir === false)', '$language_dir = $phpbb_root_path', 'phpbb_profile_text(isset($jr_admin_userdata[\'admin_notes\'])', '$user_id = max(0, intval($user_id));', 'basename((string) $file)', "preg_match('/^[a-f0-9]{32}$/D'", 'hash_equals((string) $userdata[\'session_id\']'),
	'includes/functions_color_groups.php' => array("preg_match('/^lang_[a-z0-9_]+$/iD'", "message_die(GENERAL_ERROR, 'Invalid language file request.'"),
	'admin/admin_jr_admin.php' => array("\$module['Users']['Jr_Admin']", 'phpbb_admin_require_post_session();', 'phpbb_admin_session_field()', '$allowed_module_hashes', 'jr_admin_safe_color'),
	'admin/admin_forumauth.php' => array("\$module['Forums']['Permissions']", 'phpbb_admin_require_post_session();', 'phpbb_admin_session_field()', '$s_column_span = 0;'),
	'admin/admin_groups.php' => array("\$module['Groups']['Manage']", 'phpbb_admin_require_post_session();', 'phpbb_admin_session_field()', '$validated_group_info', '$db->sql_escape($group_name)'),
	'admin/admin_ug_auth.php' => array("\$module['Users']['Permissions']", "\$module['Groups']['Permissions']", 'phpbb_admin_require_post_session();', 'phpbb_admin_session_field()', 'admin_ug_boolean_map'),
	'admin/admin_attachments.php' => array("\$module['Attachments']['Manage']", 'phpbb_admin_require_post_session();', 'phpbb_admin_session_field()', '$sync_confirm'),
	'admin/admin_extensions.php' => array("\$module['Extensions']['Extension_control']", 'phpbb_admin_require_post_session();', 'phpbb_admin_session_field()', '$add_forum || $delete_forum'),
	'admin/admin_attach_cp.php' => array("\$module['Attachments']['Control_Panel']", 'phpbb_admin_require_post_session();', 'phpbb_admin_session_field()', '$normalized_delete_ids'),
	'admin/admin_arcade.php' => array('$module[\'Arcade\'][\'Configuration\']', 'phpbb_admin_require_post_session();', 'phpbb_admin_session_field()', 'Invalid Arcade asset directory.'),
	'admin/admin_arcade_games.php' => array('phpbb_admin_require_post_session();', 'phpbb_admin_session_field()', 'arcade_admin_rename_game_references', 'arcade_admin_delete_game_references'),
	'admin/admin_arcade_cache.php' => array("'S_CONFIG_ACTION' =>", 'phpbb_admin_require_post_session();', 'phpbb_admin_session_field()'),
	'admin/admin_arcade_log.php' => array('phpbb_admin_require_post_session();', 'phpbb_admin_session_field()'),
	'admin/admin_arcade_cats.php' => array('phpbb_admin_require_post_session();', 'phpbb_admin_session_field()', '$target_scope', '`group_required`'),
	'admin/admin_arcade_tournaments.php' => array('phpbb_admin_require_post_session();', 'phpbb_admin_session_field()', '$maximum_games', '$maximum_tournaments'),
	'admin/admin_album_config_extended.php' => array('$module[\'Photo_Album\'][\'Configuration\']', 'phpbb_admin_require_post_session();', 'phpbb_admin_session_field()', 'album_admin_editable_config', '$db->sql_escape($config_value)'),
	'admin/admin_album_cat.php' => array('phpbb_admin_require_post_session();', 'phpbb_admin_session_field()', 'function album_admin_permission_level', 'album_get_sub_cat_ids'),
	'admin/admin_album_clearcache.php' => array('phpbb_admin_require_post_session();', 'phpbb_admin_session_field()', '!is_link($cache_item)'),
	'admin/admin_pa_custom.php' => array('phpbb_admin_require_post_session();', 'phpbb_admin_session_field()'),
	'admin/admin_pa_category.php' => array("array('do_add', 'do_delete', 'cat_order', 'sync', 'sync_all')", 'phpbb_admin_session_field()', 'pa_admin_category_action_form'),
	'admin/admin_pa_catauth.php' => array('phpbb_admin_require_post_session();', 'phpbb_admin_session_field()', 'in_array($auth_value, $cat_auth_const, true)'),
	'admin/admin_pa_settings.php' => array('phpbb_admin_require_post_session();', 'phpbb_admin_session_field()', '$editable_config'),
	'admin/admin_pa_ug_auth.php' => array('phpbb_admin_require_post_session();', 'phpbb_admin_session_field()', 'function pa_admin_ug_post_map', "array('user', 'group', 'glb_user', 'glb_group')"),
	'admin/admin_pa_file.php' => array('phpbb_admin_require_post_session();', 'phpbb_admin_session_field()', 'function pa_admin_file_request_scalar', '$allowed_modes'),
	'admin/admin_pa_license.php' => array('phpbb_admin_require_post_session();', 'phpbb_admin_session_field()', '$license_action'),
	'admin/admin_pa_fchecker.php' => array("\$pafiledb_config['upload_dir']", "\$pafiledb_config['screenshots_dir']", 'phpbb_admin_html($temp)'),
	'admin/admin_flags.php' => array('phpbb_admin_require_post_session();', 'phpbb_admin_session_field()', 'function phpbb_flag_image_name'),
	'admin/admin_db_maintenance.php' => array('dbmtnc_continuation_token', 'phpbb_admin_require_post_session();', "array('', 'start', 'perform')"),
	'admin/admin_db_utilities.php' => array("in_array(\$perform, array('backup', 'restore'), true)", 'phpbb_admin_require_post_session();', 'phpbb_admin_session_field()', 'is_uploaded_file($backup_file_tmpname)'),
	'admin/admin_links.php' => array('phpbb_admin_require_post_session();', 'phpbb_admin_session_field()', 'function admin_links_post_scalar', "'U_LINK_DELETE' => append_sid"),
	'admin/admin_news_cats.php' => array('phpbb_admin_require_post_session();', 'phpbb_admin_session_field()', "array('', 'delete', 'edit', 'save', 'savenew')"),
	'admin/admin_banner.php' => array('phpbb_admin_require_post_session();', 'phpbb_admin_session_field()', 'function admin_banner_post_scalar', 'foreach ($options as $offset => $type)'),
	'admin/admin_acronyms.php' => array('phpbb_admin_require_post_session();', 'phpbb_admin_session_field()', '$db->sql_escape($acronym)'),
	'admin/admin_words.php' => array('phpbb_admin_require_post_session();', 'phpbb_admin_session_field()', '$db->sql_escape($word)'),
	'admin/admin_ranks.php' => array('phpbb_admin_require_post_session();', 'phpbb_admin_session_field()', '$db->sql_escape($rank_title)'),
	'admin/admin_smilies.php' => array('phpbb_admin_require_post_session();', 'phpbb_admin_session_field()', '$db->sql_escape($smile_code)'),
	'admin/admin_profile_fields.php' => array('phpbb_admin_require_post_session();', 'phpbb_admin_session_field()', 'profile_field_column_identifier($name_input)'),
	'admin/admin_links_cat.php' => array('phpbb_admin_require_post_session();', 'phpbb_admin_session_field()', "array('', 'new', 'edit', 'delete')"),
	'admin/admin_forums.php' => array('$forum_write_modes', 'admin_forum_action_button', 'phpbb_admin_require_post_session();'),
	'admin/admin_styles.php' => array('xs_frameset.$phpEx?action=menu', '$no_page_header = true'),
	'admin/xs_include.php' => array('$module[\'Styles\'][\'Menu\']', 'phpbb_admin_require_post_session();'),
	'admin/admin_kb_cat.php' => array('kb_admin_category_order_form', 'kb_admin_category_parent_valid', 'phpbb_admin_require_post_session();'),
	'admin/admin_kb_types.php' => array('phpbb_admin_require_post_session();', '$db->sql_escape($type_name)'),
	'admin/admin_kb_config.php' => array('phpbb_admin_require_post_session();', 'phpbb_admin_session_field()', '$editable_fields', 'kb_admin_record_exists', '$db->sql_escape($new[$config_name])')
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
