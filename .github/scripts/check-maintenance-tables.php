<?php
$root=dirname(dirname(__DIR__)).'/phpBB2/';
foreach(array('IN_PHPBB'=>true,'ADMIN'=>1,'MOD'=>2,'USER'=>0,'ATTACHMENTS_TABLE'=>'fixture_links','USERS_TABLE'=>'fixture_users','JR_ADMIN_TABLE'=>'fixture_junior','SESSIONS_TABLE'=>'fixture_sessions') as $key=>$value){define($key,$value);}
require_once $root.'includes/functions_maintenance_tables.php';
function table_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
class TableControllerFailure extends RuntimeException {}
function throw_error($message){table_check($GLOBALS['table_server']->owner===null&&$GLOBALS['db'] instanceof TableForum,'Release writer before terminal error renderer');throw new TableControllerFailure($message);}
function message_die($code,$message){throw new RuntimeException($message);}
function check_mysql_version(){return true;}
function lock_db($unlock=false){throw new RuntimeException('Table commands must not toggle board availability');}
class TableRows {public $rows;function __construct($rows){$this->rows=$rows;}}
$dsn=getenv('PHPBB_TABLE_TEST_DSN');$native=$dsn!==false&&$dsn!=='';
if($native){table_check(preg_match('/^mysql:host=127\.0\.0\.1;port=33119;dbname=codex_tables_[a-f0-9]{16};charset=utf8mb4$/D',$dsn)===1,'Only owned loopback schemas allowed');}
class TableServer {
 public $pdo;public $owner=null;public $hook=null;public $after=null;public $failure='';public $lostAck='';public $commands=0;public $gates=0;public $queries=array();
 function __construct($engine){
  $this->pdo=$GLOBALS['native']?new PDO($GLOBALS['dsn'],'root',''):new PDO('sqlite::memory:');$this->pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
  $defs=array('users'=>'user_id INTEGER PRIMARY KEY,username VARCHAR(255),user_level INTEGER,user_active INTEGER','junior'=>'user_id INTEGER,user_jr_admin VARCHAR(255)',
   'sessions'=>'session_id VARCHAR(32) PRIMARY KEY,session_user_id INTEGER,session_logged_in INTEGER,session_admin INTEGER','config'=>'board_disable INTEGER',
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
 function __construct($server){$this->server=$server;$this->pdo=$GLOBALS['native']?new PDO($GLOBALS['dsn'],'root','',array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION)):$server->pdo;}
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
  $command=preg_match('/^(CHECK|REPAIR|OPTIMIZE) TABLE `fixture_(first|second)`$/D',$sql)===1;
  try{
   if($command){$s->commands++;$rows=$GLOBALS['native']?$this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC):array(array('Msg_type'=>'status','Msg_text'=>'OK'));}
   elseif(strpos($sql,'SELECT ENGINE FROM information_schema.TABLES ')===0&&!$GLOBALS['native']){$rows=array(array('ENGINE'=>'MyISAM'));}
   elseif($sql==='SHOW TABLE STATUS'&&!$GLOBALS['native']){$rows=array(array('Name'=>'fixture_first','Rows'=>2,'Data_length'=>1024,'Index_length'=>0),array('Name'=>'fixture_second','Rows'=>2,'Data_length'=>1024,'Index_length'=>0));}
   else{$rows=$this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);}
   if(is_callable($s->after)){call_user_func($s->after,$sql,$this);}
   if($s->lostAck!==''&&strpos($sql,$s->lostAck)===0){return false;}return new TableRows($rows);
  }catch(PDOException $e){return false;}
 }
 function sql_fetchrow($r){return array_shift($r->rows);}
 function sql_fetchrowset($r){return $r->rows;}
 function sql_freeresult($r){}
 function sql_escape($s){return substr($this->pdo->quote($s),1,-1);}
 function sql_close(){if($this->server->owner===$this){$this->server->owner=null;}$this->closed=true;$this->db_connect_id=false;$this->pdo=null;}
}
function table_fixture($engine,$actor=1){
 global $table_server,$userdata,$phpEx,$phpbb_root_path,$root;
 $table_server=new TableServer($engine);$userdata=array('user_id'=>$actor,'user_level'=>ADMIN,'session_logged_in'=>true,'session_admin'=>true,'session_id'=>'fixture-sid');
 $table_server->pdo->exec("INSERT INTO fixture_sessions VALUES ('fixture-sid',".(int)$actor.",1,1)");
 $_SERVER['REQUEST_METHOD']='POST';$_POST=array('sid'=>'fixture-sid');$phpEx='php';$phpbb_root_path=$root;
}
function table_revoke($case){
 $pdo=$GLOBALS['table_server']->pdo;
 if($case==='missing'){$pdo->exec('DELETE FROM fixture_sessions');}
 elseif($case==='foreign'){$pdo->exec('UPDATE fixture_sessions SET session_user_id=99');}
 elseif($case==='logged-out'){$pdo->exec('UPDATE fixture_sessions SET session_logged_in=0');}
 elseif($case==='not-admin'){$pdo->exec('UPDATE fixture_sessions SET session_admin=0');}
 elseif($case==='case-changed'){$pdo->exec("UPDATE fixture_sessions SET session_id='FIXTURE-SID'");}
 elseif($case==='actor'){$pdo->exec('UPDATE fixture_users SET user_active=0');}
 elseif($case==='grant'){$pdo->exec('DELETE FROM fixture_junior');}
 else{throw new RuntimeException('Unknown revocation');}
}
function table_snapshot(){
 $pdo=$GLOBALS['table_server']->pdo;$result=array();foreach(array('first','second') as $name){$result[$name]=$pdo->query('SELECT * FROM fixture_'.$name.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);}return $result;
}
$helper=file_get_contents($root.'includes/functions_dbmtnc.php');
foreach(array('get_table_statistic','convert_bytes','dbmtnc_optimize_table','dbmtnc_table_maintenance') as $name){table_check(preg_match('/^function '.$name.'\(.*?^\}/ms',$helper,$m)===1,'Actual report helper found');eval($m[0]);}
$source=file_get_contents($root.'admin/admin_db_maintenance.php');$branches=array();
foreach(array('check_db'=>'repair_db','repair_db'=>'optimize_db','optimize_db'=>'reset_auto_increment') as $action=>$next){$a=strpos($source,"case '".$action."':");$b=strpos($source,"case '".$next."':",$a);table_check($a!==false&&$b>$a,'Actual controller branch found');$branches[$action]='switch($function){'.substr($source,$a,$b-$a).'}';}
function table_run($function,$expected=''){
 global $db,$tables,$table_prefix,$lang,$branches,$phpbb_root_path,$phpEx;
 $original=new TableForum();$db=$original;$tables=array('first','second');$table_prefix='fixture_';$before=table_snapshot();$caught='';
 ob_start();try{eval($branches[$function]);}catch(TableControllerFailure $e){$caught=$e->getMessage();}finally{$html=ob_get_clean();}
 table_check($caught===$expected,'Expected table outcome: '.$caught.' / '.$expected);
 table_check($db===$original&&$GLOBALS['table_server']->owner===null,'Main connection restored and dedicated writer released before error handoff');
 table_check(table_snapshot()===$before,'All source rows, wide and sentinel IDs preserved');return $html;
}
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{foreach($native?array('MyISAM','InnoDB','MEMORY'):array('SQLite') as $engine){foreach(array('english','german') as $locale){
 $lang=array('Not_Authorised'=>'not-authorized','Session_invalid'=>'session-invalid','Attachment_storage_busy'=>'busy');$phpEx='php';include $root.'language/lang_'.$locale.'/lang_dbmtnc.php';
 foreach(array('check_db'=>'CHECK','repair_db'=>'REPAIR','optimize_db'=>'OPTIMIZE') as $action=>$operation){
  if($engine==='InnoDB'&&$operation==='REPAIR'){$operation='CHECK';}
  foreach(array(0,1) as $disabled){foreach(array('success','failure','late-disable','late-disable-failure') as $case){
   table_fixture($engine);$table_server->pdo->exec('UPDATE fixture_config SET board_disable='.$disabled);$command=$operation.' TABLE';
   if($case==='failure'){$table_server->failure=$command;}
   if(strpos($case,'late-disable')===0){$table_server->hook=function($sql) use($command,$case){if(strpos($sql,$command)===0){$s=$GLOBALS['table_server'];$s->hook=null;$s->pdo->exec('UPDATE fixture_config SET board_disable=1');if($case==='late-disable-failure'){$s->failure=$command;}}};}
   table_run($action,strpos($case,'failure')!==false?$lang['Maintenance_query_failed']:'');
   table_check((int)$table_server->pdo->query('SELECT board_disable FROM fixture_config')->fetchColumn()===(strpos($case,'late-disable')===0?1:$disabled),'Board state preserved on successful/failed commands');
  }}
  foreach(array('missing','foreign','logged-out','not-admin','case-changed','actor','grant') as $case){foreach(array('entry','before-first','during-first','before-second') as $phase){
   table_fixture($engine,$case==='grant'?20:1);if($case==='grant'){$table_server->pdo->exec("INSERT INTO fixture_junior VALUES (20,'".md5('GeneralDB_Maintenanceadmin_db_maintenance.php')."')");}
   if($phase==='entry'){table_revoke($case);}
   elseif($phase==='during-first'){$table_server->after=function($sql) use($operation,$case){if(strpos($sql,$operation.' TABLE')===0){$GLOBALS['table_server']->after=null;table_revoke($case);}};}
   else{$table_server->hook=function($sql) use($phase,$case){$s=$GLOBALS['table_server'];if(strpos($sql,'SELECT 1 AS allowed WHERE ')===0&&$s->gates===($phase==='before-first'?2:4)){$s->hook=null;table_revoke($case);}};}
   table_run($action,$lang['Not_Authorised']);table_check($table_server->commands===(in_array($phase,array('during-first','before-second'),true)?1:0),'Revocation stops before the next command, not a fictitious rollback');
  }}
  foreach(array('get','sid-array','cached-array','bad-sid') as $case){
   table_fixture($engine);if($case==='get'){$_SERVER['REQUEST_METHOD']='GET';}elseif($case==='cached-array'){$userdata['session_id']=array();}else{$_POST['sid']=$case==='sid-array'?array():'wrong';}
   table_run($action,$lang['Session_invalid']);table_check(!$table_server->queries,'Invalid request cannot acquire or query storage');
  }
  foreach(array('lock','SELECT user_id','SELECT 1 AS allowed WHERE ',$operation.' TABLE `fixture_first`',$operation.' TABLE `fixture_second`') as $failure){
   table_fixture($engine);$table_server->failure=$failure;table_run($action,$failure==='lock'?$lang['Attachment_storage_busy']:$lang['Maintenance_query_failed']);
   $table_server->failure='';$table_server->commands=0;table_run($action);table_check($table_server->commands===2,'Retry completes both diagnostic operations');
  }
  table_fixture($engine,20);$table_server->pdo->exec("INSERT INTO fixture_junior VALUES (20,'".md5('GeneralDB_Maintenanceadmin_db_maintenance.php')."')");table_run($action);table_check($table_server->commands===2,'Current delegated administrator permitted');
  table_fixture($engine);$table_server->lostAck=$operation.' TABLE `fixture_first`';table_run($action,$lang['Maintenance_query_failed']);table_check($table_server->commands===1,'Lost acknowledgment may follow an applied command; later command not submitted');
  $table_server->lostAck='';$table_server->commands=0;table_run($action);table_check($table_server->commands===2,'Explicit retry after lost acknowledgment completes safely');
  table_fixture($engine);$table_server->hook=function($sql,$connection) use($operation){if(strpos($sql,$operation.' TABLE')===0){$GLOBALS['table_server']->hook=null;$connection->sql_close();}};table_run($action,$lang['Maintenance_query_failed']);table_check($table_server->commands===0,'Lost owner cannot execute table command');
  foreach(class_exists('Error')?array('exception','error'):array('exception') as $fault){table_fixture($engine);$table_server->hook=function($sql) use($operation,$fault){if(strpos($sql,$operation.' TABLE')===0){if($fault==='error'){throw new Error('Injected engine failure');}throw new RuntimeException('Injected adapter exception');}};table_run($action,$lang['Maintenance_query_failed']);table_check($table_server->commands===0,'Unexpected failure releases owner before rendering');}
  table_fixture($engine);$contended=false;$table_server->hook=function($sql) use(&$contended,$operation){if(strpos($sql,$operation.' TABLE')===0){$GLOBALS['table_server']->hook=null;$other=new attach_mutation_lock(new TableForum(),false);$contended=!$other->acquired;$other->release();}};table_run($action);table_check($contended,'Table commands retain shared writer ownership');
 }
 table_fixture($engine);$table_server->failure='SHOW TABLE STATUS';table_run('optimize_db',$lang['Maintenance_query_failed']);table_check($table_server->commands===0,'Initial statistics failure does not execute optimization');
 table_fixture($engine);$table_server->after=function($sql){if(strpos($sql,'OPTIMIZE TABLE `fixture_second`')===0){$GLOBALS['table_server']->failure='SHOW TABLE STATUS';}};table_run('optimize_db',$lang['Maintenance_query_failed']);table_check($table_server->commands===2,'Final statistics failure releases owner without pretending rollback');
 echo $engine.' '.$locale." table maintenance authority and lifecycle passed.\n";
}}}finally{restore_error_handler();}
