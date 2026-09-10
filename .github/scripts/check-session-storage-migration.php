<?php
namespace SessionStorageMigrationFixture;
function check($ok,$message){if(!$ok){throw new \RuntimeException($message);}}
$root=dirname(dirname(__DIR__));$source=file_get_contents($root.'/update/update_from_153a.php');$helpers='';
foreach(array('update_quote_identifier','update_queue_session_engine') as $name){check(preg_match('/^function '.$name.'\(.*?^\}/ms',$source,$m)===1,'Actual migration helper found');$helpers.=$m[0]."\n";eval('namespace SessionStorageMigrationFixture;'.$m[0]);}
function update_config_engine($connection,$database,$table){return $connection?(string)$connection->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=".$connection->quote($table))->fetchColumn():$GLOBALS['fixture_engine'];}
function update_scalar($connection,$sql){return $connection?$connection->query($sql)->fetchColumn():'DEFAULT';}
foreach(array('MyISAM','MEMORY','HEAP','InnoDB','innodb') as $engine){$GLOBALS['fixture_engine']=$engine;$ops=array();update_queue_session_engine($ops,null,'fixture','custom_sessions');check($ops===(strcasecmp($engine,'InnoDB')===0?array():array('ALTER TABLE '.chr(96).'custom_sessions'.chr(96).' ENGINE=InnoDB MAX_ROWS=0')),'Exact engine-only plan, no deletion or cap');}
check(strpos($source,"update_queue_session_engine(\$operations, \$connection, \$dbname, \$table_prefix . 'sessions');")!==false,'Actual updater includes the migration');
$schema=file_get_contents($root.'/phpBB2/install/schemas/mysql_schema.sql');check(preg_match('/CREATE TABLE phpbb_sessions \([^;]*ENGINE=InnoDB/s',$schema)===1,'Installer and upgrade path agree');
$dsn=getenv('PHPBB_SESSION_STORAGE_DSN');
if($dsn!==false&&$dsn!==''){
 check(preg_match('/^mysql:host=127\.0\.0\.1;port=33119;dbname=codex_session_storage_[a-f0-9]{16};charset=utf8mb4$/D',$dsn)===1,'Only owned loopback schema');
 $pdo=new \PDO($dsn,'root','',array(\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION));
 foreach(array('MyISAM','MEMORY','InnoDB') as $engine){
  $pdo->exec('DROP TABLE IF EXISTS migration_sessions');$pdo->exec("CREATE TABLE migration_sessions (session_id VARCHAR(32) PRIMARY KEY,user_id INT NOT NULL,custom_flag VARCHAR(30) DEFAULT 'keep',KEY custom_user(user_id)) ENGINE=".$engine." DEFAULT CHARSET=utf8mb4");
  $rows=array();for($i=0;$i<602;$i++){$rows[]="('session-".$i."',".$i.",'Grüße')";}$pdo->exec('INSERT INTO migration_sessions VALUES '.implode(',',$rows));
  $before=$pdo->query('SELECT * FROM migration_sessions ORDER BY session_id')->fetchAll(\PDO::FETCH_ASSOC);$columns=$pdo->query('SHOW FULL COLUMNS FROM migration_sessions')->fetchAll(\PDO::FETCH_ASSOC);
  $ops=array();update_queue_session_engine($ops,$pdo,'fixture','migration_sessions');
  check($pdo->query('SELECT COUNT(*) FROM migration_sessions')->fetchColumn()==602&&strcasecmp(update_config_engine($pdo,'fixture','migration_sessions'),$engine)===0,'Planning never mutates storage');
  foreach($ops as $sql){$pdo->exec($sql);}
  check(strcasecmp(update_config_engine($pdo,'fixture','migration_sessions'),'InnoDB')===0,'Migration reaches expected engine');
  check($pdo->query('SELECT * FROM migration_sessions ORDER BY session_id')->fetchAll(\PDO::FETCH_ASSOC)===$before,'All 602 native sessions preserved');
  check($pdo->query('SHOW FULL COLUMNS FROM migration_sessions')->fetchAll(\PDO::FETCH_ASSOC)===$columns,'All native column definitions preserved');
  $keys=$pdo->query('SHOW INDEX FROM migration_sessions')->fetchAll(\PDO::FETCH_ASSOC);check(count($keys)===2&&$keys[0]['Key_name']==='PRIMARY'&&$keys[1]['Key_name']==='custom_user','Primary and custom indexes preserved');
  $retry=array();update_queue_session_engine($retry,$pdo,'fixture','migration_sessions');check(!$retry,'Repeated migration is a no-op');
  echo $engine." native session migration preserves rows, columns, indexes and retries.\n";
 }
}
// Actual preflight exits before emitting a migration for missing/custom engines
// or disabled InnoDB. A separate child is needed because exit bypasses finally.
$command=escapeshellarg(PHP_BINARY).' -d display_errors=0';
foreach(array(array('','DEFAULT'),array('ARCHIVE','DEFAULT'),array('MEMORY','NO'),array('MyISAM','DISABLED')) as $case){
 $input="<?php\nnamespace SessionStorageMigrationFixture;\nif(!defined('STDERR')){define('STDERR',fopen('php://stderr','wb'));}\nfunction update_config_engine(\$c,\$d,\$t){return ".var_export($case[0],true).";}\nfunction update_scalar(\$c,\$s){return ".var_export($case[1],true).";}\n".$helpers."\n\$ops=array();register_shutdown_function(function()use(&\$ops){echo 'PLANNED='.count(\$ops);});update_queue_session_engine(\$ops,null,'fixture','fixture_sessions');";
 $pipes=array();$p=proc_open($command,array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes,null,null,array('bypass_shell'=>true));check(is_resource($p),'Child preflight available');fwrite($pipes[0],$input);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);fclose($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);$code=proc_close($p);check($code===3&&$out==='PLANNED=0'&&strpos($err,'No update operations were applied.')!==false,'Unsupported engine preflight fails before work: '.json_encode(array($code,$out,$err)));
}
echo "Session storage migration plans, installer parity, dry runs and unsupported-engine preflight passed.\n";
