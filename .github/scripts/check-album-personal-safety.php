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

echo "Personal-album safety tests passed.\n";
