<?php
namespace MaintenanceRecoveryMigration;
function check($ok,$message){if(!$ok){throw new \RuntimeException($message);}}
function update_table_exists($connection,$database,$table){
 if(!$connection){return $GLOBALS['recovery_migration_exists'];}
 $r=$connection->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$r->execute(array($table));return (int)$r->fetchColumn()>0;
}
function update_column_exists($connection,$database,$table,$column){
 if(!$connection){return $GLOBALS['recovery_migration_column'];}
 $r=$connection->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');$r->execute(array($table,$column));return (int)$r->fetchColumn()>0;
}
function update_index_exists($connection,$database,$table,$index){
 if(!$connection){return $GLOBALS['recovery_migration_index'];}
 $r=$connection->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');$r->execute(array($table,$index));return (int)$r->fetchColumn()>0;
}
$root=dirname(dirname(__DIR__));$source=file_get_contents($root.'/update/update_from_153a.php');
foreach(array('update_quote_identifier'=>'update_query_or_fail','update_queue_column'=>'update_queue_topic_notification_columns','update_queue_maintenance_recovery_columns'=>'update_queue_pm_write_columns') as $name=>$next){
 $a=strpos($source,'function '.$name.'(');$b=strpos($source,'function '.$next.'(',$a);
 check($a!==false&&$b>$a,'Actual migration function exists '.$name);eval('namespace MaintenanceRecoveryMigration;'.substr($source,$a,$b-$a));
}
check(strpos($source,"foreach (array('categories', 'forums', 'topics') as \$recovery_table)")!==false&&strpos($source,'update_queue_maintenance_recovery_columns($operations, $connection, $dbname, $table_prefix . $recovery_table);')!==false,'All three canonical updater calls');
$schema=file_get_contents($root.'/phpBB2/install/schemas/mysql_schema.sql');
foreach(array('categories','forums','topics') as $suffix){
 check(preg_match('/CREATE TABLE phpbb_'.$suffix.' \((.*?)\) ENGINE=/s',$schema,$m)===1,'Canonical table '.$suffix);
 check(strpos($m[1],'maintenance_token char(32) default NULL')!==false&&strpos($m[1],'UNIQUE KEY maintenance_token (maintenance_token)')!==false,'Canonical nullable identity/index '.$suffix);
}
foreach(array(false,true) as $recovery_migration_exists){foreach(array(false,true) as $recovery_migration_column){foreach(array(false,true) as $recovery_migration_index){
 $operations=array();update_queue_maintenance_recovery_columns($operations,null,'fixture','custom_categories');
 check(count($operations)===($recovery_migration_exists?(!$recovery_migration_column?1:0)+(!$recovery_migration_index?1:0):0),'Only missing column/index queued');
 foreach($operations as $sql){check(strpos($sql,'ALTER TABLE `custom_categories` ADD ')===0,'Additive DDL only');}
}}}
$dsn=getenv('PHPBB_SESSION_RESET_TEST_DSN');
if($dsn!==false&&$dsn!==''){
 check(preg_match('/^mysql:host=127\.0\.0\.1;port=33119;dbname=codex_session_reset_[a-f0-9]{16};charset=utf8mb4$/D',$dsn)===1,'Only owned local fixture schema');
 $p=new \PDO($dsn,'root','',array(\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION));
 foreach(array('MyISAM','InnoDB') as $engine){foreach(array('categories','forums','topics') as $suffix){
  $table='custom_'.$suffix;$p->exec('DROP TABLE IF EXISTS '.$table);
  try{
   $p->exec('CREATE TABLE '.$table.' (id INTEGER PRIMARY KEY,label VARCHAR(50)) ENGINE='.$engine);
   $p->exec("INSERT INTO ".$table." VALUES (1,'Original Grüße'),(2,'Unrelated')");
   $ops=array();update_queue_maintenance_recovery_columns($ops,$p,'fixture',$table);check(count($ops)===2,'Legacy table needs two additive operations');
   foreach($ops as $sql){$p->exec($sql);}
   check((int)$p->query('SELECT COUNT(*) FROM '.$table.' WHERE maintenance_token IS NULL')->fetchColumn()===2,'Legacy records preserved with NULL identity');
   $p->exec("UPDATE ".$table." SET maintenance_token='".str_repeat('a',32)."' WHERE id=1");
   $duplicate=false;try{$p->exec("UPDATE ".$table." SET maintenance_token='".str_repeat('a',32)."' WHERE id=2");}catch(\PDOException $e){$duplicate=true;}
   check($duplicate,'Unique identity enforced');
   $ops=array();update_queue_maintenance_recovery_columns($ops,$p,'fixture',$table);check(!$ops,'Repeated migration empty');
   check($p->query('SELECT label FROM '.$table.' WHERE id=1')->fetchColumn()==='Original Grüße','Original label unchanged');
  }finally{$p->exec('DROP TABLE IF EXISTS '.$table);}
 }}
}
echo "Recovery container migration checks passed.\n";
