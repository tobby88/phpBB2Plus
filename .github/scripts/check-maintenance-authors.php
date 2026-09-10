<?php
$fixtureSource=file_get_contents(__DIR__.'/check-maintenance-sessions.php');
$fixtureEnd=strpos($fixtureSource,"\n\$controller=file_get_contents(");
if($fixtureEnd===false){throw new RuntimeException('Shared fixture declarations missing');}
eval(substr($fixtureSource,5,$fixtureEnd-5));
define('POSTS_TABLE','fixture_posts');define('TOPICS_TABLE','fixture_topics');define('DELETED',-1);
require_once $root.'includes/functions_maintenance_authors.php';
function author_fixture($engine,$actor=1){
 reset_fixture($engine,$actor);$p=$GLOBALS['resetServer']->pdo;
 foreach(array('posts'=>'post_id INTEGER PRIMARY KEY,topic_id INTEGER,poster_id INTEGER,post_username VARCHAR(25),poster_ip VARCHAR(8),post_time INTEGER',
  'topics'=>'topic_id INTEGER PRIMARY KEY,forum_id INTEGER,topic_poster INTEGER,topic_title VARCHAR(60)') as $name=>$definition){
  $p->exec('DROP TABLE IF EXISTS fixture_'.$name);
  if($GLOBALS['resetNative']){
   $schema=file_get_contents($GLOBALS['root'].'install/schemas/mysql_schema.sql');
   reset_check(preg_match('/CREATE TABLE phpbb_'.$name.' \(.*?\) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;/s',$schema,$m)===1,'Canonical author schema');
   $p->exec(str_replace(array('phpbb_'.$name,'ENGINE=InnoDB ROW_FORMAT=DYNAMIC'),array('fixture_'.$name,'ENGINE='.$engine),$m[0]));
  }else{$p->exec('CREATE TABLE fixture_'.$name.' ('.$definition.')');}
 }
 $p->exec("INSERT INTO fixture_posts (post_id,topic_id,poster_id,post_username,poster_ip,post_time) VALUES (1,10,999,'Grüße Alias','',100),(2,10,20,'','',90),(3,30,-1,'Guest@Fixture','',80),(4,40,222,'Preserve','',70),(5,50,20,'','',60),(6,60,20,'','',50)");
 $p->exec("INSERT INTO fixture_topics (topic_id,forum_id,topic_poster,topic_title) VALUES (10,1,998,'Orphan'),(20,1,333,'Empty'),(30,1,-1,'Guest'),(40,1,333,'Orphan2'),(50,1,20,'Healthy'),(60,1,888,'Valid first author')");
}
function author_value($table,$id,$column){return $GLOBALS['resetServer']->pdo->query('SELECT '.$column.' FROM fixture_'.$table.' WHERE '.($table==='posts'?'post':'topic').'_id='.(int)$id)->fetchColumn();}
function author_run($expected=''){
 $out=null;$caught='';try{$out=dbmtnc_repair_authors(new ResetForum(),$_POST);}catch(PhpbbAclException $e){$caught=$e->getMessage();}
 reset_check($caught===$expected,'Author repair outcome: '.$caught.' / '.$expected);
 reset_check($GLOBALS['resetServer']->owner===null,'Author repair owner released');return $out;
}
$source=file_get_contents($root.'admin/admin_db_maintenance.php');
$a=strpos($source,'// Repair missing authors against current source and ACP authority.');$b=strpos($source,'// Check for forums with invalid categories',$a);
reset_check($a!==false&&$b>$a,'Actual author controller fragment found');$authorBranch=substr($source,$a,$b-$a);
$writes=array('UPDATE fixture_posts','UPDATE fixture_topics');
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{foreach($resetNative?array('MyISAM','InnoDB'):array('SQLite') as $engine){foreach(array('english','german') as $locale){
 $lang=array('Not_Authorised'=>'not-authorized','Session_invalid'=>'session-invalid','Attachment_storage_busy'=>'busy');$phpEx='php';include $root.'language/lang_'.$locale.'/lang_dbmtnc.php';
 author_fixture($engine);$names=$resetServer->pdo->query('SELECT post_id,post_username,poster_ip,post_time FROM fixture_posts ORDER BY post_id')->fetchAll(PDO::FETCH_ASSOC);
 reset_check(author_run()===array('posts'=>2,'topics'=>4,'skipped'=>0),'Accurate repaired-author counts');
 reset_check((int)author_value('topics',10,'topic_poster')===DELETED&&(int)author_value('topics',60,'topic_poster')===20,'First post determined by actual ID, not timestamp, with valid identity');
 reset_check($names===$resetServer->pdo->query('SELECT post_id,post_username,poster_ip,post_time FROM fixture_posts ORDER BY post_id')->fetchAll(PDO::FETCH_ASSOC),'Guest names, dates and IPs untouched');
 reset_check(author_value('posts',3,'post_username')==='Guest@Fixture'&&(int)author_value('posts',3,'poster_id')===DELETED,'Guest sentinel/name retained even without reserved user row');
 reset_check(author_run()===array('posts'=>0,'topics'=>0,'skipped'=>0),'Second author repair is empty');
 foreach($writes as $target){foreach(array(false,true) as $lost){
  author_fixture($engine);$resetServer->failure=$target;$resetServer->lostAck=$lost;author_run($lang['Maintenance_author_failed']);$resetServer->failure='';author_run();
  reset_check((int)author_value('posts',1,'poster_id')===DELETED&&(int)author_value('topics',60,'topic_poster')===20,'Interrupted repair converges');
 }}
 foreach($writes as $target){foreach(array('restored','changed') as $race){
  author_fixture($engine);$isPost=$target===$writes[0];
  $resetServer->hook=function($sql)use($target,$race,$isPost){if(strpos($sql,$target)!==0){return;}$s=$GLOBALS['resetServer'];$s->hook=null;
   if($race==='restored'){$s->pdo->exec('INSERT INTO fixture_users VALUES ('.($isPost?999:998).',0,1)');}
   else{$s->pdo->exec($isPost?'UPDATE fixture_posts SET poster_id=20 WHERE post_id=1':'UPDATE fixture_topics SET topic_poster=20 WHERE topic_id=10');}
  };
  $out=author_run();reset_check($out['skipped']===1,'Changed source skipped');
  reset_check((int)author_value($isPost?'posts':'topics',$isPost?1:10,$isPost?'poster_id':'topic_poster')===($race==='restored'?($isPost?999:998):20),'Concurrent valid attribution retained');
 }}
 foreach(array('delete-first','move-first','change-first','remove-author') as $race){
  author_fixture($engine);$resetServer->hook=function($sql)use($race){if(strpos($sql,'UPDATE fixture_topics')!==0||strpos($sql,'WHERE topic_id = 60 ')===false){return;}$s=$GLOBALS['resetServer'];$s->hook=null;
   if($race==='delete-first'){$s->pdo->exec('DELETE FROM fixture_posts WHERE post_id=6');}
   elseif($race==='move-first'){$s->pdo->exec('UPDATE fixture_posts SET topic_id=50 WHERE post_id=6');}
   elseif($race==='change-first'){$s->pdo->exec('UPDATE fixture_posts SET poster_id=1 WHERE post_id=6');}
   else{$s->pdo->exec('DELETE FROM fixture_users WHERE user_id=20');}
  };
  author_run();reset_check((int)author_value('topics',60,'topic_poster')===($race==='change-first'?1:DELETED),'Topic source re-evaluated in write');
 }
 $grant=md5('GeneralDB_Maintenanceadmin_db_maintenance.php');
 foreach($writes as $target){foreach(array('inactive','grant','session','owner') as $race){
  author_fixture($engine,$race==='grant'?20:1);if($race==='grant'){$resetServer->pdo->exec("INSERT INTO fixture_junior VALUES (20,'".$grant."')");}
  $resetServer->hook=function($sql,$connection)use($target,$race){if(strpos($sql,$target)!==0){return;}$s=$GLOBALS['resetServer'];$s->hook=null;
   if($race==='inactive'){$s->pdo->exec('UPDATE fixture_users SET user_active=0 WHERE user_id=1');}
   elseif($race==='grant'){$s->pdo->exec('DELETE FROM fixture_junior');}
   elseif($race==='session'){$s->pdo->exec('DELETE FROM fixture_sessions');}
   else{$connection->sql_close();}
  };
  author_run($race==='owner'?$lang['Maintenance_author_failed']:$lang['Not_Authorised']);
  reset_check((int)author_value($target===$writes[0]?'posts':'topics',$target===$writes[0]?1:10,$target===$writes[0]?'poster_id':'topic_poster')===($target===$writes[0]?999:998),'No stale authority write');
 }}
 foreach(array('get','sid','lock','junior') as $case){
  author_fixture($engine,$case==='junior'?20:1);$expected=$lang['Session_invalid'];
  if($case==='get'){$_SERVER['REQUEST_METHOD']='GET';}elseif($case==='sid'){$_POST['sid']=array();}
  elseif($case==='lock'){$resetServer->failure='lock';$expected=$lang['Attachment_storage_busy'];}else{$expected=$lang['Not_Authorised'];}
  author_run($expected);reset_check((int)author_value('posts',1,'poster_id')===999,'Denied request leaves identity unchanged');
 }
 author_fixture($engine,20);$resetServer->pdo->exec("INSERT INTO fixture_junior VALUES (20,'".$grant."')");author_run();
 author_fixture($engine);$resetServer->pdo->exec("INSERT INTO fixture_users VALUES (999,0,0),(222,0,0),(998,0,0),(333,0,0),(888,0,0)");
 reset_check(author_run()===array('posts'=>0,'topics'=>0,'skipped'=>0),'Inactive but existing authors are not deleted');
 if($locale==='english'){
  author_fixture($engine);for($id=7;$id<=107;$id++){$resetServer->pdo->exec("INSERT INTO fixture_posts (post_id,topic_id,poster_id,post_username,poster_ip) VALUES (".$id.",50,999,'Keep','')");}
  $out=author_run();reset_check($out['posts']===103,'All missing authors past page boundary repaired');
 }
 foreach(array('success','failure','invalid') as $case){
  author_fixture($engine);$db=new ResetForum();$caught='';if($case==='failure'){$resetServer->failure=$writes[0];}elseif($case==='invalid'){$_POST['sid']='bad';}
  ob_start();try{eval($authorBranch);}catch(ResetControllerFailure $e){$caught=$e->getMessage();}finally{$html=ob_get_clean();}
  reset_check(($caught==='')===($case==='success'),'Actual controller propagates errors');
  if($case==='success'){reset_check(strpos($html,sprintf($lang['Maintenance_author_summary'],2,4,0))!==false,'Localized actual controller summary');}
 }
 echo $engine.' '.$locale." author maintenance passed.\n";
}}}finally{restore_error_handler();}
