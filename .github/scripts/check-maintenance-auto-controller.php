<?php
// Execute the real ACP branch and repair helper, with native or SQLite-backed
// authority tables. SQLite emulates only MySQL metadata and administrative DDL.
$root=dirname(dirname(__DIR__)).'/phpBB2/';
function auto_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
foreach(array('IN_PHPBB'=>true,'ADMIN'=>1,'MOD'=>2,'USER'=>0,'ATTACHMENTS_TABLE'=>'fixture_links','USERS_TABLE'=>'fixture_users','JR_ADMIN_TABLE'=>'fixture_junior','SESSIONS_TABLE'=>'fixture_sessions') as $key=>$value){define($key,$value);}
$targets=array('BANLIST'=>'ban_id','CATEGORIES'=>'cat_id','DISALLOW'=>'disallow_id','PRUNE'=>'prune_id','GROUPS'=>'group_id','POSTS'=>'post_id','PRIVMSGS'=>'privmsgs_id','RANKS'=>'rank_id','SEARCH_WORD'=>'word_id','SMILIES'=>'smilies_id','THEMES'=>'themes_id','TOPICS'=>'topic_id','VOTE_DESC'=>'vote_id','WORDS'=>'word_id');
foreach($targets as $name=>$column){define($name.'_TABLE','fixture_'.strtolower($name));}
require_once $root.'includes/functions_maintenance_tables.php';
class AutoControllerFailure extends RuntimeException {}
function throw_error($message){auto_check($GLOBALS['server']->owner===null&&$GLOBALS['db']===$GLOBALS['original'],'Release scope and restore original connection BEFORE terminal renderer');throw new AutoControllerFailure($message);}
function message_die($code,$message){throw new RuntimeException($message);}
function lock_db($unlock=false){throw new RuntimeException('Auto repair must not change board availability');}
class AutoRows {public $rows;function __construct($rows){$this->rows=$rows;}}
$dsn=getenv('PHPBB_AUTO_CONTROLLER_DSN');$native=$dsn!==false&&$dsn!=='';
if($native){auto_check(preg_match('/^mysql:host=127\.0\.0\.1;port=33119;dbname=codex_auto_controller_[a-f0-9]{16};charset=utf8mb4$/D',$dsn)===1,'Native database restricted to owned loopback fixture');}
class AutoServer {
 public $pdo;public $owner=null;public $hook=null;public $after=null;public $failure='';public $lostAck='';public $alters=0;public $reads=0;public $gates=0;public $queries=array();public $columns=array();public $closedModes=array();
 function __construct($engine){
  $this->pdo=$GLOBALS['native']?new PDO($GLOBALS['dsn'],'root',''):new PDO('sqlite::memory:');$this->pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
  $defs=array('users'=>'user_id INTEGER PRIMARY KEY,username VARCHAR(255),user_level INTEGER,user_active INTEGER','junior'=>'user_id INTEGER,user_jr_admin VARCHAR(255)','sessions'=>'session_id VARCHAR(32) PRIMARY KEY,session_user_id INTEGER,session_logged_in INTEGER,session_admin INTEGER','config'=>'board_disable INTEGER');
  foreach($defs as $name=>$def){$this->pdo->exec('DROP TABLE IF EXISTS fixture_'.$name);$this->pdo->exec('CREATE TABLE fixture_'.$name.' ('.$def.')'.($GLOBALS['native']?' ENGINE='.$engine:''));}
  $i=0;foreach($GLOBALS['targets'] as $name=>$column){
   $table='fixture_'.strtolower($name);$healthy=$i++>=2;
   $this->columns[$table]=array('Field'=>$column,'Type'=>'bigint(20) unsigned','Null'=>'NO','Key'=>'PRI','Default'=>null,'Extra'=>$healthy?'auto_increment':'','Comment'=>'Grüße');
   $this->pdo->exec('DROP TABLE IF EXISTS '.$table);
   $this->pdo->exec('CREATE TABLE '.$table.' ('.$column.' '.($GLOBALS['native']?'BIGINT UNSIGNED NOT NULL'.($healthy?' AUTO_INCREMENT':''):"BIGINT").' PRIMARY KEY'.($GLOBALS['native']?" COMMENT 'Grüße'":'').',body VARCHAR(255))'.($GLOBALS['native']?' ENGINE='.$engine.' DEFAULT CHARSET=utf8mb4':''));
   if($GLOBALS['native']){$this->pdo->exec("SET SESSION sql_mode='NO_AUTO_VALUE_ON_ZERO'");}
   $this->pdo->exec("INSERT INTO ".$table." VALUES (0,'zero'),(4294967298,'Grüße')");
   if($GLOBALS['native']&&$healthy){$this->pdo->exec('ALTER TABLE '.$table.' AUTO_INCREMENT=5000000000');}
  }
 }
}
class AutoForum {
 public $dbname='auto-controller';
 function __construct(){if($GLOBALS['native']){preg_match('/dbname=([^;]+)/',$GLOBALS['dsn'],$m);$this->dbname=$m[1];}}
 function sql_query($sql){throw new RuntimeException('Unowned main connection used');}
 function sql_dedicated_connection(){return new AutoConnection($GLOBALS['server']);}
}
class AutoConnection {
 public $server;public $pdo;public $closed=false;public $db_connect_id=true;public $mode='NO_ENGINE_SUBSTITUTION';
 function __construct($server){$this->server=$server;$this->pdo=$GLOBALS['native']?new PDO($GLOBALS['dsn'],'root','',array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION)):$server->pdo;if($GLOBALS['native']){$this->pdo->exec("SET SESSION sql_mode='NO_ENGINE_SUBSTITUTION'");}}
 function sql_query($sql){
  if($this->closed){return false;}$s=$this->server;$s->queries[]=$sql;
  if(strpos($sql,'SELECT GET_LOCK(')===0){
   if($s->failure==='lock'){return new AutoRows(array(array('acquired'=>0)));}
   $rows=$GLOBALS['native']?$this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC):array(array('acquired'=>$s->owner===null?1:0));if((int)$rows[0]['acquired']===1){$s->owner=$this;}return new AutoRows($rows);
  }
  auto_check($s->owner===$this,'Every statement belongs to current shared writer');
  if(strpos($sql,'SELECT 1 AS allowed WHERE ')===0){$s->gates++;}
  if(is_callable($s->hook)){call_user_func($s->hook,$sql,$this);}
  if($this->closed||($s->failure!==''&&strpos($sql,$s->failure)===0)){return false;}
  $isAlter=strpos($sql,'ALTER TABLE ')===0;
  try{
   if($isAlter){$s->alters++;auto_check(strpos($this->mode,'STRICT_ALL_TABLES')!==false&&strpos($this->mode,'NO_AUTO_VALUE_ON_ZERO')!==false,'Strict mode protects sentinel and wide IDs');}
   if(strpos($sql,'SHOW FULL COLUMNS')===0){$s->reads++;}
   if($GLOBALS['native']){$r=$this->pdo->query($sql);$result=$r->columnCount()?new AutoRows($r->fetchAll(PDO::FETCH_ASSOC)):true;$r->closeCursor();}
   elseif(preg_match('/^SHOW FULL COLUMNS FROM '.chr(96).'(\w+)'.chr(96).'$/D',$sql,$m)){$result=new AutoRows(array($s->columns[$m[1]]));}
   elseif(preg_match('/^SHOW INDEX FROM '.chr(96).'(\w+)'.chr(96).'$/D',$sql,$m)){$result=new AutoRows(array(array('Key_name'=>'PRIMARY','Column_name'=>$s->columns[$m[1]]['Field'],'Seq_in_index'=>1)));}
   elseif($sql==='SELECT @@SESSION.sql_mode AS sql_mode'){$result=new AutoRows(array(array('sql_mode'=>$this->mode)));}
   elseif(strpos($sql,'SET SESSION sql_mode = ')===0){$result=true;}
   elseif($isAlter){preg_match('/^ALTER TABLE '.chr(96).'(\w+)'.chr(96).'/',$sql,$m);$s->columns[$m[1]]['Extra']='auto_increment';$result=true;}
   else{$result=new AutoRows($this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC));}
   if(preg_match('/^SET SESSION sql_mode = \x27([A-Z0-9_,]*)\x27$/D',$sql,$m)){$this->mode=$m[1];}
   if(is_callable($s->after)){call_user_func($s->after,$sql,$this);}
   if($s->lostAck!==''&&strpos($sql,$s->lostAck)===0){return false;}return $result;
  }catch(PDOException $e){return false;}
 }
 function sql_fetchrow($r){return array_shift($r->rows);}
 function sql_fetchrowset($r){return $r->rows;}
 function sql_freeresult($r){auto_check($r instanceof AutoRows,'Never free boolean DDL acknowledgment as a result set');}
 function sql_escape($s){if($this->closed){throw new RuntimeException('Connection unavailable');}return substr($this->pdo->quote($s),1,-1);}
 function sql_close(){if(!$this->closed){$this->server->closedModes[]=$GLOBALS['native']?$this->pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn():$this->mode;}if($this->server->owner===$this){$this->server->owner=null;}$this->closed=true;$this->db_connect_id=false;$this->pdo=null;}
}
function auto_fixture($engine,$actor=1){
 global $server,$userdata,$phpbb_root_path,$phpEx,$root;
 $server=new AutoServer($engine);$server->pdo->exec("INSERT INTO fixture_users VALUES (1,'Root',1,1),(20,'Junior',0,1)");$server->pdo->exec('INSERT INTO fixture_config VALUES (0)');
 $server->pdo->exec("INSERT INTO fixture_sessions VALUES ('fixture-sid',".$actor.",1,1)");
 $userdata=array('user_id'=>$actor,'session_logged_in'=>true,'session_admin'=>true,'session_id'=>'fixture-sid');$phpbb_root_path=$root;$phpEx='php';
 $_SERVER['REQUEST_METHOD']='POST';$_POST=array('sid'=>'fixture-sid');
 if($actor===20){$server->pdo->exec("INSERT INTO fixture_junior VALUES (20,'".md5('GeneralDB_Maintenanceadmin_db_maintenance.php')."')");}
}
function auto_revoke($case){
 $pdo=$GLOBALS['server']->pdo;
 $sql=array('missing'=>'DELETE FROM fixture_sessions','foreign'=>'UPDATE fixture_sessions SET session_user_id=99','logged-out'=>'UPDATE fixture_sessions SET session_logged_in=0','not-admin'=>'UPDATE fixture_sessions SET session_admin=0','case-changed'=>"UPDATE fixture_sessions SET session_id='FIXTURE-SID'",'actor'=>'UPDATE fixture_users SET user_active=0','grant'=>'DELETE FROM fixture_junior');
 $pdo->exec($sql[$case]);
}
function auto_snapshot(){
 $out=array();$pdo=$GLOBALS['server']->pdo;foreach($GLOBALS['targets'] as $name=>$column){$table='fixture_'.strtolower($name);$out[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY '.$column)->fetchAll(PDO::FETCH_ASSOC);}return $out;
}
$source=file_get_contents($root.'includes/functions_dbmtnc.php');
foreach(array('dbmtnc_auto_rows','set_autoincrement') as $name){auto_check(preg_match('/^function '.$name.'\(.*?^\}/ms',$source,$m)===1,'Actual repair helper found');eval($m[0]);}
$source=file_get_contents($root.'admin/admin_db_maintenance.php');$a=strpos($source,"case 'reset_auto_increment':");$b=strpos($source,"case 'heap_convert':",$a);auto_check($a!==false&&$b>$a,'Actual controller branch found');$branch='switch($function){'.substr($source,$a,$b-$a).'}';
function auto_run($expected='',$modeRestored=true){
 global $db,$original,$server,$lang,$branch,$phpbb_root_path,$phpEx;
 $original=new AutoForum();$db=$original;$function='reset_auto_increment';$before=auto_snapshot();$caught='';ob_start();
 try{eval($branch);}catch(AutoControllerFailure $e){$caught=$e->getMessage();}finally{$output=ob_get_clean();}
 auto_check($caught===$expected,'Expected controlled outcome: '.$caught.' / '.$expected);
 auto_check($db===$original&&$server->owner===null,'Original connection restored and owner released');
 auto_check(auto_snapshot()===$before,'Every original row, sentinel/wide ID and body preserved');
 if($modeRestored){foreach($server->closedModes as $mode){auto_check($mode==='NO_ENGINE_SUBSTITUTION','Original SQL mode restored before closing owner');}}
 return $output;
}
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{foreach($native?array('MyISAM','InnoDB'):array('SQLite') as $engine){foreach(array('english','german') as $locale){
 $lang=array('Not_Authorised'=>'not-authorized','Session_invalid'=>'session-invalid','Attachment_storage_busy'=>'busy');$phpEx='php';include $root.'language/lang_'.$locale.'/lang_dbmtnc.php';
 foreach(array(0,1) as $disabled){foreach(array('success','failure','late-disable','late-disable-failure') as $case){
  auto_fixture($engine);$server->pdo->exec('UPDATE fixture_config SET board_disable='.$disabled);
  if($case==='failure'){$server->failure='ALTER TABLE';}
  if(strpos($case,'late-disable')===0){$server->hook=function($sql) use($case){if(strpos($sql,'ALTER TABLE')===0){$s=$GLOBALS['server'];$s->hook=null;$s->pdo->exec('UPDATE fixture_config SET board_disable=1');if($case==='late-disable-failure'){$s->failure='ALTER TABLE';}}};}
  auto_run(strpos($case,'failure')!==false?$lang['Maintenance_query_failed']:'');
  auto_check((int)$server->pdo->query('SELECT board_disable FROM fixture_config')->fetchColumn()===(strpos($case,'late-disable')===0?1:$disabled),'Never undo existing or independent disable');
  if($case==='success'){auto_check($server->alters===2&&$server->reads===16,'All fourteen real table targets inspected, only missing attributes repaired');auto_run();auto_check($server->alters===2,'Second run does not modify healthy counters');}
 }}
 foreach(array('missing','foreign','logged-out','not-admin','case-changed','actor','grant') as $case){foreach(array('entry','metadata','pre-alter','during-alter','next-table','healthy-table') as $phase){
  auto_fixture($engine,$case==='grant'?20:1);
  if($phase==='entry'){auto_revoke($case);}
  elseif($phase==='during-alter'){$server->after=function($sql) use($case){if(strpos($sql,'ALTER TABLE')===0){$GLOBALS['server']->after=null;auto_revoke($case);}};}
  else{$gate=array('metadata'=>2,'pre-alter'=>4,'next-table'=>8,'healthy-table'=>14);$server->hook=function($sql) use($case,$gate,$phase){$s=$GLOBALS['server'];if(strpos($sql,'SELECT 1 AS allowed WHERE ')===0&&$s->gates===$gate[$phase]){$s->hook=null;auto_revoke($case);}};}
  auto_run($lang['Not_Authorised']);$expected=$phase==='healthy-table'?2:(in_array($phase,array('during-alter','next-table'),true)?1:0);
  auto_check($server->alters===$expected,'Revocation prevents subsequent DDL; already submitted DDL is not falsely rolled back');
 }}
 foreach(array('get','sid-array','cached-array','bad-sid') as $case){
  auto_fixture($engine);if($case==='get'){$_SERVER['REQUEST_METHOD']='GET';}elseif($case==='cached-array'){$userdata['session_id']=array();}else{$_POST['sid']=$case==='sid-array'?array():'wrong';}
  auto_run($lang['Session_invalid']);auto_check(!$server->queries,'Invalid requests never acquire storage');
 }
 foreach(array('lock','SELECT user_id','SELECT 1 AS allowed WHERE ','SHOW FULL COLUMNS','SHOW INDEX','SELECT @@SESSION','SET SESSION','ALTER TABLE') as $failure){
  auto_fixture($engine);$server->failure=$failure;auto_run($failure==='lock'?$lang['Attachment_storage_busy']:$lang['Maintenance_query_failed']);
  $server->failure='';auto_run();auto_check($server->alters===2,'Explicit retry after failure repairs remaining attributes');
 }
 foreach(array('verify','restore','lost-ack','disconnect','exception','error') as $case){
  if($case==='error'&&!class_exists('Error')){continue;}auto_fixture($engine);
  if($case==='lost-ack'){$server->lostAck='ALTER TABLE';}
  elseif($case==='verify'||$case==='restore'){$server->after=function($sql) use($case){if(strpos($sql,'ALTER TABLE')===0){$s=$GLOBALS['server'];$s->after=null;$s->failure=$case==='verify'?'SHOW FULL COLUMNS':"SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'";}};}
  else{$server->hook=function($sql,$connection) use($case){if(strpos($sql,'ALTER TABLE')===0){$GLOBALS['server']->hook=null;if($case==='disconnect'){$connection->sql_close();}elseif($case==='error'){throw new Error('Private engine detail');}else{throw new RuntimeException('Private adapter detail');}}};}
  auto_run(in_array($case,array('restore','exception','error'),true)?$lang['Ai_repair_failed']:$lang['Maintenance_query_failed'],!in_array($case,array('restore','disconnect'),true));
  auto_check($server->alters===(in_array($case,array('verify','restore','lost-ack'),true)?1:0),'Failure never submits next table');
  $server->failure='';$server->lostAck='';$server->closedModes=array();auto_run();auto_check($server->alters===2,'Retry inspects metadata instead of blindly repeating DDL');
 }
 auto_fixture($engine,20);auto_run();auto_check($server->alters===2,'Current delegated administrator can repair');
 auto_fixture($engine);$contended=false;$server->hook=function($sql) use(&$contended){if(strpos($sql,'ALTER TABLE')===0){$GLOBALS['server']->hook=null;$other=new attach_mutation_lock(new AutoForum(),false);$contended=!$other->acquired;$other->release();}};auto_run();auto_check($contended,'Shared writer remains owned during DDL');
 if($native){$server->pdo->exec("INSERT INTO fixture_disallow (body) VALUES ('next')");auto_check((string)$server->pdo->query("SELECT disallow_id FROM fixture_disallow WHERE body='next'")->fetchColumn()==='5000000000','Healthy native next counter preserved');}
 echo $engine.' '.$locale." actual auto-increment controller authority, lifecycle and data preservation passed.\n";
}}}finally{restore_error_handler();}
