<?php
namespace MaintenanceReportFixture;
$root=dirname(dirname(__DIR__)).'/phpBB2/';
$phpEx='php';
function check($ok,$message){if(!$ok){throw new \RuntimeException($message);}}
class MaintenanceFailure extends \RuntimeException {}
function throw_error($message){throw new MaintenanceFailure('acp');}
function erc_throw_error($message){throw new MaintenanceFailure('erc');}
function check_mysql_version(){return true;}
function lock_db($unlock=false){$GLOBALS['locks'][]=$unlock;}
function check_authorisation(){if($GLOBALS['deny']){throw new MaintenanceFailure('auth');}$GLOBALS['authorized']=true;}
function success_message($message){$GLOBALS['successes'][]=$message;}
class Rows {public $rows;function __construct($rows){$this->rows=$rows;}}
class Database {
 public $messages=array();public $queries=array();public $failure=false;public $pdo=null;public $freed=0;
 function sql_query($sql){
  check(preg_match('/^(CHECK|REPAIR) TABLE `fixture_(first|second)`$/D',$sql)===1,'Only intended table operation issued');
  $this->queries[]=$sql;if($this->failure){return false;}
  if($this->pdo){return $this->pdo->query($sql);}
  return new Rows($this->messages[count($this->queries)-1]);
 }
 function sql_fetchrow($r){return $r instanceof Rows?array_shift($r->rows):$r->fetch(\PDO::FETCH_ASSOC);}
 function sql_freeresult($r){$this->freed++;if(!($r instanceof Rows)){$r->closeCursor();}}
}
$helper=file_get_contents($root.'includes/functions_dbmtnc.php');
check(preg_match('/^function dbmtnc_table_maintenance\(.*?^\}/ms',$helper,$m)===1,'Actual shared helper found');
eval('namespace MaintenanceReportFixture;'.$m[0]);
$source=file_get_contents($root.'admin/admin_db_maintenance.php');$branches=array();
foreach(array('check_db'=>'repair_db','repair_db'=>'optimize_db') as $action=>$next){
 $a=strpos($source,"case '".$action."':");$b=strpos($source,"case '".$next."':",$a);
 check($a!==false&&$b>$a,'Actual ACP branch found');
 $branches[$action]='namespace MaintenanceReportFixture; switch("'.$action.'"){'.substr($source,$a,$b-$a).'}';
}
$source=file_get_contents($root.'admin/erc.php');$execute=strpos($source,"case 'execute':");
check($execute!==false,'ERC execute dispatch found');$a=strpos($source,"case 'rdb':",$execute);$b=strpos($source,"case 'cct':",$a);
check($a!==false&&$b>$a,'Actual ERC execution, not confirmation branch found');
$branches['rdb']='namespace MaintenanceReportFixture; switch("rdb"){'.substr($source,$a,$b-$a).'}';
function run_branch($database,$action,$expected_failure='', $empty=false){
 global $branches,$db,$lang,$tables,$table_prefix;
 $db=$database;$tables=$empty?array():array('first','second');$table_prefix='fixture_';$GLOBALS['locks']=array();$GLOBALS['successes']=array();$GLOBALS['authorized']=false;$caught='';
 ob_start();try{eval($branches[$action]);}catch(MaintenanceFailure $e){$caught=$e->getMessage();}finally{$html=ob_get_clean();}
 check($caught===$expected_failure,'Failure uses appropriate ACP, ERC or authorization boundary');
 if($action==='rdb'&&$expected_failure!=='auth'){check($GLOBALS['authorized'],'ERC checks authorization before execution');}
 if($action!=='rdb'&&$caught===''){check($GLOBALS['locks']===array(false,true),'ACP maintenance lock restored after complete/incomplete result');}
 return $html;
}
function message($type,$text){return array('Msg_type'=>$type,'Msg_text'=>$text);}
$GLOBALS['deny']=false;
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new \RuntimeException($message);}});
try{
 foreach(array('english','german') as $language){
  $lang=array();include($root.'language/lang_'.$language.'/lang_dbmtnc.php');$lang['rdb_success']='fixture success';
  foreach(array('check_db','repair_db','rdb') as $action){
   $cases=array(
    array(array(message('status','OK')),true,''),
    array(array(message('status','Operation failed')),false,'Operation failed'),
    array(array(message('note','preliminary note'),message('status','OK')),true,'preliminary note'),
    array(array(message('note','unsupported engine')),false,'unsupported engine'),
    array(array(message('status','OK'),message('error','late failure')),false,'late failure'),
    array(array(message('status','OK'),message('note','no final confirmation')),false,'no final confirmation'),
    array(array(message('error','<script>bad</script>'),message('status','Operation failed')),false,'&lt;script&gt;bad&lt;/script&gt;'),
    array(array(message('warning','warning'),message('status','OK')),false,'warning'),
    array(array(message('status','Table is already up to date')),true,'Table is already up to date'),
    array(array(),false,''),array(array(array('Msg_type'=>'status')),false,'')
   );
   foreach($cases as $case){
    $database=new Database();$database->messages=array($case[0],array(message('status','OK')));
    $html=run_branch($database,$action);
    check(count($database->queries)===2&&$database->freed===2,'Continue later tables and release every result, even after first failure');
    check($case[2]===''||strpos($html,$case[2])!==false,'All server messages shown');check(strpos($html,'<script>')===false,'Server diagnostics escaped');
    check((strpos($html,$lang['Maintenance_unconfirmed'])===false)===$case[1],'Table failure not hidden');
    check((strpos($html,$lang['Maintenance_incomplete'])===false)===$case[1],'Later success cannot erase earlier failure');
    if($action==='rdb'){check(count($GLOBALS['successes'])===($case[1]?1:0),'ERC success only for fully confirmed repairs');}
   }
   $database=new Database();$html=run_branch($database,$action,'',true);
   check(count($database->queries)===0&&strpos($html,$lang['Maintenance_incomplete'])!==false&&count($GLOBALS['successes'])===0,'Empty table list is not success');
   $database=new Database();$database->failure=true;run_branch($database,$action,$action==='rdb'?'erc':'acp');
   check(count($database->queries)===1&&count($GLOBALS['successes'])===0,'Query failure stops without success');
  }
  $GLOBALS['deny']=true;$database=new Database();run_branch($database,'rdb','auth');
  check(count($database->queries)===0,'Denied ERC never executes SQL');$GLOBALS['deny']=false;
  foreach(array(false,true) as $erc){foreach(array(array('bad`table','REPAIR'),array('fixture_first','DROP')) as $bad){
   $db=new Database();$caught='';try{dbmtnc_table_maintenance($bad[0],$bad[1],$erc);}catch(MaintenanceFailure $e){$caught=$e->getMessage();}
   check($caught===($erc?'erc':'acp')&&count($db->queries)===0,'Invalid operation/identifier rejected in the right context');
  }}
 }
 $dsn=getenv('PHPBB_REPORT_TEST_DSN');
 if($dsn!==false&&$dsn!==''){
  check(preg_match('/^mysql:host=127\.0\.0\.1;port=33119;dbname=codex_report_[a-f0-9]{16};charset=utf8mb4$/D',$dsn)===1,'Only owned local schemas allowed');
  $pdo=new \PDO($dsn,'root','',array(\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION));
  foreach(array('MyISAM','InnoDB','MEMORY') as $engine){
   foreach(array('first','second') as $table){$pdo->exec('CREATE TABLE fixture_'.$table.' (id BIGINT PRIMARY KEY, body VARCHAR(255)) ENGINE='.$engine);$pdo->exec("INSERT INTO fixture_".$table." VALUES (0,'Grüße'),(4294967298,'wide')");}
   try{
    $before=$pdo->query('SELECT * FROM fixture_first ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
    foreach(array('english','german') as $language){
     $lang=array();include($root.'language/lang_'.$language.'/lang_dbmtnc.php');$lang['rdb_success']='fixture success';
     foreach(array('check_db','repair_db','rdb') as $action){
      $database=new Database();$database->pdo=$pdo;$html=run_branch($database,$action);
      $supported=$engine==='MyISAM'||($engine==='InnoDB'&&$action==='check_db');
      check((strpos($html,$lang['Maintenance_incomplete'])===false)===$supported,'Native support accurately reflected: '.$engine.'/'.$action);
      if($action==='rdb'){check(count($GLOBALS['successes'])===($supported?1:0),'Native ERC success reflects engine support');}
      foreach(array('first','second') as $table){check($before===$pdo->query('SELECT * FROM fixture_'.$table.' ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC),'Every fixture row/ID preserved');}
     }
    }
   }finally{foreach(array('first','second') as $table){$pdo->exec('DROP TABLE fixture_'.$table);}}
  }
  echo "Native CHECK/REPAIR reports for MyISAM/InnoDB/MEMORY and data preservation passed.\n";
 }
 echo "Actual ACP CHECK/REPAIR and ERC execution reports, authorization boundary and both languages passed.\n";
}finally{restore_error_handler();}
