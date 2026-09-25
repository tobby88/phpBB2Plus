<?php
if (PHP_SAPI !== 'cli' || getenv('PHPBB_STYLE_IDS_NATIVE') !== '1') { echo "Style ID migration checks require an explicitly enabled disposable database.\n"; return; }
mysqli_report(MYSQLI_REPORT_OFF);
function sid_check($ok,$message){if(!$ok){throw new RuntimeException($message);} $GLOBALS['checks']=isset($GLOBALS['checks'])?$GLOBALS['checks']+1:1;}
function sid_refuses($call,$message){$refused=false;try{$call();}catch(Exception $e){$refused=true;}sid_check($refused,$message);}
$root=dirname(dirname(__DIR__));$schema=file_get_contents($root.'/phpBB2/install/schemas/mysql_schema.sql');
$port=getenv('PHPBB_STYLE_IDS_PORT')?:'3306';$password=getenv('PHPBB_STYLE_IDS_PASSWORD')?:'';
sid_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Loopback port');
$db=mysqli_connect('127.0.0.1','root',$password,'',(int)$port);sid_check($db,'Own loopback connection');
$database='codex_style_ids_'.bin2hex(function_exists('random_bytes')?random_bytes(8):openssl_random_pseudo_bytes(8));
function sid_sql($sql){$result=mysqli_query($GLOBALS['db'],$sql);sid_check($result,'Fixture SQL failed: '.mysqli_error($GLOBALS['db']));return $result;}
function sid_rows($sql){$r=sid_sql($sql);$rows=array();while($row=mysqli_fetch_assoc($r)){$rows[]=$row;}mysqli_free_result($r);return $rows;}
function sid_cli($config,$arguments,$expected){
 $command=escapeshellarg(PHP_BINARY);
 if(DIRECTORY_SEPARATOR==='\\'){$command.=' -n -d '.escapeshellarg('extension_dir='.ini_get('extension_dir')).' -d extension=php_mysqli.dll';}
 else{$ini=php_ini_loaded_file();if($ini!==false){$command.=' -c '.escapeshellarg($ini);}}
 // Linux CI loads mysqlnd and, on older PHP, JSON through separate ini files.
 // Keep that configured module stack instead of loading mysqli alone with -n.
 $command.=' '.escapeshellarg($GLOBALS['root'].'/update/update_from_153a.php').' '.escapeshellarg('--config='.$config).' --style-ids-only '.$arguments.' 2>&1';
 $output=array();$code=0;exec($command,$output,$code);sid_check($code===$expected,'Actual CLI exit '.$expected.': '.implode("\n",$output));return implode("\n",$output);
}
sid_sql('CREATE DATABASE '.$database.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');mysqli_select_db($db,$database);mysqli_set_charset($db,'utf8mb4');
try{
 sid_sql("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
 sid_check(preg_match('/^\s*user_style\s+([^,]+),/m',$schema,$m)===1,'Actual user style definition');
 sid_sql('CREATE TABLE fixture_users (user_id INT PRIMARY KEY, username VARCHAR(30), user_style '.$m[1].") ENGINE=InnoDB ROW_FORMAT=DYNAMIC COMMENT='preserve table'");
 foreach(array('phpbb_themes'=>'fixture_themes','phpbb_themes_name'=>'fixture_themes_name') as $source=>$target){sid_check(preg_match('/CREATE TABLE '.$source.'\s*\([\s\S]*?;/',$schema,$m)===1,'Canonical theme table');sid_sql(str_replace($source,$target,$m[0]));}
 sid_sql("INSERT INTO fixture_users VALUES (1,'Grüße 😀',127),(2,'Default',NULL)");
 if(getenv('PHPBB_STYLE_IDS_PROBE')==='1'){
  sid_sql('ALTER TABLE fixture_users MODIFY user_style TINYINT NULL');
  sid_sql('ALTER TABLE fixture_themes_name MODIFY themes_id SMALLINT UNSIGNED NOT NULL DEFAULT 0');
  sid_check(mysqli_query($db,'UPDATE fixture_users SET user_style=128 WHERE user_id=1')===false,'Old tinyint cannot hold existing theme ID128');
  sid_check(mysqli_query($db,'INSERT INTO fixture_themes_name (themes_id) VALUES (65536)')===false,'Old label ID cannot hold theme ID65536');
  echo "Reproduced: style ID128 cannot be assigned to users; ID65536 cannot receive labels.\n";
 }else{
  require $root.'/update/style_id_migration.php';
  sid_check(plus_style_ids_plan($db,'fixture_')===array(),'Fresh schema already supports all IDs');
  foreach(array(128,65536,16777215) as $id){sid_sql('UPDATE fixture_users SET user_style='.$id.' WHERE user_id=1');sid_sql('INSERT INTO fixture_themes_name (themes_id) VALUES ('.$id.')');}
  sid_check(mysqli_query($db,'UPDATE fixture_users SET user_style=16777216 WHERE user_id=1')===false,'Fresh schema rejects overflow');
  sid_sql('DELETE FROM fixture_themes_name');sid_sql('UPDATE fixture_users SET user_style=127 WHERE user_id=1');
  sid_sql("ALTER TABLE fixture_users MODIFY user_style TINYINT NULL DEFAULT 5 COMMENT 'Grüße style', ADD KEY style_lookup (user_style)");
  sid_sql("ALTER TABLE fixture_themes_name MODIFY themes_id SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Label key'");
  sid_sql("INSERT INTO fixture_themes_name (themes_id,tr_class1_name) VALUES (127,'Über Größe 😀'),(65535,'Maximum')");
  $beforeUsers=sid_rows('SELECT * FROM fixture_users ORDER BY user_id');$beforeLabels=sid_rows('SELECT * FROM fixture_themes_name ORDER BY themes_id');
  $plan=plus_style_ids_plan($db,'fixture_');sid_check(count($plan)===2,'Two old columns need migration');
  sid_check(sid_rows('SELECT * FROM fixture_users ORDER BY user_id')===$beforeUsers,'Planner does not change users');
  sid_refuses(function()use($db){plus_style_ids_apply($db,'fixture_',false,true);},'Backup confirmation required');
  sid_refuses(function()use($db){plus_style_ids_apply($db,'fixture_',true,false);},'Stopped writers confirmation required');
  sid_refuses(function()use($db){plus_style_ids_plan($db,'bad`prefix');},'Unsafe prefix rejected');
  $other=mysqli_connect('127.0.0.1','root',$password,$database,(int)$port);
  $lock='plus_innodb_'.sha1($database);mysqli_query($other,"DO GET_LOCK('".$lock."',0)");
  sid_refuses(function()use($db){plus_style_ids_apply($db,'fixture_',true,true);},'Concurrent migration refused');mysqli_close($other);
  $session=sid_rows('SELECT @@SESSION.sql_mode AS mode,@@SESSION.lock_wait_timeout AS timeout');
  sid_check(plus_style_ids_apply($db,'fixture_',true,true)===2,'Legacy migration applied');
  sid_check($session===sid_rows('SELECT @@SESSION.sql_mode AS mode,@@SESSION.lock_wait_timeout AS timeout'),'Session options restored');
  sid_check(sid_rows('SELECT * FROM fixture_users ORDER BY user_id')===$beforeUsers,'User data and NULL preference preserved');
  sid_check(sid_rows('SELECT * FROM fixture_themes_name ORDER BY themes_id')===$beforeLabels,'All labels preserved');
  sid_check(plus_style_ids_apply($db,'fixture_',true,true)===0,'Repeated apply is a no-op');
  sid_check(sid_rows("SELECT TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='fixture_users'")[0]['TABLE_COMMENT']==='preserve table','Table comment preserved');
  $definition=sid_rows("SHOW FULL COLUMNS FROM fixture_users WHERE Field='user_style'");
  sid_check($definition[0]['Comment']==='Grüße style'&&(string)$definition[0]['Default']==='5','Comment and valid custom default preserved');
  sid_check(count(sid_rows("SHOW INDEX FROM fixture_users WHERE Key_name='style_lookup'"))===1,'Secondary index preserved');
  foreach(array(128,65536,16777215) as $id){sid_sql('UPDATE fixture_users SET user_style='.$id.' WHERE user_id=1');sid_sql('INSERT INTO fixture_themes_name (themes_id) VALUES ('.$id.')');}
  // A retry after one already completed DDL must only process the remaining column.
  sid_sql('UPDATE fixture_users SET user_style=127 WHERE user_id=1');
  sid_sql('UPDATE fixture_users SET user_style=0 WHERE user_id=2');
  sid_sql('ALTER TABLE fixture_users MODIFY user_style TINYINT NOT NULL DEFAULT 5');
  sid_check(plus_style_ids_apply($db,'fixture_',true,true)===1,'Partial migration resumes remaining column');
  sid_sql('ALTER TABLE fixture_users MODIFY user_style INT NULL DEFAULT 5');sid_sql('UPDATE fixture_users SET user_style=-1 WHERE user_id=1');
  sid_refuses(function()use($db){plus_style_ids_apply($db,'fixture_',true,true);},'Invalid negative reference refused');
  sid_check(sid_rows('SELECT user_style FROM fixture_users WHERE user_id=1')[0]['user_style']==='-1','Invalid reference not silently rewritten');
  sid_sql('UPDATE fixture_users SET user_style=16777216 WHERE user_id=1');
  sid_refuses(function()use($db){plus_style_ids_apply($db,'fixture_',true,true);},'Oversized reference refused');
  sid_sql('UPDATE fixture_users SET user_style=127 WHERE user_id=1');
  sid_sql('ALTER TABLE fixture_themes_name MODIFY themes_id INT NOT NULL DEFAULT 0');sid_sql('INSERT INTO fixture_themes_name (themes_id) VALUES (-1)');
  sid_refuses(function()use($db){plus_style_ids_apply($db,'fixture_',true,true);},'Invalid label key refused before any DDL');
  sid_check(stripos(sid_rows("SHOW COLUMNS FROM fixture_users WHERE Field='user_style'")[0]['Type'],'mediumint')===false,'Preflight validates second table before changing first');
  sid_sql('DELETE FROM fixture_themes_name WHERE themes_id=-1');sid_check(plus_style_ids_apply($db,'fixture_',true,true)===2,'Corrected fixture can retry');
  sid_sql("ALTER TABLE fixture_users MODIFY user_style VARCHAR(20) NULL");
  sid_refuses(function()use($db){plus_style_ids_plan($db,'fixture_');},'Noninteger custom column refused');
  sid_sql('ALTER TABLE fixture_users MODIFY user_style MEDIUMINT UNSIGNED NULL');
  // Comments must survive both SQL quoting modes, including apostrophes and
  // literal backslashes. No table contents may change during schema rebuilds.
  foreach(array('STRICT_ALL_TABLES','STRICT_ALL_TABLES,NO_BACKSLASH_ESCAPES') as $mode){
   sid_sql("SET SESSION sql_mode='".$mode."'");
   $comment="Größe's \\ labels";$escaped=strpos($mode,'NO_BACKSLASH_ESCAPES')!==false?str_replace("'","''",$comment):mysqli_real_escape_string($db,$comment);
   sid_sql("ALTER TABLE fixture_users MODIFY user_style INT NULL COMMENT '".$escaped."'");
   sid_check(plus_style_ids_apply($db,'fixture_',true,true)===1,'Comment quoting migration');
   sid_check(sid_rows("SHOW FULL COLUMNS FROM fixture_users WHERE Field='user_style'")[0]['Comment']===$comment,'Comment bytes preserved in both modes');
  }
  sid_sql("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
  sid_sql('ALTER TABLE fixture_users MODIFY user_style INT NULL DEFAULT 16777216');
  sid_refuses(function()use($db){plus_style_ids_plan($db,'fixture_');},'Oversized default refused');
  sid_sql('ALTER TABLE fixture_users MODIFY user_style INT UNSIGNED ZEROFILL NULL');
  sid_refuses(function()use($db){plus_style_ids_plan($db,'fixture_');},'Custom display semantics not silently discarded');
  sid_sql('ALTER TABLE fixture_users MODIFY user_style TINYINT NULL');
  $config=tempnam(sys_get_temp_dir(),'style-id-fixture-');sid_check($config!==false,'Own CLI config');
  try{
   $settings=array('dbhost'=>'127.0.0.1:'.$port,'dbuser'=>'root','dbpasswd'=>$password,'dbname'=>$database,'table_prefix'=>'fixture_','dbms'=>'mysqli');$source="<?php\n";
   foreach($settings as $key=>$value){$source.='$'.$key.'='.var_export($value,true).";\n";}
   sid_check(file_put_contents($config,$source)===strlen($source),'Write own fixture config');
   $before=plus_storage_signature($db,'fixture_users');$contents=plus_style_ids_digest($db,'fixture_users','user_id');
   sid_check(strpos(sid_cli($config,'',0),'Dry run only')!==false,'Actual CLI dry run');
   sid_check(plus_storage_signature($db,'fixture_users')===$before,'CLI plan is read-only');
   sid_cli($config,'--apply',2);sid_cli($config,'--apply --backup-confirmed',2);sid_cli($config,'--storage-only',2);
   sid_check(plus_storage_signature($db,'fixture_users')===$before,'CLI refusals leave schema unchanged');
   sid_check(strpos(sid_cli($config,'--apply --backup-confirmed --maintenance-confirmed',0),'complete: 1 columns')!==false,'Actual targeted apply only touches remaining column');
   sid_check(plus_style_ids_digest($db,'fixture_users','user_id')===$contents,'CLI keeps all user data');
   sid_check(strpos(sid_cli($config,'--apply --backup-confirmed --maintenance-confirmed',0),'complete: 0 columns')!==false,'CLI retry no-op');
  }finally{if(is_file($config)){unlink($config);}}
  $updater=file_get_contents($root.'/update/update_from_153a.php');
  $applyPosition=strpos($updater,'// Widen references before');$loopPosition=strpos($updater,'foreach ($operations as $sql)');
  sid_check($applyPosition!==false&&$applyPosition<$loopPosition,'Full updater applies migration before style assignment operations');
  echo 'Style ID migration checks passed: '.$GLOBALS['checks']." assertions/SQL checks\n";
 }
}finally{sid_sql('DROP DATABASE '.$database);mysqli_close($db);}
