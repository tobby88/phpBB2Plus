<?php
$root=dirname(dirname(__DIR__)).'/phpBB2/';
foreach(array('IN_PHPBB'=>true,'ADMIN'=>1,'MOD'=>2,'USER'=>0,'ATTACHMENTS_TABLE'=>'fixture_links','USERS_TABLE'=>'fixture_users','JR_ADMIN_TABLE'=>'fixture_junior','SESSIONS_TABLE'=>'fixture_sessions') as $key=>$value){define($key,$value);}
require_once $root.'includes/functions_maintenance_session_storage.php';
function table_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
class TableControllerFailure extends RuntimeException {}
function throw_error($message){table_check($GLOBALS['table_server']->owner===null&&$GLOBALS['db'] instanceof TableForum,'Release writer before terminal error renderer');throw new TableControllerFailure($message);}
function message_die($code,$message){throw new RuntimeException($message);}
function check_mysql_version(){return true;}
function lock_db($unlock=false){throw new RuntimeException('Table commands must not toggle board availability');}
class TableRows {public $rows;function __construct($rows){$this->rows=$rows;}}
$dsn=getenv('PHPBB_SESSION_STORAGE_DSN');$native=$dsn!==false&&$dsn!=='';
if($native){table_check(preg_match('/^mysql:host=127\.0\.0\.1;port=33119;dbname=codex_session_storage_[a-f0-9]{16};charset=utf8mb4$/D',$dsn)===1,'Only owned loopback schemas allowed');}
class TableServer {
 public $pdo;public $owner=null;public $hook=null;public $after=null;public $failure='';public $lostAck='';public $commands=0;public $gates=0;public $queries=array();public $engine;public $mode='NO_ENGINE_SUBSTITUTION';public $supported=true;public $closedModes=array();
 function __construct($engine){$this->engine=$engine;
  $this->pdo=$GLOBALS['native']?new PDO($GLOBALS['dsn'],'root',''):new PDO('sqlite::memory:');$this->pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
  $defs=array('users'=>'user_id INTEGER PRIMARY KEY,username VARCHAR(255),user_level INTEGER,user_active INTEGER','junior'=>'user_id INTEGER,user_jr_admin VARCHAR(255)',
   'sessions'=>'session_id VARCHAR(32) PRIMARY KEY,session_user_id INTEGER,session_logged_in INTEGER,session_admin INTEGER,custom_flag VARCHAR(30) DEFAULT \'preserve\'','config'=>'board_disable INTEGER',
   'first'=>'id BIGINT PRIMARY KEY,body VARCHAR(255)','second'=>'id BIGINT PRIMARY KEY,body VARCHAR(255)');
  foreach($defs as $name=>$def){$this->pdo->exec('DROP TABLE IF EXISTS fixture_'.$name);$this->pdo->exec('CREATE TABLE fixture_'.$name.' ('.$def.')'.($GLOBALS['native']?' ENGINE='.$engine:''));}
  $this->pdo->exec("INSERT INTO fixture_users VALUES (1,'Root',1,1),(20,'Junior',0,1)");$this->pdo->exec('INSERT INTO fixture_config VALUES (0)');
  foreach(array('first','second') as $name){$this->pdo->exec("INSERT INTO fixture_".$name." VALUES (0,'Grüße'),(4294967298,'wide')");}
 }
}
class TableForum {
 public $dbname='table-fixture';
 function __construct(){if($GLOBALS['native']){preg_match('/dbname=([^;]+)/',$GLOBALS['dsn'],$m);$this->dbname=$m[1];}}
 function sql_query($sql){throw new RuntimeException('Unowned main connection used');}
 function sql_dedicated_connection(){return new TableConnection($GLOBALS['table_server']);}
}
class TableConnection {
 public $server;public $pdo;public $db_connect_id=true;public $closed=false;
 function __construct($server){$this->server=$server;$this->pdo=$GLOBALS['native']?new PDO($GLOBALS['dsn'],'root','',array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION)):$server->pdo;if($GLOBALS['native']){$this->pdo->exec("SET SESSION sql_mode='NO_ENGINE_SUBSTITUTION'");}}
 function sql_query($sql){
  if($this->closed){return false;}$s=$this->server;$s->queries[]=$sql;
  if(strpos($sql,'SELECT GET_LOCK(')===0){
   if($s->failure==='lock'){return new TableRows(array(array('acquired'=>0)));}
   $rows=$GLOBALS['native']?$this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC):array(array('acquired'=>$s->owner===null?1:0));
   if((int)$rows[0]['acquired']===1){$s->owner=$this;}return new TableRows($rows);
  }
  table_check($s->owner===$this,'Every statement uses the current owner');
  if(strpos($sql,'SELECT 1 AS allowed WHERE ')===0){$s->gates++;}
  if(is_callable($s->hook)){call_user_func($s->hook,$sql,$this);}
  if($this->closed||($s->failure!==''&&strpos($sql,$s->failure)===0)){return false;}
  $command=$sql==='ALTER TABLE `fixture_sessions` ENGINE=InnoDB MAX_ROWS=0';
  try{
   if($command){$s->commands++;table_check(strpos($s->mode,'NO_ENGINE_SUBSTITUTION')!==false&&strpos($s->mode,'STRICT_ALL_TABLES')!==false,'Never silently substitute unavailable engines');if($GLOBALS['native']){$this->pdo->exec($sql);}$rows=array();$s->engine='InnoDB';}
   elseif(strpos($sql,'SELECT ENGINE FROM information_schema.TABLES')===0&&!$GLOBALS['native']){$rows=array(array('ENGINE'=>$s->engine));}
   elseif(strpos($sql,'SELECT SUPPORT FROM information_schema.ENGINES')===0){$rows=array(array('SUPPORT'=>$s->supported?'DEFAULT':'NO'));}
   elseif($sql==='SELECT @@SESSION.sql_mode AS sql_mode'&&!$GLOBALS['native']){$rows=array(array('sql_mode'=>$s->mode));}
   elseif(strpos($sql,'SET SESSION sql_mode = ')===0){if($GLOBALS['native']){$this->pdo->exec($sql);}$s->mode=trim(substr($sql,23),"'");$rows=array();}
   elseif($sql==='SHOW TABLE STATUS'&&!$GLOBALS['native']){$rows=array(array('Name'=>'fixture_first','Rows'=>2,'Data_length'=>1024,'Index_length'=>0),array('Name'=>'fixture_second','Rows'=>2,'Data_length'=>1024,'Index_length'=>0));}
   else{$rows=$this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);}
   if(is_callable($s->after)){call_user_func($s->after,$sql,$this);}
   if($s->lostAck!==''&&strpos($sql,$s->lostAck)===0){return false;}return new TableRows($rows);
  }catch(PDOException $e){return false;}
 }
 function sql_fetchrow($r){return array_shift($r->rows);}
 function sql_fetchrowset($r){return $r->rows;}
 function sql_freeresult($r){}
 function sql_escape($s){if($this->closed){throw new RuntimeException('Connection unavailable');}return substr($this->pdo->quote($s),1,-1);}
 function sql_close(){if(!$this->closed){$this->server->closedModes[]=$GLOBALS['native']?$this->pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn():$this->server->mode;}if($this->server->owner===$this){$this->server->owner=null;}$this->closed=true;$this->db_connect_id=false;$this->pdo=null;}
}
function table_fixture($engine,$actor=1){
 global $table_server,$userdata,$phpEx,$phpbb_root_path,$root;
 $table_server=new TableServer($engine);$userdata=array('user_id'=>$actor,'user_level'=>ADMIN,'session_logged_in'=>true,'session_admin'=>true,'session_id'=>'fixture-sid');
 $table_server->pdo->exec("INSERT INTO fixture_sessions (session_id,session_user_id,session_logged_in,session_admin) VALUES ('fixture-sid',".(int)$actor.",1,1)");
 $sessions=array();for($i=0;$i<601;$i++){$sessions[]="('other-".$i."',99,1,0,'Grüße')";}$table_server->pdo->exec('INSERT INTO fixture_sessions VALUES '.implode(',',$sessions));
 $_SERVER['REQUEST_METHOD']='POST';$_POST=array('sid'=>'fixture-sid');$phpEx='php';$phpbb_root_path=$root;
}
function table_revoke($case){
 $pdo=$GLOBALS['table_server']->pdo;
 if($case==='missing'){$pdo->exec("DELETE FROM fixture_sessions WHERE session_id='fixture-sid'");}
 elseif($case==='foreign'){$pdo->exec('UPDATE fixture_sessions SET session_user_id=99');}
 elseif($case==='logged-out'){$pdo->exec('UPDATE fixture_sessions SET session_logged_in=0');}
 elseif($case==='not-admin'){$pdo->exec('UPDATE fixture_sessions SET session_admin=0');}
 elseif($case==='case-changed'){$pdo->exec("UPDATE fixture_sessions SET session_id='FIXTURE-SID' WHERE session_id='fixture-sid'");}
 elseif($case==='actor'){$pdo->exec('UPDATE fixture_users SET user_active=0');}
 elseif($case==='grant'){$pdo->exec('DELETE FROM fixture_junior');}
 else{throw new RuntimeException('Unknown revocation');}
}
function table_snapshot(){return $GLOBALS['table_server']->pdo->query('SELECT * FROM fixture_sessions ORDER BY session_id')->fetchAll(PDO::FETCH_ASSOC);}

$source=file_get_contents($root.'admin/admin_db_maintenance.php');$a=strpos($source,"case 'session_storage':");$b=strpos($source,"case 'unlock_db':",$a);table_check($a!==false&&$b>$a,'Actual controller branch');$branch='switch($function){'.substr($source,$a,$b-$a).'}';
function storage_run($expected='',$restore=true){
 global $db,$lang,$branch,$phpbb_root_path,$phpEx,$table_server,$expected_sessions;
 $original=new TableForum();$db=$original;$function='session_storage';$expected_sessions=table_snapshot();$caught='';$table_server->closedModes=array();
 ob_start();try{eval($branch);}catch(TableControllerFailure $e){$caught=$e->getMessage();}finally{$output=ob_get_clean();}
 table_check($caught===$expected,'Expected storage outcome: '.$caught.' / '.$expected);table_check($db===$original&&$table_server->owner===null,'Scope released before renderer and main connection preserved');
 table_check(table_snapshot()===$expected_sessions,'All sessions including 601 other users and custom fields preserved');
 if($restore){foreach($table_server->closedModes as $mode){table_check($mode==='NO_ENGINE_SUBSTITUTION','Mode restored before owner closes');}}return $output;
}
function storage_revoke($case){table_revoke($case);$GLOBALS['expected_sessions']=table_snapshot();}
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{foreach(array('MyISAM','MEMORY','InnoDB') as $engine){foreach(array('english','german') as $locale){
 $lang=array('Not_Authorised'=>'not-authorized','Session_invalid'=>'session-invalid','Attachment_storage_busy'=>'busy');$phpEx='php';include $root.'language/lang_'.$locale.'/lang_dbmtnc.php';$needs=$engine!=='InnoDB';
 foreach(array(0,1) as $disabled){
  table_fixture($engine);$table_server->pdo->exec('UPDATE fixture_config SET board_disable='.$disabled);$html=storage_run();
  table_check($table_server->commands===($needs?1:0),'One conversion only for legacy engine');table_check(strpos($html,$lang[$needs?'Session_storage_converted':'Session_storage_current'])!==false,'Localized truthful outcome');
  table_check((int)$table_server->pdo->query('SELECT board_disable FROM fixture_config')->fetchColumn()===$disabled,'Existing board state preserved');storage_run();table_check($table_server->commands===($needs?1:0),'Already converted table not rebuilt');
 }
 foreach(array('missing','foreign','logged-out','not-admin','case-changed','actor','grant') as $case){foreach($needs?array('entry','after-metadata','pre-alter','during-alter'):array('entry','after-metadata') as $phase){
  table_fixture($engine,$case==='grant'?20:1);if($case==='grant'){$table_server->pdo->exec("INSERT INTO fixture_junior VALUES (20,'".md5('GeneralDB_Maintenanceadmin_db_maintenance.php')."')");}
  if($phase==='entry'){table_revoke($case);}
  elseif($phase==='during-alter'){$table_server->after=function($sql) use($case){if(strpos($sql,'ALTER TABLE')===0){$GLOBALS['table_server']->after=null;storage_revoke($case);}};}
  else{$table_server->hook=function($sql) use($phase,$case){$s=$GLOBALS['table_server'];if(strpos($sql,'SELECT 1 AS allowed WHERE ')===0&&$s->gates===($phase==='after-metadata'?2:3)){$s->hook=null;storage_revoke($case);}};}
  storage_run($lang['Not_Authorised']);table_check($table_server->commands===($phase==='during-alter'?1:0),'Revocation stops work, not an imaginary DDL rollback');
 }}
 foreach(array('get','sid-array','cached-array','bad-sid') as $case){table_fixture($engine);if($case==='get'){$_SERVER['REQUEST_METHOD']='GET';}elseif($case==='cached-array'){$userdata['session_id']=array();}else{$_POST['sid']=$case==='sid-array'?array():'wrong';}storage_run($lang['Session_invalid']);table_check(!$table_server->queries,'Invalid request never accesses storage');}
 foreach($needs?array('lock','SELECT user_id','SELECT 1 AS allowed WHERE ','SELECT ENGINE','SELECT SUPPORT','SELECT @@SESSION','SET SESSION','ALTER TABLE'):array('lock','SELECT ENGINE') as $failure){
  table_fixture($engine);$table_server->failure=$failure;storage_run($failure==='lock'?$lang['Attachment_storage_busy']:$lang['Maintenance_query_failed']);$table_server->failure='';storage_run();table_check($table_server->commands===($needs?1:0),'Explicit retry inspects engine before applying DDL');
 }
 if($needs){
  foreach(array('late-disable','late-disable-failure','lost-ack','lost-mode-ack','verify','restore','disconnect','exception','error','late-session') as $case){
   if($case==='error'&&!class_exists('Error')){continue;}table_fixture($engine);
   if($case==='lost-ack'){$table_server->lostAck='ALTER TABLE';}
   elseif($case==='lost-mode-ack'){$table_server->lostAck='SET SESSION';}
   elseif($case==='verify'||$case==='restore'){$table_server->after=function($sql) use($case){if(strpos($sql,'ALTER TABLE')===0){$s=$GLOBALS['table_server'];$s->after=null;$s->failure=$case==='verify'?'SELECT ENGINE':"SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'";}};}
   else{$table_server->hook=function($sql,$conn) use($case){if(strpos($sql,'ALTER TABLE')===0){$s=$GLOBALS['table_server'];$s->hook=null;
    if(strpos($case,'late-disable')===0){$s->pdo->exec('UPDATE fixture_config SET board_disable=1');if($case==='late-disable-failure'){$s->failure='ALTER TABLE';}}
    elseif($case==='late-session'){$s->pdo->exec("INSERT INTO fixture_sessions VALUES ('concurrent',55,1,0,'current')");$GLOBALS['expected_sessions']=table_snapshot();}
    elseif($case==='disconnect'){$conn->sql_close();}elseif($case==='error'){throw new Error('Private adapter detail');}else{throw new RuntimeException('Private adapter detail');}
   }};}
   $failure=in_array($case,array('late-disable','late-session'),true)?'':(in_array($case,array('exception','error'),true)?$lang['Session_storage_failed']:$lang['Maintenance_query_failed']);
   storage_run($failure,!in_array($case,array('restore','disconnect'),true));
   if(strpos($case,'late-disable')===0){table_check((int)$table_server->pdo->query('SELECT board_disable FROM fixture_config')->fetchColumn()===1,'Concurrent disable preserved');}
   $table_server->failure='';$table_server->lostAck='';$table_server->mode='NO_ENGINE_SUBSTITUTION';storage_run();table_check($table_server->commands===1,'Retry after uncertain applied DDL never converts twice');
  }
  table_fixture($engine);$table_server->supported=false;storage_run($lang['Session_storage_unsupported']);table_check(!$table_server->commands,'Unavailable engine fails before DDL');
 }
 table_fixture($engine,20);$table_server->pdo->exec("INSERT INTO fixture_junior VALUES (20,'".md5('GeneralDB_Maintenanceadmin_db_maintenance.php')."')");storage_run();table_check($table_server->commands===($needs?1:0),'Current delegated administrator permitted');
 echo ($native?'native ':'fixture ').$engine.' '.$locale." session storage controller, 602-row preservation and authority passed.\n";
}}
 table_check(strpos($source,"case 'heap_convert':")===false&&strpos($source,'HEAP_SIZE')===false,'Old destructive action and cap removed');
 foreach(array('english','german') as $locale){$mtnc=array();include $root.'language/lang_'.$locale.'/lang_dbmtnc.php';$names=array();foreach($mtnc as $entry){$names[]=$entry[0];}table_check(!in_array('heap_convert',$names,true)&&in_array('session_storage',$names,true),'Old requests absent from controller allowlist; new action has explicit confirmation');}
}finally{restore_error_handler();}
