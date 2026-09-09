<?php
$root=dirname(dirname(__DIR__)).'/phpBB2/';
foreach(array('IN_PHPBB'=>true,'ADMIN'=>1,'MOD'=>2,'USER'=>0,'GENERAL_ERROR'=>202,'ATTACHMENTS_TABLE'=>'fixture_links','USERS_TABLE'=>'fixture_users','GROUPS_TABLE'=>'fixture_groups','USER_GROUP_TABLE'=>'fixture_members','AUTH_ACCESS_TABLE'=>'fixture_auth','FORUMS_TABLE'=>'fixture_forums','SESSIONS_TABLE'=>'fixture_sessions','JR_ADMIN_TABLE'=>'fixture_junior') as $key=>$value){define($key,$value);}
require_once $root.'includes/functions_maintenance_roles.php';
function role_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
function message_die($code,$message){throw new RuntimeException($message);}
class RoleControllerFailure extends RuntimeException {}
function throw_error($message){throw new RoleControllerFailure($message);}
function lock_db($unlock=false){$GLOBALS['board_locks'][]=$unlock;}
class RoleRows {public $rows;function __construct($rows){$this->rows=$rows;}}
$dsn=getenv('PHPBB_ROLE_TEST_DSN');$native=$dsn!==false&&$dsn!=='';
if($native){role_check(preg_match('/^mysql:host=127\.0\.0\.1;port=33119;dbname=codex_roles_[a-f0-9]{16};charset=utf8mb4$/D',$dsn)===1,'Only owned local role schemas allowed');}
class RoleServer {
 public $pdo;public $owner=null;public $hook=null;public $failure='';public $queries=array();
 function __construct($engine){
  $this->pdo=$GLOBALS['native']?new PDO($GLOBALS['dsn'],'root',''):new PDO('sqlite::memory:');$this->pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
  $definitions=array('users'=>'user_id INTEGER PRIMARY KEY,username VARCHAR(255),user_level INTEGER,user_active INTEGER','groups'=>'group_id INTEGER PRIMARY KEY','members'=>'user_id INTEGER,group_id INTEGER,user_pending INTEGER','auth'=>'group_id INTEGER,forum_id INTEGER,auth_mod INTEGER','forums'=>'forum_id INTEGER PRIMARY KEY','sessions'=>'session_user_id INTEGER','junior'=>'user_id INTEGER,user_jr_admin VARCHAR(255)');
  foreach($definitions as $name=>$definition){$this->pdo->exec('DROP TABLE IF EXISTS fixture_'.$name);$this->pdo->exec('CREATE TABLE fixture_'.$name.' ('.$definition.')'.($GLOBALS['native']?' ENGINE='.$engine:''));}
  $this->pdo->exec("INSERT INTO fixture_users VALUES (-1,'Anonymous',2,1),(0,'Zero',2,1),(1,'Root',1,1),(2,'Other admin',1,1),(10,'<script>Grüße</script>',0,1),(11,'Stale moderator',2,1),(12,'Valid moderator',2,1),(13,'Pending',2,1),(14,'Orphan group',2,1),(15,'Orphan forum',2,1),(16,'Invalid pending flag',2,1),(17,'Inactive',0,0),(18,'Special role',3,1),(20,'Junior',0,1)");
  $this->pdo->exec('INSERT INTO fixture_groups VALUES (10),(11)');$this->pdo->exec('INSERT INTO fixture_forums VALUES (5)');
  $this->pdo->exec('INSERT INTO fixture_auth VALUES (10,5,1),(11,999,1),(999,5,1)');
  $this->pdo->exec('INSERT INTO fixture_members VALUES (10,10,0),(12,10,0),(13,10,1),(14,999,0),(15,11,0),(16,10,2),(17,10,0)');
  $this->pdo->exec('INSERT INTO fixture_sessions SELECT user_id FROM fixture_users');
 }
}
class RoleForum {
 public $dbname='role-fixture';
 function sql_query($sql){throw new RuntimeException('Unlocked main connection used');}
 function sql_dedicated_connection(){return new RoleConnection($GLOBALS['role_server']);}
}
class RoleConnection {
 public $server;public $pdo;public $db_connect_id=true;public $closed=false;public $affected=0;
 function __construct($server){$this->server=$server;$this->pdo=$GLOBALS['native']?new PDO($GLOBALS['dsn'],'root','',array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION)):$server->pdo;}
 function sql_query($sql){
  if($this->closed){return false;}$s=$this->server;$s->queries[]=$sql;
  if(strpos($sql,'SELECT GET_LOCK(')===0){
   if($s->failure==='lock'){return new RoleRows(array(array('acquired'=>0)));}
   $rows=$GLOBALS['native']?$this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC):array(array('acquired'=>$s->owner===null?1:0));
   if((int)$rows[0]['acquired']===1){$s->owner=$this;}return new RoleRows($rows);
  }
  role_check($s->owner===$this,'Every role/ACL query uses the owning connection');
  if(is_callable($s->hook)){call_user_func($s->hook,$sql,$this);}
  if($this->closed||($s->failure!==''&&strpos($sql,$s->failure)===0)){return false;}
  try{$r=$this->pdo->query($sql);$this->affected=$r->rowCount();return preg_match('/^SELECT/',$sql)?new RoleRows($r->fetchAll(PDO::FETCH_ASSOC)):true;}catch(PDOException $e){return false;}
 }
 function sql_fetchrow($r){return array_shift($r->rows);}
 function sql_fetchrowset($r){return $r->rows;}
 function sql_freeresult($r){}
 function sql_affectedrows(){return $this->affected;}
 function sql_escape($s){return substr($this->pdo->quote($s),1,-1);}
 function sql_close(){if($this->server->owner===$this){$this->server->owner=null;}$this->closed=true;$this->db_connect_id=false;$this->pdo=null;}
}
function role_fixture($engine,$actor=1){
 global $role_server,$userdata,$phpEx,$phpbb_root_path,$root;
 $role_server=new RoleServer($engine);$userdata=array('user_id'=>$actor,'user_level'=>ADMIN,'session_logged_in'=>true,'session_admin'=>true,'session_id'=>'fixture-sid');
 $_SERVER['REQUEST_METHOD']='POST';$_POST=array('sid'=>'fixture-sid');$phpEx='php';$phpbb_root_path=$root;
}
function role_value($sql){return (int)$GLOBALS['role_server']->pdo->query($sql)->fetchColumn();}
function role_run($expected='',$post=null){
 $caught='';$result=null;try{$result=dbmtnc_synchronize_mod_state(new RoleForum(),$post===null?$_POST:$post);}catch(PhpbbAclException $e){$caught=$e->getMessage();}
 role_check($caught===$expected,'Expected role synchronization outcome: '.$caught.' / '.$expected);role_check($GLOBALS['role_server']->owner===null,'Owner released on success/failure');return $result;
}
$controller=file_get_contents($root.'admin/admin_db_maintenance.php');$a=strpos($controller,"case 'synchronize_mod_state':");$b=strpos($controller,"case 'reset_date':",$a);
role_check($a!==false&&$b>$a,'Actual controller branch found');$branch='switch("synchronize_mod_state"){'.substr($controller,$a,$b-$a).'}';
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{
 foreach($native?array('MyISAM','InnoDB'):array('SQLite') as $engine){foreach(array('english','german') as $locale){
  $lang=array('Not_Authorised'=>'not-authorized','Session_invalid'=>'session-invalid','Attachment_storage_busy'=>'busy');$phpEx='php';include $root.'language/lang_'.$locale.'/lang_dbmtnc.php';
  role_fixture($engine);$before=$role_server->pdo->query('SELECT * FROM fixture_members')->fetchAll(PDO::FETCH_ASSOC);$out=role_run();
  role_check(count($out['changed'])===7&&count($out['skipped'])===0,'Only seven inconsistent USER/MOD flags change');
  foreach(array(-1=>2,0=>2,1=>1,2=>1,10=>2,11=>0,12=>2,13=>0,14=>0,15=>0,16=>0,17=>2,18=>3,20=>0) as $id=>$level){role_check(role_value('SELECT user_level FROM fixture_users WHERE user_id='.$id)===$level,'Expected derived role for '.$id);}
  role_check(role_value('SELECT COUNT(*) FROM fixture_sessions')===7,'Only changed accounts lose cached sessions');
  role_check($before===$role_server->pdo->query('SELECT * FROM fixture_members')->fetchAll(PDO::FETCH_ASSOC),'Memberships unchanged');
  $out=role_run();role_check(!$out['changed']&&!$out['skipped']&&role_value('SELECT COUNT(*) FROM fixture_sessions')===7,'Repeat synchronization is a no-op');
  foreach(array('promotion-before-delete','promotion-before-update','new-grant','revoked-grant','removed-user','actor-before-delete','actor-before-update','lost-owner') as $race){
   role_fixture($engine);$role_server->hook=function($sql,$connection) use($race){
    $update=in_array($race,array('promotion-before-update','actor-before-update'),true);$prefix=$update?'UPDATE fixture_users SET user_level = CASE':'DELETE FROM fixture_sessions';
    if(strpos($sql,$prefix)!==0){return;}$s=$GLOBALS['role_server'];$s->hook=null;
    if($race==='lost-owner'){$connection->sql_close();return;}
    if(strpos($race,'promotion')===0){$s->pdo->exec('UPDATE fixture_users SET user_level=1 WHERE user_id=10');}
    elseif($race==='new-grant'){$s->pdo->exec('INSERT INTO fixture_members VALUES (11,10,0)');}
    elseif($race==='revoked-grant'){$s->pdo->exec('DELETE FROM fixture_members WHERE user_id=10');}
    elseif($race==='removed-user'){$s->pdo->exec('DELETE FROM fixture_users WHERE user_id=10');}
    else{$s->pdo->exec('UPDATE fixture_users SET user_active=0 WHERE user_id=1');}
   };
   $expected=strpos($race,'actor-')===0?$lang['Not_Authorised']:($race==='lost-owner'?$lang['Maintenance_role_sync_failed']:'');$out=role_run($expected);
   if(strpos($race,'promotion')===0){role_check(role_value('SELECT user_level FROM fixture_users WHERE user_id=10')===ADMIN,'Late ADMIN promotion survives');if($race==='promotion-before-delete'){role_check(role_value('SELECT COUNT(*) FROM fixture_sessions WHERE session_user_id=10')===1,'New admin session preserved');}}
   if($race==='new-grant'){role_check(role_value('SELECT user_level FROM fixture_users WHERE user_id=11')===MOD,'New current grant prevents stale demotion');}
   if($race==='revoked-grant'){role_check(role_value('SELECT user_level FROM fixture_users WHERE user_id=10')===USER,'Revoked current grant prevents stale promotion');}
   if($race==='removed-user'){role_check(role_value('SELECT COUNT(*) FROM fixture_users WHERE user_id=10')===0,'Removed user not recreated');}
   if(strpos($race,'actor-')===0){role_check(role_value('SELECT user_level FROM fixture_users WHERE user_id=10')===USER,'Revoked actor cannot publish role change');}
  }
  foreach(array('lock','SELECT role_user','DELETE FROM fixture_sessions','UPDATE fixture_users') as $failure){role_fixture($engine);$role_server->failure=$failure;role_run($failure==='lock'?$lang['Attachment_storage_busy']:$lang['Maintenance_role_sync_failed']);}
  foreach(array('get','wrong-sid','array-sid','inactive','demoted','no-admin-session') as $case){
   role_fixture($engine);$expected=$lang['Not_Authorised'];
   if($case==='get'){$_SERVER['REQUEST_METHOD']='GET';$expected=$lang['Session_invalid'];}
   if($case==='wrong-sid'){$_POST['sid']='wrong';$expected=$lang['Session_invalid'];}
   if($case==='array-sid'){$_POST['sid']=array();$expected=$lang['Session_invalid'];}
   if($case==='inactive'){$role_server->pdo->exec('UPDATE fixture_users SET user_active=0 WHERE user_id=1');}
   if($case==='demoted'){$role_server->pdo->exec('UPDATE fixture_users SET user_level=0 WHERE user_id=1');}
   if($case==='no-admin-session'){$userdata['session_admin']=false;}
   role_run($expected);role_check(role_value('SELECT COUNT(*) FROM fixture_sessions')===14,'Denied request leaves sessions untouched');
  }
  role_fixture($engine,20);$hash=md5('GeneralDB_Maintenanceadmin_db_maintenance.php');$role_server->pdo->exec("INSERT INTO fixture_junior VALUES (20,'".$hash."')");role_run();
  role_fixture($engine,20);$wrong=md5('GroupsPermissionsadmin_ug_auth.php?mode=group');$role_server->pdo->exec("INSERT INTO fixture_junior VALUES (20,'".$wrong."')");role_run($lang['Not_Authorised']);
  role_fixture($engine,20);$role_server->pdo->exec("INSERT INTO fixture_junior VALUES (20,'".$hash."')");$role_server->hook=function($sql){if(strpos($sql,'DELETE FROM fixture_sessions')===0){$GLOBALS['role_server']->hook=null;$GLOBALS['role_server']->pdo->exec('DELETE FROM fixture_junior');}};role_run($lang['Not_Authorised']);
  role_check(role_value('SELECT COUNT(*) FROM fixture_sessions')===14,'Delegation revocation guards session mutation');
  role_fixture($engine);$contended=false;$role_server->hook=function($sql) use(&$contended){if(strpos($sql,'SELECT role_user')===0){$GLOBALS['role_server']->hook=null;$other=new attach_mutation_lock(new RoleForum(),false);$contended=!$other->acquired;$other->release();}};role_run();role_check($contended,'Competing writer cannot acquire lock during role snapshot');
  foreach(array(false,true) as $fail){role_fixture($engine);if($fail){$role_server->failure='UPDATE fixture_users';}$db=new RoleForum();$board_locks=array();$caught='';ob_start();try{eval($branch);}catch(RoleControllerFailure $e){$caught=$e->getMessage();}finally{$html=ob_get_clean();}
   role_check($board_locks===array(false,true),'Controller restores maintenance setting on success/failure');role_check(($caught!=='')===$fail,'Controller reports service failures');if(!$fail){role_check(strpos($html,'&lt;script&gt;Grüße&lt;/script&gt;')!==false&&strpos($html,'<script>')===false,'Actual controller escapes displayed usernames');}
  }
  echo $engine.' '.$locale." moderator role synchronization passed.\n";
 }}
}finally{restore_error_handler();}
