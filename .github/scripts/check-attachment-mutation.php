<?php
define('IN_PHPBB', true);
define('GENERAL_ERROR', 202);
define('PAGE_PRIVMSGS', -10);
define('MODE_THUMBNAIL', 1);
define('THUMB_DIR', 'thumbs');
define('ATTACH_DEBUG', 0);
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
function mutation_check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
class MutationFailure extends RuntimeException {}
function message_die($type, $message, $title = '', $line = 0, $file = '', $sql = '') { throw new MutationFailure($message); }
set_error_handler(function ($severity, $message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } });
$forum_root = dirname(dirname(__DIR__)) . '/phpBB2/';
require $forum_root . 'attach_mod/includes/functions_attach.php';
require $forum_root . 'attach_mod/includes/functions_delete.php';
require $forum_root . 'attach_mod/includes/functions_mutation.php';
require $forum_root . 'attach_mod/posting_attachments.php';
require $forum_root . 'attach_mod/pm_attachments.php';

class MutationServer
{
	var $pdo; var $owner = null; var $connections = array(); var $failure = ''; var $hook = null;
	function __construct()
	{
		$this->pdo = new PDO('sqlite::memory:'); $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$this->pdo->exec('CREATE TABLE fixture_links (attach_id INTEGER, post_id INTEGER, privmsgs_id INTEGER, user_id_1 INTEGER, user_id_2 INTEGER)');
		$this->pdo->exec('CREATE TABLE fixture_descriptions (attach_id INTEGER PRIMARY KEY AUTOINCREMENT, physical_filename TEXT, real_filename TEXT, comment TEXT, extension TEXT, mimetype TEXT, filesize INTEGER, filetime INTEGER, thumbnail INTEGER)');
		$this->pdo->exec('CREATE TABLE fixture_posts (post_id INTEGER PRIMARY KEY, topic_id INTEGER, post_attachment INTEGER)');
		$this->pdo->exec('CREATE TABLE fixture_topics (topic_id INTEGER PRIMARY KEY, topic_attachment INTEGER)');
		$this->pdo->exec('CREATE TABLE fixture_messages (privmsgs_id INTEGER PRIMARY KEY, privmsgs_type INTEGER, privmsgs_to_userid INTEGER, privmsgs_from_userid INTEGER, privmsgs_attachment INTEGER)');
		$this->pdo->exec('INSERT INTO fixture_posts VALUES (10,100,1)');
		$this->pdo->exec('INSERT INTO fixture_topics VALUES (100,1)');
		$this->pdo->exec('INSERT INTO fixture_messages VALUES (20,1,7,8,1),(21,2,7,8,0)');
	}
	function count_rows($table) { return (int) $this->pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn(); }
}
class MutationForum
{
	var $server = 'p:fixture'; var $user = 'fixture'; var $password = ''; var $dbname = 'fixture';
	function sql_query($sql) { throw new RuntimeException('Mutation used the unlocked forum connection'); }
	function sql_escape($value) { return str_replace("'", "''", $value); }
}
class sql_db
{
	var $db_connect_id = true; var $state; var $closed = false; var $affected = 0;
	function __construct($server, $user, $password, $name, $persistent)
	{
		mutation_check($server === 'fixture' && $persistent === false, 'Use a dedicated non-persistent session');
		$this->state = $GLOBALS['mutation_server']; $this->state->connections[] = $this;
		if ($this->state->failure === 'connect') { $this->db_connect_id = false; }
	}
	function sql_query($sql)
	{
		if ($this->closed || !$this->db_connect_id) { return false; }
		$s = $this->state;
		if (preg_match("/^SELECT GET_LOCK\('([^']+)', 10\) AS acquired$/D", $sql, $match))
		{
			mutation_check($match[1] === 'attachment:' . md5("fixture\0" . ATTACHMENTS_TABLE) && strlen($match[1]) <= 64, 'Database-scoped bounded lock name');
			if ($s->failure === 'lock-query') { return false; }
			if ($s->failure === 'lock-throw') { throw new RuntimeException('Private database details'); }
			$value = $s->failure === 'lock-null' ? null : ($s->owner === null ? '1' : '0');
			if ($value === '1') { $s->owner = $this; }
			return $this->result(array(array('acquired' => $value)));
		}
		mutation_check($s->owner === $this, 'All attachment SQL must use the owning session');
		if (is_callable($s->hook)) { call_user_func($s->hook, $sql, $this); }
		if ($this->closed || !$this->db_connect_id) { return false; }
		if ($s->failure !== '' && strpos($sql, $s->failure) === 0) { return false; }
		$statement = $s->pdo->query($sql);
		$this->affected = $statement->rowCount();
		return preg_match('/^SELECT/', $sql) ? $this->result($statement->fetchAll(PDO::FETCH_ASSOC)) : true;
	}
	function result($rows) { $result = new stdClass(); $result->rows = $rows; $result->position = 0; return $result; }
	function sql_fetchrow($result) { return isset($result->rows[$result->position]) ? $result->rows[$result->position++] : false; }
	function sql_fetchrowset($result) { return $result->rows; }
	function sql_numrows($result) { return count($result->rows); }
	function sql_freeresult($result) {}
	function sql_nextid() { return (int) $this->state->pdo->lastInsertId(); }
	function sql_affectedrows() { return $this->affected; }
	function sql_escape($value) { return str_replace("'", "''", $value); }
	function sql_close()
	{
		mutation_check(!$this->closed, 'Close each owned connection only once');
		$this->closed = true;
		if ($this->state->owner === $this) { $this->state->owner = null; }
	}
}
$db = new MutationForum();
$lang = array('Attachment_storage_busy' => 'busy', 'Attachment_publish_unavailable' => 'unavailable', 'Error_deleted_attachments' => 'delete failure', 'Attachment_delete_incomplete' => 'incomplete');
$attach_config = array('allow_ftp_upload' => '0');
$upload_dir = sys_get_temp_dir() . '/phpbb-mutation-' . uniqid('', true);
$userdata = array('user_id' => 8); $to_userdata = array('user_id' => 7); $post_info = array('poster_id' => 8);
$privmsg = array('privmsgs_type' => PRIVMSGS_NEW_MAIL); $folder = 'inbox'; $_POST = array();
function mutation_publisher($file, $mode = 'last_attachment', $class = 'attach_parent')
{
	$reflection = new ReflectionClass($class); $parent = $reflection->newInstanceWithoutConstructor();
	$parent->post_attach = true; $parent->attach_filename = $file; $parent->filename = $file;
	$parent->type = 'text/plain'; $parent->extension = 'txt'; $parent->filesize = 5;
	if ($mode === 'attach_list')
	{
		$parent->attachment_list = array($file); $parent->attachment_id_list = array(0);
		$parent->attachment_filename_list = array($file); $parent->attachment_comment_list = array('Grüße');
		$parent->attachment_extension_list = array('txt'); $parent->attachment_mimetype_list = array('text/plain');
		$parent->attachment_filesize_list = array(5); $parent->attachment_filetime_list = array(123); $parent->attachment_thumbnail_list = array(0);
	}
	return $parent;
}
function mutation_pm() { $reflection = new ReflectionClass('attach_pm'); return $reflection->newInstanceWithoutConstructor(); }
function mutation_expect_failure($callback, $expected = null)
{
	$caught = false;
	try { call_user_func($callback); } catch (MutationFailure $error) { $caught = $expected === null || $error->getMessage() === $expected; }
	mutation_check($caught, 'Expected controlled mutation failure');
}
try
{
	mutation_check(mkdir($upload_dir, 0700), 'Create owned file fixture');
	foreach (array('last_attachment', 'attach_list') as $mode)
	{
		$mutation_server = new MutationServer(); file_put_contents($upload_dir . '/fixture.txt', 'owned');
		$publisher = mutation_publisher('fixture.txt', $mode);
		$publisher->do_insert_attachment($mode, 'pm', 20);
		mutation_check($mutation_server->count_rows(ATTACHMENTS_TABLE) === 1 && $mutation_server->owner === null, 'Publish once and release lock');
		mutation_expect_failure(function () use ($publisher, $mode) { $publisher->do_insert_attachment($mode, 'post', 10); }, 'unavailable');
		mutation_check($mutation_server->count_rows(ATTACHMENTS_TABLE) === 1, 'Stale submit cannot add another physical-file registration');
		mutation_pm()->duplicate_attachment_pm(1, 20, 21);
		mutation_check($mutation_server->count_rows(ATTACHMENTS_TABLE) === 2, 'Sent copy preserves a valid shared attachment');
		delete_attachment(20, 0, PAGE_PRIVMSGS);
		mutation_check(is_file($upload_dir . '/fixture.txt') && $mutation_server->count_rows(ATTACHMENTS_TABLE) === 1, 'Deleting first copy preserves file');
		delete_attachment(21, 0, PAGE_PRIVMSGS);
		mutation_check(!is_file($upload_dir . '/fixture.txt') && $mutation_server->count_rows(ATTACHMENTS_DESC_TABLE) === 0, 'Deleting last copy cleans file under same lock');
		mutation_pm()->duplicate_attachment_pm(1, 20, 21);
		mutation_check($mutation_server->count_rows(ATTACHMENTS_TABLE) === 0, 'Later copy cannot revive removed attachment snapshot');
		mutation_expect_failure(function () use ($publisher, $mode) { $publisher->do_insert_attachment($mode, 'post', 10); }, 'unavailable');
	}
	foreach (array('connect', 'lock-query', 'lock-null', 'lock-throw') as $failure)
	{
		$mutation_server = new MutationServer(); $mutation_server->failure = $failure;
		mutation_expect_failure(function () { mutation_publisher('fixture.txt')->do_insert_attachment('last_attachment', 'post', 10); }, 'busy');
		mutation_check($mutation_server->count_rows(ATTACHMENTS_DESC_TABLE) === 0 && $mutation_server->owner === null, 'Lock failure has no writes or leaked owner');
	}
	$mutation_server = new MutationServer(); file_put_contents($upload_dir . '/fixture.txt', 'owned');
	mutation_publisher('fixture.txt')->do_insert_attachment('last_attachment', 'pm', 20);
	$interleaved = false;
	$mutation_server->hook = function ($sql) use (&$interleaved)
	{
		if ($interleaved || strpos($sql, 'DELETE FROM ' . ATTACHMENTS_TABLE) !== 0) { return; }
		$interleaved = true;
		mutation_expect_failure(function () { mutation_pm()->duplicate_attachment_pm(1, 20, 21); }, 'busy');
		mutation_expect_failure(function () { mutation_publisher('fixture.txt')->do_insert_attachment('last_attachment', 'post', 10); }, 'busy');
	};
	delete_attachment(20, 0, PAGE_PRIVMSGS);
	mutation_check($interleaved && !$mutation_server->owner && $mutation_server->count_rows(ATTACHMENTS_TABLE) === 0, 'Competing copy and publish cannot interleave with deletion');
	$mutation_server = new MutationServer(); file_put_contents($upload_dir . '/fixture.txt', 'owned');
	mutation_publisher('fixture.txt')->do_insert_attachment('last_attachment', 'pm', 20);
	$mutation_server->hook = function ($sql, $connection)
	{
		if (strpos($sql, 'INSERT INTO ' . ATTACHMENTS_TABLE) === 0) { $connection->sql_close(); }
	};
	mutation_expect_failure(function () { mutation_pm()->duplicate_attachment_pm(1, 20, 21); });
	mutation_check($mutation_server->count_rows(ATTACHMENTS_TABLE) === 1 && !$mutation_server->owner, 'Lost owning connection cannot publish through unlocked fallback');
	$mutation_server->hook = null;
	$mutation_server->failure = 'INSERT INTO ' . ATTACHMENTS_TABLE;
	$mutation_server->pdo->exec('DELETE FROM fixture_links'); $mutation_server->pdo->exec('DELETE FROM fixture_descriptions');
	mutation_expect_failure(function () { mutation_publisher('fixture.txt')->do_insert_attachment('last_attachment', 'post', 10); });
	mutation_check($mutation_server->count_rows(ATTACHMENTS_DESC_TABLE) === 1 && is_file($upload_dir . '/fixture.txt') && !$mutation_server->owner, 'Failed link publication retains description and physical file for recovery');
	$mutation_server->failure = '';
	mutation_expect_failure(function () { mutation_publisher('fixture.txt')->do_insert_attachment('last_attachment', 'post', 10); }, 'unavailable');
	$mutation_server = new MutationServer(); $publisher = mutation_publisher('fixture.txt');
	foreach (array(0, -1, '0', null, '1,2', array(1, 2)) as $id) { mutation_check($publisher->do_insert_attachment('last_attachment', 'post', $id) === false, 'Reject invalid destination IDs'); }
	mutation_check($publisher->do_insert_attachment('last_attachment', 'invalid', 10) === false && !$mutation_server->connections, 'Invalid type cannot fall through to post insertion');
	foreach (array('german', 'english') as $language)
	{
		$strings = file_get_contents($forum_root . 'language/lang_' . $language . '/lang_main_attach.php');
		mutation_check(strpos($strings, "['Attachment_storage_busy']") !== false && strpos($strings, "['Attachment_publish_unavailable']") !== false, 'Both languages provide mutation errors');
	}
	foreach (array('post', 'pm') as $kind)
	{
		$mutation_server = new MutationServer(); file_put_contents($upload_dir . '/fixture.txt', 'owned');
		$is_auth = array('auth_attachments' => true); $attach_config['allow_pm_attach'] = 1;
		$mode = 'reply';
		$publisher = mutation_publisher('fixture.txt', 'last_attachment', $kind === 'pm' ? 'attach_pm' : 'attach_posting');
		$interleaved = false;
		$mutation_server->hook = function ($sql) use (&$interleaved, $kind)
		{
			if ($interleaved || strpos($sql, 'UPDATE ') !== 0) { return; }
			$interleaved = true;
			mutation_expect_failure(function () use ($kind) { delete_attachment($kind === 'pm' ? 20 : 10, 0, $kind === 'pm' ? PAGE_PRIVMSGS : 0); }, 'busy');
		};
		if ($kind === 'pm') { $publisher->insert_attachment_pm(20); } else { $publisher->insert_attachment(10); }
		mutation_check($interleaved && !$mutation_server->owner, 'Outer writer synchronizes flags on the owning session');
		$mutation_server->hook = null;
		delete_attachment($kind === 'pm' ? 20 : 10, 0, $kind === 'pm' ? PAGE_PRIVMSGS : 0);
		$flag = $kind === 'pm' ? 'SELECT privmsgs_attachment FROM fixture_messages WHERE privmsgs_id = 20' : 'SELECT topic_attachment FROM fixture_topics WHERE topic_id = 100';
		mutation_check((int) $mutation_server->pdo->query($flag)->fetchColumn() === 0, 'Later deletion clears the published flag');
	}
	$mutation_server = new MutationServer(); file_put_contents($upload_dir . '/fixture.txt', 'owned');
	mutation_publisher('fixture.txt')->do_insert_attachment('last_attachment', 'pm', 20);
	$mutation_server->pdo->exec("INSERT INTO fixture_descriptions (physical_filename,thumbnail) VALUES ('fixture.txt',0)");
	$legacy_id = (int) $mutation_server->pdo->lastInsertId();
	$mutation_server->pdo->exec('INSERT INTO fixture_links VALUES (' . $legacy_id . ',10,0,8,0)');
	delete_attachment(20, 0, PAGE_PRIVMSGS);
	mutation_check(is_file($upload_dir . '/fixture.txt') && $mutation_server->count_rows(ATTACHMENTS_DESC_TABLE) === 1, 'Legacy duplicate physical names preserve other attachment bytes');
	delete_attachment(10);
	mutation_check(!is_file($upload_dir . '/fixture.txt') && $mutation_server->count_rows(ATTACHMENTS_DESC_TABLE) === 0, 'Last legacy registration removes the file');
	echo "Attachment publication, deletion and PM-copy coordination checks passed.\n";
}
finally
{
	if (isset($mutation_server) && $mutation_server->owner !== null) { $mutation_server->owner->sql_close(); }
	if (is_file($upload_dir . '/fixture.txt')) { unlink($upload_dir . '/fixture.txt'); }
	if (is_dir($upload_dir)) { rmdir($upload_dir); }
	restore_error_handler();
}
