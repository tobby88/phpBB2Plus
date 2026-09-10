<?php
$fixture = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/check-pm-write-attachments.php'));
$end = strpos($fixture, "\nset_error_handler(");
if ($end === false) { throw new RuntimeException('Attachment writer fixture boundary'); }
eval(substr($fixture,5,$end-5));
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try
{
	foreach($native?array('MyISAM','InnoDB'):array('SQLite') as $engine)
	{
		foreach(array('english','german') as $locale)
		{
			include $root.'language/lang_'.$locale.'/lang_main.php';
			include $root.'language/lang_'.$locale.'/lang_main_attach.php';
			pm_attachment_fixture($engine);$nonce=str_repeat('a',32);$name='pm_1_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.txt';
			$pm_repair_server->failure='INSERT INTO fixture_descriptions ';
			mailbox_storage_fail(function()use($nonce){phpbb_pm_write_message($nonce,12,'',pm_attachment_data(),0);},$lang['PM_cleanup_failed']);
			$pm_repair_server->failure='';
			pm_repair_check(pm_repair_value('SELECT COUNT(*) FROM fixture_descriptions WHERE pm_write_token IS NOT NULL')===0,'Failure precedes attachment reservation');
			pm_repair_check(pm_repair_value('SELECT COUNT(*) FROM fixture_pm WHERE privmsgs_id=12 AND privmsgs_write_payload IS NOT NULL')===1,'Accepted write already owns its unregistered file');
			$lock=attach_require_mutation_lock($db);
			try
			{
				pm_repair_check(attach_pm_stage_is_claimed($lock->connection,$name),'Actual accepted JSON intent claims exact unregistered filename');
				$compose=new PhpbbPmComposeDatabase($lock->connection,'post',0);
				$pm=(new ReflectionClass('attach_pm'))->newInstanceWithoutConstructor();$pm->compose_database=$compose;
				$pm->attachment_list=array($name);$pm->attachment_id_list=array(0);
				$caught='';try{$pm->validate_compose_attachments();}catch(PhpbbAclException $e){$caught=$e->getMessage();}
				pm_repair_check($caught===$lang['PM_write_pending'],'Old new-message form cannot adopt an accepted upload');
				$caught='';try{$pm->delete_temporary_attachment($name);}catch(PhpbbAclException $e){$caught=$e->getMessage();}
				pm_repair_check($caught===$lang['PM_write_pending']&&is_file($upload_dir.'/'.$name),'Old form cannot unlink accepted upload');
			}
			finally{$pm->compose_database=null;$lock->release();}
			$data=pm_attachment_data();$data['attachments']=array($data['attachments'][1]);
			mailbox_storage_fail(function()use($data){phpbb_pm_write_message(str_repeat('b',32),0,'',$data,0);},$lang['PM_write_pending']);
			phpbb_pm_retry_write($nonce,0);
			pm_repair_check(pm_repair_value('SELECT COUNT(*) FROM fixture_pm WHERE privmsgs_id=12 AND privmsgs_write_payload IS NULL')===1,'Original accepted nonce still resumes successfully');
			$pdo=$pm_repair_server->pdo;
			$insert=$pdo->prepare('INSERT INTO fixture_pm (privmsgs_id,privmsgs_type,privmsgs_from_userid,privmsgs_to_userid,privmsgs_date,privmsgs_write_payload) VALUES(?,1,1,8,700,?)');
			for($i=200;$i<=350;$i++)
			{
				$claim=$i===350?$name:'pm_1_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.txt';
				$insert->execute(array($i,json_encode(array('version'=>1,'content'=>array('attachments'=>array(array('id'=>0,'physical_filename'=>$claim)))))));
			}
			$lock=attach_require_mutation_lock($db);
			try
			{
				pm_repair_check(attach_pm_stage_is_claimed($lock->connection,$name),'Claim beyond first 100 pending messages is not truncated');
				pm_repair_check(!attach_pm_stage_is_claimed($lock->connection,'pm_1_cccccccccccccccccccccccccccccccc.txt'),'Unrelated exact filename is not claimed');
				$pdo->exec("UPDATE fixture_pm SET privmsgs_write_payload='broken JSON' WHERE privmsgs_id=200");
				pm_repair_check(attach_pm_stage_is_claimed($lock->connection,'pm_1_cccccccccccccccccccccccccccccccc.txt'),'Malformed pending intent preserves uploader files for review');
				pm_repair_check(!attach_pm_stage_is_claimed($lock->connection,'pm_2_cccccccccccccccccccccccccccccccc.txt'),'Other uploader is not blocked by unrelated corrupt intent');
			}
			finally{$lock->release();}
			echo $engine.' '.$locale." accepted PN staging-claim/retry checks passed.\n";
		}
	}
}
finally
{
	if($pm_repair_server->owner!==null){$pm_repair_server->owner->sql_close();}
	unlink($upload_dir.'/pm_1_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.txt');rmdir($upload_dir);restore_error_handler();
}
