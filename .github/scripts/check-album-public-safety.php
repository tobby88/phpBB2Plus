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
$edit_storage = file_get_contents($root . '/phpBB2/album_mod/album_photo_storage.php');
$comment_edit = file_get_contents($root . '/phpBB2/album_comment_edit.php');
$discussion = file_get_contents($root . '/phpBB2/album_mod/album_picture_discussion.php');
$showpage = file_get_contents($root . '/phpBB2/album_showpage.php');
$upload = file_get_contents($root . '/phpBB2/album_upload.php');
$upload_storage = file_get_contents($root . '/phpBB2/album_mod/album_picture_upload.php');
$search = file_get_contents($root . '/phpBB2/album_search.php');
$functions = file_get_contents($root . '/phpBB2/album_mod/album_functions.php');

foreach (array('delete' => $delete, 'edit' => $edit, 'comment edit' => $comment_edit) as $name => $source)
{
	album_public_test_assert(strpos($source, "is_scalar(\$_GET[") !== false, $name . ' must reject array identifiers');
	album_public_test_assert(strpos($source, "\$_SERVER['REQUEST_METHOD'] !== 'POST'") !== false, $name . ' writes must require POST');
	album_public_test_assert(strpos($source, "hash_equals((string) \$userdata['session_id']") !== false, $name . ' writes must use the session token');
}

album_public_test_assert(strpos($delete, "if (!\$album_user_access['moderator'] && \$userdata['user_level'] != ADMIN)") !== false, 'authorized category moderators must be allowed to delete pictures');
album_public_test_assert(strpos($delete, '$delete_writer->remove(') !== false && strpos($delete, '@unlink(') === false, 'picture deletion must delegate to current authority and committed cleanup instead of unlinking inline');
album_public_test_assert(strpos($edit, 'album_picture_text_edit((int) $pic_id, (int) $cat_id, (int) $album_user_id, (int) $thispic[\'pic_user_id\'], $pic_title, $pic_desc)') !== false, 'picture text must use the owned writer with the verified target/category/owner/author handoff');
album_public_test_assert(strpos($edit_storage, '$this->connection->sql_escape($title)') !== false && strpos($edit_storage, '$this->connection->sql_escape($description)') !== false, 'picture metadata must use the actual owned database-driver escaping');
album_public_test_assert(strpos($comment_edit, "album_discussion_request('change', array(") !== false && strpos($comment_edit, 'AlbumPictureDiscussion::input_text(') !== false, 'edited comments must delegate canonical Unicode input to the owned writer');
album_public_test_assert(strpos($discussion, "' AND comment_pic_id=' .") !== false || strpos($discussion, "' AND comment_pic_id='.") !== false, 'comment updates must remain bound to the verified picture');
album_public_test_assert(strpos($showpage, "'S_FORM_TOKEN' => '<input type=\"hidden\" name=\"sid\"") !== false, 'picture comments and ratings must carry the session token');
album_public_test_assert(strpos($showpage, "strtoupper((string) \$_SERVER['REQUEST_METHOD']) !== 'POST'") !== false, 'picture comments and ratings must require POST');
album_public_test_assert(strpos($showpage, "album_discussion_request('publish', array(") !== false && strpos($discussion, 'bin2hex($value)') !== false, 'new comments and votes must share one owned publication using mode-independent SQL literals');
album_public_test_assert(strpos($showpage, "ANONYMOUS . \" AND rate_user_ip = '\"") !== false, 'guest ratings must be identified by IP');
album_public_test_assert(strpos($discussion, "rate_user_id='.\$actor['user_id']") !== false && strpos($discussion, "throw new AlbumDiscussionFailure('Already_rated')") !== false, 'current voter identity and selected-channel duplicate checks must be locked inside the writer');
album_public_test_assert(strpos($showpage, "['post_username']") === false, 'guest comments must not read a nonexistent post username');
$missing_picture_check = strpos($showpage, 'if( empty($thispic) )');
$picture_field_read = strpos($showpage, "\$cat_id = (\$thispic['pic_cat_id']");
album_public_test_assert($missing_picture_check !== false && $picture_field_read !== false && $missing_picture_check < $picture_field_read, 'missing pictures must be rejected before their fields are read');
album_public_test_assert(strpos($functions, 'function album_nuffload_is_staged_file') !== false && strpos($functions, "realpath(\$source)") !== false, 'upload sources must remain inside the verified Nuffload staging directory');
$upload_stage = file_get_contents($root . '/phpBB2/album_mod/album_upload_stage.php');
album_public_test_assert(strpos($upload_stage, 'fstat($handle)') !== false && strpos($upload_storage, "\$source['metadata']['bytes']") !== false, 'owned upload limits must use verified server-side source bytes');
album_public_test_assert(strpos($upload_stage, 'getimagesizefromstring($data)') !== false && strpos($upload_stage, "IMAGETYPE_JPEG=>'.jpg'") !== false, 'stored extensions must use the actual staged image type');
album_public_test_assert(strpos($upload, '$upload_writer->publish(')!==false&&strpos($upload_storage, 'function insert_sql(')!==false&&strpos($upload_storage, 'bin2hex($value)')!==false, 'actual owned publisher must encode complete metadata independently of SQL modes');
album_public_test_assert(strpos($upload,'@unlink(')===false&&strpos($upload_storage,'@unlink(')===false&&strpos($upload_storage,'$receipt->prepare(')!==false&&strpos($upload_storage,'$receipt->mark_published(')!==false, 'failed or uncertain inserts must preserve files with a durable receipt, never inline-unlink committed data');
album_public_test_assert(strpos($search, '$search_columns = array(') !== false && strpos($search, '$db->sql_escape($search)') !== false, 'Album search modes and SQL values must be constrained');
album_public_test_assert(strpos($search, 'LEFT JOIN " . ALBUM_CAT_TABLE') !== false && strpos($search, 'album_check_permission($album_user_access, ALBUM_AUTH_VIEW)') !== false, 'Album search must hide inaccessible and orphaned categories');
album_public_test_assert(strpos($search, "htmlspecialchars((string) \$row['pic_title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')") !== false, 'Album search result text must be escaped');
album_public_test_assert(strpos($search, 'LIMIT 500') !== false, 'Album search work must be bounded');

echo "Public Album action safety tests passed.\n";
