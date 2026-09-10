<?php
namespace MaintenanceOptimizationFixture;
define('IN_PHPBB',true);
$root=dirname(dirname(__DIR__)).'/phpBB2/';
$phpEx='php';
function check($ok,$message){if(!$ok){throw new \RuntimeException($message);}}
class MaintenanceFailure extends \RuntimeException {}
function throw_error($message){throw new MaintenanceFailure($message);}
function check_mysql_version(){return true;}
function lock_db($unlock=false){throw new \RuntimeException('Optimization must not change board availability');}
function dbmtnc_table_begin(&$db,$request){$GLOBALS['locks'][]='begin';return array($db);}
function dbmtnc_table_end(&$db,$scope){check($db===$scope[0],'Original report connection preserved');$GLOBALS['locks'][]='end';}
class Rows {public $rows;function __construct($rows){$this->rows=$rows;}}
class Database {
 public $old_size=0;public $new_size=0;public $reads=0;public $queries=array();public $messages=array();public $failure=false;public $pdo=null;
 function sql_query($sql){
  $this->queries[]=$sql;
  if($sql==='SHOW TABLE STATUS'){
   if($this->pdo){return $this->pdo->query($sql);}
   $size=$this->reads++===0?$this->old_size:$this->new_size;
   return new Rows(array(array('Name'=>'fixture_items','Rows'=>1,'Data_length'=>$size,'Index_length'=>0)));
  }
  if(strpos($sql,'OPTIMIZE TABLE ')===0){
   if($this->failure){return false;}
   return $this->pdo?$this->pdo->query($sql):new Rows($this->messages);
  }
  // Old implementation's unnecessary lookup when the first row is a note.
  if(strpos($sql,'SHOW TABLE STATUS LIKE ')===0){return new Rows(array(array('Engine'=>'InnoDB')));}
  throw new \RuntimeException('Unexpected SQL: '.$sql);
 }
 function sql_fetchrow($r){return $r instanceof Rows?array_shift($r->rows):$r->fetch(\PDO::FETCH_ASSOC);}
 function sql_freeresult($r){if(!($r instanceof Rows)){$r->closeCursor();}}
}
$helper_source=file_get_contents($root.'includes/functions_dbmtnc.php');
foreach(array('get_table_statistic','convert_bytes','dbmtnc_optimize_table','dbmtnc_table_maintenance') as $name){
 if(preg_match('/^function '.$name.'\(.*?^\}/ms',$helper_source,$m)){eval('namespace MaintenanceOptimizationFixture;'.$m[0]);}
}
$source=file_get_contents($root.'admin/admin_db_maintenance.php');
$start=strpos($source,"case 'optimize_db':");$end=strpos($source,"case 'reset_auto_increment':",$start);
check($start!==false&&$end>$start,'Actual optimization branch found');
$branch='namespace MaintenanceOptimizationFixture; switch("optimize_db") {'.substr($source,$start,$end-$start).'}';
function run_branch($database,$expect_failure=false){
 global $branch,$db,$tables,$table_prefix,$lang,$phpbb_root_path,$phpEx;
 $phpbb_root_path=$GLOBALS['root'];$_POST=array('sid'=>'fixture');
 $db=$database;$tables=array('items');$table_prefix='fixture_';$GLOBALS['locks']=array();$caught=false;
 ob_start();try{eval($branch);}catch(MaintenanceFailure $e){$caught=true;}finally{$html=ob_get_clean();}
 check($caught===$expect_failure,'Expected query failure boundary');
 check($GLOBALS['locks']===array('begin','end'),'Writer released after complete/incomplete/failed report');
 return $html;
}
function message($type,$text){return array('Msg_type'=>$type,'Msg_text'=>$text);}
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new \RuntimeException($message);}});
try{
 foreach(array('english','german') as $language){
  $lang=array();include($root.'language/lang_'.$language.'/lang_dbmtnc.php');
  foreach(array(array(0,0),array(0,2048),array(4096,2048),array(2048,4096),array(4096,4096)) as $sizes){
   $database=new Database();$database->old_size=$sizes[0];$database->new_size=$sizes[1];$database->messages=array(message('status','OK'));
   $html=run_branch($database);
   check(strpos($html,convert_bytes($sizes[0]))!==false&&strpos($html,convert_bytes($sizes[1]))!==false,'Before/after sizes present');
   $expected=$sizes[0]>0?sprintf('%01.2f%%',($sizes[0]-$sizes[1])/$sizes[0]*100):$lang['Optimization_percent_unavailable'];
   check(strpos($html,$expected)!==false,'Zero base is unavailable, nonzero signed percentage is correct');
   check(substr_count($html,'<b>')===substr_count($html,'</b>'),'No unmatched bold close in report');
  }
  $cases=array(
   array(array(message('note','recreate + analyze'),message('status','OK')),true,'recreate + analyze'),
   array(array(message('status','Operation failed')),false,'Operation failed'),
   array(array(message('error','<script>bad</script>'),message('status','Operation failed')),false,'&lt;script&gt;bad&lt;/script&gt;'),
   array(array(message('status','OK'),message('error','late failure')),false,'late failure'),
   array(array(message('status','OK'),message('note','no final confirmation')),false,'no final confirmation'),
   array(array(message('warning','unsupported engine'),message('status','OK')),false,'unsupported engine'),
   array(array(message('note','no final status')),false,'no final status'),
   array(array(),false,''),
   array(array(message('status','Table is already up to date')),true,'Table is already up to date'),
   array(array(message('info','information'),message('status','OK')),true,'information'),
   array(array(array('Msg_type'=>'status')),false,''),
   array(array(message('unexpected','unrecognized message'),message('status','OK')),false,'unrecognized message')
  );
  foreach($cases as $case){
   $database=new Database();$database->old_size=$database->new_size=4096;$database->messages=$case[0];
   $html=run_branch($database);
   check($case[2]===''||strpos($html,$case[2])!==false,'All server messages displayed, including later rows');
   check(strpos($html,'<script>')===false,'Server diagnostics escaped');
   check((strpos($html,$lang['Optimization_unconfirmed'])===false)===$case[1],'Unknown, failed or incomplete results never counted as success');
   check((strpos($html,$lang['Optimization_incomplete'])===false)===$case[1],'Aggregate report states incomplete operations');
   $ok_rows=0;foreach($case[0] as $row){if(isset($row['Msg_type'],$row['Msg_text'])&&$row['Msg_type']==='status'&&$row['Msg_text']==='OK'){$ok_rows++;}}
   check(substr_count($html,': '.$lang['Table_OK'].'</li>')===$ok_rows,'Only actual OK rows are mapped to localized OK');
  }
  $database=new Database();$database->failure=true;run_branch($database,true);
 }
 check(convert_bytes(-2048)==='-2.00 KB'&&convert_bytes(-2097152)==='-2.00 MB','Signed changes retain appropriate units');
 check(strpos(str_replace("\r\n","\n",$source),"case 'heap_convert': // Convert session table to HEAP\n\t\t\t\techo(\"<h1>\" . \$lang['Converting_heap']")!==false,'Session engine action uses its own title');
 $db=new Database();$caught=false;try{dbmtnc_optimize_table('fixture_items; DROP TABLE fixture_items');}catch(MaintenanceFailure $e){$caught=true;}
 check($caught&&count($db->queries)===0,'Invalid identifiers never reach SQL');
 $dsn=getenv('PHPBB_OPTIMIZE_TEST_DSN');
 if($dsn!==false&&$dsn!==''){
  check(preg_match('/^mysql:host=127\.0\.0\.1;port=33119;dbname=codex_optimize_[a-f0-9]{16};charset=utf8mb4$/D',$dsn)===1,'Only owned local schema allowed');
  $pdo=new \PDO($dsn,'root','',array(\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION));
  foreach(array('MyISAM','InnoDB','MEMORY') as $engine){
   $pdo->exec('CREATE TABLE fixture_items (id BIGINT PRIMARY KEY, body VARCHAR(255)) ENGINE='.$engine);
   try{
    $pdo->exec("INSERT INTO fixture_items VALUES (0,'Grüße'),(4294967298,'wide')");
    $before=$pdo->query('SELECT * FROM fixture_items ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
    foreach(array('english','german') as $language){
     $lang=array();include($root.'language/lang_'.$language.'/lang_dbmtnc.php');$database=new Database();$database->pdo=$pdo;
     $html=run_branch($database);
     check($before===$pdo->query('SELECT * FROM fixture_items ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC),'Optimization preserved all fixture rows');
     check(strpos($html,$lang['Table_OK'])!==false||strpos($html,'Table is already up to date')!==false||$engine==='MEMORY','Native successful final status displayed');
     check((strpos($html,$lang['Optimization_incomplete'])!==false)===($engine==='MEMORY'),'Native engine support accurately reported');
    }
   }finally{$pdo->exec('DROP TABLE fixture_items');}
  }
  echo "Native MyISAM/InnoDB/MEMORY optimization reports and row preservation passed.\n";
 }
 echo "Actual maintenance optimization branch, all messages, zero/growing sizes and both languages passed.\n";
}finally{restore_error_handler();}
