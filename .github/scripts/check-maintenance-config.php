<?php
$root=dirname(dirname(__DIR__)).'/phpBB2/';
foreach(array('IN_PHPBB'=>true,'ADMIN'=>1,'MOD'=>2,'USER'=>0,'ATTACHMENTS_TABLE'=>'fixture_links','USERS_TABLE'=>'fixture_users','JR_ADMIN_TABLE'=>'fixture_junior','SESSIONS_TABLE'=>'fixture_sessions','CONFIG_TABLE'=>'fixture_config','TOPICS_TABLE'=>'fixture_topics') as $key=>$value){define($key,$value);}
require $root.'includes/php_compat.php';require $root.'includes/functions_maintenance_config.php';
function config_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
class ConfigControllerFailure extends RuntimeException {}
function throw_error($message){config_check($GLOBALS['config_server']->owner===null,'Release owner before error renderer');throw new ConfigControllerFailure($message);}
function erc_throw_error($message){throw new ConfigControllerFailure($message);}
function message_die($code,$message){throw new RuntimeException($message);}
function lock_db($unlock=false){throw new RuntimeException('Recovery must not toggle board availability');}
function check_authorisation(){if(!$GLOBALS['erc_allowed']){throw new ConfigControllerFailure('erc-denied');}$GLOBALS['erc_checked']=true;}
function success_message($message){$GLOBALS['erc_success']=true;}
class ConfigRows {public $rows;function __construct($rows){$this->rows=$rows;}}
$dsn=getenv('PHPBB_CONFIG_TEST_DSN');$native=$dsn!==false&&$dsn!=='';
if($native){config_check(preg_match('/^mysql:host=127\.0\.0\.1;port=33119;dbname=codex_config_[a-f0-9]{16};charset=utf8mb4$/D',$dsn)===1,'Only owned loopback fixture allowed');}
class ConfigServer {
 public $pdo;public $owner=null;public $hook=null;public $failure='';public $lostAck='';public $queries=array();
 function __construct($engine){
  $this->pdo=$GLOBALS['native']?new PDO($GLOBALS['dsn'],'root',''):new PDO('sqlite::memory:');$this->pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
  $defs=array('users'=>'user_id INTEGER PRIMARY KEY,username VARCHAR(255),user_level INTEGER,user_active INTEGER','junior'=>'user_id INTEGER,user_jr_admin VARCHAR(255)',
   'sessions'=>'session_id VARCHAR(32) PRIMARY KEY,session_user_id INTEGER,session_logged_in INTEGER,session_admin INTEGER','config'=>'config_name VARCHAR(191) PRIMARY KEY,config_value TEXT','topics'=>'topic_time INTEGER');
  foreach($defs as $name=>$def){$this->pdo->exec('DROP TABLE IF EXISTS fixture_'.$name);$this->pdo->exec('CREATE TABLE fixture_'.$name.' ('.$def.')'.($GLOBALS['native']?' ENGINE='.$engine:''));}
  $this->pdo->exec("INSERT INTO fixture_users VALUES (1,'Root',1,1),(20,'Junior',0,1)");
  $this->pdo->exec("INSERT INTO fixture_config VALUES ('board_disable','0'),('version','.0.23'),('custom','keep')");$this->pdo->exec('INSERT INTO fixture_topics VALUES (100),(200)');
 }
}
class ConfigForum {
 public $dbname='config-fixture';
 function __construct(){if($GLOBALS['native']){preg_match('/dbname=([^;]+)/',$GLOBALS['dsn'],$m);$this->dbname=$m[1];}}
 function sql_query($sql){throw new RuntimeException('Unowned main connection used');}
 function sql_dedicated_connection(){return new ConfigConnection($GLOBALS['config_server']);}
}
class ConfigConnection {
 public $server;public $pdo;public $db_connect_id=true;public $closed=false;public $affected=0;public $erc=false;
 function __construct($server,$erc=false){$this->server=$server;$this->erc=$erc;$this->pdo=$GLOBALS['native']?new PDO($GLOBALS['dsn'],'root','',array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION)):$server->pdo;}
 function sql_query($sql){
  if($this->closed){return false;}$s=$this->server;$s->queries[]=$sql;
  if(strpos($sql,'SELECT GET_LOCK(')===0){
   if($s->failure==='lock'){return new ConfigRows(array(array('acquired'=>0)));}
   $rows=$GLOBALS['native']?$this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC):array(array('acquired'=>$s->owner===null?1:0));
   if((int)$rows[0]['acquired']===1){$s->owner=$this;}return new ConfigRows($rows);
  }
  config_check($this->erc||$s->owner===$this,'ACP statements use owning connection');
  if(is_callable($s->hook)){call_user_func($s->hook,$sql,$this);}
  if($this->closed||($s->failure!==''&&strpos($sql,$s->failure)===0)){return false;}
  try{$q=$this->pdo->query($sql);$this->affected=$q->rowCount();if($s->lostAck!==''&&strpos($sql,$s->lostAck)===0){return false;}return strpos($sql,'SELECT')===0?new ConfigRows($q->fetchAll(PDO::FETCH_ASSOC)):true;}catch(PDOException $e){return false;}
 }
 function sql_fetchrow($r){return array_shift($r->rows);}
 function sql_fetchrowset($r){return $r->rows;}
 function sql_freeresult($r){}
 function sql_affectedrows(){return $this->affected;}
 function sql_escape($s){return substr($this->pdo->quote($s),1,-1);}
 function sql_close(){if($this->server->owner===$this){$this->server->owner=null;}$this->closed=true;$this->db_connect_id=false;$this->pdo=null;}
}
function config_fixture($engine,$actor=1){
 global $config_server,$userdata,$phpEx,$phpbb_root_path,$root,$board_config,$default_config,$erc_allowed,$erc_checked,$erc_success;
 $config_server=new ConfigServer($engine);$userdata=array('user_id'=>$actor,'user_level'=>ADMIN,'session_logged_in'=>true,'session_admin'=>true,'session_id'=>'fixture-sid');
 $config_server->pdo->exec("INSERT INTO fixture_sessions VALUES ('fixture-sid',".(int)$actor.",1,1)");
 $_SERVER=array('REQUEST_METHOD'=>'POST','HTTPS'=>'on','SERVER_PROTOCOL'=>'HTTP/1.1','SERVER_NAME'=>'fixture.invalid','SERVER_PORT'=>'443','SCRIPT_NAME'=>'/forum/admin/admin_db_maintenance.php');$_POST=array('sid'=>'fixture-sid');$phpEx='php';$phpbb_root_path=$root;
 $board_config=array('version'=>'.0.23','board_disable'=>'0');$erc_allowed=true;$erc_checked=false;$erc_success=false;
 $default_config=array('board_disable'=>'0','cookie_secure'=>'0','server_name'=>'old.invalid','server_port'=>'80','script_path'=>'/old/','site_desc'=>"Grüße <b>O'Connor</b>",'board_startdate'=>'0','version'=>'.0.0');
}
function config_value($key){$pdo=$GLOBALS['config_server']->pdo;return $pdo->query('SELECT config_value FROM fixture_config WHERE config_name='.$pdo->quote($key))->fetchColumn();}
function config_snapshot(){return $GLOBALS['config_server']->pdo->query('SELECT * FROM fixture_config ORDER BY config_name')->fetchAll(PDO::FETCH_ASSOC);}
function config_revoke($case){
 $pdo=$GLOBALS['config_server']->pdo;
 if($case==='missing'){$pdo->exec('DELETE FROM fixture_sessions');}elseif($case==='foreign'){$pdo->exec('UPDATE fixture_sessions SET session_user_id=99');}
 elseif($case==='logged-out'){$pdo->exec('UPDATE fixture_sessions SET session_logged_in=0');}elseif($case==='not-admin'){$pdo->exec('UPDATE fixture_sessions SET session_admin=0');}
 elseif($case==='case-changed'){$pdo->exec("UPDATE fixture_sessions SET session_id='FIXTURE-SID'");}elseif($case==='actor'){$pdo->exec('UPDATE fixture_users SET user_active=0');}
 elseif($case==='grant'){$pdo->exec('DELETE FROM fixture_junior');}else{throw new RuntimeException('Unknown revocation');}
}
$source=file_get_contents($root.'admin/admin_db_maintenance.php');$a=strpos($source,"case 'check_config':");$b=strpos($source,"case 'check_search_wordmatch':",$a);config_check($a!==false&&$b>$a,'Actual ACP branch found');$branches=array('acp'=>'switch($function){'.substr($source,$a,$b-$a).'}');
$source=file_get_contents($root.'admin/erc.php');$e=strpos($source,"case 'execute':");$a=strpos($source,"case 'cct':",$e);$b=strpos($source,"case 'rpd':",$a);config_check($e!==false&&$a>$e&&$b>$a,'Actual ERC branch found');$branches['erc']='switch($function){'.substr($source,$a,$b-$a).'}';
function config_run($expected='',$mode='acp'){
 global $db,$lang,$default_config,$branches,$phpbb_root_path,$phpEx;
 $db=$mode==='acp'?new ConfigForum():new ConfigConnection($GLOBALS['config_server'],true);$function=$mode==='acp'?'check_config':'cct';$caught='';
 ob_start();try{eval($branches[$mode]);}catch(ConfigControllerFailure $e){$caught=$e->getMessage();}finally{$html=ob_get_clean();}
 config_check($caught===$expected,'Expected recovery outcome: '.$caught.' / '.$expected);config_check($GLOBALS['config_server']->owner===null,'Owner released');return $html;
}
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{foreach($native?array('MyISAM','InnoDB'):array('SQLite') as $engine){foreach(array('english','german') as $locale){
 $lang=array('Not_Authorised'=>'not-authorized','Session_invalid'=>'session-invalid','Attachment_storage_busy'=>'busy');$phpEx='php';include $root.'language/lang_'.$locale.'/lang_dbmtnc.php';
 foreach(array('acp','erc') as $mode){
  config_fixture($engine);$html=config_run('',$mode);config_check(config_value('cookie_secure')==='1'&&config_value('server_port')==='443'&&config_value('script_path')==='/forum/'&&config_value('board_startdate')==='100','HTTPS/path/date defaults recovered');
  config_check(config_value('custom')==='keep'&&config_value('version')==='.0.23'&&config_value('board_disable')==='0','Existing settings and version preserved');config_check(config_value('site_desc')===$default_config['site_desc'],'Quotes/UTF8 stored exactly');config_check(strpos($html,"<b>O'Connor</b>")===false,'Recovered values not rendered as markup');
  $before=config_snapshot();config_run('',$mode);config_check(config_snapshot()===$before,'Repeat recovery is a no-op');if($mode==='erc'){config_check($erc_checked&&$erc_success,'Separate emergency authorization retained');}
  foreach(array(null,'','.0.0','.0.22','custom') as $version){
   config_fixture($engine);$config_server->pdo->exec("DELETE FROM fixture_config WHERE config_name='version'");if($version!==null){$config_server->pdo->exec("INSERT INTO fixture_config VALUES ('version',".$config_server->pdo->quote($version).")");}
   $html=config_run('',$mode);config_check(config_value('version')===($version===null?false:$version),'Recovery never invents or rewrites version');config_check((strpos($html,$lang['Maintenance_config_version_unknown'])!==false)===in_array($version,array(null,'','.0.0'),true),'Missing/unknown version explicitly reported');
  }
  config_fixture($engine);unset($_SERVER['HTTPS']);$_SERVER['SERVER_PORT']='80';config_run('',$mode);config_check(config_value('cookie_secure')==='0','HTTP defaults not forced to HTTPS');
  config_fixture($engine);$config_server->pdo->exec("DELETE FROM fixture_config WHERE config_name='board_disable'");config_run('',$mode);config_check(config_value('board_disable')==='1','Lost availability setting is restored disabled for review');
  config_fixture($engine);$config_server->hook=function($sql){if(strpos($sql,'INSERT INTO fixture_config')===0){$s=$GLOBALS['config_server'];$s->hook=null;$s->pdo->exec("INSERT INTO fixture_config VALUES ('cookie_secure','custom-secure')");}};config_run('',$mode);config_check(config_value('cookie_secure')==='custom-secure','Concurrent recovery wins inside insertion');
 }
 foreach(array('missing','foreign','logged-out','not-admin','case-changed','actor','grant') as $case){foreach(array('entry','insert') as $phase){
  config_fixture($engine,$case==='grant'?20:1);if($case==='grant'){$config_server->pdo->exec("INSERT INTO fixture_junior VALUES (20,'".md5('GeneralDB_Maintenanceadmin_db_maintenance.php')."')");}$before=config_snapshot();
  if($phase==='entry'){config_revoke($case);}else{$config_server->hook=function($sql) use($case){if(strpos($sql,'INSERT INTO fixture_config')===0){$GLOBALS['config_server']->hook=null;config_revoke($case);}};}
  config_run($lang['Not_Authorised']);config_check(config_snapshot()===$before,'Current authority blocks actual config insertion at '.$phase);
 }}
 foreach(array(0,1) as $disabled){foreach(array('success','failure','late-disable','late-disable-failure') as $case){
  config_fixture($engine);$config_server->pdo->exec("UPDATE fixture_config SET config_value='".$disabled."' WHERE config_name='board_disable'");if($case==='failure'){$config_server->failure='INSERT INTO fixture_config';}
  if(strpos($case,'late-disable')===0){$config_server->hook=function($sql) use($case){if(strpos($sql,'INSERT INTO fixture_config')===0){$s=$GLOBALS['config_server'];$s->hook=null;$s->pdo->exec("UPDATE fixture_config SET config_value='1' WHERE config_name='board_disable'");if($case==='late-disable-failure'){$s->failure='INSERT INTO fixture_config';}}};}
  config_run(strpos($case,'failure')!==false?$lang['Maintenance_config_failed']:'');config_check(config_value('board_disable')===(strpos($case,'late-disable')===0?'1':(string)$disabled),'Independent board state untouched');
 }}
 foreach(array('get','posted-array','cached-array','bad-sid') as $case){config_fixture($engine);if($case==='get'){$_SERVER['REQUEST_METHOD']='GET';}elseif($case==='cached-array'){$userdata['session_id']=array();}else{$_POST['sid']=$case==='posted-array'?array():'bad';}config_run($lang['Session_invalid']);config_check(!$config_server->queries,'Invalid request never reaches database');}
 foreach(array('lock','SELECT user_id','INSERT INTO fixture_config') as $failure){config_fixture($engine);$before=config_snapshot();$config_server->failure=$failure;config_run($failure==='lock'?$lang['Attachment_storage_busy']:$lang['Maintenance_config_failed']);config_check(config_snapshot()===$before,'Initial failures do not change settings');}
 config_fixture($engine);$config_server->lostAck='INSERT INTO fixture_config';config_run($lang['Maintenance_config_failed']);config_check(config_value('cookie_secure')==='1','Lost acknowledgment may follow completed insert');$config_server->lostAck='';config_run();config_check(config_value('server_name')==='fixture.invalid','Retry finishes remaining defaults');
 config_fixture($engine);$inserts=0;$config_server->hook=function($sql) use(&$inserts){if(strpos($sql,'INSERT INTO fixture_config')===0&&++$inserts===2){$GLOBALS['config_server']->hook=null;config_revoke('missing');}};config_run($lang['Not_Authorised']);config_check(config_value('cookie_secure')==='1'&&config_value('server_name')===false,'Earlier addition remains but later revoked insert is blocked');
 config_fixture($engine);$config_server->failure='SELECT MIN(topic_time)';config_run();config_check(config_value('board_startdate')==='0','Optional damaged topic data does not block config repair');
 config_fixture($engine,20);$config_server->pdo->exec("INSERT INTO fixture_junior VALUES (20,'".md5('GeneralDB_Maintenanceadmin_db_maintenance.php')."')");config_run();config_check(config_value('cookie_secure')==='1','Valid delegated administrator permitted');
 config_fixture($engine);$config_server->hook=function($sql,$connection){if(strpos($sql,'INSERT INTO fixture_config')===0){$GLOBALS['config_server']->hook=null;$connection->sql_close();}};config_run($lang['Maintenance_config_failed']);config_check(config_value('cookie_secure')===false,'Lost owner cannot insert');
 config_fixture($engine);$erc_allowed=false;config_run('erc-denied','erc');config_check(!$config_server->queries&&!$erc_success,'Denied emergency credential cannot query/write');
 echo $engine.' '.$locale." config recovery authority and defaults passed.\n";
}}}finally{restore_error_handler();}
