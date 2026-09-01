<?php

function album_public_test_assert($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, "Album public-action test failed: $message\n");
		exit(1);
	}
}

$root = dirname(dirname(__DIR__));
$delete = file_get_contents($root . '/phpBB2/album_delete.php');
$edit = file_get_contents($root . '/phpBB2/album_edit.php');
$comment_edit = file_get_contents($root . '/phpBB2/album_comment_edit.php');
$showpage = file_get_contents($root . '/phpBB2/album_showpage.php');
$upload = file_get_contents($root . '/phpBB2/album_upload.php');
$search = file_get_contents($root . '/phpBB2/album_search.php');
$functions = file_get_contents($root . '/phpBB2/album_mod/album_functions.php');

foreach (array('delete' => $delete, 'edit' => $edit, 'comment edit' => $comment_edit) as $name => $source)
{
	album_public_test_assert(strpos($source, "is_scalar(\$_GET[") !== false, $name . ' must reject array identifiers');
	album_public_test_assert(strpos($source, "\$_SERVER['REQUEST_METHOD'] !== 'POST'") !== false, $name . ' writes must require POST');
	album_public_test_assert(strpos($source, "hash_equals((string) \$userdata['session_id']") !== false, $name . ' writes must use the session token');
}

album_public_test_assert(strpos($delete, "if (!\$album_user_access['moderator'] && \$userdata['user_level'] != ADMIN)") !== false, 'authorized category moderators must be allowed to delete pictures');
album_public_test_assert(strpos($delete, "basename(\$thispic['pic_filename'])") !== false, 'picture deletion must keep file removal inside the upload directory');
album_public_test_assert(strpos($edit, "\$db->sql_escape(\$pic_title)") !== false && strpos($edit, "\$db->sql_escape(\$pic_desc)") !== false, 'picture metadata must use database-driver escaping');
album_public_test_assert(strpos($comment_edit, "\$db->sql_escape(\$comment_text)") !== false, 'edited comments must use database-driver escaping');
album_public_test_assert(strpos($comment_edit, 'AND comment_pic_id = $pic_id') !== false, 'comment updates must remain bound to the verified picture');
album_public_test_assert(strpos($showpage, "'S_FORM_TOKEN' => '<input type=\"hidden\" name=\"sid\"") !== false, 'picture comments and ratings must carry the session token');
album_public_test_assert(strpos($showpage, "strtoupper((string) \$_SERVER['REQUEST_METHOD']) !== 'POST'") !== false, 'picture comments and ratings must require POST');
album_public_test_assert(strpos($showpage, "\$comment_text_sql = \$db->sql_escape(\$comment_text)") !== false, 'new comments must use database-driver escaping');
album_public_test_assert(strpos($showpage, "ANONYMOUS . \" AND rate_user_ip = '\"") !== false, 'guest ratings must be identified by IP');
album_public_test_assert(strpos($showpage, "WHERE NOT EXISTS (SELECT 1 FROM \" . ALBUM_RATE_TABLE") !== false, 'ratings must not add a second voter row');
album_public_test_assert(strpos($showpage, "['post_username']") === false, 'guest comments must not read a nonexistent post username');
$missing_picture_check = strpos($showpage, 'if( empty($thispic) )');
$picture_field_read = strpos($showpage, "\$cat_id = (\$thispic['pic_cat_id']");
album_public_test_assert($missing_picture_check !== false && $picture_field_read !== false && $missing_picture_check < $picture_field_read, 'missing pictures must be rejected before their fields are read');
album_public_test_assert(strpos($functions, 'function album_nuffload_is_staged_file') !== false && strpos($functions, "realpath(\$source)") !== false, 'upload sources must remain inside the verified Nuffload staging directory');
album_public_test_assert(strpos($upload, '$filesize = @filesize($filetmp)') !== false, 'upload limits must use the server-side file size');
album_public_test_assert(strpos($upload, '$pic_size = @getimagesize($filetmp)') !== false && strpos($upload, 'switch ($pic_image_type)') !== false, 'stored extensions must use the server-detected image type');
album_public_test_assert(strpos($upload, '$pic_title_sql = $db->sql_escape($pic_title)') !== false && strpos($upload, '$pic_user_ip_sql = $db->sql_escape($pic_user_ip)') !== false, 'uploaded picture metadata must use database-driver escaping');
album_public_test_assert(strpos($upload, "if( !\$result = \$db->sql_query(\$sql) )\n\t{\n\t\t@unlink(ALBUM_UPLOAD_PATH . \$pic_filename);") !== false, 'failed picture inserts must remove the stored upload');
album_public_test_assert(strpos($search, '$search_columns = array(') !== false && strpos($search, '$db->sql_escape($search)') !== false, 'Album search modes and SQL values must be constrained');
album_public_test_assert(strpos($search, 'LEFT JOIN " . ALBUM_CAT_TABLE') !== false && strpos($search, 'album_check_permission($album_user_access, ALBUM_AUTH_VIEW)') !== false, 'Album search must hide inaccessible and orphaned categories');
album_public_test_assert(strpos($search, "htmlspecialchars((string) \$row['pic_title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')") !== false, 'Album search result text must be escaped');
album_public_test_assert(strpos($search, 'LIMIT 500') !== false, 'Album search work must be bounded');

echo "Public Album action safety tests passed.\n";
