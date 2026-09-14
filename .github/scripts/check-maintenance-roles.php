<?php
// Fast logic/controller checks. SQL dialect/metadata/independent concurrency
// are verified with the actual driver by check-maintenance-roles-native.php.
$root=dirname(dirname(__DIR__)).'/phpBB2/';
foreach(array('IN_PHPBB'=>true,'ADMIN'=>1,'MOD'=>2,'USER'=>0,'GENERAL_ERROR'=>202,'ATTACHMENTS_TABLE'=>'fixture_links','USERS_TABLE'=>'fixture_users','GROUPS_TABLE'=>'fixture_groups','USER_GROUP_TABLE'=>'fixture_members','AUTH_ACCESS_TABLE'=>'fixture_auth','FORUMS_TABLE'=>'fixture_forums','SESSIONS_TABLE'=>'fixture_sessions','JR_ADMIN_TABLE'=>'fixture_junior') as $key=>$value){define($key,$value);}
require_once $root.'includes/functions_maintenance_roles.php';
function role_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
function message_die($code,$message){throw new RuntimeException($message);}
class RoleControllerFailure extends RuntimeException {}
function throw_error($message){throw new RoleControllerFailure($message);}
function lock_db(){throw new RuntimeException('Role synchronization must not change board availability');}
class RoleRows {var $rows;function __construct($rows){$this->rows=$rows;}}
class RoleServer {
 var $pdo;var $owner=null;var $failure='';var $lostAck=false;var $writes=0;var $failAt=0;var $queries=array();var $modern=true;
 function __construct(){
  $this->pdo=new PDO('sqlite::memory:');$this->pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
  $definitions=array('users'=>'user_id INTEGER PRIMARY KEY,username VARCHAR(255),user_level INTEGER,user_active INTEGER','groups'=>'group_id INTEGER PRIMARY KEY','members'=>'user_id INTEGER,group_id INTEGER,user_pending INTEGER','auth'=>'group_id INTEGER,forum_id INTEGER,auth_mod INTEGER','forums'=>'forum_id INTEGER PRIMARY KEY','sessions'=>'session_id VARCHAR(32) PRIMARY KEY,session_user_id INTEGER,session_logged_in INTEGER,session_admin INTEGER','junior'=>'user_id INTEGER,user_jr_admin VARCHAR(255)','config'=>'config_name VARCHAR(50) PRIMARY KEY,config_value VARCHAR(255)');
  foreach($definitions as $name=>$definition){$this->pdo->exec('CREATE TABLE fixture_'.$name.' ('.$definition.')');}
  $this->pdo->exec("INSERT INTO fixture_users VALUES (-1,'Anonymous',2,1),(0,'Zero',2,1),(1,'Root',1,1),(2,'Other admin',1,1),(10,'<script>Grüße</script>',0,1),(11,'Stale moderator',2,1),(12,'Valid moderator',2,1),(13,'Pending',2,1),(14,'Orphan group',2,1),(15,'Orphan forum',2,1),(16,'Invalid pending flag',2,1),(17,'Inactive',0,0),(18,'Special role',3,1),(20,'Junior',0,1)");
  $this->pdo->exec('INSERT INTO fixture_groups VALUES (10),(11)');$this->pdo->exec('INSERT INTO fixture_forums VALUES (5)');
  $this->pdo->exec('INSERT INTO fixture_auth VALUES (10,5,1),(11,999,1),(999,5,1)');
  $this->pdo->exec('INSERT INTO fixture_members VALUES (10,10,0),(12,10,0),(13,10,1),(14,999,0),(15,11,0),(16,10,2),(17,10,0)');
  foreach($this->pdo->query('SELECT user_id FROM fixture_users')->fetchAll(PDO::FETCH_ASSOC) as $row){$id=(int)$row['user_id'];$this->pdo->exec("INSERT INTO fixture_sessions VALUES ('session-".$id."',".$id.',1,1)');}
  $this->pdo->exec("INSERT INTO fixture_config VALUES ('board_disable','0')");
 }
}
class RoleForum {
 var $dbname='role-fixture';
 function sql_query($sql){throw new RuntimeException('Unlocked main connection used');}
 function sql_dedicated_connection(){return new RoleConnection($GLOBALS['role_server']);}
}
class RoleConnection {
 var $server;var $pdo;var $db_connect_id=true;var $closed=false;var $affected=0;
 function __construct($server){$this->server=$server;$this->pdo=$server->pdo;}
 function sql_query($sql){
  if($this->closed){return false;}$s=$this->server;$s->queries[]=$sql;
  if(strpos($sql,'SELECT GET_LOCK(')===0){$ok=$s->failure!=='lock'&&$s->owner===null;if($ok){$s->owner=$this;}return new RoleRows(array(array('acquired'=>$ok?1:0)));}
  role_check($s->owner===$this,'Every role/ACL query uses the owning connection');
  if(preg_match('/^(UPDATE|DELETE|INSERT)\b/',$sql)&&++$s->writes===$s->failAt){return false;}
  $fail=$s->failure!==''&&strpos($sql,$s->failure)===0;if($fail&&!$s->lostAck){return false;}
  // Explicit protocol emulation, not evidence for native storage/row locks.
  if(strpos($sql,'SET SESSION ')===0){return true;}
  if(strpos($sql,'SELECT ENGINE, ROW_FORMAT, TABLE_COLLATION FROM information_schema.')===0){return new RoleRows(array(array('ENGINE'=>$s->modern?'InnoDB':'MyISAM','ROW_FORMAT'=>'Dynamic','TABLE_COLLATION'=>'utf8mb4_unicode_ci')));}
  $query=$sql==='START TRANSACTION'?'BEGIN':preg_replace('/ (FOR UPDATE|LOCK IN SHARE MODE)$/D','',$sql);
  try{$r=$this->pdo->query($query);$this->affected=$r->rowCount();if($fail){return false;}return preg_match('/^SELECT/',$sql)?new RoleRows($r->fetchAll(PDO::FETCH_ASSOC)):true;}catch(PDOException $e){return false;}
 }
 function sql_fetchrow($r){return array_shift($r->rows);}
 function sql_fetchrowset($r){return $r->rows;}
 function sql_freeresult($r){}
 function sql_affectedrows(){return $this->affected;}
 function sql_escape($s){return substr($this->pdo->quote($s),1,-1);}
 function sql_close(){if($this->server->owner===$this){try{$this->pdo->exec('ROLLBACK');}catch(PDOException $e){}$this->server->owner=null;}$this->closed=true;$this->db_connect_id=false;}
}
function role_fixture($actor=1,$direction=''){
 global $role_server,$userdata,$phpEx,$phpbb_root_path,$root;
 $role_server=new RoleServer();$userdata=array('user_id'=>$actor,'user_level'=>ADMIN,'session_logged_in'=>true,'session_admin'=>true,'session_id'=>'fixture-sid');
 $role_server->pdo->exec("UPDATE fixture_sessions SET session_id='fixture-sid' WHERE session_user_id=".(int)$actor);
 if($actor===20){$role_server->pdo->exec("INSERT INTO fixture_junior VALUES (20,'".md5('GeneralDB_Maintenanceadmin_db_maintenance.php')."'); INSERT INTO fixture_sessions VALUES ('other-self',20,1,1)");}
 if($direction==='promote'){$role_server->pdo->exec('INSERT INTO fixture_members VALUES (20,10,0)');}
 if($direction==='demote'){$role_server->pdo->exec('UPDATE fixture_users SET user_level=2 WHERE user_id=20');}
 $_SERVER['REQUEST_METHOD']='POST';$_POST=array('sid'=>'fixture-sid');$phpEx='php';$phpbb_root_path=$root;
}
function role_value($sql){return (int)$GLOBALS['role_server']->pdo->query($sql)->fetchColumn();}
function role_snap(){$out=array();foreach(array('users','groups','members','auth','forums','sessions','junior','config') as $s){$out[$s]=$GLOBALS['role_server']->pdo->query('SELECT * FROM fixture_'.$s.' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);}return $out;}
function role_run($expected='',$post=null){
 $caught='';$result=null;try{$result=dbmtnc_synchronize_mod_state(new RoleForum(),$post===null?$_POST:$post);}catch(PhpbbAclException $e){$caught=$e->getMessage();}
 role_check($caught===$expected,'Expected role synchronization outcome: '.$caught.' / '.$expected);role_check($GLOBALS['role_server']->owner===null,'Owner released on success/failure');return $result;
}
$controller=file_get_contents($root.'admin/admin_db_maintenance.php');$a=strpos($controller,"case 'synchronize_mod_state':");$b=strpos($controller,"case 'reset_date':",$a);
role_check($a!==false&&$b>$a,'Actual controller branch found');$branch='switch("synchronize_mod_state"){'.substr($controller,$a,$b-$a).'}';
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{
 foreach(array('english','german') as $locale){
  $lang=array('Not_Authorised'=>'not-authorized','Session_invalid'=>'session-invalid','Attachment_storage_busy'=>'busy','Acl_storage_failed'=>'storage');$phpEx='php';include $root.'language/lang_'.$locale.'/lang_dbmtnc.php';
  foreach(array(array(1,''),array(20,''),array(20,'promote'),array(20,'demote')) as $setup){
   list($actor,$direction)=$setup;role_fixture($actor,$direction);$before=role_snap();$out=role_run();$after=role_snap();$writes=$role_server->writes;
   role_check(count($out['changed'])===($direction===''?7:8)&&!$out['skipped'],'Only inconsistent USER/MOD flags change');
   foreach(array(-1=>2,0=>2,1=>1,2=>1,10=>2,11=>0,12=>2,13=>0,14=>0,15=>0,16=>0,17=>2,18=>3,20=>$direction==='promote'?2:0) as $id=>$level){role_check(role_value('SELECT user_level FROM fixture_users WHERE user_id='.$id)===$level,'Expected derived role for '.$id);}
   role_check(role_value("SELECT COUNT(*) FROM fixture_sessions WHERE session_id='fixture-sid'")===1,'Current ACP session preserved');
   role_check($before['members']===$after['members'],'Memberships unchanged');
   $out=role_run();role_check(!$out['changed']&&!$out['skipped']&&role_snap()===$after,'Repeat synchronization is a no-op');
   for($n=1;$n<=$writes;$n++){role_fixture($actor,$direction);$before=role_snap();$role_server->failAt=$n;role_run($lang['Maintenance_role_sync_failed']);role_check(role_snap()===$before,'All roles and sessions roll back at every failed write');}
   foreach(array('COMMIT','UPDATE fixture_users','DELETE FROM fixture_sessions','SAVEPOINT','RELEASE SAVEPOINT') as $failure){foreach(array(false,true) as $lost){
    role_fixture($actor,$direction);$before=role_snap();$role_server->failure=$failure;$role_server->lostAck=$lost;role_run($lang['Maintenance_role_sync_failed']);
    role_check(role_snap()===($failure==='COMMIT'&&$lost?$after:$before),'Only committed lost acknowledgement may persist the complete repair');
    $role_server->failure='';role_run();role_check(role_snap()===$after,'Safe complete retry');
   }}
  }
  foreach(array('get','wrong-sid','array-sid','inactive','demoted','no-admin-session','missing','foreign','logout','admin-lost','case','wrong-module','legacy') as $case){
   role_fixture($case==='wrong-module'?20:1);$expected=$lang['Not_Authorised'];$p=$role_server->pdo;
   if($case==='get'){$_SERVER['REQUEST_METHOD']='GET';$expected=$lang['Session_invalid'];}
   if($case==='wrong-sid'){$_POST['sid']='wrong';$expected=$lang['Session_invalid'];}
   if($case==='array-sid'){$_POST['sid']=array();$expected=$lang['Session_invalid'];}
   if($case==='inactive'){$p->exec('UPDATE fixture_users SET user_active=0 WHERE user_id=1');}
   if($case==='demoted'){$p->exec('UPDATE fixture_users SET user_level=0 WHERE user_id=1');}
   if($case==='no-admin-session'){$userdata['session_admin']=false;}
   if($case==='wrong-module'){$p->exec("UPDATE fixture_junior SET user_jr_admin='".md5('GroupsPermissionsadmin_ug_auth.php?mode=group')."'");}
   $changes=array('missing'=>"DELETE FROM fixture_sessions WHERE session_id='fixture-sid'",'foreign'=>"UPDATE fixture_sessions SET session_user_id=20 WHERE session_id='fixture-sid'",'logout'=>"UPDATE fixture_sessions SET session_logged_in=0 WHERE session_id='fixture-sid'",'admin-lost'=>"UPDATE fixture_sessions SET session_admin=0 WHERE session_id='fixture-sid'",'case'=>"UPDATE fixture_sessions SET session_id='FIXTURE-SID' WHERE session_id='fixture-sid'");
   if(isset($changes[$case])){$p->exec($changes[$case]);}
   if($case==='legacy'){$role_server->modern=false;$expected=$lang['Acl_storage_failed'];}
   $before=role_snap();role_run($expected);role_check(role_snap()===$before,'Rejected request cannot alter roles/sessions');
  }
  role_fixture();$role_server->failure='lock';$before=role_snap();role_run($lang['Attachment_storage_busy']);role_check(role_snap()===$before,'Contended owner leaves data unchanged');
  foreach(array(false,true) as $fail){foreach(array(0,1) as $disabled){
   role_fixture();if($fail){$role_server->failure='UPDATE fixture_users';}$role_server->pdo->exec("UPDATE fixture_config SET config_value='".$disabled."'");$before=role_snap();
   $db=new RoleForum();$caught='';ob_start();try{eval($branch);}catch(RoleControllerFailure $e){$caught=$e->getMessage();}finally{$html=ob_get_clean();}
   role_check(role_value('SELECT config_value FROM fixture_config')===$disabled,'Controller never changes board availability');
   role_check(($caught!=='')===$fail,'Controller reports storage failure');
   if($fail){role_check(role_snap()===$before&&strpos($html,'<li>')===false,'No partial storage or success list on failure');}
   else{role_check(strpos($html,'&lt;script&gt;Grüße&lt;/script&gt;')!==false&&strpos($html,'<script>')===false,'Actual controller escapes account labels');}
  }}
  echo 'SQLite protocol/logic '.$locale." moderator synchronization passed; native suite covers real concurrency.\n";
 }
}finally{restore_error_handler();}
