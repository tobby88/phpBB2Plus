<?php
$root=dirname(dirname(__DIR__)).'/phpBB2/';
foreach(array('IN_PHPBB'=>true,'ADMIN'=>1,'MOD'=>2,'USER'=>0,'TOPIC_MOVED'=>2,'GENERAL_ERROR'=>202,'ATTACHMENTS_TABLE'=>'fixture_links','USERS_TABLE'=>'fixture_users','TOPICS_TABLE'=>'fixture_topics','POSTS_TABLE'=>'fixture_posts','FORUMS_TABLE'=>'fixture_forums','JR_ADMIN_TABLE'=>'fixture_junior') as $key=>$value){define($key,$value);}
require_once $root.'includes/functions_maintenance_posts.php';
function sync_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
function message_die($code,$message){throw new RuntimeException($message);}
class SyncControllerFailure extends RuntimeException {}
function throw_error($message){throw new SyncControllerFailure($message);}
function lock_db($unlock=false,$delay=true,$ignore=false){$GLOBALS['board_locks'][]=array($unlock,$delay,$ignore);}
class SyncRows {public $rows;function __construct($rows){$this->rows=$rows;}}
$dsn=getenv('PHPBB_POST_SYNC_TEST_DSN');$native=$dsn!==false&&$dsn!=='';
if($native){sync_check(preg_match('/^mysql:host=127\.0\.0\.1;port=33119;dbname=codex_postsync_[a-f0-9]{16};charset=utf8mb4$/D',$dsn)===1,'Only owned local schemas allowed');}
class SyncServer {
 public $pdo;public $owner=null;public $hook=null;public $failure='';public $queries=array();
 function __construct($engine){
  $this->pdo=$GLOBALS['native']?new PDO($GLOBALS['dsn'],'root',''):new PDO('sqlite::memory:');$this->pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
  $definitions=array('users'=>'user_id INTEGER PRIMARY KEY,username VARCHAR(255),user_level INTEGER,user_active INTEGER','topics'=>'topic_id INTEGER PRIMARY KEY,forum_id INTEGER,topic_title VARCHAR(255),topic_status INTEGER,topic_moved_id INTEGER,topic_replies INTEGER,topic_first_post_id INTEGER,topic_last_post_id INTEGER','posts'=>'post_id INTEGER PRIMARY KEY,topic_id INTEGER,forum_id INTEGER','forums'=>'forum_id INTEGER PRIMARY KEY,forum_name VARCHAR(255),forum_topics INTEGER,forum_posts INTEGER,forum_last_post_id INTEGER','junior'=>'user_id INTEGER,user_jr_admin VARCHAR(255)');
  foreach($definitions as $name=>$definition){$this->pdo->exec('DROP TABLE IF EXISTS fixture_'.$name);$this->pdo->exec('CREATE TABLE fixture_'.$name.' ('.$definition.')'.($GLOBALS['native']?' ENGINE='.$engine:''));}
  $this->pdo->exec("INSERT INTO fixture_users VALUES (1,'Root',1,1),(20,'Junior',0,1)");
  $this->pdo->exec("INSERT INTO fixture_topics VALUES (1,1,'<b>Grüße</b>',0,0,99,99,99),(2,1,'Empty',0,0,7,7,7),(3,1,'Moved',2,1,99,99,2),(4,1,'Missing target',2,999,99,99,99),(5,1,'Self redirect',2,5,99,99,99),(6,1,'Invalid normal',0,1,99,99,99),(7,1,'Redirect chain',2,3,99,99,99),(8,2,'Stable',0,0,0,8,8),(10,4,'Only redirect',2,1,99,99,2)");
  $this->pdo->exec('INSERT INTO fixture_posts VALUES (1,1,1),(2,1,1),(8,8,2)');
  $this->pdo->exec("INSERT INTO fixture_forums VALUES (1,'<i>Forum</i>',99,99,99),(2,'Stable',1,1,8),(3,'Empty',99,99,99),(4,'Redirect only',99,99,99)");
 }
}
class SyncForum {
 public $dbname='post-sync-fixture';
 function sql_query($sql){throw new RuntimeException('Unlocked main connection used');}
 function sql_dedicated_connection(){return new SyncConnection($GLOBALS['sync_server']);}
}
class SyncConnection {
 public $server;public $pdo;public $db_connect_id=true;public $closed=false;public $affected=0;
 function __construct($server){$this->server=$server;$this->pdo=$GLOBALS['native']?new PDO($GLOBALS['dsn'],'root','',array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION)):$server->pdo;}
 function sql_query($sql){
  if($this->closed){return false;}$s=$this->server;$s->queries[]=$sql;
  if(strpos($sql,'SELECT GET_LOCK(')===0){
   if($s->failure==='lock'){return new SyncRows(array(array('acquired'=>0)));}
   $rows=$GLOBALS['native']?$this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC):array(array('acquired'=>$s->owner===null?1:0));
   if((int)$rows[0]['acquired']===1){$s->owner=$this;}return new SyncRows($rows);
  }
  sync_check($s->owner===$this,'Every protected query uses the owning connection');
  if(is_callable($s->hook)){call_user_func($s->hook,$sql,$this);}
  if($this->closed||($s->failure!==''&&strpos($sql,$s->failure)===0)){return false;}
  try{$r=$this->pdo->query($sql);$this->affected=$r->rowCount();return preg_match('/^SELECT/',$sql)?new SyncRows($r->fetchAll(PDO::FETCH_ASSOC)):true;}catch(PDOException $e){return false;}
 }
 function sql_fetchrow($r){return array_shift($r->rows);}
 function sql_fetchrowset($r){return $r->rows;}
 function sql_freeresult($r){}
 function sql_affectedrows(){return $this->affected;}
 function sql_escape($s){return substr($this->pdo->quote($s),1,-1);}
 function sql_close(){if($this->server->owner===$this){$this->server->owner=null;}$this->closed=true;$this->db_connect_id=false;$this->pdo=null;}
}
function sync_fixture($engine,$actor=1){
 global $sync_server,$userdata,$phpEx,$phpbb_root_path,$root;
 $sync_server=new SyncServer($engine);$userdata=array('user_id'=>$actor,'user_level'=>ADMIN,'session_logged_in'=>true,'session_admin'=>true,'session_id'=>'fixture-sid');
 $_SERVER['REQUEST_METHOD']='POST';$_POST=array('sid'=>'fixture-sid');$_GET=array();$phpEx='php';$phpbb_root_path=$root;
}
function sync_value($sql){return (int)$GLOBALS['sync_server']->pdo->query($sql)->fetchColumn();}
function sync_run($expected='',$function='synchronize_post',$request=null){
 $caught='';$result=null;try{$result=dbmtnc_synchronize_posts(new SyncForum(),$function,$request===null?$_POST:$request);}catch(PhpbbAclException $e){$caught=$e->getMessage();}
 sync_check($caught===$expected,'Expected synchronization outcome: '.$caught.' / '.$expected);sync_check($GLOBALS['sync_server']->owner===null,'Owner released');return $result;
}
function sync_direct($state){$_SERVER['REQUEST_METHOD']='GET';return array('db_state'=>$state,'dbmtnc_token'=>hash_hmac('sha256','synchronize_post_direct|'.$state,$GLOBALS['userdata']['session_id']));}
$controller=file_get_contents($root.'admin/admin_db_maintenance.php');$a=strpos($controller,"case 'synchronize_post':");$b=strpos($controller,"case 'synchronize_user':",$a);
sync_check($a!==false&&$b>$a,'Actual controller branch found');$branch='switch($function){'.substr($controller,$a,$b-$a).'}';
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{
 foreach($native?array('MyISAM','InnoDB'):array('SQLite') as $engine){foreach(array('english','german') as $locale){
  $lang=array('Not_Authorised'=>'not-authorized','Session_invalid'=>'session-invalid','Attachment_storage_busy'=>'busy');$phpEx='php';include $root.'language/lang_'.$locale.'/lang_dbmtnc.php';
  sync_fixture($engine);$before=$sync_server->pdo->query('SELECT * FROM fixture_posts')->fetchAll(PDO::FETCH_ASSOC);$out=sync_run();
  sync_check(count($out['topics'])===1&&count($out['redirects'])===2&&count($out['forums'])===3,'Only inconsistent counters change');
  sync_check($out['review']===array(2,4,5,6,7),'Empty and invalid destinations reported, not deleted');
  sync_check(sync_value('SELECT topic_replies FROM fixture_topics WHERE topic_id=1')===1,'Normal reply count');
  sync_check(sync_value('SELECT topic_last_post_id FROM fixture_topics WHERE topic_id=3')===2,'Historical redirect cutoff');
  sync_check(sync_value('SELECT forum_topics FROM fixture_forums WHERE forum_id=1')===7,'Forum counts include redirects');
  foreach(array(3,4) as $emptyForum){foreach(array('forum_posts','forum_last_post_id') as $field){sync_check(sync_value('SELECT '.$field.' FROM fixture_forums WHERE forum_id='.$emptyForum)===0,'Empty forum counters reset');}}
  sync_check(sync_value('SELECT forum_topics FROM fixture_forums WHERE forum_id=4')===1&&sync_value('SELECT forum_last_post_id FROM fixture_forums WHERE forum_id=4')===0,'Redirect-only forum has no own post');
  sync_check($before===$sync_server->pdo->query('SELECT * FROM fixture_posts')->fetchAll(PDO::FETCH_ASSOC)&&sync_value('SELECT COUNT(*) FROM fixture_topics')===9,'No source content deleted');
  $out=sync_run();sync_check(!$out['topics']&&!$out['redirects']&&!$out['forums']&&$out['review']===array(2,4,5,6,7),'Repeat no-op retains unresolved report');
  foreach(array('new-post','delete-posts','move-target','change-to-redirect','new-forum-post','removed-target','actor-revoked','lost-owner') as $race){
   sync_fixture($engine);$sync_server->hook=function($sql,$connection) use($race){
    $prefix=$race==='new-forum-post'?'UPDATE fixture_forums':'UPDATE fixture_topics';if(strpos($sql,$prefix)!==0){return;}$s=$GLOBALS['sync_server'];$s->hook=null;
    if($race==='lost-owner'){$connection->sql_close();return;}
    if($race==='new-post'){$s->pdo->exec('INSERT INTO fixture_posts VALUES (9,1,1)');$s->pdo->exec('UPDATE fixture_topics SET topic_replies=2,topic_last_post_id=9 WHERE topic_id=1');}
    elseif($race==='delete-posts'){$s->pdo->exec('DELETE FROM fixture_posts WHERE topic_id=1');}
    elseif($race==='move-target'){$s->pdo->exec('UPDATE fixture_topics SET forum_id=2,topic_replies=4 WHERE topic_id=1');}
    elseif($race==='change-to-redirect'){$s->pdo->exec('UPDATE fixture_topics SET topic_status=2,topic_moved_id=8 WHERE topic_id=1');}
    elseif($race==='new-forum-post'){$s->pdo->exec('INSERT INTO fixture_posts VALUES (9,1,1)');}
    elseif($race==='removed-target'){$s->pdo->exec('DELETE FROM fixture_topics WHERE topic_id=1');}
    else{$s->pdo->exec('UPDATE fixture_users SET user_active=0 WHERE user_id=1');}
   };
   $out=sync_run($race==='actor-revoked'?$lang['Not_Authorised']:($race==='lost-owner'?$lang['Maintenance_post_sync_failed']:''));
   if($race==='new-post'){sync_check(sync_value('SELECT topic_replies FROM fixture_topics WHERE topic_id=1')===2&&sync_value('SELECT topic_last_post_id FROM fixture_topics WHERE topic_id=1')===9,'Current new post counters survive');sync_check(sync_value('SELECT topic_last_post_id FROM fixture_topics WHERE topic_id=3')===2,'New destination replies excluded from old redirect');}
   if($race==='delete-posts'){sync_check(in_array(1,$out['review'],true)&&sync_value('SELECT topic_replies FROM fixture_topics WHERE topic_id=1')===99,'Late empty topic untouched and reported');}
   if($race==='move-target'){sync_check(sync_value('SELECT topic_replies FROM fixture_topics WHERE topic_id=1')===4&&in_array(1,$out['review'],true),'Changed forum identity is not overwritten');}
   if($race==='change-to-redirect'){sync_check(sync_value('SELECT topic_replies FROM fixture_topics WHERE topic_id=1')===99&&in_array(1,$out['review'],true),'New redirect identity preserved');}
   if($race==='new-forum-post'){sync_check(sync_value('SELECT forum_posts FROM fixture_forums WHERE forum_id=1')===3&&sync_value('SELECT forum_last_post_id FROM fixture_forums WHERE forum_id=1')===9,'Forum counters computed inside write');}
   if($race==='removed-target'){sync_check(sync_value('SELECT COUNT(*) FROM fixture_topics WHERE topic_id=1')===0,'Removed target not recreated');}
   if($race==='actor-revoked'){sync_check(sync_value('SELECT topic_replies FROM fixture_topics WHERE topic_id=1')===99,'Revoked actor cannot write');}
  }
  foreach(array('lock','SELECT topic_id, forum_id','UPDATE fixture_topics','UPDATE fixture_forums') as $failure){sync_fixture($engine);$sync_server->failure=$failure;sync_run($failure==='lock'?$lang['Attachment_storage_busy']:$lang['Maintenance_post_sync_failed']);}
  foreach(array('get','sid-array','wrong-sid','unknown','bad-token','wrong-function-token','state-array','state-tamper','inactive','no-admin-session') as $case){
   sync_fixture($engine);$function='synchronize_post';$request=$_POST;$expected=$lang['Session_invalid'];
   if($case==='get'){$_SERVER['REQUEST_METHOD']='GET';}elseif($case==='sid-array'){$request['sid']=array();}elseif($case==='wrong-sid'){$request['sid']='bad';}
   elseif($case==='unknown'){$function='unknown';$expected=$lang['Invalid_dbmtnc_request'];}
   elseif(in_array($case,array('inactive','no-admin-session'),true)){$expected=$lang['Not_Authorised'];if($case==='inactive'){$sync_server->pdo->exec('UPDATE fixture_users SET user_active=0 WHERE user_id=1');}else{$userdata['session_admin']=false;}}
   else{$function='synchronize_post_direct';$request=sync_direct('0');$expected=$lang['Invalid_dbmtnc_request'];if($case==='bad-token'){$request['dbmtnc_token']='bad';}elseif($case==='wrong-function-token'){$request['dbmtnc_token']=hash_hmac('sha256','perform_rebuild|0',$userdata['session_id']);}elseif($case==='state-array'){$request['db_state']=array();}else{$request['db_state']='1';}}
   sync_run($expected,$function,$request);sync_check(sync_value('SELECT topic_replies FROM fixture_topics WHERE topic_id=1')===99,'Denied requests leave counters alone');
  }
  foreach(array('0','1') as $state){sync_fixture($engine);sync_run('','synchronize_post_direct',sync_direct($state));}
  sync_fixture($engine,20);$hash=md5('GeneralDB_Maintenanceadmin_db_maintenance.php');$sync_server->pdo->exec("INSERT INTO fixture_junior VALUES (20,'".$hash."')");sync_run();
  sync_fixture($engine,20);sync_run($lang['Not_Authorised']);
  sync_fixture($engine,20);$sync_server->pdo->exec("INSERT INTO fixture_junior VALUES (20,'".$hash."')");$sync_server->hook=function($sql){if(strpos($sql,'UPDATE fixture_topics')===0){$GLOBALS['sync_server']->hook=null;$GLOBALS['sync_server']->pdo->exec('DELETE FROM fixture_junior');}};sync_run($lang['Not_Authorised']);sync_check(sync_value('SELECT topic_replies FROM fixture_topics WHERE topic_id=1')===99,'Current delegation guarded at write');
  sync_fixture($engine);$contended=false;$sync_server->hook=function($sql) use(&$contended){if(strpos($sql,'SELECT topic_id, forum_id')===0){$GLOBALS['sync_server']->hook=null;$other=new attach_mutation_lock(new SyncForum(),false);$contended=!$other->acquired;$other->release();}};sync_run();sync_check($contended,'Competing writer blocked before snapshot');
  foreach(array('normal','direct0','direct1','invalid') as $mode){foreach(array(false,true) as $fail){
   sync_fixture($engine);$function=$mode==='normal'?'synchronize_post':'synchronize_post_direct';if($mode!=='normal'){$_GET=sync_direct($mode==='direct1'?'1':'0');}if($mode==='invalid'){$_GET['dbmtnc_token']='bad';}if($fail){$sync_server->failure='UPDATE fixture_topics';}
   $db=new SyncForum();$board_locks=array();$caught='';ob_start();try{eval($branch);}catch(SyncControllerFailure $e){$caught=$e->getMessage();}finally{$html=ob_get_clean();}
   $expected=$mode==='normal'?array(array(false,true,false),array(true,true,false)):($mode==='direct0'?array(array(true,true,true)):array());
   sync_check($board_locks===$expected,'Correct ordinary/continuation maintenance-state restoration');sync_check(($caught!=='')===($fail||$mode==='invalid'),'Actual controller reports failure');
   if(!$fail&&$mode!=='invalid'){sync_check(strpos($html,'&lt;b&gt;Grüße&lt;/b&gt;')!==false&&strpos($html,'<b>Grüße')===false,'Actual controller escapes topic names');sync_check(strpos($html,'2, 4, 5, 6, 7')!==false,'Actual controller renders unresolved IDs');}
  }}
  echo $engine.' '.$locale." post synchronization passed.\n";
 }}
}finally{restore_error_handler();}
