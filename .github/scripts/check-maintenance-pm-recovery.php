<?php
require __DIR__ . '/check-private-message-cleanup.php';
require_once $forum_root . 'includes/functions_pm_repair_journal.php';
mutation_check(!is_dir($upload_dir) && mkdir($upload_dir,0700),'Owned PM recovery directory');
function pm_recovery_clear_files()
{
	global $upload_dir;
	foreach(array('fixture.txt','replacement.txt','unregistered.txt','thumbs/t_fixture.txt') as $name)
	{
		if(is_file($upload_dir.'/'.$name)){unlink($upload_dir.'/'.$name);}
	}
	if(is_dir($upload_dir.'/thumbs')){rmdir($upload_dir.'/thumbs');}
}
function pm_recovery_fixture()
{
	global $userdata,$mutation_server;
	pm_recovery_clear_files(); pm_cleanup_fixture();
	foreach(array("privmsgs_read_token CHAR(32) NOT NULL DEFAULT ''",'privmsgs_read_copy_id INTEGER NOT NULL DEFAULT 0','privmsgs_copy_token CHAR(32) DEFAULT NULL') as $column){$mutation_server->pdo->exec('ALTER TABLE fixture_messages ADD COLUMN '.$column);}
	$userdata=array('user_id'=>8,'user_level'=>ADMIN,'session_logged_in'=>true,'session_admin'=>true,'session_id'=>'fixture-sid');
	$_SERVER['REQUEST_METHOD']='POST'; $_POST=array('sid'=>'fixture-sid');
	$mutation_server->pdo->exec('UPDATE fixture_messages SET privmsgs_from_userid=-1');
}
function pm_recovery_fail($callback,$expected)
{
	$caught='';try{call_user_func($callback);}catch(PhpbbAclException $error){$caught=$error->getMessage();}
	mutation_check($caught===$expected,'Expected controlled recovery failure: '.$caught.' / '.$expected);
	mutation_check(!$GLOBALS['mutation_server']->owner,'Recovery owner released');
}
function pm_recovery_run()
{
	return dbmtnc_repair_pm($GLOBALS['db'],$_POST);
}
function pm_recovery_finished()
{
	global $mutation_server,$upload_dir;
	foreach(array(PRIVMSGS_TABLE,PRIVMSGS_TEXT_TABLE,ATTACHMENTS_TABLE,ATTACHMENTS_DESC_TABLE,PM_REPAIR_JOBS_TABLE,PM_REPAIR_ITEMS_TABLE) as $table)
	{
		mutation_check($mutation_server->count_rows($table)===0,'Completed intended cleanup: '.$table);
	}
	mutation_check(!is_file($upload_dir.'/fixture.txt'),'Intended final file removed');
	mutation_check(array_sum(pm_recovery_run())===0,'Completed recovery is a no-op on repeat');
}
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try
{
	foreach(array('english','german') as $locale)
	{
		include $forum_root.'language/lang_'.$locale.'/lang_dbmtnc.php';
		foreach(array('INSERT INTO fixture_pm_repair_jobs','INSERT INTO fixture_pm_repair_items','UPDATE fixture_pm_repair_jobs SET repair_state',
			'DELETE FROM fixture_messages WHERE','DELETE FROM fixture_message_text','DELETE FROM fixture_links','UPDATE fixture_users SET',
			'SELECT attach_id,physical_filename,thumbnail','DELETE FROM fixture_descriptions','DELETE FROM fixture_pm_repair_jobs WHERE') as $failure)
		{
			pm_recovery_fixture(); $mutation_server->failure=$failure;
			pm_recovery_fail(function(){phpbb_pm_repair_messages(array(20,21),'deleted_users');},$lang['Maintenance_pm_repair_failed']);
			$pending=$mutation_server->count_rows(PM_REPAIR_JOBS_TABLE);
			mutation_check($pending===($failure==='INSERT INTO fixture_pm_repair_jobs'?0:1),'Only the interrupted message has a durable job');
			$mutation_server->failure=''; $result=pm_recovery_run();
			mutation_check($result['recovered']===$pending,'Report resumed operations without inventing deletion counts');
			pm_recovery_finished();
		}
		// Job-item deletion and the last job DELETE can fail after all source
		// work succeeded. A prepared, empty-inventory job is still retryable.
		pm_recovery_fixture(); $mutation_server->hook=function($sql)
		{
			if(strpos($sql,'DELETE FROM fixture_pm_repair_items')===0 && strpos($sql,"HEX('prepared')")!==false)
			{
				$GLOBALS['mutation_server']->hook=null; $GLOBALS['mutation_server']->failure=$sql;
			}
		};
		pm_recovery_fail(function(){phpbb_pm_repair_messages(array(20,21),'deleted_users');},$lang['Maintenance_pm_repair_failed']);
		$mutation_server->failure=''; pm_recovery_run(); pm_recovery_finished();
		foreach(array('INSERT INTO fixture_pm_repair_jobs','INSERT INTO fixture_pm_repair_items','UPDATE fixture_pm_repair_jobs SET repair_state','DELETE FROM fixture_messages WHERE','DELETE FROM fixture_message_text','DELETE FROM fixture_links','DELETE FROM fixture_descriptions','DELETE FROM fixture_pm_repair_jobs WHERE') as $boundary)
		{
			pm_recovery_fixture(); $committed=false;
			$mutation_server->hook=function($sql) use($boundary,&$committed)
			{
				if(strpos($sql,$boundary)!==0){return;}
				$s=$GLOBALS['mutation_server']; $s->hook=null;
				$s->pdo->exec($sql); $committed=true; $s->failure=$sql;
			};
			pm_recovery_fail(function(){phpbb_pm_repair_messages(array(20,21),'deleted_users');},$lang['Maintenance_pm_repair_failed']);
			mutation_check($committed,'Simulated accepted write with lost acknowledgement');
			$mutation_server->failure=''; pm_recovery_run(); pm_recovery_finished();
		}
		// A new attachment after inventory preparation must not be detached by
		// an old inventory. The source remains for a fresh authorized selection.
		pm_recovery_fixture(); $mutation_server->failure='DELETE FROM fixture_messages WHERE';
		pm_recovery_fail(function(){phpbb_pm_repair_messages(array(20),'deleted_users');},$lang['Maintenance_pm_repair_failed']);
		$mutation_server->failure=''; file_put_contents($upload_dir.'/replacement.txt','late attachment');
		$mutation_server->pdo->exec("INSERT INTO fixture_descriptions (physical_filename,thumbnail) VALUES ('replacement.txt',0)");
		$mutation_server->pdo->exec('INSERT INTO fixture_links VALUES (2,0,20,8,7)');
		mutation_check(phpbb_pm_repair_messages(array(20),'deleted_users')===0,'Prepared inventory does not cover a new attachment');
		mutation_check(pm_scalar('SELECT COUNT(*) FROM fixture_messages WHERE privmsgs_id=20')===1&&is_file($upload_dir.'/replacement.txt'),'New source reference and bytes retained');

		// Missing thumbnail storage is not proof that its file is already gone.
		pm_recovery_fixture(); $mutation_server->pdo->exec('UPDATE fixture_descriptions SET thumbnail=1');
		pm_recovery_fail(function(){phpbb_pm_repair_messages(array(20,21),'deleted_users');},$lang['Maintenance_pm_repair_failed']);
		mutation_check(is_file($upload_dir.'/fixture.txt')&&$mutation_server->count_rows(PM_REPAIR_JOBS_TABLE)===1,'File failure retains main bytes and prepared inventory');
		mkdir($upload_dir.'/thumbs',0700); file_put_contents($upload_dir.'/thumbs/t_fixture.txt','thumbnail');
		pm_recovery_run(); pm_recovery_finished(); mutation_check(!is_file($upload_dir.'/thumbs/t_fixture.txt'),'Recovered thumbnail removed');

		foreach(array('restored-parent','restored-text','moved-copy','changed-descriptor','dual-reference','duplicate-filename','unregistered-upload') as $case)
		{
			pm_recovery_fixture();
			if($case==='restored-text')
			{
				$mutation_server->pdo->exec('UPDATE fixture_messages SET privmsgs_from_userid=8');
				$mutation_server->pdo->exec('DELETE FROM fixture_message_text WHERE privmsgs_text_id=20');
				$mutation_server->failure='DELETE FROM fixture_messages WHERE';
				pm_recovery_fail(function(){phpbb_pm_repair_messages(array(20),'missing_text',1000);},$lang['Maintenance_pm_repair_failed']);
				$mutation_server->pdo->exec("INSERT INTO fixture_message_text VALUES (20,'Restored text')");
			}
			else
			{
				$mutation_server->failure=$case==='moved-copy'?'DELETE FROM fixture_messages WHERE':'DELETE FROM fixture_message_text';
				pm_recovery_fail(function(){phpbb_pm_repair_messages(array(20,21),'deleted_users');},$lang['Maintenance_pm_repair_failed']);
				if($case==='restored-parent'){$mutation_server->pdo->exec('INSERT INTO fixture_messages (privmsgs_id,privmsgs_type,privmsgs_to_userid,privmsgs_from_userid,privmsgs_attachment,privmsgs_date) VALUES (20,0,7,8,1,999)');$mutation_server->pdo->exec("UPDATE fixture_message_text SET privmsgs_text='Restored body' WHERE privmsgs_text_id=20");}
				elseif($case==='moved-copy'){$mutation_server->pdo->exec('UPDATE fixture_messages SET privmsgs_from_userid=8,privmsgs_type=3 WHERE privmsgs_id=20');}
				elseif($case==='changed-descriptor')
				{
					$mutation_server->pdo->exec('UPDATE fixture_messages SET privmsgs_from_userid=8 WHERE privmsgs_id=21');
					$mutation_server->pdo->exec("UPDATE fixture_descriptions SET physical_filename='replacement.txt'");file_put_contents($upload_dir.'/replacement.txt','replacement');
				}
				elseif($case==='dual-reference'){$mutation_server->pdo->exec('UPDATE fixture_links SET post_id=10 WHERE privmsgs_id=20');}
				elseif($case==='duplicate-filename'){$mutation_server->pdo->exec("INSERT INTO fixture_descriptions (physical_filename,thumbnail) VALUES ('fixture.txt',0)");}
				else{file_put_contents($upload_dir.'/unregistered.txt','unfinished upload');}
			}
			$mutation_server->failure=''; $result=pm_recovery_run();
			if(in_array($case,array('restored-parent','restored-text','moved-copy','changed-descriptor'),true)){mutation_check($result['cancelled']===1,'Changed source is reported, not silently deleted');}
			if(in_array($case,array('restored-parent','restored-text','moved-copy'),true)){mutation_check(pm_scalar('SELECT COUNT(*) FROM fixture_messages WHERE privmsgs_id=20')===1&&pm_scalar('SELECT COUNT(*) FROM fixture_message_text WHERE privmsgs_text_id=20')===1,'Current parent and text retained');}
			if($case==='changed-descriptor'){mutation_check(is_file($upload_dir.'/replacement.txt'),'Replacement file retained');}
			if($case==='dual-reference'){mutation_check(pm_scalar('SELECT COUNT(*) FROM fixture_links WHERE post_id=10 AND privmsgs_id=0')===1,'Post half of dual-purpose reference retained');}
			if($case==='unregistered-upload'){mutation_check(is_file($upload_dir.'/unregistered.txt'),'Unrelated unfinished upload retained');pm_recovery_finished();}
			else{mutation_check(is_file($upload_dir.'/fixture.txt'),'Other references/restored source/reservation protect original bytes');}
			mutation_check($mutation_server->count_rows(PM_REPAIR_JOBS_TABLE)===0&&$mutation_server->count_rows(PM_REPAIR_ITEMS_TABLE)===0,'Resolved/cancelled job metadata retired');
		}
		foreach(array('actor','owner','job','item') as $case)
		{
			pm_recovery_fixture(); $mutation_server->failure='DELETE FROM fixture_message_text';
			pm_recovery_fail(function(){phpbb_pm_repair_messages(array(20,21),'deleted_users');},$lang['Maintenance_pm_repair_failed']);
			$mutation_server->failure=''; $changed=false;
			$mutation_server->hook=function($sql,$connection) use($case,&$changed)
			{
				if(strpos($sql,'SELECT 1 AS allowed WHERE EXISTS (SELECT 1 FROM fixture_pm_repair_jobs')!==0 || strpos($sql,'other_file')===false){return;}
				$changed=true; $s=$GLOBALS['mutation_server']; $s->hook=null;
				if($case==='actor'){$s->pdo->exec('UPDATE fixture_users SET user_active=0 WHERE user_id=8');}
				elseif($case==='owner'){$connection->db_connect_id=false;$connection->sql_close();}
				elseif($case==='job'){$s->pdo->exec('UPDATE fixture_pm_repair_jobs SET message_date=message_date+1');}
				else{$s->pdo->exec("UPDATE fixture_pm_repair_items SET physical_filename='changed.txt'");}
			};
			pm_recovery_fail(function(){pm_recovery_run();},$case==='actor'?$lang['Not_Authorised']:($case==='owner'?$lang['Maintenance_pm_repair_failed']:$lang['Maintenance_pm_journal_changed']));
			mutation_check($changed&&is_file($upload_dir.'/fixture.txt')&&$mutation_server->count_rows(PM_REPAIR_JOBS_TABLE)===1,'Fresh authority and journal/item identity guard physical bytes');
		}
		echo $locale." durable PM cleanup recovery passed.\n";
	}
}
finally
{
	if(isset($mutation_server)&&$mutation_server->owner!==null){$mutation_server->owner->sql_close();}
	pm_recovery_clear_files(); rmdir($upload_dir); restore_error_handler();
}
