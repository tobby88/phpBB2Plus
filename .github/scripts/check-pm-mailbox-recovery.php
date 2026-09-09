<?php
require __DIR__ . '/check-private-message-cleanup.php';
mutation_check(!is_dir($upload_dir) && mkdir($upload_dir,0700),'Owned mailbox recovery fixture');
function mailbox_fixture($single = true)
{
	global $userdata;
	pm_cleanup_fixture();
	if ($single) { pm_fixture_delete(array(20),7,'inbox'); }
	$userdata['user_id']=8; $userdata['user_level']=0;
}
function mailbox_fail($callback, $expected)
{
	$caught='';
	try {call_user_func($callback);}
	catch (MutationFailure $error) {$caught=$error->getMessage();}
	catch (PhpbbAclException $error) {$caught=$error->getMessage();}
	mutation_check($caught===$expected,'Expected mailbox failure: '.$caught.' / '.$expected);
	mutation_check(!$GLOBALS['mutation_server']->owner,'Failed mailbox operation releases owner');
}
function mailbox_finished()
{
	global $mutation_server,$upload_dir;
	foreach(array(PRIVMSGS_TABLE,PRIVMSGS_TEXT_TABLE,ATTACHMENTS_TABLE,ATTACHMENTS_DESC_TABLE,PM_DELETE_JOBS_TABLE,PM_DELETE_ITEMS_TABLE) as $table)
	{
		mutation_check($mutation_server->count_rows($table)===0,'Completed last-copy cleanup '.$table);
	}
	mutation_check(!is_file($upload_dir.'/fixture.txt'),'Last unshared file removed');
	mutation_check(phpbb_pm_recover_mailbox(8,'sentbox')===0,'Repeated GET recovery is empty');
}
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try
{
	foreach(array('english','german') as $locale)
	{
		include $forum_root.'language/lang_'.$locale.'/lang_main.php';
		foreach(array('INSERT INTO fixture_pm_delete_jobs','INSERT INTO fixture_pm_delete_items','UPDATE fixture_pm_delete_jobs SET job_state',
			'DELETE FROM fixture_messages WHERE','DELETE FROM fixture_message_text','DELETE FROM fixture_links','UPDATE fixture_users SET',
			'SELECT attach_id,physical_filename,thumbnail','DELETE FROM fixture_descriptions','DELETE FROM fixture_pm_delete_items','DELETE FROM fixture_pm_delete_jobs') as $boundary)
		{
			foreach(array(false,true) as $lost_ack)
			{
				mailbox_fixture();
				if($lost_ack)
				{
					if(strpos($boundary,'SELECT')===0){continue;}
					$mutation_server->hook=function($sql) use($boundary)
					{
						if(strpos($sql,$boundary)!==0){return;}
						$s=$GLOBALS['mutation_server'];$s->hook=null;$s->pdo->exec($sql);$s->failure=$boundary;
					};
				}
				else {$mutation_server->failure=$boundary;}
				mailbox_fail(function(){phpbb_pm_delete_messages(array(21),8,'sentbox');},$lang['PM_cleanup_failed']);
				$mutation_server->failure='';$mutation_server->hook=null;
				$surviving=$mutation_server->count_rows(PRIVMSGS_TABLE);
				$_SERVER['REQUEST_METHOD']='GET';$_POST=array();
				phpbb_pm_recover_mailbox(8,'sentbox');
				mutation_check($mutation_server->count_rows(PRIVMSGS_TABLE)===$surviving,'GET never executes an old parent-deletion intent');
				if($surviving)
				{
					mutation_check(is_file($upload_dir.'/fixture.txt') && $mutation_server->count_rows(PRIVMSGS_TEXT_TABLE)===1,'Cancelled old intent preserves source');
					$_SERVER['REQUEST_METHOD']='POST';$_POST=array('sid'=>'fixture-sid');
					phpbb_pm_delete_messages(array(21),8,'sentbox');
				}
				mailbox_finished();
			}
		}
		// Confirmed missing parent is necessary in dependent writes and before
		// file access, even if an unrelated writer restores the same message ID.
		mailbox_fixture();$restored=false;
		$mutation_server->hook=function($sql) use (&$restored)
		{
			if(strpos($sql,'DELETE FROM fixture_message_text')!==0){return;}
			$s=$GLOBALS['mutation_server'];$s->hook=null;$restored=true;
			$s->pdo->exec('INSERT INTO fixture_messages VALUES(21,2,7,8,1,999)');
			$s->pdo->exec("UPDATE fixture_message_text SET privmsgs_text='restored Grüße' WHERE privmsgs_text_id=21");
		};
		mailbox_fail(function(){phpbb_pm_delete_messages(array(21),8,'sentbox');},$lang['PM_journal_changed']);
		mutation_check($restored && $mutation_server->count_rows(PRIVMSGS_TEXT_TABLE)===1 && $mutation_server->count_rows(ATTACHMENTS_TABLE)===1 && is_file($upload_dir.'/fixture.txt'),'Restored parent retains text, links and bytes');
		phpbb_pm_recover_mailbox(8,'sentbox');
		mutation_check($mutation_server->count_rows(PM_DELETE_JOBS_TABLE)===0 && $mutation_server->count_rows(PRIVMSGS_TABLE)===1,'Restored parent cancels old metadata only');
		foreach(array('foreign','guest','inactive','missing','GET','bad-sid','actor-changed','owner-changed') as $case)
		{
			mailbox_fixture();$expected=$lang['Not_Authorised'];
			if($case==='foreign'){$userdata['user_id']=7;}
			elseif($case==='guest'){$userdata['session_logged_in']=false;}
			elseif($case==='inactive'){$mutation_server->pdo->exec('UPDATE fixture_users SET user_active=0 WHERE user_id=8');}
			elseif($case==='missing'){$mutation_server->pdo->exec('DELETE FROM fixture_users WHERE user_id=8');}
			elseif($case==='GET'){$_SERVER['REQUEST_METHOD']='GET';$expected=$lang['Session_invalid'];}
			elseif($case==='bad-sid'){$_POST['sid']='wrong';$expected=$lang['Session_invalid'];}
			else
			{
				$mutation_server->hook=function($sql,$connection) use($case)
				{
					if(strpos($sql,'DELETE FROM fixture_messages WHERE')!==0){return;}
					$GLOBALS['mutation_server']->hook=null;
					if($case==='actor-changed'){$GLOBALS['mutation_server']->pdo->exec('UPDATE fixture_users SET user_active=0 WHERE user_id=8');}
					else {$connection->sql_close();}
				};
				if($case==='owner-changed'){$expected=$lang['PM_cleanup_failed'];}
			}
			mailbox_fail(function(){phpbb_pm_delete_messages(array(21),8,'sentbox');},$expected);
			mutation_check($mutation_server->count_rows(PRIVMSGS_TABLE)===1 && is_file($upload_dir.'/fixture.txt'),'Current capability protects source: '.$case);
		}
		// A pending owner journal is not an authority to inspect another mailbox.
		mailbox_fixture();$mutation_server->failure='DELETE FROM fixture_message_text';
		mailbox_fail(function(){phpbb_pm_delete_messages(array(21),8,'sentbox');},$lang['PM_cleanup_failed']);
		$mutation_server->failure='';$userdata['user_id']=7;
		mailbox_fail(function(){phpbb_pm_recover_mailbox(8,'sentbox');},$lang['Not_Authorised']);
		mutation_check(phpbb_pm_recover_mailbox(7,'sentbox')===0 && $mutation_server->count_rows(PM_DELETE_JOBS_TABLE)===1,'Other mailbox recovery leaves pending job intact');
		$userdata['user_id']=8;phpbb_pm_recover_mailbox(8,'sentbox');mailbox_finished();
		// Real foreign-mailbox quota paths, with current source/send capability.
		mailbox_fixture(false);$userdata['user_id']=7;
		$mutation_server->pdo->exec('UPDATE fixture_messages SET privmsgs_type=0 WHERE privmsgs_id=20');
		mutation_check(phpbb_pm_trim_oldest(8,'sentbox',1,'read',20)===1,'Recipient read may trim sender sentbox');
		mutation_check($mutation_server->count_rows(PRIVMSGS_TABLE)===1 && is_file($upload_dir.'/fixture.txt'),'Read source and shared attachment preserved');
		mailbox_fixture(false);$userdata['user_id']=99;
		mailbox_fail(function(){phpbb_pm_trim_oldest(8,'sentbox',1,'read',20);},$lang['Not_Authorised']);
		mailbox_fixture(false);
		mutation_check(phpbb_pm_trim_oldest(7,'inbox',1,'send')===1,'Sender may enforce delivery recipient quota');
		mailbox_fixture(false);$mutation_server->pdo->exec('UPDATE fixture_users SET user_allow_pm=0 WHERE user_id=8');
		mailbox_fail(function(){phpbb_pm_trim_oldest(7,'inbox',1,'send');},$lang['Not_Authorised']);
		mailbox_fixture(false);$_SERVER['REQUEST_METHOD']='GET';
		mailbox_fail(function(){phpbb_pm_trim_oldest(7,'inbox',1,'send');},$lang['Session_invalid']);
		mailbox_fixture();$_SERVER['REQUEST_METHOD']='GET';
		mailbox_fail(function(){phpbb_pm_trim_oldest(8,'sentbox',1,'recover');},$lang['Not_Authorised']);
		mutation_check($mutation_server->count_rows(PRIVMSGS_TABLE)===1,'Recovery capability cannot create new quota deletion intents');
		// Both participants may remove the same NEW/UNREAD parent. An old
		// pre-delete journal of one must not permanently block the other.
		mailbox_fixture(false);$mutation_server->failure='DELETE FROM fixture_messages WHERE';
		$userdata['user_id']=7;
		mailbox_fail(function(){phpbb_pm_delete_messages(array(20),7,'inbox');},$lang['PM_cleanup_failed']);
		$mutation_server->failure='';$userdata['user_id']=8;
		mutation_check(phpbb_pm_delete_messages(array(20),8,'outbox')===1 && $mutation_server->count_rows(PM_DELETE_JOBS_TABLE)===0,'Authorized other participant supersedes metadata of an undeleted shared parent');
		mutation_check($mutation_server->count_rows(PRIVMSGS_TABLE)===1 && is_file($upload_dir.'/fixture.txt'),'Independent sent copy still survives');
		mailbox_fixture();$mutation_server->pdo->exec('UPDATE fixture_descriptions SET thumbnail=1');
		mailbox_fail(function(){phpbb_pm_delete_messages(array(21),8,'sentbox');},$lang['PM_cleanup_failed']);
		mutation_check(is_file($upload_dir.'/fixture.txt') && $mutation_server->count_rows(PM_DELETE_ITEMS_TABLE)===1,'Unavailable thumbnail storage retains main bytes and inventory');
		mutation_check(mkdir($upload_dir.'/thumbs',0700),'Owned thumbnail storage');
		file_put_contents($upload_dir.'/thumbs/t_fixture.txt','owned thumbnail');
		phpbb_pm_recover_mailbox(8,'sentbox');mailbox_finished();
		mutation_check(!is_file($upload_dir.'/thumbs/t_fixture.txt'),'Recovered thumbnail deleted');rmdir($upload_dir.'/thumbs');
		foreach(array('actor','job','item') as $changed)
		{
			mailbox_fixture();
			$mutation_server->hook=function($sql) use($changed)
			{
				if(strpos($sql,'SELECT attach_id,physical_filename,thumbnail')!==0){return;}
				$s=$GLOBALS['mutation_server'];$s->hook=null;
				if($changed==='actor'){$s->pdo->exec('UPDATE fixture_users SET user_active=0 WHERE user_id=8');}
				elseif($changed==='job'){$s->pdo->exec('UPDATE fixture_pm_delete_jobs SET created_by=99');}
				else {$s->pdo->exec('DELETE FROM fixture_pm_delete_items');}
			};
			mailbox_fail(function(){phpbb_pm_delete_messages(array(21),8,'sentbox');},$lang[$changed==='actor'?'Not_Authorised':'PM_journal_changed']);
			mutation_check(is_file($upload_dir.'/fixture.txt') && $mutation_server->count_rows(ATTACHMENTS_DESC_TABLE)===1,'Fresh file guard protects changed '.$changed);
		}
		mailbox_fixture();$mutation_server->failure='SELECT attach_id,physical_filename,thumbnail';
		mailbox_fail(function(){phpbb_pm_delete_messages(array(21),8,'sentbox');},$lang['PM_cleanup_failed']);
		$mutation_server->failure='';$mutation_server->pdo->exec("UPDATE fixture_descriptions SET physical_filename='replacement.txt'");
		file_put_contents($upload_dir.'/replacement.txt','owned replacement');
		mailbox_fail(function(){phpbb_pm_recover_mailbox(8,'sentbox');},$lang['PM_journal_changed']);
		mutation_check(is_file($upload_dir.'/fixture.txt') && is_file($upload_dir.'/replacement.txt') && $mutation_server->count_rows(ATTACHMENTS_DESC_TABLE)===1,'Changed registration and unregistered old file preserved for review');
		unlink($upload_dir.'/replacement.txt');
		mailbox_fixture();$mutation_server->pdo->exec('UPDATE fixture_links SET post_id=10 WHERE privmsgs_id=21');
		phpbb_pm_delete_messages(array(21),8,'sentbox');
		mutation_check(pm_scalar('SELECT COUNT(*) FROM fixture_links WHERE post_id=10 AND privmsgs_id=0')===1 && is_file($upload_dir.'/fixture.txt'),'Dual-purpose reference keeps its post half and file');
		mailbox_fixture();$mutation_server->pdo->exec("INSERT INTO fixture_descriptions (attach_id,physical_filename,thumbnail) VALUES(99,'fixture.txt',0)");
		phpbb_pm_delete_messages(array(21),8,'sentbox');
		mutation_check(pm_scalar('SELECT COUNT(*) FROM fixture_descriptions WHERE attach_id=99')===1 && is_file($upload_dir.'/fixture.txt'),'Another descriptor with same filename protects shared bytes');
		// Savebox recovery does not evict the newly saved selection again.
		mailbox_fixture(false);$userdata['user_id']=7;
		$mutation_server->pdo->exec('UPDATE fixture_messages SET privmsgs_type=3 WHERE privmsgs_id=21');
		$mutation_server->failure='DELETE FROM fixture_message_text';
		mailbox_fail(function(){phpbb_pm_save_messages(array(20),7,'inbox',1);},$lang['PM_cleanup_failed']);
		$mutation_server->failure='';phpbb_pm_recover_mailbox(7,'savebox');
		mutation_check(pm_scalar('SELECT COUNT(*) FROM fixture_messages WHERE privmsgs_id=20 AND privmsgs_type=3')===1 && $mutation_server->count_rows(PRIVMSGS_TABLE)===1 && is_file($upload_dir.'/fixture.txt'),'New saved message survives interrupted archive eviction');
		mutation_check(phpbb_pm_save_messages(array(20),7,'inbox',1)===0,'Stale repeated save cannot evict another message');
		// Recipient-triggered quota cleanup can continue under the sender's
		// own recovery capability after its source-read context is gone.
		mailbox_fixture(false);$userdata['user_id']=7;
		$mutation_server->pdo->exec('UPDATE fixture_messages SET privmsgs_type=0 WHERE privmsgs_id=20');
		$mutation_server->failure='DELETE FROM fixture_message_text';
		mailbox_fail(function(){phpbb_pm_trim_oldest(8,'sentbox',1,'read',20);},$lang['PM_cleanup_failed']);
		$mutation_server->failure='';$userdata['user_id']=8;
		phpbb_pm_recover_mailbox(8,'sentbox');
		mutation_check($mutation_server->count_rows(PM_DELETE_JOBS_TABLE)===0 && pm_scalar('SELECT COUNT(*) FROM fixture_messages WHERE privmsgs_id=20')===1,'Mailbox owner can finish previously authorized foreign-actor quota cleanup');
		mailbox_fixture();$delivered=false;
		$mutation_server->hook=function($sql) use(&$delivered)
		{
			if(strpos($sql,'DELETE FROM fixture_messages WHERE')!==0){return;}
			$s=$GLOBALS['mutation_server'];$s->hook=null;$delivered=true;
			$s->pdo->exec('INSERT INTO fixture_messages VALUES(30,2,7,8,0,999)');
			$s->pdo->exec("INSERT INTO fixture_message_text VALUES(30,'new arrival')");
		};
		mutation_check(phpbb_pm_delete_messages(array(),8,'sentbox',true)===1 && $delivered,'Delete-all removes the original bounded selection');
		mutation_check(pm_scalar('SELECT COUNT(*) FROM fixture_messages WHERE privmsgs_id=30')===1 && pm_scalar('SELECT COUNT(*) FROM fixture_message_text WHERE privmsgs_text_id=30')===1,'Delete-all does not consume newly delivered higher-ID copies');
		echo $locale." mailbox intent, recovery, source-preservation and capability checks passed.\n";
	}
	$source=file_get_contents($forum_root.'privmsg.php');
	mutation_check(strpos($source,"phpbb_pm_recover_mailbox(\$userdata['user_id'], \$folder)")!==false,'Actual controller exposes owner-scoped retry after a vanished selection');
	mutation_check(strpos($source,"'read', \$privmsg['privmsgs_id']")!==false && strpos($source,'phpbb_pm_finalize_delivery(')!==false,'Actual automatic trim/finalization calls bind distinct capabilities');
}
finally
{
	if($mutation_server->owner!==null){$mutation_server->owner->sql_close();}
	if(is_file($upload_dir.'/fixture.txt')){unlink($upload_dir.'/fixture.txt');}
	if(is_file($upload_dir.'/replacement.txt')){unlink($upload_dir.'/replacement.txt');}
	if(is_file($upload_dir.'/thumbs/t_fixture.txt')){unlink($upload_dir.'/thumbs/t_fixture.txt');}
	if(is_dir($upload_dir.'/thumbs')){rmdir($upload_dir.'/thumbs');}
	rmdir($upload_dir);restore_error_handler();
}
