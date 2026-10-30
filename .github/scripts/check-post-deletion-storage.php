<?php
define('PHPBB_ATTACHMENT_MUTATION_FIXTURE_ONLY',true);
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
	$mutation_server->pdo->exec('INSERT INTO fixture_posts(post_id,topic_id,post_attachment,forum_id) VALUES (11,100,0,3)');
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
	mutation_check(strpos(substr($source, $end), 'phpbb_submit_post_delete($GLOBALS[\'db\'], $mode, $forum_id, $topic_id, $post_id, $poll_id)') !== false, 'Normal delete controller delegates to the transactional storage worker');
    foreach(array('phpbb_delete_post_storage','phpbb_delete_post_storage_owned')as$legacy){
        post_deletion_fixture();$failed=false;try{call_user_func($legacy,$db,10,100,3);}catch(RuntimeException $error){$failed=strpos($error->getMessage(),'phpbb_submit_post_delete')!==false;}
        mutation_check($failed,'Obsolete partial deletion API is disabled');post_deletion_preserved('Disabled legacy entry point');
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
