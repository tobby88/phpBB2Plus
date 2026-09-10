<?php
$fixtureSource=file_get_contents(__DIR__.'/check-maintenance-sessions.php');
$fixtureEnd=strpos($fixtureSource,"\n\$controller=file_get_contents(");
if($fixtureEnd===false){throw new RuntimeException('Shared fixture declarations missing');}
eval(substr($fixtureSource,5,$fixtureEnd-5));
foreach(array('POSTS_TABLE'=>'fixture_posts','POSTS_TEXT_TABLE'=>'fixture_text','CONFIG_TABLE'=>'fixture_config',
 'CATEGORIES_TABLE'=>'fixture_categories','FORUMS_TABLE'=>'fixture_forums','TOPICS_TABLE'=>'fixture_topics','ATTACHMENTS_DESC_TABLE'=>'fixture_descriptions',
 'AUTH_ADMIN'=>5,'FORUM_LOCKED'=>1,'TOPIC_LOCKED'=>1,'POST_NORMAL'=>0,'ANONYMOUS'=>-1) as $k=>$v){define($k,$v);}
function phpbb_random_bytes($length){return str_repeat("\x12",$length);}
function cache_tree($write=false){reset_check($GLOBALS['resetServer']->owner===null&&$write===true,'Navigation refresh occurs after owner release');}
require_once $root.'includes/functions_maintenance_recovery.php';
function recovery_metadata_fixture($p){
 if($GLOBALS['resetNative']){return;}
 $attached=false;foreach($p->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC) as $row){if($row['name']==='information_schema'){$attached=true;}}
 if(!$attached){$p->exec("ATTACH DATABASE ':memory:' AS information_schema");}
 $method=method_exists($p,'createFunction')?'createFunction':'sqliteCreateFunction';$p->$method('DATABASE',function(){return 'fixture';});
 $p->exec('DROP TABLE IF EXISTS information_schema.COLUMNS; DROP TABLE IF EXISTS information_schema.TABLES; CREATE TABLE information_schema.COLUMNS (TABLE_SCHEMA TEXT,TABLE_NAME TEXT,COLUMN_NAME TEXT,DATA_TYPE TEXT); CREATE TABLE information_schema.TABLES (TABLE_SCHEMA TEXT,TABLE_NAME TEXT,AUTO_INCREMENT INTEGER)');
 $insert=$p->prepare('INSERT INTO information_schema.COLUMNS VALUES (?,?,?,?)');
 foreach($p->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_ASSOC) as $table){
  foreach($p->query('PRAGMA table_info('.$table['name'].')')->fetchAll(PDO::FETCH_ASSOC) as $row){$insert->execute(array('fixture',$table['name'],$row['name'],stripos($row['type'],'INT')!==false?'int':'varchar'));}
  $p->exec("INSERT INTO information_schema.TABLES VALUES ('fixture',".$p->quote($table['name']).",1)");
 }
}
function recovery_fixture($engine,$actor=1){
 reset_fixture($engine,$actor);$p=$GLOBALS['resetServer']->pdo;
 $auto=$GLOBALS['resetNative']?'INTEGER PRIMARY KEY AUTO_INCREMENT':'INTEGER PRIMARY KEY AUTOINCREMENT';
 $acl='';foreach(phpbb_acl_fields() as $field){$acl.=','.$field.' INTEGER NOT NULL DEFAULT 0';}
 $definitions=array(
  'config'=>'config_name VARCHAR(191) PRIMARY KEY,config_value VARCHAR(255)',
  'categories'=>'cat_id '.$auto.',maintenance_token VARCHAR(32) UNIQUE,cat_title VARCHAR(100),cat_order INTEGER,cat_main_type VARCHAR(1),cat_main INTEGER DEFAULT 0,cat_desc TEXT,icon VARCHAR(255)',
  'forums'=>'forum_id INTEGER PRIMARY KEY,maintenance_token VARCHAR(32) UNIQUE,cat_id INTEGER,forum_name VARCHAR(150),forum_desc TEXT,forum_status INTEGER,forum_order INTEGER,prune_next INTEGER,prune_enable INTEGER,forum_link VARCHAR(255),main_type VARCHAR(1),count_posts VARCHAR(1),forum_posts INTEGER DEFAULT 0,forum_topics INTEGER DEFAULT 0,forum_last_post_id INTEGER DEFAULT 0'.$acl,
  'topics'=>'topic_id '.$auto.',maintenance_token VARCHAR(32) UNIQUE,forum_id INTEGER,topic_title VARCHAR(60),topic_poster INTEGER,topic_time INTEGER,topic_status INTEGER,topic_type INTEGER,topic_moved_id INTEGER DEFAULT 0,topic_replies INTEGER DEFAULT 0,topic_first_post_id INTEGER DEFAULT 0,topic_last_post_id INTEGER DEFAULT 0,topic_attachment INTEGER DEFAULT 0',
  'posts'=>'post_id INTEGER PRIMARY KEY,topic_id INTEGER,forum_id INTEGER,poster_id INTEGER,post_time INTEGER,poster_ip VARCHAR(8),post_username VARCHAR(25),enable_html INTEGER,enable_bbcode INTEGER,enable_smilies INTEGER,enable_sig INTEGER,post_edit_time INTEGER,post_edit_count INTEGER,post_attachment INTEGER DEFAULT 0',
  'text'=>'post_id INTEGER PRIMARY KEY,bbcode_uid VARCHAR(10),post_subject VARCHAR(60),post_text TEXT',
  'links'=>'attach_id INTEGER,post_id INTEGER','descriptions'=>'attach_id INTEGER PRIMARY KEY,filename VARCHAR(255)'
 );
 foreach($definitions as $name=>$definition){
  $p->exec('DROP TABLE IF EXISTS fixture_'.$name);
  if($GLOBALS['resetNative']&&in_array($name,array('categories','forums','topics','posts','text'),true)){
   $schema=file_get_contents($GLOBALS['root'].'install/schemas/mysql_schema.sql');
   $suffix=$name==='text'?'posts_text':$name;
   reset_check(preg_match('/CREATE TABLE phpbb_'.$suffix.' \(.*?\) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;/s',$schema,$m)===1,'Canonical native schema available');
   $p->exec(str_replace(array('phpbb_'.$suffix,'ENGINE=MyISAM'),array('fixture_'.$name,'ENGINE='.$engine),$m[0]));
  }else{$p->exec('CREATE TABLE fixture_'.$name.' ('.$definition.')'.($GLOBALS['resetNative']?' ENGINE='.$engine:''));}
 }
 $p->exec("INSERT INTO fixture_config VALUES ('allow_html','0'),('allow_bbcode','1'),('allow_smilies','1'),('board_disable','0')");
 $p->exec("INSERT INTO fixture_text VALUES (1,'abcd1234','Grüße ''?','Body one'),(2,'','Second','Body two')");
 $p->exec("INSERT INTO fixture_links VALUES (7,1); INSERT INTO fixture_descriptions VALUES (7,'fixture.jpg')");
 recovery_metadata_fixture($p);
}
function recovery_run($expected=''){
 $out=null;$caught='';try{$out=dbmtnc_recover_orphan_text(new ResetForum(),$_POST);}catch(PhpbbAclException $e){$caught=$e->getMessage();}
 reset_check($caught===$expected,'Recovery outcome: '.$caught.' / '.$expected);
 reset_check($GLOBALS['resetServer']->owner===null,'Recovery owner released');
 reset_check((int)$GLOBALS['resetServer']->pdo->query("SELECT config_value FROM fixture_config WHERE config_name='board_disable'")->fetchColumn()===0,'Worker does not toggle availability');
 return $out;
}
function recovery_count($table){return (int)$GLOBALS['resetServer']->pdo->query('SELECT COUNT(*) FROM fixture_'.$table)->fetchColumn();}
$source=file_get_contents($root.'admin/admin_db_maintenance.php');$a=strpos($source,'// Check for texts without a post');$b=strpos($source,'// Check moved topics',$a);
reset_check($a!==false&&$b>$a,'Actual recovery controller fragment found');$recoveryBranch='switch(1){case 1:'.substr($source,$a,$b-$a).'}';
$recoveryWrites=array('INSERT INTO fixture_config','INSERT INTO fixture_categories','INSERT INTO fixture_forums','INSERT INTO fixture_topics','INSERT INTO fixture_posts','UPDATE fixture_topics','UPDATE fixture_forums');
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{
 foreach($resetNative?array('MyISAM','InnoDB'):array('SQLite') as $engine){foreach(array('english','german') as $locale){
  $lang=array('Not_Authorised'=>'not-authorized','Session_invalid'=>'session-invalid','Attachment_storage_busy'=>'busy');$phpEx='php';include $root.'language/lang_'.$locale.'/lang_dbmtnc.php';
  recovery_fixture($engine);$textBefore=$resetServer->pdo->query('SELECT * FROM fixture_text ORDER BY post_id')->fetchAll(PDO::FETCH_ASSOC);
  $out=recovery_run();reset_check($out===array('restored'=>2,'skipped'=>0),'Both orphan texts restored');
  foreach(array('categories','forums','topics') as $t){reset_check(recovery_count($t)===1,'One reserved '.$t);}
  $forum=$resetServer->pdo->query('SELECT * FROM fixture_forums')->fetch(PDO::FETCH_ASSOC);
  foreach(phpbb_acl_fields() as $field){reset_check((int)$forum[$field]===AUTH_ADMIN,'Recovery capability restricted '.$field);}
  reset_check((int)$forum['forum_posts']===2&&(int)$forum['forum_topics']===1&&(int)$forum['forum_last_post_id']===2,'Forum totals synchronized');
  $topic=$resetServer->pdo->query('SELECT * FROM fixture_topics')->fetch(PDO::FETCH_ASSOC);
  reset_check((int)$topic['topic_replies']===1&&(int)$topic['topic_first_post_id']===1&&(int)$topic['topic_last_post_id']===2&&(int)$topic['topic_attachment']===1,'Topic totals/attachments synchronized');
  $post=$resetServer->pdo->query('SELECT * FROM fixture_posts WHERE post_id=1')->fetch(PDO::FETCH_ASSOC);
  reset_check((int)$post['poster_id']===ANONYMOUS&&(int)$post['post_attachment']===1&&(int)$post['enable_html']===0&&(int)$post['enable_bbcode']===1&&(int)$post['enable_smilies']===1,'Current rendering policy and existing attachment retained');
  reset_check($textBefore===$resetServer->pdo->query('SELECT * FROM fixture_text ORDER BY post_id')->fetchAll(PDO::FETCH_ASSOC),'Original body/subject/uid unchanged');
  reset_check(recovery_run()===array('restored'=>0,'skipped'=>0),'Repeat does not duplicate');
  foreach($recoveryWrites as $failure){foreach(array(false,true) as $lost){
   recovery_fixture($engine);$resetServer->failure=$failure;$resetServer->lostAck=$lost;recovery_run($lang['Maintenance_recovery_failed']);
   $resetServer->failure='';$out=recovery_run();reset_check(recovery_count('posts')===2,'Retry publishes both posts after '.$failure);
   foreach(array('categories','forums','topics') as $t){reset_check(recovery_count($t)===1,'Retry reuses '.$t.' after '.$failure);}
   reset_check((int)$resetServer->pdo->query('SELECT forum_posts FROM fixture_forums')->fetchColumn()===2,'Retry repairs counter after final INSERT loss');
  }}
  foreach(array('gone','body','uid','subject','parent','public-forum','token') as $race){
   recovery_fixture($engine);$resetServer->hook=function($sql)use($race){
    if(strpos($sql,'INSERT INTO fixture_posts')!==0){return;}$s=$GLOBALS['resetServer'];$s->hook=null;
    if($race==='gone'){$s->pdo->exec('DELETE FROM fixture_text WHERE post_id=1');}
    elseif($race==='body'){$s->pdo->exec("UPDATE fixture_text SET post_text='Changed body' WHERE post_id=1");}
    elseif($race==='uid'){$s->pdo->exec("UPDATE fixture_text SET bbcode_uid='different' WHERE post_id=1");}
    elseif($race==='subject'){$s->pdo->exec("UPDATE fixture_text SET post_subject='Changed subject' WHERE post_id=1");}
   elseif($race==='parent'){$s->pdo->exec("INSERT INTO fixture_posts (post_id,topic_id,forum_id,poster_id,poster_ip) VALUES (1,999,999,20,'')");}
    elseif($race==='public-forum'){$s->pdo->exec('UPDATE fixture_forums SET auth_view=0');}
    else{$s->pdo->exec("UPDATE fixture_config SET config_value='changed' WHERE config_name='dbmtnc_orphan_recovery_token'");}
   };
   $out=recovery_run(in_array($race,array('public-forum','token'),true)?$lang['Maintenance_recovery_changed']:'');
   if(!in_array($race,array('public-forum','token'),true)){reset_check($out===array('restored'=>1,'skipped'=>1),'Changed source skipped '.$race);}
   if($race==='parent'){reset_check((int)$resetServer->pdo->query('SELECT topic_id FROM fixture_posts WHERE post_id=1')->fetchColumn()===999,'Concurrent parent not overwritten');}
   else{reset_check((int)$resetServer->pdo->query('SELECT COUNT(*) FROM fixture_posts WHERE post_id=1')->fetchColumn()===0,'No stale publication '.$race);}
  }
  foreach(array('get','sid','inactive','session','junior-denied') as $case){
   recovery_fixture($engine,$case==='junior-denied'?20:1);$expected=$lang['Not_Authorised'];
   if($case==='get'){$_SERVER['REQUEST_METHOD']='GET';$expected=$lang['Session_invalid'];}
   elseif($case==='sid'){$_POST['sid']=array();$expected=$lang['Session_invalid'];}
   elseif($case==='inactive'){$resetServer->pdo->exec('UPDATE fixture_users SET user_active=0 WHERE user_id=1');}
   elseif($case==='session'){$resetServer->pdo->exec('DELETE FROM fixture_sessions');}
   recovery_run($expected);reset_check(recovery_count('posts')===0&&recovery_count('categories')===0,'Denied request has no recovery side effects');
  }
  $grant=md5('GeneralDB_Maintenanceadmin_db_maintenance.php');
  recovery_fixture($engine,20);$resetServer->pdo->exec("INSERT INTO fixture_junior VALUES (20,'".$grant."')");recovery_run();
  recovery_fixture($engine);$resetServer->failure='lock';recovery_run($lang['Attachment_storage_busy']);
  reset_check(recovery_count('categories')===0&&recovery_count('posts')===0,'Contended owner creates nothing');
  recovery_fixture($engine);$resetServer->pdo->exec('DELETE FROM fixture_text');
  reset_check(recovery_run()===array('restored'=>0,'skipped'=>0)&&recovery_count('categories')===0&&recovery_count('config')===4,'Empty run allocates nothing');
  recovery_fixture($engine);$resetServer->pdo->exec('UPDATE fixture_text SET post_subject=NULL,post_text=NULL WHERE post_id=1');
  recovery_run();reset_check($resetServer->pdo->query('SELECT post_text FROM fixture_text WHERE post_id=1')->fetchColumn()===null,'Nullable original text preserved');
  foreach(array('INSERT INTO fixture_config','INSERT INTO fixture_categories','INSERT INTO fixture_forums','INSERT INTO fixture_topics') as $target){
   recovery_fixture($engine);$resetServer->hook=function($sql)use($target){if(strpos($sql,$target)!==0){return;}$s=$GLOBALS['resetServer'];$s->hook=null;$s->pdo->exec('DELETE FROM fixture_text WHERE post_id=1');};
   reset_check(recovery_run()===array('restored'=>1,'skipped'=>1),'Vanished source before allocation skipped');
   foreach(array('categories','forums','topics') as $t){reset_check(recovery_count($t)===1,'Second source reuses partial allocation');}
  }
  foreach($recoveryWrites as $target){foreach(array('grant','session','owner') as $race){
   recovery_fixture($engine,$race==='grant'?20:1);
   if($race==='grant'){$resetServer->pdo->exec("INSERT INTO fixture_junior VALUES (20,'".$grant."')");}
   $resetServer->hook=function($sql,$connection)use($target,$race){if(strpos($sql,$target)!==0){return;}$s=$GLOBALS['resetServer'];$s->hook=null;
    if($race==='grant'){$s->pdo->exec('DELETE FROM fixture_junior');}
    elseif($race==='session'){$s->pdo->exec('DELETE FROM fixture_sessions');}
    else{$connection->sql_close();}
   };
   recovery_run($race==='owner'?$lang['Maintenance_recovery_failed']:$lang['Not_Authorised']);
  }}
  if($locale==='english'){
   recovery_fixture($engine);
   for($i=3;$i<=103;$i++){$resetServer->pdo->exec("INSERT INTO fixture_text VALUES (".$i.",'','Paging','Original')");}
   reset_check(recovery_run()===array('restored'=>103,'skipped'=>0),'Recovery crosses page boundary without skips or duplicates');
   reset_check(recovery_count('posts')===103&&recovery_count('topics')===1,'Paged recovery preserves single target');
  }
  foreach($recoveryWrites as $target){
   recovery_fixture($engine);$resetServer->hook=function($sql)use($target){if(strpos($sql,$target)!==0){return;}$s=$GLOBALS['resetServer'];$s->hook=null;$s->pdo->exec('UPDATE fixture_users SET user_active=0 WHERE user_id=1');};
   recovery_run($lang['Not_Authorised']);
  }
  foreach(array('success','failure','invalid') as $case){
   recovery_fixture($engine);$db=new ResetForum();$caught='';
   if($case==='failure'){$resetServer->failure='INSERT INTO fixture_posts';}if($case==='invalid'){$_POST['sid']='bad';}
   ob_start();try{eval($recoveryBranch);}catch(ResetControllerFailure $e){$caught=$e->getMessage();}finally{$html=ob_get_clean();}
   reset_check(($caught!=='')===($case!=='success'),'Actual recovery controller outcome');
   if($case==='success'){reset_check(strpos($html,sprintf($lang['Maintenance_recovery_summary'],2,0))!==false,'Actual localized summary');}
  }
  echo $engine.' '.$locale." orphan text recovery passed.\n";
 }}
}finally{restore_error_handler();}
