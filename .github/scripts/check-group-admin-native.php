<?php
// Actual group storage/driver and canonical plugin schema, never a live DB.
putenv('PHPBB_ATTACH_SETTINGS_NATIVE=0');
require __DIR__.'/check-attachment-settings-storage.php';
foreach(array('USER'=>0,'MOD'=>2,'GROUP_OPEN'=>0,'GROUP_CLOSED'=>1,'GROUP_HIDDEN'=>2,'POST_GROUPS_URL'=>'g',
 'GROUPS_TABLE'=>'fixture_groups','USER_GROUP_TABLE'=>'fixture_user_group','AUTH_ACCESS_TABLE'=>'fixture_auth_access',
 'FORUMS_TABLE'=>'fixture_forums','PA_AUTH_ACCESS_TABLE'=>'fixture_pa_auth') as $k=>$v){if(!defined($k)){define($k,$v);}}
require $ats_source.'includes/functions_group_admin_storage.php';
if(PHP_SAPI!=='cli'||getenv('PHPBB_GROUP_ADMIN_NATIVE')!=='1'){echo "Native group storage checks require an explicitly enabled disposable MySQL/MariaDB fixture.\n";return;}
require $ats_source.'db/mysqli.php';
$port=getenv('PHPBB_GROUP_ADMIN_PORT')?:'3306';$password=getenv('PHPBB_GROUP_ADMIN_PASSWORD')?:'';
ats_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Loopback fixture port');
$host='127.0.0.1:'.$port;$fixture='codex_group_admin_'.bin2hex(function_exists('random_bytes')?random_bytes(8):openssl_random_pseudo_bytes(8));
$control=new sql_db($host,'root',$password,'',false);
ats_check($control->sql_query('CREATE DATABASE '.$fixture.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Owned schema');
class GanConnection
{
 var $db;var $db_connect_id;
 function __construct($db){$this->db=$db;$this->db_connect_id=$db->db_connect_id;}
 function __call($method,$args){return call_user_func_array(array($this->db,$method),$args);}
 function sql_query($sql,$tx=false){
  $GLOBALS['gan_queries'][]=$sql;if(is_callable($GLOBALS['gan_hook'])){call_user_func($GLOBALS['gan_hook'],$sql);}
  if(preg_match('/^(UPDATE|DELETE|INSERT)\b/',$sql)&&++$GLOBALS['gan_write']===$GLOBALS['gan_fail']){return false;}
  if($sql==='COMMIT'&&$GLOBALS['gan_commit']==='fail'){return false;}
  $r=$this->db->sql_query($sql,$tx);return $sql==='COMMIT'&&$GLOBALS['gan_commit']==='ack'?false:$r;
 }
}
class GanDatabase extends sql_db {function sql_dedicated_connection(){return new GanConnection(parent::sql_dedicated_connection());}}
$db=new GanDatabase($host,'root',$password,$fixture,false);$peer=new sql_db($host,'root',$password,$fixture,false);
$gan_hook=null;$gan_fail=$gan_write=0;$gan_commit='';$gan_queries=array();
$gan_tables=array('groups','user_group','auth_access','forums','pa_auth','attach_quota','quota_limits','users','sessions','jr');
function gan_sql($sql){$r=$GLOBALS['peer']->sql_query($sql);ats_check($r,'Native fixture SQL');return $r;}
function gan_rows($sql){return phpbb_group_rows($GLOBALS['peer'],$sql);}
function gan_snap(){
 $out=array();foreach($GLOBALS['gan_tables'] as $s){$rows=gan_rows('SELECT * FROM fixture_'.$s);usort($rows,function($a,$b){return strcmp(json_encode($a),json_encode($b));});$out[$s]=$rows;}return $out;
}
function gan_reset($actor='root'){
 global $userdata,$gan_hook,$gan_fail,$gan_write,$gan_commit,$gan_queries;
 $gan_hook=null;$gan_fail=$gan_write=0;$gan_commit='';$gan_queries=array();
 foreach($GLOBALS['gan_tables'] as $s){gan_sql('DELETE FROM fixture_'.$s);}
 gan_sql('ALTER TABLE fixture_groups AUTO_INCREMENT=1');
 gan_sql("INSERT INTO fixture_users VALUES (1,".($actor==='root'?1:0).",1,'actor'),(2,2,1,'old'),(3,0,1,'new'),(4,2,1,'member'),(5,1,1,'unrelated')");
 gan_sql("INSERT INTO fixture_sessions VALUES ('fixture-admin',1,1,1),('old-session',2,1,0),('new-session',3,1,0),('member-session',4,1,0),('unrelated-session',5,1,1)");
 gan_sql("INSERT INTO fixture_groups (group_id,group_type,group_name,group_description,group_moderator,group_single_user) VALUES (10,0,'before','before',2,0),(11,0,'other','other',5,0),(12,0,'personal','personal',4,1)");
 gan_sql('INSERT INTO fixture_user_group (group_id,user_id,user_pending) VALUES (10,2,0),(10,3,1),(10,4,0),(11,5,0),(12,4,0)');
 gan_sql('INSERT INTO fixture_auth_access (group_id,forum_id,auth_mod) VALUES (10,1,1),(11,1,1)');
 gan_sql("INSERT INTO fixture_forums (forum_id,cat_id,forum_name) VALUES (1,1,'fixture')");
 gan_sql('INSERT INTO fixture_pa_auth VALUES (10,1),(11,1)');
 gan_sql("INSERT INTO fixture_quota_limits VALUES (1,'first',1024),(2,'second',2048)");
 gan_sql('INSERT INTO fixture_attach_quota VALUES (0,10,1,2),(0,11,1,2),(4,0,2,1)');
 if($actor!=='root'){$hash=array_search('admin_groups.php',jr_admin_authorization_routes(),true);ats_check($hash!==false,'Registered group delegation');gan_sql("INSERT INTO fixture_jr VALUES (1,'".$hash."')");}
 $userdata=array('user_id'=>1,'user_level'=>$actor==='root'?1:0,'session_logged_in'=>true,'session_admin'=>true,'session_id'=>'fixture-admin');$_SERVER['REQUEST_METHOD']='POST';
}
function gan_post($mode){$p=array('mode'=>$mode==='new'?'newgroup':'editgroup','g'=>'10','sid'=>'fixture-admin',
 'group_name'=>addslashes("Grüße & ' 😀"),'group_description'=>addslashes('Beschreibung 😀'),'username'=>'new','group_type'=>'1',
 'group_upload_quota'=>'1','group_pm_quota'=>'2','delete_old_moderator'=>'on');if($mode==='delete'){$p['group_delete']='on';}return $p;}
function gan_run($mode,$patch=array()){
 try{return phpbb_group_admin_save($GLOBALS['db'],array_merge(gan_post($mode),$patch));}catch(PhpbbGroupException $e){return 'error';}
}
function gan_boundary($sql){return preg_match('/^(UPDATE|DELETE|INSERT)\b/',$sql)||$sql==='COMMIT'||strpos($sql,' LOCK IN SHARE MODE')!==false;}
function gan_revoke($kind){$map=array('role'=>'UPDATE fixture_users SET user_level=0 WHERE user_id=1','inactive'=>'UPDATE fixture_users SET user_active=0 WHERE user_id=1',
 'grant'=>'DELETE FROM fixture_jr','missing'=>"DELETE FROM fixture_sessions WHERE session_id='fixture-admin'",'admin-off'=>"UPDATE fixture_sessions SET session_admin=0 WHERE session_id='fixture-admin'",
 'foreign'=>"UPDATE fixture_sessions SET session_user_id=99 WHERE session_id='fixture-admin'",'logout'=>"UPDATE fixture_sessions SET session_logged_in=0 WHERE session_id='fixture-admin'",
 'case'=>"UPDATE fixture_sessions SET session_id='FIXTURE-ADMIN' WHERE session_id='fixture-admin'");return $map[$kind];}
function gan_without_authority($snapshot){
 $snapshot['users']=array_values(array_filter($snapshot['users'],function($r){return (int)$r['user_id']!==1;}));
 $snapshot['sessions']=array_values(array_filter($snapshot['sessions'],function($r){return strtolower($r['session_id'])!=='fixture-admin';}));unset($snapshot['jr']);return $snapshot;
}
try{
 set_error_handler(function($s,$m){if(error_reporting()&$s){throw new RuntimeException($m);}});
 $schema=file_get_contents($ats_source.'install/schemas/mysql_schema.sql');
 foreach(array('groups','user_group','auth_access','forums','quota_limits','attach_quota') as $s){ats_check(preg_match('/CREATE TABLE phpbb_'.$s.'\s*\([\s\S]*?;/',$schema,$m)===1,'Canonical schema');gan_sql(str_replace('phpbb_'.$s,'fixture_'.$s,$m[0]));}
 foreach(array('users'=>'user_id INT PRIMARY KEY,user_level INT,user_active INT,username VARCHAR(255)',
  'sessions'=>'session_id VARCHAR(32) PRIMARY KEY,session_user_id INT,session_logged_in INT,session_admin INT',
  'jr'=>'user_id INT PRIMARY KEY,user_jr_admin TEXT','pa_auth'=>'group_id INT,cat_id INT') as $s=>$columns){gan_sql('CREATE TABLE fixture_'.$s.' ('.$columns.') ENGINE=InnoDB ROW_FORMAT=DYNAMIC');}
 gan_sql('SET SESSION innodb_lock_wait_timeout=1');$cases=$serialized=0;
 foreach(array('root','delegated') as $actor){foreach(array('edit'=>'Updated_group','new'=>'Added_new_group','delete'=>'Deleted_group') as $mode=>$success){
  gan_reset($actor);ats_check(gan_run($mode)===$success,'Native '.$actor.' '.$mode);$after=gan_snap();$boundaries=array_values(array_filter($gan_queries,'gan_boundary'));$writes=$gan_write;
  if($mode==='delete'){
   foreach(array('groups','user_group','auth_access','pa_auth','attach_quota') as $s){ats_check(!gan_rows('SELECT * FROM fixture_'.$s.' WHERE group_id=10'),'Delete removes exact dependents');}
   ats_check(gan_rows('SELECT user_level FROM fixture_users WHERE user_id=4')[0]['user_level']==0,'Delete derives moderator role');
  }else{
   $id=$mode==='new'?13:10;$row=gan_rows('SELECT * FROM fixture_groups WHERE group_id='.$id)[0];
   ats_check($row['group_name']==="Grüße & ' 😀"&&$row['group_moderator']==3,'Raw Unicode and new leader');
   ats_check(count(gan_rows('SELECT * FROM fixture_user_group WHERE group_id='.$id.' AND user_id=3 AND user_pending=0'))===1,'Approved leader');
   ats_check(count(gan_rows('SELECT * FROM fixture_attach_quota WHERE group_id='.$id))===2,'Both quotas');
   if($mode==='edit'){ats_check(!gan_rows('SELECT * FROM fixture_user_group WHERE group_id=10 AND user_id=2'),'Explicit old leader removal');}
  }
  ats_check(count(gan_rows('SELECT * FROM fixture_sessions WHERE session_user_id=5'))===1&&count(gan_rows('SELECT * FROM fixture_pa_auth WHERE group_id=11'))===1,'Unrelated user/group untouched');
  for($i=1;$i<=$writes;$i++){gan_reset($actor);$before=gan_snap();$gan_fail=$i;ats_check(gan_run($mode)==='error'&&gan_snap()===$before,'Every failed write fully rolls back: '.$mode.' '.$i);$cases++;}
  foreach(array('fail','ack') as $commit){gan_reset($actor);$before=gan_snap();$gan_commit=$commit;ats_check(gan_run($mode)==='error','Uncertain/failed COMMIT never reports success');ats_check(gan_snap()===($commit==='fail'?$before:$after),'COMMIT failure or lost acknowledgement has whole-state outcome');$cases++;}
  foreach(array('inactive','missing','admin-off','foreign','logout','case',$actor==='root'?'role':'grant') as $kind){gan_reset($actor);gan_sql(gan_revoke($kind));$before=gan_snap();ats_check(gan_run($mode)==='error'&&gan_snap()===$before,'Entry authority checks');$cases++;}
  foreach(array('missing',$actor==='root'?'role':'grant') as $kind){for($nth=1;$nth<=count($boundaries);$nth++){
   gan_reset($actor);$before=gan_snap();$seen=0;$reached=$blocked=false;
   $gan_hook=function($sql)use($kind,$nth,&$seen,&$reached,&$blocked){if(!gan_boundary($sql)||++$seen!==$nth){return;}$GLOBALS['gan_hook']=null;$reached=true;
    if(!$GLOBALS['peer']->sql_query(gan_revoke($kind))){$e=$GLOBALS['peer']->sql_error();ats_check((int)$e['code']===1205,'Only real row-lock timeout counts as serialization');$blocked=true;}};
   $outcome=gan_run($mode);ats_check($reached,'Every mutation/commit/authority lock tested');
   if($blocked){ats_check($outcome===$success&&gan_snap()===$after,'Blocked revocation serializes after atomic save');gan_sql(gan_revoke($kind));$serialized++;}
   else{ats_check($outcome==='error'&&gan_without_authority(gan_snap())===gan_without_authority($before),'Effective revocation rolls back all group state');}$cases++;
  }}
  echo $actor.' group '.$mode." rollback and concurrent authority checks passed\n";
 }}
 // All participant engines/row formats and text columns must satisfy policy.
 foreach($gan_tables as $s){gan_reset();gan_sql('ALTER TABLE fixture_'.$s.' ENGINE=MyISAM');$before=gan_snap();ats_check(gan_run('edit')==='error'&&gan_snap()===$before,'Reject old participant '.$s);gan_sql('ALTER TABLE fixture_'.$s.' ENGINE=InnoDB ROW_FORMAT=DYNAMIC');}
 gan_reset();gan_sql('ALTER TABLE fixture_groups ROW_FORMAT=COMPACT');$before=gan_snap();ats_check(gan_run('edit')==='error'&&gan_snap()===$before,'Reject compact row format');gan_sql('ALTER TABLE fixture_groups ROW_FORMAT=DYNAMIC');
 gan_sql('ALTER TABLE fixture_groups MODIFY group_name VARCHAR(40) CHARACTER SET latin1');$before=gan_snap();ats_check(gan_run('edit')==='error'&&gan_snap()===$before,'Reject legacy text column despite modern table default');gan_sql('ALTER TABLE fixture_groups MODIFY group_name VARCHAR(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
 foreach(array(array('group_name'=>str_repeat('😀',41)),array('group_type'=>'oops'),array('username'=>'missing'),array('group_pm_quota'=>'999'),array('sid'=>'wrong')) as $patch){gan_reset();$before=gan_snap();ats_check(gan_run('edit',$patch)==='error'&&gan_snap()===$before,'Invalid complete form has no writes');}
 gan_reset();gan_sql('DELETE FROM fixture_user_group WHERE group_id=10 AND user_id=2');ats_check(gan_run('delete')==='Deleted_group'&&gan_rows('SELECT user_level FROM fixture_users WHERE user_id=2')[0]['user_level']==0,'Orphan former leader role repaired on deletion');
 echo 'Native group administration: '.$cases.' boundary/failure cases, '.$serialized." serialized revocations; schema and lifecycle checks passed.\n";
}finally{
 $gan_hook=null;$gan_fail=0;$gan_commit='';$db->sql_close();$peer->sql_close();$control->sql_query('DROP DATABASE '.$fixture);$control->sql_close();restore_error_handler();
}
