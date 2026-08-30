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
$category_functions = file_get_contents($root . '/phpBB2/pafiledb/includes/functions_pafiledb.php');
$field_functions = file_get_contents($root . '/phpBB2/pafiledb/includes/functions_field.php');

pafiledb_admin_assert(strpos($admin_category, "array('do_add', 'do_delete')") !== false, 'category mutations must be identified');
pafiledb_admin_assert(strpos($admin_category, 'phpbb_admin_require_post_session();') !== false, 'category mutations must require a POST token');
pafiledb_admin_assert(strpos($admin_custom, 'phpbb_admin_require_post_session();') !== false, 'custom-field mutations must require a POST token');
pafiledb_admin_assert(strpos($admin_custom, "isset(\$_POST['field_ids']) && is_array") !== false, 'custom-field deletion IDs must be a POST array');

pafiledb_admin_assert(strpos($field_functions, '$field_id = (int) $field_id;') !== false, 'custom-field IDs must be integers');
pafiledb_admin_assert(strpos($field_functions, '$db->sql_escape(serialize($data))') !== false, 'serialized custom-field data must be escaped at the SQL boundary');
pafiledb_admin_assert(strpos($category_functions, '$cat_id = (int) $cat_id;') !== false, 'category IDs must be integers');
pafiledb_admin_assert(strpos($category_functions, "array('move', 'delete'), true") !== false, 'category delete modes must use a strict allowlist');
pafiledb_admin_assert(strpos($category_functions, '!isset($this->cat_rowset[$cat_parent])') !== false, 'category parents must exist');

pafiledb_admin_assert(strpos($admin_flags, 'function phpbb_flag_image_name') !== false, 'flag images need a filename validator');
pafiledb_admin_assert(strpos($admin_flags, "basename(str_replace('\\\\', '/', trim") !== false, 'flag paths must be reduced to a basename');
pafiledb_admin_assert(substr_count($admin_flags, 'phpbb_admin_require_post_session();') >= 2, 'flag saves and deletes must require POST tokens');
pafiledb_admin_assert(substr_count($admin_flags, 'phpbb_admin_html($flag_image)') >= 2, 'stored flag images must be escaped when rendered');

echo "paFileDB administration safety tests passed.\n";
