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

album_test_assert(strpos($personal, "isset(\$album_data['keys'][\$cat_id])") !== false, 'hierarchy cache lookup must be guarded');
album_test_assert(strpos($personal, "WHERE cat_id = ' . \$cat_id") !== false, 'fallback must select the exact category');
album_test_assert(strpos($personal, "AND cat_user_id = ' . (int) \$album_user_id") !== false, 'fallback must bind the category to its owner');
album_test_assert(strpos($personal, "\$no_picture_message = '';") !== false, 'picture message must always be initialized');

album_test_assert(strpos($hierarchy, "'moderator' => 0") !== false, 'missing moderator permission must default to denied');
album_test_assert(strpos($hierarchy, "'edit' => 0") !== false, 'missing edit permission must default to denied');
album_test_assert(strpos($hierarchy, "'delete' => 0") !== false, 'missing delete permission must default to denied');
album_test_assert(substr_count($hierarchy, "=> ALBUM_ADMIN") >= 2, 'missing category mutation levels must remain administrator-only');

echo "Personal-album safety tests passed.\n";
