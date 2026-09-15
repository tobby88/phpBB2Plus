<?php
require __DIR__.'/check-maintenance-polls.php';
function poll_session_snapshot(){
 $out=array();foreach(array('users','topics','polls','options','voters','junior') as $table){
  $rows=$GLOBALS['poll_mtnc_server']->pdo->query('SELECT * FROM fixture_'.$table)->fetchAll(PDO::FETCH_ASSOC);
  $rows=array_map('serialize',$rows);sort($rows,SORT_STRING);$out[$table]=$rows;
 }return $out;
}
function poll_session_revoke($kind){
 $sql=array('missing'=>'DELETE FROM fixture_sessions','foreign'=>'UPDATE fixture_sessions SET session_user_id=99',
  'logged-out'=>'UPDATE fixture_sessions SET session_logged_in=0','admin-revoked'=>'UPDATE fixture_sessions SET session_admin=0',
  'case-changed'=>"UPDATE fixture_sessions SET session_id='FIXTURE-SID'",'replacement'=>"UPDATE fixture_sessions SET session_id='different-sid'");
 $GLOBALS['poll_mtnc_server']->pdo->exec($sql[$kind]);
}
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{foreach($native?array('InnoDB'):array('SQLite') as $engine){
 foreach(array(1,20) as $actor){foreach(array('missing','foreign','logged-out','admin-revoked','case-changed','replacement') as $kind){
  foreach(array('entry','DELETE FROM fixture_polls','DELETE FROM fixture_options','DELETE FROM fixture_voters','UPDATE fixture_voters','UPDATE fixture_topics') as $boundary){
   poll_mtnc_fixture($engine,$actor);
   if($actor===20){$poll_mtnc_server->pdo->exec("INSERT INTO fixture_junior VALUES (20,'".md5('GeneralDB_Maintenanceadmin_db_maintenance.php')."')");}
   $snapshot=poll_session_snapshot();$intercepted=false;
   if($boundary==='entry'){poll_session_revoke($kind);}
   else{$poll_mtnc_server->hook=function($sql)use($kind,$boundary,&$intercepted){
    if(strpos($sql,$boundary)!==0){return;}
    $GLOBALS['poll_mtnc_server']->hook=null;$intercepted=true;try{poll_session_revoke($kind);}catch(PDOException $e){if(!$GLOBALS['native']||!isset($e->errorInfo[1])||(int)$e->errorInfo[1]!==1205){throw $e;}$GLOBALS['poll_mtnc_server']->actor_blocked=true;}
   };}
   $out=poll_mtnc_run(function()use($lang){return $GLOBALS['poll_mtnc_server']->actor_blocked?'':$lang['Not_Authorised'];});
   poll_mtnc_check($boundary==='entry'||$intercepted,'Actual poll write-boundary injection reached');
   if($poll_mtnc_server->actor_blocked){foreach(array('polls_removed'=>1,'options_removed'=>2,'voters_removed'=>2,'voters_anonymized'=>1,'topics_updated'=>3) as $key=>$count){poll_mtnc_check($out[$key]===$count,'Blocked revocation serializes with complete maintenance');}}
   else{poll_mtnc_check(poll_session_snapshot()===$snapshot,'Complete rollback after effective session revocation: '.$actor.' '.$kind.' '.$boundary);}
   $poll_mtnc_server->pdo->exec('DELETE FROM fixture_sessions');
   $poll_mtnc_server->pdo->exec("INSERT INTO fixture_sessions VALUES ('new-admin-sid',".$actor.",1,1)");
   $userdata['session_id']='new-admin-sid';
   poll_mtnc_run($lang['Session_invalid'],array('sid'=>'fixture-sid'));
   $_POST=array('sid'=>'new-admin-sid');$out=poll_mtnc_run();
   poll_mtnc_check($out['review_count']===3&&poll_mtnc_value('SELECT COUNT(*) FROM fixture_polls WHERE vote_id=2')===0,'Fresh authorized request completes interrupted repair');
   $again=poll_mtnc_run();foreach(array('polls_removed','options_removed','voters_removed','voters_anonymized','topics_updated') as $key){poll_mtnc_check($again[$key]===0,'Authorized retry remains idempotent');}
  }
 }}
 poll_mtnc_fixture($engine);$userdata['session_id']=array('bad');$snapshot=poll_session_snapshot();
 poll_mtnc_run($lang['Session_invalid']);poll_mtnc_check($snapshot===poll_session_snapshot()&&!$poll_mtnc_server->queries,'Malformed cached session fails before queries without warnings');
 echo $engine." current poll session, every write boundary, delegated access and retry passed\n";
}}finally{restore_error_handler();}
