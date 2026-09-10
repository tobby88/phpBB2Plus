<?php
$root = dirname(dirname(__DIR__)) . '/phpBB2/';
foreach (array('IN_PHPBB'=>true,'ADMIN'=>1,'MOD'=>2,'USER'=>0,'GENERAL_ERROR'=>202,
 'ATTACHMENTS_TABLE'=>'fixture_links','USERS_TABLE'=>'fixture_users','JR_ADMIN_TABLE'=>'fixture_junior',
 'SESSIONS_TABLE'=>'fixture_sessions','SEARCH_TABLE'=>'fixture_search') as $key=>$value) { define($key,$value); }
require_once $root . 'includes/functions_maintenance_sessions.php';
function reset_check($ok,$message) { if (!$ok) { throw new RuntimeException($message); } }
function message_die($code,$message) { throw new RuntimeException($message); }
class ResetControllerFailure extends RuntimeException {}
function throw_error($message) { throw new ResetControllerFailure($message); }
function lock_db() { throw new RuntimeException('Session reset must not toggle board availability'); }
class ResetRows { public $rows; function __construct($rows) { $this->rows=$rows; } }
$resetDsn = getenv('PHPBB_SESSION_RESET_TEST_DSN'); $resetNative = $resetDsn !== false && $resetDsn !== '';
if ($resetNative) { reset_check(preg_match('/^mysql:host=127\.0\.0\.1;port=33119;dbname=codex_session_reset_[a-f0-9]{16};charset=utf8mb4$/D',$resetDsn)===1,'Only owned local schemas allowed'); }
class ResetServer {
 public $pdo; public $owner=null; public $hook=null; public $failure=''; public $lostAck=false; public $queries=array(); public $metadataExpiry=null;
 function __construct($engine,$actor) {
  $sqliteClass=class_exists('Pdo\\Sqlite')?'Pdo\\Sqlite':'PDO';
  $this->pdo = $GLOBALS['resetNative'] ? new PDO($GLOBALS['resetDsn'],'root','') : new $sqliteClass('sqlite::memory:');
  $this->pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
  $definitions=array('users'=>'user_id INTEGER PRIMARY KEY,user_level INTEGER,user_active INTEGER',
   'junior'=>'user_id INTEGER,user_jr_admin VARCHAR(255)',
   'sessions'=>'session_id VARCHAR(32) PRIMARY KEY,session_user_id INTEGER,session_start INTEGER,session_time INTEGER,session_ip VARCHAR(8),session_page INTEGER,session_topic INTEGER,session_logged_in INTEGER,session_admin INTEGER',
   'search'=>'search_id INTEGER PRIMARY KEY,session_id VARCHAR(32),search_array TEXT',
   'keys'=>'key_id VARCHAR(32),user_id INTEGER');
  foreach ($definitions as $name=>$definition) {
   $this->pdo->exec('DROP TABLE IF EXISTS fixture_'.$name);
   $this->pdo->exec('CREATE TABLE fixture_'.$name.' ('.$definition.')'.($GLOBALS['resetNative']?' ENGINE='.$engine:''));
  }
  $this->pdo->exec('INSERT INTO fixture_users VALUES (1,1,1),(20,0,1)');
  $this->pdo->exec("INSERT INTO fixture_sessions VALUES ('".str_repeat('a',32)."',".$actor.",100,120,'01020304',9,83,1,1),('".str_repeat('b',32)."',".$actor.",100,120,'01020304',9,83,1,1),('".str_repeat('c',32)."',-1,101,121,'05060708',0,0,0,0),('".str_repeat('d',32)."',8,102,122,'090a0b0c',2,0,1,0)");
  $this->pdo->exec("INSERT INTO fixture_search VALUES (1,'".str_repeat('a',32)."','own search'),(2,'".str_repeat('d',32)."','other search')");
  $this->pdo->exec("INSERT INTO fixture_keys VALUES ('remember',8)");
 }
}
class ResetForum {
 public $dbname='session-reset-fixture';
 function __construct() {
  // Separate disposable databases must not contend on a global fixture name.
  if ($GLOBALS['resetNative']) { preg_match('/dbname=([^;]+);/',$GLOBALS['resetDsn'],$match); $this->dbname=$match[1]; }
 }
 function sql_query($sql) { throw new RuntimeException('Unowned main connection used'); }
 function sql_dedicated_connection() { return new ResetConnection($GLOBALS['resetServer']); }
}
class ResetConnection {
 public $server; public $pdo; public $db_connect_id=true; public $closed=false; public $affected=0;
 function __construct($server) {
  $this->server=$server;
  $this->pdo=$GLOBALS['resetNative'] ? new PDO($GLOBALS['resetDsn'],'root','',array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION)) : $server->pdo;
 }
 function sql_query($sql) {
  if ($this->closed) { return false; } $s=$this->server; $s->queries[]=$sql;
  if (strpos($sql,'SELECT GET_LOCK(')===0) {
   if ($s->failure==='lock') { return new ResetRows(array(array('acquired'=>0))); }
   $rows=$GLOBALS['resetNative'] ? $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) : array(array('acquired'=>$s->owner===null?1:0));
   if ((int)$rows[0]['acquired']===1) { $s->owner=$this; } return new ResetRows($rows);
  }
  reset_check($s->owner===$this,'Queries use the owning connection');
  if (is_callable($s->hook)) { call_user_func($s->hook,$sql,$this); }
  $fail=$s->failure!=='' && strpos($sql,$s->failure)===0;
  if ($this->closed || ($fail && !$s->lostAck)) { return false; }
  if ($sql==="SHOW SESSION VARIABLES WHERE Variable_name = 'information_schema_stats_expiry'" && (!$GLOBALS['resetNative'] || $s->metadataExpiry!==null)) {
   return new ResetRows($s->metadataExpiry===null?array():array(array('Variable_name'=>'information_schema_stats_expiry','Value'=>(string)$s->metadataExpiry)));
  }
  if ($sql==='SET SESSION information_schema_stats_expiry = 0' && $s->metadataExpiry!==null) { $s->metadataExpiry=0; return $fail?false:true; }
  try {
   $r=$this->pdo->query($sql); $this->affected=$r->rowCount();
   if ($fail) { return false; }
   return preg_match('/^(?:SELECT|SHOW)\\b/',$sql) ? new ResetRows($r->fetchAll(PDO::FETCH_ASSOC)) : true;
  } catch (PDOException $error) { throw new RuntimeException('Fixture SQL error: '.$error->getMessage().' SQL: '.$sql); }
 }
 function sql_fetchrow($r) { return array_shift($r->rows); }
 function sql_fetchrowset($r) { return $r->rows; }
 function sql_freeresult($r) {}
 function sql_affectedrows() { return $this->affected; }
 function sql_escape($s) { return substr($this->pdo->quote($s),1,-1); }
 function sql_close() { if ($this->server->owner===$this) { $this->server->owner=null; } $this->closed=true; $this->db_connect_id=false; $this->pdo=null; }
}
function reset_fixture($engine,$actor=1) {
 global $resetServer,$userdata,$phpEx,$phpbb_root_path,$root;
 $resetServer=new ResetServer($engine,$actor); $phpEx='php'; $phpbb_root_path=$root;
 $userdata=array('user_id'=>$actor,'user_level'=>ADMIN,'session_logged_in'=>true,'session_admin'=>true,'session_id'=>str_repeat('a',32));
 $_SERVER['REQUEST_METHOD']='POST'; $_POST=array('sid'=>$userdata['session_id']);
}
function reset_count($table) { return (int)$GLOBALS['resetServer']->pdo->query('SELECT COUNT(*) FROM fixture_'.$table)->fetchColumn(); }
function reset_own() { return $GLOBALS['resetServer']->pdo->query("SELECT * FROM fixture_sessions WHERE session_id='".str_repeat('a',32)."'")->fetch(PDO::FETCH_ASSOC); }
function reset_run($expected='') {
 $caught=''; $result=null;
 try { $result=dbmtnc_reset_sessions(new ResetForum(),$_POST); } catch (PhpbbAclException $error) { $caught=$error->getMessage(); }
 reset_check($caught===$expected,'Expected outcome: '.$caught.' / '.$expected);
 reset_check($GLOBALS['resetServer']->owner===null,'Connection/lock released'); return $result;
}
$controller=file_get_contents($root.'admin/admin_db_maintenance.php');
$a=strpos($controller,"case 'reset_sessions':"); $b=strpos($controller,"case 'check_db':",$a);
reset_check($a!==false && $b>$a,'Actual controller branch found');
$branch='switch("reset_sessions"){'.substr($controller,$a,$b-$a).'}';
set_error_handler(function($severity,$message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } });
try {
 foreach ($resetNative ? array('MyISAM','InnoDB') : array('SQLite') as $engine) { foreach (array('english','german') as $locale) {
  $lang=array('Not_Authorised'=>'not-authorized','Session_invalid'=>'session-invalid','Attachment_storage_busy'=>'busy');
  $phpEx='php'; include $root.'language/lang_'.$locale.'/lang_dbmtnc.php';
  reset_fixture($engine); $own=reset_own(); $out=reset_run();
  reset_check($out===array('searches'=>2,'sessions'=>3),'Accurate reset counts');
  reset_check(reset_own()===$own && reset_count('sessions')===1 && reset_count('search')===0 && reset_count('keys')===1,'Only other sessions and cached results removed; full ACP row and remember-me key preserved');
  reset_check(reset_run()===array('searches'=>0,'sessions'=>0),'Idempotent empty reset');
  foreach (array('get','array-sid','wrong-sid','inactive','demoted','no-cached-admin','missing-session','foreign-session','logged-out','no-live-admin') as $case) {
   reset_fixture($engine); $expected=$lang['Not_Authorised'];
   if ($case==='get') { $_SERVER['REQUEST_METHOD']='GET'; $expected=$lang['Session_invalid']; }
   elseif ($case==='array-sid') { $_POST['sid']=array(); $expected=$lang['Session_invalid']; }
   elseif ($case==='wrong-sid') { $_POST['sid']='bad'; $expected=$lang['Session_invalid']; }
   elseif ($case==='inactive') { $resetServer->pdo->exec('UPDATE fixture_users SET user_active=0 WHERE user_id=1'); }
   elseif ($case==='demoted') { $resetServer->pdo->exec('UPDATE fixture_users SET user_level=0 WHERE user_id=1'); }
   elseif ($case==='no-cached-admin') { $userdata['session_admin']=false; }
   else {
    $where=" WHERE session_id='".str_repeat('a',32)."'";
    $sql=$case==='missing-session'?'DELETE FROM fixture_sessions':'UPDATE fixture_sessions SET '.($case==='foreign-session'?'session_user_id=20':($case==='logged-out'?'session_logged_in=0':'session_admin=0'));
    $resetServer->pdo->exec($sql.$where);
   }
   $before=reset_count('sessions'); reset_run($expected);
   reset_check(reset_count('sessions')===$before && reset_count('search')===2,'Denied request does not mutate '.$case);
  }
  foreach (array('lock','DELETE FROM fixture_search','DELETE FROM fixture_sessions') as $failure) { foreach (array(false,true) as $lost) {
   reset_fixture($engine); $own=reset_own(); $resetServer->failure=$failure; $resetServer->lostAck=$lost;
   reset_run($failure==='lock'?$lang['Attachment_storage_busy']:$lang['Maintenance_session_reset_failed']);
   reset_check(reset_own()===$own,'Own session survives failure/lost acknowledgement');
   reset_check(reset_count('sessions')===($failure==='DELETE FROM fixture_sessions' && $lost?1:4),'Only confirmed session DELETE can remove other sessions');
   $resetServer->failure=''; reset_run(); reset_check(reset_count('sessions')===1 && reset_count('search')===0,'Retry completes safely');
  } }
  $grant=md5('GeneralDB_Maintenanceadmin_db_maintenance.php');
  reset_fixture($engine,20); $resetServer->pdo->exec("INSERT INTO fixture_junior VALUES (20,'".$grant."')"); reset_run();
  reset_fixture($engine,20); $resetServer->pdo->exec("INSERT INTO fixture_junior VALUES (20,'".md5('wrong-module')."')"); reset_run($lang['Not_Authorised']);
  foreach (array('search','sessions') as $target) { foreach (array('inactive','demoted','revoked-grant','deleted-session','deprivileged-session','lost-owner') as $race) {
   reset_fixture($engine,$race==='revoked-grant'?20:1); $own=reset_own();
   if ($race==='revoked-grant') { $resetServer->pdo->exec("INSERT INTO fixture_junior VALUES (20,'".$grant."')"); }
   $resetServer->hook=function($sql,$connection) use($target,$race) {
    if (strpos($sql,'DELETE FROM fixture_'.$target)!==0) { return; }
    $s=$GLOBALS['resetServer']; $s->hook=null;
    if ($race==='lost-owner') { $connection->sql_close(); }
    elseif ($race==='inactive') { $s->pdo->exec('UPDATE fixture_users SET user_active=0 WHERE user_id=1'); }
    elseif ($race==='demoted') { $s->pdo->exec('UPDATE fixture_users SET user_level=0 WHERE user_id=1'); }
    elseif ($race==='revoked-grant') { $s->pdo->exec('DELETE FROM fixture_junior'); }
    else { $s->pdo->exec(($race==='deleted-session'?'DELETE FROM fixture_sessions':'UPDATE fixture_sessions SET session_admin=0')." WHERE session_id='".str_repeat('a',32)."'"); }
   };
   reset_run($race==='lost-owner'?$lang['Maintenance_session_reset_failed']:$lang['Not_Authorised']);
   reset_check(reset_count('sessions')===($race==='deleted-session'?3:4),'Guard blocks stale session reset '.$race);
   reset_check(reset_count('search')===($target==='search'?2:0),'Guard checks current authority inside each write');
   if ($race==='deleted-session') { reset_check(reset_own()===false,'Revoked session never recreated'); }
   elseif ($race!=='deprivileged-session') { reset_check(reset_own()===$own,'Existing session untouched by maintenance'); }
  } }
  reset_fixture($engine); $contended=false;
  $resetServer->hook=function($sql) use(&$contended) {
   if (strpos($sql,'DELETE FROM fixture_search')!==0) { return; } $GLOBALS['resetServer']->hook=null;
   $other=new attach_mutation_lock(new ResetForum(),false); $contended=!$other->acquired; $other->release();
  };
  reset_run(); reset_check($contended,'Coordinates with permission/account writers');
  foreach (array('success','failure','invalid') as $case) {
   reset_fixture($engine); $own=reset_own(); $db=new ResetForum(); $caught='';
   if ($case==='failure') { $resetServer->failure='DELETE FROM fixture_search'; }
   if ($case==='invalid') { $_POST['sid']='bad'; }
   ob_start(); try { eval($branch); } catch (ResetControllerFailure $error) { $caught=$error->getMessage(); } finally { $html=ob_get_clean(); }
   reset_check(($caught!=='')===($case!=='success'),'Actual controller reports failure');
   reset_check(reset_own()===$own,'Actual controller keeps original ACP session');
   if ($case==='success') { reset_check(strpos($html,sprintf($lang['Maintenance_session_reset_summary'],3,2))!==false,'Actual translated summary'); }
   else { reset_check(strpos($html,sprintf($lang['Maintenance_session_reset_summary'],3,2))===false,'Failure not reported as success'); }
  }
  echo $engine.' '.$locale." session maintenance passed.\n";
 } }
} finally { restore_error_handler(); }
