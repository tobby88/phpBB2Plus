<?php
$fixtureSource=file_get_contents(__DIR__.'/check-maintenance-recovery.php');$fixtureEnd=strpos($fixtureSource,"\n\$source=file_get_contents(");
if($fixtureEnd===false){throw new RuntimeException('Allocation fixture boundary missing');}eval(substr($fixtureSource,5,$fixtureEnd-5));
function allocation_table($engine,$name,$field,$value,$type='INTEGER'){
 $p=$GLOBALS['resetServer']->pdo;$p->exec('DROP TABLE IF EXISTS fixture_'.$name);$p->exec('CREATE TABLE fixture_'.$name.' ('.$field.' '.$type.')'.($GLOBALS['resetNative']?' ENGINE='.$engine:''));
 $p->exec('INSERT INTO fixture_'.$name.' VALUES ('.(int)$value.')');recovery_metadata_fixture($p);
}
function allocation_ids(){return $GLOBALS['resetServer']->pdo->query('SELECT (SELECT cat_id FROM fixture_categories WHERE maintenance_token IS NOT NULL) AS category,(SELECT forum_id FROM fixture_forums WHERE maintenance_token IS NOT NULL) AS forum,(SELECT topic_id FROM fixture_topics WHERE maintenance_token IS NOT NULL) AS topic')->fetch(PDO::FETCH_ASSOC);}
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{foreach($resetNative?array('MyISAM','InnoDB'):array('SQLite') as $engine){
 $lang=array('Not_Authorised'=>'denied','Session_invalid'=>'session','Attachment_storage_busy'=>'busy');$phpEx='php';include $root.'language/lang_english/lang_dbmtnc.php';
 recovery_fixture($engine);$resetServer->metadataExpiry=86400;recovery_run();reset_check($resetServer->metadataExpiry===0,'Use uncached MySQL metadata on the dedicated connection');
 recovery_fixture($engine);$resetServer->metadataExpiry=86400;$resetServer->failure='SET SESSION information_schema_stats_expiry = 0';recovery_run($lang['Maintenance_recovery_failed']);
 reset_check(recovery_count('categories')===0&&recovery_count('posts')===0,'Cannot allocate using stale high-water metadata if freshness setup fails');
 // Each optional source is individually authoritative, not just the largest
 // one in a combined fixture. Old sessions, polls and KB links must not alias.
 foreach(array('topics_watch'=>'topic_id','vote_desc'=>'topic_id','logs'=>'topic_id','bookmarks'=>'topic_id','topic_view'=>'topic_id','kb_articles'=>'topic_id') as $table=>$column){
  recovery_fixture($engine);allocation_table($engine,$table,$column,9000);recovery_run();$ids=allocation_ids();reset_check((int)$ids['topic']>9000,'Avoid dangling '.$table.' topic ID');
  $resetServer->pdo->exec('DROP TABLE fixture_'.$table);
 }
 foreach(array('auth_access','forum_prune') as $table){
  recovery_fixture($engine);allocation_table($engine,$table,'forum_id',6000);recovery_run();$ids=allocation_ids();reset_check((int)$ids['forum']>6000,'Avoid dangling '.$table.' forum ID');
  $resetServer->pdo->exec('DROP TABLE fixture_'.$table);
 }
 recovery_fixture($engine);
 $resetServer->pdo->exec("INSERT INTO fixture_categories (cat_id,cat_title,cat_order,cat_main,cat_main_type,cat_desc) VALUES (10,'Hierarchy',10,60000,'f',''); INSERT INTO fixture_forums (forum_id,cat_id,forum_name,main_type) VALUES (10,30000,'Category child','c'),(11,61000,'Forum child','f')");
 recovery_run();$ids=allocation_ids();reset_check((int)$ids['category']===30001&&(int)$ids['forum']===61001,'Hierarchy type distinguishes category and forum namespaces');
 recovery_fixture($engine);
 if($resetNative){$resetServer->pdo->exec('ALTER TABLE fixture_topics AUTO_INCREMENT=12000');}else{$resetServer->pdo->exec("UPDATE information_schema.TABLES SET AUTO_INCREMENT=12000 WHERE TABLE_NAME='fixture_topics'");}
 recovery_run();$ids=allocation_ids();reset_check((int)$ids['topic']>=12000,'Respect the auto-increment high-water mark');
 recovery_fixture($engine);$resetServer->hook=function($sql){if(strpos($sql,'INSERT INTO fixture_topics')!==0){return;}$s=$GLOBALS['resetServer'];$s->hook=null;$s->pdo->exec("UPDATE fixture_sessions SET session_topic=13000");};
 recovery_run();$ids=allocation_ids();reset_check((int)$ids['topic']>13000,'Reference floor is evaluated at the allocation write');
 foreach(array('forum','topic','category') as $kind){
  recovery_fixture($engine);
  if($kind==='forum'){allocation_table($engine,'auth_access','forum_id',65535);}
  elseif($kind==='topic'){allocation_table($engine,'bookmarks','topic_id',16777215);}
  else{$resetServer->pdo->exec("INSERT INTO fixture_categories (cat_id,cat_title,cat_order,cat_main,cat_main_type,cat_desc) VALUES (16777215,'Full',10,0,'c','')");}
  recovery_run($lang['Maintenance_recovery_changed']);reset_check(recovery_count('posts')===0,'Capacity exhaustion does not wrap or publish a parent');
  if($kind==='forum'){$resetServer->pdo->exec('DROP TABLE fixture_auth_access');}elseif($kind==='topic'){$resetServer->pdo->exec('DROP TABLE fixture_bookmarks');}
 }
 recovery_fixture($engine);allocation_table($engine,'bookmarks','topic_id',7,'VARCHAR(20)');recovery_run($lang['Maintenance_recovery_changed']);
 reset_check(recovery_count('posts')===0,'Unexpected reference type fails closed');$resetServer->pdo->exec('DROP TABLE fixture_bookmarks');
 echo $engine." recovery identity allocation passed.\n";
}}finally{restore_error_handler();}
