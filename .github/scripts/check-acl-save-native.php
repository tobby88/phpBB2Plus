<?php
// Real permission writer and mysqli driver, exclusively an owned loopback DB.
putenv('PHPBB_ATTACH_SETTINGS_NATIVE=0');
require __DIR__.'/check-attachment-settings-storage.php';
foreach(array('USER'=>0,'MOD'=>2,'AUTH_ACL'=>2,'GROUPS_TABLE'=>'fixture_groups','USER_GROUP_TABLE'=>'fixture_user_group',
 'AUTH_ACCESS_TABLE'=>'fixture_auth_access','FORUMS_TABLE'=>'fixture_forums') as $k=>$v){if(!defined($k)){define($k,$v);}}
if(PHP_SAPI!=='cli'||getenv('PHPBB_ACL_SAVE_NATIVE')!=='1'){echo "Native ACL save checks require an explicitly enabled disposable MySQL/MariaDB fixture.\n";return;}
require $ats_source.'db/mysqli.php';
$port=getenv('PHPBB_ACL_SAVE_PORT')?:'3306';$password=getenv('PHPBB_ACL_SAVE_PASSWORD')?:'';
ats_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Loopback fixture port');
$host='127.0.0.1:'.$port;$fixture='codex_acl_save_'.bin2hex(function_exists('random_bytes')?random_bytes(8):openssl_random_pseudo_bytes(8));
$control=new sql_db($host,'root',$password,'',false);
ats_check($control->sql_query('CREATE DATABASE '.$fixture.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Owned schema');
class AsnConnection {
 var $db;var $db_connect_id;
 function __construct($db){$this->db=$db;$this->db_connect_id=$db->db_connect_id;}
 function __call($m,$args){return call_user_func_array(array($this->db,$m),$args);}
 function sql_query($sql,$tx=false){
  if(!empty($GLOBALS['asn_acknowledged'])){$GLOBALS['asn_after_queries'][]=$sql;}
  $GLOBALS['asn_queries'][]=$sql;if(is_callable($GLOBALS['asn_hook'])){call_user_func($GLOBALS['asn_hook'],$sql);}
  if(preg_match('/^(UPDATE|DELETE|INSERT)\b/',$sql)&&++$GLOBALS['asn_write']===$GLOBALS['asn_fail']){return false;}
  if($sql==='COMMIT'&&$GLOBALS['asn_commit']==='fail'){return false;}
  $r=$this->db->sql_query($sql,$tx);
  if($sql==='COMMIT'&&$r&&$GLOBALS['asn_commit']!=='ack'){$GLOBALS['asn_acknowledged']=true;if(is_callable($GLOBALS['asn_after_commit'])){call_user_func($GLOBALS['asn_after_commit'],$this);}}
  return $sql==='COMMIT'&&$GLOBALS['asn_commit']==='ack'?false:$r;
 }
}
class AsnDatabase extends sql_db {function sql_dedicated_connection(){return new AsnConnection(parent::sql_dedicated_connection());}}
$db=new AsnDatabase($host,'root',$password,$fixture,false);$peer=new sql_db($host,'root',$password,$fixture,false);
$asn_tables=array('users','sessions','jr','groups','user_group','auth_access','forums');
$asn_hook=null;$asn_write=$asn_fail=0;$asn_commit='';$asn_queries=array();
$asn_after_commit=null;$asn_acknowledged=false;$asn_after_queries=array();
function asn_sql($sql){$r=$GLOBALS['peer']->sql_query($sql);ats_check($r,'Native fixture SQL');return $r;}
function asn_rows($sql){return phpbb_acl_rows($GLOBALS['peer'],$sql);}
function asn_snap(){
 $out=array();foreach($GLOBALS['asn_tables'] as $s){$rows=asn_rows('SELECT * FROM fixture_'.$s);usort($rows,function($a,$b){return strcmp(json_encode($a),json_encode($b));});$out[$s]=$rows;}return $out;
}
function asn_reset($actor,$scenario){
 global $userdata,$asn_actor,$asn_mode,$asn_target,$asn_post,$asn_hook,$asn_write,$asn_fail,$asn_commit,$asn_queries;
 $asn_hook=null;$asn_write=$asn_fail=0;$asn_commit='';$asn_queries=array();
 $GLOBALS['asn_after_commit']=null;$GLOBALS['asn_acknowledged']=false;$GLOBALS['asn_after_queries']=array();
 foreach($GLOBALS['asn_tables'] as $s){asn_sql('DELETE FROM fixture_'.$s);}
 asn_sql("INSERT INTO fixture_users VALUES (1,1,1,'root'),(2,0,1,'target'),(3,2,1,'member'),(4,0,1,'pending'),(5,0,1,'junior')");
 asn_sql("INSERT INTO fixture_sessions VALUES ('fixture-admin',".($actor==='root'?1:5).",1,1),('target-session',2,1,0),('member-session',3,1,0),('pending-session',4,1,0),('unrelated-session',99,1,0)");
 asn_sql("INSERT INTO fixture_groups (group_id,group_type,group_name,group_description,group_moderator,group_single_user) VALUES (10,0,'shared','',2,0),(11,0,'other','',3,0),(12,0,'personal','',2,1),(13,0,'founder','',1,1)");
 asn_sql('INSERT INTO fixture_user_group (group_id,user_id,user_pending) VALUES (10,2,0),(10,3,0),(10,4,1),(11,3,0),(12,2,0),(13,1,0)');
 asn_sql("INSERT INTO fixture_forums (forum_id,cat_id,forum_name,auth_read,auth_post) VALUES (1,1,'one',2,2),(2,1,'two',2,2)");
 asn_sql('INSERT INTO fixture_auth_access (group_id,forum_id,auth_mod) VALUES (11,1,1)');
 $asn_mode=strpos($scenario,'user-')===0||in_array($scenario,array('promote','demote'),true)?'user':'group';$asn_target=$asn_mode==='user'?2:10;
 $group=$asn_mode==='user'?12:10;
 if(strpos($scenario,'update')!==false||strpos($scenario,'delete')!==false){asn_sql('INSERT INTO fixture_auth_access (group_id,forum_id,auth_read) VALUES ('.$group.',1,1),('.$group.',2,1)');}
 if(in_array($scenario,array('promote','demote'),true)){
  asn_sql('INSERT INTO fixture_auth_access (group_id,forum_id,auth_read,auth_mod) VALUES (12,1,1,0),(12,2,1,1)');
  if($scenario==='demote'){asn_sql('UPDATE fixture_users SET user_level=1 WHERE user_id=2');}
 }
 $asn_actor=$actor==='root'?1:5;
 if($actor==='junior'){asn_sql("INSERT INTO fixture_jr VALUES (5,'".md5(($asn_mode==='user'?'Users':'Groups').'Permissionsadmin_ug_auth.php?mode='.$asn_mode)."')");}
 $userdata=array('user_id'=>$asn_actor,'user_level'=>1,'session_logged_in'=>true,'session_admin'=>true,'session_id'=>'fixture-admin');$_SERVER['REQUEST_METHOD']='POST';
 $asn_post=array('sid'=>'fixture-admin','adv'=>'1','private_auth_post'=>array(1=>'1',2=>'1'));
 if(strpos($scenario,'delete')!==false){$asn_post=array('sid'=>'fixture-admin','adv'=>'0','private'=>array(1=>'0',2=>'0'));}
 if($scenario==='moderator'){$asn_post=array('sid'=>'fixture-admin','moderator'=>array(1=>'1',2=>'1'));}
 if($scenario==='promote'||$scenario==='demote'){$asn_post=array('sid'=>'fixture-admin','userlevel'=>$scenario==='promote'?'admin':'user');}
}
function asn_run(){try{return phpbb_acl_save($GLOBALS['db'],$GLOBALS['asn_mode'],$GLOBALS['asn_target'],$GLOBALS['asn_post']);}catch(PhpbbAclException $e){return 'error';}}
function asn_boundary($sql){return preg_match('/^(UPDATE|DELETE|INSERT)\b/',$sql)||$sql==='COMMIT'||strpos($sql,' LOCK IN SHARE MODE')!==false;}
function asn_revoke($kind){
 $id=$GLOBALS['asn_actor'];$map=array('role'=>'UPDATE fixture_users SET user_level=0 WHERE user_id='.$id,'inactive'=>'UPDATE fixture_users SET user_active=0 WHERE user_id='.$id,
  'delegation'=>'DELETE FROM fixture_jr WHERE user_id='.$id,'missing'=>"DELETE FROM fixture_sessions WHERE session_id='fixture-admin'",
  'foreign'=>"UPDATE fixture_sessions SET session_user_id=99 WHERE session_id='fixture-admin'",'logout'=>"UPDATE fixture_sessions SET session_logged_in=0 WHERE session_id='fixture-admin'",
  'acp'=>"UPDATE fixture_sessions SET session_admin=0 WHERE session_id='fixture-admin'",'case'=>"UPDATE fixture_sessions SET session_id='FIXTURE-ADMIN' WHERE session_id='fixture-admin'",
  'replacement'=>"UPDATE fixture_sessions SET session_id='different-sid' WHERE session_id='fixture-admin'");return $map[$kind];
}
function asn_without_authority($snap){
 $id=$GLOBALS['asn_actor'];$snap['users']=array_values(array_filter($snap['users'],function($r)use($id){return (int)$r['user_id']!==$id;}));
 $snap['sessions']=array_values(array_filter($snap['sessions'],function($r){return strtolower($r['session_id'])!=='fixture-admin'&&$r['session_id']!=='different-sid';}));
 $snap['jr']=array_values(array_filter($snap['jr'],function($r)use($id){return (int)$r['user_id']!==$id;}));return $snap;
}
try{
 set_error_handler(function($s,$m){if(error_reporting()&$s){throw new RuntimeException($m);}});
 $schema=file_get_contents($ats_source.'install/schemas/mysql_schema.sql');
 foreach(array('groups','user_group','auth_access','forums') as $s){ats_check(preg_match('/CREATE TABLE phpbb_'.$s.'\s*\([\s\S]*?;/',$schema,$m)===1,'Canonical schema');asn_sql(str_replace('phpbb_'.$s,'fixture_'.$s,$m[0]));}
 foreach(array('users'=>'user_id INT PRIMARY KEY,user_level INT,user_active INT,username VARCHAR(255)',
  'sessions'=>'session_id VARCHAR(32) PRIMARY KEY,session_user_id INT,session_logged_in INT,session_admin INT',
  'jr'=>'user_id INT PRIMARY KEY,user_jr_admin TEXT') as $s=>$columns){asn_sql('CREATE TABLE fixture_'.$s.' ('.$columns.') ENGINE=InnoDB ROW_FORMAT=DYNAMIC');}
 asn_sql('SET SESSION innodb_lock_wait_timeout=1');$cases=$serialized=0;
 foreach(array('root','junior') as $actor){foreach(array('group-insert','group-update','group-delete','user-insert','user-update','user-delete','moderator','promote','demote') as $scenario){
  if($actor==='junior'&&in_array($scenario,array('promote','demote'),true)){continue;}
  asn_reset($actor,$scenario);$before=asn_snap();ats_check(asn_run()===true,'Authorized '.$actor.' '.$scenario);$after=asn_snap();$writes=$asn_write;$boundaries=array_values(array_filter($asn_queries,'asn_boundary'));
  ats_check($writes>0&&count(asn_rows("SELECT * FROM fixture_sessions WHERE session_id='fixture-admin'"))===1,'Actual writes keep acting session');
  ats_check(!asn_rows("SELECT * FROM fixture_sessions WHERE session_id='target-session'"),'Target session invalidated');
  ats_check(count(asn_rows('SELECT * FROM fixture_auth_access WHERE group_id=11 AND auth_mod=1'))===1,'Unrelated moderator grant preserved');
  if(strpos($scenario,'insert')!==false||strpos($scenario,'update')!==false){ats_check(count(asn_rows('SELECT * FROM fixture_auth_access WHERE group_id='.($asn_mode==='user'?12:10).' AND auth_post=1'))===2,'Both submitted forum grants saved');}
  if(strpos($scenario,'update')!==false){ats_check(count(asn_rows('SELECT * FROM fixture_auth_access WHERE group_id='.($asn_mode==='user'?12:10).' AND auth_read=1'))===2,'Unsubmitted grants preserved');}
  if($scenario==='moderator'){ats_check(asn_rows('SELECT user_level FROM fixture_users WHERE user_id=2')[0]['user_level']==2&&asn_rows('SELECT user_level FROM fixture_users WHERE user_id=4')[0]['user_level']==0,'Approved-only derived moderator role');}
  if($scenario==='promote'||$scenario==='demote'){ats_check(asn_rows('SELECT user_level FROM fixture_users WHERE user_id=2')[0]['user_level']==($scenario==='promote'?1:2),'Role transition preserves real moderation');}
  asn_run();ats_check(asn_snap()===$after,'Authorized retry idempotent');
  for($n=1;$n<=$writes;$n++){asn_reset($actor,$scenario);$before=asn_snap();$asn_fail=$n;ats_check(asn_run()==='error'&&asn_snap()===$before,'Every failed write rolls back all grants/roles/sessions: '.$scenario.' '.$n);$cases++;}
  foreach(array('fail','ack') as $kind){asn_reset($actor,$scenario);$before=asn_snap();$asn_commit=$kind;ats_check(asn_run()==='error','Uncertain commit never announces success');ats_check(asn_snap()===($kind==='fail'?$before:$after),'COMMIT has whole before/after outcome');$cases++;}
  $authority=$actor==='root'?'role':'delegation';
  foreach(array('missing',$authority,'disconnect') as $kind){
   asn_reset($actor,$scenario);$stored=null;$asn_after_commit=function($writer)use($kind,&$stored){$stored=asn_snap();if($kind==='disconnect'){asn_sql('KILL CONNECTION '.(int)mysqli_thread_id($writer->db_connect_id));}else{asn_sql(asn_revoke($kind));}};
   ats_check(asn_run()===true&&$stored===$after&&!$asn_after_queries,'Acknowledged permission/role save survives later '.$kind);$cases++;
  }
  foreach(array('inactive','missing','foreign','logout','acp','case','replacement',$authority) as $kind){asn_reset($actor,$scenario);asn_sql(asn_revoke($kind));$before=asn_snap();ats_check(asn_run()==='error'&&asn_snap()===$before,'Current entry authority '.$kind);$cases++;}
  foreach(array('missing',$authority) as $kind){for($nth=1;$nth<=count($boundaries);$nth++){
   asn_reset($actor,$scenario);$before=asn_snap();$seen=0;$reached=$blocked=false;
   $asn_hook=function($sql)use($kind,$nth,&$seen,&$reached,&$blocked){if(!asn_boundary($sql)||++$seen!==$nth){return;}$GLOBALS['asn_hook']=null;$reached=true;
    if(!$GLOBALS['peer']->sql_query(asn_revoke($kind))){$e=$GLOBALS['peer']->sql_error();ats_check((int)$e['code']===1205,'Only real lock timeout counts as serialization');$blocked=true;}};
   $result=asn_run();ats_check($reached,'Every write/commit/authority-lock boundary reached');
   if($blocked){ats_check($result===true&&asn_snap()===$after,'Revocation serializes after complete save');asn_sql(asn_revoke($kind));$serialized++;}
   else{ats_check($result==='error'&&asn_without_authority(asn_snap())===asn_without_authority($before),'Effective revocation rolls back entire save');}$cases++;
  }}
  echo $actor.' '.$scenario." native atomic save/current-authority passed\n";
 }}
 foreach($asn_tables as $s){asn_reset('root','group-insert');asn_sql('ALTER TABLE fixture_'.$s.' ENGINE=MyISAM');$before=asn_snap();ats_check(asn_run()==='error'&&asn_snap()===$before,'Reject nontransactional participant '.$s);asn_sql('ALTER TABLE fixture_'.$s.' ENGINE=InnoDB ROW_FORMAT=DYNAMIC');}
 asn_reset('root','group-insert');asn_sql('ALTER TABLE fixture_groups ROW_FORMAT=COMPACT');$before=asn_snap();ats_check(asn_run()==='error'&&asn_snap()===$before,'Reject old row format');asn_sql('ALTER TABLE fixture_groups ROW_FORMAT=DYNAMIC');
 asn_sql('ALTER TABLE fixture_groups MODIFY group_name VARCHAR(40) CHARACTER SET latin1');$before=asn_snap();ats_check(asn_run()==='error'&&asn_snap()===$before,'Reject old text column');asn_sql('ALTER TABLE fixture_groups MODIFY group_name VARCHAR(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
 foreach(array('user-insert','group-insert') as $scenario){asn_reset('junior',$scenario);$asn_mode=$asn_mode==='user'?'group':'user';$asn_target=$asn_mode==='user'?2:10;$before=asn_snap();ats_check(asn_run()==='error'&&asn_snap()===$before,'Delegation never crosses mode');}
 asn_reset('junior','promote');$before=asn_snap();ats_check(asn_run()==='error'&&asn_snap()===$before,'Junior cannot promote');
 asn_reset('root','demote');$asn_target=1;$before=asn_snap();ats_check(asn_run()==='error'&&asn_snap()===$before,'Self demotion blocked');
 asn_reset('root','demote');asn_sql('UPDATE fixture_users SET user_level=1 WHERE user_id=5');$userdata['user_id']=5;asn_sql("UPDATE fixture_sessions SET session_user_id=5 WHERE session_id='fixture-admin'");$asn_target=1;$before=asn_snap();ats_check(asn_run()==='error'&&asn_snap()===$before,'First administrator protected');
 asn_reset('root','user-insert');asn_sql('INSERT INTO fixture_user_group (group_id,user_id,user_pending) VALUES (12,4,0)');$before=asn_snap();ats_check(asn_run()==='error'&&asn_snap()===$before,'Shared personal group rejected');
 asn_reset('root','group-insert');$asn_post=array('sid'=>'fixture-admin');$before=asn_snap();ats_check(asn_run()===false&&asn_snap()===$before,'Empty request has no session or permission changes');
 // Simple mode observes each current forum policy, not a global private flag.
 asn_reset('root','group-insert');asn_sql('UPDATE fixture_forums SET auth_post=1 WHERE forum_id=2');$asn_post=array('sid'=>'fixture-admin','private'=>array(1=>'1',2=>'1'));
 ats_check(asn_run()===true&&count(asn_rows('SELECT * FROM fixture_auth_access WHERE group_id=10 AND auth_read=1'))===2&&count(asn_rows('SELECT * FROM fixture_auth_access WHERE group_id=10 AND auth_post=1'))===1,'Simple controls affect only current AUTH_ACL fields');
 // Duplicate preserved legacy rows retain OR grants without altering neighbors.
 asn_reset('root','group-update');asn_sql('INSERT INTO fixture_auth_access (group_id,forum_id,auth_download) VALUES (10,1,1)');
 ats_check(asn_run()===true&&count(asn_rows('SELECT * FROM fixture_auth_access WHERE group_id=10 AND forum_id=1 AND auth_read=1 AND auth_post=1 AND auth_download=1'))===2,'Duplicate grants merge consistently and preserve omitted fields');
 // Lock the selected policies/target and affected users until atomic commit.
 foreach(array('policy'=>'UPDATE fixture_forums SET auth_post=1 WHERE forum_id=2','personal'=>'UPDATE fixture_groups SET group_single_user=0 WHERE group_id=12','target'=>'UPDATE fixture_users SET user_level=1 WHERE user_id=2') as $kind=>$sql){
  asn_reset('root','user-insert');$blocked=false;$reached=false;
  $asn_hook=function($query)use($sql,&$blocked,&$reached){if(strpos($query,'INSERT INTO fixture_auth_access')!==0){return;}$GLOBALS['asn_hook']=null;$reached=true;$r=$GLOBALS['peer']->sql_query($sql);if(!$r){$e=$GLOBALS['peer']->sql_error();ats_check((int)$e['code']===1205,'Native target lock timeout');$blocked=true;}};
  ats_check(asn_run()===true&&$reached&&$blocked,'Concurrent '.$kind.' change serialized');asn_sql($sql);
 }
 foreach(array('before-begin','repeat-begin','after-commit','after-rollback','raw-commit','ddl','empty-commit','savepoint-before','savepoint-after','unknown-savepoint','raw-rollback','set-autocommit') as $case){
  asn_reset('root','group-insert');$before=asn_snap();$lock=new attach_mutation_lock($db);ats_check($lock->acquired,'Owned ACL lifecycle');$owner=new PhpbbAclSaveDatabase($lock->connection);$denied=false;
  try{
   if(!in_array($case,array('before-begin','empty-commit','savepoint-before'),true)){$owner->begin('group');$owner->sql_query("UPDATE fixture_groups SET group_description='owned' WHERE group_id=10");}
   if($case==='repeat-begin'){$owner->begin('group');}elseif($case==='empty-commit'){$owner->commit('group');}
   elseif($case==='raw-commit'){$owner->sql_query('COMMIT');}elseif($case==='ddl'){$owner->sql_query('ALTER TABLE fixture_groups ENGINE=MyISAM');}
   elseif($case==='unknown-savepoint'){$owner->sql_query('SAVEPOINT unknown');}elseif($case==='raw-rollback'){$owner->sql_query('ROLLBACK');}elseif($case==='set-autocommit'){$owner->sql_query('SET autocommit=1');}
   elseif($case==='savepoint-before'||$case==='savepoint-after'){if($case==='savepoint-after'){$owner->commit('group');}$owner->sql_query('SAVEPOINT phpbb_role_target');}
   else{if($case==='after-commit'){$owner->commit('group');}if($case==='after-rollback'){$owner->rollback();}$owner->sql_query("UPDATE fixture_groups SET group_description='escaped' WHERE group_id=10");}
  }catch(PhpbbAclException $e){$denied=true;}finally{$owner->rollback();$lock->release();}
  ats_check($denied,'Reject ACL transaction escape '.$case);ats_check(in_array($case,array('after-commit','savepoint-after'),true)?asn_rows('SELECT group_description FROM fixture_groups WHERE group_id=10')[0]['group_description']==='owned':asn_snap()===$before,'No implicit permission publication');$cases++;
 }
 echo 'Native user/group ACL: '.$cases.' boundary/failure cases, '.$serialized." serialized revocations; whole multi-forum grants and roles passed.\n";
}finally{
 $asn_hook=null;$asn_fail=0;$asn_commit='';$db->sql_close();$peer->sql_close();$control->sql_query('DROP DATABASE '.$fixture);$control->sql_close();restore_error_handler();
}
