<?php
if (PHP_SAPI !== 'cli' || getenv('PHPBB_STYLE_RECOVERY_CONTROLLER_NATIVE') !== '1') { echo "Style recovery controller checks require an explicitly enabled disposable database.\n"; return; }
putenv('PHPBB_STYLE_DATA_NATIVE=1'); putenv('PHPBB_STYLE_DATA_PORT=' . (getenv('PHPBB_STYLE_IMPORT_PORT') ?: '3306'));
putenv('PHPBB_STYLE_DATA_PASSWORD=' . (getenv('PHPBB_STYLE_IMPORT_PASSWORD') ?: ''));
$source=file_get_contents(__DIR__.'/check-style-data-native.php');$cut=strpos($source," if (getenv('PHPBB_STYLE_DATA_PROBE')");
if($cut===false){throw new RuntimeException('Missing native fixture boundary');}
$head=str_replace('__DIR__',var_export(__DIR__,true),substr($source,5,$cut-5));$head=str_replace('codex_style_data_','codex_style_recovery_controller_',$head);
$source=str_replace("\r\n","\n",file_get_contents(__DIR__.'/check-style-import-native.php'));
$marker="\$body=<<<'PHP'\n";$start=strpos($source,$marker);$end=strpos($source,' $cases=0;$serialized=0;',$start);
if($start===false||$end===false){throw new RuntimeException('Missing import fixture boundary');}
$setup=substr($source,$start+strlen($marker),$end-$start-strlen($marker));
$body=<<<'PHP'
 require_once $sd_source.'includes/functions_style_clone.php';
 define('XS_FTP_LOCAL',-1);
 $template_dir='templates/';$checks=0;$ftp_calls=0;$ftp_form=false;
 class RecoveryTemplate extends StyleDataTemplate { var $vars=array(); function assign_vars($vars){$this->vars=array_merge($this->vars,$vars);} }
 $template=new RecoveryTemplate();
 function xs_exit(){throw new StyleDataExit('form');}
 function get_ftp_config($url,$params,$save){$GLOBALS['ftp_calls']++;$GLOBALS['ftp_params']=$params;if(isset($GLOBALS['ftp_setup_hook'])&&is_callable($GLOBALS['ftp_setup_hook'])){call_user_func($GLOBALS['ftp_setup_hook']);}return !$GLOBALS['ftp_form'];}
 function xs_ftp_connect($url,$params,$save){$GLOBALS['ftp_calls']++;$GLOBALS['ftp']=XS_FTP_LOCAL;}
 function rc_check($ok,$message){$GLOBALS['checks']++;sd_check($ok,$message);}
 function rc_run($request=null,$method='POST'){
  global $db,$phpbb_root_path,$phpEx,$lang,$template,$userdata,$HTTP_POST_VARS,$HTTP_GET_VARS,$board_config,$ftp;
  $_SERVER['REQUEST_METHOD']=$method;$_POST=$HTTP_POST_VARS=$request===null?array():$request;$HTTP_GET_VARS=array();$phpbb_root_path='../';$GLOBALS['ftp_calls']=0;
  try{include $GLOBALS['sd_source'].'admin/xs_include_recovery.php';return 'listed';}catch(StyleDataExit $error){return $error->getMessage()==='Invalid administration request.'?'error':$error->getMessage();}
 }
 function rc_request($receipt,$mode){return array('sid'=>'fixture-admin','recovery_action'=>$mode,'recovery_template'=>$receipt['t'],'recovery_operation'=>$receipt['o']);}
 function rc_pending($actor='root'){
  sim_reset($actor);file_put_contents($GLOBALS['sd_root'].'/templates/fisubsilversh/theme_info.cfg','original');
  $GLOBALS['sd_failure']='INSERT INTO fixture_themes ';rc_check(sim_run()==='error','Create actual-controller recoverable import');$GLOBALS['sd_failure']='';
  return phpbb_style_import_read_receipt($GLOBALS['peer'],'fisubsilversh');
 }
 foreach(array('root','delegated') as $actor){
  $r=rc_pending($actor);$before=sim_snapshot();rc_check(rc_run(null,'GET')==='listed'&&$ftp_calls===0&&sim_snapshot()===$before,'Listing is read-only and does not connect FTP');
  $html=$template->vars['IMPORT_RECOVERY'];rc_check(substr_count($html,'method="post"')===2&&strpos($html,'value="'.$r['o'].'"')!==false&&strpos($html,'value="fixture-admin"')!==false,'Prepared job renders two bound POST forms');
  $ftp_form=true;rc_check(rc_run(rc_request($r,'resume'))==='form'&&$ftp_calls===1&&$ftp_params['recovery_operation']===$r['o']&&$ftp_params['recovery_action']==='resume','FTP setup round-trip preserves exact action and operation');$ftp_form=false;
  rc_check(rc_run(rc_request($r,'resume'))==='saved'&&$ftp_calls===2&&count(sd_snapshot()[0])===4,'Actual resume route registers complete batch');sd_unlocked();
  rc_check(rc_run(null,'GET')==='listed'&&strpos($template->vars['IMPORT_RECOVERY'],'<form')===false,'Committed job has no rollback/resume form');
  file_put_contents($sd_root.'/templates/fisubsilversh/theme_info.cfg','later edit');
  rc_check(rc_run(rc_request($r,'resume'))==='saved'&&file_get_contents($sd_root.'/templates/fisubsilversh/theme_info.cfg')==='later edit','Terminal retry cannot overwrite later files');
  rc_check(rc_run(rc_request($r,'rollback'))==='error'&&$ftp_calls===0,'Committed rollback denied before FTP side effects');
  $r=rc_pending($actor);rc_check(rc_run(rc_request($r,'rollback'))==='saved'&&file_get_contents($sd_root.'/templates/fisubsilversh/theme_info.cfg')==='original'&&count(sd_snapshot()[0])===2,'Actual rollback route restores original without adding metadata');
  rc_check(rc_run(rc_request($r,'resume'))==='error'&&$ftp_calls===0,'Rolled-back job cannot resume');
  foreach(array('missing','inactive','admin-off','foreign','logout','case',$actor==='root'?'role':'grant') as $kind){
   $r=rc_pending($actor);sd_sql(sd_revoke($kind));$before=sim_snapshot();
   rc_check(rc_run(rc_request($r,'rollback'))==='error'&&$ftp_calls===0&&sim_snapshot()===$before,'Revoked action denied before connection: '.$kind);sd_unlocked();
   rc_check(rc_run(null,'GET')==='error','Revoked listing denied too');
  }
 }
 foreach(array(array('sid'=>'bad'),array('recovery_operation'=>str_repeat('a',32)),array('recovery_template'=>'legacy'),array('recovery_action'=>'start'),array('recovery_action'=>array())) as $bad){
  $r=rc_pending();rc_check(rc_run(array_merge(rc_request($r,'resume'),$bad))==='error'&&$ftp_calls===0,'Invalid recovery request has no FTP side effects');
 }
 $r=rc_pending();rc_check(rc_run(rc_request($r,'resume'),'GET')==='error'&&$ftp_calls===0,'GET cannot mutate');
 sim_reset();$operation=bin2hex(phpbb_random_bytes(16));$commits=0;
 $sd_hook=function($sql)use(&$commits){if($sql==='COMMIT'&&++$commits===2){$GLOBALS['sd_failure']='lost-ack';$GLOBALS['sd_hook']=null;}};
 rc_check(sim_run(array('recovery_operation'=>$operation))==='error','Actual import never claims an unconfirmed final commit');$sd_failure='';
 $r=phpbb_style_import_read_receipt($peer,'fisubsilversh');rc_check($commits===2&&$r['s']==='committed'&&$r['o']===$operation&&count(sd_snapshot()[0])===4,'Native receipt proves actual final-controller commit');
 file_put_contents($sd_root.'/templates/fisubsilversh/theme_info.cfg','later independent edit');
 rc_check(rc_run(rc_request($r,'resume'))==='saved'&&file_get_contents($sd_root.'/templates/fisubsilversh/theme_info.cfg')==='later independent edit','Actual recovery route resolves lost final ACK without republishing files');
 // Package capture uses the real packer and owned native connection, not a
 // fake database or a stub of the operation under test.
 $phpbb_root_path=$sd_root.'/';$_SERVER['REQUEST_METHOD']='POST';$req=array('sid'=>'fixture-admin');
 foreach(array('source','target') as $pending){
  sim_reset();$name=$pending==='source'?'fisubsilversh':'copied';$receipt=array('v'=>1,'t'=>$name,'o'=>str_repeat('a',32),'s'=>'prepared','h'=>str_repeat('b',64),'a'=>str_repeat('c',64));
  sd_sql("INSERT INTO fixture_config VALUES ('".phpbb_style_import_receipt_key($name)."','".$peer->sql_escape(json_encode($receipt))."')");
  $denied=false;try{phpbb_style_clone_package($db,$req,'fisubsilversh','copied',array(1=>'Copied Größe 😀'));}catch(PhpbbAclException $e){$denied=true;}
  rc_check($denied,'Pending '.$pending.' prevents package capture');sd_unlocked();
 }
 foreach(array('root','delegated') as $actor){
  sim_reset($actor);file_put_contents($sd_root.'/templates/fisubsilversh/theme_info.cfg','not executable');$before=sim_snapshot();
  $packed=phpbb_style_clone_package($db,$req,'fisubsilversh','copied',array(1=>'Copied Größe 😀'));file_put_contents($sd_root.'/cache/captured.style',$packed);
  $h=xs_get_style_header($sd_root.'/cache/captured.style');$tar=gzuncompress(substr($packed,$h['offset']));$entries=phpbb_style_archive_entries($tar);
  rc_check($h['template']==='copied'&&count($entries)>=2&&sim_snapshot()===$before,'Real archive captured without SQL mutation');sd_unlocked();
  foreach(array('missing',$actor==='root'?'role':'grant') as $kind){
   sim_reset($actor);sd_sql(sd_revoke($kind));$denied=false;try{phpbb_style_clone_package($db,$req,'fisubsilversh','copied',array(1=>'Copied'));}catch(PhpbbAclException $e){$denied=true;}
   rc_check($denied,'Fresh authority required for source capture');sd_unlocked();
  }
  sim_reset($actor);$killed=false;$sd_hook=function($sql,$connection)use(&$killed){if($sql==='COMMIT'){$GLOBALS['sd_hook']=null;sd_sql('KILL CONNECTION '.mysqli_thread_id($connection->db_connect_id));$killed=true;}};
  $denied=false;try{phpbb_style_clone_package($db,$req,'fisubsilversh','copied',array(1=>'Copied'));}catch(PhpbbAclException $e){$denied=true;}
  rc_check($denied&&$killed,'Lost source owner never returns captured package');sd_unlocked();
 }
 // One complete actual clone controller invocation (including its real import
 // controller) verifies nonce hand-off, packing, publication and registration.
 sim_reset();$phpbb_root_path='../';$GLOBALS['ftp']=XS_FTP_LOCAL;
 foreach(array('functions_style_clone.php') as $helper){file_put_contents($sd_root.'/includes/'.$helper,'<?php require_once '.var_export($sd_source.'includes/'.$helper,true).';');}
 foreach(array('xs_include_import.php','xs_include_import2.php') as $controller){file_put_contents($sd_root.'/admin/'.$controller,'<?php include '.var_export($sd_source.'admin/'.$controller,true).';');}
 $_POST=$HTTP_POST_VARS=array('sid'=>'fixture-admin','clone_tpl'=>'fisubsilversh','clone_style_name'=>'copied','total'=>'1','clone_style_0'=>'1','clone_style_id_0'=>'1','clone_style_name_0'=>'Copied Größe 😀','recovery_operation'=>bin2hex(phpbb_random_bytes(16)));$HTTP_GET_VARS=array();
 $clone_request=$HTTP_POST_VARS;
 foreach(array('missing','role','nonce') as $bad){
  sim_reset();$ftp_calls=0;$_POST=$HTTP_POST_VARS=$clone_request;
  if($bad==='nonce'){$HTTP_POST_VARS['recovery_operation']='wrong';}else{sd_sql(sd_revoke($bad));}
  $out='';try{include $sd_source.'admin/xs_clone.php';}catch(StyleDataExit $e){$out=$e->getMessage();}
  rc_check($out==='error'&&$ftp_calls===0&&!is_dir($sd_root.'/templates/copied'),'Invalid clone denied before FTP configuration: '.$bad);sd_unlocked();
 }
 sim_reset();$_POST=$HTTP_POST_VARS=$clone_request;
 $race=isset($argv[1])?$argv[1]:'';
 rc_check(in_array($race,array('','target-directory','target-definition'),true),'Known clone race fixture mode');
 if($race!==''){
  $ftp_setup_hook=function()use($race){
   $GLOBALS['ftp_setup_hook']=null;
   if($race==='target-directory'){mkdir($GLOBALS['sd_root'].'/templates/copied');file_put_contents($GLOBALS['sd_root'].'/templates/copied/theme_info.cfg','independently created target');}
   else{sd_sql("INSERT INTO fixture_themes (themes_id,template_name,style_name,theme_public) VALUES (90,'copied','Independent clone',0)");}
   $GLOBALS['clone_race_before']=sim_snapshot();
  };
 }
 $out='';try{include $sd_source.'admin/xs_clone.php';}catch(StyleDataExit $e){$out=$e->getMessage();}
 if($race!==''){
  rc_check($out==='error'&&sim_snapshot()===$clone_race_before,'Clone must reject a target created after its initial check: '.$race);
  rc_check($race==='target-directory'?file_get_contents($sd_root.'/templates/copied/theme_info.cfg')==='independently created target':!is_dir($sd_root.'/templates/copied'),'Concurrent target contents stay untouched');
  rc_check(phpbb_style_import_read_receipt($peer,'copied')===null,'Rejected clone creates no pending receipt');sd_unlocked();
 }else{
 rc_check($out==='saved'&&count(sd_snapshot()[0])===3&&is_file($sd_root.'/templates/copied/theme_info.cfg'),'Actual clone package controller completes the new recovery protocol');
 $r=phpbb_style_import_read_receipt($peer,'copied');rc_check($r['o']===$HTTP_POST_VARS['recovery_operation']&&$r['s']==='committed','Clone receipt uses form nonce');sd_unlocked();
 rc_check($ftp_params['recovery_operation']===$r['o'],'Clone FTP form preserves nonce');
 }
 echo 'Style recovery ACP: '.$checks." assertions; actual routes and owned package capture\n";
}finally{
 $sd_hook=null;$sd_failure='';$sd_after_commit=null;$db->sql_close();$peer->sql_close();$control->sql_query('DROP DATABASE '.$fixture);$control->sql_close();chdir($sd_previous);
 sim_cleanup(realpath($sd_root),realpath($sd_root));restore_error_handler();
}
PHP;
eval($head.$setup.$body);
