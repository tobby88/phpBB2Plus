<?php
if (PHP_SAPI !== 'cli' || getenv('PHPBB_CT_RECOVERY_NATIVE') !== '1') { echo "Native CrackerTracker authority fixture skipped.\n"; return; }
define('IN_PHPBB',true);define('CTRACKER_ACP',true);define('ADMIN',1);
define('CONFIG_TABLE','fixture_config');define('CTRACKER_BACKUP','fixture_backup');
define('CTRACKER_FILECHK','fixture_filechk');define('CTRACKER_FILESCANNER','fixture_filescanner');
define('CTRACKER_CONFIG','fixture_ct_config');
define('USERS_TABLE','fixture_users');define('SESSIONS_TABLE','fixture_sessions');define('JR_ADMIN_TABLE','fixture_jr');
define('GENERAL_ERROR',1);define('GENERAL_MESSAGE',2);define('CRITICAL_ERROR',3);define('END_TRANSACTION',2);
class CtAuthorityExit extends RuntimeException {}
function message_die($level,$message) { throw new CtAuthorityExit($message); }
function ca_check($ok,$message) { if (!$ok) { throw new RuntimeException($message); } }
$source=dirname(dirname(__DIR__)).'/phpBB2/';
require $source.'db/mysqli.php';require $source.'ctracker/classes/class_ct_adminfunctions.php';
require $source.'includes/functions_jr_admin.php';
$port=getenv('PHPBB_CT_RECOVERY_TEST_PORT')?:'3306';
ca_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Fixture port');
$host='127.0.0.1:'.$port;$password=getenv('PHPBB_CT_RECOVERY_TEST_PASSWORD')?:'';
$control=new sql_db($host,'root',$password,'',false);ca_check($control->db_connect_id,'Loopback fixture connection');
$fixture='codex_ct_authority_'.bin2hex(function_exists('random_bytes')?random_bytes(8):openssl_random_pseudo_bytes(8));
ca_check($control->sql_query('CREATE DATABASE '.$fixture.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Create isolated fixture');
class CtAuthorityConnection {
 var $connection;var $db_connect_id;
 function __construct($connection){$this->connection=$connection;$this->db_connect_id=$connection->db_connect_id;}
 function __call($name,$args){return call_user_func_array(array($this->connection,$name),$args);}
 function sql_query($sql,$transaction=false){
  $GLOBALS['ca_queries'][]=$sql;
  if(is_callable($GLOBALS['ca_hook'])){call_user_func($GLOBALS['ca_hook'],$sql,$this->connection);}
  if($GLOBALS['ca_failure']!==''&&strpos($sql,$GLOBALS['ca_failure'])===0){return false;}
  $result=$this->connection->sql_query($sql,$transaction);
  if(!$result){$GLOBALS['ca_sql_error']=$this->connection->sql_error();}
  return $result;
 }
}
class CtAuthorityForumDatabase extends sql_db {
 function sql_dedicated_connection(){return new CtAuthorityConnection(parent::sql_dedicated_connection());}
}
$db=new CtAuthorityForumDatabase($host,'root',$password,$fixture,false);unset($db->password);
$peer=new sql_db($host,'root',$password,$fixture,false);
$root=sys_get_temp_dir().'/'.$fixture;mkdir($root,0700);mkdir($root.'/cache',0700);
file_put_contents($root.'/source.php',"<?php define('IN_PHPBB',true); echo 'fixture';");
$phpbb_root_path=$root.'/';$phpEx='php';
$lang=array('ctracker_error_database_op'=>'database','ctracker_error_loading_config'=>'load','ctracker_scan_busy'=>'busy',
 'ctracker_recovery_busy'=>'busy','ctracker_error_storage_migration'=>'storage','ctracker_error_fileop'=>'file',
 'ctracker_rec_never_saved'=>'empty','ctracker_rec_transaction_required'=>'engine');
$ca_hook=null;$ca_failure='';$ca_queries=array();
function ca_sql($sql){$r=$GLOBALS['peer']->sql_query($sql);ca_check($r,'Fixture SQL failed');return $r;}
function ca_snapshot(){
 $result=array();
 foreach(array(CONFIG_TABLE,CTRACKER_BACKUP,CTRACKER_FILECHK,CTRACKER_FILESCANNER,CTRACKER_CONFIG) as $table){
  $r=ca_sql('SELECT * FROM '.$table.' ORDER BY 1');$result[$table]=$GLOBALS['peer']->sql_fetchrowset($r);$GLOBALS['peer']->sql_freeresult($r);
 }
 return $result;
}
function ca_reset($operation,$actor){
 global $userdata,$ca_hook,$ca_queries,$ca_failure;
 $ca_hook=null;$ca_failure='';$ca_queries=array();
 foreach(array(CONFIG_TABLE,CTRACKER_BACKUP,CTRACKER_FILECHK,CTRACKER_FILESCANNER,CTRACKER_CONFIG,USERS_TABLE,SESSIONS_TABLE,JR_ADMIN_TABLE) as $table){ca_sql('DELETE FROM '.$table);}
 ca_sql("INSERT INTO fixture_ct_config VALUES ('last_file_scan','123'),('last_checksum_scan','123')");
 ca_sql("INSERT INTO fixture_config VALUES ('site_desc','current'),('version','.0.23'),('board_disable','1')");
 ca_sql("INSERT INTO fixture_backup VALUES ('site_desc','saved'),('version','.0.21'),('board_disable','0'),('ct_last_backup','123')");
 ca_sql("INSERT INTO fixture_filechk VALUES ('old.php','old')");ca_sql("INSERT INTO fixture_filescanner VALUES (1,'old.php',10)");
 ca_sql('INSERT INTO fixture_users VALUES (1,'.($actor==='root'?1:0).',1)');
 ca_sql("INSERT INTO fixture_sessions VALUES ('fixture-admin',1,1,1)");
 $route=$operation==='auto'?'admin_board.php':('admin_cracker_tracker.php?modu='.($operation==='hash'?'1':($operation==='scan'?'3':'10')));
 $routes=jr_admin_authorization_routes();$hash=array_search($route,$routes,true);ca_check($hash!==false,'Actual registered ACP route');
 if($actor==='delegated'){ca_sql("INSERT INTO fixture_jr VALUES (1,'".$hash."')");}
 $userdata=array('user_id'=>1,'session_id'=>'fixture-admin','session_logged_in'=>1,'session_admin'=>1,'user_level'=>$actor==='root'?1:0);
 $_SERVER['REQUEST_METHOD']='POST';$_POST=array('sid'=>'fixture-admin');
}
function ca_run($operation){
 $admin=new ct_adminfunctions();
 try{
  if($operation==='backup'||$operation==='auto'){$admin->recover_configuration($operation==='auto'?'board':'ctracker');}
  elseif($operation==='restore'){$admin->restore_configuration();}
  elseif($operation==='hash'){$admin->do_filechk();}
  else{$admin->RunFileScan($GLOBALS['phpbb_root_path'],'php');}
 }finally{
  // mysqli_close sends QUIT without waiting for server-side rollback/lock
  // cleanup. Synchronize consecutive fixture requests, without retrying an
  // operation or accepting a busy response in place of an authority denial.
  $table=$operation==='hash'?CTRACKER_FILECHK:($operation==='scan'?CTRACKER_FILESCANNER:CTRACKER_BACKUP);
  $name='ctscan:'.md5($GLOBALS['fixture']."\0".$table);
  $result=ca_sql("SELECT GET_LOCK('".$name."',5) AS released");
  $row=$GLOBALS['peer']->sql_fetchrow($result);$GLOBALS['peer']->sql_freeresult($result);
  ca_check($row&&(int)$row['released']===1,'Owned operation connection releases its lock');
  $result=ca_sql("SELECT RELEASE_LOCK('".$name."')");$GLOBALS['peer']->sql_freeresult($result);
 }
}
function ca_revocation($kind){
 $changes=array('inactive'=>'UPDATE fixture_users SET user_active=0','role'=>'UPDATE fixture_users SET user_level=0',
  'missing'=>'DELETE FROM fixture_sessions','logout'=>'UPDATE fixture_sessions SET session_logged_in=0',
  'admin-off'=>'UPDATE fixture_sessions SET session_admin=0','foreign'=>'UPDATE fixture_sessions SET session_user_id=99',
  'case'=>"UPDATE fixture_sessions SET session_id='FIXTURE-ADMIN'",'grant'=>'DELETE FROM fixture_jr');
 return $changes[$kind];
}
function ca_expect_denied($operation){
 try{ca_run($operation);throw new RuntimeException('Revoked operation accepted');}
 catch(CtAuthorityExit $e){ca_check(in_array($e->getMessage(),array('Not_Authorised','Session_invalid'),true),'Current authority rejection: '.$e->getMessage());}
}
function ca_boundary($sql) { return preg_match('/^(CREATE|DROP|INSERT|UPDATE|DELETE|COMMIT)\b/',$sql) || strpos($sql,' LOCK IN SHARE MODE')!==false; }
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{
 $schema=file_get_contents($source.'install/schemas/mysql_schema.sql');
 foreach(array('config','ctracker_config','ctracker_filechk','ctracker_filescanner') as $table){
  ca_check(preg_match('/CREATE TABLE `?phpbb_'.$table.'`?\s*\([\s\S]*?;/',$schema,$m)===1,'Canonical operation schema');
  ca_sql(str_replace('phpbb_'.$table,$table==='config'?CONFIG_TABLE:($table==='ctracker_config'?CTRACKER_CONFIG:'fixture_'.substr($table,9)),$m[0]));
 }
 ca_sql('CREATE TABLE fixture_backup LIKE fixture_config');
 ca_sql('CREATE TABLE fixture_users (user_id INT PRIMARY KEY,user_level INT,user_active INT) ENGINE=InnoDB');
 ca_sql('CREATE TABLE fixture_sessions (session_id VARCHAR(32) PRIMARY KEY,session_user_id INT,session_logged_in INT,session_admin INT) ENGINE=InnoDB');
 ca_sql('CREATE TABLE fixture_jr (user_id INT PRIMARY KEY,user_jr_admin TEXT) ENGINE=InnoDB');
 ca_sql('SET SESSION innodb_lock_wait_timeout=1');
 $cases=0;$serialized=0;
 foreach(array('backup','auto','restore','hash','scan') as $operation){foreach(array('root','delegated') as $actor){
  ca_reset($operation,$actor);$before=ca_snapshot();ca_run($operation);ca_check(ca_snapshot()!==$before,'Authorized operation publishes');
  $writes=array_values(array_filter($ca_queries,'ca_boundary'));
  ca_check(count($writes)>0,'Actual write boundaries captured');
  $kinds=array('inactive','missing','logout','admin-off','foreign','case',$actor==='root'?'role':'grant');
  foreach($kinds as $kind){
   ca_reset($operation,$actor);ca_sql(ca_revocation($kind));$snapshot=ca_snapshot();ca_expect_denied($operation);
   ca_check($snapshot===ca_snapshot(),'Entry revocation leaves every active table intact');$cases++;
  }
  // Revoke the live session immediately before every actual statement, not
  // merely before an application-level authorization call.
  foreach(array('missing',$actor==='root'?'role':'grant') as $mid_kind){for($boundary=1;$boundary<=count($writes);$boundary++){
   ca_reset($operation,$actor);$seen=0;$reached=false;$snapshot=null;$blocked=false;
   $ca_hook=function($sql)use($boundary,$mid_kind,&$seen,&$reached,&$snapshot,&$blocked){
    if(!ca_boundary($sql)||++$seen!==$boundary){return;}
    $GLOBALS['ca_hook']=null;$reached=true;$snapshot=ca_snapshot();
    if(!$GLOBALS['peer']->sql_query(ca_revocation($mid_kind))){
     $error=$GLOBALS['peer']->sql_error();ca_check((int)$error['code']===1205,'Only actual lock serialization may defer revocation');$blocked=true;
    }
   };
   $denied=false;try{ca_run($operation);}catch(CtAuthorityExit $e){$denied=true;ca_check($e->getMessage()==='Not_Authorised','Expected session rejection: '.$operation.' '.$actor.' #'.$boundary.' '.$e->getMessage().' '.json_encode(isset($GLOBALS['ca_sql_error'])?$GLOBALS['ca_sql_error']:array()).' '.$e->getTraceAsString());}
   ca_check($reached,'Captured write boundary reached');
   if($blocked){ca_check(!$denied,'Operation serialized before authority revocation');ca_sql(ca_revocation($mid_kind));$serialized++;}
   else{ca_check($denied&&$snapshot===ca_snapshot(),'No active state changes after session revocation: '.$operation.' #'.$boundary);}
   ca_expect_denied($operation);$cases++;
  }}
  // A failure after the live DELETE must roll back the complete publication.
  ca_reset($operation,$actor);$snapshot=ca_snapshot();
  $target=$operation==='restore'?CONFIG_TABLE:($operation==='hash'?CTRACKER_FILECHK:($operation==='scan'?CTRACKER_FILESCANNER:CTRACKER_BACKUP));
  $ca_failure='INSERT INTO '.$target.' ';
  try{ca_run($operation);throw new RuntimeException('Injected publication failure accepted');}
  catch(CtAuthorityExit $e){ca_check($e->getMessage()==='database','Expected storage failure');}
  ca_check(ca_snapshot()===$snapshot,'Publication failure rolls back previous rows');$ca_failure='';
  ca_reset($operation,$actor);$_POST['sid']=array('bad');$snapshot=ca_snapshot();ca_expect_denied($operation);ca_check($snapshot===ca_snapshot(),'Malformed form leaves data intact');
  echo $operation.' '.$actor." authority boundaries passed\n";
 }}
 foreach(array(array('auto','backup'),array('backup','auto'),array('hash','scan'),array('scan','hash')) as $routes){
  ca_reset($routes[0],'delegated');$snapshot=ca_snapshot();ca_expect_denied($routes[1]);ca_check($snapshot===ca_snapshot(),'Delegated routes cannot authorize adjacent modules');
 }
 echo 'Native CrackerTracker authority checks: '.$cases.' cases; '.$serialized." serialized before revocation\n";
}finally{
 $ca_hook=null;$ca_failure='';$db->sql_close();$peer->sql_close();$control->sql_query('DROP DATABASE '.$fixture);$control->sql_close();
 unlink($root.'/source.php');rmdir($root.'/cache');rmdir($root);restore_error_handler();
}
