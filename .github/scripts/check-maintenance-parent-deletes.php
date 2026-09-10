<?php
$root=dirname(dirname(__DIR__)).'/phpBB2/';
foreach(array('POSTS_TABLE'=>'fixture_posts','POSTS_TEXT_TABLE'=>'fixture_text','TOPICS_TABLE'=>'fixture_topics','USERS_TABLE'=>'fixture_users','TOPIC_MOVED'=>2) as $k=>$v){define($k,$v);}
function parent_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
class ParentRepairFailure extends RuntimeException {}
function throw_error($message){throw new ParentRepairFailure($message);}
$parentDsn=getenv('PHPBB_PARENT_REPAIR_TEST_DSN');$parentNative=$parentDsn!==false&&$parentDsn!=='';
if($parentNative){parent_check(preg_match('/^mysql:host=127\.0\.0\.1;port=33119;dbname=codex_parent_repair_[a-f0-9]{16};charset=utf8mb4$/D',$parentDsn)===1,'Only isolated owned loopback databases allowed');}
class ParentRepairDatabase {
 public $pdo;public $hook=null;public $failure='';public $lostAck=false;public $affected=0;
 function __construct($engine,$mode){
  $this->pdo=$GLOBALS['parentNative']?new PDO($GLOBALS['parentDsn'],'root',''):new PDO('sqlite::memory:');
  $this->pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
  foreach(array('posts'=>'post_id INTEGER PRIMARY KEY,topic_id INTEGER,poster_id INTEGER',
   'text'=>'post_id INTEGER PRIMARY KEY,post_text TEXT',
   'topics'=>'topic_id INTEGER PRIMARY KEY,topic_title VARCHAR(255),topic_status INTEGER,topic_moved_id INTEGER',
   'users'=>'user_id INTEGER PRIMARY KEY,username VARCHAR(255)') as $name=>$definition){
   $this->pdo->exec('DROP TABLE IF EXISTS fixture_'.$name);
   $this->pdo->exec('CREATE TABLE fixture_'.$name.' ('.$definition.')'.($GLOBALS['parentNative']?' ENGINE='.$engine:''));
  }
  $this->pdo->exec("INSERT INTO fixture_users VALUES (8,'Grüße <img src=x>')");
  $this->pdo->exec("INSERT INTO fixture_topics VALUES (10,'Leeres <Thema>',".($mode==='redirect'?2:0).",999),(20,'Healthy',0,0),(30,'Valid redirect',2,20)");
  $this->pdo->exec('INSERT INTO fixture_posts VALUES (2,20,8)');
  $this->pdo->exec("INSERT INTO fixture_text VALUES (2,'Healthy text'),(999,'Unrelated orphan text')");
  if($mode==='post'){$this->pdo->exec('INSERT INTO fixture_posts VALUES (1,10,8)');}
  if($mode==='redirect'){
   $this->pdo->exec("INSERT INTO fixture_topics VALUES (40,'Redirect with post',2,998)");
   $this->pdo->exec('INSERT INTO fixture_posts VALUES (4,40,8)');
   $this->pdo->exec("INSERT INTO fixture_text VALUES (4,'Must not lose parent')");
  }
 }
 function sql_query($sql){
  if(is_callable($this->hook)){call_user_func($this->hook,$sql,$this);}
  $fail=$this->failure!==''&&strpos($sql,$this->failure)===0;
  if($fail&&!$this->lostAck){return false;}
  $r=$this->pdo->query($sql);$this->affected=$r->rowCount();return $fail?false:$r;
 }
 function sql_fetchrow($r){return $r->fetch(PDO::FETCH_ASSOC);}
 function sql_freeresult($r){$r->closeCursor();}
 function sql_affectedrows(){return $this->affected;}
}
function parent_exists($db,$table,$key,$id){return (int)$db->pdo->query('SELECT COUNT(*) FROM fixture_'.$table.' WHERE '.$key.'='.(int)$id)->fetchColumn()===1;}
function parent_fragment($db,$mode){
 global $lang,$parentBranches;
 $list_open=false;$update_post_data=false;$db_updated=false;$caught='';
 ob_start();try{eval($parentBranches[$mode]);}catch(ParentRepairFailure $e){$caught=$e->getMessage();}finally{$html=ob_get_clean();}
 return array($caught,$html,$update_post_data,$db_updated);
}
$source=file_get_contents($root.'admin/admin_db_maintenance.php');$parentBranches=array();
foreach(array('post'=>array('// Check for posts without a text','// Check for topics without a post'),
 'topic'=>array('// Check for topics without a post','// Check for topics with invalid forum'),
 'redirect'=>array('// Check moved topics','// Check for normal topics with move information')) as $mode=>$markers){
 $a=strpos($source,$markers[0]);$b=strpos($source,$markers[1],$a);
 parent_check($a!==false&&$b>$a,'Actual '.$mode.' controller fragment found');$parentBranches[$mode]=substr($source,$a,$b-$a);
}
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{
 foreach($parentNative?array('MyISAM','InnoDB'):array('SQLite') as $engine){foreach(array('english','german') as $locale){
  $phpEx='php';$lang=array();include $root.'language/lang_'.$locale.'/lang_dbmtnc.php';
  foreach(array('post','topic','redirect') as $mode){
   $table=$mode==='post'?'posts':'topics';$key=$mode==='post'?'post_id':'topic_id';$id=$mode==='post'?1:10;
   $races=$mode==='post'?array('none','body','gone','null-diagnostic'):($mode==='topic'?array('none','post','redirect-status','gone'):array('none','destination','retarget','post','normal-status','gone'));
   foreach($races as $race){
    $db=new ParentRepairDatabase($engine,$mode);
    if($race==='null-diagnostic'){$db->pdo->exec('UPDATE fixture_posts SET topic_id=777,poster_id=777 WHERE post_id=1');}
    $db->hook=function($sql,$connection)use($mode,$race,$table,$key,$id){
     if(strpos($sql,'DELETE FROM fixture_'.$table)!==0){return;}$connection->hook=null;$p=$connection->pdo;
     if($race==='body'){$p->exec("INSERT INTO fixture_text VALUES (1,'Newly completed text')");}
     elseif($race==='post'){$p->exec('INSERT INTO fixture_posts VALUES (5,10,8)');$p->exec("INSERT INTO fixture_text VALUES (5,'New reply')");}
     elseif($race==='redirect-status'){$p->exec('UPDATE fixture_topics SET topic_status=2,topic_moved_id=20 WHERE topic_id=10');}
     elseif($race==='normal-status'){$p->exec('UPDATE fixture_topics SET topic_status=0,topic_moved_id=0 WHERE topic_id=10');}
     elseif($race==='destination'){$p->exec("INSERT INTO fixture_topics VALUES (999,'Restored target',0,0)");}
     elseif($race==='retarget'){$p->exec('UPDATE fixture_topics SET topic_moved_id=20 WHERE topic_id=10');}
     elseif($race==='gone'){$p->exec('DELETE FROM fixture_'.$table.' WHERE '.$key.'='.$id);}
    };
    $result=parent_fragment($db,$mode);parent_check($result[0]==='','Actual fragment succeeds');
    $removed=in_array($race,array('none','null-diagnostic'),true)?1:0;
    parent_check(parent_exists($db,$table,$key,$id)===($removed===0&&$race!=='gone'),'Current source state decides deletion '.$mode.' '.$race);
    $skipped=($mode==='redirect'?2:1)-$removed;
    parent_check(strpos($result[1],sprintf($lang['Maintenance_parent_delete_summary'],$removed,$skipped))!==false,'Report actual affected/skipped count');
    parent_check(($mode==='redirect'?$result[3]:$result[2])===($removed>0),'Synchronization requested only for actual removals');
    parent_check(parent_exists($db,'posts','post_id',2)&&parent_exists($db,'topics','topic_id',20)&&parent_exists($db,'topics','topic_id',30)&&parent_exists($db,'text','post_id',999),'Unrelated healthy parents/text retained');
    if($mode==='redirect'){parent_check(parent_exists($db,'topics','topic_id',40)&&parent_exists($db,'posts','post_id',4),'Malformed redirect with posts preserved');}
    if($race==='body'){parent_check(parent_exists($db,'text','post_id',1),'Concurrent completed body retained with parent');}
    if($race==='post'){parent_check(parent_exists($db,'posts','post_id',5)&&parent_exists($db,'text','post_id',5),'Concurrent reply retained with parent');}
    parent_check(strpos($result[1],'<img src=x>')===false&&strpos($result[1],'<Thema>')===false,'Diagnostic labels escaped');
   }
   foreach(array(false,true) as $lost){
    $db=new ParentRepairDatabase($engine,$mode);$db->failure='DELETE FROM fixture_'.$table;$db->lostAck=$lost;
    $result=parent_fragment($db,$mode);parent_check($result[0]!=='','Failed/lost DELETE acknowledgement is reported');
    parent_check(parent_exists($db,$table,$key,$id)===!$lost,'Confirmed side effects not claimed rolled back');
    parent_check(strpos($result[1],sprintf($lang['Maintenance_parent_delete_summary'],1,$mode==='redirect'?1:0))===false,'Failure not reported as success');
    $db->failure='';$result=parent_fragment($db,$mode);parent_check($result[0]===''&&!parent_exists($db,$table,$key,$id),'Retry succeeds without resurrecting parent');
   }
   $db=new ParentRepairDatabase($engine,$mode);$db->failure='SELECT';$result=parent_fragment($db,$mode);
   parent_check($result[0]!==''&&parent_exists($db,$table,$key,$id),'Failed diagnosis cannot delete');
  }
  echo $engine.' '.$locale." structural parent deletion guards passed.\n";
 }}
}finally{restore_error_handler();}
