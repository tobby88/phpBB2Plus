<?php
require dirname(dirname(__DIR__)) . '/update/innodb_migration.php';
function storage_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
$root=dirname(dirname(__DIR__));$schema=file_get_contents($root.'/phpBB2/install/schemas/mysql_schema.sql');
$tables=plus_storage_tables($schema,'fixture_');
storage_check(count($tables)>=118&&in_array('fixture_ctracker_backup',$tables,true),'Known bundled tables');
storage_check(strpos($schema,'ENGINE=MyISAM')===false,'Fresh schema uses InnoDB');
storage_check(!in_array('fixture_unrelated',$tables,true),'No prefix wildcard');
$index_rows=array(array('Key_name'=>'z','Seq_in_index'=>'2','Column_name'=>'second'),array('Key_name'=>'PRIMARY','Seq_in_index'=>'1','Column_name'=>'id'),array('Key_name'=>'z','Seq_in_index'=>'1','Column_name'=>'first'));
storage_check(plus_storage_sort_indexes($index_rows)===plus_storage_sort_indexes(array_reverse($index_rows)),'Physical index row order is irrelevant; component sequence is retained');
storage_check(!plus_storage_counter_at_least('18446744073709551613','18446744073709551614')&&plus_storage_counter_at_least('18446744073709551614','18446744073709551614'),'No float rounding of unsigned 64-bit counters');
$note=array('Level'=>'Note','Code'=>'1031','Message'=>"Storage engine InnoDB of the table `fixture`.`table` doesn't have this option");
storage_check(!plus_storage_fatal_warnings(array($note)),'Known informational legacy option note');
foreach(array(array('Level'=>'Warning'),array('Code'=>'1265'),array('Message'=>'Other problem')) as $change){storage_check(plus_storage_fatal_warnings(array(array_merge($note,$change))),'All actual warnings and unrelated notes still stop');}
foreach(array('bad`name','',str_repeat('a',65)) as $bad){$caught=false;try{plus_storage_identifier($bad);}catch(Exception $e){$caught=true;}storage_check($caught,'Unsafe identifier rejected');}
$updater=file_get_contents($root.'/update/update_from_153a.php');
storage_check(substr_count($updater,'plus_storage_apply(')===2&&strpos($updater,'--storage-only')!==false,'Both updater paths use shared migrator');
echo "InnoDB schema and updater wiring passed\n";
if(getenv('PHPBB_STORAGE_NATIVE')!=='1'){exit(0);}
mysqli_report(MYSQLI_REPORT_OFF);
$port=getenv('PHPBB_STORAGE_PORT')?(int)getenv('PHPBB_STORAGE_PORT'):3306;
$password=getenv('PHPBB_STORAGE_PASSWORD')?:'';
$db=mysqli_connect('127.0.0.1','root',$password,'',$port);storage_check((bool)$db,'Native connection');
$name='codex_storage_'.bin2hex(function_exists('random_bytes')?random_bytes(8):openssl_random_pseudo_bytes(8));
plus_storage_query($db,'CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');mysqli_select_db($db,$name);mysqli_set_charset($db,'utf8mb4');
$config_file=null;
try {
 plus_storage_query($db,"SET SESSION sql_mode='NO_AUTO_VALUE_ON_ZERO'");
 foreach(array('users','posts','sessions') as $i=>$suffix){
  plus_storage_query($db,'CREATE TABLE fixture_'.$suffix.' (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, body VARCHAR(255) NULL, UNIQUE KEY body (body)) ENGINE='.($i===2?'MEMORY':'MyISAM').' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
  plus_storage_query($db,"INSERT INTO fixture_".$suffix." VALUES (0,'Grüße 😀'),(4294967298,NULL),(4294967300,'C@ro')");
  plus_storage_query($db,'ALTER TABLE fixture_'.$suffix.' AUTO_INCREMENT=5000000000');
 }
 plus_storage_query($db,'CREATE TABLE fixture_unrelated (id INT) ENGINE=MyISAM');
 $selected=array('fixture_users','fixture_posts','fixture_sessions');$before=array();
 foreach($selected as $t){$before[$t]=plus_storage_rows($db,'SELECT * FROM `'.$t.'` ORDER BY id');}
 storage_check(count(plus_storage_plan($db,$selected))===3,'Dry run sees all legacy engines');
 foreach(array(array(false,true),array(true,false)) as $flags){$caught=false;try{plus_storage_apply($db,$selected,$flags[0],$flags[1]);}catch(Exception $e){$caught=true;}storage_check($caught&&count(plus_storage_plan($db,$selected))===3,'Missing confirmation cannot mutate');}
 $interrupted=false;try{plus_storage_apply($db,$selected,true,true,function(){throw new RuntimeException('Injected interruption');});}catch(Exception $e){$interrupted=true;}
 storage_check($interrupted&&count(plus_storage_plan($db,$selected))===2,'Partial DDL preserved honestly');
 storage_check(plus_storage_apply($db,$selected,true,true)===2,'Retry resumes remaining tables');
 storage_check(plus_storage_apply($db,$selected,true,true)===0,'Second completed run is a no-op');
 foreach($selected as $t){
  storage_check($before[$t]===plus_storage_rows($db,'SELECT * FROM `'.$t.'` ORDER BY id'),'All data bytes/NULL/zero/wide IDs preserved');
  $meta=plus_storage_metadata($db,$t);storage_check($meta['AUTO_INCREMENT']==='5000000000','Deleted historical ID floor preserved');
  storage_check(mysqli_query($db,"INSERT INTO `".$t."` (body) VALUES ('C@ro')")===false,'Unique key still enforced');
 }
 storage_check(plus_storage_metadata($db,'fixture_unrelated')['ENGINE']==='MyISAM','Foreign table untouched');
 plus_storage_query($db,'CREATE TABLE fixture_disabled (id INT NOT NULL, body VARCHAR(64), KEY id_key(id), KEY body_key(body)) ENGINE=MyISAM');
 plus_storage_query($db,"INSERT INTO fixture_disabled VALUES (1,'kept'),(2,'also kept')");
 plus_storage_query($db,'ALTER TABLE fixture_disabled DISABLE KEYS');
 $disabled_indexes=plus_storage_rows($db,'SHOW INDEX FROM fixture_disabled');
 storage_check($disabled_indexes[0]['Comment']==='disabled','Actual legacy disabled index fixture');
 plus_storage_apply($db,array('fixture_disabled'),true,true);
 foreach(plus_storage_rows($db,'SHOW INDEX FROM fixture_disabled') as $index){storage_check($index['Comment']==='','InnoDB indexes enabled after rebuild');}
 storage_check(plus_storage_rows($db,'SELECT * FROM fixture_disabled ORDER BY id')===array(array('id'=>'1','body'=>'kept'),array('id'=>'2','body'=>'also kept')),'Disabled legacy indexes rebuilt without data loss');
 $mode=plus_storage_rows($db,'SELECT @@SESSION.sql_mode AS mode');storage_check($mode[0]['mode']==='NO_AUTO_VALUE_ON_ZERO','SQL mode restored');
 plus_storage_query($db,'CREATE TABLE fixture_grouped (bucket INT NOT NULL,id INT NOT NULL AUTO_INCREMENT, PRIMARY KEY(bucket,id)) ENGINE=MyISAM');
 $caught=false;try{plus_storage_plan($db,array('fixture_unrelated','fixture_grouped'));}catch(Exception $e){$caught=true;}
 storage_check($caught&&plus_storage_metadata($db,'fixture_unrelated')['ENGINE']==='MyISAM','Grouped auto increment rejected before changes');
 $other=mysqli_connect('127.0.0.1','root',$password,$name,$port);
 plus_storage_query($other,"DO GET_LOCK('plus_innodb_".sha1($name)."',0)");
 $caught=false;try{plus_storage_apply($db,$selected,true,true);}catch(Exception $e){$caught=true;}
 storage_check($caught,'Concurrent migration rejected');mysqli_close($other);
 foreach(array_merge($selected,array('fixture_unrelated','fixture_grouped','fixture_disabled')) as $t){plus_storage_query($db,'DROP TABLE `'.$t.'`');}
 preg_match_all('/CREATE TABLE\s+.*?;(?=\s*(?:#|CREATE|$))/s',$schema,$matches);
 foreach($matches[0] as $sql){plus_storage_query($db,$sql);}
 storage_check(count($matches[0])===count(plus_storage_tables($schema,'phpbb_'))-2,'Entire canonical schema executed');
 storage_check(!plus_storage_plan($db,plus_storage_tables($schema,'phpbb_')),'Fresh installation all InnoDB');
 // Exercise the actual CLI updater against a fully isolated fresh schema,
 // then a mixed-engine already-upgraded schema. Never read a real config.
 $config_file=tempnam(sys_get_temp_dir(),'plus-storage-config-');chmod($config_file,0600);
 $config="<?php\n";
 foreach(array('dbhost'=>'127.0.0.1:'.$port,'dbuser'=>'root','dbpasswd'=>$password,'dbname'=>$name,'table_prefix'=>'phpbb_','dbms'=>'mysqli') as $key=>$value){$config.='$'.$key.'='.var_export($value,true).";\n";}
 file_put_contents($config_file,$config);
 foreach(array('dry','missing-maintenance','full','storage','retry') as $case){
  if($case==='storage'){plus_storage_query($db,'ALTER TABLE phpbb_posts ENGINE=MyISAM');}
  $command=escapeshellarg(PHP_BINARY);
  if(DIRECTORY_SEPARATOR==='\\'){$command.=' -n -d '.escapeshellarg('extension_dir='.ini_get('extension_dir')).' -d extension=php_mysqli.dll';}
  $command.=' '.escapeshellarg($root.'/update/update_from_153a.php').' '.escapeshellarg('--config='.$config_file);
  if(in_array($case,array('storage','retry'),true)){$command.=' --storage-only';}
  if($case!=='dry'){$command.=' --apply --backup-confirmed';}
  if($case!=='missing-maintenance'){$command.=' --maintenance-confirmed';}
  $pipes=array();$p=proc_open($command,array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes,null,null,array('bypass_shell'=>true));
  storage_check(is_resource($p),'Updater subprocess');fclose($pipes[0]);$out=stream_get_contents($pipes[1]);fclose($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);$code=proc_close($p);
  storage_check($code===($case==='missing-maintenance'?2:0),'Actual updater '.$case.' failed: '.$err);
 }
 storage_check(!plus_storage_plan($db,plus_storage_tables($schema,'phpbb_')),'Actual updater left all tables InnoDB');
 echo "Native migration, strict data preservation, unique constraints, interruption/resume, locks, engine mix and fresh schema passed\n";
} finally {if($config_file!==null&&is_file($config_file)){unlink($config_file);}plus_storage_query($db,'DROP DATABASE `'.$name.'`');mysqli_close($db);}
