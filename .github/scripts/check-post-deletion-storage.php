<?php
require __DIR__ . '/check-attachment-mutation.php';
define('GENERAL_MESSAGE', 200);
define('POSTS_TEXT_TABLE', 'fixture_post_text');
$lang['Topic_post_not_exist'] = 'missing post';
set_error_handler(function ($severity, $message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } });

// Exercise the actual form coordinator without rendering or accepting uploads.
class PostDeletionForm extends attach_posting
{
	function __construct() {}
	function handle_attachments($mode) { return true; }
	function display_attachment_bodies() {}
}
function post_deletion_fixture()
{
	global $mutation_server, $upload_dir;
	$mutation_server = new MutationServer();
	$mutation_server->pdo->exec('ALTER TABLE fixture_posts ADD forum_id INTEGER DEFAULT 3');
	$mutation_server->pdo->exec('CREATE TABLE fixture_post_text (post_id INTEGER PRIMARY KEY, post_text TEXT)');
	$mutation_server->pdo->exec("INSERT INTO fixture_post_text VALUES (10,'Grüße')");
	$mutation_server->pdo->exec('INSERT INTO fixture_posts VALUES (11,100,0,3)');
	file_put_contents($upload_dir . '/fixture.txt', 'owned');
	mutation_publisher('fixture.txt')->do_insert_attachment('last_attachment', 'post', 10);
}
function post_deletion_preserved($label)
{
	global $mutation_server, $upload_dir;
	mutation_check((int) $mutation_server->pdo->query('SELECT COUNT(*) FROM fixture_posts WHERE post_id = 10')->fetchColumn() === 1, $label . ': preserve parent');
	mutation_check($mutation_server->count_rows(POSTS_TEXT_TABLE) === 1, $label . ': preserve text');
	mutation_check($mutation_server->count_rows(ATTACHMENTS_TABLE) === 1 && $mutation_server->count_rows(ATTACHMENTS_DESC_TABLE) === 1 && is_file($upload_dir . '/fixture.txt'), $label . ': preserve attachments');
	mutation_check($mutation_server->owner === null, $label . ': release lock');
}
$upload_dir = sys_get_temp_dir() . '/phpbb-post-delete-' . uniqid('', true);
try
{
	mutation_check(mkdir($upload_dir, 0700), 'Create owned post-deletion fixture');
	foreach (array('delete', 'editpost') as $mode)
	{
		post_deletion_fixture();
		$confirm = true; $delete = false; $post_id = 10; $refresh = false;
		$is_auth = array('auth_delete' => true, 'auth_mod' => false);
		$form = new PostDeletionForm();
		$form->posting_attachment_mod();
		post_deletion_preserved('Form handling before successful ' . $mode);
	}
	$source = file_get_contents($forum_root . 'includes/functions_post.php');
	require $forum_root . 'includes/functions_post.php';
	$end = strpos($source, "\nfunction delete_post(");
	mutation_check($end !== false, 'Locate actual delete controller');
	mutation_check(strpos(substr($source, $end), 'phpbb_delete_post_storage($db, $post_id, $topic_id, $forum_id);') !== false, 'Actual delete controller calls guarded storage');
	foreach (array(0, -1, '', null, true, '10,11', '10,10', array(10), '10x', '9999999999999999999999999') as $invalid)
	{
		foreach (array(0, 1, 2) as $position)
		{
			post_deletion_fixture(); $ids = array(10, 100, 3); $ids[$position] = $invalid;
			$count = count($mutation_server->connections);
			mutation_expect_failure(function () use ($ids) { phpbb_delete_post_storage($GLOBALS['db'], $ids[0], $ids[1], $ids[2]); }, 'missing post');
			mutation_check(count($mutation_server->connections) === $count, 'Invalid scope never opens a mutation connection');
			post_deletion_preserved('Invalid selection');
		}
	}
	foreach (array('connect', 'lock-query', 'DELETE FROM fixture_posts') as $failure)
	{
		post_deletion_fixture(); $mutation_server->failure = $failure;
		mutation_expect_failure(function () use ($db) { phpbb_delete_post_storage($db, 10, 100, 3); });
		post_deletion_preserved('Failed parent deletion');
	}
	foreach (array(array(12,100,3), array(10,101,3), array(10,100,4)) as $ids)
	{
		post_deletion_fixture();
		mutation_expect_failure(function () use ($db, $ids) { phpbb_delete_post_storage($db, $ids[0], $ids[1], $ids[2]); }, 'missing post');
		post_deletion_preserved('Missing or moved parent');
	}
	post_deletion_fixture(); $interleaved = false;
	$mutation_server->hook = function ($sql) use (&$interleaved)
	{
		if ($interleaved || strpos($sql, 'DELETE FROM fixture_posts') !== 0) { return; }
		$interleaved = true;
		mutation_expect_failure(function () { mutation_publisher('fixture.txt')->do_insert_attachment('last_attachment', 'post', 10); }, 'busy');
	};
	phpbb_delete_post_storage($db, '10', '100', '3');
	mutation_check($interleaved && $mutation_server->owner === null, 'Deletion coordinates with competing publication and releases its session');
	mutation_check($mutation_server->count_rows(POSTS_TABLE) === 1 && $mutation_server->count_rows(POSTS_TEXT_TABLE) === 0, 'Delete only selected parent and text');
	mutation_check($mutation_server->count_rows(ATTACHMENTS_TABLE) === 0 && $mutation_server->count_rows(ATTACHMENTS_DESC_TABLE) === 0 && !is_file($upload_dir . '/fixture.txt'), 'Remove final attachment after successful parent deletion');
	mutation_check((int) $mutation_server->pdo->query('SELECT topic_attachment FROM fixture_topics WHERE topic_id = 100')->fetchColumn() === 0, 'Explicitly refresh surviving topic flag after parent disappears');
	mutation_expect_failure(function () use ($db) { phpbb_delete_post_storage($db, 10, 100, 3); }, 'missing post');
	file_put_contents($upload_dir . '/fixture.txt', 'owned');
	mutation_expect_failure(function () { mutation_publisher('fixture.txt')->do_insert_attachment('last_attachment', 'post', 10); }, 'unavailable');
	mutation_check($mutation_server->count_rows(ATTACHMENTS_TABLE) === 0, 'Late upload cannot publish to deleted parent');

	post_deletion_fixture();
	$mutation_server->pdo->exec('INSERT INTO fixture_links VALUES (1,11,0,8,0)');
	phpbb_delete_post_storage($db, 10, 100, 3);
	mutation_check($mutation_server->count_rows(ATTACHMENTS_TABLE) === 1 && $mutation_server->count_rows(ATTACHMENTS_DESC_TABLE) === 1 && is_file($upload_dir . '/fixture.txt'), 'Shared attachment remains available to surviving post');
	mutation_check((int) $mutation_server->pdo->query('SELECT topic_attachment FROM fixture_topics WHERE topic_id = 100')->fetchColumn() === 1, 'Preserve surviving topic attachment indicator');

	foreach (array('DELETE FROM fixture_post_text', 'DELETE FROM fixture_links') as $failure)
	{
		post_deletion_fixture(); $mutation_server->failure = $failure;
		mutation_expect_failure(function () use ($db) { phpbb_delete_post_storage($db, 10, 100, 3); });
		mutation_check($mutation_server->owner === null && $mutation_server->count_rows(ATTACHMENTS_DESC_TABLE) === 1 && is_file($upload_dir . '/fixture.txt'), 'Partial failure retains recovery bytes and releases lock');
	}
	echo "Post deletion storage and form-ordering checks passed.\n";
}
finally
{
	if (isset($mutation_server) && $mutation_server->owner !== null) { $mutation_server->owner->sql_close(); }
	if (is_file($upload_dir . '/fixture.txt')) { unlink($upload_dir . '/fixture.txt'); }
	if (is_dir($upload_dir)) { rmdir($upload_dir); }
	restore_error_handler();
}
