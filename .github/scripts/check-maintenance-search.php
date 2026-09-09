<?php
$root=dirname(dirname(__DIR__)).'/phpBB2/';
foreach(array('IN_PHPBB'=>true,'ADMIN'=>1,'MOD'=>2,'USER'=>0,'GENERAL_ERROR'=>202,'ATTACHMENTS_TABLE'=>'fixture_links','USERS_TABLE'=>'fixture_users','POSTS_TABLE'=>'fixture_posts','SEARCH_WORD_TABLE'=>'fixture_words','SEARCH_MATCH_TABLE'=>'fixture_matches','JR_ADMIN_TABLE'=>'fixture_junior') as $key=>$value){define($key,$value);}
require_once $root.'includes/functions_maintenance_search.php';
function cleanup_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
function message_die($code,$message){throw new RuntimeException($message);}
class CleanupControllerFailure extends RuntimeException {}
function throw_error($message){throw new CleanupControllerFailure($message);}
function lock_db($unlock=false,$delay=true,$ignore=false){$GLOBALS['board_locks'][]=array($unlock,$delay,$ignore);}
class CleanupRows {public $rows;function __construct($rows){$this->rows=$rows;}}
$dsn=getenv('PHPBB_CLEANUP_TEST_DSN');$native=$dsn!==false&&$dsn!=='';
if($native){cleanup_check(preg_match('/^mysql:host=127\.0\.0\.1;port=33119;dbname=codex_cleanup_[a-f0-9]{16};charset=utf8mb4$/D',$dsn)===1,'Only owned local schemas allowed');}
class CleanupServer {
 public $pdo;public $owner=null;public $hook=null;public $failure='';public $queries=array();
 function __construct($engine){
  $this->pdo=$GLOBALS['native']?new PDO($GLOBALS['dsn'],'root',''):new PDO('sqlite::memory:');$this->pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
  $definitions=array('users'=>'user_id INTEGER PRIMARY KEY,username VARCHAR(255),user_level INTEGER,user_active INTEGER','words'=>'word_id INTEGER PRIMARY KEY,word_common INTEGER,word_text VARCHAR(255)','matches'=>'word_id INTEGER,post_id INTEGER,title_match INTEGER','posts'=>'post_id INTEGER PRIMARY KEY','junior'=>'user_id INTEGER,user_jr_admin VARCHAR(255)');
  foreach($definitions as $name=>$definition){$this->pdo->exec('DROP TABLE IF EXISTS fixture_'.$name);$this->pdo->exec('CREATE TABLE fixture_'.$name.' ('.$definition.')'.($GLOBALS['native']?' ENGINE='.$engine:''));}
  $this->pdo->exec("INSERT INTO fixture_users VALUES (1,'Root',1,1),(20,'Junior',0,1)");
  $this->pdo->exec("INSERT INTO fixture_words VALUES (1,0,'Grüße'),(2,1,'common'),(3,0,'unused'),(4,0,'orphan-post'),(5,1,'unused-common')");
  $this->pdo->exec('INSERT INTO fixture_posts VALUES (10)');
  $this->pdo->exec('INSERT INTO fixture_matches VALUES (1,10,0),(1,10,1),(2,10,0),(4,99,0),(999,10,0)');

 }
}
class CleanupForum {
 public $dbname='cleanup-fixture';
 function sql_query($sql){throw new RuntimeException('Unlocked main connection used');}
 function sql_dedicated_connection(){return new CleanupConnection($GLOBALS['cleanup_server']);}
}
class CleanupConnection {
 public $server;public $pdo;public $db_connect_id=true;public $closed=false;public $affected=0;
 function __construct($server){$this->server=$server;$this->pdo=$GLOBALS['native']?new PDO($GLOBALS['dsn'],'root','',array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION)):$server->pdo;}
 function sql_query($sql){
  if($this->closed){return false;}$s=$this->server;$s->queries[]=$sql;
  if(strpos($sql,'SELECT GET_LOCK(')===0){
   if($s->failure==='lock'){return new CleanupRows(array(array('acquired'=>0)));}
   $rows=$GLOBALS['native']?$this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC):array(array('acquired'=>$s->owner===null?1:0));
   if((int)$rows[0]['acquired']===1){$s->owner=$this;}return new CleanupRows($rows);
  }
  cleanup_check($s->owner===$this,'Every protected query uses the owning connection');
  if(is_callable($s->hook)){call_user_func($s->hook,$sql,$this);}
  if($this->closed||($s->failure!==''&&strpos($sql,$s->failure)===0)){return false;}
  try{$r=$this->pdo->query($sql);$this->affected=$r->rowCount();return preg_match('/^SELECT/',$sql)?new CleanupRows($r->fetchAll(PDO::FETCH_ASSOC)):true;}catch(PDOException $e){return false;}
 }
 function sql_fetchrow($r){return array_shift($r->rows);}
 function sql_fetchrowset($r){return $r->rows;}
 function sql_freeresult($r){}
 function sql_affectedrows(){return $this->affected;}
 function sql_escape($s){return substr($this->pdo->quote($s),1,-1);}
 function sql_close(){if($this->server->owner===$this){$this->server->owner=null;}$this->closed=true;$this->db_connect_id=false;$this->pdo=null;}
}


function cleanup_fixture($engine,$actor=1){
 global $cleanup_server,$userdata,$phpEx,$phpbb_root_path,$root;
 $cleanup_server=new CleanupServer($engine);$userdata=array('user_id'=>$actor,'user_level'=>ADMIN,'session_logged_in'=>true,'session_admin'=>true,'session_id'=>'fixture-sid');
 $_SERVER['REQUEST_METHOD']='POST';$_POST=array('sid'=>'fixture-sid');$phpEx='php';$phpbb_root_path=$root;
}
function cleanup_value($sql){return (int)$GLOBALS['cleanup_server']->pdo->query($sql)->fetchColumn();}
function cleanup_run($mode,$expected='',$request=null){
 $caught='';$result=null;try{$result=dbmtnc_cleanup_search(new CleanupForum(),$mode,$request===null?$_POST:$request);}catch(PhpbbAclException $e){$caught=$e->getMessage();}
 cleanup_check($caught===$expected,'Expected cleanup outcome: '.$caught.' / '.$expected);cleanup_check($GLOBALS['cleanup_server']->owner===null,'Owner released');return $result;
}
function cleanup_words($count){
 $pdo=$GLOBALS['cleanup_server']->pdo;$pdo->exec('DELETE FROM fixture_matches');$pdo->exec('DELETE FROM fixture_words');
 for($id=1;$id<=$count;$id++){$pdo->exec("INSERT INTO fixture_words VALUES (".$id.",0,'fixture')");}
}
$controller=file_get_contents($root.'admin/admin_db_maintenance.php');$a=strpos($controller,"case 'check_search_wordmatch':");$b=strpos($controller,"case 'rebuild_search_index':",$a);
cleanup_check($a!==false&&$b>$a,'Actual controller branches found');$branch='switch($function){'.substr($controller,$a,$b-$a).'}';
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{
 foreach($native?array('MyISAM','InnoDB'):array('SQLite') as $engine){foreach(array('english','german') as $locale){
  $lang=array('Not_Authorised'=>'not-authorized','Session_invalid'=>'session-invalid','Attachment_storage_busy'=>'busy');$phpEx='php';include $root.'language/lang_'.$locale.'/lang_dbmtnc.php';
  $wordlist='check_search_wordlist';$wordmatch='check_search_wordmatch';
  cleanup_fixture($engine);cleanup_check(cleanup_run($wordlist)===1,'Only unused non-common dictionary word removed');
  cleanup_check(cleanup_value('SELECT COUNT(*) FROM fixture_words WHERE word_id IN (1,2,4,5)')===4,'Referenced/common words retained');
  cleanup_check(cleanup_run($wordlist)===0,'Repeat dictionary cleanup is a no-op');
  cleanup_check(cleanup_run($wordmatch)===3,'Remove missing post, missing word and common-word matches');
  cleanup_check(cleanup_value('SELECT COUNT(*) FROM fixture_matches WHERE word_id=1 AND post_id=10')===2,'Both valid title and body references retained');
  cleanup_check(cleanup_run($wordmatch)===0&&cleanup_run($wordlist)===1,'Repeat match cleanup no-op; newly unused orphan word can be cleaned');
  cleanup_check(cleanup_value('SELECT COUNT(*) FROM fixture_posts')===1&&cleanup_value('SELECT COUNT(*) FROM fixture_users')===2,'Posts/accounts unchanged');
  foreach(array(2,100,101,205) as $count){
   cleanup_fixture($engine);cleanup_words($count);$cleanup_server->hook=function($sql){
    if(strpos($sql,'DELETE FROM fixture_words')!==0){return;}$s=$GLOBALS['cleanup_server'];$s->hook=null;
    $s->pdo->exec('INSERT INTO fixture_matches VALUES (1,10,1)');$s->pdo->exec('UPDATE fixture_words SET word_common=1 WHERE word_id=2');
   };
   cleanup_check(cleanup_run($wordlist)===$count-2,'Current referenced/common words survive tail and full batches');
   cleanup_check(cleanup_value('SELECT COUNT(*) FROM fixture_words WHERE word_id IN (1,2)')===2,'Both protected words survive');
   $batches=0;foreach($cleanup_server->queries as $sql){if(strpos($sql,'DELETE FROM fixture_words')===0){$batches++;preg_match('/word_id IN \\(([^)]+)\\)/',$sql,$m);cleanup_check(count(explode(',',$m[1]))<=100,'Dictionary batch bounded to 100 IDs');}}
   cleanup_check($batches===(int)ceil($count/100),'Keyset pages advance even when first rows retained');
   cleanup_check(cleanup_run($wordlist)===0,'Retained rows do not cause repeat loops');
  }
  cleanup_fixture($engine);$cleanup_server->hook=function($sql){
   if(strpos($sql,'DELETE FROM fixture_matches')!==0){return;}$s=$GLOBALS['cleanup_server'];$s->hook=null;
   $s->pdo->exec('INSERT INTO fixture_posts VALUES (99)');$s->pdo->exec("INSERT INTO fixture_words VALUES (999,0,'restored')");$s->pdo->exec('UPDATE fixture_words SET word_common=0 WHERE word_id=2');
  };
  cleanup_check(cleanup_run($wordmatch)===0&&cleanup_value('SELECT COUNT(*) FROM fixture_matches')===5,'Restored post/word and cleared common flag guard every match');
  cleanup_fixture($engine);$cleanup_server->pdo->exec('DELETE FROM fixture_matches');
  for($id=1;$id<=205;$id++){$cleanup_server->pdo->exec('INSERT INTO fixture_matches VALUES (1,'.$id.',0)');}
  cleanup_check(cleanup_run($wordmatch)===204&&cleanup_value('SELECT COUNT(*) FROM fixture_matches WHERE post_id=10')===1,'Match cleanup pages many orphan post IDs while retaining valid one');
  foreach(array($wordlist,$wordmatch) as $mode){
   $delete=$mode===$wordlist?'DELETE FROM fixture_words':'DELETE FROM fixture_matches';$select=$mode===$wordlist?'SELECT DISTINCT word_id':'SELECT DISTINCT post_id';
   foreach(array('lock',$select,$delete) as $failure){
    cleanup_fixture($engine);$cleanup_server->failure=$failure;cleanup_run($mode,$failure==='lock'?$lang['Attachment_storage_busy']:$lang['Maintenance_search_cleanup_failed']);
    cleanup_check(cleanup_value('SELECT COUNT(*) FROM fixture_words')===5&&cleanup_value('SELECT COUNT(*) FROM fixture_matches')===5,'Initial failure leaves index alone');
   }
   foreach(array('actor','lost-owner') as $race){
    cleanup_fixture($engine);$cleanup_server->hook=function($sql,$connection) use($delete,$race){if(strpos($sql,$delete)!==0){return;}$GLOBALS['cleanup_server']->hook=null;if($race==='lost-owner'){$connection->sql_close();}else{$GLOBALS['cleanup_server']->pdo->exec('UPDATE fixture_users SET user_active=0 WHERE user_id=1');}};
    cleanup_run($mode,$race==='actor'?$lang['Not_Authorised']:$lang['Maintenance_search_cleanup_failed']);cleanup_check(cleanup_value('SELECT COUNT(*) FROM fixture_words')===5&&cleanup_value('SELECT COUNT(*) FROM fixture_matches')===5,'Lost owner or revoked actor cannot delete');
   }
   foreach(array('get','sid-array','wrong-sid','no-admin-session','demoted') as $case){
    cleanup_fixture($engine);$request=$_POST;$expected=$lang['Session_invalid'];
    if($case==='get'){$_SERVER['REQUEST_METHOD']='GET';}elseif($case==='sid-array'){$request['sid']=array();}elseif($case==='wrong-sid'){$request['sid']='bad';}
    elseif($case==='no-admin-session'){$userdata['session_admin']=false;$expected=$lang['Not_Authorised'];}else{$cleanup_server->pdo->exec('UPDATE fixture_users SET user_level=0 WHERE user_id=1');$expected=$lang['Not_Authorised'];}
    cleanup_run($mode,$expected,$request);
   }
   cleanup_fixture($engine,20);$hash=md5('GeneralDB_Maintenanceadmin_db_maintenance.php');$cleanup_server->pdo->exec("INSERT INTO fixture_junior VALUES (20,'".$hash."')");cleanup_run($mode);
   cleanup_fixture($engine,20);cleanup_run($mode,$lang['Not_Authorised']);
   cleanup_fixture($engine,20);$cleanup_server->pdo->exec("INSERT INTO fixture_junior VALUES (20,'".$hash."')");$cleanup_server->hook=function($sql) use($delete){if(strpos($sql,$delete)===0){$GLOBALS['cleanup_server']->hook=null;$GLOBALS['cleanup_server']->pdo->exec('DELETE FROM fixture_junior');}};cleanup_run($mode,$lang['Not_Authorised']);
   cleanup_check(cleanup_value('SELECT COUNT(*) FROM fixture_words')===5&&cleanup_value('SELECT COUNT(*) FROM fixture_matches')===5,'Current delegation guarded inside deletion');
   cleanup_fixture($engine);$contended=false;$cleanup_server->hook=function($sql) use(&$contended,$select){if(strpos($sql,$select)===0){$GLOBALS['cleanup_server']->hook=null;$other=new attach_mutation_lock(new CleanupForum(),false);$contended=!$other->acquired;$other->release();}};cleanup_run($mode);cleanup_check($contended,'Writer lock acquired before cleanup snapshot');
   foreach(array('success','failure','invalid','empty') as $case){
    cleanup_fixture($engine);if($case==='failure'){$cleanup_server->failure=$delete;}if($case==='invalid'){$_POST['sid']='bad';}if($case==='empty'){cleanup_run($mode);}
    $function=$mode;$db=new CleanupForum();$board_locks=array();$caught='';ob_start();try{eval($branch);}catch(CleanupControllerFailure $e){$caught=$e->getMessage();}finally{$html=ob_get_clean();}
    cleanup_check($board_locks===($case==='invalid'?array():array(array(false,true,false),array(true,true,false))),'Actual cleanup controller restores maintenance state');
    cleanup_check(($caught!=='')===in_array($case,array('failure','invalid'),true),'Actual controller reports failure');
    if($case==='success'){cleanup_check(strpos($html,sprintf($lang[$mode===$wordlist?'Affected_row':'Affected_rows'],$mode===$wordlist?1:3))!==false,'Only actual affected rows reported');}
    if($case==='empty'){cleanup_check(strpos($html,$lang['Nothing_to_do'])!==false,'Actual no-op report');}
   }
  }
  foreach(array('query','actor') as $failure){
   cleanup_fixture($engine);cleanup_words(205);$batches=0;$cleanup_server->hook=function($sql) use(&$batches,$failure){if(strpos($sql,'DELETE FROM fixture_words')===0&&++$batches===2){$s=$GLOBALS['cleanup_server'];$s->hook=null;if($failure==='actor'){$s->pdo->exec('UPDATE fixture_users SET user_active=0 WHERE user_id=1');}else{$s->failure='DELETE FROM fixture_words';}}};
   cleanup_run($wordlist,$failure==='actor'?$lang['Not_Authorised']:$lang['Maintenance_search_cleanup_failed']);
   cleanup_check(cleanup_value('SELECT COUNT(*) FROM fixture_words')===105,'Earlier batch remains applied; failed/revoked next batch untouched');
  }
  cleanup_fixture($engine);cleanup_run('rebuild_search_index',$lang['Invalid_dbmtnc_request']);cleanup_check(cleanup_value('SELECT COUNT(*) FROM fixture_words')===5,'Cleanup cannot dispatch whole-index rebuild');
  echo $engine.' '.$locale." search maintenance cleanup passed.\n";
 }}
}finally{restore_error_handler();}
