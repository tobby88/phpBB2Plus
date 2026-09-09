<?php
$root=dirname(dirname(__DIR__)).'/phpBB2/';
foreach(array('IN_PHPBB'=>true,'ADMIN'=>1,'MOD'=>2,'USER'=>0,'GENERAL_ERROR'=>202,'ATTACHMENTS_TABLE'=>'fixture_links','USERS_TABLE'=>'fixture_users','DELETED'=>-1,'TOPICS_TABLE'=>'fixture_topics','VOTE_DESC_TABLE'=>'fixture_polls','VOTE_RESULTS_TABLE'=>'fixture_options','VOTE_USERS_TABLE'=>'fixture_voters','JR_ADMIN_TABLE'=>'fixture_junior') as $key=>$value){define($key,$value);}
require_once $root.'includes/functions_maintenance_polls.php';
function poll_mtnc_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
function message_die($code,$message){throw new RuntimeException($message);}
class PollMaintenanceControllerFailure extends RuntimeException {}
function throw_error($message){throw new PollMaintenanceControllerFailure($message);}
function lock_db($unlock=false,$delay=true,$ignore=false){$GLOBALS['board_locks'][]=array($unlock,$delay,$ignore);}
class PollMaintenanceRows {public $rows;function __construct($rows){$this->rows=$rows;}}
$dsn=getenv('PHPBB_POLL_MAINTENANCE_TEST_DSN');$native=$dsn!==false&&$dsn!=='';
if($native){poll_mtnc_check(preg_match('/^mysql:host=127\.0\.0\.1;port=33119;dbname=codex_poll_mtnc_[a-f0-9]{16};charset=utf8mb4$/D',$dsn)===1,'Only owned local schemas allowed');}
class PollMaintenanceServer {
 public $pdo;public $owner=null;public $hook=null;public $failure='';public $queries=array();
 function __construct($engine){
  $this->pdo=$GLOBALS['native']?new PDO($GLOBALS['dsn'],'root',''):new PDO('sqlite::memory:');$this->pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
  $definitions=array('users'=>'user_id INTEGER PRIMARY KEY,username VARCHAR(255),user_level INTEGER,user_active INTEGER','topics'=>'topic_id INTEGER PRIMARY KEY,topic_vote INTEGER','polls'=>'vote_id INTEGER PRIMARY KEY,topic_id INTEGER,vote_text VARCHAR(255),vote_start INTEGER,vote_length INTEGER','options'=>'vote_id INTEGER,vote_option_id INTEGER,vote_option_text VARCHAR(255),vote_result INTEGER','voters'=>'vote_id INTEGER,vote_user_id INTEGER,vote_user_ip VARCHAR(45)','junior'=>'user_id INTEGER,user_jr_admin VARCHAR(255)');
  foreach($definitions as $name=>$definition){$this->pdo->exec('DROP TABLE IF EXISTS fixture_'.$name);$this->pdo->exec('CREATE TABLE fixture_'.$name.' ('.$definition.')'.($GLOBALS['native']?' ENGINE='.$engine:''));}
  $this->pdo->exec("INSERT INTO fixture_users VALUES (1,'Root',1,1),(20,'Junior',0,1),(8,'Member',0,1)");
  $this->pdo->exec('INSERT INTO fixture_topics VALUES (10,0),(20,1),(30,1),(40,0)');
  $this->pdo->exec("INSERT INTO fixture_polls VALUES (1,10,'valid',1,0),(2,99,'orphan',1,0),(3,30,'<script>Grüße</script>',1,0),(4,40,'duplicate one',1,0),(5,40,'duplicate two',1,0)");
  $this->pdo->exec("INSERT INTO fixture_options VALUES (1,1,'one',3),(1,2,'two',4),(2,1,'orphan',9),(4,1,'first',20),(5,1,'second',30),(99,1,'absent parent',40)");
  $this->pdo->exec("INSERT INTO fixture_voters VALUES (1,8,'192.0.2.8'),(1,-1,'192.0.2.9'),(1,77,'192.0.2.77'),(2,8,'192.0.2.8'),(99,8,'192.0.2.8'),(3,8,'192.0.2.8')");

 }
}
class PollMaintenanceForum {
 public $dbname='poll-maintenance-fixture';
 function sql_query($sql){throw new RuntimeException('Unlocked main connection used');}
 function sql_dedicated_connection(){return new PollMaintenanceConnection($GLOBALS['poll_mtnc_server']);}
}
class PollMaintenanceConnection {
 public $server;public $pdo;public $db_connect_id=true;public $closed=false;public $affected=0;
 function __construct($server){$this->server=$server;$this->pdo=$GLOBALS['native']?new PDO($GLOBALS['dsn'],'root','',array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION)):$server->pdo;}
 function sql_query($sql){
  if($this->closed){return false;}$s=$this->server;$s->queries[]=$sql;
  if(strpos($sql,'SELECT GET_LOCK(')===0){
   if($s->failure==='lock'){return new PollMaintenanceRows(array(array('acquired'=>0)));}
   $rows=$GLOBALS['native']?$this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC):array(array('acquired'=>$s->owner===null?1:0));
   if((int)$rows[0]['acquired']===1){$s->owner=$this;}return new PollMaintenanceRows($rows);
  }
  poll_mtnc_check($s->owner===$this,'Every protected query uses the owning connection');
  if(is_callable($s->hook)){call_user_func($s->hook,$sql,$this);}
  if($this->closed||($s->failure!==''&&strpos($sql,$s->failure)===0)){return false;}
  try{$r=$this->pdo->query($sql);$this->affected=$r->rowCount();return preg_match('/^SELECT/',$sql)?new PollMaintenanceRows($r->fetchAll(PDO::FETCH_ASSOC)):true;}catch(PDOException $e){return false;}
 }
 function sql_fetchrow($r){return array_shift($r->rows);}
 function sql_fetchrowset($r){return $r->rows;}
 function sql_freeresult($r){}
 function sql_affectedrows(){return $this->affected;}
 function sql_escape($s){return substr($this->pdo->quote($s),1,-1);}
 function sql_close(){if($this->server->owner===$this){$this->server->owner=null;}$this->closed=true;$this->db_connect_id=false;$this->pdo=null;}
}

function poll_mtnc_fixture($engine,$actor=1){
 global $poll_mtnc_server,$userdata,$phpEx,$phpbb_root_path,$root,$board_locks;
 $poll_mtnc_server=new PollMaintenanceServer($engine);$userdata=array('user_id'=>$actor,'user_level'=>ADMIN,'session_logged_in'=>true,'session_admin'=>true,'session_id'=>'fixture-sid');
 $_SERVER['REQUEST_METHOD']='POST';$_POST=array('sid'=>'fixture-sid');$phpEx='php';$phpbb_root_path=$root;$board_locks=array();
}
function poll_mtnc_value($sql){return (int)$GLOBALS['poll_mtnc_server']->pdo->query($sql)->fetchColumn();}
function poll_mtnc_run($expected='',$request=null){
 $caught='';$result=null;try{$result=dbmtnc_maintain_polls(new PollMaintenanceForum(),$request===null?$_POST:$request);}catch(PhpbbAclException $e){$caught=$e->getMessage();}
 poll_mtnc_check($caught===$expected,'Expected maintenance outcome: '.$caught.' / '.$expected);poll_mtnc_check($GLOBALS['poll_mtnc_server']->owner===null,'Owner released');return $result;
}
function phpbb_admin_html($value){return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
$controller=file_get_contents($root.'admin/admin_db_maintenance.php');$a=strpos($controller,"case 'check_vote':");$b=strpos($controller,"case 'check_pm':",$a);
poll_mtnc_check($a!==false&&$b>$a,'Actual poll controller branch found');$branch='switch($function){'.substr($controller,$a,$b-$a).'}';
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{
 foreach($native?array('MyISAM','InnoDB'):array('SQLite') as $engine){foreach(array('english','german') as $locale){
  $lang=array('Not_Authorised'=>'not-authorized','Session_invalid'=>'session-invalid','Attachment_storage_busy'=>'busy');$phpEx='php';include $root.'language/lang_'.$locale.'/lang_dbmtnc.php';
  poll_mtnc_fixture($engine);$out=poll_mtnc_run();
  foreach(array('polls_removed'=>1,'options_removed'=>2,'voters_removed'=>2,'voters_anonymized'=>1,'topics_updated'=>3,'review_count'=>3) as $key=>$expected){poll_mtnc_check($out[$key]===$expected,'Accurate affected count: '.$key);}
  poll_mtnc_check(array_map('intval',array_column($out['review'],'vote_id'))===array(3,4,5),'Optionless and duplicate polls retained for source repair');
  poll_mtnc_check(poll_mtnc_value('SELECT COUNT(*) FROM fixture_voters WHERE vote_id=3')===1,'Optionless poll keeps its vote history');
  poll_mtnc_check(poll_mtnc_value("SELECT COUNT(*) FROM fixture_voters WHERE vote_id=1 AND vote_user_id=-1 AND vote_user_ip IN ('192.0.2.9','192.0.2.77')")===2,'Anonymous and deleted-account votes retain IP-based identity');
  poll_mtnc_check(poll_mtnc_value('SELECT SUM(vote_result) FROM fixture_options WHERE vote_id=1')===7,'Aggregate results are not guessed from voter records');
  poll_mtnc_check(poll_mtnc_value('SELECT COUNT(*) FROM fixture_topics')===4&&poll_mtnc_value('SELECT COUNT(*) FROM fixture_users')===3,'No topics or accounts removed');
  $again=poll_mtnc_run();foreach(array('polls_removed','options_removed','voters_removed','voters_anonymized','topics_updated') as $key){poll_mtnc_check($again[$key]===0,'Repeated repair is a no-op: '.$key);}
  poll_mtnc_check($again['review_count']===3,'Unresolved source repairs remain visible on repeat');
  foreach(array('topic','parent','user','new-poll','removed-poll') as $race){
   poll_mtnc_fixture($engine);$poll_mtnc_server->hook=function($sql) use($race){
    $prefix=$race==='topic'?'DELETE FROM fixture_polls':($race==='parent'?'DELETE FROM fixture_options':($race==='user'?'UPDATE fixture_voters':'UPDATE fixture_topics'));
    if(strpos($sql,$prefix)!==0){return;}$s=$GLOBALS['poll_mtnc_server'];$s->hook=null;
    if($race==='topic'){$s->pdo->exec('INSERT INTO fixture_topics VALUES (99,1)');}
    elseif($race==='parent'){$s->pdo->exec("INSERT INTO fixture_polls VALUES (99,20,'restored parent',1,0)");}
    elseif($race==='user'){$s->pdo->exec("INSERT INTO fixture_users VALUES (77,'restored',0,1)");}
    elseif($race==='new-poll'){$s->pdo->exec("INSERT INTO fixture_polls VALUES (77,20,'new poll',1,0)");$s->pdo->exec("INSERT INTO fixture_options VALUES (77,1,'new answer',0)");}
    else{$s->pdo->exec('DELETE FROM fixture_polls WHERE vote_id=1');}
   };
   $out=poll_mtnc_run();
   if($race==='topic'){poll_mtnc_check($out['polls_removed']===0&&poll_mtnc_value('SELECT COUNT(*) FROM fixture_options WHERE vote_id=2')===1&&poll_mtnc_value('SELECT COUNT(*) FROM fixture_voters WHERE vote_id=2')===1,'Restored topic protects poll and both dependent tables');}
   elseif($race==='parent'){poll_mtnc_check(poll_mtnc_value('SELECT COUNT(*) FROM fixture_options WHERE vote_id=99')===1&&poll_mtnc_value('SELECT COUNT(*) FROM fixture_voters WHERE vote_id=99')===1,'Restored parent protects orphan candidates at each DELETE');}
   elseif($race==='user'){poll_mtnc_check($out['voters_anonymized']===0&&poll_mtnc_value('SELECT COUNT(*) FROM fixture_voters WHERE vote_user_id=77')===1,'Restored user protects current vote identity');}
   elseif($race==='new-poll'){poll_mtnc_check(poll_mtnc_value('SELECT topic_vote FROM fixture_topics WHERE topic_id=20')===1,'Newly published poll is not hidden by stale flag reset');}
   else{poll_mtnc_check(poll_mtnc_value('SELECT topic_vote FROM fixture_topics WHERE topic_id=10')===0,'Removed poll does not leave a stale enabled flag');}
  }
  foreach(array('DELETE FROM fixture_polls','DELETE FROM fixture_options','DELETE FROM fixture_voters','UPDATE fixture_voters','UPDATE fixture_topics') as $prefix){
   foreach(array('failure','actor','owner') as $race){
    poll_mtnc_fixture($engine);$poll_mtnc_server->hook=function($sql,$connection) use($prefix,$race){
     if(strpos($sql,$prefix)!==0){return;}$s=$GLOBALS['poll_mtnc_server'];$s->hook=null;
     if($race==='actor'){$s->pdo->exec('UPDATE fixture_users SET user_active=0 WHERE user_id=1');}elseif($race==='owner'){$connection->sql_close();}else{$s->failure=$prefix;}
    };
    poll_mtnc_run($lang[$race==='actor'?'Not_Authorised':'Maintenance_poll_failed']);
    poll_mtnc_check(poll_mtnc_value('SELECT COUNT(*) FROM fixture_polls WHERE vote_id=3')===1&&poll_mtnc_value('SELECT COUNT(*) FROM fixture_voters WHERE vote_id=3')===1,'Interrupted repair never destroys optionless source');
    $poll_mtnc_server->failure='';$poll_mtnc_server->pdo->exec('UPDATE fixture_users SET user_active=1 WHERE user_id=1');$out=poll_mtnc_run();
    poll_mtnc_check($out['review_count']===3&&poll_mtnc_value('SELECT COUNT(*) FROM fixture_polls WHERE vote_id=2')===0,'Retry completes dependent cleanup after partial table changes');
   }
  }
  foreach(array('get','bad-sid','array-sid','inactive','no-admin','demoted','lock') as $case){
   poll_mtnc_fixture($engine);$request=$_POST;$expected=$lang['Session_invalid'];
   if($case==='get'){$_SERVER['REQUEST_METHOD']='GET';}elseif($case==='bad-sid'){$request['sid']='bad';}elseif($case==='array-sid'){$request['sid']=array();}
   elseif($case==='lock'){$poll_mtnc_server->failure='lock';$expected=$lang['Attachment_storage_busy'];}
   else{$expected=$lang['Not_Authorised'];if($case==='inactive'){$poll_mtnc_server->pdo->exec('UPDATE fixture_users SET user_active=0 WHERE user_id=1');}elseif($case==='no-admin'){$userdata['session_admin']=false;}else{$poll_mtnc_server->pdo->exec('UPDATE fixture_users SET user_level=0 WHERE user_id=1');}}
   poll_mtnc_run($expected,$request);poll_mtnc_check(poll_mtnc_value('SELECT COUNT(*) FROM fixture_polls')===5&&poll_mtnc_value('SELECT COUNT(*) FROM fixture_voters')===6,'Denied repair leaves data unchanged');
  }
  poll_mtnc_fixture($engine,20);poll_mtnc_run($lang['Not_Authorised']);$hash=md5('GeneralDB_Maintenanceadmin_db_maintenance.php');$poll_mtnc_server->pdo->exec("INSERT INTO fixture_junior VALUES (20,'".$hash."')");poll_mtnc_run();
  poll_mtnc_fixture($engine);$contended=false;$poll_mtnc_server->hook=function($sql) use(&$contended){if(strpos($sql,'SELECT DISTINCT vote_id FROM fixture_polls')===0){$GLOBALS['poll_mtnc_server']->hook=null;$other=new attach_mutation_lock(new PollMaintenanceForum(),false);$contended=!$other->acquired;$other->release();}};poll_mtnc_run();poll_mtnc_check($contended,'Maintenance participates in the same lock as normal voting/posting');
  poll_mtnc_fixture($engine);for($id=100;$id<405;$id++){$poll_mtnc_server->pdo->exec("INSERT INTO fixture_polls VALUES (".$id.",999,'batch orphan',1,0)");}$out=poll_mtnc_run();poll_mtnc_check($out['polls_removed']===306,'Keyset paging covers more than one batch');
  foreach($poll_mtnc_server->queries as $sql){if(strpos($sql,'DELETE FROM fixture_polls')===0){preg_match('/vote_id IN \(([^)]+)\)/',$sql,$m);poll_mtnc_check(count(explode(',',$m[1]))<=100,'Delete candidate batch bounded to 100 IDs');}}
  poll_mtnc_fixture($engine);for($id=100;$id<205;$id++){$poll_mtnc_server->pdo->exec("INSERT INTO fixture_topics VALUES (".$id.",1)");$poll_mtnc_server->pdo->exec("INSERT INTO fixture_polls VALUES (".$id.",".$id.",'missing answers',1,0)");}$out=poll_mtnc_run();poll_mtnc_check($out['review_count']===108&&count($out['review'])===100,'Unresolved review is bounded and reports the full count');
  poll_mtnc_fixture($engine);$db=new PollMaintenanceForum();$function='check_vote';ob_start();eval($branch);$html=ob_get_clean();
  poll_mtnc_check(strpos($html,sprintf($lang['Maintenance_poll_summary'],1,2,2,1,3))!==false&&strpos($html,'&lt;script&gt;Grüße&lt;/script&gt;')!==false&&strpos($html,'<script>')===false,'Actual localized controller reports confirmed counts and escapes source text');
  poll_mtnc_check(!$board_locks,'Controller leaves global board-disable state untouched');
  poll_mtnc_fixture($engine);$db=new PollMaintenanceForum();$poll_mtnc_server->failure='DELETE FROM fixture_options';$caught='';ob_start();try{eval($branch);}catch(PollMaintenanceControllerFailure $e){$caught=$e->getMessage();}finally{ob_end_clean();}
  poll_mtnc_check($caught===$lang['Maintenance_poll_failed']&&$poll_mtnc_server->owner===null&&!$board_locks,'Actual controller handles partial failures without a stuck board lock');
  echo $engine.' '.$locale." current-state poll maintenance and controller checks passed.\n";
 }}
}finally{restore_error_handler();}
