<?php
$fixtureSource=file_get_contents(__DIR__.'/check-maintenance-recovery.php');
$fixtureEnd=strpos($fixtureSource,"\n\$source=file_get_contents(");
if($fixtureEnd===false){throw new RuntimeException('Recovery fixture declarations missing');}
eval(substr($fixtureSource,5,$fixtureEnd-5));
foreach(array('TOPIC_MOVED'=>2,'DELETED'=>-1,'GROUPS_TABLE'=>'fixture_groups','AUTH_ACCESS_TABLE'=>'fixture_auth_access','TOPICS_WATCH_TABLE'=>'fixture_topics_watch','PRUNE_TABLE'=>'fixture_forum_prune') as $k=>$v){define($k,$v);}
require_once $root.'includes/functions_maintenance_cleanup.php';
function cleanup_fixture($engine,$actor=1){
 recovery_fixture($engine,$actor);$p=$GLOBALS['resetServer']->pdo;
 $p->exec('DELETE FROM fixture_text; DELETE FROM fixture_links; DELETE FROM fixture_descriptions');
 foreach(array('groups'=>'group_id INTEGER PRIMARY KEY','auth_access'=>'group_id INTEGER,forum_id INTEGER','topics_watch'=>'user_id INTEGER,topic_id INTEGER,notify_status INTEGER','forum_prune'=>'prune_id INTEGER PRIMARY KEY,forum_id INTEGER,prune_days INTEGER,prune_freq INTEGER') as $name=>$definition){
  $p->exec('DROP TABLE IF EXISTS fixture_'.$name);
  if($GLOBALS['resetNative']&&$name==='forum_prune'){
   $schema=file_get_contents($GLOBALS['root'].'install/schemas/mysql_schema.sql');reset_check(preg_match('/CREATE TABLE phpbb_forum_prune \(.*?\) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;/s',$schema,$m)===1,'Canonical prune schema');
   $p->exec(str_replace(array('phpbb_forum_prune','ENGINE=MyISAM'),array('fixture_forum_prune','ENGINE='.$engine),$m[0]));
  }else{$p->exec('CREATE TABLE fixture_'.$name.' ('.$definition.')'.($GLOBALS['resetNative']?' ENGINE='.$engine:''));}
 }
 $p->exec("INSERT INTO fixture_categories (cat_id,cat_title,cat_order,cat_main_type,cat_main,cat_desc) VALUES (10,'Original',10,'c',0,'')");
 $p->exec("INSERT INTO fixture_forums (forum_id,cat_id,forum_name,main_type,prune_enable) VALUES (10,10,'Healthy','c',0),(11,10,'Missing rule','c',1),(12,10,'Identical rules','c',1),(13,10,'Conflicting rules','c',1)");
 $p->exec("INSERT INTO fixture_topics (topic_id,forum_id,topic_title,topic_poster,topic_time,topic_status,topic_moved_id,maintenance_token) VALUES (10,10,'Empty <topic>',20,100,0,0,NULL),(20,10,'Healthy',20,100,0,0,NULL),(30,10,'Valid redirect',20,100,2,20,NULL),(40,10,'Invalid redirect',20,100,2,999,NULL),(50,10,'Reserved',20,100,1,0,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'),(60,10,'Stale marker',20,100,0,777,NULL)");
 $p->exec("INSERT INTO fixture_posts (post_id,topic_id,forum_id,poster_id,post_time,poster_ip,post_username) VALUES (1,10,10,20,100,'','Guest@Fixture'),(2,20,10,20,100,'',''),(3,60,10,20,100,'','')");
 $p->exec("INSERT INTO fixture_text VALUES (2,'uid1234','Grüße','Healthy body'),(3,'uid1234','Marker','Body')");
 $p->exec('INSERT INTO fixture_groups VALUES (20); INSERT INTO fixture_auth_access VALUES (20,10),(999,10),(20,999); INSERT INTO fixture_topics_watch VALUES (20,20,0),(999,20,0),(999,20,1),(20,999,0); INSERT INTO fixture_forum_prune VALUES (1,999,30,7),(2,12,30,7),(3,12,30,7),(4,13,30,7),(5,13,90,14)');
 recovery_metadata_fixture($p);
}
function cleanup_value($sql){return $GLOBALS['resetServer']->pdo->query($sql)->fetchColumn();}
function cleanup_run($phase,$expected=''){
 $out=null;$caught='';$state=cleanup_value("SELECT config_value FROM fixture_config WHERE config_name='board_disable'");
 try{$out=dbmtnc_cleanup_structure(new ResetForum(),$_POST,$phase);}catch(PhpbbAclException $e){$caught=$e->getMessage();}
 reset_check($caught===$expected,'Cleanup outcome '.$caught.' / '.$expected);reset_check($GLOBALS['resetServer']->owner===null,'Cleanup lock released');
 reset_check(cleanup_value("SELECT config_value FROM fixture_config WHERE config_name='board_disable'")===$state,'Availability unchanged on every outcome');return $out;
}
$controller=file_get_contents($root.'admin/admin_db_maintenance.php');$a=strpos($controller,"case 'check_post':");$b=strpos($controller,"case 'check_vote':",$a);
reset_check($a!==false&&$b>$a,'Actual complete check_post controller');$branch='switch("check_post"){'.substr($controller,$a,$b-$a).'}';
$writes=array('DELETE FROM fixture_posts'=>'parents','DELETE FROM fixture_topics'=>'parents','UPDATE fixture_topics SET topic_moved_id'=>'references','DELETE FROM fixture_forum_prune'=>'references','UPDATE fixture_forums SET prune_enable'=>'references','DELETE FROM fixture_topics_watch'=>'references','DELETE FROM fixture_auth_access'=>'references');
$writes['DELETE FROM fixture_topics WHERE topic_id = 40']='references';
$writes['DELETE FROM fixture_forum_prune WHERE prune_id = 3']='references';
$protected=array('DELETE FROM fixture_posts'=>'fixture_posts WHERE post_id=1','DELETE FROM fixture_topics'=>'fixture_topics WHERE topic_id=10',
 'UPDATE fixture_topics SET topic_moved_id'=>'fixture_topics WHERE topic_id=60','DELETE FROM fixture_forum_prune'=>'fixture_forum_prune WHERE prune_id=1',
 'UPDATE fixture_forums SET prune_enable'=>'fixture_forums WHERE forum_id=11','DELETE FROM fixture_topics_watch'=>'fixture_topics_watch WHERE user_id=20 AND topic_id=999',
 'DELETE FROM fixture_auth_access'=>'fixture_auth_access WHERE group_id=20 AND forum_id=999','DELETE FROM fixture_topics WHERE topic_id = 40'=>'fixture_topics WHERE topic_id=40','DELETE FROM fixture_forum_prune WHERE prune_id = 3'=>'fixture_forum_prune WHERE prune_id=3');
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{foreach($resetNative?array('MyISAM','InnoDB'):array('SQLite') as $engine){foreach(array('english','german') as $locale){
 $lang=array('Not_Authorised'=>'not-authorized','Session_invalid'=>'session-invalid','Attachment_storage_busy'=>'busy');$phpEx='php';include $root.'language/lang_'.$locale.'/lang_dbmtnc.php';
 cleanup_fixture($engine);$out=cleanup_run('parents');reset_check($out['changed']===2&&$out['skipped']===0,'Accurate parent totals');
 reset_check((int)cleanup_value('SELECT COUNT(*) FROM fixture_topics WHERE topic_id=50')===1,'Reserved empty recovery topic survives retry');
 $out=cleanup_run('references');reset_check($out['steps']===array('redirects'=>1,'moved'=>1,'prune_orphans'=>1,'prune_duplicates'=>1,'prune_disabled'=>1,'watch'=>3,'acl'=>2),'Accurate child row totals including duplicate composites');
 reset_check($out['conflicts']===array(13),'Conflicting prune policies reported');reset_check((int)cleanup_value('SELECT COUNT(*) FROM fixture_forum_prune WHERE forum_id=13')===2,'Different policies retained');
 reset_check((int)cleanup_value('SELECT MIN(prune_id) FROM fixture_forum_prune WHERE forum_id=12')===2,'Oldest identical policy retained');
 reset_check(cleanup_run('parents')['changed']===0&&cleanup_run('references')['changed']===0,'Repeat is idempotent');
 $races=array('post-body','post-gone','topic-post','topic-redirect','topic-gone','redirect-destination','redirect-retarget','redirect-post','redirect-normal','prune-forum','prune-policy','prune-conflict','watch-user','watch-topic','acl-group','acl-forum');
 foreach($races as $race){
  cleanup_fixture($engine);$phase=strpos($race,'post-')===0||strpos($race,'topic-')===0?'parents':'references';
  if(strpos($race,'topic-')===0){$resetServer->pdo->exec('DELETE FROM fixture_posts WHERE post_id=1');}
  $resetServer->hook=function($sql)use($race){
   $prefix=strpos($race,'post-')===0?'DELETE FROM fixture_posts':((strpos($race,'topic-')===0||strpos($race,'redirect-')===0)?'DELETE FROM fixture_topics':(strpos($race,'watch-')===0?'DELETE FROM fixture_topics_watch':(strpos($race,'acl-')===0?'DELETE FROM fixture_auth_access':($race==='prune-policy'?'UPDATE fixture_forums SET prune_enable':'DELETE FROM fixture_forum_prune'))));
   if(strpos($sql,$prefix)!==0||($race==='prune-conflict'&&strpos($sql,'prune_id = 3 ')===false)){return;}$s=$GLOBALS['resetServer'];$s->hook=null;$p=$s->pdo;
   if($race==='post-body'){$p->exec("INSERT INTO fixture_text VALUES (1,'','Restored','New body')");}
   elseif($race==='post-gone'){$p->exec('DELETE FROM fixture_posts WHERE post_id=1');}
   elseif($race==='topic-post'||$race==='redirect-post'){$p->exec("INSERT INTO fixture_posts (post_id,topic_id,forum_id,poster_id,poster_ip) VALUES (9,".($race==='topic-post'?10:40).",10,20,'')");}
   elseif($race==='topic-redirect'){$p->exec('UPDATE fixture_topics SET topic_status=2,topic_moved_id=20 WHERE topic_id=10');}
   elseif($race==='topic-gone'){$p->exec('DELETE FROM fixture_topics WHERE topic_id=10');}
   elseif($race==='redirect-retarget'){$p->exec('UPDATE fixture_topics SET topic_moved_id=20 WHERE topic_id=40');}
   elseif($race==='redirect-normal'){$p->exec('UPDATE fixture_topics SET topic_status=0,topic_moved_id=0 WHERE topic_id=40');}
   elseif($race==='redirect-destination'||$race==='watch-topic'){$p->exec("INSERT INTO fixture_topics (topic_id,forum_id,topic_title,topic_poster) VALUES (999,10,'Restored',20)");}
   elseif($race==='prune-forum'||$race==='acl-forum'){$p->exec("INSERT INTO fixture_forums (forum_id,cat_id,forum_name,main_type) VALUES (999,10,'Restored','c')");}
   elseif($race==='prune-policy'){$p->exec('INSERT INTO fixture_forum_prune VALUES (9,11,30,7)');}
   elseif($race==='prune-conflict'){$p->exec('UPDATE fixture_forum_prune SET prune_days=90 WHERE prune_id=3');}
   elseif($race==='watch-user'){$p->exec('INSERT INTO fixture_users VALUES (999,0,1)');}
   else{$p->exec('INSERT INTO fixture_groups VALUES (999)');}
  };
  $out=cleanup_run($phase);reset_check($out['skipped']>0,'Changed candidate skipped '.$race);
  if($race==='post-body'){reset_check((int)cleanup_value('SELECT COUNT(*) FROM fixture_posts WHERE post_id=1')===1,'Restored body keeps parent');}
  if(strpos($race,'topic-')===0&&$race!=='topic-gone'){reset_check((int)cleanup_value('SELECT COUNT(*) FROM fixture_topics WHERE topic_id=10')===1,'Reused topic survives');}
  if(strpos($race,'redirect-')===0){reset_check((int)cleanup_value('SELECT COUNT(*) FROM fixture_topics WHERE topic_id=40')===1,'Repaired redirect retained');}
  if($race==='prune-conflict'){reset_check((int)cleanup_value('SELECT COUNT(*) FROM fixture_forum_prune WHERE forum_id=12')===2,'Late conflict retains both policies');}
  if($race==='prune-forum'){reset_check((int)cleanup_value('SELECT COUNT(*) FROM fixture_forum_prune WHERE prune_id=1')===1,'Restored forum retains policy');}
  if($race==='prune-policy'){reset_check((int)cleanup_value('SELECT prune_enable FROM fixture_forums WHERE forum_id=11')===1,'Restored policy stays enabled');}
  if(strpos($race,'watch-')===0){reset_check((int)cleanup_value('SELECT COUNT(*) FROM fixture_topics_watch WHERE '.($race==='watch-user'?'user_id=999':'topic_id=999'))>0,'Restored watch parent retains subscriptions');}
  if(strpos($race,'acl-')===0){reset_check((int)cleanup_value('SELECT COUNT(*) FROM fixture_auth_access WHERE '.($race==='acl-group'?'group_id=999':'forum_id=999'))===1,'Restored ACL parent retains permission');}
 }
 foreach($writes as $target=>$phase){foreach(array('failure','lost-ack','session','grant','owner') as $case){
  cleanup_fixture($engine,$case==='grant'?20:1);
  $protectedSql='SELECT * FROM '.$protected[$target];$beforeProtected=$resetServer->pdo->query($protectedSql)->fetchAll(PDO::FETCH_ASSOC);
  if($case==='grant'){$resetServer->pdo->exec("INSERT INTO fixture_junior VALUES (20,'".md5('GeneralDB_Maintenanceadmin_db_maintenance.php')."')");}
  if($case==='failure'||$case==='lost-ack'){$resetServer->failure=$target;$resetServer->lostAck=$case==='lost-ack';}
  else{$resetServer->hook=function($sql,$connection)use($target,$case){if(strpos($sql,$target)!==0){return;}$s=$GLOBALS['resetServer'];$s->hook=null;if($case==='owner'){$connection->sql_close();}elseif($case==='grant'){$s->pdo->exec('DELETE FROM fixture_junior');}else{$s->pdo->exec("UPDATE fixture_sessions SET session_admin=0 WHERE session_id='".str_repeat('a',32)."'");}};}
  cleanup_run($phase,in_array($case,array('session','grant'),true)?$lang['Not_Authorised']:$lang['Maintenance_cleanup_failed']);
  if(in_array($case,array('session','grant','owner'),true)){reset_check($beforeProtected===$resetServer->pdo->query($protectedSql)->fetchAll(PDO::FETCH_ASSOC),'Revocation blocks actual mutation, not only success report: '.$target);}
  if($case==='failure'||$case==='lost-ack'){$resetServer->failure='';cleanup_run($phase);reset_check(cleanup_run($phase)['changed']===0,'Retry finishes '.$target);}
 }}
 foreach(array('get','sid','lock','junior') as $case){cleanup_fixture($engine,$case==='junior'?20:1);$expected=$lang['Session_invalid'];if($case==='get'){$_SERVER['REQUEST_METHOD']='GET';}elseif($case==='sid'){$_POST['sid']='bad';}elseif($case==='lock'){$resetServer->failure='lock';$expected=$lang['Attachment_storage_busy'];}else{$expected=$lang['Not_Authorised'];}cleanup_run('parents',$expected);reset_check((int)cleanup_value('SELECT COUNT(*) FROM fixture_posts')===3,'Rejected request preserves source');}
 cleanup_fixture($engine);$p=$resetServer->pdo;for($i=1000;$i<1205;$i++){$p->exec('INSERT INTO fixture_topics_watch VALUES (999,'.$i.',0)');}$out=cleanup_run('references');reset_check($out['steps']['watch']===208,'Composite cleanup spans more than two pages');
 foreach(array('success','disabled','failure','lost-ack','counter-failure','orphan','invalid') as $case){
  cleanup_fixture($engine);$db=new ResetForum();$caught='';
  if($case==='disabled'){$resetServer->pdo->exec("UPDATE fixture_config SET config_value='1' WHERE config_name='board_disable'");}
  if($case==='failure'||$case==='lost-ack'){$resetServer->failure='DELETE FROM fixture_auth_access';$resetServer->lostAck=$case==='lost-ack';}
  if($case==='orphan'){$resetServer->pdo->exec("INSERT INTO fixture_text VALUES (99,'uid1234','Recovery Grüße','Original orphan body')");}
  if($case==='counter-failure'){$resetServer->hook=function($sql){if(strpos($sql,'DELETE FROM fixture_auth_access')!==0){return;}$GLOBALS['resetServer']->hook=null;$GLOBALS['resetServer']->failure='UPDATE fixture_topics SET topic_replies';};}
  if($case==='invalid'){$_POST['sid']='bad';}
  ob_start();try{eval($branch);}catch(ResetControllerFailure $e){$caught=$e->getMessage();}finally{$html=ob_get_clean();}
  reset_check(($caught==='')===in_array($case,array('success','disabled','orphan'),true),'Complete controller outcome '.$case);
  reset_check($resetServer->owner===null&&cleanup_value("SELECT config_value FROM fixture_config WHERE config_name='board_disable'")===($case==='disabled'?'1':'0'),'Complete controller preserves board state and releases owners');
  reset_check(strpos($html,'synchronize_post_direct')===false,'No browser continuation needed');
  if($caught===''){reset_check(strpos($html,sprintf($lang['Maintenance_cleanup_prune_conflicts'],'13'))!==false,'Translated conflict report');reset_check(strpos($html,'Empty <topic>')===false,'No unescaped diagnostic title');}
  if(in_array($case,array('failure','lost-ack','counter-failure','orphan'),true)){
   $resetServer->failure='';$resetServer->hook=null;ob_start();try{eval($branch);}finally{ob_end_clean();}
   reset_check((int)cleanup_value('SELECT COUNT(*) FROM fixture_topics WHERE topic_id=50')===1,'Full retry keeps reserved topic');
   reset_check((int)cleanup_value('SELECT COUNT(*) FROM fixture_auth_access')===1,'Full retry completes reference cleanup');
   if($case==='orphan'){reset_check((int)cleanup_value('SELECT COUNT(*) FROM fixture_posts WHERE post_id=99')===1&&cleanup_value('SELECT post_text FROM fixture_text WHERE post_id=99')==='Original orphan body','Full repeat retains restored body and identity');}
  }
 }
 echo $engine.' '.$locale." owned structural cleanup and full controller passed.\n";
}}}finally{restore_error_handler();}
