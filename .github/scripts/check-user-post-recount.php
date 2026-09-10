<?php
$root=dirname(dirname(__DIR__)).'/phpBB2/';
foreach(array('IN_PHPBB'=>true,'ADMIN'=>1,'MOD'=>2,'USER'=>0,'GENERAL_ERROR'=>202,'ATTACHMENTS_TABLE'=>'fixture_links','USERS_TABLE'=>'fixture_users','POSTS_TABLE'=>'fixture_posts','FORUMS_TABLE'=>'fixture_forums','JR_ADMIN_TABLE'=>'fixture_junior') as $key=>$value){define($key,$value);}
require_once $root.'includes/functions_maintenance_posts.php';
define('SESSIONS_TABLE','fixture_sessions');
function recount_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
function message_die($code,$message){throw new RuntimeException($message);}
class RecountControllerFailure extends RuntimeException {}
function throw_error($message){throw new RecountControllerFailure($message);}
function lock_db(){throw new RuntimeException('Recount must not alter board availability');}
class RecountRows {public $rows;function __construct($rows){$this->rows=$rows;}}
$dsn=getenv('PHPBB_RECOUNT_TEST_DSN');$native=$dsn!==false&&$dsn!=='';
if($native){recount_check(preg_match('/^mysql:host=127\.0\.0\.1;port=33119;dbname=codex_recount_[a-f0-9]{16};charset=utf8mb4$/D',$dsn)===1,'Only owned local schemas allowed');}
class RecountServer {
 public $pdo;public $owner=null;public $hook=null;public $failure='';public $lostAck=false;public $queries=array();
 function __construct($engine){
  $this->pdo=$GLOBALS['native']?new PDO($GLOBALS['dsn'],'root',''):new PDO('sqlite::memory:');$this->pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
  $definitions=array('users'=>'user_id INTEGER PRIMARY KEY,username VARCHAR(255),user_level INTEGER,user_active INTEGER,user_posts INTEGER','posts'=>'post_id INTEGER PRIMARY KEY,poster_id INTEGER,forum_id INTEGER','forums'=>'forum_id INTEGER PRIMARY KEY,count_posts INTEGER','junior'=>'user_id INTEGER,user_jr_admin VARCHAR(255)');
  foreach($definitions as $name=>$definition){$this->pdo->exec('DROP TABLE IF EXISTS fixture_'.$name);$this->pdo->exec('CREATE TABLE fixture_'.$name.' ('.$definition.')'.($GLOBALS['native']?' ENGINE='.$engine:''));}
  foreach(array('sessions'=>'session_id VARCHAR(32) PRIMARY KEY,session_user_id INTEGER,session_logged_in INTEGER,session_admin INTEGER','config'=>'config_name VARCHAR(50) PRIMARY KEY,config_value VARCHAR(255)') as $name=>$definition){$this->pdo->exec('DROP TABLE IF EXISTS fixture_'.$name);$this->pdo->exec('CREATE TABLE fixture_'.$name.' ('.$definition.')'.($GLOBALS['native']?' ENGINE='.$engine:''));}
  $this->pdo->exec("INSERT INTO fixture_sessions VALUES ('fixture-sid',1,1,1); INSERT INTO fixture_config VALUES ('board_disable','0')");
  $this->pdo->exec("INSERT INTO fixture_users VALUES (-1,'Guest',0,1,77),(0,'Reserved',0,1,66),(1,'Root',1,1,0),(8,'Grüße <img src=x>',0,1,99),(9,'Disabled forum only',0,1,7),(10,'No posts',0,0,5),(11,'Correct',0,1,1),(20,'Junior',0,1,0)");
  $this->pdo->exec('INSERT INTO fixture_forums VALUES (3,1),(4,0),(5,1)');
  $this->pdo->exec('INSERT INTO fixture_posts VALUES (10,8,3),(11,8,4),(12,8,5),(13,8,999),(14,9,4),(15,11,3),(16,-1,3),(17,0,3)');

 }
}
class RecountForum {
 public $dbname='recount-fixture';
 function __construct(){if($GLOBALS['native']){preg_match('/dbname=([^;]+);/',$GLOBALS['dsn'],$match);$this->dbname=$match[1];}}
 function sql_query($sql){throw new RuntimeException('Unlocked main connection used');}
 function sql_dedicated_connection(){return new RecountConnection($GLOBALS['recount_server']);}
}
class RecountConnection {
 public $server;public $pdo;public $db_connect_id=true;public $closed=false;public $affected=0;
 function __construct($server){$this->server=$server;$this->pdo=$GLOBALS['native']?new PDO($GLOBALS['dsn'],'root','',array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION)):$server->pdo;}
 function sql_query($sql){
  if($this->closed){return false;}$s=$this->server;$s->queries[]=$sql;
  if(strpos($sql,'SELECT GET_LOCK(')===0){
   if($s->failure==='lock'){return new RecountRows(array(array('acquired'=>0)));}
   $rows=$GLOBALS['native']?$this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC):array(array('acquired'=>$s->owner===null?1:0));
   if((int)$rows[0]['acquired']===1){$s->owner=$this;}return new RecountRows($rows);
  }
  recount_check($s->owner===$this,'Every protected query uses the owning connection');
  if(is_callable($s->hook)){call_user_func($s->hook,$sql,$this);}
  $fail=$s->failure!==''&&strpos($sql,$s->failure)===0;
  if($this->closed||($fail&&!$s->lostAck)){return false;}
  try{$r=$this->pdo->query($sql);$this->affected=$r->rowCount();if($fail){return false;}return preg_match('/^SELECT/',$sql)?new RecountRows($r->fetchAll(PDO::FETCH_ASSOC)):true;}catch(PDOException $e){return false;}
 }
 function sql_fetchrow($r){return array_shift($r->rows);}
 function sql_fetchrowset($r){return $r->rows;}
 function sql_freeresult($r){}
 function sql_affectedrows(){return $this->affected;}
 function sql_escape($s){return substr($this->pdo->quote($s),1,-1);}
 function sql_close(){if($this->server->owner===$this){$this->server->owner=null;}$this->closed=true;$this->db_connect_id=false;$this->pdo=null;}
}

function recount_fixture($engine,$actor=1){
 global $recount_server,$userdata,$phpEx,$phpbb_root_path,$root;
 $recount_server=new RecountServer($engine);$userdata=array('user_id'=>$actor,'user_level'=>ADMIN,'session_logged_in'=>true,'session_admin'=>true,'session_id'=>'fixture-sid');
 $recount_server->pdo->exec('UPDATE fixture_sessions SET session_user_id='.(int)$actor);
 $_SERVER['REQUEST_METHOD']='POST';$_POST=array('sid'=>'fixture-sid');$phpEx='php';$phpbb_root_path=$root;
}
function recount_value($sql){return (int)$GLOBALS['recount_server']->pdo->query($sql)->fetchColumn();}
function recount_run($expected='',$request=null){
 $caught='';$result=null;try{$result=dbmtnc_synchronize_user_counts(new RecountForum(),$request===null?$_POST:$request);}catch(PhpbbAclException $e){$caught=$e->getMessage();}
 recount_check($caught===$expected,'Expected recount outcome: '.$caught.' / '.$expected);recount_check($GLOBALS['recount_server']->owner===null,'Owner released');return $result;
}
$controller=file_get_contents($root.'admin/admin_db_maintenance.php');$a=strpos($controller,"case 'synchronize_user':");$b=strpos($controller,"case 'synchronize_mod_state':",$a);
recount_check($a!==false&&$b>$a,'Actual controller branch found');$branch='switch("synchronize_user"){'.substr($controller,$a,$b-$a).'}';
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{
 foreach($native?array('MyISAM','InnoDB'):array('SQLite') as $engine){foreach(array('english','german') as $locale){
  $lang=array('Not_Authorised'=>'not-authorized','Session_invalid'=>'session-invalid','Attachment_storage_busy'=>'busy');$phpEx='php';include $root.'language/lang_'.$locale.'/lang_dbmtnc.php';
  recount_fixture($engine);$before=$recount_server->pdo->query('SELECT * FROM fixture_posts')->fetchAll(PDO::FETCH_ASSOC);$out=recount_run();
  recount_check(count($out['changed'])===3&&!$out['skipped'],'Only three inconsistent counters change');
  foreach(array(-1=>77,0=>66,1=>0,8=>2,9=>0,10=>0,11=>1,20=>0) as $id=>$count){recount_check(recount_value('SELECT user_posts FROM fixture_users WHERE user_id='.$id)===$count,'Existing counted-forum/sentinel semantics for '.$id);}
  recount_check($before===$recount_server->pdo->query('SELECT * FROM fixture_posts')->fetchAll(PDO::FETCH_ASSOC),'Source posts unchanged');
  $recount_server->queries=array();$out=recount_run();recount_check(!$out['changed']&&!$out['skipped'],'Repeat is a no-op');
  foreach($recount_server->queries as $sql){recount_check(strpos($sql,'UPDATE ')!==0,'Consistent candidates need no UPDATE');}
  recount_fixture($engine);$recount_server->pdo->exec('UPDATE fixture_forums SET count_posts=0');recount_run();
  foreach(array(8,9,10,11) as $id){recount_check(recount_value('SELECT user_posts FROM fixture_users WHERE user_id='.$id)===0,'Empty counted set resets positive accounts');}
  recount_check(recount_value('SELECT user_posts FROM fixture_users WHERE user_id=-1')===77&&recount_value('SELECT user_posts FROM fixture_users WHERE user_id=0')===66,'Empty count set preserves sentinels');
  recount_fixture($engine);$recount_server->pdo->exec('DELETE FROM fixture_users WHERE user_id>1');$out=recount_run();recount_check(!$out['changed']&&!$out['skipped'],'Only current ACP actor and sentinels: no recount targets');
  foreach(array('new-post','policy-off','policy-on','removed-forum','removed-posts','removed-user','renamed-user','actor-before-update','actor-after-update','lost-owner') as $race){
   recount_fixture($engine);$recount_server->hook=function($sql,$connection) use($race){
    $prefix=$race==='actor-after-update'?'SELECT user_id, username, user_posts':'UPDATE fixture_users SET user_posts';
    if(strpos($sql,$prefix)!==0){return;}$s=$GLOBALS['recount_server'];$s->hook=null;
    if($race==='lost-owner'){$connection->sql_close();return;}
    if($race==='new-post'){$s->pdo->exec('INSERT INTO fixture_posts VALUES (18,8,3)');$s->pdo->exec('UPDATE fixture_users SET user_posts=3 WHERE user_id=8');}
    elseif($race==='policy-off'){$s->pdo->exec('UPDATE fixture_forums SET count_posts=0');}
    elseif($race==='policy-on'){$s->pdo->exec('UPDATE fixture_forums SET count_posts=1 WHERE forum_id=4');}
    elseif($race==='removed-forum'){$s->pdo->exec('DELETE FROM fixture_forums WHERE forum_id=3');}
    elseif($race==='removed-posts'){$s->pdo->exec('DELETE FROM fixture_posts WHERE poster_id=8');}
    elseif($race==='removed-user'){$s->pdo->exec('DELETE FROM fixture_users WHERE user_id=8');}
    elseif($race==='renamed-user'){$s->pdo->exec("UPDATE fixture_users SET username='New name' WHERE user_id=8");}
    else{$s->pdo->exec('UPDATE fixture_users SET user_active=0 WHERE user_id=1');}
   };
   $out=recount_run(strpos($race,'actor-')===0?$lang['Not_Authorised']:($race==='lost-owner'?$lang['Maintenance_user_sync_failed']:''));
   $expected=array('new-post'=>3,'policy-off'=>0,'policy-on'=>3,'removed-forum'=>1,'removed-posts'=>0,'actor-before-update'=>99,'actor-after-update'=>2);
   if(isset($expected[$race])){recount_check(recount_value('SELECT user_posts FROM fixture_users WHERE user_id=8')===$expected[$race],'Current recount result for '.$race);}
   if($race==='removed-user'){recount_check($out['skipped']===array(8)&&recount_value('SELECT COUNT(*) FROM fixture_users WHERE user_id=8')===0,'Deleted account reported, not recreated');}
   if($race==='renamed-user'){recount_check($out['changed'][0]['username']==='New name','Report uses current name');}
  }
  foreach(array('lock','SELECT u.user_id','UPDATE fixture_users SET user_posts','SELECT user_id, username, user_posts') as $failure){
   recount_fixture($engine);$recount_server->failure=$failure;recount_run($failure==='lock'?$lang['Attachment_storage_busy']:$lang['Maintenance_user_sync_failed']);
   recount_check(recount_value('SELECT user_posts FROM fixture_users WHERE user_id=8')===($failure==='SELECT user_id, username, user_posts'?2:99),'Failure preserves or reports already completed work');
   recount_check(recount_value('SELECT user_posts FROM fixture_users WHERE user_id=9')===7,'Failure stops subsequent users');
  }
  foreach(array('get','sid-array','wrong-sid','inactive','demoted','no-admin-session') as $case){
   recount_fixture($engine);$request=$_POST;$expected=$lang['Not_Authorised'];
   if($case==='get'){$_SERVER['REQUEST_METHOD']='GET';$expected=$lang['Session_invalid'];}
   elseif($case==='sid-array'){$request['sid']=array();$expected=$lang['Session_invalid'];}
   elseif($case==='wrong-sid'){$request['sid']='bad';$expected=$lang['Session_invalid'];}
   elseif($case==='inactive'){$recount_server->pdo->exec('UPDATE fixture_users SET user_active=0 WHERE user_id=1');}
   elseif($case==='demoted'){$recount_server->pdo->exec('UPDATE fixture_users SET user_level=0 WHERE user_id=1');}
   else{$userdata['session_admin']=false;}
   recount_run($expected,$request);recount_check(recount_value('SELECT user_posts FROM fixture_users WHERE user_id=8')===99,'Denied requests do not write');
  }
  recount_fixture($engine,20);$hash=md5('GeneralDB_Maintenanceadmin_db_maintenance.php');$recount_server->pdo->exec("INSERT INTO fixture_junior VALUES (20,'".$hash."')");recount_run();
  foreach(array('missing','foreign','logged-out','admin-lost','case-changed') as $case){
   recount_fixture($engine);$sql=array('missing'=>'DELETE FROM fixture_sessions','foreign'=>'UPDATE fixture_sessions SET session_user_id=20','logged-out'=>'UPDATE fixture_sessions SET session_logged_in=0','admin-lost'=>'UPDATE fixture_sessions SET session_admin=0','case-changed'=>"UPDATE fixture_sessions SET session_id='FIXTURE-SID'");$recount_server->pdo->exec($sql[$case]);
   recount_run($lang['Not_Authorised']);recount_check(recount_value('SELECT user_posts FROM fixture_users WHERE user_id=8')===99,'Invalid live session cannot recount');
  }
  foreach(array('deleted','logged-out','admin-lost') as $case){
   recount_fixture($engine);$recount_server->hook=function($sql)use($case){if(strpos($sql,'UPDATE fixture_users SET user_posts')!==0){return;}$s=$GLOBALS['recount_server'];$s->hook=null;$s->pdo->exec($case==='deleted'?'DELETE FROM fixture_sessions':('UPDATE fixture_sessions SET '.($case==='logged-out'?'session_logged_in':'session_admin').'=0'));};
   recount_run($lang['Not_Authorised']);recount_check(recount_value('SELECT user_posts FROM fixture_users WHERE user_id=8')===99,'Late session revocation blocks actual user counter write');
  }
  recount_fixture($engine);$recount_server->failure='UPDATE fixture_users SET user_posts';$recount_server->lostAck=true;recount_run($lang['Maintenance_user_sync_failed']);recount_check(recount_value('SELECT user_posts FROM fixture_users WHERE user_id=8')===2,'Lost acknowledgement is not a rollback');$recount_server->failure='';recount_run();
  recount_fixture($engine,20);$wrong=md5('GroupsPermissionsadmin_ug_auth.php?mode=group');$recount_server->pdo->exec("INSERT INTO fixture_junior VALUES (20,'".$wrong."')");recount_run($lang['Not_Authorised']);
  recount_fixture($engine,20);$recount_server->pdo->exec("INSERT INTO fixture_junior VALUES (20,'".$hash."')");$recount_server->hook=function($sql){if(strpos($sql,'UPDATE fixture_users SET user_posts')===0){$GLOBALS['recount_server']->hook=null;$GLOBALS['recount_server']->pdo->exec('DELETE FROM fixture_junior');}};recount_run($lang['Not_Authorised']);recount_check(recount_value('SELECT user_posts FROM fixture_users WHERE user_id=8')===99,'Delegated permission guarded inside UPDATE');
  recount_fixture($engine);$contended=false;$recount_server->hook=function($sql) use(&$contended){if(strpos($sql,'SELECT u.user_id')===0){$GLOBALS['recount_server']->hook=null;$other=new attach_mutation_lock(new RecountForum(),false);$contended=!$other->acquired;$other->release();}};recount_run();recount_check($contended,'Shared writer acquired before selecting candidates');
  foreach(array('success','failure','invalid','skipped','concurrent-disable') as $case){foreach(array(0,1) as $disabled){
   recount_fixture($engine);if($case==='failure'){$recount_server->failure='UPDATE fixture_users SET user_posts';}if($case==='invalid'){$_POST['sid']='bad';}
   $recount_server->pdo->exec("UPDATE fixture_config SET config_value='".$disabled."'");
   if($case==='concurrent-disable'){$recount_server->hook=function($sql){if(strpos($sql,'UPDATE fixture_users SET user_posts')!==0){return;}$GLOBALS['recount_server']->hook=null;$GLOBALS['recount_server']->pdo->exec("UPDATE fixture_config SET config_value='1'");};}
   if($case==='skipped'){$recount_server->hook=function($sql){if(strpos($sql,'UPDATE fixture_users SET user_posts')===0){$GLOBALS['recount_server']->hook=null;$GLOBALS['recount_server']->pdo->exec('DELETE FROM fixture_users WHERE user_id=8');}};}
   $db=new RecountForum();$board_locks=array();$caught='';ob_start();try{eval($branch);}catch(RecountControllerFailure $e){$caught=$e->getMessage();}finally{$html=ob_get_clean();}
   recount_check(recount_value('SELECT config_value FROM fixture_config')===($case==='concurrent-disable'?1:$disabled),'Recount preserves current board state');
   recount_check(($caught!=='')===in_array($case,array('failure','invalid'),true),'Actual controller failure status');
   if($case==='success'){recount_check(strpos($html,'Grüße &lt;img src=x&gt;')!==false&&strpos($html,'<img src=x>')===false,'Actual current report escapes UTF8 username');}
   if($case==='failure'){recount_check(strpos($html,'Grüße')===false,'Failed write not presented as changed');}
   if($case==='skipped'){recount_check(strpos($html,sprintf($lang['Maintenance_user_counter_skipped'],8))!==false,'Actual controller reports disappeared user');}
  }}
  echo $engine.' '.$locale." user post recount passed.\n";
 }}
}finally{restore_error_handler();}
