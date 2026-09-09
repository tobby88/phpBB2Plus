<?php
$root=dirname(dirname(__DIR__)).'/phpBB2/';
require_once $root.'includes/php_compat.php';
function diagnostic_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
if(isset($argv[1])&&$argv[1]==='--child'){
 $case=$argv[2];$debug=$argv[3]==='1';$actor=$argv[4];
 define('DEBUG',$debug);define('ADMIN',1);define('GENERAL_MESSAGE',200);define('GENERAL_ERROR',202);define('CRITICAL_ERROR',204);define('CRITICAL_MESSAGE',203);define('HEADER_INC',true);
 if($actor!=='public'){define('IN_ADMIN',true);}
 $userdata=array('session_logged_in'=>$actor!=='public','user_level'=>$actor==='admin'?ADMIN:0);
 $phpEx='php';$phpbb_root_path=$root;$theme=array('fontcolor3'=>'000000');$list_open=false;
 $lang=array('Error'=>'Error','An_error_occured'=>'Error','Back_to_DB_Maintenance'=>'Back','General_Error'=>'Error','A_critical_error'=>'Error','Critical_Error'=>'Error');
 $plus_config=array('plus_version'=>'fixture');$board_config=array();
 class DiagnosticDatabase {function sql_error(){return array('code'=>1064,'message'=>'<script>fixture_driver_secret</script>');}}
 class DiagnosticTemplate {
  public $values=array();
  function set_filenames($x){}
  function assign_vars($x){$this->values=$x;}
  function pparse($x){
   if($GLOBALS['case']==='nested'){message_die(GENERAL_ERROR,'Nested safe message','',17,'C:\\private\\second.php',"SELECT 'fixture_query_secret'");}
   echo $this->values['MESSAGE_TEXT'];
  }
 }
 function append_sid($url){return $url;}
 $db=$case==='missing-db'?false:new DiagnosticDatabase();$template=new DiagnosticTemplate();
 foreach(array('functions.php'=>array('phpbb_debug_details_allowed','message_die'),'functions_dbmtnc.php'=>array('throw_error','erc_throw_error')) as $file=>$functions){
  $source=file_get_contents($root.'includes/'.$file);
  foreach($functions as $name){
   diagnostic_check(preg_match('/^function '.$name.'\(.*?^\}/ms',$source,$m)===1,'Actual error function found: '.$name);
   // Isolate external page-shell/language includes; real function exits and
   // real template dispatch remain. No live pages or render templates needed.
   $body=preg_replace('/^\s*include\(.*\);\r?$/m','',$m[0]);eval($body);
  }
 }
 set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
 $message='<a href="/help">Hilfe Grüße</a>';$sql="UPDATE users SET user_password='fixture_query_secret', user_email='fixture@example.invalid'";
 if($case==='erc'){erc_throw_error($message,12,'C:\\private\\source<>.php',$sql);}
 elseif($case==='acp'){throw_error($message,12,'C:\\private\\source<>.php',$sql);}
 else{message_die($case==='critical'?CRITICAL_ERROR:GENERAL_ERROR,$message,'',12,'C:\\private\\source<>.php',$sql);}
 throw new RuntimeException('Actual error handler failed to exit');
}
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{
 foreach(array(null,false,array(),array('code'=>array('bad'),'message'=>array()),array('code'=>'<script>bad</script>')) as $error){
  diagnostic_check(phpbb_safe_sql_diagnostics($error,array(),array(),array())==='','Malformed metadata safely ignored');
 }
 foreach(array("UPDATE t SET password='secret'"=>'UPDATE'," /* secret */ SELECT 1"=>'OTHER',"selective_secret"=>'OTHER',"\tselect 'secret'"=>'SELECT',str_repeat(' ',1000).'secret'=>'OTHER') as $sql=>$operation){
  $html=phpbb_safe_sql_diagnostics(array('code'=>1064,'message'=>'secret'),$sql,'12','C:\\private\\Grüße<>.php');
  diagnostic_check(strpos($html,'SQL: '.$operation)!==false&&strpos($html,'SQL Error: 1064')!==false,'Useful safe metadata retained');
  diagnostic_check(strpos($html,'secret')===false&&strpos($html,'private')===false&&strpos($html,'Grüße&lt;&gt;.php')!==false,'Values omitted and Unicode basename escaped');
 }
 diagnostic_check(strlen(phpbb_safe_sql_diagnostics(array(),'SELECT 1',12,str_repeat('x',100000)))<1000,'Diagnostic output bounded');
 diagnostic_check(strpos(phpbb_safe_sql_diagnostics(array(),'SELECT 1',12,"bad\xff.php"),'bad')!==false,'Invalid UTF-8 substituted without losing entire diagnostic');
 foreach(array('main','critical','nested','acp','erc','missing-db') as $case){foreach(array('0','1') as $debug){foreach($case==='erc'?array('admin'):array('admin','user','public') as $actor){
  $command=escapeshellarg(PHP_BINARY).' -d date.timezone=UTC '.escapeshellarg(__FILE__).' --child '.$case.' '.$debug.' '.$actor;
  $pipes=array();$process=proc_open($command,array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes,null,null,array('bypass_shell'=>true));
  diagnostic_check(is_resource($process),'Child started');fclose($pipes[0]);$html=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
  diagnostic_check($exit===0&&$errors==='','Actual handler exited cleanly: '.$case.'/'.$debug.'/'.$actor.' '.$errors);
  foreach(array('fixture_driver_secret','fixture_query_secret','fixture@example.invalid','<script>','C:\\private','C:/private') as $secret){diagnostic_check(strpos($html,$secret)===false,'No private SQL/driver/path data: '.$case);}
  $allowed=$debug==='1'&&($actor==='admin'||$case==='erc');
  diagnostic_check((strpos($html,'SQL: UPDATE')!==false)===$allowed,'Debug authorization retained: '.$case.'/'.$debug.'/'.$actor);
  if($allowed){diagnostic_check(strpos($html,'source&lt;&gt;.php')!==false,'Filename escaped in actual renderer');}
  if($allowed&&$case!=='nested'&&$case!=='missing-db'){diagnostic_check(strpos($html,'SQL Error: 1064')!==false,'Safe error code retained');}
  if($case!=='nested'||$allowed){diagnostic_check(strpos($html,'<a href="/help">Hilfe Grüße</a>')!==false,'Trusted localized help markup retained');}
 }}}
 $erc=file_get_contents($root.'admin/erc.php');diagnostic_check(preg_match('/(?<!erc_)\bthrow_error\(/',$erc)===0,'ERC callsites use their own handler');
 echo "Safe SQL metadata and actual main, nested, critical, ACP and ERC error renderers passed.\n";
}finally{restore_error_handler();}
