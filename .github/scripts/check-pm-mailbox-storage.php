<?php
// Reuse only the owning-connection fixture definitions, not the unrelated ACP
// test cases. Both suites execute the actual runtime SQL on SQLite or, when
// explicitly selected, an isolated local MariaDB schema.
$fixture=file_get_contents(__DIR__.'/check-maintenance-pm-repair.php');
$end=strpos($fixture,'set_error_handler(');
if($end===false){throw new RuntimeException('Owning PM fixture definitions missing');}
eval(substr($fixture,5,$end-5));
require_once __DIR__.'/pm-mailbox-journal-fixture.php';
function mailbox_storage_fixture($engine)
{
	global $pm_repair_server,$userdata;
	pm_repair_fixture($engine);
	$pdo=$pm_repair_server->pdo;
	pm_mailbox_fixture_tables($pdo,$engine);
	$pdo->exec('ALTER TABLE fixture_users ADD COLUMN user_allow_pm INTEGER DEFAULT 1');
	$pdo->exec("INSERT INTO fixture_descriptions VALUES(1,'not-a-real-upload.txt',0)");
	$pdo->exec('INSERT INTO fixture_links VALUES(1,12,0),(1,18,0)');
	$userdata['user_id']=8; $userdata['user_level']=0; $userdata['session_admin']=false;
}
function mailbox_storage_fail($callback,$expected)
{
	$caught='';try{call_user_func($callback);}catch(RuntimeException $error){$caught=$error->getMessage();}
	pm_repair_check($caught===$expected,'Expected native mailbox result: '.$caught.' / '.$expected);
	pm_repair_check($GLOBALS['pm_repair_server']->owner===null,'Native mailbox owner released');
}
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try
{
	foreach($native?array('MyISAM','InnoDB'):array('SQLite') as $engine)
	{
		foreach(array('english','german') as $locale)
		{
			include $root.'language/lang_'.$locale.'/lang_main.php';
			foreach(array(PM_DELETE_JOBS_TABLE,PM_DELETE_ITEMS_TABLE) as $missing_table)
			{
				mailbox_storage_fixture($engine);$before=pm_repair_snapshot(PRIVMSGS_TABLE);
				$pm_repair_server->pdo->exec('DROP TABLE '.$missing_table);
				mailbox_storage_fail(function(){phpbb_pm_delete_messages(array(12),8,'inbox');},$lang['PM_journal_unavailable']);
				pm_repair_check($before===pm_repair_snapshot(PRIVMSGS_TABLE),'Missing schema fails before source mutations');
			}
			foreach(array('INSERT INTO fixture_pm_delete_jobs','INSERT INTO fixture_pm_delete_items','UPDATE fixture_pm_delete_jobs SET job_state',
				'DELETE FROM fixture_pm WHERE','DELETE FROM fixture_text','DELETE FROM fixture_links','UPDATE fixture_users SET',
				'DELETE FROM fixture_pm_delete_items','DELETE FROM fixture_pm_delete_jobs') as $boundary)
			{
				foreach(array(false,true) as $lost_ack)
				{
					mailbox_storage_fixture($engine);
					if($lost_ack)
					{
						$pm_repair_server->hook=function($sql) use($boundary)
						{
							if(strpos($sql,$boundary)!==0){return;}
							$s=$GLOBALS['pm_repair_server'];$s->hook=null;$s->pdo->exec($sql);$s->failure=$boundary;
						};
					}
					else {$pm_repair_server->failure=$boundary;}
					mailbox_storage_fail(function(){phpbb_pm_delete_messages(array(12),8,'inbox');},$lang['PM_cleanup_failed']);
					$pm_repair_server->failure='';$pm_repair_server->hook=null;
					$before=pm_repair_value('SELECT COUNT(*) FROM fixture_pm WHERE privmsgs_id=12');
					$_SERVER['REQUEST_METHOD']='GET';$_POST=array();phpbb_pm_recover_mailbox(8,'inbox');
					pm_repair_check(pm_repair_value('SELECT COUNT(*) FROM fixture_pm WHERE privmsgs_id=12')===$before,'Native GET does not remove surviving parents');
					$_SERVER['REQUEST_METHOD']='POST';$_POST=array('sid'=>'fixture-sid');phpbb_pm_delete_messages(array(12),8,'inbox');
					pm_repair_check(pm_repair_value('SELECT COUNT(*) FROM fixture_text WHERE privmsgs_text_id=12')===0 && pm_repair_value('SELECT COUNT(*) FROM fixture_links WHERE privmsgs_id=12')===0,'Native retry cleans vanished selection');
					pm_repair_check(pm_repair_value('SELECT COUNT(*) FROM fixture_pm_delete_jobs')===0 && pm_repair_value('SELECT COUNT(*) FROM fixture_pm_delete_items')===0,'Native retry retires journal');
					pm_repair_check(pm_repair_value('SELECT COUNT(*) FROM fixture_links WHERE privmsgs_id=18')===1 && pm_repair_value('SELECT COUNT(*) FROM fixture_descriptions')===1,'Native cleanup retains shared registration; never touches real files');
				}
			}
			mailbox_storage_fixture($engine);$userdata['user_id']=9;
			mailbox_storage_fail(function(){phpbb_pm_delete_messages(array(12),8,'inbox');},$lang['Not_Authorised']);
			mailbox_storage_fixture($engine);
			$pm_repair_server->hook=function($sql)
			{
				if(strpos($sql,'DELETE FROM fixture_pm WHERE')!==0){return;}
				$s=$GLOBALS['pm_repair_server'];$s->hook=null;$s->pdo->exec('UPDATE fixture_users SET user_active=0 WHERE user_id=8');
			};
			mailbox_storage_fail(function(){phpbb_pm_delete_messages(array(12),8,'inbox');},$lang['Not_Authorised']);
			pm_repair_check(pm_repair_value('SELECT COUNT(*) FROM fixture_pm WHERE privmsgs_id=12')===1,'Native current-actor SQL guard prevents deletion');
			mailbox_storage_fixture($engine);$userdata['user_id']=1;
			pm_repair_check(phpbb_pm_trim_oldest(8,'inbox',1,'send')===1,'Native sender quota capability');
			mailbox_storage_fixture($engine);
			$pm_repair_server->pdo->exec('UPDATE fixture_pm SET privmsgs_type=0 WHERE privmsgs_id=12');
			$pm_repair_server->pdo->exec('UPDATE fixture_pm SET privmsgs_type=2,privmsgs_from_userid=1 WHERE privmsgs_id=14');
			pm_repair_check(phpbb_pm_trim_oldest(1,'sentbox',1,'read',12)===1,'Native recipient read quota capability');
			echo $engine.' '.$locale." mailbox storage, failure recovery and capability checks passed.\n";
		}
	}
}
finally {if(isset($pm_repair_server) && $pm_repair_server->owner!==null){$pm_repair_server->owner->sql_close();}restore_error_handler();}
