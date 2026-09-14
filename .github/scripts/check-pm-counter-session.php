<?php
require __DIR__.'/check-maintenance-pm-counters.php';
require __DIR__.'/maintenance-session-fixture.php';
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{foreach($native?array('InnoDB','MyISAM'):array('SQLite') as $engine){
 $tables=array('fixture_users','fixture_pm','fixture_junior');
 foreach(array(1,20) as $actor){foreach(array('missing','foreign','logged-out','admin-revoked','case-changed','replacement') as $kind){
  foreach(array('entry','write','second-batch') as $boundary){
   pm_counter_fixture($engine,$actor);
   if($actor===20){$pm_counter_server->pdo->exec("INSERT INTO fixture_junior VALUES (20,'".md5('GeneralDB_Maintenanceadmin_db_maintenance.php')."')");}
   if($boundary==='second-batch'){pm_counter_many(101);}
   $snapshot=maintenance_session_snapshot($pm_counter_server->pdo,$tables);$intercepted=false;$writes=0;
   if($boundary==='entry'){maintenance_session_revoke($pm_counter_server->pdo,$kind);}
   else{$pm_counter_server->hook=function($sql)use($kind,$boundary,$tables,&$snapshot,&$intercepted,&$writes){
    if(strpos($sql,'UPDATE fixture_users SET user_new_privmsg')!==0||++$writes!==($boundary==='second-batch'?2:1)){return;}
    $s=$GLOBALS['pm_counter_server'];$s->hook=null;$snapshot=maintenance_session_snapshot($s->pdo,$tables);$intercepted=true;maintenance_session_revoke($s->pdo,$kind);
   };}
   pm_counter_run($lang['Not_Authorised']);
   pm_counter_check($boundary==='entry'||$intercepted,'Counter write boundary reached');
   pm_counter_check($snapshot===maintenance_session_snapshot($pm_counter_server->pdo,$tables),'No counter/source writes after revocation');
   maintenance_session_login($pm_counter_server->pdo,$actor);pm_counter_run($lang['Session_invalid'],array('sid'=>'fixture-sid'));
   pm_counter_check(pm_counter_run()===($boundary==='second-batch'?1:2),'New login completes only remaining counters');
   pm_counter_check(pm_counter_run()===0,'Counter recovery is idempotent');
  }
 }}
 pm_counter_fixture($engine);$userdata['session_id']=array('bad');pm_counter_run($lang['Session_invalid']);pm_counter_check(!$pm_counter_server->queries,'Malformed session rejected before query');
 echo $engine." PN counter current session, delegated rights, bounded batches and retry passed\n";
}}finally{restore_error_handler();}
