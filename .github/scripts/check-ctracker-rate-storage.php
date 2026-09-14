<?php
// Explicit native test: no forum configuration, live counters or SQLite SQL model.
if(PHP_SAPI!=='cli'||getenv('PHPBB_RATE_NATIVE')!=='1'){echo "Native rate-storage fixture requires PHPBB_RATE_NATIVE=1; skipped.\n";return;}
define('IN_PHPBB',true);define('CTRACKER_REQUEST_LIMITER_NO_AUTO_RUN',true);define('CTRACKER_RATE_LIMITS','fixture_rate_limits');
$root=dirname(dirname(__DIR__));
require $root.'/phpBB2/ctracker/engines/ct_request_limiter.php';
function rate_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
$port=getenv('PHPBB_RATE_TEST_PORT')?:'3306';
rate_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Fixture port');
$dsn='mysql:host=127.0.0.1;port='.$port.';charset=utf8mb4';
$password=getenv('PHPBB_RATE_TEST_PASSWORD')?:'';
$pdo=new PDO($dsn,'root',$password,array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION));
$fixture='codex_rate_'.bin2hex(function_exists('random_bytes')?random_bytes(8):openssl_random_pseudo_bytes(8));
$pdo->exec('CREATE DATABASE '.$fixture.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
class NativeRateDatabase {
 public $pdo;public $hook=null;public $failure='';public $queries=array();public $insert=null;
 function __construct($pdo){$this->pdo=$pdo;}
 function sql_escape($value){return substr($this->pdo->quote($value),1,-1);}
 function sql_query($sql){
  $this->queries[]=$sql;
  if(preg_match("/VALUES \('([a-f0-9]{64})', ([0-9]+), 1, ([0-9]+)\)/",$sql,$m)){$this->insert=array('hash'=>$m[1],'window'=>(int)$m[2],'now'=>(int)$m[3]);}
  if(is_callable($this->hook)){call_user_func($this->hook,$sql,$this);}
  if($this->failure!==''&&strpos($sql,$this->failure)===0){return false;}
  try{return $this->pdo->query($sql);}catch(PDOException $e){return false;}
 }
 function sql_fetchrow($r){return $r->fetch(PDO::FETCH_ASSOC);}
 function sql_freeresult($r){$r->closeCursor();}
}
function rate_reset(){
 global $db,$pdo;
 $pdo->exec('DELETE FROM fixture_rate_limits');$db->hook=null;$db->failure='';$db->queries=array();$db->insert=null;
}
function rate_row(){return $GLOBALS['pdo']->query('SELECT window_start,request_count,updated_at FROM fixture_rate_limits')->fetch(PDO::FETCH_ASSOC);}
function rate_seed($hash,$window,$count,$updated){
 $q=$GLOBALS['pdo']->prepare('INSERT INTO fixture_rate_limits (bucket_hash,window_start,request_count,updated_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE window_start=VALUES(window_start),request_count=VALUES(request_count),updated_at=VALUES(updated_at)');
 $q->execute(array($hash,$window,$count,$updated));
}
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{
 $pdo->exec('USE '.$fixture);
 $writer=new PDO($dsn.';dbname='.$fixture,'root',$password,array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION));
 foreach(array($pdo,$writer) as $connection){$connection->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");}
 $db=new NativeRateDatabase($writer);
 $schema=file_get_contents($root.'/phpBB2/install/schemas/mysql_schema.sql');
 rate_check(preg_match('/CREATE TABLE .phpbb_ctracker_rate_limits.[\s\S]*?;/',$schema,$m)===1,'Canonical rate schema');
 foreach(array('InnoDB','MyISAM') as $engine){
  $pdo->exec('DROP TABLE IF EXISTS fixture_rate_limits');
  $ddl=str_replace('phpbb_ctracker_rate_limits','fixture_rate_limits',$m[0]);
  if($engine==='MyISAM'){$ddl=str_replace('ENGINE=InnoDB ROW_FORMAT=DYNAMIC','ENGINE=MyISAM',$ddl);}
  $pdo->exec($ddl);
  foreach(array('empty','same','old','newer','newer-low','two-windows','saturated','old-saturated') as $case){
   rate_reset();$seed=null;
   $db->hook=function($sql,$database)use($case,&$seed){
    if(strpos($sql,'INSERT INTO')!==0){return;}$database->hook=null;$p=$database->insert;
    if($case==='empty'){return;}
    $offset=$case==='old'||$case==='old-saturated'?-600:($case==='two-windows'?1200:(strpos($case,'newer')===0?600:0));
    $count=$case==='newer-low'?2:(strpos($case,'saturated')!==false?'4294967295':30);
    $seed=array('window'=>$p['window']+$offset,'count'=>$count,'updated'=>$p['now']+$offset);
    rate_seed($p['hash'],$seed['window'],$count,$seed['updated']);
   };
   $retry=ctracker_rate_limit_increment('counter','192.0.2.1',600,30);$row=rate_row();$p=$db->insert;
   $fresh=in_array($case,array('empty','old','old-saturated'),true);
   $expectedCount=$fresh?'1':($case==='saturated'?'4294967295':($case==='newer-low'?'3':'31'));
   rate_check((string)$row['request_count']===$expectedCount,'Atomic count/reset/saturation: '.$case);
   rate_check((int)$row['window_start']===max($p['window'],$seed?$seed['window']:0),'Stored window never rewinds');
   rate_check((int)$row['updated_at']===max($p['now'],$seed?$seed['updated']:0),'Last update never rewinds');
   rate_check(is_int($retry)&&($fresh||$case==='newer-low'?$retry===0:$retry>0&&$retry<=600),'Threshold and bounded Retry-After: '.$case);
  }
  // A newer window can also commit between our write and readback.
  rate_reset();$db->hook=function($sql,$database){if(strpos($sql,'SELECT request_count')!==0){return;}$database->hook=null;$p=$database->insert;rate_seed($p['hash'],$p['window']+600,31,$p['now']+600);};
  rate_check(ctracker_rate_limit_increment('counter','192.0.2.1',600,30)===600,'Readback cannot lose a concurrently advanced window');
  rate_reset();
  rate_check(ctracker_rate_limit_increment('counter','192.0.2.1',3600,1)===0,'First request allowed');
  rate_check(ctracker_rate_limit_increment('counter','192.0.2.1',3600,1)>0,'Excess request limited');
  rate_check(ctracker_rate_limit_increment('counter','192.0.2.2',3600,1)===0&&ctracker_rate_limit_increment('other','192.0.2.1',3600,1)===0,'Independent IPs and buckets');
  foreach(array('empty','old','same','newer','saturated') as $case){
   rate_reset();$seed=null;
   $db->hook=function($sql,$database)use($case,&$seed){
    if(strpos($sql,'INSERT INTO')!==0){return;}$database->hook=null;$p=$database->insert;
    if($case==='empty'){return;}
    $stamp=$p['now']+($case==='old'?-60:($case==='newer'?10:0));$seed=$stamp;
    rate_seed($p['hash'],$stamp,$case==='saturated'?'4294967295':30,$stamp);
   };
   rate_check(ctracker_rate_limit_mark_success('success','user:1'),'Success timestamp storage: '.$case);
   $row=rate_row();$expected=max($db->insert['now'],$seed===null?0:$seed);
   rate_check((int)$row['updated_at']===$expected&&(int)$row['window_start']===$expected,'Completion timestamp never moves backwards');
   rate_check((string)$row['request_count']===($case==='empty'?'1':($case==='saturated'?'4294967295':'31')),'Completion counter saturates safely');
   $remaining=ctracker_rate_limit_cooldown_remaining('success','user:1',30);
   rate_check($remaining>0&&abs($remaining-($expected+30-time()))<=1,'Cooldown uses latest successful completion');
   rate_check(ctracker_rate_limit_cooldown_remaining('success','user:2',30)===0,'Successful action does not lock another identity');
  }
  rate_reset();rate_seed(hash('sha256',"success\0user:1"),time()-60,1,time()-60);
  rate_check(ctracker_rate_limit_cooldown_remaining('success','user:1',30)===0,'Expired cooldown allows retry');
  rate_reset();$db->failure='INSERT INTO';
  rate_check(ctracker_rate_limit_increment('counter','ip',600,1)===false&&ctracker_rate_limit_mark_success('success','ip')===false,'Failed writes retain documented fail-open result');
  rate_reset();$db->failure='SELECT request_count';
  rate_check(ctracker_rate_limit_increment('counter','ip',600,1)===false&&(int)rate_row()['request_count']===1,'Read failure does not pretend the write was rolled back');
  rate_reset();$db->failure='SELECT updated_at';rate_check(ctracker_rate_limit_cooldown_remaining('success','ip',30)===false,'Cooldown read failure');
  rate_reset();$db->hook=function($sql,$database){if(strpos($sql,'SELECT request_count')===0){$database->hook=null;$GLOBALS['pdo']->exec('DELETE FROM fixture_rate_limits');}};
  rate_check(ctracker_rate_limit_increment('counter','ip',600,1)===false,'Missing readback row is a controlled storage failure');
  rate_reset();rate_check(ctracker_rate_limit_increment('','ip',600,1)===false&&ctracker_rate_limit_mark_success('success','')===false&&!$db->queries,'Empty identity rejected before SQL');
  $pdo->exec('DROP TABLE fixture_rate_limits');
  rate_check(ctracker_rate_limit_increment('counter','ip',600,1)===false&&ctracker_rate_limit_mark_success('success','ip')===false&&ctracker_rate_limit_cooldown_remaining('success','ip',30)===false,'Interrupted update with missing storage stays fail-open');
  echo $engine." native CrackerTracker ordering, quota, cooldown, saturation and failure checks passed\n";
 }
}finally{$writer=null;$pdo->exec('DROP DATABASE '.$fixture);restore_error_handler();}
