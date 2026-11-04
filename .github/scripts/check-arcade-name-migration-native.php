<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_ARCADE_NAME_NATIVE')!=='1'){echo "Arcade name migration checks require an explicitly enabled disposable database.\n";return;}
define('IN_PHPBB',true);require dirname(dirname(__DIR__)).'/phpBB2/includes/php_compat.php';require dirname(dirname(__DIR__)).'/update/arcade_name_storage_migration.php';
mysqli_report(MYSQLI_REPORT_OFF);error_reporting(E_ALL);set_error_handler(function($s,$m){if(error_reporting()&$s){throw new RuntimeException($m);}});
$an_checks=$an_cases=0;
function an_check($ok,$message){if(!$ok){throw new RuntimeException($message);}$GLOBALS['an_checks']++;}
function an_sql($sql){$r=mysqli_query($GLOBALS['an_db'],$sql);an_check($r!==false,'Own migration fixture SQL: '.mysqli_error($GLOBALS['an_db']));return $r;}
function an_state(){return array(plus_storage_metadata($GLOBALS['an_db'],'fixture_ina_banned'),plus_storage_signature($GLOBALS['an_db'],'fixture_ina_banned'),plus_arcade_name_digest($GLOBALS['an_db'],'fixture_ina_banned'));}
function an_create($variant,$populated=true){
 global $an_canonical,$an_db;an_sql('DROP TABLE IF EXISTS fixture_ina_banned');an_sql($an_canonical);
 if(!in_array($variant,array('current','wide','text'),true)){an_sql('ALTER TABLE fixture_ina_banned MODIFY game VARCHAR(25) NULL DEFAULT NULL');}
 if($variant==='wide'){an_sql('ALTER TABLE fixture_ina_banned MODIFY game VARCHAR(80) NULL DEFAULT NULL');}
 if($variant==='text'){an_sql('ALTER TABLE fixture_ina_banned MODIFY game TEXT NULL DEFAULT NULL');}
 if($variant==='not-null'){an_sql("ALTER TABLE fixture_ina_banned MODIFY game VARCHAR(25) NOT NULL DEFAULT ''");}
 if(in_array($variant,array('literal','emoji','ambiguous'),true)){
  $value=$variant==='literal'?"O'Reilly \\ Grüße":($variant==='emoji'?'Grüße 😀':'?literal');
  $escaped=mysqli_real_escape_string($an_db,$value);an_sql("ALTER TABLE fixture_ina_banned MODIFY game VARCHAR(25) NULL DEFAULT '$escaped'");
 }
 $column=plus_storage_rows($an_db,"SHOW FULL COLUMNS FROM fixture_ina_banned WHERE Field='game'")[0];
 $default=$column['Default']===null?' DEFAULT NULL':null;
 if($default!==null){$comment=mysqli_real_escape_string($an_db,"Game ' \\ Grüße 😀");an_sql("ALTER TABLE fixture_ina_banned MODIFY game ".$column['Type'].($column['Null']==='YES'?' NULL':' NOT NULL').$default." COMMENT '$comment'");}
 an_sql('ALTER TABLE fixture_ina_banned ADD extra_payload VARBINARY(12) NULL,ADD KEY custom_game(game(20),user_id)');
 if($populated){
  foreach(array('old_game','old_game','ä😀game')as$name){$name=mysqli_real_escape_string($an_db,$name);an_sql("INSERT INTO fixture_ina_banned(user_id,game,extra_payload) VALUES(8,'$name',X'00FF5C27')");}
  if($variant!=='not-null'){an_sql('INSERT INTO fixture_ina_banned(user_id,game,extra_payload) VALUES(8,NULL,NULL)');}
 }
 if($variant==='generated'){an_sql('ALTER TABLE fixture_ina_banned DROP INDEX custom_game,DROP game,ADD game VARCHAR(25) GENERATED ALWAYS AS (username) STORED');}
 if($variant==='integer'){an_sql('ALTER TABLE fixture_ina_banned DROP INDEX custom_game,DROP game,ADD game INT DEFAULT NULL');}
 if($variant==='myisam'){an_sql('ALTER TABLE fixture_ina_banned ENGINE=MyISAM');}
 if($variant==='auto'){an_sql('ALTER TABLE fixture_ina_banned ADD row_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,AUTO_INCREMENT=900');}
}
function an_denied($callback,$label){$before=an_state();$caught=false;try{$callback();}catch(Exception$e){$caught=true;}an_check($caught&&an_state()===$before,$label);$GLOBALS['an_cases']++;}
function an_cli($arguments){
 global $an_config;$extension=DIRECTORY_SEPARATOR==='\\'?'php_mysqli.dll':'mysqli.so';
 $parts=array(PHP_BINARY,'-n','-d','extension_dir='.ini_get('extension_dir'),'-d','extension='.$extension,dirname(dirname(__DIR__)).'/update/update_from_153a.php','--config='.$an_config);
 $command=implode(' ',array_map('escapeshellarg',array_merge($parts,$arguments)));$pipes=array();$process=proc_open($command,array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes,null,null,array('bypass_shell'=>true));an_check(is_resource($process),'Own updater process');fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return array(proc_close($process),$out,$err);
}
$an_db=$control=$peer=null;$created=false;$an_config=null;$an_config_owned=false;$schema='codex_arc_names_'.bin2hex(phpbb_random_bytes(8));
try{
 $port=getenv('PHPBB_ARCADE_GAME_PORT')?:'3306';an_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Own loopback port');$password=getenv('PHPBB_ARCADE_GAME_PASSWORD')?:'';
 $control=mysqli_connect('127.0.0.1','root',$password,'',(int)$port);an_check($control&&mysqli_query($control,'CREATE DATABASE '.$schema.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Own migration schema');$created=true;
 $an_db=mysqli_connect('127.0.0.1','root',$password,$schema,(int)$port);$peer=mysqli_connect('127.0.0.1','root',$password,$schema,(int)$port);an_check($an_db&&$peer&&mysqli_set_charset($an_db,'utf8mb4')&&mysqli_set_charset($peer,'utf8mb4'),'Own migration connections');
 $source=file_get_contents(dirname(dirname(__DIR__)).'/phpBB2/install/schemas/mysql_schema.sql');an_check(preg_match('/CREATE TABLE phpbb_ina_banned \(.*?\) ENGINE=InnoDB[^;]*;/s',$source,$m)===1,'Actual canonical banned-game schema');$an_canonical=str_replace('phpbb_ina_banned','fixture_ina_banned',$m[0]);
 foreach(array('','STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION,NO_BACKSLASH_ESCAPES,ANSI_QUOTES')as$mode){
  an_sql("SET SESSION sql_mode=''");
  foreach(array('legacy','current','wide','text','not-null','literal','emoji','auto')as$variant){foreach(array(false,true)as$populated){if($variant==='emoji'&&!$populated){continue;}
   // Build in the normal mode; execute both planning and DDL in the requested mode.
   an_sql("SET SESSION sql_mode=''");an_create($variant,$populated);an_sql("SET SESSION sql_mode='$mode'");$before=an_state();$settings=plus_storage_rows($an_db,'SELECT @@SESSION.sql_mode AS mode,@@SESSION.lock_wait_timeout AS wait_timeout');
   $plan=plus_arcade_name_plan($an_db,'fixture_',true,true);an_check(an_state()===$before,'Migration plan preserves exact source');$count=plus_arcade_name_apply($an_db,'fixture_',true,true,1);
   an_check($count===(in_array($variant,array('current','wide','text'),true)?0:1)&&plus_arcade_name_plan($an_db,'fixture_',true,true)===array(),'Widen minimum without narrowing larger definitions '.$variant);
   an_check(an_state()[2]===$before[2],'Exact duplicate rows, NULLs and binary payload preserved');an_check(plus_storage_rows($an_db,'SELECT @@SESSION.sql_mode AS mode,@@SESSION.lock_wait_timeout AS wait_timeout')===$settings,'Caller mode and wait restored');
   $after=an_state();an_check(plus_arcade_name_apply($an_db,'fixture_',true,true,1)===0&&an_state()===$after,'Migration rerun is a no-op');$an_cases++;
  }}
 }
 an_sql("SET SESSION sql_mode=''");foreach(array('generated','integer','myisam')as$variant){an_create($variant);an_denied(function()use($an_db){plus_arcade_name_apply($an_db,'fixture_',true,true,1);},'Unsupported source preserved '.$variant);}
 an_create('ambiguous',false);an_denied(function()use($an_db){plus_arcade_name_apply($an_db,'fixture_',true,true,1);},'Ambiguous empty literal never replaced');
 an_create('legacy');foreach(array(array(false,true),array(true,false),array(false,false))as$flags){an_denied(function()use($an_db,$flags){plus_arcade_name_apply($an_db,'fixture_',$flags[0],$flags[1],1);},'Explicit backup and stopped-writer confirmations required');}
 foreach(array(0,31,'1')as$wait){an_denied(function()use($an_db,$wait){plus_arcade_name_apply($an_db,'fixture_',true,true,$wait);},'Bounded integer DDL wait required');}
 an_sql('SET autocommit=0');try{an_denied(function()use($an_db){plus_arcade_name_apply($an_db,'fixture_',true,true,1);},'Do not borrow an autocommit-off connection');}finally{an_sql('ROLLBACK');an_sql('SET autocommit=1');}
 an_check(mysqli_query($peer,'START TRANSACTION')!==false,'Own peer metadata lock');$read=mysqli_query($peer,'SELECT * FROM fixture_ina_banned');an_check($read!==false,'Own peer pins target metadata');mysqli_free_result($read);
 try{an_denied(function()use($an_db){plus_arcade_name_apply($an_db,'fixture_',true,true,1);},'Metadata-lock timeout keeps source intact');}finally{an_check(mysqli_query($peer,'ROLLBACK')!==false,'Release own peer metadata lock');}
 $lock='plus_innodb_'.sha1($schema);an_sql("SELECT GET_LOCK('$lock',0)");an_denied(function()use($an_db){plus_arcade_name_apply($an_db,'fixture_',true,true,1);},'Do not consume caller migration lock');an_check((string)plus_storage_rows($an_db,"SELECT IS_USED_LOCK('$lock') AS owner")[0]['owner']===(string)mysqli_thread_id($an_db),'Caller lock retained');an_sql("DO RELEASE_LOCK('$lock')");
 an_check(mysqli_query($peer,"SELECT GET_LOCK('$lock',0)")!==false,'Own competing migration lock');an_denied(function()use($an_db){plus_arcade_name_apply($an_db,'fixture_',true,true,1);},'Concurrent schema migration refused');mysqli_query($peer,"DO RELEASE_LOCK('$lock')");
 an_sql('START TRANSACTION');an_sql("UPDATE fixture_ina_banned SET username='Caller only'");$caught=false;try{plus_arcade_name_apply($an_db,'fixture_',true,true,1);}catch(Exception$e){$caught=true;}an_check($caught&&plus_storage_rows($peer,"SELECT username FROM fixture_ina_banned WHERE username='Caller only'")===array(),'Active caller transaction never committed by DDL');an_sql('ROLLBACK');$an_cases++;
 an_create('legacy');$an_config=sys_get_temp_dir().'/codex_arc_name_'.bin2hex(phpbb_random_bytes(8)).'.php';$handle=fopen($an_config,'xb');an_check(is_resource($handle),'Exclusive own updater configuration');$an_config_owned=true;$config="<?php\n";foreach(array('dbms'=>'mysqli','dbhost'=>'127.0.0.1:'.$port,'dbname'=>$schema,'dbuser'=>'root','dbpasswd'=>$password,'table_prefix'=>'fixture_')as$key=>$value){$config.='$'.$key.'='.var_export($value,true).";\n";}try{an_check(fwrite($handle,$config)===strlen($config),'Own isolated updater configuration');}finally{fclose($handle);}
 $before=an_state();$r=an_cli(array('--arcade-name-storage-only'));an_check($r[0]===0&&strpos($r[1],'Dry run only.')!==false&&an_state()===$before,'Actual targeted updater dry run '.$r[2]);$an_cases++;
 $r=an_cli(array('--arcade-name-storage-only','--profile-text-storage-only'));an_check($r[0]===2&&an_state()===$before,'Multiple targeted modes rejected before writes');$an_cases++;
 $r=an_cli(array('--arcade-name-storage-only','--apply','--backup-confirmed','--maintenance-confirmed'));an_check($r[0]===0&&strpos($r[1],'migration complete: 1 tables.')!==false&&plus_arcade_name_plan($an_db,'fixture_',true,true)===array()&&an_state()[2]===$before[2],'Actual targeted updater applies preserving widening '.$r[2]);$an_cases++;
 $after=an_state();$r=an_cli(array('--arcade-name-storage-only','--apply','--backup-confirmed','--maintenance-confirmed'));an_check($r[0]===0&&strpos($r[1],'migration complete: 0 tables.')!==false&&an_state()===$after,'Actual targeted updater rerun unchanged');$an_cases++;
 echo 'Native Arcade name migration: '.$an_cases.' cases, '.$an_checks." assertions PASS; preserving widening, exact defaults, SQL modes, owned DDL and actual CLI.\n";
}finally{if($an_db){mysqli_close($an_db);}if($peer){mysqli_close($peer);}if($created){an_check(preg_match('/^codex_arc_names_[a-f0-9]{16}$/D',$schema)&&mysqli_query($control,'DROP DATABASE '.$schema),'Drop only own migration schema');}if($control){mysqli_close($control);}if($an_config_owned&&is_file($an_config)){an_check(realpath(dirname($an_config))===realpath(sys_get_temp_dir())&&preg_match('/^codex_arc_name_[a-f0-9]{16}\.php$/D',basename($an_config))&&unlink($an_config),'Remove only own updater config');}}
