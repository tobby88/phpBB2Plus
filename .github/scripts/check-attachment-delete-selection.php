<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_ATTACH_DELETE_SELECTION_NATIVE')!=='1'){echo "Attachment selection checks require explicit disposable native storage.\n";return;}
if (!defined('PRIVMSGS_PENDING_SENT_MAIL')) { define('PRIVMSGS_PENDING_SENT_MAIL', 6); }
// Real deletion/synchronization SQL, canonical installer tables and owned files.
define('IN_PHPBB', true);
define('GENERAL_ERROR', 202);
define('PAGE_PRIVMSGS', -10);
define('MODE_THUMBNAIL', 1);
define('THUMB_DIR', '.'); // This fixture keeps its thumbnail files in one owned directory.
$attach_config = array('allow_ftp_upload'=>0);
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
// This suite isolates deletion selection/SQL/files. Dedicated-session locking
// and competing publishers are exercised by check-attachment-mutation.php.
class AttachmentSelectionLock
{
	var $connection;
	function __construct($database) { $this->connection = $database; }
	function release() {}
}
function attach_require_mutation_lock($database) { return new AttachmentSelectionLock($database); }
function message_die($type, $message, $title = '', $line = 0, $file = '', $sql = '')
{
	throw new AttachmentSelectionFailure($message);
}
set_error_handler(function ($severity, $message) { throw new RuntimeException($message); });
require $forum_root . 'attach_mod/includes/functions_delete.php';
$source = file_get_contents($forum_root . 'attach_mod/includes/functions_attach.php');
$start = strpos($source, 'function attach_local_storage_directory(');
$end = strpos($source, '/**' . "\n" . '* Deletes an Attachment', $start);
delete_selection_check($start !== false && $end > $start, 'Locate actual local storage resolver');
eval(substr($source, $start, $end - $start));
$start = strpos($source, 'function attach_ftp_listing_entry(');
$end = strpos($source, '// Return complete regular-file metadata', $start);
delete_selection_check($start !== false && $end > $start, 'Locate actual filename validator');
eval(substr($source, $start, $end - $start));
$start = strpos($source, 'function attachment_sync_topic(');
$end = strpos($source, 'function get_extension(', $start);
delete_selection_check($start !== false && $end > $start, 'Locate actual topic synchronization');
eval(substr($source, $start, $end - $start));
$lang = array('Error_deleted_attachments' => 'Fixture deletion failed', 'Attachment_delete_incomplete' => 'Fixture file cleanup incomplete');

require_once $forum_root.'includes/php_compat.php';require_once $forum_root.'db/mysqli.php';require_once __DIR__.'/uacp-list-native-fixture.php';
class AttachmentSelectionDatabase extends sql_db
{
 var $queries=array();var $fail_delete=false;
 function __construct(){global$native_host,$native_password,$native_schema;parent::__construct($native_host,'root',$native_password,$native_schema,false);delete_selection_check($this->db_connect_id,'Owned native fixture connection');delete_selection_check(parent::sql_query('START TRANSACTION'),'Owned native seed boundary');foreach(array('fixture_links','fixture_descriptions','fixture_posts','fixture_topics','fixture_messages')as$table){delete_selection_check(parent::sql_query('DELETE FROM '.$table),'Reset only owned canonical rows');}foreach(array(10,11)as$id){uacp_list_fixture_insert($this,'fixture_posts',array('post_id'=>$id,'topic_id'=>100,'post_attachment'=>1));}uacp_list_fixture_insert($this,'fixture_topics',array('topic_id'=>100,'topic_attachment'=>1));foreach(array(20=>0,21=>2)as$id=>$type){uacp_list_fixture_insert($this,'fixture_messages',array('privmsgs_id'=>$id,'privmsgs_type'=>$type,'privmsgs_to_userid'=>7,'privmsgs_from_userid'=>8,'privmsgs_attachment'=>1));}}
 function sql_query($sql='',$transaction=false){$this->queries[]=$sql;if($this->fail_delete&&preg_match('/^DELETE FROM fixture_links/',$sql)){return false;}return parent::sql_query($sql,$transaction);}
 function scalar($sql){$r=parent::sql_query($sql);delete_selection_check($r,'Actual native scalar query');try{$row=parent::sql_fetchrow($r);return(int)reset($row);}finally{parent::sql_freeresult($r);}}
}
$native_port=getenv('PHPBB_ATTACH_DELETE_SELECTION_PORT')?:'3306';delete_selection_check(preg_match('/^[0-9]{1,5}$/D',$native_port)&&(int)$native_port>0&&(int)$native_port<=65535,'Owned loopback port');$native_host='127.0.0.1:'.$native_port;$native_password=getenv('PHPBB_ATTACH_DELETE_SELECTION_PASSWORD')?:'';$native_schema='codex_legacy_attach_delete_'.bin2hex(phpbb_random_bytes(8));$native_control=new sql_db($native_host,'root',$native_password,'',false);$native_created=false;$db=null;
$fixture_dir = sys_get_temp_dir() . '/phpbb-delete-selection-' . uniqid('', true);
$upload_dir = $fixture_dir;
$fixture_files = array(); $unlinked = array(); $delete_failures = array(); $listing_failure = false;
function unlink_attach($name, $mode = false, $quiet = false)
{
	global $fixture_dir, $fixture_files, $unlinked, $delete_failures;
	delete_selection_check($quiet === true, 'Caller handles deletion failure after synchronizing flags');
	$path = $fixture_dir . '/' . ($mode ? 't_' : '') . $name;
	delete_selection_check(in_array($path, $fixture_files, true), 'Delete only an owned test file');
	if (isset($delete_failures[basename($path)]))
	{
		if ($delete_failures[basename($path)] === 'throw') { throw new RuntimeException('private storage details'); }
		return false;
	}
	if (!is_file($path)) { return false; }
	$unlinked[] = basename($path);
	return unlink($path);
}
function attach_storage_file_entries($mode = false, $quiet = false)
{
	global $fixture_dir, $fixture_files, $listing_failure;
	delete_selection_check($quiet === true, 'Listing failure must remain distinguishable from an empty directory');
	if ($listing_failure) { return false; }
	$entries = array();
	foreach ($fixture_files as $file)
	{
		$name = basename($file);
		if (is_file($file) && (strpos($name, 't_') === 0) === (bool) $mode)
		{
			$entries[] = array('name' => $name, 'size' => filesize($file));
		}
	}
	return $entries;
}
function selection_fixture($links, $descriptions)
{
	global $db, $fixture_dir, $fixture_files, $unlinked, $delete_failures, $listing_failure;
	if($db){$db->sql_close();}$db = new AttachmentSelectionDatabase(); $unlinked = array(); $delete_failures = array(); $listing_failure = false;
	foreach ($links as $link) { uacp_list_fixture_insert($db,'fixture_links',array('attach_id'=>$link[0],'post_id'=>$link[1],'privmsgs_id'=>$link[2])); }
	foreach ($descriptions as $id => $thumbnail)
	{
		$name = 'attachment-' . $id . '.dat';
		uacp_list_fixture_insert($db,'fixture_descriptions',array('attach_id'=>$id,'physical_filename'=>$name,'real_filename'=>$name,'filesize'=>123,'thumbnail'=>$thumbnail));
		foreach ($thumbnail ? array($name, 't_' . $name) : array($name) as $file)
		{
			$path = $fixture_dir . '/' . $file;
			if (!in_array($path, $fixture_files, true)) { $fixture_files[] = $path; }
			delete_selection_check(file_put_contents($path, 'owned fixture') !== false, 'Create owned test file');
		}
	}
	delete_selection_check($db->sql_query('COMMIT'),'Owned native seed complete');$db->queries=array();
}
try
{
	delete_selection_check($native_control->db_connect_id&&$native_control->sql_query('CREATE DATABASE '.$native_schema.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Create only owned native schema');$native_created=true;
	$native_ddl=file_get_contents($forum_root.'install/schemas/mysql_schema.sql');foreach(array('attachments'=>'fixture_links','attachments_desc'=>'fixture_descriptions','posts'=>'fixture_posts','topics'=>'fixture_topics','privmsgs'=>'fixture_messages')as$name=>$target){delete_selection_check(preg_match('/^CREATE TABLE phpbb_'.$name.'\s*\([\s\S]*?;/m',$native_ddl,$m)===1,'Actual installer definition');delete_selection_check($native_control->sql_query('USE '.$native_schema)&&$native_control->sql_query(str_replace('phpbb_'.$name,$target,$m[0])),'Install canonical native fixture table');}
	if(getenv('PHPBB_ATTACH_DELETE_SELECTION_MYSQL')==='8.4'){$r=$native_control->sql_query('SELECT VERSION() version');$v=$native_control->sql_fetchrow($r);$native_control->sql_freeresult($r);delete_selection_check(strpos($v['version'],'8.4.')===0&&stripos($v['version'],'MariaDB')===false,'Actual MySQL8.4');}
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
	delete_selection_check($unlinked === array('t_attachment-1.dat', 'attachment-1.dat'), 'Remove thumbnail then main only after last selected reference');
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
		delete_selection_check($db->sql_query('UPDATE fixture_messages SET privmsgs_type = ' . $type . ' WHERE privmsgs_id = 20'),'Owned PM type');
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
	// The ACP now uses its own atomic native store. Its full controller, both
	// post/PM contexts, real files and revoked rights are exercised on MariaDB
	// and MySQL by check-attachment-admin-delete-native.php. Keep this legacy
	// helper suite from substituting its reduced schema for that writer.
	delete_selection_check(strpos($admin, 'phpbb_attach_admin_remove($db, $_POST, true)') !== false && strpos($admin, 'delete_attachment(') === false, 'ACP delegates both contexts to the current atomic native writer');
	foreach (array(null, false, '', array(), array(7, 8), '0', '7oops') as $owner)
	{
		selection_fixture(array(array(8, 0, 20)), array(8 => 0)); $failed = false;
		try { delete_attachment(0, 8, PAGE_PRIVMSGS, $owner); } catch (AttachmentSelectionFailure $error) { $failed = true; }
		delete_selection_check($failed && !$db->queries && !$unlinked, 'Malformed owner must not disable PM ownership filtering');
	}
	foreach (array('attachment-12.dat', 't_attachment-12.dat') as $failed_file)
	{
		foreach (array(true, 'throw') as $failure)
		{
			selection_fixture(array(array(12, 10, 0)), array(12 => 1));
			$delete_failures[$failed_file] = $failure; $caught = false;
			try { delete_attachment(10); } catch (AttachmentSelectionFailure $error) { $caught = $error->getMessage() === $lang['Attachment_delete_incomplete']; }
			delete_selection_check($caught && $db->scalar('SELECT COUNT(*) FROM fixture_descriptions') === 1, 'Failed file cleanup preserves description and reports a safe error');
			delete_selection_check(is_file($fixture_dir . '/attachment-12.dat'), 'Main file is retained on main or thumbnail failure');
			delete_selection_check($db->scalar('SELECT thumbnail FROM fixture_descriptions') === ($failed_file === 't_attachment-12.dat' ? 1 : 0), 'Metadata records successful thumbnail cleanup before main-file failure');
			delete_selection_check($db->scalar('SELECT COUNT(*) FROM fixture_links') === 0 && $db->scalar('SELECT post_attachment FROM fixture_posts WHERE post_id = 10') === 0 && $db->scalar('SELECT topic_attachment FROM fixture_topics') === 0, 'Removed links and flags are consistent before file error is reported');
		}
	}
	selection_fixture(array(array(13, 0, 20), array(14, 0, 21)), array(13 => 0, 14 => 0));
	$delete_failures['attachment-13.dat'] = true; $caught = false;
	try { delete_attachment('20,21', 0, PAGE_PRIVMSGS); } catch (AttachmentSelectionFailure $error) { $caught = true; }
	delete_selection_check($caught && $db->scalar('SELECT COUNT(*) FROM fixture_descriptions WHERE attach_id = 13') === 1 && $db->scalar('SELECT COUNT(*) FROM fixture_descriptions WHERE attach_id = 14') === 0, 'Mixed batch retains failed metadata and cleans up successful file');
	delete_selection_check($db->scalar('SELECT SUM(privmsgs_attachment) FROM fixture_messages') === 0, 'All removed PM links synchronize before failure report');
	selection_fixture(array(array(15, 10, 0)), array(15 => 1));
	unlink($fixture_dir . '/t_attachment-15.dat'); unlink($fixture_dir . '/attachment-15.dat');
	delete_attachment(10);
	delete_selection_check($db->scalar('SELECT COUNT(*) FROM fixture_descriptions') === 0 && !$unlinked, 'Already missing files may be cleaned up after a complete inventory');
	selection_fixture(array(array(16, 10, 0)), array(16 => 0));
	unlink($fixture_dir . '/attachment-16.dat'); $listing_failure = true; $caught = false;
	try { delete_attachment(10); } catch (AttachmentSelectionFailure $error) { $caught = true; }
	delete_selection_check($caught && $db->scalar('SELECT COUNT(*) FROM fixture_descriptions') === 1, 'Failed inventory cannot prove absence or discard metadata');
	foreach (array('index.php', '.htpasswd', '../attachment-16.dat', array('nested')) as $name)
	{
		delete_selection_check(!attach_delete_file($name), 'Reserved or invalid names never count as successful cleanup');
	}
	echo "Attachment deletion selections native: canonical five-table ownership, file failures, recovery metadata and actual SQL/files passed.\n";
}
finally
{
	if($db){$db->sql_query('ROLLBACK');$db->sql_close();}if($native_created){delete_selection_check($native_control->sql_query('DROP DATABASE '.$native_schema),'Drop only owned native schema');}$native_control->sql_close();
	foreach ($fixture_files as $path) { if (is_file($path)) { unlink($path); } }
	if (is_dir($fixture_dir)) { rmdir($fixture_dir); }
	restore_error_handler();
}
