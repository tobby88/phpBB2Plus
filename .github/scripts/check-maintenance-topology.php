<?php
// Reuse fixture declarations only; all databases are disposable and loopback-owned.
$fixtureSource=file_get_contents(__DIR__.'/check-maintenance-recovery.php');
$fixtureEnd=strpos($fixtureSource,"\n\$source=file_get_contents(");
if($fixtureEnd===false){throw new RuntimeException('Recovery fixture boundary missing');}
eval(substr($fixtureSource,5,$fixtureEnd-5));
define('TOPIC_MOVED',2);
require_once $root.'includes/functions_maintenance_topology.php';
function topology_fixture($engine,$actor=1){
 recovery_fixture($engine,$actor);$p=$GLOBALS['resetServer']->pdo;
 $p->exec('DELETE FROM fixture_text; DELETE FROM fixture_links; DELETE FROM fixture_descriptions');
 $p->exec("INSERT INTO fixture_categories (cat_id,cat_title,cat_order,cat_main_type,cat_main,cat_desc) VALUES (10,'Original',10,'c',0,'')");
 $p->exec("INSERT INTO fixture_forums (forum_id,cat_id,forum_name,main_type,forum_status) VALUES (10,10,'Original','c',0),(11,999,'Missing category','c',0),(12,999,'Subforum','f',0)");
 $p->exec("INSERT INTO fixture_topics (topic_id,forum_id,topic_title,topic_poster,topic_time,topic_status,topic_moved_id) VALUES (10,10,'Healthy',20,100,0,0),(20,999,'Lost forum A',20,100,0,0),(21,998,'Lost forum B',20,100,0,0),(30,10,'Redirect',20,100,2,777),(40,10,'Healthy2',20,100,0,0)");
 foreach(array(1=>array(20,999),2=>array(20,999),3=>array(21,998),4=>array(888,999),5=>array(888,999),6=>array(889,999),7=>array(30,10),8=>array(10,999),9=>array(10,10),10=>array(40,11)) as $id=>$link){
  $p->exec("INSERT INTO fixture_posts (post_id,topic_id,forum_id,poster_id,post_time,poster_ip,post_username,post_attachment) VALUES (".$id.','.$link[0].','.$link[1].",20,100,'','Guest alias',".($id===4?1:0).')');
  $p->exec("INSERT INTO fixture_text VALUES (".$id.",'uid1234','Subject ".$id." Grüße','Original body ".$id."')");
 }
 $p->exec("INSERT INTO fixture_links VALUES (7,4); INSERT INTO fixture_descriptions VALUES (7,'original.jpg')");
 foreach(array('bookmarks'=>'topic_id INTEGER,user_id INTEGER','auth_access'=>'forum_id INTEGER,group_id INTEGER') as $name=>$definition){
  $p->exec('DROP TABLE IF EXISTS fixture_'.$name);$p->exec('CREATE TABLE fixture_'.$name.' ('.$definition.')'.($GLOBALS['resetNative']?' ENGINE='.$engine:''));
 }
 $p->exec('INSERT INTO fixture_bookmarks VALUES (1500,20); INSERT INTO fixture_auth_access VALUES (2500,20)');
 recovery_metadata_fixture($p);
}
function topology_value($sql){return $GLOBALS['resetServer']->pdo->query($sql)->fetchColumn();}
function topology_run($expected=''){
 $out=null;$caught='';try{$out=dbmtnc_repair_topology(new ResetForum(),$_POST);}catch(PhpbbAclException $e){$caught=$e->getMessage();}
 reset_check($caught===$expected,'Topology outcome: '.$caught.' / '.$expected);reset_check($GLOBALS['resetServer']->owner===null,'Topology owner released');
 reset_check((int)topology_value("SELECT config_value FROM fixture_config WHERE config_name='board_disable'")===0,'Topology worker does not toggle board availability');return $out;
}
function topology_complete(){
 reset_check(recovery_count('categories')===2&&recovery_count('forums')===4&&recovery_count('topics')===8,'No duplicate recovery containers or collapsed original topics');
 $forum=(int)topology_value('SELECT forum_id FROM fixture_forums WHERE maintenance_token IS NOT NULL');
 reset_check($forum>2500&&(int)topology_value('SELECT cat_id FROM fixture_categories WHERE maintenance_token IS NOT NULL')>999,'Dangling ACL/category references not inherited');
 $groupA=(int)topology_value('SELECT topic_id FROM fixture_posts WHERE post_id=4');$groupB=(int)topology_value('SELECT topic_id FROM fixture_posts WHERE post_id=6');$groupC=(int)topology_value('SELECT topic_id FROM fixture_posts WHERE post_id=7');
 reset_check($groupA>1500&&$groupB>1500&&$groupC>1500&&count(array_unique(array($groupA,$groupB,$groupC)))===3,'Distinct old topics get separate unreferenced identities');
 reset_check((int)topology_value('SELECT topic_id FROM fixture_posts WHERE post_id=5')===$groupA,'Original topic grouping retained');
 reset_check((int)topology_value('SELECT cat_id FROM fixture_forums WHERE forum_id=12')===999,'Subforum parent is not mistaken for a category');
 reset_check((int)topology_value('SELECT forum_id FROM fixture_topics WHERE topic_id=20')===$forum&&(int)topology_value('SELECT forum_id FROM fixture_topics WHERE topic_id=21')===$forum,'Original orphan-forum topics retain their identities');
 reset_check((int)topology_value('SELECT COUNT(*) FROM fixture_posts p JOIN fixture_topics t ON t.topic_id=p.topic_id WHERE p.forum_id<>t.forum_id')===0,'No stale dependent forum links');
 reset_check(topology_value('SELECT topic_title FROM fixture_topics WHERE topic_id='.$groupA)==='Subject 4 Grüße','Original first subject becomes restored title');
 reset_check((int)topology_value('SELECT topic_attachment FROM fixture_topics WHERE topic_id='.$groupA)===1,'Existing attachment badge restored');
 reset_check((int)topology_value('SELECT topic_replies FROM fixture_topics WHERE topic_id='.$groupA)===1,'Recovered topic counters match surviving posts');
 reset_check((int)topology_value('SELECT forum_posts FROM fixture_forums WHERE forum_id='.$forum)===7,'Forum counter finishes after routing');
 reset_check((int)topology_value('SELECT forum_posts FROM fixture_forums WHERE forum_id=10')===3,'Ordinary forum counters also finish after routing');
}
$controller=file_get_contents($root.'admin/admin_db_maintenance.php');
$a=strpos($controller,'// Check for topics with invalid forum, orphan posts and mismatched routing.');$b=strpos($controller,'// Check for texts without a post',$a);
reset_check($a!==false&&$b>$a,'Actual topology controller branch');$branch=substr($controller,$a,$b-$a);
$writes=array('INSERT INTO fixture_config','INSERT INTO fixture_categories','INSERT INTO fixture_forums','INSERT INTO fixture_topics',
 'UPDATE fixture_forums SET cat_id','UPDATE fixture_topics SET forum_id','UPDATE fixture_posts SET forum_id = 2501,topic_id',
 'UPDATE fixture_posts SET forum_id = (SELECT','UPDATE fixture_topics SET topic_replies','UPDATE fixture_forums SET forum_topics','UPDATE fixture_topics SET topic_attachment');
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{foreach($resetNative?array('MyISAM','InnoDB'):array('SQLite') as $engine){foreach(array('english','german') as $locale){
 $lang=array('Not_Authorised'=>'not-authorized','Session_invalid'=>'session-invalid','Attachment_storage_busy'=>'busy');$phpEx='php';include $root.'language/lang_'.$locale.'/lang_dbmtnc.php';
 topology_fixture($engine);$texts=$resetServer->pdo->query('SELECT * FROM fixture_text ORDER BY post_id')->fetchAll(PDO::FETCH_ASSOC);
 $out=topology_run();topology_complete();
 foreach(array('forums'=>1,'topics'=>2,'posts'=>4,'routes'=>5,'skipped'=>0) as $k=>$v){reset_check($out[$k]===$v,'Accurate topology count '.$k);}
 reset_check($texts===$resetServer->pdo->query('SELECT * FROM fixture_text ORDER BY post_id')->fetchAll(PDO::FETCH_ASSOC),'Texts, subjects and BBCode IDs unchanged');
 reset_check(recovery_count('links')===1&&recovery_count('descriptions')===1&&topology_value('SELECT filename FROM fixture_descriptions')==='original.jpg','Attachment rows retained');
 $out=topology_run();topology_complete();foreach(array('forums','topics','posts','routes','skipped') as $k){reset_check($out[$k]===0,'Repeat has no routing work');}
 foreach($writes as $target){foreach(array(false,true) as $lost){
  topology_fixture($engine);$resetServer->failure=$target;$resetServer->lostAck=$lost;topology_run($lang['Maintenance_topology_failed']);$resetServer->failure='';topology_run();topology_complete();
 }}
 foreach(array('category-restored','category-reassigned','forum-restored','topic-reassigned','parent-restored','post-reassigned','route-parent-changed','public-target') as $race){
  topology_fixture($engine);$resetServer->hook=function($sql)use($race){
   $target=strpos($race,'category-')===0?'UPDATE fixture_forums SET cat_id':(in_array($race,array('forum-restored','topic-reassigned'),true)?'UPDATE fixture_topics SET forum_id':($race==='route-parent-changed'?'UPDATE fixture_posts SET forum_id = (SELECT':'UPDATE fixture_posts SET forum_id = 2501,topic_id'));
   if(strpos($sql,$target)!==0){return;}$s=$GLOBALS['resetServer'];$s->hook=null;
   if($race==='category-restored'){$s->pdo->exec("INSERT INTO fixture_categories (cat_id,cat_title,cat_order,cat_main_type,cat_main,cat_desc) VALUES (999,'Restored',20,'c',0,'')");}
   elseif($race==='category-reassigned'){$s->pdo->exec('UPDATE fixture_forums SET cat_id=10 WHERE forum_id=11');}
   elseif($race==='forum-restored'){$s->pdo->exec("INSERT INTO fixture_forums (forum_id,cat_id,forum_name,main_type,forum_status) VALUES (999,10,'Restored','c',0)");}
   elseif($race==='topic-reassigned'){$s->pdo->exec('UPDATE fixture_topics SET forum_id=10 WHERE topic_id=20');}
   elseif($race==='parent-restored'){$s->pdo->exec("INSERT INTO fixture_topics (topic_id,forum_id,topic_title,topic_poster,topic_time,topic_status,topic_moved_id) VALUES (888,10,'Restored parent',20,100,0,0)");}
   elseif($race==='post-reassigned'){$s->pdo->exec('UPDATE fixture_posts SET topic_id=10,forum_id=10 WHERE post_id=4');}
   elseif($race==='route-parent-changed'){$s->pdo->exec('UPDATE fixture_topics SET forum_id=11 WHERE topic_id=20');}
   else{$s->pdo->exec('UPDATE fixture_forums SET auth_view=0 WHERE maintenance_token IS NOT NULL');}
  };
  topology_run($race==='public-target'?$lang['Maintenance_recovery_changed']:'');
  if($race==='category-restored'||$race==='category-reassigned'){reset_check((int)topology_value('SELECT cat_id FROM fixture_forums WHERE forum_id=11')===($race==='category-restored'?999:10),'Current category assignment retained');}
  elseif($race==='forum-restored'||$race==='topic-reassigned'){reset_check((int)topology_value('SELECT forum_id FROM fixture_topics WHERE topic_id=20')===($race==='forum-restored'?999:10),'Current topic/forum retained');}
  elseif($race==='parent-restored'||$race==='post-reassigned'){reset_check((int)topology_value('SELECT topic_id FROM fixture_posts WHERE post_id=4')===($race==='parent-restored'?888:10),'Current post/topic retained');}
  elseif($race==='route-parent-changed'){reset_check((int)topology_value('SELECT forum_id FROM fixture_posts WHERE post_id=1')===11,'Dependent route uses current parent');}
  else{reset_check((int)topology_value('SELECT topic_id FROM fixture_posts WHERE post_id=4')===888,'No publication into changed public recovery area');}
 }
 $grant=md5('GeneralDB_Maintenanceadmin_db_maintenance.php');
 foreach($writes as $target){
  topology_fixture($engine);$resetServer->hook=function($sql)use($target){if(strpos($sql,$target)!==0){return;}$s=$GLOBALS['resetServer'];$s->hook=null;$s->pdo->exec('DELETE FROM fixture_sessions');};
  topology_run($lang['Not_Authorised']);
 }
 foreach(array_slice($writes,4,4) as $target){foreach(array('grant','owner') as $race){
  topology_fixture($engine,$race==='grant'?20:1);if($race==='grant'){$resetServer->pdo->exec("INSERT INTO fixture_junior VALUES (20,'".$grant."')");}
  $resetServer->hook=function($sql,$connection)use($target,$race){if(strpos($sql,$target)!==0){return;}$s=$GLOBALS['resetServer'];$s->hook=null;if($race==='grant'){$s->pdo->exec('DELETE FROM fixture_junior');}else{$connection->sql_close();}};
  topology_run($race==='grant'?$lang['Not_Authorised']:$lang['Maintenance_topology_failed']);
 }}
 foreach(array('get','sid','lock','junior') as $case){
  topology_fixture($engine,$case==='junior'?20:1);$expected=$lang['Session_invalid'];
  if($case==='get'){$_SERVER['REQUEST_METHOD']='GET';}elseif($case==='sid'){$_POST['sid']=array();}
  elseif($case==='lock'){$resetServer->failure='lock';$expected=$lang['Attachment_storage_busy'];}else{$expected=$lang['Not_Authorised'];}
  topology_run($expected);reset_check((int)topology_value('SELECT cat_id FROM fixture_forums WHERE forum_id=11')===999,'Denied topology leaves original state');
 }
 topology_fixture($engine,20);$resetServer->pdo->exec("INSERT INTO fixture_junior VALUES (20,'".$grant."')");topology_run();topology_complete();
 foreach(array('success','failure','invalid') as $case){
  topology_fixture($engine);$db=new ResetForum();$caught='';if($case==='failure'){$resetServer->failure='UPDATE fixture_topics SET forum_id';}elseif($case==='invalid'){$_POST['sid']='bad';}
  ob_start();try{eval($branch);}catch(ResetControllerFailure $e){$caught=$e->getMessage();}finally{$html=ob_get_clean();}
  reset_check(($caught==='')===($case==='success'),'Actual topology controller failure handling');
  if($case==='success'){reset_check(strpos($html,sprintf($lang['Maintenance_topology_summary'],1,2,4,5,0,1))!==false,'Actual translated topology summary');}
 }
 echo $engine.' '.$locale." topology maintenance passed.\n";
}}}finally{restore_error_handler();}
