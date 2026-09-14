<?php
require __DIR__.'/check-maintenance-pm-repair.php';
require __DIR__.'/maintenance-session-fixture.php';
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{foreach($native?array('InnoDB','MyISAM'):array('SQLite') as $engine){
 $tables=array('fixture_users','fixture_pm','fixture_text','fixture_links','fixture_descriptions','fixture_junior',PM_REPAIR_JOBS_TABLE,PM_REPAIR_ITEMS_TABLE);
 $boundaries=array('entry','INSERT INTO fixture_pm_repair_jobs','INSERT INTO fixture_pm_repair_items','UPDATE fixture_pm_repair_jobs SET repair_state',
  'DELETE FROM fixture_pm WHERE','DELETE FROM fixture_text','DELETE FROM fixture_links','UPDATE fixture_users SET',
  'UPDATE fixture_pm SET privmsgs_from_userid','UPDATE fixture_pm SET privmsgs_to_userid','DELETE FROM fixture_pm_repair_items','DELETE FROM fixture_pm_repair_jobs WHERE');
 foreach(array(1,20) as $actor){foreach(array('missing','foreign','logged-out','admin-revoked','case-changed','replacement') as $kind){
  foreach($boundaries as $boundary){
   pm_repair_fixture($engine,$actor);
   if($actor===20){$pm_repair_server->pdo->exec("INSERT INTO fixture_junior VALUES (20,'".md5('GeneralDB_Maintenanceadmin_db_maintenance.php')."')");}
   // Shared live reference exercises inventory but never deletes real files.
   $pm_repair_server->pdo->exec("INSERT INTO fixture_descriptions VALUES (1,'shared.txt',0)");
   $pm_repair_server->pdo->exec('INSERT INTO fixture_links VALUES (1,10,0),(1,12,0)');
   $snapshot=maintenance_session_snapshot($pm_repair_server->pdo,$tables);$intercepted=false;
   if($boundary==='entry'){maintenance_session_revoke($pm_repair_server->pdo,$kind);}
   else{$pm_repair_server->hook=function($sql)use($kind,$boundary,$tables,&$snapshot,&$intercepted){
    if(strpos($sql,$boundary)!==0){return;}
    $s=$GLOBALS['pm_repair_server'];$s->hook=null;$snapshot=maintenance_session_snapshot($s->pdo,$tables);$intercepted=true;maintenance_session_revoke($s->pdo,$kind);
   };}
   pm_repair_run($lang['Not_Authorised']);
   pm_repair_check($boundary==='entry'||$intercepted,'Repair write boundary reached: '.$boundary);
   pm_repair_check($snapshot===maintenance_session_snapshot($pm_repair_server->pdo,$tables),'No message/attachment/journal writes after revocation: '.$boundary);
   maintenance_session_login($pm_repair_server->pdo,$actor);pm_repair_run($lang['Session_invalid'],array('sid'=>'fixture-sid'));pm_repair_run();
   pm_repair_check(array_sum(pm_repair_run())===0&&pm_repair_value('SELECT COUNT(*) FROM fixture_pm_repair_jobs')===0&&pm_repair_value('SELECT COUNT(*) FROM fixture_pm_repair_items')===0,'Fresh login resumes and retires repair journal idempotently');
   pm_repair_check(pm_repair_value('SELECT COUNT(*) FROM fixture_pm')===5&&pm_repair_value('SELECT COUNT(*) FROM fixture_links WHERE privmsgs_id=12')===1&&pm_repair_value('SELECT COUNT(*) FROM fixture_descriptions')===1,'Authorized retry preserves valid messages and shared attachments');
  }
 }}
 foreach($selections as $mode=>$ids){pm_repair_fixture($engine);maintenance_session_revoke($pm_repair_server->pdo,'missing');$snapshot=maintenance_session_snapshot($pm_repair_server->pdo,$tables);pm_repair_run($lang['Not_Authorised'],null,$mode,$ids);pm_repair_check($snapshot===maintenance_session_snapshot($pm_repair_server->pdo,$tables),'Legacy selected repair also validates session');}
 pm_repair_fixture($engine);$userdata['session_id']=array('bad');pm_repair_run($lang['Session_invalid']);pm_repair_check(!$pm_repair_server->queries,'Malformed controller session rejected before query');
 pm_repair_run($lang['Not_Authorised'],null,'missing_text',array(10));
 echo $engine." PN repair current session, every write, journal recovery and delegated access passed\n";
}}finally{restore_error_handler();}
