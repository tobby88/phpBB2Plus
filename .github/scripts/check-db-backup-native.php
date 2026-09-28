<?php
// Actual ACP export followed by a native import into a different owned schema.
if (PHP_SAPI !== 'cli' || getenv('PHPBB_DB_BACKUP_NATIVE') !== '1') { echo "Backup roundtrip checks require an explicitly enabled disposable database.\n"; return; }
$mode=isset($argv[1])?$argv[1]:'plain';if(!in_array($mode,array('plain','gzip','data','structure','normal-source'),true)){throw new RuntimeException('Unknown backup case');}
$scripts=__DIR__;putenv('PHPBB_STYLE_DATA_NATIVE=1');putenv('PHPBB_STYLE_DATA_PORT='.(getenv('PHPBB_DB_BACKUP_PORT')?:'3306'));putenv('PHPBB_STYLE_DATA_PASSWORD='.(getenv('PHPBB_DB_BACKUP_PASSWORD')?:''));
$fixture_source=file_get_contents($scripts.'/check-style-data-native.php');$cut=strpos($fixture_source," if (getenv('PHPBB_STYLE_DATA_PROBE')");
if($cut===false){throw new RuntimeException('Fixture boundary missing');}
$head=str_replace('__DIR__',var_export($scripts,true),substr($fixture_source,5,$cut-5));$head=str_replace('codex_style_data_','codex_db_backup_',$head);
$body= <<<'PHP'
 sd_reset();$table_prefix='fixture_';$dbname=$fixture;$restore=null;$restore_name=$fixture.'_restore';$capture=false;
 sd_sql("CREATE TABLE fixture_roundtrip (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,content TEXT NOT NULL,opaque BLOB NOT NULL,optional_value VARCHAR(20) NULL) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
 $text="Größe 😀 'quotes' back\\slash\n\r\0\x1a";$opaque="\xff\x80\0'\\\r\n\x1a";
 sd_sql("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_AUTO_VALUE_ON_ZERO,NO_BACKSLASH_ESCAPES'");
 sd_sql("INSERT INTO fixture_roundtrip VALUES (0,CONVERT(0x".bin2hex($text)." USING utf8mb4),0x".bin2hex($opaque).",NULL)");
 sd_sql("INSERT INTO fixture_roundtrip VALUES (12,'',0x00,'')");
 sd_sql('ALTER TABLE fixture_roundtrip AUTO_INCREMENT=500');
 sd_check($db->sql_query("SET SESSION sql_mode='STRICT_ALL_TABLES".($mode==='normal-source'?'':',NO_BACKSLASH_ESCAPES')."'"),'Source escape mode');
 $expected=phpbb_acl_rows($peer,'SELECT * FROM fixture_roundtrip ORDER BY id');
 $_GET=$HTTP_GET_VARS=array();$_POST=$HTTP_POST_VARS=array('perform'=>'backup','backupstart'=>'1','backup_type'=>'full','sid'=>'fixture-admin');
 if($mode==='data'||$mode==='structure'){$_POST['backup_type']=$HTTP_POST_VARS['backup_type']=$mode;}
 if($mode==='gzip'){sd_check(function_exists('gzdecode'),'Gzip support');$_POST['gzipcompress']=$HTTP_POST_VARS['gzipcompress']='1';}
 $controller=file_get_contents($sd_source.'admin/admin_db_utilities.php');
 // Replace only response termination so the fixture can verify and clean up.
 $controller=str_replace('exit;','throw new StyleDataExit("backup");',$controller,$replacements);sd_check($replacements===1,'One terminal response seam');
 ob_start();$capture=true;
 try{eval(substr($controller,5));throw new RuntimeException('Backup controller did not terminate');}catch(StyleDataExit $e){sd_check($e->getMessage()==='backup','Actual backup completed');}
 $dump=ob_get_clean();$capture=false;
 if($mode==='gzip'){$dump=gzdecode($dump);}
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
 $expected_import=$mode==='structure'?array():$expected;
 foreach(array('id','content','opaque','optional_value')as$field){foreach($expected_import as$i=>$row){if(!isset($actual[$i])||$actual[$i][$field]!==$row[$field]){$different[]=$field;break;}}}
 sd_check($actual===$expected_import,'Roundtrip must preserve exact values; mismatched fields: '.implode(',',$different));
 $storage=phpbb_acl_rows($restore,"SELECT ENGINE,ROW_FORMAT,TABLE_COLLATION,AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='fixture_roundtrip'");
 sd_check(count($storage)===1&&$storage[0]['ENGINE']==='InnoDB'&&strtolower($storage[0]['ROW_FORMAT'])==='dynamic'&&$storage[0]['TABLE_COLLATION']==='utf8mb4_unicode_ci'&&(string)$storage[0]['AUTO_INCREMENT']==='500','Native modern table format and reserved auto-increment floor preserved');
 sd_check(phpbb_acl_rows($peer,'SELECT * FROM fixture_roundtrip ORDER BY id')===$expected,'Backup never changes source data');
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
