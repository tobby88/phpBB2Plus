<?php
require __DIR__.'/check-maintenance-rebuild.php';
function rebuild_session_snapshot(){
 $out=array();foreach(array('config','words','matches','results','posts','texts') as $table){
  $rows=$GLOBALS['rebuild_server']->pdo->query('SELECT * FROM fixture_'.$table)->fetchAll(PDO::FETCH_ASSOC);
  $rows=array_map('serialize',$rows);sort($rows,SORT_STRING);$out[$table]=$rows;
 }return $out;
}
function rebuild_session_revoke($kind){
 $sql=array('missing'=>'DELETE FROM fixture_sessions','foreign'=>'UPDATE fixture_sessions SET session_user_id=99',
  'logged-out'=>'UPDATE fixture_sessions SET session_logged_in=0','admin-revoked'=>'UPDATE fixture_sessions SET session_admin=0',
  'case-changed'=>"UPDATE fixture_sessions SET session_id='FIXTURE-SID'",'replacement'=>"UPDATE fixture_sessions SET session_id='different-sid'");
 $GLOBALS['rebuild_server']->pdo->exec($sql[$kind]);
}
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{foreach($native?array('InnoDB','MyISAM'):array('SQLite') as $engine){
 foreach(array('start','resume','step','release') as $mode){foreach(array('missing','foreign','logged-out','admin-revoked','case-changed','replacement') as $kind){foreach(array('entry','write') as $phase){
  rebuild_fixture($engine);$job=null;
  if($mode!=='start'){$job=rebuild_run('start');}
  if($mode==='release'){
   $job['state']['s']='release';$raw=json_encode($job['state']);
   $rebuild_server->pdo->exec("UPDATE fixture_config SET config_value=".$rebuild_server->pdo->quote($raw)." WHERE config_name='dbmtnc_rebuild_job'");
  }
  $request=($mode==='step'||$mode==='release')?rebuild_step_request($job['state']):$_POST;
  $snapshot=rebuild_session_snapshot();$intercepted=false;
  if($phase==='entry'){rebuild_session_revoke($kind);}
  else{$rebuild_server->hook=function($sql)use($kind,$mode,&$snapshot,&$intercepted){
   if(!preg_match('/^(INSERT|UPDATE|DELETE) /',$sql)){return;}
   if($mode==='release'&&strpos($sql,"config_name = 'board_disable'")===false){return;}
   $GLOBALS['rebuild_server']->hook=null;$snapshot=rebuild_session_snapshot();$intercepted=true;rebuild_session_revoke($kind);
  };}
  rebuild_run($mode==='release'?'step':$mode,$request,'Not_Authorised');
  rebuild_check($phase==='entry'||$intercepted,'Actual write-boundary injection reached');
  rebuild_check(rebuild_session_snapshot()===$snapshot,'No index/checkpoint/availability/source writes after revoked session: '.$mode.' '.$phase.' '.$kind);
  // An administrator who signs in again can resume the same generation. Old
  // signed URLs are not accepted under the new session identity.
  $rebuild_server->pdo->exec('DELETE FROM fixture_sessions');
  $rebuild_server->pdo->exec("INSERT INTO fixture_sessions VALUES ('new-admin-sid',1,1,1)");
  $userdata['session_id']='new-admin-sid';
  if($mode==='step'||$mode==='release'){$_SERVER['REQUEST_METHOD']='GET';rebuild_run('step',$request,'Invalid_dbmtnc_request');}
  $_SERVER['REQUEST_METHOD']='POST';$_POST=array('sid'=>'new-admin-sid');
  $resumed=rebuild_run($mode==='start'?'start':'resume');
  if($job){rebuild_check($resumed['state']['g']===$job['state']['g'],'Authorized resume keeps generation');}
  rebuild_complete($resumed);
  rebuild_check(rebuild_saved()['s']==='done'&&rebuild_value("SELECT config_value FROM fixture_config WHERE config_name='board_disable'")==='0','Authorized recovery completes and restores original availability');
 }}}
 rebuild_fixture($engine);$userdata['session_id']=array('bad');$before=rebuild_session_snapshot();rebuild_run('start',$_POST,'Invalid_dbmtnc_request');rebuild_check(rebuild_session_snapshot()===$before,'Malformed cached session fails without casting warnings');
 echo $engine." current rebuild session, signed continuation, SQL-boundary revocation and recovery passed\n";
}}finally{restore_error_handler();}
