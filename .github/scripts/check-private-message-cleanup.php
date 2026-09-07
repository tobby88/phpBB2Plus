<?php
require __DIR__ . '/check-attachment-mutation.php';
define('PRIVMSGS_TEXT_TABLE', 'fixture_message_text');
define('USERS_TABLE', 'fixture_users');
require $forum_root . 'includes/functions_privmsgs.php';
$lang['PM_cleanup_failed'] = 'pm database';
$upload_dir = sys_get_temp_dir() . '/phpbb-pm-cleanup-' . uniqid('',true);
function pm_cleanup_fixture()
{
	global $mutation_server, $upload_dir;
	$mutation_server = new MutationServer();
	$mutation_server->pdo->exec('ALTER TABLE fixture_messages ADD COLUMN privmsgs_date INTEGER DEFAULT 123');
	$mutation_server->pdo->exec('CREATE TABLE fixture_message_text (privmsgs_text_id INTEGER PRIMARY KEY, privmsgs_text TEXT)');
	$mutation_server->pdo->exec("INSERT INTO fixture_message_text VALUES (20,'original'),(21,'sent copy')");
	$mutation_server->pdo->exec('CREATE TABLE fixture_users (user_id INTEGER PRIMARY KEY, user_new_privmsg INTEGER, user_unread_privmsg INTEGER)');
	$mutation_server->pdo->exec('INSERT INTO fixture_users VALUES(7,9,9),(8,0,0),(99,0,0)');
	file_put_contents($upload_dir.'/fixture.txt','owned');
	mutation_publisher('fixture.txt')->do_insert_attachment('last_attachment','pm',20);
	mutation_pm()->duplicate_attachment_pm(1,20,21);
}
function pm_scalar($sql) { return (int)$GLOBALS['mutation_server']->pdo->query($sql)->fetchColumn(); }
set_error_handler(function ($severity,$message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } });
try
{
	mutation_check(mkdir($upload_dir,0700),'Owned PM fixture');
	pm_cleanup_fixture();
	foreach (array(array(0),array('20 OR 1=1'),array(20,null),null) as $ids) { mutation_check(phpbb_pm_delete_messages($ids,7,'inbox')===0,'Invalid complete ID list rejected'); }
	mutation_check(phpbb_pm_delete_messages(array(),7,'inbox')===0,'Empty selection is not delete-all');
	mutation_check(phpbb_pm_delete_messages(array(20),99,'inbox')===0 && phpbb_pm_delete_messages(array(20),7,'unknown')===0,'Foreign owner and invalid mailbox cannot delete');
	mutation_check(phpbb_pm_delete_messages(array(20),7,'inbox')===1,'Selected single PM removed');
	mutation_check(pm_scalar('SELECT COUNT(*) FROM fixture_message_text WHERE privmsgs_text_id=20')===0 && $mutation_server->count_rows(ATTACHMENTS_TABLE)===1 && is_file($upload_dir.'/fixture.txt'),'Single deletion cleans text/reference but retains sent-copy file');
	mutation_check(pm_scalar('SELECT user_new_privmsg FROM fixture_users WHERE user_id=7')===0 && pm_scalar('SELECT user_unread_privmsg FROM fixture_users WHERE user_id=7')===0,'Counters recount actual mailbox instead of subtracting stale values');
	mutation_check(phpbb_pm_delete_messages(array(21),8,'sentbox')===1 && !is_file($upload_dir.'/fixture.txt'),'Last sent copy deletes final attachment bytes');

	foreach (array('inbox','outbox','sentbox','savebox') as $mailbox)
	{
		pm_cleanup_fixture();
		if ($mailbox==='savebox') { $mutation_server->pdo->exec('UPDATE fixture_messages SET privmsgs_type=3 WHERE privmsgs_id=20'); }
		$owner=($mailbox==='outbox'||$mailbox==='sentbox')?8:7;
		mutation_check(phpbb_pm_delete_messages(array(),$owner,$mailbox,true)===1,'Explicit delete-all respects '.$mailbox.' ownership/type');
		mutation_check($mutation_server->count_rows(PRIVMSGS_TABLE)===1 && $mutation_server->count_rows(ATTACHMENTS_TABLE)===1 && is_file($upload_dir.'/fixture.txt'),'Other mailbox copy survives delete-all');
	}
	foreach (array('inbox','sentbox','savebox') as $mailbox)
	{
		pm_cleanup_fixture();
		if ($mailbox==='savebox') { $mutation_server->pdo->exec('UPDATE fixture_messages SET privmsgs_type=3 WHERE privmsgs_id=20'); }
		$owner=$mailbox==='sentbox'?8:7;
		mutation_check(phpbb_pm_trim_oldest($owner,$mailbox,0)===0 && phpbb_pm_trim_oldest($owner,$mailbox,2)===0,'Unlimited/non-full mailbox not trimmed');
		mutation_check(phpbb_pm_trim_oldest($owner,$mailbox,1)===1,'Full '.$mailbox.' uses complete deletion path');
		mutation_check($mutation_server->count_rows(PRIVMSGS_TEXT_TABLE)===1 && $mutation_server->count_rows(ATTACHMENTS_TABLE)===1 && is_file($upload_dir.'/fixture.txt'),'Automatic trimming preserves shared file and removes only expired copy');
	}
	pm_cleanup_fixture();
	$mutation_server->pdo->exec("INSERT INTO fixture_messages VALUES(22,1,7,8,0,123),(23,1,99,8,0,1)");
	$mutation_server->pdo->exec("INSERT INTO fixture_message_text VALUES(22,'same date'),(23,'foreign')");
	mutation_check(phpbb_pm_trim_oldest(7,'inbox',2)===1 && pm_scalar('SELECT COUNT(*) FROM fixture_messages WHERE privmsgs_id=20')===0 && pm_scalar('SELECT COUNT(*) FROM fixture_messages WHERE privmsgs_id IN (22,23)')===2,'Trim has deterministic ID tie-break and never crosses owner');

	pm_cleanup_fixture(); $moved=false;
	$mutation_server->hook=function($sql) use (&$moved)
	{
		if (!$moved && strpos($sql,'DELETE FROM fixture_messages')===0) { $moved=true; $GLOBALS['mutation_server']->pdo->exec('UPDATE fixture_messages SET privmsgs_type=3 WHERE privmsgs_id=20'); }
	};
	mutation_check(phpbb_pm_delete_messages(array(20),7,'inbox')===0 && $moved,'Moved message no longer matches fresh qualified deletion');
	mutation_check(pm_scalar('SELECT COUNT(*) FROM fixture_message_text WHERE privmsgs_text_id=20')===1 && $mutation_server->count_rows(ATTACHMENTS_TABLE)===2 && is_file($upload_dir.'/fixture.txt'),'Zero affected parent rows cannot delete text or attachments');
	pm_cleanup_fixture(); $competed=false;
	$mutation_server->hook=function($sql) use (&$competed)
	{
		if (!$competed && strpos($sql,'DELETE FROM fixture_messages')===0)
		{
			$competed=true;
			mutation_expect_failure(function(){mutation_pm()->duplicate_attachment_pm(1,20,21);},'busy');
		}
	};
	phpbb_pm_delete_messages(array(20),7,'inbox');
	mutation_check($competed && !$mutation_server->owner,'PM parent and attachments share the same writer lock');
	$mutation_server->hook=null;
	mutation_expect_failure(function(){mutation_publisher('fixture.txt')->do_insert_attachment('last_attachment','pm',20);},'unavailable');
	mutation_pm()->duplicate_attachment_pm(1,21,20);
	mutation_check($mutation_server->count_rows(ATTACHMENTS_TABLE)===1,'Deleted parent cannot receive stale publication or copied references');
	pm_cleanup_fixture(); $mutation_server->failure='DELETE FROM fixture_messages';
	mutation_expect_failure(function(){phpbb_pm_delete_messages(array(20),7,'inbox');},'pm database');
	mutation_check($mutation_server->count_rows(PRIVMSGS_TEXT_TABLE)===2 && $mutation_server->count_rows(ATTACHMENTS_TABLE)===2 && is_file($upload_dir.'/fixture.txt'),'Parent deletion failure preserves text and file references');
	foreach (array('DELETE FROM fixture_message_text','UPDATE fixture_users') as $failed_sql)
	{
		pm_cleanup_fixture(); $mutation_server->failure=$failed_sql;
		mutation_expect_failure(function(){phpbb_pm_delete_messages(array(20),7,'inbox');},'pm database');
		mutation_check(pm_scalar('SELECT COUNT(*) FROM fixture_messages WHERE privmsgs_id=20')===0,'Completed parent deletion is not falsely rolled back on nontransactional storage');
		mutation_check($mutation_server->count_rows(ATTACHMENTS_TABLE)===2 && is_file($upload_dir.'/fixture.txt') && !$mutation_server->owner,'Later database failure retains attachment recovery records and releases lock');
	}
	$controller=file_get_contents($forum_root.'privmsg.php');
	mutation_check(substr_count($controller,'phpbb_pm_trim_oldest(')===3 && substr_count($controller,'phpbb_pm_delete_messages(')===1 && strpos($controller,'delete_all_pm_attachments(')===false,'All four PN controller deletion paths use helper');
	echo "Private-message selected/all deletion, mailbox trimming, counters and attachment lifecycle checks passed.\n";
}
finally
{
	if ($mutation_server->owner!==null) { $mutation_server->owner->sql_close(); }
	if(is_file($upload_dir.'/fixture.txt')) { unlink($upload_dir.'/fixture.txt'); } rmdir($upload_dir); restore_error_handler();
}
