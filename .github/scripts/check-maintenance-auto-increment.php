<?php
namespace MaintenanceAutoFixture;
$root=dirname(dirname(__DIR__)).'/phpBB2/';
function check($ok,$message){if(!$ok){throw new \RuntimeException($message);}}
class RepairFailure extends \RuntimeException {}
function throw_error($message){throw new RepairFailure($message);}
$source=file_get_contents($root.'includes/functions_dbmtnc.php');
foreach(array('dbmtnc_auto_rows','set_autoincrement') as $name){check(preg_match('/^function '.$name.'\(.*?^\}/ms',$source,$match)===1,'Actual helper found');eval('namespace MaintenanceAutoFixture; use \RuntimeException; use \Exception; use \Throwable;'.$match[0]);}
class Rows {public $rows;function __construct($rows){$this->rows=$rows;}}
class Database {
 public $pdo;public $columns;public $indexes;public $mode='NO_ENGINE_SUBSTITUTION';public $original_mode='NO_ENGINE_SUBSTITUTION';public $queries=array();public $failure='';public $alters=0;public $column_reads=0;
 function __construct($pdo=null){$this->pdo=$pdo;$this->columns=array(array('Field'=>'id','Type'=>'bigint(20) unsigned','Null'=>'NO','Key'=>'PRI','Default'=>null,'Extra'=>'','Comment'=>"Grüße O'Connor \\ zero"));$this->indexes=array(array('Key_name'=>'PRIMARY','Column_name'=>'id','Seq_in_index'=>1));}
 function sql_escape($s){return $this->pdo?substr($this->pdo->quote($s),1,-1):str_replace(array('\\',"'"),array('\\\\',"\\'"),$s);}
 function sql_query($sql){
  $this->queries[]=$sql;
  if(strpos($sql,'SHOW FULL COLUMNS')===0){$this->column_reads++;if($this->failure==='columns'||($this->failure==='verify'&&$this->column_reads>1)){return false;}}
  if($this->failure==='index'&&strpos($sql,'SHOW INDEX')===0){return false;}
  if($this->failure==='mode-read'&&strpos($sql,'SELECT @@SESSION')===0){return false;}
  if(strpos($sql,'SET SESSION')===0&&($this->failure==='mode-set'||($this->failure==='restore'&&strpos($sql,'STRICT_ALL_TABLES')===false))){return false;}
  if(strpos($sql,'ALTER ')===0){$this->alters++;check(strpos($sql,'IGNORE')===false,'Never ignore DDL errors');if($this->failure==='alter'){return false;}}
  if($this->pdo){try{return $this->pdo->query($sql);}catch(\PDOException $e){return false;}}
  if(strpos($sql,'SHOW FULL COLUMNS')===0){return new Rows($this->columns);}
  if(strpos($sql,'SHOW INDEX')===0){return new Rows($this->indexes);}
  if($sql==='SELECT @@SESSION.sql_mode AS sql_mode'){return new Rows(array(array('sql_mode'=>$this->mode)));}
  if(preg_match('/^SET SESSION sql_mode = \x27([A-Z0-9_,]*)\x27$/D',$sql,$m)){$this->mode=$m[1];return true;}
  if(strpos($sql,'ALTER TABLE `fixture_auto` MODIFY COLUMN `id` ')===0){
   check(strpos($this->mode,'STRICT_ALL_TABLES')!==false&&strpos($this->mode,'NO_AUTO_VALUE_ON_ZERO')!==false,'Protect type conversion and existing zero IDs');
   $expected='ALTER TABLE `fixture_auto` MODIFY COLUMN `id` '.$this->columns[0]['Type']." NOT NULL AUTO_INCREMENT COMMENT '".$this->sql_escape($this->columns[0]['Comment'])."'";
   check($sql===$expected,'Exact original type/attributes/comment used');$this->columns[0]['Extra']='auto_increment';return true;
  }
  throw new \RuntimeException('Unexpected fixture SQL');
 }
 function sql_fetchrow($r){return $r instanceof Rows?array_shift($r->rows):$r->fetch(\PDO::FETCH_ASSOC);}
 function sql_freeresult($r){if(!($r instanceof Rows)){$r->closeCursor();}}
}
function run_repair($db,$expect_failure=false,$table='fixture_auto'){
 $GLOBALS['db']=$db;$caught=false;ob_start();try{set_autoincrement($table,'id',8,false);}catch(\RuntimeException $e){$caught=$e->getMessage()===$GLOBALS['lang']['Ai_repair_failed'];}finally{$output=ob_get_clean();}
 check($caught===$expect_failure,'Expected controlled repair result');return $output;
}
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new \RuntimeException($message);}});
try{
 foreach(array('english','german') as $language){
  $lang=array();$mtnc=array();$phpEx='php';include $root.'language/lang_'.$language.'/lang_dbmtnc.php';
  foreach(array('wide','healthy','default','nullable','type','extra','other-auto','compound','no-key','missing','columns','index','mode-read','mode-set','alter','verify','restore','identifier') as $case){
   $db=new Database();$review=in_array($case,array('default','nullable','type','extra','other-auto','compound','no-key'),true);
   if($case==='healthy'){$db->columns[0]['Extra']='auto_increment';}
   if($case==='default'){$db->columns[0]['Default']='7';}
   if($case==='nullable'){$db->columns[0]['Null']='YES';}
   if($case==='type'){$db->columns[0]['Type']='varchar(20)';}
   if($case==='extra'){$db->columns[0]['Extra']='INVISIBLE';}
   if($case==='other-auto'){$db->columns[]=array('Field'=>'other_id','Extra'=>'auto_increment');}
   if($case==='compound'){$db->indexes[]=array('Key_name'=>'PRIMARY','Column_name'=>'other_id','Seq_in_index'=>2);}
   if($case==='no-key'){$db->indexes=array();}
   if($case==='missing'){$db->columns=array();}
   $fails=in_array($case,array('missing','columns','index','mode-read','mode-set','alter','verify','restore','identifier'),true);
   if($fails){$db->failure=$case;}
   $before=$db->columns;$output=run_repair($db,$fails,$case==='identifier'?'fixture_auto;DROP':'fixture_auto');
   if($review){check(strpos($output,$lang['Ai_review_column'])!==false&&$db->alters===0&&$db->columns===$before,'Unsupported metadata retained for explicit review');}
   if($case==='healthy'){check($db->alters===0&&$db->columns===$before,'Healthy counter untouched');}
   if($case==='wide'){check($db->alters===1&&$db->columns[0]['Type']===$before[0]['Type'],'Wide repaired without narrowing');run_repair($db);check($db->alters===1,'Repair idempotent');}
   if($case!=='restore'){check($db->mode===$db->original_mode,'Session mode restored on success/failure');}
   if(in_array($case,array('verify','restore'),true)){check($db->columns[0]['Extra']==='auto_increment','Uncertain DDL is reported, not falsely described as rolled back');}
  }
 }
 echo "Auto-increment metadata, failure, SQL-mode restoration and localized review checks passed.\n";
 $dsn=getenv('PHPBB_AUTO_TEST_DSN');
 if($dsn!==false&&$dsn!==''){
  check(preg_match('/^mysql:host=127\.0\.0\.1;port=33119;dbname=codex_autoincrement_[a-f0-9]{16};charset=utf8mb4$/D',$dsn)===1,'Native DB restricted to owned local fixture');
  $pdo=new \PDO($dsn,'root','',array(\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION));
  foreach(array('MyISAM','InnoDB') as $engine){
   foreach(array('wide-unsigned','wide-signed','healthy','explicit-default','compound','failed-ddl') as $case){
    $pdo->exec('DROP TABLE IF EXISTS fixture_auto');$pdo->exec("SET SESSION sql_mode='NO_ENGINE_SUBSTITUTION'");
    $type=$case==='wide-signed'?'BIGINT':'BIGINT UNSIGNED';$comment="Grüße O'Connor \\ zero";
    $pdo->exec('CREATE TABLE fixture_auto (id '.$type.' NOT NULL'.($case==='healthy'?' AUTO_INCREMENT':'').($case==='explicit-default'?' DEFAULT 7':'').' COMMENT '.$pdo->quote($comment).',payload VARCHAR(100) NOT NULL, PRIMARY KEY (id'.($case==='compound'?',payload':'').'),KEY payload_index (payload)) ENGINE='.$engine.' DEFAULT CHARSET=utf8mb4');
    if($case==='healthy'){$pdo->exec("INSERT INTO fixture_auto (payload) VALUES ('old')");$pdo->exec('ALTER TABLE fixture_auto AUTO_INCREMENT=90000');}
    else{$pdo->exec("INSERT INTO fixture_auto VALUES (0,'zero'),(4294967298,'wide')".($case==='wide-signed'?",(-9,'negative')":",(18446744073709551600,'huge')"));}
    $before=$pdo->query('SELECT * FROM fixture_auto ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);$meta=$pdo->query("SHOW FULL COLUMNS FROM fixture_auto WHERE Field='id'")->fetch(\PDO::FETCH_ASSOC);$mode=$pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
    $db=new Database($pdo);if($case==='failed-ddl'){$db->failure='alter';}
    run_repair($db,$case==='failed-ddl');
    check($before===$pdo->query('SELECT * FROM fixture_auto ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC),'Every native row and zero/negative/huge ID preserved: '.$case);
    check($mode===$pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn(),'Native SQL mode restored');
    $after=$pdo->query("SHOW FULL COLUMNS FROM fixture_auto WHERE Field='id'")->fetch(\PDO::FETCH_ASSOC);
    foreach(array('Type','Null','Key','Default','Comment') as $key){check($after[$key]===$meta[$key],'Native column attribute preserved: '.$key);}
    if(in_array($case,array('explicit-default','compound','failed-ddl'),true)){check($after===$meta,'Review/failed DDL does not alter metadata');}
    else{
     check(strpos($after['Extra'],'auto_increment')!==false,'Missing native attribute restored');
     $count=$db->alters;run_repair($db);check($db->alters===$count,'Second native run issues no ALTER');
     $pdo->exec("INSERT INTO fixture_auto (payload) VALUES ('next')");
     $next=(string)$pdo->query("SELECT id FROM fixture_auto WHERE payload='next'")->fetchColumn();
     check($next===($case==='healthy'?'90000':($case==='wide-signed'?'4294967299':'18446744073709551601')),'Native next ID/counter not reset');
    }
   }
   echo $engine.": native wide/zero/negative IDs, attributes, next counters and strict failures passed.\n";
  }
 }
 $admin=file_get_contents($root.'admin/admin_db_maintenance.php');$a=strpos($admin,"case 'reset_auto_increment':");$b=strpos($admin,"case 'session_storage':",$a);$body=substr($admin,$a,$b-$a);
 $begin=strpos($body,'dbmtnc_table_begin($db, $_POST)');$end=strpos($body,'dbmtnc_table_end($db,');$first=strpos($body,'set_autoincrement(');$last=strrpos($body,'set_autoincrement(');
 check($begin!==false&&$end!==false&&$first!==false&&$last!==false&&$begin<$first&&$end>$last,'All DDL uses shared dedicated writer scope');
}finally{restore_error_handler();}
