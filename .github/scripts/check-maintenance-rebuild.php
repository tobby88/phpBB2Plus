<?php
$root=dirname(dirname(__DIR__)).'/phpBB2/';
foreach(array('IN_PHPBB'=>true,'ADMIN'=>1,'MOD'=>2,'USER'=>0,'GENERAL_ERROR'=>202,'ATTACHMENTS_TABLE'=>'fixture_links','USERS_TABLE'=>'fixture_users','POSTS_TABLE'=>'fixture_posts','POSTS_TEXT_TABLE'=>'fixture_texts','CONFIG_TABLE'=>'fixture_config','SEARCH_TABLE'=>'fixture_results','SEARCH_WORD_TABLE'=>'fixture_words','SEARCH_MATCH_TABLE'=>'fixture_matches','JR_ADMIN_TABLE'=>'fixture_junior') as $key=>$value){define($key,$value);}
require_once $root.'includes/functions_maintenance_rebuild.php';
function rebuild_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
function message_die($code,$message){throw new RuntimeException($message);}
class RebuildControllerFailure extends RuntimeException {}
function throw_error($message){throw new RebuildControllerFailure($message);}
function lock_db($unlock=false,$delay=true,$ignore=false){$GLOBALS['board_locks'][]=array($unlock,$delay,$ignore);}
class RebuildRows {public $rows;function __construct($rows){$this->rows=$rows;}}
$dsn=getenv('PHPBB_REBUILD_TEST_DSN');$native=$dsn!==false&&$dsn!=='';
if($native){rebuild_check(preg_match('/^mysql:host=127\.0\.0\.1;port=33119;dbname=codex_rebuild_[a-f0-9]{16};charset=utf8mb4$/D',$dsn)===1,'Only owned local schemas allowed');}
class RebuildServer {
 public $pdo;public $owner=null;public $hook=null;public $failure='';public $queries=array();
 function __construct($engine){
  $sqliteClass=class_exists('Pdo\Sqlite')?'Pdo\Sqlite':'PDO';
  $this->pdo=$GLOBALS['native']?new PDO($GLOBALS['dsn'],'root',''):new $sqliteClass('sqlite::memory:');$this->pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
  if(!$GLOBALS['native']){$method=method_exists($this->pdo,'createFunction')?'createFunction':'sqliteCreateFunction';$this->pdo->$method('SHA2',function($value,$bits){return hash('sha256',$value);},2);}
  $definitions=array('users'=>'user_id INTEGER PRIMARY KEY,username VARCHAR(255),user_level INTEGER,user_active INTEGER','config'=>'config_name VARCHAR(64) PRIMARY KEY,config_value VARCHAR(255)','posts'=>'post_id INTEGER PRIMARY KEY,topic_id INTEGER,forum_id INTEGER,poster_id INTEGER','texts'=>'post_id INTEGER PRIMARY KEY,post_subject VARCHAR(255),post_text TEXT','words'=>'word_id INTEGER PRIMARY KEY'.($GLOBALS['native']?' AUTO_INCREMENT':'').',word_common INTEGER,word_text VARCHAR(50) UNIQUE','matches'=>'word_id INTEGER,post_id INTEGER,title_match INTEGER','results'=>'search_id INTEGER','junior'=>'user_id INTEGER,user_jr_admin VARCHAR(255)');
  foreach($definitions as $name=>$definition){$this->pdo->exec('DROP TABLE IF EXISTS fixture_'.$name);$this->pdo->exec('CREATE TABLE fixture_'.$name.' ('.$definition.')'.($GLOBALS['native']?' ENGINE='.$engine:''));}
  $this->pdo->exec("INSERT INTO fixture_users VALUES (1,'Root',1,1),(20,'Junior',0,1)");
  $this->pdo->exec("INSERT INTO fixture_config VALUES ('board_disable','0'),('dbmtnc_rebuild_pos','-1'),('dbmtnc_rebuild_end','0')");
  $this->pdo->exec('INSERT INTO fixture_posts VALUES (10,1,2,8),(20,1,2,8)');
  $this->pdo->exec("INSERT INTO fixture_texts VALUES (10,'alpha','music Grüße alpha alpha'),(20,'hello','beta')");
  $this->pdo->exec("INSERT INTO fixture_words VALUES (100,1,'stale'),(101,0,'beta'),(102,0,'orphan')");
  $this->pdo->exec('INSERT INTO fixture_matches VALUES (100,10,0),(101,20,0),(101,20,0)');
  $this->pdo->exec('INSERT INTO fixture_results VALUES (1)');

 }
}
class RebuildForum {
 public $dbname='rebuild-fixture';
 function sql_query($sql){throw new RuntimeException('Unlocked main connection used');}
 function sql_dedicated_connection(){return new RebuildConnection($GLOBALS['rebuild_server']);}
}
class RebuildConnection {
 public $server;public $pdo;public $db_connect_id=true;public $closed=false;public $affected=0;
 function __construct($server){$this->server=$server;$this->pdo=$GLOBALS['native']?new PDO($GLOBALS['dsn'],'root','',array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION)):$server->pdo;}
 function sql_query($sql){
  if($this->closed){return false;}$s=$this->server;$s->queries[]=$sql;
  if(strpos($sql,'SELECT GET_LOCK(')===0){
   if($s->failure==='lock'){return new RebuildRows(array(array('acquired'=>0)));}
   $rows=$GLOBALS['native']?$this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC):array(array('acquired'=>$s->owner===null?1:0));
   if((int)$rows[0]['acquired']===1){$s->owner=$this;}return new RebuildRows($rows);
  }
  rebuild_check($s->owner===$this,'Every protected query uses the owning connection');
  if(is_callable($s->hook)){call_user_func($s->hook,$sql,$this);}
  if($this->closed||($s->failure!==''&&strpos($sql,$s->failure)===0)){return false;}
  try{$r=$this->pdo->query($sql);$this->affected=$r->rowCount();return preg_match('/^SELECT/',$sql)?new RebuildRows($r->fetchAll(PDO::FETCH_ASSOC)):true;}catch(PDOException $e){return false;}
 }
 function sql_fetchrow($r){return array_shift($r->rows);}
 function sql_fetchrowset($r){return $r->rows;}
 function sql_freeresult($r){}
 function sql_affectedrows(){return $this->affected;}
 function sql_escape($s){return substr($this->pdo->quote($s),1,-1);}
 function sql_close(){if($this->server->owner===$this){$this->server->owner=null;}$this->closed=true;$this->db_connect_id=false;$this->pdo=null;}
}

function rebuild_fixture($engine,$actor=1){
 global $rebuild_server,$userdata,$phpEx,$phpbb_root_path,$root,$lang;
 $rebuild_server=new RebuildServer($engine);$userdata=array('user_id'=>$actor,'user_level'=>ADMIN,'session_logged_in'=>true,'session_admin'=>true,'session_id'=>'fixture-sid');
 $_SERVER['REQUEST_METHOD']='POST';$_POST=array('sid'=>'fixture-sid');$phpEx='php';$phpbb_root_path=$root;$lang=array();
}
function rebuild_value($sql){return $GLOBALS['rebuild_server']->pdo->query($sql)->fetchColumn();}
function rebuild_saved(){return dbmtnc_rebuild_decode(rebuild_value("SELECT config_value FROM fixture_config WHERE config_name='dbmtnc_rebuild_job'"));}
function rebuild_run($mode,$request=null,$expected=''){
 $caught='';$out=null;
 try{$out=dbmtnc_rebuild_batch(new RebuildForum(),$mode,$request===null?$_POST:$request,array(),array("rock'n'roll music"));}catch(PhpbbAclException $e){$caught=$e->getMessage();}
 rebuild_check($caught===$expected,'Expected rebuild outcome: '.$caught.' / '.$expected);
 rebuild_check($GLOBALS['rebuild_server']->owner===null,'Rebuild owner released');return $out;
}
function rebuild_step_request($state){
 $_SERVER['REQUEST_METHOD']='GET';$p=(string)$state['p'];return array('job'=>$state['g'],'pos'=>$p,'token'=>dbmtnc_rebuild_token($state['g'],$p,$GLOBALS['userdata']['session_id']));
}
function rebuild_complete($job){
 for($i=0;$i<300&&$job['state']['s']!=='done';$i++){$job=rebuild_run('step',rebuild_step_request($job['state']));}
 rebuild_check($job['state']['s']==='done','Bounded continuations reach durable completion');return $job;
}
function append_sid($url){return $url;}
function phpbb_admin_html($text){return htmlspecialchars($text,ENT_QUOTES,'UTF-8');}
class RebuildTemplate {public $vars=array();function assign_vars($vars){$this->vars=array_merge($this->vars,$vars);}}
$controller=file_get_contents($root.'admin/admin_db_maintenance.php');$a=strpos($controller,"case 'rebuild_search_index':");$b=strpos($controller,"case 'synchronize_post':",$a);
rebuild_check($a!==false&&$b>$a,'Actual rebuild controller found');
$branch='switch($function){'.substr($controller,$a,$b-$a).'}';
$branch=str_replace("include('./page_header_admin.' . \$phpEx);",'$GLOBALS[\'rebuild_headers\']++;',$branch);
$updater=file_get_contents(dirname($root).'/update/update_from_153a.php');
$a=strpos($updater,'$config_defaults = array(');$b=strpos($updater,'foreach ($config_defaults',$a);
rebuild_check($a!==false&&$b>$a,'Actual updater defaults found');eval(substr($updater,$a,$b-$a));
rebuild_check(array_key_exists('dbmtnc_rebuild_job',$config_defaults)&&$config_defaults['dbmtnc_rebuild_job']==='','Consolidated updater seeds empty job');
$a=strpos($updater,'function update_queue_default(');$b=strpos($updater,'function update_queue_drop_table(',$a);$queue_source=substr($updater,$a,$b-$a);
$a=strpos($updater,'function update_quote_identifier(');$b=strpos($updater,'function update_query_or_fail(',$a);
// Run the actual additive planner with PDO-backed driver shims, not the updater
// entrypoint or any installed forum. SQL is executed below on disposable tables.
eval('namespace RebuildMigration; function mysqli_real_escape_string($connection,$value){return substr($connection->quote($value),1,-1);} function update_scalar($connection,$sql){return $connection->query($sql)->fetchColumn();} '.$queue_source.substr($updater,$a,$b-$a));
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{
 foreach($native?array('MyISAM','InnoDB'):array('SQLite') as $engine){
  rebuild_fixture($engine);$operations=array();
  RebuildMigration\update_queue_default($operations,$rebuild_server->pdo,'fixture_config','config_name','config_value','dbmtnc_rebuild_job',$config_defaults['dbmtnc_rebuild_job']);
  rebuild_check(count($operations)===1,'Missing job produces exactly one additive operation');
  $seed=$native?$operations[0]:str_replace('INSERT IGNORE','INSERT OR IGNORE',$operations[0]);$rebuild_server->pdo->exec($seed);
  $job=rebuild_run('start');$before=rebuild_value("SELECT config_value FROM fixture_config WHERE config_name='dbmtnc_rebuild_job'");
  $rebuild_server->pdo->exec($seed);$operations=array();
  RebuildMigration\update_queue_default($operations,$rebuild_server->pdo,'fixture_config','config_name','config_value','dbmtnc_rebuild_job','');
  rebuild_check(!$operations&&rebuild_value("SELECT config_value FROM fixture_config WHERE config_name='dbmtnc_rebuild_job'")===$before,'Repeated and stale migration plans preserve active generation/checkpoint');
  $basic=file_get_contents($root.'install/schemas/mysql_basic.sql');
  rebuild_check(strpos($basic,"VALUES ('dbmtnc_rebuild_job', '')")!==false,'Fresh install seeds the same empty state');
  // A long post must not multiply ACL/job metadata reads by its vocabulary.
  rebuild_fixture($engine);$long_words=array();for($i=0;$i<500;$i++){$long_words[]='vocabulary'.$i;}
  $rebuild_server->pdo->exec('UPDATE fixture_texts SET post_text='.$rebuild_server->pdo->quote(implode(' ',$long_words)).' WHERE post_id=10');
  $job=rebuild_run('start');rebuild_check($job['state']['p']>=10&&count($rebuild_server->queries)<500*5,'Long-post indexing keeps current SQL guards without per-token metadata round trips');
  rebuild_fixture($engine);$job=rebuild_run('start');
  rebuild_check($job['state']['p']===20&&$job['state']['e']===20&&$job['state']['s']==='run','Both posts checkpointed');
  rebuild_check(rebuild_value("SELECT config_value FROM fixture_config WHERE config_name='board_disable'")==='1','Maintenance flag retained until finalization');
  rebuild_check((int)rebuild_value('SELECT COUNT(*) FROM fixture_matches')===6,'Deduplicated title/body index replaces old matches');
  rebuild_check(rebuild_value('SELECT word_text FROM fixture_words WHERE word_text LIKE \'rock%\'')==="rock'n'roll",'Configured apostrophe synonym stored safely');
  rebuild_check((int)rebuild_value('SELECT MIN(word_id) FROM fixture_words')===100,'No word ID counter reset or full dictionary deletion');
  rebuild_check((int)rebuild_value('SELECT COUNT(*) FROM fixture_results')===0,'Old search result cache cleared');
  $stale=$job['state'];$stale['p']=0;$before=$job['raw'];$job=rebuild_run('step',rebuild_step_request($stale));
  rebuild_check($job['raw']===$before&&(int)rebuild_value('SELECT COUNT(*) FROM fixture_matches')===6,'Stale position is read-only, not a duplicate batch');
  foreach(array('token','job','pos') as $invalid){$request=rebuild_step_request($job['state']);$request[$invalid]=$invalid==='pos'?'020':'bad';rebuild_run('step',$request,'Invalid_dbmtnc_request');}
  $_SERVER['REQUEST_METHOD']='POST';rebuild_run('start',array('sid'=>'fixture-sid'),'Maintenance_rebuild_changed');
  rebuild_check(rebuild_saved()===$job['state'],'Stale confirmation cannot restart a currently active generation');
  $job=rebuild_run('step',rebuild_step_request($job['state']));
  rebuild_check($job['state']['s']==='finish','Last post is not premature completion');
  $_SERVER['REQUEST_METHOD']='POST';$old=$job['state'];$job=rebuild_run('start',array('sid'=>'fixture-sid','job'=>$old['g']));
  rebuild_check($old['g']!==$job['state']['g'],'Restart gets a new generation');
  rebuild_check($job['state']['b']===0,'Restart preserves original enabled-board state');
  rebuild_run('step',rebuild_step_request($old),'Maintenance_rebuild_changed');
  $job=rebuild_complete($job);
  rebuild_check(rebuild_value("SELECT config_value FROM fixture_config WHERE config_name='board_disable'")==='0','Only completed rebuild restores original board state');
  rebuild_check(rebuild_value("SELECT config_value FROM fixture_config WHERE config_name='dbmtnc_rebuild_pos'")==='-1'&&rebuild_value("SELECT config_value FROM fixture_config WHERE config_name='dbmtnc_rebuild_end'")==='0','Legacy display mirrors reset on completion');
  rebuild_check((int)rebuild_value("SELECT COUNT(*) FROM fixture_words WHERE word_text IN ('orphan','stale')")===0,'Finalization removes only unused noncommon words');
  $saved=$job['raw'];$job=rebuild_run('step',rebuild_step_request($job['state']));rebuild_check($job['raw']===$saved,'Completed continuation is read-only');
  rebuild_fixture($engine);$rebuild_server->pdo->exec("UPDATE fixture_config SET config_value='1' WHERE config_name='board_disable'");$job=rebuild_complete(rebuild_run('start'));
  rebuild_check(rebuild_value("SELECT config_value FROM fixture_config WHERE config_name='board_disable'")==='1','Previously disabled board stays disabled');
  foreach(array('DELETE FROM fixture_matches','DELETE FROM fixture_words','mirror','restore','done') as $failure){
   rebuild_fixture($engine);$job=rebuild_run('start');$job=rebuild_run('step',rebuild_step_request($job['state']));
   $rebuild_server->pdo->exec('INSERT INTO fixture_matches VALUES (999,999,0)');
   $rebuild_server->hook=function($sql) use($failure){
    $needle=$failure==='mirror'?"WHERE config_name = 'dbmtnc_rebuild_pos'":($failure==='restore'?"WHERE config_name = 'board_disable'":'"s":"done"');
    if((strpos($failure,'DELETE')===0&&strpos($sql,$failure)===0)||(strpos($failure,'DELETE')!==0&&strpos($sql,'UPDATE fixture_config')===0&&strpos(str_replace(chr(92),'',$sql),$needle)!==false)){
     $GLOBALS['rebuild_server']->hook=null;$GLOBALS['rebuild_server']->failure=$sql;
    }
   };
   for($i=0;$i<10;$i++){
    $caught='';try{$job=dbmtnc_rebuild_batch(new RebuildForum(),'step',rebuild_step_request($job['state']),array(),array());}catch(PhpbbAclException $e){$caught=$e->getMessage();break;}
   }
   rebuild_check($caught==='Maintenance_rebuild_failed'&&$rebuild_server->owner===null,'Finalization failure remains resumable and releases owner: '.$failure);
   rebuild_check(rebuild_saved()['s']!=='done','Failed finalization is never reported complete');
   $rebuild_server->failure='';$_SERVER['REQUEST_METHOD']='POST';$job=rebuild_complete(rebuild_run('resume'));
   rebuild_check(rebuild_value("SELECT config_value FROM fixture_config WHERE config_name='board_disable'")==='0','Retry restores original state: '.$failure);
   rebuild_check((int)rebuild_value('SELECT COUNT(*) FROM fixture_matches WHERE post_id=999')===0,'Retry removes orphan matches');
  }
  // Common words count distinct real posts, not title/body rows or orphan IDs.
  foreach(array(99,100) as $total){
   rebuild_fixture($engine);$job=rebuild_run('start');$job=rebuild_run('step',rebuild_step_request($job['state']));
   $rebuild_server->pdo->exec('DELETE FROM fixture_matches');$rebuild_server->pdo->exec('DELETE FROM fixture_words');$rebuild_server->pdo->exec('DELETE FROM fixture_posts');
   $rebuild_server->pdo->exec("INSERT INTO fixture_words VALUES (201,0,'frequent'),(202,0,'boundary'),(203,0,'orphaned')");
   for($i=1;$i<=$total;$i++){$rebuild_server->pdo->exec('INSERT INTO fixture_posts VALUES ('.$i.',1,2,8)');}
   for($i=1;$i<=41;$i++){$rebuild_server->pdo->exec('INSERT INTO fixture_matches VALUES (201,'.$i.',0)');}
   for($i=1;$i<=40;$i++){$rebuild_server->pdo->exec('INSERT INTO fixture_matches VALUES (202,'.$i.',0),(202,'.$i.',1)');}
   for($i=1001;$i<=1041;$i++){$rebuild_server->pdo->exec('INSERT INTO fixture_matches VALUES (203,'.$i.',0)');}
   $job=rebuild_complete($job);
   rebuild_check((int)rebuild_value('SELECT word_common FROM fixture_words WHERE word_id=201')===($total===100?1:0),'Common classification requires at least 100 posts');
   rebuild_check((int)rebuild_value('SELECT COUNT(*) FROM fixture_matches WHERE word_id=201')===($total===100?0:41),'Common matches removed after classification');
   rebuild_check((int)rebuild_value('SELECT word_common FROM fixture_words WHERE word_id=202')===0&&(int)rebuild_value('SELECT COUNT(*) FROM fixture_matches WHERE word_id=202')===80,'Exactly 40 percent with title/body duplicates remains searchable');
   rebuild_check((int)rebuild_value('SELECT COUNT(*) FROM fixture_words WHERE word_id=203')===0,'Orphan posts never make a word common');
  }
  foreach(array('actor','generation') as $race){
   rebuild_fixture($engine);$job=rebuild_run('start');$job=rebuild_run('step',rebuild_step_request($job['state']));
   $rebuild_server->hook=function($sql) use($race){
    if(strpos($sql,'DELETE FROM fixture_words')!==0){return;}$s=$GLOBALS['rebuild_server'];$s->hook=null;
    if($race==='actor'){$s->pdo->exec('UPDATE fixture_users SET user_active=0 WHERE user_id=1');}
    else{$state=rebuild_saved();$state['g']=str_repeat('f',32);$s->pdo->exec("UPDATE fixture_config SET config_value=".$s->pdo->quote(json_encode($state))." WHERE config_name='dbmtnc_rebuild_job'");}
   };
   rebuild_run('step',rebuild_step_request($job['state']),$race==='actor'?'Not_Authorised':'Maintenance_rebuild_changed');
   rebuild_check((int)rebuild_value("SELECT COUNT(*) FROM fixture_words WHERE word_text IN ('stale','orphan')")===2,'Final cleanup rechecks actor and exact job inside DELETE');
   rebuild_check(rebuild_value("SELECT config_value FROM fixture_config WHERE config_name='board_disable'")==='1','Conflict never unlocks an unfinished rebuild');
  }
  foreach(array('DELETE FROM fixture_matches','INSERT INTO fixture_words','INSERT INTO fixture_matches') as $failure){
   rebuild_fixture($engine);$rebuild_server->failure=$failure;rebuild_run('start',null,'Maintenance_rebuild_failed');
   rebuild_check(rebuild_saved()['p']===0,'Failure never advances past unconfirmed post');
   $rebuild_server->failure='';$job=rebuild_run('resume');
   rebuild_check($job['state']['p']===20&&(int)rebuild_value('SELECT COUNT(*) FROM fixture_matches')===6,'Retry reconstructs interrupted post without duplicate matches');
  }
  foreach(array('content','delete','actor','owner','checkpoint') as $race){
   rebuild_fixture($engine);$rebuild_server->hook=function($sql,$connection) use($race){
    $checkpoint=$race==='checkpoint';$match=$checkpoint?(strpos($sql,'UPDATE fixture_config')===0&&strpos(str_replace(chr(92),'',$sql),'"p":10')!==false):strpos($sql,'DELETE FROM fixture_matches')===0;
    if(!$match){return;}$s=$GLOBALS['rebuild_server'];$s->hook=null;
    if($race==='owner'){$connection->sql_close();return;}
    if($race==='content'){$s->pdo->exec("UPDATE fixture_texts SET post_text='replacement' WHERE post_id=10");}
    elseif($race==='delete'){$s->pdo->exec('DELETE FROM fixture_posts WHERE post_id=10');}
    else{$s->pdo->exec('UPDATE fixture_users SET user_active=0 WHERE user_id=1');}
   };
   $error=$race==='owner'?'Maintenance_rebuild_failed':(in_array($race,array('actor'),true)?'Not_Authorised':'Maintenance_rebuild_changed');
   rebuild_run('start',null,$error);rebuild_check(rebuild_saved()['p']===0,'Interleaving does not commit old source checkpoint');
   $rebuild_server->pdo->exec('UPDATE fixture_users SET user_active=1 WHERE user_id=1');$job=rebuild_run('resume');
   rebuild_check($job['state']['p']===20,'Current source can be resumed after conflict');
   if($race==='content'){rebuild_check((int)rebuild_value("SELECT COUNT(*) FROM fixture_matches m JOIN fixture_words w ON w.word_id=m.word_id WHERE m.post_id=10 AND w.word_text='replacement'")===1,'Resume indexes current content');}
   if($race==='delete'){rebuild_check((int)rebuild_value('SELECT COUNT(*) FROM fixture_posts WHERE post_id=10')===0,'Removed source not recreated');}
  }
  rebuild_fixture($engine);$rebuild_server->hook=function($sql){
   if(strpos($sql,'SELECT post_id FROM fixture_posts WHERE post_id >')!==0){return;}$s=$GLOBALS['rebuild_server'];$s->hook=null;
   $s->pdo->exec('INSERT INTO fixture_posts VALUES (30,1,2,8)');$s->pdo->exec("INSERT INTO fixture_texts VALUES (30,'new','concurrent')");$s->pdo->exec("INSERT INTO fixture_words VALUES (200,0,'concurrent')");$s->pdo->exec('INSERT INTO fixture_matches VALUES (200,30,0)');
  };
  $job=rebuild_run('start');rebuild_check($job['state']['e']===20&&(int)rebuild_value('SELECT COUNT(*) FROM fixture_matches WHERE post_id=30')===1,'New posts beyond initial horizon retain their own index');
  rebuild_fixture($engine);$rebuild_server->pdo->exec('DELETE FROM fixture_texts WHERE post_id=10');rebuild_run('start',null,'Maintenance_rebuild_text_missing');rebuild_check(rebuild_saved()['p']===0,'Missing text is not silently skipped');
  rebuild_fixture($engine);$rebuild_server->pdo->exec('DELETE FROM fixture_posts');$job=rebuild_run('start');rebuild_check($job['state']['s']==='finish'&&$job['state']['p']===0,'Empty index reaches finalization without division');
  foreach(array('get','bad-sid','sid-array','inactive','no-admin') as $case){
   rebuild_fixture($engine);$expected='Session_invalid';$request=$_POST;
   if($case==='get'){$_SERVER['REQUEST_METHOD']='GET';}elseif($case==='bad-sid'){$request['sid']='bad';}elseif($case==='sid-array'){$request['sid']=array();}
   elseif($case==='inactive'){$rebuild_server->pdo->exec('UPDATE fixture_users SET user_active=0 WHERE user_id=1');$expected='Not_Authorised';}else{$userdata['session_admin']=false;$expected='Not_Authorised';}
   rebuild_run('start',$request,$expected);rebuild_check((int)rebuild_value('SELECT COUNT(*) FROM fixture_matches')===3,'Denied request preserves index');
  }
  rebuild_fixture($engine,20);$hash=md5('GeneralDB_Maintenanceadmin_db_maintenance.php');$rebuild_server->pdo->exec("INSERT INTO fixture_junior VALUES (20,'".$hash."')");rebuild_run('start');
  rebuild_fixture($engine,20);rebuild_run('start',null,'Not_Authorised');
  rebuild_fixture($engine);$contended=false;$rebuild_server->hook=function($sql) use(&$contended){
   if(strpos($sql,'SELECT COALESCE(MAX(post_id),0)')===0){$GLOBALS['rebuild_server']->hook=null;$other=new attach_mutation_lock(new RebuildForum(),false);$contended=!$other->acquired;$other->release();}
  };rebuild_run('start');rebuild_check($contended,'Start and horizon read share writer lock');
  rebuild_fixture($engine);$rebuild_server->pdo->exec("UPDATE fixture_config SET config_value='10' WHERE config_name='dbmtnc_rebuild_pos'");$rebuild_server->pdo->exec("UPDATE fixture_config SET config_value='20' WHERE config_name='dbmtnc_rebuild_end'");
  $job=rebuild_run('resume');rebuild_check($job['state']['p']===20,'Explicit legacy continuation adopts persisted checkpoint');
  rebuild_fixture($engine);$rebuild_server->pdo->exec("INSERT INTO fixture_config VALUES ('dbmtnc_rebuild_job','bad-json')");rebuild_run('start',null,'Maintenance_rebuild_state_invalid');
  foreach(array('english','german') as $locale){
   rebuild_fixture($engine);include $root.'language/lang_'.$locale.'/lang_dbmtnc.php';
   $board_config=array('default_lang'=>$locale);$db=new RebuildForum();$template=new RebuildTemplate();$function='rebuild_search_index';$rebuild_headers=0;
   ob_start();eval($branch);$html=ob_get_clean();
   rebuild_check(strpos($html,$lang['Rebuilding_search_index'])!==false&&strpos($html,'&amp;job=')!==false,'Actual localized controller emits generation-bound continuation');
   rebuild_check(strpos($html,$lang['Indexing_finished'])===false,'Controller never claims first batch finished');
   $function='perform_rebuild';
   for($i=0;$i<20&&rebuild_saved()['s']!=='done';$i++){
    $_GET=rebuild_step_request(rebuild_saved());$template=new RebuildTemplate();ob_start();eval($branch);$html=ob_get_clean();
    if(rebuild_saved()['s']!=='done'){rebuild_check(isset($template->vars['META'])&&strpos($template->vars['META'],'token=')!==false,'Every ongoing phase refreshes through signed URL');}
   }
   rebuild_check(rebuild_saved()['s']==='done'&&strpos($html,$lang['Indexing_finished'])!==false&&!isset($template->vars['META']),'Actual controller completes and stops refreshing');
   rebuild_check($rebuild_headers>0,'Step controller sends its delayed header');
   rebuild_fixture($engine);include $root.'language/lang_'.$locale.'/lang_dbmtnc.php';$rebuild_server->failure='INSERT INTO fixture_words';$function='rebuild_search_index';$template=new RebuildTemplate();
   ob_start();eval($branch);$html=ob_get_clean();
   rebuild_check(strpos($html,phpbb_admin_html($lang['Maintenance_rebuild_failed']))!==false&&strpos($html,$lang['Maintenance_rebuild_resume_help'])!==false&&!isset($template->vars['META']),'Actual controller stops on failure and explains authorized recovery');
   rebuild_check(rebuild_saved()['p']===0&&rebuild_value("SELECT config_value FROM fixture_config WHERE config_name='board_disable'")==='1','Controller failure preserves resumable state');
   $rebuild_server->failure='';$function='proceed_rebuilding';ob_start();eval($branch);ob_end_clean();rebuild_check(rebuild_saved()['p']===20,'Actual POST resume continues interrupted post');
  }
  echo $engine." durable rebuild backend (start, batches, replayable finalization) passed.\n";
 }
}finally{restore_error_handler();}
