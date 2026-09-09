<?php
$fixture=str_replace("\r\n","\n",file_get_contents(__DIR__.'/check-pm-mailbox-storage.php'));
$end=strpos($fixture,"\nset_error_handler(");
if($end===false){throw new RuntimeException('Mailbox storage fixture boundary missing');}
eval(substr($fixture,5,$end-5));
function publication_storage_fixture($engine)
{
	mailbox_storage_fixture($engine);
	$GLOBALS['pm_repair_server']->pdo->exec('ALTER TABLE fixture_users ADD COLUMN user_last_privmsg INTEGER DEFAULT 0');
	$GLOBALS['pm_repair_server']->pdo->exec('UPDATE fixture_pm SET privmsgs_date=500 WHERE privmsgs_id=12');
	$GLOBALS['userdata']['user_id']=1;
}
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try
{
	foreach($native?array('MyISAM','InnoDB'):array('SQLite') as $engine)
	{
		foreach(array('english','german') as $locale)
		{
			include $root.'language/lang_'.$locale.'/lang_main.php';
			foreach(array(0,4,5,100) as $limit)
			{
				publication_storage_fixture($engine);$removed=phpbb_pm_finalize_delivery(8,12,$limit);
				pm_repair_check($removed===($limit===4?1:0),'Post-publication quota only evicts above capacity');
				pm_repair_check(pm_repair_value('SELECT COUNT(*) FROM fixture_pm WHERE privmsgs_id=12')===1 && pm_repair_value('SELECT COUNT(*) FROM fixture_links WHERE privmsgs_id=12')===1,'Published message and registered attachment retained');
				pm_repair_check(pm_repair_value('SELECT user_new_privmsg FROM fixture_users WHERE user_id=8')===($limit===4?3:4),'Delivery counters do not double-add after eviction');
				pm_repair_check(pm_repair_value('SELECT user_last_privmsg FROM fixture_users WHERE user_id=8')===500,'Confirmed delivery updates last time');
				phpbb_pm_finalize_delivery(8,12,$limit);
				pm_repair_check(pm_repair_value('SELECT user_new_privmsg FROM fixture_users WHERE user_id=8')===($limit===4?3:4),'Repeated finalization does not increment counters');
			}
			foreach(array('text-missing','moved','foreign','revoked','moved-before-delete','removed-before-delete','text-removed-before-delete') as $case)
			{
				publication_storage_fixture($engine);
				if($case==='text-missing'){$pm_repair_server->pdo->exec('DELETE FROM fixture_text WHERE privmsgs_text_id=12');}
				elseif($case==='moved'){$pm_repair_server->pdo->exec('UPDATE fixture_pm SET privmsgs_type=3 WHERE privmsgs_id=12');}
				elseif($case==='foreign'){$pm_repair_server->pdo->exec('UPDATE fixture_pm SET privmsgs_from_userid=9 WHERE privmsgs_id=12');}
				else
				{
					$pm_repair_server->hook=function($sql) use($case)
					{
						if(strpos($sql,'DELETE FROM fixture_pm WHERE')!==0){return;}
						$s=$GLOBALS['pm_repair_server'];$s->hook=null;
						if($case==='revoked'){$s->pdo->exec('UPDATE fixture_users SET user_allow_pm=0 WHERE user_id=1');}
						elseif($case==='moved-before-delete'){$s->pdo->exec('UPDATE fixture_pm SET privmsgs_type=3 WHERE privmsgs_id=12');}
						elseif($case==='text-removed-before-delete'){$s->pdo->exec('DELETE FROM fixture_text WHERE privmsgs_text_id=12');}
						else {$s->pdo->exec('DELETE FROM fixture_pm WHERE privmsgs_id=12');}
					};
				}
				if($case==='revoked'){mailbox_storage_fail(function(){phpbb_pm_finalize_delivery(8,12,4);},$lang['Not_Authorised']);}
				else {phpbb_pm_finalize_delivery(8,12,4);}
				pm_repair_check(pm_repair_value('SELECT COUNT(*) FROM fixture_pm WHERE privmsgs_id=10')===1,'Current publication guard preserves old inbox: '.$case);
			}
			echo $engine.' '.$locale." post-publication quota and counter storage checks passed.\n";
		}
	}
}
finally {if(isset($pm_repair_server) && $pm_repair_server->owner!==null){$pm_repair_server->owner->sql_close();}restore_error_handler();}
