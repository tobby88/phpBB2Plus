<?php

function album_test_assert($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, "Album personal-gallery test failed: $message\n");
		exit(1);
	}
}

$root = dirname(dirname(__DIR__));
$personal = file_get_contents($root . '/phpBB2/album_mod/album_personal.php');
$hierarchy = file_get_contents($root . '/phpBB2/album_mod/album_hierarchy_sql.php');
$admin_categories = file_get_contents($root . '/phpBB2/admin/admin_album_cat.php');
$category_template = file_get_contents($root . '/phpBB2/templates/subSilver/admin/album_cat_new_body.tpl');
$admin_config = file_get_contents($root . '/phpBB2/admin/admin_album_config_extended.php');
$config_helpers = file_get_contents($root . '/phpBB2/album_mod/album_acp_functions.php');
$config_template = file_get_contents($root . '/phpBB2/templates/subSilver/admin/album_config_body_extended.tpl');
$clear_cache = file_get_contents($root . '/phpBB2/admin/admin_album_clearcache.php');

album_test_assert(strpos($personal, "isset(\$album_data['keys'][\$cat_id])") !== false, 'hierarchy cache lookup must be guarded');
album_test_assert(strpos($personal, "WHERE cat_id = ' . \$cat_id") !== false, 'fallback must select the exact category');
album_test_assert(strpos($personal, "AND cat_user_id = ' . (int) \$album_user_id") !== false, 'fallback must bind the category to its owner');
album_test_assert(strpos($personal, "\$no_picture_message = '';") !== false, 'picture message must always be initialized');

album_test_assert(strpos($hierarchy, "'moderator' => 0") !== false, 'missing moderator permission must default to denied');
album_test_assert(strpos($hierarchy, "'edit' => 0") !== false, 'missing edit permission must default to denied');
album_test_assert(strpos($hierarchy, "'delete' => 0") !== false, 'missing delete permission must default to denied');
album_test_assert(substr_count($hierarchy, "=> ALBUM_ADMIN") >= 2, 'missing category mutation levels must remain administrator-only');

album_test_assert(strpos($admin_categories, 'phpbb_admin_require_post_session();') !== false, 'category writes must verify the AdminCP POST token');
album_test_assert(strpos($admin_categories, "array('new', 'edit', 'delete')") !== false, 'category write modes must use an allowlist');
album_test_assert(strpos($admin_categories, 'function album_admin_permission_level') !== false, 'category permission levels must be normalized');
album_test_assert(strpos($admin_categories, 'album_admin_category_exists($cat_parent)') !== false, 'category parents must exist');
album_test_assert(strpos($admin_categories, "album_get_sub_cat_ids(\$cat_id, \$descendant_ids") !== false, 'category moves must reject descendants');
album_test_assert(strpos($admin_categories, 'SET cat_parent = $deleted_parent_id WHERE cat_parent = $cat_id') !== false, 'deletion must reparent child categories');
album_test_assert(strpos($admin_categories, '$db->sql_escape($cat_title)') !== false, 'category text must use database-driver escaping');
album_test_assert(strpos($admin_categories, '= each(') === false, 'PHP 8-incompatible category iteration must not return');
album_test_assert(strpos($category_template, 'name="cat_id"') !== false, 'category write targets must travel in the tokenized POST form');

album_test_assert(!is_file($root . '/phpBB2/admin/admin_album_config.php'), 'the duplicate legacy configuration module must remain removed');
album_test_assert(strpos($admin_config, 'phpbb_admin_require_post_session();') !== false, 'configuration writes must verify the AdminCP POST token');
album_test_assert(strpos($admin_config, 'is_scalar($_POST[\'save_config\'])') !== false, 'configuration actions must reject array input');
album_test_assert(strpos($admin_config, 'album_admin_editable_config($submitted_tab_data') !== false, 'configuration writes must be limited to fields on the submitted tab');
album_test_assert(strpos($admin_config, '$db->sql_escape($config_value)') !== false, 'configuration values must use database-driver escaping');
album_test_assert(strpos($admin_config, 'phpbb_admin_html($default_config[$config_name])') !== false, 'stored configuration must be escaped for AdminCP output');
album_test_assert(strpos($config_helpers, '#^admin/album_config_[A-Za-z0-9_/-]+\\.tpl$#D') !== false, 'configuration field discovery must stay inside known Album templates');
album_test_assert(strpos($config_template, '{S_FORM_TOKEN}') !== false, 'configuration forms must carry the session token');
album_test_assert(strpos($clear_cache, 'phpbb_admin_require_post_session();') !== false && strpos($clear_cache, 'phpbb_admin_session_field()') !== false, 'thumbnail cache deletion must use the central AdminCP token');
album_test_assert(strpos($clear_cache, "preg_match('/\\.(?:gif|png|jpe?g|webp)\$/iD'") !== false, 'thumbnail cache deletion must use an end-anchored image extension');
album_test_assert(strpos($clear_cache, '!is_link($cache_item)') !== false, 'thumbnail cache deletion must not follow symbolic links');
album_test_assert(strpos($clear_cache, "isset(\$_POST['cancel'])") !== false, 'thumbnail cache cancellation must leave the confirmation page');

echo "Personal-album safety tests passed.\n";
