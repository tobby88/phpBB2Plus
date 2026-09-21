<?php
// Actual group storage/driver and canonical plugin schema, never a live DB.
putenv('PHPBB_ATTACH_SETTINGS_NATIVE=0');
require __DIR__.'/check-attachment-settings-storage.php';
foreach(array('USER'=>0,'MOD'=>2,'GROUP_OPEN'=>0,'GROUP_CLOSED'=>1,'GROUP_HIDDEN'=>2,'POST_GROUPS_URL'=>'g',
 'GROUPS_TABLE'=>'fixture_groups','USER_GROUP_TABLE'=>'fixture_user_group','AUTH_ACCESS_TABLE'=>'fixture_auth_access',
 'FORUMS_TABLE'=>'fixture_forums','PA_AUTH_ACCESS_TABLE'=>'fixture_pa_auth') as $k=>$v){if(!defined($k)){define($k,$v);}}
require $ats_source.'includes/functions_group_storage.php';
ats_load_function($ats_source.'groupcp.php','groupcp_change');
if(PHP_SAPI!=='cli'||getenv('PHPBB_GROUP_MEMBERSHIP_NATIVE')!=='1'){echo "Native group storage checks require an explicitly enabled disposable MySQL/MariaDB fixture.\n";return;}
require $ats_source.'db/mysqli.php';
$port=getenv('PHPBB_GROUP_MEMBERSHIP_PORT')?:'3306';$password=getenv('PHPBB_GROUP_MEMBERSHIP_PASSWORD')?:'';
ats_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Loopback fixture port');
$host='127.0.0.1:'.$port;$fixture='codex_group_membership_'.bin2hex(function_exists('random_bytes')?random_bytes(8):openssl_random_pseudo_bytes(8));
$control=new sql_db($host,'root',$password,'',false);
ats_check($control->sql_query('CREATE DATABASE '.$fixture.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Owned schema');
class GmnConnection
{
 var $db;var $db_connect_id;
 function __construct($db){$this->db=$db;$this->db_connect_id=$db->db_connect_id;}
 function __call($method,$args){return call_user_func_array(array($this->db,$method),$args);}
 function sql_query($sql,$tx=false){
  if(!empty($GLOBALS['gmn_acknowledged'])){$GLOBALS['gmn_after_queries'][]=$sql;}
  $GLOBALS['gmn_queries'][]=$sql;if(is_callable($GLOBALS['gmn_hook'])){call_user_func($GLOBALS['gmn_hook'],$sql);}
  if(preg_match('/^(UPDATE|DELETE|INSERT)\b/',$sql)&&++$GLOBALS['gmn_write']===$GLOBALS['gmn_fail']){return false;}
  if($sql==='COMMIT'&&$GLOBALS['gmn_commit']==='fail'){return false;}
  $r=$this->db->sql_query($sql,$tx);
  if($sql==='COMMIT'&&$r&&$GLOBALS['gmn_commit']!=='ack'){$GLOBALS['gmn_acknowledged']=true;if(is_callable($GLOBALS['gmn_after_commit'])){call_user_func($GLOBALS['gmn_after_commit'],$this);}}
  return $sql==='COMMIT'&&$GLOBALS['gmn_commit']==='ack'?false:$r;
 }
}
class GmnDatabase extends sql_db {function sql_dedicated_connection(){return new GmnConnection(parent::sql_dedicated_connection());}}
$db=new GmnDatabase($host,'root',$password,$fixture,false);$peer=new sql_db($host,'root',$password,$fixture,false);
$gmn_hook=null;$gmn_fail=$gmn_write=0;$gmn_commit='';$gmn_queries=array();
$gmn_after_commit=null;$gmn_acknowledged=false;$gmn_after_queries=array();
$gmn_tables=array('groups','user_group','auth_access','forums','pa_auth','attach_quota','quota_limits','users','sessions','jr');
function gmn_sql($sql){$r=$GLOBALS['peer']->sql_query($sql);ats_check($r,'Native fixture SQL');return $r;}
function gmn_rows($sql){return phpbb_group_rows($GLOBALS['peer'],$sql);}
function gmn_snap(){
 $out=array();foreach($GLOBALS['gmn_tables'] as $s){$rows=gmn_rows('SELECT * FROM fixture_'.$s);usort($rows,function($a,$b){return strcmp(json_encode($a),json_encode($b));});$out[$s]=$rows;}return $out;
}
function gmn_reset($actor='root'){
 global $userdata,$gmn_actor,$gmn_hook,$gmn_fail,$gmn_write,$gmn_commit,$gmn_queries;
 $gmn_hook=null;$gmn_fail=$gmn_write=0;$gmn_commit='';$gmn_queries=array();
 $GLOBALS['gmn_after_commit']=null;$GLOBALS['gmn_acknowledged']=false;$GLOBALS['gmn_after_queries']=array();
 foreach($GLOBALS['gmn_tables'] as $s){gmn_sql('DELETE FROM fixture_'.$s);}
 gmn_sql('ALTER TABLE fixture_groups AUTO_INCREMENT=1');
 gmn_sql("INSERT INTO fixture_users (user_id,user_level,user_active,username) VALUES (1,1,1,'actor'),(2,2,1,'old'),(3,0,1,'new'),(4,2,1,'member'),(5,0,1,'unrelated')");
 gmn_sql("INSERT INTO fixture_sessions VALUES ('fixture-admin',1,1,1),('old-session',2,1,0),('new-session',3,1,0),('member-session',4,1,0),('unrelated-session',5,1,1)");
 gmn_sql("INSERT INTO fixture_groups (group_id,group_type,group_name,group_description,group_moderator,group_single_user) VALUES (10,0,'before','before',2,0),(11,0,'other','other',5,0),(12,0,'personal','personal',4,1)");
 gmn_sql('INSERT INTO fixture_user_group (group_id,user_id,user_pending) VALUES (10,2,0),(10,3,1),(10,4,0),(11,5,0),(12,4,0)');
 gmn_sql('INSERT INTO fixture_auth_access (group_id,forum_id,auth_mod) VALUES (10,1,1),(11,1,1)');
 gmn_sql("INSERT INTO fixture_forums (forum_id,cat_id,forum_name) VALUES (1,1,'fixture')");
 gmn_sql('INSERT INTO fixture_pa_auth VALUES (10,1),(11,1)');
 gmn_sql("INSERT INTO fixture_quota_limits VALUES (1,'first',1024),(2,'second',2048)");
 gmn_sql('INSERT INTO fixture_attach_quota VALUES (0,10,1,2),(0,11,1,2),(4,0,2,1)');

 $map=array('root'=>1,'leader'=>2,'pending'=>3,'member'=>4,'outsider'=>5);$gmn_actor=$map[$actor];
 gmn_sql("UPDATE fixture_sessions SET session_user_id=".$gmn_actor.",session_admin=0 WHERE session_id='fixture-admin'");
 $userdata=array('user_id'=>$gmn_actor,'user_level'=>1,'session_logged_in'=>true,'session_admin'=>false,'session_id'=>'fixture-admin');$_SERVER['REQUEST_METHOD']='POST';

}

function gmn_run($action,$value=null,$patch=array()){
 $_POST=array_merge(array('sid'=>'fixture-admin','g'=>'10'),$patch);$_GET=array();
 try{return groupcp_change($action,$value);}catch(AttachSettingsExit $e){return 'error';}
}
function gmn_boundary($sql){return preg_match('/^(UPDATE|DELETE|INSERT)\b/',$sql)||$sql==='COMMIT'||strpos($sql,' LOCK IN SHARE MODE')!==false;}

function gmn_revoke($kind){
 $id=$GLOBALS['gmn_actor'];$map=array('role'=>'UPDATE fixture_users SET user_level=0 WHERE user_id='.$id,'inactive'=>'UPDATE fixture_users SET user_active=0 WHERE user_id='.$id,
 'leader'=>'UPDATE fixture_groups SET group_moderator=1 WHERE group_id=10',
 'missing'=>"DELETE FROM fixture_sessions WHERE session_id='fixture-admin'",'foreign'=>"UPDATE fixture_sessions SET session_user_id=99 WHERE session_id='fixture-admin'",
 'logout'=>"UPDATE fixture_sessions SET session_logged_in=0 WHERE session_id='fixture-admin'",'case'=>"UPDATE fixture_sessions SET session_id='FIXTURE-ADMIN' WHERE session_id='fixture-admin'");
 return $map[$kind];
}
function gmn_without_authority($snapshot,$kind){
 $id=$GLOBALS['gmn_actor'];$snapshot['users']=array_values(array_filter($snapshot['users'],function($r)use($id){return (int)$r['user_id']!==$id;}));
 $snapshot['sessions']=array_values(array_filter($snapshot['sessions'],function($r){return strtolower($r['session_id'])!=='fixture-admin';}));
 if($kind==='leader'){foreach($snapshot['groups'] as &$g){if((int)$g['group_id']===10){unset($g['group_moderator']);}}unset($g);}
 return $snapshot;
}

try{
 set_error_handler(function($s,$m){if(error_reporting()&$s){throw new RuntimeException($m);}});
 if(!defined('GENERAL_MESSAGE')){define('GENERAL_MESSAGE',200);}
 $schema=file_get_contents($ats_source.'install/schemas/mysql_schema.sql');
 foreach(array('groups','user_group','auth_access','forums','quota_limits','attach_quota') as $s){ats_check(preg_match('/CREATE TABLE phpbb_'.$s.'\s*\([\s\S]*?;/',$schema,$m)===1,'Canonical schema');gmn_sql(str_replace('phpbb_'.$s,'fixture_'.$s,$m[0]));}
 foreach(array('users'=>"user_id INT PRIMARY KEY,user_level INT,user_active INT,username VARCHAR(255),user_email VARCHAR(255) DEFAULT 'fixture@example.invalid',user_lang VARCHAR(20) DEFAULT 'german'",
  'sessions'=>'session_id VARCHAR(32) PRIMARY KEY,session_user_id INT,session_logged_in INT,session_admin INT',
  'jr'=>'user_id INT PRIMARY KEY,user_jr_admin TEXT','pa_auth'=>'group_id INT,cat_id INT') as $s=>$columns){gmn_sql('CREATE TABLE fixture_'.$s.' ('.$columns.') ENGINE=InnoDB ROW_FORMAT=DYNAMIC');}
 gmn_sql('SET SESSION innodb_lock_wait_timeout=1');$cases=$serialized=0;
 $scenarios=array();foreach(array('root','leader') as $actor){foreach(array('status'=>1,'add'=>'unrelated','approve'=>array(3),'deny'=>array(3),'remove'=>array(4)) as $action=>$value){$scenarios[]=array($actor,$action,$value);}}
 foreach(array('join'=>'outsider','unsubscribe'=>'member','unsubscribe_pending'=>'pending') as $action=>$actor){$scenarios[]=array($actor,$action,null);}
 foreach($scenarios as $scenario){list($actor,$action,$value)=$scenario;
  gmn_reset($actor);$before=gmn_snap();$out=gmn_run($action,$value);ats_check(is_array($out)&&count($out['changed'])===1,'Actual public controller '.$actor.' '.$action);
  $after=gmn_snap();$boundaries=array_values(array_filter($gmn_queries,'gmn_boundary'));$writes=$gmn_write;
  ats_check(count($out['recipients'])===(in_array($action,array('join','add','approve'),true)?1:0),'Only actual join/add/approval yields notification');
  $repeat=gmn_run($action,$value);ats_check(is_array($repeat)&&!$repeat['changed']&&!$repeat['recipients']&&gmn_snap()===$after,'No-op retry has no notification or writes');
  ats_check($after['attach_quota']===$before['attach_quota']&&$after['pa_auth']===$before['pa_auth'],'Unrelated quotas and grants preserved');
  ats_check(count(gmn_rows("SELECT * FROM fixture_sessions WHERE session_id='fixture-admin'"))===1,'Actor keeps own current login without ACP rights');
  if($action==='approve'){ats_check(gmn_rows('SELECT user_pending FROM fixture_user_group WHERE group_id=10 AND user_id=3')[0]['user_pending']==0&&gmn_rows('SELECT user_level FROM fixture_users WHERE user_id=3')[0]['user_level']==2,'Approval derives real moderator role');}
  if($action==='remove'||$action==='unsubscribe'){ats_check(!gmn_rows('SELECT * FROM fixture_user_group WHERE group_id=10 AND user_id=4')&&gmn_rows('SELECT user_level FROM fixture_users WHERE user_id=4')[0]['user_level']==0,'Removal derives role without orphan/personal privileges');}
  if($action==='join'){ats_check(gmn_rows('SELECT user_pending FROM fixture_user_group WHERE group_id=10 AND user_id=5')[0]['user_pending']==1,'Self join is pending');}
  for($i=1;$i<=$writes;$i++){gmn_reset($actor);$before=gmn_snap();$gmn_fail=$i;ats_check(gmn_run($action,$value)==='error'&&gmn_snap()===$before,'Every failed write rolls back all state: '.$action.' '.$i);$cases++;}
  foreach(array('fail','ack') as $commit){gmn_reset($actor);$before=gmn_snap();$gmn_commit=$commit;ats_check(gmn_run($action,$value)==='error','Failed/uncertain COMMIT not reported successful');ats_check(gmn_snap()===($commit==='fail'?$before:$after),'Whole outcome on COMMIT failure/lost ack');$cases++;}
  $authority=$actor==='root'?'role':($actor==='leader'?'leader':'inactive');
  foreach(array('missing',$authority,'disconnect') as $kind){
   gmn_reset($actor);$stored=null;
   $gmn_after_commit=function($writer)use($kind,&$stored){$stored=gmn_snap();if($kind==='disconnect'){gmn_sql('KILL CONNECTION '.(int)mysqli_thread_id($writer->db_connect_id));}else{gmn_sql(gmn_revoke($kind));}};
   $actual=gmn_run($action,$value);
   ats_check($stored===$after&&$actual===$out&&!$gmn_after_queries,'Acknowledged outcome/recipients survive later '.$actor.' '.$action.' '.$kind);$cases++;
  }
  foreach(array('inactive','missing','foreign','logout','case',$authority) as $kind){gmn_reset($actor);gmn_sql(gmn_revoke($kind));$before=gmn_snap();ats_check(gmn_run($action,$value)==='error'&&gmn_snap()===$before,'Current entry authority: '.$actor.' '.$action.' '.$kind);$cases++;}
  foreach(array('missing',$authority) as $kind){for($nth=1;$nth<=count($boundaries);$nth++){
   gmn_reset($actor);$before=gmn_snap();$seen=0;$reached=$blocked=false;
   $gmn_hook=function($sql)use($kind,$nth,&$seen,&$reached,&$blocked){if(!gmn_boundary($sql)||++$seen!==$nth){return;}$GLOBALS['gmn_hook']=null;$reached=true;
    if(!$GLOBALS['peer']->sql_query(gmn_revoke($kind))){$e=$GLOBALS['peer']->sql_error();ats_check((int)$e['code']===1205,'Only native lock timeout counts as serialization');$blocked=true;}};
   $actual=gmn_run($action,$value);ats_check($reached,'Every write/commit/authority lock exercised');
   if($blocked){ats_check(is_array($actual)&&$actual===$out&&gmn_snap()===$after,'Revocation serialized after complete save');gmn_sql(gmn_revoke($kind));$serialized++;}
   else{ats_check($actual==='error'&&gmn_without_authority(gmn_snap(),$kind)===gmn_without_authority($before,$kind),'Effective concurrent revocation rolls back all membership/roles/sessions');}$cases++;
  }}
  echo $actor.' public '.$action." native rollback/current-authority checks passed\n";
 }
 // Normal users never acquire manager privileges through cached role fields.
 foreach(array('status'=>1,'add'=>'new','approve'=>array(3),'deny'=>array(3),'remove'=>array(4)) as $action=>$value){gmn_reset('outsider');$before=gmn_snap();ats_check(gmn_run($action,$value)==='error'&&gmn_snap()===$before,'Ordinary account cannot manage group');}
 foreach(array('groups','user_group','auth_access','forums','users','sessions') as $s){gmn_reset();gmn_sql('ALTER TABLE fixture_'.$s.' ENGINE=MyISAM');$before=gmn_snap();ats_check(gmn_run('approve',array(3))==='error'&&gmn_snap()===$before,'Reject nontransactional participant');gmn_sql('ALTER TABLE fixture_'.$s.' ENGINE=InnoDB ROW_FORMAT=DYNAMIC');}
 gmn_reset();gmn_sql('ALTER TABLE fixture_groups ROW_FORMAT=COMPACT');$before=gmn_snap();ats_check(gmn_run('approve',array(3))==='error'&&gmn_snap()===$before,'Reject legacy row format');gmn_sql('ALTER TABLE fixture_groups ROW_FORMAT=DYNAMIC');
 gmn_sql('ALTER TABLE fixture_groups MODIFY group_name VARCHAR(40) CHARACTER SET latin1');$before=gmn_snap();ats_check(gmn_run('approve',array(3))==='error'&&gmn_snap()===$before,'Reject legacy character column');gmn_sql('ALTER TABLE fixture_groups MODIFY group_name VARCHAR(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
 // Batch approval must not partly succeed when a later member fails.
 gmn_reset('leader');gmn_sql('UPDATE fixture_user_group SET user_pending=1 WHERE group_id=10 AND user_id=4');$before=gmn_snap();$gmn_fail=5;ats_check(gmn_run('approve',array(3,4))==='error'&&gmn_snap()===$before,'Whole multi-member request rolls back');
 gmn_reset('leader');$before=gmn_snap();$out=gmn_run('remove',array(2));ats_check(is_array($out)&&!$out['changed']&&gmn_snap()===$before,'Mandatory leader cannot be removed');
 gmn_reset('leader');$before=gmn_snap();ats_check(gmn_run('approve',array(3),array('g'=>array(10)))==='error'&&gmn_snap()===$before,'Actual controller preserves nested original selection for validation');
 gmn_reset('outsider');gmn_sql('UPDATE fixture_groups SET group_type=1 WHERE group_id=10');$before=gmn_snap();ats_check(gmn_run('join')==='error'&&gmn_snap()===$before,'Closed group rejects new join');
 foreach(array('before-begin','repeat-begin','after-commit','after-rollback','raw-commit','ddl','empty-commit') as $case){
  gmn_reset();$_POST=array('sid'=>'fixture-admin');$before=gmn_snap();$lock=new attach_mutation_lock($db);ats_check($lock->acquired,'Owned lifecycle connection');$owner=new PhpbbGroupMemberDatabase($lock->connection);$denied=false;
  try{
   if($case!=='before-begin'&&$case!=='empty-commit'){$owner->begin();$owner->sql_query("UPDATE fixture_groups SET group_description='owned' WHERE group_id=10");}
   if($case==='repeat-begin'){$owner->begin();}
   elseif($case==='empty-commit'){$owner->commit(10,'status');}
   elseif($case==='raw-commit'){$owner->sql_query('COMMIT');}
   elseif($case==='ddl'){$owner->sql_query('ALTER TABLE fixture_groups ENGINE=MyISAM');}
   else{if($case==='after-commit'){$owner->commit(10,'status');}if($case==='after-rollback'){$owner->rollback();}$owner->sql_query("UPDATE fixture_groups SET group_description='escaped' WHERE group_id=10");}
  }catch(PhpbbGroupException $e){$denied=true;}finally{$owner->rollback();$lock->release();}
  ats_check($denied,'Reject group transaction escape '.$case);
  ats_check($case==='after-commit'?gmn_rows('SELECT group_description FROM fixture_groups WHERE group_id=10')[0]['group_description']==='owned':gmn_snap()===$before,'Lifecycle guard never implicitly publishes a partial request');$cases++;
 }
 echo 'Native public group membership: '.$cases.' boundary/failure cases, '.$serialized." serialized revocations; all eight actions passed.\n";
}finally{
 $gmn_hook=null;$gmn_fail=0;$gmn_commit='';$db->sql_close();$peer->sql_close();$control->sql_query('DROP DATABASE '.$fixture);$control->sql_close();restore_error_handler();
}
