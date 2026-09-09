<?php
require __DIR__ . '/check-attachment-mutation.php';
require_once __DIR__ . '/pm-repair-journal-fixture.php';
require_once __DIR__ . '/pm-mailbox-journal-fixture.php';
define('PRIVMSGS_TEXT_TABLE', 'fixture_message_text');
define('USERS_TABLE', 'fixture_users');
define('JR_ADMIN_TABLE','fixture_jr_admin'); $phpEx='php'; $phpbb_root_path=$forum_root;
require $forum_root . 'includes/functions_privmsgs.php';
$lang['PM_cleanup_failed'] = 'pm database';
$lang['Not_Authorised'] = 'pm permission';
$pm_fixture_grants='';
function sql_query_nivisec($sql,$error,$fast=true,$return_items=0)
{
	mutation_check(preg_match('/^SELECT \* FROM fixture_jr_admin\s+WHERE user_id = 8$/D',$sql)===1,'Only current fixture admin grants queried');
	return array('user_jr_admin'=>$GLOBALS['pm_fixture_grants']);
}
$upload_dir = sys_get_temp_dir() . '/phpbb-pm-cleanup-' . uniqid('',true);
function pm_cleanup_fixture()
{
	global $mutation_server, $upload_dir;
	$mutation_server = new MutationServer();
	pm_journal_fixture_tables($mutation_server->pdo);
	pm_mailbox_fixture_tables($mutation_server->pdo);
	$GLOBALS['userdata']['user_id']=8; $GLOBALS['userdata']['session_logged_in']=true; $GLOBALS['userdata']['session_id']='fixture-sid';
	$_SERVER['REQUEST_METHOD']='POST'; $_POST=array('sid'=>'fixture-sid');
	$mutation_server->pdo->exec('ALTER TABLE fixture_messages ADD COLUMN privmsgs_date INTEGER DEFAULT 123');
	$mutation_server->pdo->exec('CREATE TABLE fixture_message_text (privmsgs_text_id INTEGER PRIMARY KEY, privmsgs_text TEXT)');
	$mutation_server->pdo->exec("INSERT INTO fixture_message_text VALUES (20,'original'),(21,'sent copy')");
	$mutation_server->pdo->exec('CREATE TABLE fixture_users (user_id INTEGER PRIMARY KEY, user_new_privmsg INTEGER, user_unread_privmsg INTEGER,user_active INTEGER DEFAULT 1,user_level INTEGER DEFAULT 0,user_allow_pm INTEGER DEFAULT 1)');
	$mutation_server->pdo->exec('INSERT INTO fixture_users (user_id,user_new_privmsg,user_unread_privmsg) VALUES(7,9,9),(8,0,0),(99,0,0)');
	$mutation_server->pdo->exec('UPDATE fixture_users SET user_level=1 WHERE user_id=8');
	$mutation_server->pdo->exec('CREATE TABLE fixture_jr_admin (user_id INTEGER,user_jr_admin TEXT)');
	file_put_contents($upload_dir.'/fixture.txt','owned');
	mutation_publisher('fixture.txt')->do_insert_attachment('last_attachment','pm',20);
	mutation_pm()->duplicate_attachment_pm(1,20,21);
}
function pm_repair_expect_failure($callback,$expected){
 $caught='';try{call_user_func($callback);}catch(PhpbbAclException $error){$caught=$error->getMessage();}
 mutation_check($caught===$expected,'Expected current-authority PM repair failure');
}

// Ordinary-controller fixtures impersonate the requested owner explicitly.
// Dedicated capability tests call the real API with mismatched actors instead.
function pm_fixture_owner_call($name,$args,$owner_index) {
 $prior=$GLOBALS['userdata']; $GLOBALS['userdata']['user_id']=(int)$args[$owner_index];
 try { return call_user_func_array($name,$args); }
 finally { $GLOBALS['userdata']=$prior; }
}
function pm_fixture_delete(){return pm_fixture_owner_call('phpbb_pm_delete_messages',func_get_args(),1);}
function pm_fixture_trim(){return pm_fixture_owner_call('phpbb_pm_trim_oldest',func_get_args(),0);}
function pm_fixture_save(){return pm_fixture_owner_call('phpbb_pm_save_messages',func_get_args(),1);}

function pm_scalar($sql) { return (int)$GLOBALS['mutation_server']->pdo->query($sql)->fetchColumn(); }
set_error_handler(function ($severity,$message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } });
try
{
	mutation_check(mkdir($upload_dir,0700),'Owned PM fixture');
	pm_cleanup_fixture();
	foreach (array(array(0),array('20 OR 1=1'),array(20,null),null) as $ids) { mutation_check(pm_fixture_delete($ids,7,'inbox')===0,'Invalid complete ID list rejected'); }
	mutation_check(pm_fixture_delete(array(),7,'inbox')===0,'Empty selection is not delete-all');
	mutation_check(pm_fixture_delete(array(20),99,'inbox')===0 && pm_fixture_delete(array(20),7,'unknown')===0,'Foreign owner and invalid mailbox cannot delete');
	mutation_check(pm_fixture_delete(array(20),7,'inbox')===1,'Selected single PM removed');
	mutation_check(pm_scalar('SELECT COUNT(*) FROM fixture_message_text WHERE privmsgs_text_id=20')===0 && $mutation_server->count_rows(ATTACHMENTS_TABLE)===1 && is_file($upload_dir.'/fixture.txt'),'Single deletion cleans text/reference but retains sent-copy file');
	mutation_check(pm_scalar('SELECT user_new_privmsg FROM fixture_users WHERE user_id=7')===0 && pm_scalar('SELECT user_unread_privmsg FROM fixture_users WHERE user_id=7')===0,'Counters recount actual mailbox instead of subtracting stale values');
	mutation_check(pm_fixture_delete(array(21),8,'sentbox')===1 && !is_file($upload_dir.'/fixture.txt'),'Last sent copy deletes final attachment bytes');

	foreach (array('inbox','outbox','sentbox','savebox') as $mailbox)
	{
		pm_cleanup_fixture();
		if ($mailbox==='savebox') { $mutation_server->pdo->exec('UPDATE fixture_messages SET privmsgs_type=3 WHERE privmsgs_id=20'); }
		$owner=($mailbox==='outbox'||$mailbox==='sentbox')?8:7;
		mutation_check(pm_fixture_delete(array(),$owner,$mailbox,true)===1,'Explicit delete-all respects '.$mailbox.' ownership/type');
		mutation_check($mutation_server->count_rows(PRIVMSGS_TABLE)===1 && $mutation_server->count_rows(ATTACHMENTS_TABLE)===1 && is_file($upload_dir.'/fixture.txt'),'Other mailbox copy survives delete-all');
	}
	foreach (array('inbox','sentbox','savebox') as $mailbox)
	{
		pm_cleanup_fixture();
		if ($mailbox==='savebox') { $mutation_server->pdo->exec('UPDATE fixture_messages SET privmsgs_type=3 WHERE privmsgs_id=20'); }
		$owner=$mailbox==='sentbox'?8:7;
		mutation_check(pm_fixture_trim($owner,$mailbox,0)===0 && pm_fixture_trim($owner,$mailbox,2)===0,'Unlimited/non-full mailbox not trimmed');
		mutation_check(pm_fixture_trim($owner,$mailbox,1)===1,'Full '.$mailbox.' uses complete deletion path');
		mutation_check($mutation_server->count_rows(PRIVMSGS_TEXT_TABLE)===1 && $mutation_server->count_rows(ATTACHMENTS_TABLE)===1 && is_file($upload_dir.'/fixture.txt'),'Automatic trimming preserves shared file and removes only expired copy');
	}
	pm_cleanup_fixture();
	$mutation_server->pdo->exec("INSERT INTO fixture_messages VALUES(22,1,7,8,0,123),(23,1,99,8,0,1)");
	$mutation_server->pdo->exec("INSERT INTO fixture_message_text VALUES(22,'same date'),(23,'foreign')");
	mutation_check(pm_fixture_trim(7,'inbox',2)===1 && pm_scalar('SELECT COUNT(*) FROM fixture_messages WHERE privmsgs_id=20')===0 && pm_scalar('SELECT COUNT(*) FROM fixture_messages WHERE privmsgs_id IN (22,23)')===2,'Trim has deterministic ID tie-break and never crosses owner');

	pm_cleanup_fixture(); $moved=false;
	$mutation_server->hook=function($sql) use (&$moved)
	{
		if (!$moved && strpos($sql,'DELETE FROM fixture_messages')===0) { $moved=true; $GLOBALS['mutation_server']->pdo->exec('UPDATE fixture_messages SET privmsgs_type=3 WHERE privmsgs_id=20'); }
	};
	mutation_check(pm_fixture_delete(array(20),7,'inbox')===0 && $moved,'Moved message no longer matches fresh qualified deletion');
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
	pm_fixture_delete(array(20),7,'inbox');
	mutation_check($competed && !$mutation_server->owner,'PM parent and attachments share the same writer lock');
	$mutation_server->hook=null;
	mutation_expect_failure(function(){mutation_publisher('fixture.txt')->do_insert_attachment('last_attachment','pm',20);},'unavailable');
	mutation_pm()->duplicate_attachment_pm(1,21,20);
	mutation_check($mutation_server->count_rows(ATTACHMENTS_TABLE)===1,'Deleted parent cannot receive stale publication or copied references');
	pm_cleanup_fixture(); $mutation_server->failure='DELETE FROM fixture_messages';
	mutation_expect_failure(function(){pm_fixture_delete(array(20),7,'inbox');},'pm database');
	mutation_check($mutation_server->count_rows(PRIVMSGS_TEXT_TABLE)===2 && $mutation_server->count_rows(ATTACHMENTS_TABLE)===2 && is_file($upload_dir.'/fixture.txt'),'Parent deletion failure preserves text and file references');
	foreach (array('DELETE FROM fixture_message_text','UPDATE fixture_users') as $failed_sql)
	{
		pm_cleanup_fixture(); $mutation_server->failure=$failed_sql;
		mutation_expect_failure(function(){pm_fixture_delete(array(20),7,'inbox');},'pm database');
		mutation_check(pm_scalar('SELECT COUNT(*) FROM fixture_messages WHERE privmsgs_id=20')===0,'Completed parent deletion is not falsely rolled back on nontransactional storage');
		mutation_check($mutation_server->count_rows(PM_DELETE_JOBS_TABLE)===1 && $mutation_server->count_rows(PM_DELETE_ITEMS_TABLE)===1 && is_file($upload_dir.'/fixture.txt') && !$mutation_server->owner,'Later database failure retains durable attachment recovery records and releases lock');
		$mutation_server->failure=''; pm_fixture_delete(array(20),7,'inbox');
		mutation_check($mutation_server->count_rows(PM_DELETE_JOBS_TABLE)===0 && $mutation_server->count_rows(PRIVMSGS_TEXT_TABLE)===1 && $mutation_server->count_rows(ATTACHMENTS_TABLE)===1,'Retry completes cleanup even after the selected parent disappeared');
	}
	$controller=file_get_contents($forum_root.'privmsg.php');
	mutation_check(substr_count($controller,'phpbb_pm_trim_oldest(')===1 && substr_count($controller,'phpbb_pm_finalize_delivery(')===1 && substr_count($controller,'phpbb_pm_delete_messages(')===1 && substr_count($controller,'phpbb_pm_save_messages(')===1 && strpos($controller,'delete_all_pm_attachments(')===false,'All PN controller deletion/save/finalization paths use helper');
	define('GENERAL_MESSAGE',200); define('ADMIN',1);
	$lang['PM_save_limit_exceeded']='save limit';
	pm_cleanup_fixture();
	$mutation_server->pdo->exec('UPDATE fixture_messages SET privmsgs_type=3,privmsgs_to_userid=7 WHERE privmsgs_id=21');
	foreach (array(array(),array(0),array(20,null),array(999)) as $ids) { mutation_check(pm_fixture_save($ids,7,'inbox',1)===0,'Invalid/stale save selection does not evict'); }
	mutation_check(pm_fixture_save(array(20),99,'inbox',1)===0 && pm_fixture_save(array(20),8,'outbox',1)===0,'Foreign mailbox and unsupported save source refused');
	mutation_check($mutation_server->count_rows(PRIVMSGS_TABLE)===2,'No invalid save operation deleted a parent');
	$mutation_server->failure='UPDATE fixture_messages';
	mutation_expect_failure(function(){pm_fixture_save(array(20),7,'inbox',1);},'pm database');
	mutation_check($mutation_server->count_rows(PRIVMSGS_TABLE)===2 && $mutation_server->count_rows(ATTACHMENTS_TABLE)===2,'Failed move does not evict existing archive');
	$mutation_server->failure='';
	mutation_check(pm_fixture_save(array(20),7,'inbox',1)===1,'Inbox message saved');
	mutation_check(pm_scalar('SELECT privmsgs_type FROM fixture_messages WHERE privmsgs_id=20')===3 && pm_scalar('SELECT COUNT(*) FROM fixture_messages WHERE privmsgs_id=21')===0,'New save retained, oldest existing archive evicted');
	mutation_check($mutation_server->count_rows(ATTACHMENTS_TABLE)===1 && is_file($upload_dir.'/fixture.txt') && pm_scalar('SELECT user_new_privmsg FROM fixture_users WHERE user_id=7')===0,'Saved attachment and correct counters preserved');
	mutation_check(pm_fixture_save(array(20),7,'inbox',1)===0 && $mutation_server->count_rows(PRIVMSGS_TABLE)===1,'Repeated stale save cannot erase archive');
	pm_cleanup_fixture();
	mutation_check(pm_fixture_save(array(21),8,'sentbox',0)===1 && pm_scalar('SELECT privmsgs_type FROM fixture_messages WHERE privmsgs_id=21')===4,'Sent copy saves to outgoing archive, unlimited capacity supported');
	mutation_check($mutation_server->count_rows(ATTACHMENTS_TABLE)===2,'Move preserves original and sent attachment links');
	pm_cleanup_fixture();
	$mutation_server->pdo->exec('INSERT INTO fixture_messages VALUES(22,1,7,8,0,1),(23,3,7,8,0,300),(24,3,7,8,0,300)');
	$mutation_server->pdo->exec("INSERT INTO fixture_message_text VALUES(22,'new selection'),(23,'archive'),(24,'archive tie')");
	mutation_expect_failure(function(){pm_fixture_save(array(20,22),7,'inbox',1);},'save limit');
	mutation_check($mutation_server->count_rows(PRIVMSGS_TABLE)===5,'Oversized batch rejected before writes');
	mutation_check(pm_fixture_save(array(20,22),7,'inbox',2)===2 && pm_scalar('SELECT COUNT(*) FROM fixture_messages WHERE privmsgs_id IN (20,22) AND privmsgs_type=3')===2 && pm_scalar('SELECT COUNT(*) FROM fixture_messages WHERE privmsgs_id IN (23,24)')===0,'Multi-save respects capacity and retains selected messages even with older original dates');
	pm_cleanup_fixture(); $moved=false;
	$mutation_server->hook=function($sql) use (&$moved) {
		if (!$moved && strpos($sql,'UPDATE fixture_messages SET privmsgs_type')===0) { $moved=true; $GLOBALS['mutation_server']->pdo->exec('UPDATE fixture_messages SET privmsgs_type=3 WHERE privmsgs_id=20'); }
	};
	mutation_check(pm_fixture_save(array(20),7,'inbox',1)===0 && $moved && $mutation_server->count_rows(PRIVMSGS_TEXT_TABLE)===2,'Zero affected save rows cannot evict or delete text');
	pm_cleanup_fixture();
	mutation_expect_failure(function(){phpbb_pm_delete_user_messages(7);},'pm permission');
	define('IN_ADMIN',true); $userdata['user_level']=0;
	$userdata['session_logged_in']=true; $userdata['session_admin']=true;
	mutation_expect_failure(function(){phpbb_pm_delete_user_messages(7);},'pm permission');
	$userdata['user_level']=ADMIN;
	foreach(array(0,-1,'7 OR 1=1',null) as $invalid_user) { mutation_check(phpbb_pm_delete_user_messages($invalid_user)===0,'Invalid account target refused'); }
	$mutation_server->pdo->exec('INSERT INTO fixture_messages VALUES(22,2,99,99,0,123)');
	$mutation_server->pdo->exec("INSERT INTO fixture_message_text VALUES(22,'unrelated')");
	mutation_pm()->duplicate_attachment_pm(1,20,22);
	mutation_check(phpbb_pm_delete_user_messages(7)===2 && $mutation_server->count_rows(PRIVMSGS_TABLE)===1 && $mutation_server->count_rows(PRIVMSGS_TEXT_TABLE)===1,'Account cleanup removes only existing from/to scope');
	mutation_check($mutation_server->count_rows(ATTACHMENTS_TABLE)===1 && is_file($upload_dir.'/fixture.txt'),'Unrelated shared reference protects physical file during account cleanup');
	mutation_check(phpbb_pm_delete_user_messages(99)===1 && !is_file($upload_dir.'/fixture.txt'),'Last account reference removes final file');
	$admin=file_get_contents($forum_root.'admin/admin_users.php');
	mutation_check(strpos($admin,'phpbb_admin_user_remove($db, $_POST)')!==false && strpos($admin,'phpbb_pm_delete_user_messages($user_id);')===false && strpos($admin,'SELECT privmsgs_id')===false,'Account removal uses the mode-aware durable worker, without legacy pre-delete PM mutation');
	define('DELETED',-1);
	pm_cleanup_fixture(); $userdata['user_level']=0;
	$mutation_server->pdo->exec('UPDATE fixture_users SET user_level=0 WHERE user_id=8');
	pm_repair_expect_failure(function(){phpbb_pm_repair_messages(array(20),'missing_text',1000);},'pm permission');
	$userdata['user_level']=ADMIN;
	$mutation_server->pdo->exec('UPDATE fixture_users SET user_level=1 WHERE user_id=8');
	mutation_check(phpbb_pm_repair_messages(array(20,null),'missing_text',1000)===0 && phpbb_pm_repair_messages(array(20),'unknown',1000)===0,'Invalid maintenance input refused');
	$mutation_server->pdo->exec('DELETE FROM fixture_message_text WHERE privmsgs_text_id=20');
	$mutation_server->pdo->exec('UPDATE fixture_messages SET privmsgs_date=701 WHERE privmsgs_id=20');
	mutation_check(phpbb_pm_repair_messages(array(20),'missing_text',1000)===0,'Recent missing-text parent protected by five-minute grace');
	$mutation_server->pdo->exec('UPDATE fixture_messages SET privmsgs_date=700 WHERE privmsgs_id=20');
	mutation_check(phpbb_pm_repair_messages(array(20),'missing_text',1000)===1 && $mutation_server->count_rows(ATTACHMENTS_TABLE)===1 && is_file($upload_dir.'/fixture.txt'),'Old missing-text PN cleans attachment reference and preserves copy');
	$mutation_server->pdo->exec("INSERT INTO fixture_message_text VALUES(77,'orphan')");
	mutation_check(phpbb_pm_repair_messages(array(21,77),'orphan_text')===1 && pm_scalar('SELECT COUNT(*) FROM fixture_message_text WHERE privmsgs_text_id=21')===1,'Orphan-text deletion rechecks parent absence');
	pm_cleanup_fixture(); $restored=false;
	$mutation_server->pdo->exec('DELETE FROM fixture_message_text WHERE privmsgs_text_id=20');
	$mutation_server->hook=function($sql) use (&$restored) {
		if (!$restored && strpos($sql,'DELETE FROM fixture_messages')===0) { $restored=true; $GLOBALS['mutation_server']->pdo->exec("INSERT INTO fixture_message_text VALUES(20,'restored')"); }
	};
	mutation_check(phpbb_pm_repair_messages(array(20),'missing_text',1000)===0 && $restored && $mutation_server->count_rows(ATTACHMENTS_TABLE)===2,'Text restored after selection protects parent and attachments');
	pm_cleanup_fixture();
	$mutation_server->pdo->exec('UPDATE fixture_messages SET privmsgs_from_userid=999 WHERE privmsgs_id=20');
	mutation_check(phpbb_pm_repair_messages(array(20,21),'invalid_sender')===1 && pm_scalar('SELECT privmsgs_from_userid FROM fixture_messages WHERE privmsgs_id=21')===8,'Only currently invalid sender anonymized');
	mutation_check(phpbb_pm_repair_messages(array(20,21),'deleted_users')===1 && $mutation_server->count_rows(ATTACHMENTS_TABLE)===1,'Deleted-sender policy uses full attachment cleanup');
	mutation_check(phpbb_pm_repair_messages(array(21),'invalid_recipient')===0,'Existing recipient is never anonymized');
	$mutation_server->pdo->exec('UPDATE fixture_messages SET privmsgs_to_userid=999 WHERE privmsgs_id=21');
	mutation_check(phpbb_pm_repair_messages(array(21),'invalid_recipient')===1 && phpbb_pm_repair_messages(array(21),'deleted_users')===0,'Sent copy survives deleted recipient per existing policy');
	$mutation_server->pdo->exec('UPDATE fixture_messages SET privmsgs_from_userid=-1 WHERE privmsgs_id=21');
	mutation_check(phpbb_pm_repair_messages(array(21),'deleted_users')===1 && !is_file($upload_dir.'/fixture.txt'),'Final invalid-user reference cleans file');
	pm_cleanup_fixture(); $restored=false;
	$mutation_server->pdo->exec('UPDATE fixture_messages SET privmsgs_from_userid=999 WHERE privmsgs_id=20');
	$mutation_server->hook=function($sql) use (&$restored) {
		if (!$restored && strpos($sql,'UPDATE fixture_messages')===0) { $restored=true; $GLOBALS['mutation_server']->pdo->exec('INSERT INTO fixture_users (user_id,user_new_privmsg,user_unread_privmsg) VALUES(999,0,0)'); }
	};
	mutation_check(phpbb_pm_repair_messages(array(20),'invalid_sender')===0 && pm_scalar('SELECT privmsgs_from_userid FROM fixture_messages WHERE privmsgs_id=20')===999,'Restored user protected in modifying statement');
	$maintenance=file_get_contents($forum_root.'admin/admin_db_maintenance.php');
	mutation_check(strpos($maintenance,'dbmtnc_repair_pm($db, $_POST)')!==false && strpos($maintenance,'phpbb_pm_repair_messages(')===false,'Controller delegates all repair modes to current-authority service');
	pm_cleanup_fixture(); $userdata['session_logged_in']=false;
	mutation_check(phpbb_pm_prune_user_messages(7)===0,'Logged-out pruning helper refused');
	$userdata['session_logged_in']=true; $userdata['user_level']=0;
	mutation_check(phpbb_pm_prune_user_messages(7)===0,'Nonadmin pruning helper refused');
	$userdata['user_level']=ADMIN;
	foreach(array(0,-1,null,'7 OR 1=1') as $bad) { mutation_check(phpbb_pm_prune_user_messages($bad)===0,'Invalid pruning account refused'); }
	mutation_check(phpbb_pm_prune_user_messages(7)===0 && pm_scalar('SELECT privmsgs_to_userid FROM fixture_messages WHERE privmsgs_id=20')===7,'Existing account cannot be pruned or anonymized');
	$mutation_server->pdo->exec('DELETE FROM fixture_users WHERE user_id=7');
	mutation_check(phpbb_pm_prune_user_messages(7)===1 && pm_scalar('SELECT privmsgs_to_userid FROM fixture_messages WHERE privmsgs_id=21')===-1,'Deleted recipient inbox removed, other sender copy anonymized');
	mutation_check($mutation_server->count_rows(ATTACHMENTS_TABLE)===1 && is_file($upload_dir.'/fixture.txt') && pm_scalar('SELECT COUNT(*) FROM fixture_message_text WHERE privmsgs_text_id=21')===1,'Preserved sender copy keeps its text and shared attachment');
	mutation_check(phpbb_pm_prune_user_messages(7)===0,'Repeated pruning is harmless');
	$mutation_server->pdo->exec('DELETE FROM fixture_users WHERE user_id=8');
	mutation_check(phpbb_pm_prune_user_messages(8)===1 && !is_file($upload_dir.'/fixture.txt'),'Last pruned owner removes final attachment bytes');
	pm_cleanup_fixture();
	$mutation_server->pdo->exec('UPDATE fixture_messages SET privmsgs_to_userid=99,privmsgs_from_userid=99');
	foreach(array('from','to') as $direction) {
		foreach(range(0,5) as $type) {
			$id=100+($direction==='from'?0:10)+$type; $from=$direction==='from'?7:8; $to=$direction==='to'?7:8;
			$mutation_server->pdo->exec('INSERT INTO fixture_messages VALUES('.$id.','.$type.','.$to.','.$from.',0,123)');
			$mutation_server->pdo->exec("INSERT INTO fixture_message_text VALUES(".$id.",'policy fixture')");
		}
	}
	$mutation_server->pdo->exec('DELETE FROM fixture_users WHERE user_id=7');
	mutation_check(phpbb_pm_prune_user_messages(7)===8,'All six message types obey deleted-account mailbox ownership in both directions');
	mutation_check(pm_scalar('SELECT COUNT(*) FROM fixture_messages WHERE privmsgs_id IN (100,103,112,114)')===4 && pm_scalar('SELECT COUNT(*) FROM fixture_message_text')===6,'Delivered/saved copies belonging to other users and unrelated messages survive');
	mutation_check(pm_scalar('SELECT COUNT(*) FROM fixture_messages WHERE privmsgs_from_userid=7 OR privmsgs_to_userid=7')===0,'Surviving copies no longer point at removed account');
	mutation_check(pm_scalar('SELECT user_new_privmsg FROM fixture_users WHERE user_id=8')===0 && pm_scalar('SELECT user_unread_privmsg FROM fixture_users WHERE user_id=8')===0,'Deleted pending outgoing mail recounts recipient counters');
	pm_cleanup_fixture(); $restored=false;
	$mutation_server->pdo->exec('DELETE FROM fixture_users WHERE user_id=7');
	$mutation_server->hook=function($sql) use (&$restored) {
		if (!$restored && strpos($sql,'DELETE FROM fixture_messages')===0) { $restored=true; $GLOBALS['mutation_server']->pdo->exec('INSERT INTO fixture_users (user_id,user_new_privmsg,user_unread_privmsg) VALUES(7,0,0)'); }
	};
	mutation_check(phpbb_pm_prune_user_messages(7)===0 && $restored && pm_scalar('SELECT privmsgs_to_userid FROM fixture_messages WHERE privmsgs_id=21')===7 && $mutation_server->count_rows(ATTACHMENTS_TABLE)===2,'Restored account between selection and deletion protects messages, participant IDs and files');
	pm_cleanup_fixture(); $mutation_server->pdo->exec('DELETE FROM fixture_users WHERE user_id=7'); $mutation_server->failure='DELETE FROM fixture_messages';
	mutation_expect_failure(function(){phpbb_pm_prune_user_messages(7);},'pm database');
	mutation_check(!$mutation_server->owner && $mutation_server->count_rows(ATTACHMENTS_TABLE)===2,'Pruning failure preserves recovery references and releases writer lock');
	$prune_controller=file_get_contents($forum_root.'delete_users.php');
	mutation_check(strpos($prune_controller,'phpbb_prune_user_remove($db, $_POST,')!==false && strpos($prune_controller,'SELECT privmsgs_id')===false && strpos($prune_controller,'SET privmsgs_to_userid')===false,'Standalone prune controller delegates its entire durable account/PN lifecycle');

	pm_cleanup_fixture(); $lock=attach_require_mutation_lock($db);
	try {
		$viewer=array('user_id'=>7,'user_level'=>0,'session_logged_in'=>true);
		foreach(range(0,5) as $type) {
			$mutation_server->pdo->exec('UPDATE fixture_messages SET privmsgs_type='.$type.' WHERE privmsgs_id=20');
			$viewer['user_id']=7;
			mutation_check(phpbb_pm_attachment_access($lock->connection,20,$viewer,true)===in_array($type,array(0,1,3,5),true),'Recipient download requires recipient-owned copy');
			$viewer['user_id']=8;
			mutation_check(phpbb_pm_attachment_access($lock->connection,20,$viewer,true)===in_array($type,array(1,2,4,5),true),'Sender download requires sender-owned copy');
		}
		$viewer['user_id']=99; mutation_check(!phpbb_pm_attachment_access($lock->connection,20,$viewer,true),'Unrelated viewer cannot download private attachment');
		$viewer['user_id']=8; mutation_check(!phpbb_pm_attachment_access($lock->connection,20,$viewer,false),'Disabled PM attachments deny ordinary download');
		$viewer['user_level']=ADMIN; mutation_check(phpbb_pm_attachment_access($lock->connection,20,$viewer,false),'Administrator retains access to an existing parent');
		$viewer['session_logged_in']=false; mutation_check(!phpbb_pm_attachment_access($lock->connection,20,$viewer,true),'Logged-out metadata cannot grant access');
		$viewer['session_logged_in']=true; $viewer['user_id']=-1;
		$mutation_server->pdo->exec('UPDATE fixture_messages SET privmsgs_to_userid=-1,privmsgs_from_userid=-1');
		mutation_check(!phpbb_pm_attachment_access($lock->connection,20,$viewer,true),'Deleted participant sentinel never authorizes guest downloads');
		$viewer['user_id']=8; $mutation_server->pdo->exec('DELETE FROM fixture_messages WHERE privmsgs_id=20');
		mutation_check(!phpbb_pm_attachment_access($lock->connection,20,$viewer,true),'Orphan attachment link cannot grant even admin access without a message');
		foreach(array(null,0,'20 OR 1=1') as $bad) { mutation_check(!phpbb_pm_attachment_access($lock->connection,$bad,$viewer,true),'Malformed PM download target rejected'); }
		$mutation_server->failure='SELECT privmsgs_id FROM fixture_messages';
		mutation_expect_failure(function()use($lock,$viewer){phpbb_pm_attachment_access($lock->connection,21,$viewer,true);},'pm database');
	}
	finally { $lock->release(); }
	$download=file_get_contents($forum_root.'download.php');
	mutation_check(strpos($download,'phpbb_pm_attachment_access($db,')!==false && strpos($download,"\$userdata['user_id'] == \$auth_pages[\$i]['user_id_")===false,'Download uses authoritative parent ownership rather than stale link participants');
	// Real module authorization with only its grant lookup replaced: mutation SQL
	// must still go through the fixture's actual owning connection.
	$phpEx='php';
	require_once $forum_root.'includes/functions_jr_admin.php';
	pm_cleanup_fixture(); $userdata['user_level']=0;
	$mutation_server->pdo->exec('UPDATE fixture_users SET user_level=0 WHERE user_id=8');
	$pm_fixture_grants=md5('UsersManageadmin_users.php');
	pm_repair_expect_failure(function(){phpbb_pm_repair_messages(array(20),'missing_text',1000);},'pm permission');
	mutation_check(!$mutation_server->owner && $mutation_server->count_rows(PRIVMSGS_TABLE)===2,'Wrong module cannot begin maintenance writes');
	mutation_check(phpbb_pm_delete_user_messages(7)===2 && !is_file($upload_dir.'/fixture.txt'),'Delegated user manager performs complete PN cleanup');
	pm_cleanup_fixture(); $pm_fixture_grants=md5('GeneralDB_Maintenanceadmin_db_maintenance.php');
	$mutation_server->pdo->exec('UPDATE fixture_users SET user_level=0 WHERE user_id=8');
	$mutation_server->pdo->exec("INSERT INTO fixture_jr_admin VALUES (8,'".$pm_fixture_grants."')");
	mutation_expect_failure(function(){phpbb_pm_delete_user_messages(7);},'pm permission');
	$mutation_server->pdo->exec("INSERT INTO fixture_message_text VALUES(77,'orphan')");
	mutation_check(phpbb_pm_repair_messages(array(77),'orphan_text')===1,'Delegated maintenance repairs PN data');
	$pm_fixture_grants='';
	$mutation_server->pdo->exec('DELETE FROM fixture_jr_admin');
	pm_repair_expect_failure(function(){phpbb_pm_repair_messages(array(20),'missing_text',1000);},'pm permission');
	$pm_fixture_grants=md5('UsersManageadmin_users.php');
	foreach(array('session_admin','session_logged_in') as $flag)
	{
		$userdata[$flag]=false;
		mutation_expect_failure(function(){phpbb_pm_delete_user_messages(7);},'pm permission');
		$userdata['user_level']=ADMIN;
		mutation_expect_failure(function(){phpbb_pm_delete_user_messages(7);},'pm permission');
		$userdata['user_level']=0; $userdata[$flag]=true;
	}
	mutation_expect_failure(function(){phpbb_pm_require_admin_module('admin_board.php');},'pm permission');
	mutation_check(!$mutation_server->owner && $mutation_server->count_rows(PRIVMSGS_TABLE)===2,'Revoked or unauthenticated capabilities leave messages intact');
	pm_cleanup_fixture();
	mutation_expect_failure(function(){phpbb_pm_delete_inactive_user_messages(7);},'pm permission');
	$pm_fixture_grants=md5('UsersActivate_titleadmin_account.php');
	mutation_check(phpbb_pm_delete_inactive_user_messages(7)===0 && $mutation_server->count_rows(ATTACHMENTS_TABLE)===2,'Inactive module cannot clean an existing account');
	$mutation_server->pdo->exec('DELETE FROM fixture_users WHERE user_id=7');
	mutation_check(phpbb_pm_delete_inactive_user_messages(7)===1 && pm_scalar('SELECT privmsgs_to_userid FROM fixture_messages WHERE privmsgs_id=21')===-1,'Delegated inactive manager removes deleted recipient mailbox and anonymizes other sender copy');
	mutation_check(is_file($upload_dir.'/fixture.txt') && $mutation_server->count_rows(ATTACHMENTS_TABLE)===1,'Inactive deletion preserves the other user attachment copy');
	mutation_check(phpbb_pm_delete_inactive_user_messages(7)===0,'Repeated inactive PN cleanup is harmless');
	mutation_expect_failure(function(){phpbb_pm_delete_user_messages(8);},'pm permission');
	$mutation_server->pdo->exec('UPDATE fixture_users SET user_level=0 WHERE user_id=8');
	pm_repair_expect_failure(function(){phpbb_pm_repair_messages(array(21),'deleted_users');},'pm permission');
	$userdata['session_admin']=false;
	mutation_expect_failure(function(){phpbb_pm_delete_inactive_user_messages(7);},'pm permission');
	$userdata['session_admin']=true; $pm_fixture_grants='';
	mutation_expect_failure(function(){phpbb_pm_delete_inactive_user_messages(7);},'pm permission');
	// Current authority also protects follow-up text/counter/link cleanup and
	// the final reads that precede physical file removal. Parent deletions may
	// already be committed: this asserts stopping, not transaction rollback.
	foreach(array('DELETE FROM fixture_message_text','UPDATE fixture_users','DELETE FROM fixture_links','SELECT attach_id,physical_filename,thumbnail','SELECT attach_id FROM fixture_descriptions WHERE HEX(physical_filename)') as $boundary)
	{
		pm_cleanup_fixture(); $userdata['user_level']=ADMIN;
		$mutation_server->pdo->exec('UPDATE fixture_messages SET privmsgs_from_userid=-1');
		$revoked=false;
		$mutation_server->hook=function($sql) use($boundary,&$revoked)
		{
			if(strpos($sql,$boundary)!==0){return;}
			$revoked=true; $GLOBALS['mutation_server']->hook=null;
			$GLOBALS['mutation_server']->pdo->exec('UPDATE fixture_users SET user_active=0 WHERE user_id=8');
		};
		pm_repair_expect_failure(function(){phpbb_pm_repair_messages(array(20,21),'deleted_users');},'pm permission');
		mutation_check($revoked && !$mutation_server->owner && is_file($upload_dir.'/fixture.txt'),'Revocation stops dependent cleanup/file removal: '.$boundary);
		mutation_check($mutation_server->count_rows(ATTACHMENTS_DESC_TABLE)===1,'Pending file metadata retained for later recovery');
	}
	echo "Private-message cleanup, saving, account removal, maintenance and download checks passed.\n";
}
finally
{
	if ($mutation_server->owner!==null) { $mutation_server->owner->sql_close(); }
	if(is_file($upload_dir.'/fixture.txt')) { unlink($upload_dir.'/fixture.txt'); } rmdir($upload_dir); restore_error_handler();
}
