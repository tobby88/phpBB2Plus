<?php
// Actual ACP export followed by a native import into a different owned schema.
if (PHP_SAPI !== 'cli' || getenv('PHPBB_DB_BACKUP_NATIVE') !== '1') { echo "Backup roundtrip checks require an explicitly enabled disposable database.\n"; return; }
$mode=isset($argv[1])?$argv[1]:'plain';if(!in_array($mode,array('plain','gzip','data','structure','normal-source','revoked','read-failure','concurrent-write','concurrent-gzip','gzip-read-failure','delegated','wrong-grant','revoked-grant','logout','inactive','foreign-session','bad-sid','legacy-table','legacy-structure','legacy-authority','snapshot-failure','commit-failure','lost-ack','lost-authority','authority-concurrency','ddl-concurrency','request-transaction','factory-reuse'),true)){throw new RuntimeException('Unknown backup case');}
$scripts=__DIR__;putenv('PHPBB_STYLE_DATA_NATIVE=1');putenv('PHPBB_STYLE_DATA_PORT='.(getenv('PHPBB_DB_BACKUP_PORT')?:'3306'));putenv('PHPBB_STYLE_DATA_PASSWORD='.(getenv('PHPBB_DB_BACKUP_PASSWORD')?:''));
$fixture_source=file_get_contents($scripts.'/check-style-data-native.php');$cut=strpos($fixture_source," if (getenv('PHPBB_STYLE_DATA_PROBE')");
if($cut===false){throw new RuntimeException('Fixture boundary missing');}
$head=str_replace('__DIR__',var_export($scripts,true),substr($fixture_source,5,$cut-5));$head=str_replace('codex_style_data_','codex_db_backup_',$head);
$body= <<<'PHP'
 function backup_connections_closed() {
  // COM_QUIT/KILL acknowledgement need not mean the server has removed its
  // process-list row yet, especially with legacy client libraries. Assert
  // actual server-side release with a bounded wait, not a single racy query.
  $deadline=microtime(true)+2;
  do {$rows=phpbb_acl_rows($GLOBALS['peer'],'SELECT ID FROM information_schema.PROCESSLIST WHERE DB=DATABASE()');if(count($rows)===2){return true;}usleep(10000);}while(microtime(true)<$deadline);
  return false;
 }
 $delegated=in_array($mode,array('delegated','wrong-grant','revoked-grant'),true);
 sd_reset($delegated?'delegated':'root');$table_prefix='fixture_';$dbname=$fixture;$restore=null;$restore_name=$fixture.'_restore';$capture=false;
 if($delegated){$route='admin_db_utilities.php?perform='.($mode==='wrong-grant'?'restore':'backup');$grant=array_search($route,jr_admin_authorization_routes(),true);sd_check($grant!==false,'Actual registered backup/restore module');sd_sql("UPDATE fixture_jr SET user_jr_admin='".$grant."'");}
 sd_sql("CREATE TABLE fixture_roundtrip (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,content TEXT NOT NULL,opaque BLOB NOT NULL,optional_value VARCHAR(20) NULL) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
 $text="Größe 😀 'quotes' back\\slash\n\r\0\x1a";$opaque="\xff\x80\0'\\\r\n\x1a";
 sd_sql("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_AUTO_VALUE_ON_ZERO,NO_BACKSLASH_ESCAPES'");
 sd_sql("INSERT INTO fixture_roundtrip VALUES (0,CONVERT(0x".bin2hex($text)." USING utf8mb4),0x".bin2hex($opaque).",NULL)");
 sd_sql("INSERT INTO fixture_roundtrip VALUES (12,'',0x00,'')");
 sd_sql('ALTER TABLE fixture_roundtrip AUTO_INCREMENT=500');
 sd_check($db->sql_query("SET SESSION sql_mode='STRICT_ALL_TABLES".($mode==='normal-source'?'':',NO_BACKSLASH_ESCAPES')."'"),'Source escape mode');
 $expected=phpbb_acl_rows($peer,'SELECT * FROM fixture_roundtrip ORDER BY id');
 $source_session_sql='SELECT CONNECTION_ID() AS connection_id,@@SESSION.sql_mode AS sql_mode,@@SESSION.foreign_key_checks AS foreign_keys,@@SESSION.character_set_client AS client_charset,@@SESSION.character_set_results AS results_charset,@@SESSION.character_set_connection AS connection_charset,@@SESSION.collation_connection AS connection_collation,@@SESSION.in_transaction AS in_transaction';
 if($mode==='request-transaction'){sd_check($db->sql_query('START TRANSACTION'),'Original request owns a transaction');sd_check($db->sql_query("UPDATE fixture_roundtrip SET content='uncommitted caller value' WHERE id=0"),'Uncommitted caller data');}
 if($mode==='factory-reuse'){class BackupReuseDatabase extends StyleDataDatabase {function sql_dedicated_connection(){return $this;}}$db=new BackupReuseDatabase($db->inner);}
 $source_session_before=phpbb_acl_rows($db,$source_session_sql);
 $concurrent=in_array($mode,array('concurrent-write','concurrent-gzip'),true);$read_failure=in_array($mode,array('read-failure','gzip-read-failure'),true);
 if($concurrent||$read_failure){
  sd_sql('CREATE TABLE fixture_roundtrip_peer LIKE fixture_roundtrip');sd_sql('INSERT INTO fixture_roundtrip_peer SELECT * FROM fixture_roundtrip');
 }
 $concurrent_reached=false;
 if($concurrent){$sd_hook=function($sql)use(&$concurrent_reached){if($sql!=='SELECT * FROM `fixture_roundtrip_peer`'){return;}$GLOBALS['sd_hook']=null;sd_sql('START TRANSACTION');sd_sql("UPDATE fixture_roundtrip SET content='after parallel write' WHERE id=0");sd_sql("UPDATE fixture_roundtrip_peer SET content='after parallel write' WHERE id=0");sd_sql('COMMIT');$concurrent_reached=true;};}
 if($read_failure){$sd_failure='SELECT * FROM `fixture_roundtrip_peer`';}
 if($mode==='revoked'){sd_sql('UPDATE fixture_users SET user_level=0');}
 if($mode==='inactive'){sd_sql('UPDATE fixture_users SET user_active=0');}
 if($mode==='logout'){sd_sql('UPDATE fixture_sessions SET session_logged_in=0');}
 if($mode==='foreign-session'){sd_sql('UPDATE fixture_sessions SET session_user_id=99');}
 if($mode==='revoked-grant'){sd_sql('DELETE FROM fixture_jr');}
 if($mode==='legacy-table'||$mode==='legacy-structure'){sd_sql('ALTER TABLE fixture_roundtrip ENGINE=MyISAM');}
 if($mode==='legacy-authority'){sd_sql('ALTER TABLE fixture_jr ENGINE=MyISAM');}
 if($mode==='snapshot-failure'){$sd_failure='START TRANSACTION WITH CONSISTENT SNAPSHOT';}
 if($mode==='commit-failure'){$sd_failure='COMMIT';}if($mode==='lost-ack'){$sd_failure='lost-ack';}
 $serialized=false;$lost_authority=false;$authority_thread=null;
 if($mode==='authority-concurrency'){$sd_hook=function($sql)use(&$serialized){if($sql!=='SELECT * FROM `fixture_roundtrip`'){return;}$GLOBALS['sd_hook']=null;sd_check(!$GLOBALS['peer']->sql_query('UPDATE fixture_users SET user_level=0'),'Current authority remains held during capture');$error=$GLOBALS['peer']->sql_error();sd_check((int)$error['code']===1205,'Only native row-lock timeout serializes revocation');$serialized=true;};}
 if($mode==='ddl-concurrency'){sd_sql('SET SESSION lock_wait_timeout=1');$sd_hook=function($sql)use(&$serialized){if($sql!=='SELECT * FROM `fixture_roundtrip`'){return;}$GLOBALS['sd_hook']=null;sd_check(!$GLOBALS['peer']->sql_query('ALTER TABLE fixture_roundtrip ADD COLUMN unexpected INT'),'Selected metadata remains pinned during capture');$error=$GLOBALS['peer']->sql_error();sd_check((int)$error['code']===1205,'Only native metadata-lock timeout serializes DDL');$serialized=true;};}
 if($mode==='lost-authority'){$sd_hook=function($sql,$connection)use(&$authority_thread,&$lost_authority){if(strpos($sql,'SELECT session_id FROM fixture_sessions ')===0&&strpos($sql,'LOCK IN SHARE MODE')!==false&&$authority_thread===null){$authority_thread=mysqli_thread_id($connection->db_connect_id);}if($sql==='COMMIT'&&$authority_thread!==null&&mysqli_thread_id($connection->db_connect_id)!==$authority_thread){$GLOBALS['sd_hook']=null;sd_check($GLOBALS['peer']->sql_query('KILL CONNECTION '.$authority_thread),'Kill only captured fixture authority connection');$lost_authority=true;}};}
 $_GET=$HTTP_GET_VARS=array();$_POST=$HTTP_POST_VARS=array('perform'=>'backup','backupstart'=>'1','backup_type'=>'full','sid'=>'fixture-admin');
 if($mode==='data'||$mode==='structure'||$mode==='legacy-structure'){$_POST['backup_type']=$HTTP_POST_VARS['backup_type']=$mode==='legacy-structure'?'structure':$mode;}
 $gzip=in_array($mode,array('gzip','concurrent-gzip','gzip-read-failure'),true);if($gzip){sd_check(function_exists('gzdecode'),'Gzip support');$_POST['gzipcompress']=$HTTP_POST_VARS['gzipcompress']='1';}
 if($mode==='bad-sid'){$_POST['sid']=$HTTP_POST_VARS['sid']='wrong';}
 $controller=file_get_contents($sd_source.'admin/admin_db_utilities.php');
 // Replace only response termination so the fixture can verify and clean up.
 $controller=str_replace('exit;','throw new StyleDataExit("backup");',$controller,$replacements);sd_check($replacements===1,'One terminal response seam');
 ob_start();$capture=true;
 try{eval(substr(str_replace('__DIR__',var_export($sd_source.'admin',true),$controller),5));throw new RuntimeException('Backup controller did not terminate');}catch(StyleDataExit $e){$outcome=$e->getMessage();}
 $dump=ob_get_clean();$capture=false;
 $denied=in_array($mode,array('revoked','read-failure','gzip-read-failure','wrong-grant','revoked-grant','logout','inactive','foreign-session','bad-sid','legacy-table','legacy-authority','snapshot-failure','commit-failure','lost-ack','lost-authority','factory-reuse'),true);
 if($denied){
  sd_check($outcome!=='backup'&&$dump==='','Denied/failed backup publishes no SQL bytes, even after earlier tables were read');
  $sd_failure='';$sd_hook=null;sd_check(phpbb_acl_rows($peer,'SELECT * FROM fixture_roundtrip ORDER BY id')===$expected,'Denied/failed backup preserves source data');
  if($mode==='lost-authority'){sd_check($lost_authority,'Lost authority fault actually reached after data capture');}
  sd_check(phpbb_acl_rows($db,$source_session_sql)===$source_session_before,'Failed capture neither closes nor changes the original connection');
  sd_check(backup_connections_closed(),'Denied capture releases both dedicated connections');
  echo 'Native backup '.$mode." denied without partial output\n";return;
 }
 sd_check($outcome==='backup','Actual backup completed');
 sd_check(phpbb_acl_rows($db,$source_session_sql)===$source_session_before,'Capture preserves original connection settings and transaction ownership');
 if($mode==='request-transaction'){$caller_rows=phpbb_acl_rows($db,'SELECT content FROM fixture_roundtrip WHERE id=0');sd_check($caller_rows[0]['content']==='uncommitted caller value','Caller transaction remains uncommitted and usable');sd_check($db->sql_query('ROLLBACK'),'Only caller rolls back its transaction');}
 if($gzip){$dump=gzdecode($dump);}
 sd_check(is_string($dump)&&$dump!=='','Complete actual plain/gzip dump');
 sd_check((strpos($dump,'CREATE TABLE `fixture_roundtrip`')!==false)===($mode!=='data'),'Selected structure scope');
 sd_check($control->sql_query('CREATE DATABASE '.$restore_name.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Owned restoration schema');
 $restore=new sql_db($host,'root',$password,$restore_name,false);
 if($mode==='data'){
  foreach(phpbb_acl_rows($peer,'SHOW TABLES')as$table){$name=reset($table);$definition=phpbb_acl_rows($peer,'SHOW CREATE TABLE `'.str_replace('`','``',$name).'`');sd_check($restore->sql_query($definition[0]['Create Table']),'Prepare existing target for data-only backup');}
 }
 sd_check($restore->sql_query("SET NAMES latin1"),'Importer starts with a different connection encoding');
 sd_check($restore->sql_query("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_BACKSLASH_ESCAPES'"),'Importer starts with a different escape mode');
 sd_check($restore->sql_query('SET FOREIGN_KEY_CHECKS='.($mode==='normal-source'?'1':'0')),'Importer original foreign-key state');
 $session_sql='SELECT @@SESSION.sql_mode AS sql_mode,@@SESSION.foreign_key_checks AS foreign_keys,@@SESSION.character_set_client AS client_charset,@@SESSION.character_set_results AS results_charset,@@SESSION.character_set_connection AS connection_charset,@@SESSION.collation_connection AS connection_collation';
 $session_before=phpbb_acl_rows($restore,$session_sql);
 $connection=$restore->db_connect_id;sd_check(mysqli_multi_query($connection,$dump),'Backup SQL parses in the importing session');
 do{$result=mysqli_store_result($connection);if($result){mysqli_free_result($result);}if(!mysqli_more_results($connection)){break;}sd_check(mysqli_next_result($connection),'Every backup statement imports successfully');}while(true);
 sd_check(phpbb_acl_rows($restore,$session_sql)===$session_before,'Import restores every original SQL mode, foreign-key and charset/collation session setting');
 sd_check($restore->sql_query('SET NAMES utf8mb4'),'Read restored UTF-8 values');
 $actual=phpbb_acl_rows($restore,'SELECT * FROM fixture_roundtrip ORDER BY id');$different=array();
 $expected_import=in_array($mode,array('structure','legacy-structure'),true)?array():$expected;
 foreach(array('id','content','opaque','optional_value')as$field){foreach($expected_import as$i=>$row){if(!isset($actual[$i])||$actual[$i][$field]!==$row[$field]){$different[]=$field;break;}}}
 sd_check($actual===$expected_import,'Roundtrip must preserve exact values; mismatched fields: '.implode(',',$different));
 if($concurrent){sd_check($concurrent_reached,'Concurrent transactional writer actually ran between table reads');sd_check(phpbb_acl_rows($restore,'SELECT * FROM fixture_roundtrip_peer ORDER BY id')===$expected,'Related tables share the same snapshot, not different commit points');}
 $storage=phpbb_acl_rows($restore,"SELECT ENGINE,ROW_FORMAT,TABLE_COLLATION,AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='fixture_roundtrip'");
 sd_check(count($storage)===1&&$storage[0]['ENGINE']===($mode==='legacy-structure'?'MyISAM':'InnoDB')&&strtolower($storage[0]['ROW_FORMAT'])==='dynamic'&&$storage[0]['TABLE_COLLATION']==='utf8mb4_unicode_ci'&&(string)$storage[0]['AUTO_INCREMENT']==='500','Actual table format and reserved auto-increment floor preserved');
 $expected_source=$expected;if($concurrent){$expected_source[0]['content']=$expected_source[0][1]='after parallel write';}
 $actual_source=phpbb_acl_rows($peer,'SELECT * FROM fixture_roundtrip ORDER BY id');
 sd_check($actual_source===$expected_source,'Backup never changes source data or overwrites a concurrent writer');
 if($mode==='authority-concurrency'){sd_check($serialized,'Concurrent authority revocation actually attempted');sd_sql('UPDATE fixture_users SET user_level=0');$following=false;try{$next=phpbb_database_backup_capture($db,array('fixture_roundtrip'),false,function()use(&$following){$following=true;});fclose($next);throw new RuntimeException('Following revoked capture allowed');}catch(PhpbbAclException $error){}sd_check(!$following,'Next revoked capture cannot run its builder');}
 if($mode==='ddl-concurrency'){sd_check($serialized,'Concurrent DDL actually attempted');$columns=phpbb_acl_rows($peer,"SHOW COLUMNS FROM fixture_roundtrip LIKE 'unexpected'");sd_check(!$columns,'Rejected concurrent DDL leaves source schema unchanged');}
 sd_check(backup_connections_closed(),'Successful capture releases both dedicated connections before response');
 echo 'Native database backup '.$mode." roundtrip passed\n";
PHP;
$tail= <<<'PHP'
}finally{
 if(isset($capture)&&$capture){ob_end_clean();}
 if(isset($restore)&&$restore!==null){$restore->sql_close();}
 if(isset($restore_name)){$control->sql_query('DROP DATABASE IF EXISTS '.$restore_name);}
 $db->sql_close();$peer->sql_close();$control->sql_query('DROP DATABASE '.$fixture);$control->sql_close();chdir($sd_previous);
 if(is_file($sd_root.'/cache/themes.cache')){unlink($sd_root.'/cache/themes.cache');}
 foreach(array_reverse($sd_files)as$file){if(is_file($file)){unlink($file);}}foreach(array_reverse($sd_dirs)as$dir){rmdir($dir);}restore_error_handler();
}
PHP;
eval($head.$body.$tail);
