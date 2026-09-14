<?php
require __DIR__.'/check-forum-acl-storage.php';
require __DIR__.'/acl-session-native-fixture.php';
require __DIR__.'/maintenance-session-fixture.php';
function acl_session_run($scenario){
 global $db,$userdata;
 if($scenario==='forum'){return phpbb_forum_acl_save($db,fa_post(array('auth_read'=>'0','sid'=>$userdata['session_id'])));}
 $mode=strpos($scenario,'user')===0||$scenario==='promote'||$scenario==='demote'?'user':'group';
 $extra=$scenario==='promote'||$scenario==='demote'?array('userlevel'=>$scenario==='promote'?'admin':'user'):
  ($scenario==='group-delete'?array('moderator'=>array(2=>'0')):
   ($scenario==='group-update'?array('moderator'=>array(2=>'0'),'private'=>array(2=>'1')):array('private'=>array(3=>'1'))));
 $extra['sid']=$userdata['session_id'];return phpbb_acl_save($db,$mode,$mode==='user'?9:3,acl_post($extra));
}
function acl_session_revoke($kind){
 $p=$GLOBALS['mutation_server']->pdo;
 $actions=array('missing'=>'DELETE FROM fixture_sessions','foreign'=>'UPDATE fixture_sessions SET session_user_id=99',
  'logged-out'=>'UPDATE fixture_sessions SET session_logged_in=0','admin-revoked'=>'UPDATE fixture_sessions SET session_admin=0',
  'case-changed'=>"UPDATE fixture_sessions SET session_id='FIXTURE-SESSION'",'replacement'=>"UPDATE fixture_sessions SET session_id='different-sid'");
 $p->exec($actions[$kind]." WHERE HEX(session_id)=HEX('fixture-session')");
}
function acl_session_login($actor){
 global $userdata,$mutation_server;
 $mutation_server->pdo->exec("DELETE FROM fixture_sessions WHERE session_id IN ('fixture-session','FIXTURE-SESSION','different-sid')");
 $mutation_server->pdo->exec("INSERT INTO fixture_sessions (session_id,session_user_id,session_logged_in,session_admin) VALUES ('fresh-session',".$actor.",1,1)");
 $userdata['session_id']='fresh-session';
}
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{foreach($aclSessionNative?array('InnoDB','MyISAM'):array('SQLite') as $engine){
 $tables=array_keys($aclSessionSchema);
 foreach(array('group-insert','group-update','group-delete','user-insert','promote','demote','forum') as $scenario){
  foreach(in_array($scenario,array('promote','demote'),true)?array(1):array(1,8) as $actor){
   acl_session_fixture($engine,$actor,$scenario);$writes=0;
   $mutation_server->hook=function($sql)use(&$writes){if(preg_match('/^(INSERT|UPDATE|DELETE) /',$sql)){$writes++;}};
   acl_session_run($scenario);$mutation_server->hook=null;
   mutation_check($writes>0&&$mutation_server->owner===null,'Authorized scenario writes and releases');
   if($scenario==='promote'||$scenario==='demote'){mutation_check(group_value('SELECT user_level FROM fixture_users WHERE user_id=9')===($scenario==='promote'?1:2),'Root role transition preserved');}
   if($scenario!=='forum'){mutation_check(group_value('SELECT COUNT(*) FROM fixture_sessions WHERE session_user_id=9')===0,'Changed target sessions expired');}
   mutation_check(group_value("SELECT COUNT(*) FROM fixture_sessions WHERE session_id='fixture-session'")===1,'Actor session retained');
   for($boundary=0;$boundary<=$writes;$boundary++){foreach(array('missing','foreign','logged-out','admin-revoked','case-changed','replacement') as $kind){
    acl_session_fixture($engine,$actor,$scenario);$seen=0;$intercepted=false;$snapshot=null;
    if($boundary===0){acl_session_revoke($kind);$snapshot=maintenance_session_snapshot($mutation_server->pdo,$tables);}
    else{$mutation_server->hook=function($sql)use($kind,$boundary,$tables,&$seen,&$intercepted,&$snapshot){
     if(!preg_match('/^(INSERT|UPDATE|DELETE) /',$sql)||++$seen!==$boundary){return;}
     $GLOBALS['mutation_server']->hook=null;acl_session_revoke($kind);$snapshot=maintenance_session_snapshot($GLOBALS['mutation_server']->pdo,$tables);$intercepted=true;
    };}
    acl_failure(function()use($scenario){acl_session_run($scenario);},'Not_Authorised');
    mutation_check($boundary===0||$intercepted,'Every real ACL write boundary reached');
    mutation_check($snapshot===maintenance_session_snapshot($mutation_server->pdo,$tables),'No ACL/role/other-session changes after revocation: '.$scenario.' '.$boundary);
    acl_session_login($actor);acl_session_run($scenario);$done=maintenance_session_snapshot($mutation_server->pdo,$tables);
    acl_session_run($scenario);mutation_check($done===maintenance_session_snapshot($mutation_server->pdo,$tables),'Authorized retry is idempotent');
   }}
  }
 }
 foreach(array('user','group','forum') as $mode){
  acl_session_fixture($engine,1,'group-insert');acl_session_revoke('missing');
  acl_failure(function()use($mode){phpbb_acl_actor(new PhpbbAclDatabase(new AclViewDatabase(),'Acl_read_failed'),$mode);},'Not_Authorised');
 }
 acl_session_fixture($engine,1,'group-insert');$userdata['session_id']=array('bad');
 acl_failure(function()use($db){phpbb_acl_save($db,'group',3,acl_post());},'Session_invalid');
 acl_failure(function()use($db){phpbb_forum_acl_save($db,fa_post(array('auth_read'=>0)));},'Session_invalid');
 echo $engine." ACL current-session, every write, role transition, target expiry and retry passed\n";
}}finally{restore_error_handler();}
