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
$mirror_storage = file_get_contents($root . '/phpBB2/pafiledb/includes/functions_mirror_storage.php');
$admin_category = file_get_contents($root . '/phpBB2/admin/admin_pa_category.php');
$admin_custom = file_get_contents($root . '/phpBB2/admin/admin_pa_custom.php');
$admin_flags = file_get_contents($root . '/phpBB2/admin/admin_flags.php');
$admin_catauth = file_get_contents($root . '/phpBB2/admin/admin_pa_catauth.php');
$admin_settings = file_get_contents($root . '/phpBB2/admin/admin_pa_settings.php');
$settings_storage = file_get_contents($root . '/phpBB2/pafiledb/includes/functions_settings_storage.php');
$admin_ug_auth = file_get_contents($root . '/phpBB2/admin/admin_pa_ug_auth.php');
$admin_file = file_get_contents($root . '/phpBB2/admin/admin_pa_file.php');
$admin_license = file_get_contents($root . '/phpBB2/admin/admin_pa_license.php');
$admin_fchecker = file_get_contents($root . '/phpBB2/admin/admin_pa_fchecker.php');
$pafiledb_functions = file_get_contents($root . '/phpBB2/pafiledb/includes/functions.php');
$category_functions = file_get_contents($root . '/phpBB2/pafiledb/includes/functions_pafiledb.php');
$category_actions = file_get_contents($root . '/phpBB2/pafiledb/includes/functions_category_actions.php');
$field_functions = file_get_contents($root . '/phpBB2/pafiledb/includes/functions_field.php');
$pafiledb_search = file_get_contents($root . '/phpBB2/pafiledb/modules/pa_search.php');
$search_helpers = file_get_contents($root . '/phpBB2/pafiledb/includes/functions_search.php');
$pafiledb_upload = file_get_contents($root . '/phpBB2/pafiledb/modules/pa_user_upload.php');
$pafiledb_mcp = file_get_contents($root . '/phpBB2/pafiledb/modules/pa_mcp.php');

pafiledb_admin_assert(strpos($admin_category, "array('do_add', 'do_delete', 'cat_order', 'sync', 'sync_all')") !== false, 'all category mutations must be identified');
pafiledb_admin_assert(strpos($admin_category, 'phpbb_admin_require_post_session();') !== false, 'category mutations must require a POST token');
pafiledb_admin_assert(strpos($admin_category, "\$_POST['move']") !== false, 'category ordering must read its direction from POST');
pafiledb_admin_assert(strpos($admin_category, 'pa_admin_category_action_form') !== false, 'category maintenance actions must render tokenized POST forms');
pafiledb_admin_assert(strpos($admin_category, '?mode=cat_order') === false, 'category ordering must not use a GET mutation');
pafiledb_admin_assert(strpos($admin_category, '?mode=sync') === false, 'category synchronization must not use a GET mutation');
pafiledb_admin_assert(strpos($admin_custom, 'phpbb_admin_require_post_session();') !== false, 'custom-field mutations must require a POST token');
pafiledb_admin_assert(strpos($admin_custom, "isset(\$_POST['field_ids']) && is_array") !== false, 'custom-field deletion IDs must be a POST array');
pafiledb_admin_assert(strpos($admin_custom, "\$module['Download']['Mfieldtitle']") !== false, 'custom-field module discovery must not read an unloaded language entry');

pafiledb_admin_assert(strpos($field_functions, '$field_id=PaFileDbFileAuthority::id($field_id);') !== false, 'custom-field IDs must use the strict shared identifier validator');
pafiledb_admin_assert(strpos($field_functions, "return pafiledb_custom_save('save',\$post)") !== false && strpos($field_functions, "return pafiledb_custom_save('delete',") !== false, 'legacy custom-field entry points must delegate to the owned writer');
pafiledb_admin_assert(strpos($category_actions, '$id=PaFileDbFileAuthority::id($id);') !== false, 'category mutation IDs must use the strict shared identifier validator');
pafiledb_admin_assert(strpos($category_functions, 'function order_cat($cat_id, $move)') !== false, 'category ordering must receive a validated move value');
pafiledb_admin_assert(strpos($category_actions, "in_array(\$values[\$key],array('delete','move'),true)") !== false, 'category delete modes must use a strict allowlist');
// Parent existence and cycle checks now run against the current locked tree,
// not the request's cached rowset; exercised by the native category writer test.

pafiledb_admin_assert(strpos($admin_flags, 'function phpbb_flag_image_name') !== false, 'flag images need a filename validator');
pafiledb_admin_assert(strpos($admin_flags, "basename(str_replace('\\\\', '/', trim") !== false, 'flag paths must be reduced to a basename');
pafiledb_admin_assert(substr_count($admin_flags, 'phpbb_admin_require_post_session();') >= 2, 'flag saves and deletes must require POST tokens');
pafiledb_admin_assert(substr_count($admin_flags, 'phpbb_admin_html($flag_image)') >= 2, 'stored flag images must be escaped when rendered');

pafiledb_admin_assert(strpos($admin_catauth, 'phpbb_admin_require_post_session();') !== false, 'category permission writes must require a POST token');
$permission_storage = file_get_contents($root . '/phpBB2/pafiledb/includes/functions_permission_storage.php');
pafiledb_admin_assert(strpos($admin_catauth, 'pafiledb_category_permissions_save($_POST)') !== false && strpos($permission_storage, 'function pafiledb_permission_value(') !== false, 'whole category batches require the owned strict permission writer');
pafiledb_admin_assert(strpos($permission_storage, 'pafiledb_permission_categories($storage,$ids,$authority)') !== false, 'permission targets are checked against current locked categories');
pafiledb_admin_assert(strpos($admin_catauth, "\$_REQUEST") === false, 'category permissions must not merge GET and POST input');
pafiledb_admin_assert(strpos($admin_catauth, 'phpbb_admin_session_field()') !== false, 'category permission forms must carry a session token');
pafiledb_admin_assert(strpos($admin_catauth, "'CATEGORY_NAME' => phpbb_admin_html") !== false, 'category names must be escaped in permission output');

pafiledb_admin_assert(strpos($admin_settings, 'phpbb_admin_require_post_session();') !== false, 'configuration writes must require a POST token');
pafiledb_admin_assert(strpos($admin_settings, 'pafiledb_settings_save($_POST)') !== false && strpos($settings_storage, 'function pafiledb_settings_fields()') !== false, 'configuration writes need a complete owned field allowlist');
pafiledb_admin_assert(strpos($settings_storage, 'foreach(pafiledb_settings_fields()as$name)') !== false, 'only allowlisted configuration fields may be written');
pafiledb_admin_assert(strpos($settings_storage, "(^|/)\\.\\.(/|$)") !== false, 'storage directories must reject traversal');
pafiledb_admin_assert(strpos($admin_settings, "'S_HIDDEN_FIELDS' => phpbb_admin_session_field()") !== false, 'configuration forms must carry a session token');
pafiledb_admin_assert(strpos($admin_settings, "'UPLOAD_DIR' => phpbb_admin_html") !== false, 'configuration text must be escaped in form output');
pafiledb_admin_assert(strpos($settings_storage, '$storage->sql_escape($values[$name])') !== false && strpos($settings_storage, '$storage->sql_escape($name)') !== false, 'paFileDB configuration SQL must use owning-driver escaping');
pafiledb_admin_assert(strpos($pafiledb_functions, 'function set_config(') === false, 'Unowned per-field configuration writer must not remain as an alternate path');

pafiledb_admin_assert(strpos($admin_ug_auth, 'phpbb_admin_require_post_session();') !== false, 'user/group permission writes must require a POST token');
pafiledb_admin_assert(strpos($admin_ug_auth, "array('user', 'group', 'glb_user', 'glb_group')") !== false, 'user/group permission modes must use an allowlist');
pafiledb_admin_assert(strpos($admin_ug_auth, 'pafiledb_group_permissions_save($_POST)') !== false && strpos($permission_storage, 'function pafiledb_permission_map(') !== false, 'complete permission maps use the owned whole-batch writer');
pafiledb_admin_assert(strpos($admin_ug_auth, 'group_single_user = 0') !== false, 'group targets must exclude personal groups');
pafiledb_admin_assert(strpos($admin_ug_auth, 'group_single_user = 1') !== false, 'user targets must resolve through their personal group');
pafiledb_admin_assert(strpos($admin_ug_auth, '@each(') === false, 'PHP 8-incompatible permission iterators must not return');
pafiledb_admin_assert(strpos($admin_ug_auth, 'static $cache = array();') !== false, 'moderator status must be cached per group');
pafiledb_admin_assert(strpos($admin_ug_auth, 'phpbb_admin_html($target[\'name\'])') !== false, 'permission target names must be escaped');

pafiledb_admin_assert(strpos($admin_file, 'function pa_admin_file_request_scalar') !== false, 'file administration must normalize scalar request values');
pafiledb_admin_assert(strpos($admin_file, "\$_REQUEST") === false, 'file administration must not merge GET and POST input');
pafiledb_admin_assert(strpos($admin_file, '$allowed_modes = array(') !== false, 'file administration modes must use an allowlist');
pafiledb_admin_assert(strpos($admin_file, 'phpbb_admin_require_post_session();') !== false, 'file mutations must require a POST token');
pafiledb_admin_assert(strpos($admin_file, '$pafiledb->mirror_request($file_id)') !== false && strpos($mirror_storage, 'DELETE FROM ') !== false, 'mirror deletion must stay scoped to its download through the owning batch writer');
pafiledb_admin_assert(strpos($admin_file, "'MIRROR_URL' => \$mirror_url") !== false && strpos($admin_file, "htmlspecialchars(\$mirror_data['file_dlurl']") !== false, 'raw mirror values must be escaped in administration output');
pafiledb_admin_assert(strpos($category_functions, "\$mode === 'category' ? 'clear-files' : 'invalid'") !== false, 'unknown file deletion modes must be rejected rather than converted to file deletion');
pafiledb_admin_assert(strpos($mirror_storage, "' WHERE file_id='") !== false && strpos($mirror_storage, "' AND mirror_id='") !== false, 'mirror updates must verify their parent download');
pafiledb_admin_assert(strpos($category_functions, 'function delete_mirror($mirror_id, $file_id = 0)') !== false, 'mirror deletion must accept a parent scope');
pafiledb_admin_assert(strpos($category_functions, '$db->sql_escape($file_long_desc)') !== false, 'download text must use database-driver escaping');

pafiledb_admin_assert(strpos($admin_license, 'phpbb_admin_session_field()') !== false, 'license forms must use the central session token');
pafiledb_admin_assert(substr_count($admin_license, 'phpbb_admin_require_post_session();') >= 3, 'all license writes must verify the POST token');
pafiledb_admin_assert(strpos($admin_license, "in_array(\$license_action, array('add', 'edit', 'delete'), true)") !== false, 'license actions must use an allowlist');
pafiledb_admin_assert(strpos($admin_license, 'function pa_license_form_scalar') !== false && strpos($admin_license, 'pafiledb_license_save($action, $_POST)') !== false, 'license writes must use the owned batch writer and preserve rejected drafts');
pafiledb_admin_assert(strpos($admin_license, 'phpbb_admin_html($license[\'license_name\'])') !== false, 'license names must be escaped in output');

$checker = file_get_contents($root . '/phpBB2/pafiledb/includes/functions_file_checker.php');
pafiledb_admin_assert(strpos($admin_fchecker,'pafiledb_file_check()')!==false&&strpos($checker,'pafiledb_file_directories($authority->settings())')!==false,'file checker uses current owned download/screenshot directories');
pafiledb_admin_assert(strpos($checker,'pafiledb_file_url_identity(')!==false,'file checker uses the maintained local URL reference parser');
pafiledb_admin_assert(strpos($checker,'FROM \'.PA_MIRRORS_TABLE.')!==false&&strpos($checker,'FROM \'.PA_FILE_JOURNAL_TABLE.')!==false,'file checker protects mirror and pending journal references');
pafiledb_admin_assert(strpos($checker,"begin_checker()")!==false,'file checker owns its read-only coordinated operation');
pafiledb_admin_assert(strpos($admin_fchecker,"'DEL_FILE'=>phpbb_admin_html(\$path)")!==false,'file checker escapes filesystem output');
pafiledb_admin_assert(strpos($pafiledb_search, '$db->sql_escape(') !== false, 'paFileDB search terms must be escaped at the SQL boundary');
pafiledb_admin_assert(strpos($pafiledb_search, 'each($result_list)') === false, 'paFileDB search must not use the removed each() iterator');
pafiledb_admin_assert(strpos($pafiledb_search, 'global $_REQUEST') === false, 'paFileDB search must not accept cookie-backed request values');
pafiledb_admin_assert(strpos($pafiledb_search, "preg_match('/^[1-9][0-9]*\$/D', \$search_id_value)") !== false, 'paFileDB cached search IDs must be positive integers');
if (!defined('IN_PHPBB')) { define('IN_PHPBB', true); }
require_once $root . '/phpBB2/pafiledb/includes/functions_search.php';
pafiledb_admin_assert(pafiledb_search_ids('42,42,0,-1,2147483648,1 OR 1=1,43') === array(42,43), 'cached file IDs must reject duplicates, overflow and non-identifiers');
pafiledb_admin_assert(strpos($pafiledb_search, 'pafiledb_search_ids(isset($search_data[') !== false && strpos($pafiledb_search, 'pafiledb_search_refresh_ids($db, $search_categories, $search_results)') !== false, 'cached candidates must be validated and rechecked against current access');
pafiledb_admin_assert(strpos($pafiledb_search, 'pafiledb_search_author_sql($db, $search_author)') !== false && strpos($search_helpers, '$database->sql_escape($pattern)') !== false, 'author predicates must use the driver-escaped shared builder');
pafiledb_admin_assert(strpos($pafiledb_search, "\$db->sql_escape(serialize(\$store_search_data))") !== false, 'serialized paFileDB search state must use driver escaping');
pafiledb_admin_assert(strpos($pafiledb_search, "\$db->sql_escape(\$userdata['session_id'])") !== false, 'paFileDB search sessions must use driver escaping');
pafiledb_admin_assert(strpos($pafiledb_search, "'pafiledb' => 1") !== false, 'paFileDB cached results must be namespaced');
pafiledb_admin_assert(strpos($pafiledb_search, "'search_results' => \$search_results") !== false, 'paFileDB cache storage must use an explicit field map');
pafiledb_admin_assert(strpos($pafiledb_search, '${$store_vars[$i]}') === false && strpos($pafiledb_search, '$$store_vars[$i]') === false, 'dynamic variable-variable cache storage must be removed');
pafiledb_admin_assert(strpos($pafiledb_search, 'in_array($cached_sort_method, $allowed_sort_methods, true)') !== false, 'cached paFileDB sort fields must use an allowlist');
pafiledb_admin_assert(strpos($pafiledb_search, 'LEFT JOIN " . PA_VOTES_TABLE') === false, 'paFileDB result totals must not multiply vote and comment joins');
pafiledb_admin_assert(strpos($pafiledb_upload, "'FILE_LONG_DESC' => pafiledb_html(\$file_long_desc)") !== false, 'download descriptions must be escaped in edit forms');
pafiledb_admin_assert(strpos($pafiledb_upload, "'FILE_DLURL' => pafiledb_html(\$file_url)") !== false, 'download URLs must be escaped in edit forms');
pafiledb_admin_assert(strpos($pafiledb_upload, 'if (!$file_info)') !== false && strpos($pafiledb_upload, '!isset($this->auth[$source_cat_id])') !== false, 'stale download/category rows must fail before permission lookup');
pafiledb_admin_assert(strpos($pafiledb_mcp, 'array_slice(array_values($file_ids), 0, 500)') !== false, 'moderator batch IDs must be normalized and bounded');
pafiledb_admin_assert(substr_count($pafiledb_mcp, "isset(\$_REQUEST['sort_") === 2 && substr_count($pafiledb_mcp, "is_scalar(\$_REQUEST['sort_") === 2, 'moderator sort fields must reject arrays');
pafiledb_admin_assert(strpos($pafiledb_mcp, '$approved_file_rowset = array();') !== false && strpos($pafiledb_mcp, '$total_files = 0;') !== false, 'moderator result collections must be initialized for empty categories');

echo "paFileDB administration safety tests passed.\n";
