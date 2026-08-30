<?php

function pafiledb_admin_assert($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, "paFileDB administration safety test failed: $message\n");
		exit(1);
	}
}

$root = dirname(dirname(__DIR__));
$admin_category = file_get_contents($root . '/phpBB2/admin/admin_pa_category.php');
$admin_custom = file_get_contents($root . '/phpBB2/admin/admin_pa_custom.php');
$admin_flags = file_get_contents($root . '/phpBB2/admin/admin_flags.php');
$admin_catauth = file_get_contents($root . '/phpBB2/admin/admin_pa_catauth.php');
$admin_settings = file_get_contents($root . '/phpBB2/admin/admin_pa_settings.php');
$admin_ug_auth = file_get_contents($root . '/phpBB2/admin/admin_pa_ug_auth.php');
$admin_file = file_get_contents($root . '/phpBB2/admin/admin_pa_file.php');
$pafiledb_functions = file_get_contents($root . '/phpBB2/pafiledb/includes/functions.php');
$category_functions = file_get_contents($root . '/phpBB2/pafiledb/includes/functions_pafiledb.php');
$field_functions = file_get_contents($root . '/phpBB2/pafiledb/includes/functions_field.php');

pafiledb_admin_assert(strpos($admin_category, "array('do_add', 'do_delete', 'cat_order', 'sync', 'sync_all')") !== false, 'all category mutations must be identified');
pafiledb_admin_assert(strpos($admin_category, 'phpbb_admin_require_post_session();') !== false, 'category mutations must require a POST token');
pafiledb_admin_assert(strpos($admin_category, "\$_POST['move']") !== false, 'category ordering must read its direction from POST');
pafiledb_admin_assert(strpos($admin_category, 'pa_admin_category_action_form') !== false, 'category maintenance actions must render tokenized POST forms');
pafiledb_admin_assert(strpos($admin_category, '?mode=cat_order') === false, 'category ordering must not use a GET mutation');
pafiledb_admin_assert(strpos($admin_category, '?mode=sync') === false, 'category synchronization must not use a GET mutation');
pafiledb_admin_assert(strpos($admin_custom, 'phpbb_admin_require_post_session();') !== false, 'custom-field mutations must require a POST token');
pafiledb_admin_assert(strpos($admin_custom, "isset(\$_POST['field_ids']) && is_array") !== false, 'custom-field deletion IDs must be a POST array');

pafiledb_admin_assert(strpos($field_functions, '$field_id = (int) $field_id;') !== false, 'custom-field IDs must be integers');
pafiledb_admin_assert(strpos($field_functions, '$db->sql_escape(serialize($data))') !== false, 'serialized custom-field data must be escaped at the SQL boundary');
pafiledb_admin_assert(strpos($category_functions, '$cat_id = (int) $cat_id;') !== false, 'category IDs must be integers');
pafiledb_admin_assert(strpos($category_functions, 'function order_cat($cat_id, $move)') !== false, 'category ordering must receive a validated move value');
pafiledb_admin_assert(strpos($category_functions, "array('move', 'delete'), true") !== false, 'category delete modes must use a strict allowlist');
pafiledb_admin_assert(strpos($category_functions, '!isset($this->cat_rowset[$cat_parent])') !== false, 'category parents must exist');

pafiledb_admin_assert(strpos($admin_flags, 'function phpbb_flag_image_name') !== false, 'flag images need a filename validator');
pafiledb_admin_assert(strpos($admin_flags, "basename(str_replace('\\\\', '/', trim") !== false, 'flag paths must be reduced to a basename');
pafiledb_admin_assert(substr_count($admin_flags, 'phpbb_admin_require_post_session();') >= 2, 'flag saves and deletes must require POST tokens');
pafiledb_admin_assert(substr_count($admin_flags, 'phpbb_admin_html($flag_image)') >= 2, 'stored flag images must be escaped when rendered');

pafiledb_admin_assert(strpos($admin_catauth, 'phpbb_admin_require_post_session();') !== false, 'category permission writes must require a POST token');
pafiledb_admin_assert(strpos($admin_catauth, 'in_array($auth_value, $cat_auth_const, true)') !== false, 'category permission levels must use a strict allowlist');
pafiledb_admin_assert(strpos($admin_catauth, '!isset($pafiledb->cat_rowset[$temp_cat_id])') !== false, 'category permission targets must exist');
pafiledb_admin_assert(strpos($admin_catauth, "\$_REQUEST") === false, 'category permissions must not merge GET and POST input');
pafiledb_admin_assert(strpos($admin_catauth, 'phpbb_admin_session_field()') !== false, 'category permission forms must carry a session token');
pafiledb_admin_assert(strpos($admin_catauth, "'CATEGORY_NAME' => phpbb_admin_html") !== false, 'category names must be escaped in permission output');

pafiledb_admin_assert(strpos($admin_settings, 'phpbb_admin_require_post_session();') !== false, 'configuration writes must require a POST token');
pafiledb_admin_assert(strpos($admin_settings, '$editable_config = array(') !== false, 'configuration writes need a field allowlist');
pafiledb_admin_assert(strpos($admin_settings, 'in_array($config_name, $editable_config, true)') !== false, 'only allowlisted configuration fields may be written');
pafiledb_admin_assert(strpos($admin_settings, "(^|/)\\.\\.(/|$)") !== false, 'storage directories must reject traversal');
pafiledb_admin_assert(strpos($admin_settings, "'S_HIDDEN_FIELDS' => phpbb_admin_session_field()") !== false, 'configuration forms must carry a session token');
pafiledb_admin_assert(strpos($admin_settings, "'UPLOAD_DIR' => phpbb_admin_html") !== false, 'configuration text must be escaped in form output');
pafiledb_admin_assert(substr_count($pafiledb_functions, '$db->sql_escape(') >= 2, 'paFileDB configuration SQL must use driver escaping');
pafiledb_admin_assert(strpos($pafiledb_functions, "preg_match('/^[a-z0-9_]{1,191}$/Di'") !== false, 'paFileDB configuration names must be bounded identifiers');

pafiledb_admin_assert(strpos($admin_ug_auth, 'phpbb_admin_require_post_session();') !== false, 'user/group permission writes must require a POST token');
pafiledb_admin_assert(strpos($admin_ug_auth, "array('user', 'group', 'glb_user', 'glb_group')") !== false, 'user/group permission modes must use an allowlist');
pafiledb_admin_assert(strpos($admin_ug_auth, 'function pa_admin_ug_post_map') !== false, 'category permission maps must be normalized');
pafiledb_admin_assert(strpos($admin_ug_auth, 'group_single_user = 0') !== false, 'group targets must exclude personal groups');
pafiledb_admin_assert(strpos($admin_ug_auth, 'group_single_user = 1') !== false, 'user targets must resolve through their personal group');
pafiledb_admin_assert(strpos($admin_ug_auth, '@each(') === false, 'PHP 8-incompatible permission iterators must not return');
pafiledb_admin_assert(strpos($admin_ug_auth, 'static $cache = array();') !== false, 'moderator status must be cached per group');
pafiledb_admin_assert(strpos($admin_ug_auth, 'phpbb_admin_html($target[\'name\'])') !== false, 'permission target names must be escaped');

pafiledb_admin_assert(strpos($admin_file, 'function pa_admin_file_request_scalar') !== false, 'file administration must normalize scalar request values');
pafiledb_admin_assert(strpos($admin_file, "\$_REQUEST") === false, 'file administration must not merge GET and POST input');
pafiledb_admin_assert(strpos($admin_file, '$allowed_modes = array(') !== false, 'file administration modes must use an allowlist');
pafiledb_admin_assert(strpos($admin_file, 'phpbb_admin_require_post_session();') !== false, 'file mutations must require a POST token');
pafiledb_admin_assert(strpos($admin_file, '$pafiledb->delete_mirror($mirror_ids, $file_id)') !== false, 'mirror deletion must stay scoped to its download');
pafiledb_admin_assert(strpos($admin_file, "'MIRROR_URL' => pafiledb_html") !== false, 'mirror values must be escaped in administration output');
pafiledb_admin_assert(strpos($category_functions, "\$mode = (\$mode === 'category') ? 'category' : 'file';") !== false, 'file deletion modes must be normalized');
pafiledb_admin_assert(strpos($category_functions, 'AND file_id = $file_id') !== false, 'mirror updates must verify their parent download');
pafiledb_admin_assert(strpos($category_functions, 'function delete_mirror($mirror_id, $file_id = 0)') !== false, 'mirror deletion must accept a parent scope');
pafiledb_admin_assert(strpos($category_functions, '$db->sql_escape($file_long_desc)') !== false, 'download text must use database-driver escaping');

echo "paFileDB administration safety tests passed.\n";
