<?php
// Real deletion and synchronization SQL, isolated in-memory database and files.
define('IN_PHPBB', true);
define('GENERAL_ERROR', 202);
define('PAGE_PRIVMSGS', -10);
define('MODE_THUMBNAIL', 1);
define('ATTACHMENTS_TABLE', 'fixture_links');
define('ATTACHMENTS_DESC_TABLE', 'fixture_descriptions');
define('POSTS_TABLE', 'fixture_posts');
define('TOPICS_TABLE', 'fixture_topics');
define('PRIVMSGS_TABLE', 'fixture_messages');
define('PRIVMSGS_READ_MAIL', 0);
define('PRIVMSGS_NEW_MAIL', 1);
define('PRIVMSGS_SENT_MAIL', 2);
define('PRIVMSGS_SAVED_IN_MAIL', 3);
define('PRIVMSGS_SAVED_OUT_MAIL', 4);
define('PRIVMSGS_UNREAD_MAIL', 5);
$forum_root = dirname(dirname(__DIR__)) . '/phpBB2/';
function delete_selection_check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
class AttachmentSelectionFailure extends RuntimeException {}
function message_die($type, $message, $title = '', $line = 0, $file = '', $sql = '')
{
	throw new AttachmentSelectionFailure($message);
}
set_error_handler(function ($severity, $message) { throw new RuntimeException($message); });
require $forum_root . 'attach_mod/includes/functions_delete.php';
$source = file_get_contents($forum_root . 'attach_mod/includes/functions_attach.php');
$start = strpos($source, 'function attachment_sync_topic(');
$end = strpos($source, 'function get_extension(', $start);
delete_selection_check($start !== false && $end > $start, 'Locate actual topic synchronization');
eval(substr($source, $start, $end - $start));
$lang = array('Error_deleted_attachments' => 'Fixture deletion failed');

class AttachmentSelectionDatabase
{
	var $pdo; var $queries = array(); var $fail_delete = false;
	function __construct()
	{
		$this->pdo = new PDO('sqlite::memory:');
		$this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$this->pdo->exec('CREATE TABLE fixture_links (attach_id INTEGER, post_id INTEGER, privmsgs_id INTEGER)');
		$this->pdo->exec('CREATE TABLE fixture_descriptions (attach_id INTEGER PRIMARY KEY, physical_filename TEXT, thumbnail INTEGER)');
		$this->pdo->exec('CREATE TABLE fixture_posts (post_id INTEGER PRIMARY KEY, topic_id INTEGER, post_attachment INTEGER)');
		$this->pdo->exec('CREATE TABLE fixture_topics (topic_id INTEGER PRIMARY KEY, topic_attachment INTEGER)');
		$this->pdo->exec('CREATE TABLE fixture_messages (privmsgs_id INTEGER PRIMARY KEY, privmsgs_type INTEGER, privmsgs_to_userid INTEGER, privmsgs_from_userid INTEGER, privmsgs_attachment INTEGER)');
		$this->pdo->exec('INSERT INTO fixture_posts VALUES (10, 100, 1), (11, 100, 1)');
		$this->pdo->exec('INSERT INTO fixture_topics VALUES (100, 1)');
		$this->pdo->exec('INSERT INTO fixture_messages VALUES (20, 0, 7, 8, 1), (21, 2, 7, 8, 1)');
	}
	function sql_query($sql)
	{
		$this->queries[] = $sql;
		if ($this->fail_delete && preg_match('/^DELETE FROM fixture_links/', $sql)) { return false; }
		$statement = $this->pdo->query($sql);
		if (!preg_match('/^SELECT/', $sql)) { return true; }
		$result = new stdClass(); $result->rows = $statement->fetchAll(PDO::FETCH_ASSOC); $result->position = 0;
		return $result;
	}
	function sql_numrows($result) { return count($result->rows); }
	function sql_fetchrow($result) { return isset($result->rows[$result->position]) ? $result->rows[$result->position++] : false; }
	function sql_fetchrowset($result) { return $result->rows; }
	function sql_freeresult($result) {}
	function scalar($sql) { return (int) $this->pdo->query($sql)->fetchColumn(); }
}
$fixture_dir = sys_get_temp_dir() . '/phpbb-delete-selection-' . uniqid('', true);
$fixture_files = array(); $unlinked = array();
function unlink_attach($name, $mode = false)
{
	global $fixture_dir, $fixture_files, $unlinked;
	$path = $fixture_dir . '/' . ($mode ? 't_' : '') . $name;
	delete_selection_check(in_array($path, $fixture_files, true), 'Delete only an owned test file');
	$unlinked[] = basename($path);
	return unlink($path);
}
function selection_fixture($links, $descriptions)
{
	global $db, $fixture_dir, $fixture_files, $unlinked;
	$db = new AttachmentSelectionDatabase(); $unlinked = array();
	foreach ($links as $link) { $db->pdo->exec('INSERT INTO fixture_links VALUES (' . implode(',', $link) . ')'); }
	foreach ($descriptions as $id => $thumbnail)
	{
		$name = 'attachment-' . $id . '.dat';
		$db->pdo->exec("INSERT INTO fixture_descriptions VALUES ($id, '$name', $thumbnail)");
		foreach ($thumbnail ? array($name, 't_' . $name) : array($name) as $file)
		{
			$path = $fixture_dir . '/' . $file;
			if (!in_array($path, $fixture_files, true)) { $fixture_files[] = $path; }
			delete_selection_check(file_put_contents($path, 'owned fixture') !== false, 'Create owned test file');
		}
	}
}
try
{
	delete_selection_check(mkdir($fixture_dir, 0700), 'Create isolated attachment directory');
	delete_selection_check(attach_delete_id_array(' 001, 2,1 ') === array(1, 2), 'Normalize legacy comma strings and duplicates');
	delete_selection_check(attach_delete_id_array(array('003', 2, '3')) === array(3, 2), 'Normalize arrays');
	delete_selection_check(attach_delete_id_array(range(1, 1002)) === range(1, 1002), 'Never truncate large maintenance selections');
	delete_selection_check(attach_delete_id_array((string) PHP_INT_MAX) === array(PHP_INT_MAX), 'Accept exact maximum without float rounding');
	$invalid = array(null, true, false, 1.0, new stdClass(), '0', -1, '1e2', '+1', '1,,2', '1,', '1 OR 1=1',
		(string) PHP_INT_MAX . '0', array(1, array(2)), array(1, false), array(1, '0'), array(1, '2x'));
	foreach ($invalid as $selection)
	{
		delete_selection_check(attach_delete_id_array($selection) === false, 'Reject malformed whole selection');
		foreach (array(0, 1) as $argument)
		{
			selection_fixture(array(array(1, 10, 0)), array(1 => 0));
			$failed = false;
			try { delete_attachment($argument ? 10 : $selection, $argument ? $selection : 1); }
			catch (AttachmentSelectionFailure $error) { $failed = true; }
			delete_selection_check($failed && !$db->queries && !$unlinked, 'Invalid input must stop before any SQL or filesystem action');
		}
	}
	foreach (array(array(0, 0, 0), array(0, 0, PAGE_PRIVMSGS), array(array(), 0, 0), array('', 1, 0), array(10, array(), 0)) as $arguments)
	{
		selection_fixture(array(array(1, 10, 0), array(2, 0, 20)), array(1 => 0, 2 => 0));
		call_user_func_array('delete_attachment', $arguments);
		delete_selection_check(!$db->queries && !$unlinked, 'Empty selection and both sentinels are no-ops');
	}
	selection_fixture(array(array(1, 10, 0), array(1, 0, 20), array(2, 0, 21)), array(1 => 1, 2 => 0));
	delete_attachment(0, '1,2');
	delete_selection_check($db->scalar('SELECT COUNT(*) FROM fixture_links WHERE post_id > 0') === 0, 'Remove selected post links');
	delete_selection_check($db->scalar('SELECT COUNT(*) FROM fixture_links WHERE privmsgs_id > 0') === 2 && !$unlinked, 'Post context cannot delete PM links or shared files');
	delete_selection_check($db->scalar('SELECT topic_attachment FROM fixture_topics') === 0, 'Synchronize topic flag');
	delete_attachment(0, 1, PAGE_PRIVMSGS, 8);
	delete_selection_check($db->scalar('SELECT COUNT(*) FROM fixture_links WHERE attach_id = 1') === 1 && !$unlinked, 'Sender cannot delete recipient inbox attachment');
	delete_attachment(0, 1, PAGE_PRIVMSGS, 7);
	delete_selection_check($unlinked === array('attachment-1.dat', 't_attachment-1.dat'), 'Remove main and thumbnail only after last selected reference');
	delete_selection_check($db->scalar('SELECT privmsgs_attachment FROM fixture_messages WHERE privmsgs_id = 20') === 0, 'Synchronize selected PM flag');
	delete_selection_check($db->scalar('SELECT privmsgs_attachment FROM fixture_messages WHERE privmsgs_id = 21') === 1, 'Preserve other PM flag');
	selection_fixture(array(array(3, 0, 20), array(3, 0, 21)), array(3 => 0));
	delete_attachment(0, 3, PAGE_PRIVMSGS, 7);
	delete_selection_check($db->scalar('SELECT COUNT(*) FROM fixture_links') === 1 && !$unlinked, 'Recipient deletion preserves sender copy');
	delete_attachment(0, 3, PAGE_PRIVMSGS, 8);
	delete_selection_check($unlinked === array('attachment-3.dat'), 'Sender deletion cleans up final copy');
	foreach (array(PRIVMSGS_NEW_MAIL, PRIVMSGS_UNREAD_MAIL, PRIVMSGS_SAVED_IN_MAIL, PRIVMSGS_SAVED_OUT_MAIL) as $type)
	{
		selection_fixture(array(array(4, 0, 20)), array(4 => 0));
		$db->pdo->exec('UPDATE fixture_messages SET privmsgs_type = ' . $type . ' WHERE privmsgs_id = 20');
		$owner = $type === PRIVMSGS_SAVED_OUT_MAIL ? 8 : 7;
		delete_attachment(0, 4, PAGE_PRIVMSGS, $owner === 7 ? 8 : 7);
		delete_selection_check(!$unlinked, 'Other party cannot delete saved/new/unread copy');
		delete_attachment(0, 4, PAGE_PRIVMSGS, $owner);
		delete_selection_check($unlinked === array('attachment-4.dat'), 'Correct owner may remove saved/new/unread copy');
	}
	selection_fixture(array(array(5, 10, 0), array(6, 11, 0)), array(5 => 0, 6 => 0, 99 => 0));
	delete_attachment(10, array(5, 6, 99));
	delete_selection_check($unlinked === array('attachment-5.dat'), 'Do not clean up unrelated or already-orphaned requested IDs');
	delete_selection_check($db->scalar('SELECT COUNT(*) FROM fixture_descriptions') === 2, 'Preserve unrelated and orphan metadata');
	delete_selection_check($db->scalar('SELECT topic_attachment FROM fixture_topics') === 1, 'Other post keeps topic flag set');
	delete_attachment('11');
	delete_selection_check($db->scalar('SELECT COUNT(*) FROM fixture_links') === 0, 'Legacy post-only call discovers attachments');
	selection_fixture(array(array(7, 10, 0)), array(7 => 0));
	$db->fail_delete = true; $failed = false;
	try { delete_attachment(10); } catch (AttachmentSelectionFailure $error) { $failed = $error->getMessage() === $lang['Error_deleted_attachments']; }
	delete_selection_check($failed && !$unlinked && $db->scalar('SELECT COUNT(*) FROM fixture_links') === 1, 'SQL failure reports localized error without undefined language or file deletion');
	$admin = file_get_contents($forum_root . 'admin/admin_attach_cp.php');
	delete_selection_check(strpos($admin, 'delete_attachment(0, $delete_id_list, PAGE_PRIVMSGS);') !== false, 'ACP explicitly handles PM context');
	$start = strpos($admin, 'if ($confirm && sizeof($delete_id_list) > 0)');
	$end = strpos($admin, 'else if ($delete', $start);
	delete_selection_check($start !== false && $end > $start, 'Locate actual ACP confirmed deletion branch');
	selection_fixture(array(array(8, 10, 0), array(8, 0, 20), array(9, 0, 21)), array(8 => 0, 9 => 0));
	$confirm = true; $delete_id_list = array(8, 9);
	eval(substr($admin, $start, $end - $start));
	delete_selection_check($db->scalar('SELECT COUNT(*) FROM fixture_links') === 0 && $unlinked === array('attachment-8.dat', 'attachment-9.dat'), 'Actual ACP branch removes selected post and PM files once each');
	foreach (array(null, false, '', array(), array(7, 8), '0', '7oops') as $owner)
	{
		selection_fixture(array(array(8, 0, 20)), array(8 => 0)); $failed = false;
		try { delete_attachment(0, 8, PAGE_PRIVMSGS, $owner); } catch (AttachmentSelectionFailure $error) { $failed = true; }
		delete_selection_check($failed && !$db->queries && !$unlinked, 'Malformed owner must not disable PM ownership filtering');
	}
	echo "Attachment deletion selections, post/PM isolation, ownership and actual SQL/files passed.\n";
}
finally
{
	foreach ($fixture_files as $path) { if (is_file($path)) { unlink($path); } }
	if (is_dir($fixture_dir)) { rmdir($fixture_dir); }
	restore_error_handler();
}
