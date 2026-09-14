<?php
require __DIR__.'/check-maintenance-pm-recovery.php';
require __DIR__.'/maintenance-session-fixture.php';
mutation_check(!is_dir($upload_dir)&&mkdir($upload_dir,0700),'Owned session recovery fixture');
function pm_session_files(){
 global $upload_dir;
 $out=array();foreach(array('fixture.txt','thumbs/t_fixture.txt') as $name){$out[$name]=is_file($upload_dir.'/'.$name)?hash_file('sha256',$upload_dir.'/'.$name):null;}return $out;
}
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{
 $tables=array('fixture_users','fixture_messages','fixture_message_text','fixture_links','fixture_descriptions',PM_REPAIR_JOBS_TABLE,PM_REPAIR_ITEMS_TABLE);
 foreach(array('fresh','resume') as $phase){foreach(array('missing','foreign','logged-out','admin-revoked','case-changed','replacement') as $kind){
  foreach(array('descriptor-read','thumbnail-assert','original-assert','thumbnail-write','descriptor-delete') as $boundary){
   pm_recovery_fixture();mkdir($upload_dir.'/thumbs',0700);file_put_contents($upload_dir.'/thumbs/t_fixture.txt','owned thumbnail');
   $mutation_server->pdo->exec('UPDATE fixture_descriptions SET thumbnail=1');
   if($phase==='resume'){
    $mutation_server->failure='DELETE FROM fixture_message_text';
    pm_recovery_fail(function(){phpbb_pm_repair_messages(array(20,21),'deleted_users');},$lang['Maintenance_pm_repair_failed']);
    $mutation_server->failure='';
   }
   $snapshot=null;$files=null;$intercepted=false;$asserts=0;
   $mutation_server->hook=function($sql)use($kind,$boundary,$tables,&$snapshot,&$files,&$intercepted,&$asserts){
    $match=false;
    if($boundary==='descriptor-read'){$match=strpos($sql,'SELECT attach_id,physical_filename,thumbnail')===0;}
    elseif($boundary==='thumbnail-write'){$match=strpos($sql,'UPDATE fixture_descriptions SET thumbnail')===0;}
    elseif($boundary==='descriptor-delete'){$match=strpos($sql,'DELETE FROM fixture_descriptions')===0;}
    elseif(strpos($sql,'SELECT 1 AS allowed WHERE EXISTS (SELECT 1 FROM fixture_pm_repair_jobs')===0&&strpos($sql,'other_file')!==false){$match=++$asserts===($boundary==='original-assert'?2:1);}
    if(!$match){return;}
    $s=$GLOBALS['mutation_server'];$s->hook=null;$snapshot=maintenance_session_snapshot($s->pdo,$tables);$files=pm_session_files();$intercepted=true;maintenance_session_revoke($s->pdo,$kind);
   };
   pm_recovery_fail(function()use($phase){if($phase==='resume'){pm_recovery_run();}else{phpbb_pm_repair_messages(array(20,21),'deleted_users');}},$lang['Not_Authorised']);
   mutation_check($intercepted,'Actual attachment boundary reached: '.$boundary);
   mutation_check($snapshot===maintenance_session_snapshot($mutation_server->pdo,$tables)&&$files===pm_session_files(),'No further journal/source/file changes after session revocation: '.$boundary);
   mutation_check($mutation_server->count_rows(PM_REPAIR_JOBS_TABLE)>0,'Interrupted repair retains durable recovery state');
   if($boundary==='thumbnail-assert'){mutation_check(is_file($upload_dir.'/fixture.txt')&&is_file($upload_dir.'/thumbs/t_fixture.txt'),'Revocation before thumbnail deletion preserves both files');}
   if($boundary==='original-assert'){mutation_check(is_file($upload_dir.'/fixture.txt'),'Revocation before original deletion preserves original');}
   maintenance_session_login($mutation_server->pdo,8);pm_recovery_run();pm_recovery_finished();
   mutation_check(!is_file($upload_dir.'/thumbs/t_fixture.txt'),'Authorized recovery completes thumbnail cleanup');
  }
 }}
 echo "PN session revocation protects actual attachment bytes and permits explicit journal recovery\n";
}finally{
 if(isset($mutation_server)&&$mutation_server->owner!==null){$mutation_server->owner->sql_close();}
 pm_recovery_clear_files();rmdir($upload_dir);restore_error_handler();
}
