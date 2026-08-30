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

echo "Public Album action safety tests passed.\n";
