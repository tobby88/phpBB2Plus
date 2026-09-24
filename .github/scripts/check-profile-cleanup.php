<?php
$cli=dirname(dirname(__DIR__)).'/update/purge_profile_fields.php';$source=file_get_contents($cli);
$a=strpos($source,'function phpbb_profile_cleanup_arguments(');$b=strpos($source,'$worker = null;', $a);
if($a===false||$b<=$a||eval(substr($source,$a,$b-$a))===false){throw new RuntimeException('Cleanup argument parser unavailable');}
function pfc_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
$base=array('--kind=retired','--operation='.str_repeat('a',64));
$apply=array_merge($base,array('--apply','--confirm='.str_repeat('b',64),'--backup-confirmed','--maintenance-confirmed','--erase-confirmed'));
pfc_check(phpbb_profile_cleanup_arguments(array())===array(),'Default is read-only inventory');
pfc_check(!isset(phpbb_profile_cleanup_arguments($base)['apply']),'Selected preview is read-only');
pfc_check(phpbb_profile_cleanup_arguments($apply)['apply']===true,'Exact confirmed apply accepted');
foreach(array(array('--apply'),array('--unknown'),array('--apply=no'),array('--help','--help'),array('--kind=retired'),array('--operation='.str_repeat('A',64)),array('--kind=all','--operation='.str_repeat('a',64)),array('--confirm='.str_repeat('a',64))) as $invalid){
 $denied=false;try{phpbb_profile_cleanup_arguments($invalid);}catch(RuntimeException $e){$denied=true;}pfc_check($denied,'Unsafe CLI arguments rejected');
}
foreach(array('--confirm='.str_repeat('b',64),'--backup-confirmed','--maintenance-confirmed','--erase-confirmed') as $missing){$denied=false;try{phpbb_profile_cleanup_arguments(array_values(array_diff($apply,array($missing))));}catch(RuntimeException $e){$denied=true;}pfc_check($denied,'Every apply confirmation required');}
echo "Profile cleanup: read-only defaults and strict explicit CLI confirmations passed.\n";
if(PHP_SAPI!=='cli'||getenv('PHPBB_PROFILE_DEFINITION_NATIVE')!=='1'){return;}
$source=file_get_contents(__DIR__.'/check-profile-definition-storage.php');$cut=strpos($source," foreach(array('root','edit','add') as \$actor)");
pfc_check($cut!==false,'Cleanup native fixture setup');$head=str_replace('__DIR__',var_export(__DIR__,true),substr($source,5,$cut-5));$head=str_replace('codex_profile_definition_','codex_profile_cleanup_',$head);
$head=str_replace('return new DefinitionConnectionFixture(parent::sql_dedicated_connection());','return new CleanupConnectionFixture(parent::sql_dedicated_connection());',$head,$replaced);pfc_check($replaced===1,'Cleanup fixture diagnostics adapter');
$tail= <<<'PHP'
 class CleanupConnectionFixture extends DefinitionConnectionFixture {
  function sql_query($sql,$tx=false){$r=parent::sql_query($sql,$tx);
   if(!$r&&$GLOBALS['pds_failure']===''){$GLOBALS['pfc_driver_error']=array($sql,$this->inner->sql_error());}
   if($r&&$sql==='SHOW WARNINGS'){$warnings=$this->inner->sql_fetchrowset($r);if($warnings){$GLOBALS['pfc_driver_error']=$warnings;$this->inner->sql_rowseek(0,$r);}}
   return $r;
  }
 }
 require_once $ats_source.'includes/functions_profile_definition_retirement.php';
 require_once dirname($ats_source).'/update/profile_field_cleanup.php';
 function pfc_reset(){
  $GLOBALS['pds_hook']=null;$GLOBALS['pds_failure']='';pds_sql('DROP TRIGGER IF EXISTS fixture_cleanup_trigger');
  foreach(pds_rows('SHOW COLUMNS FROM fixture_users') as $c){if($c['Field']==='user_notes'||preg_match('/^cpf_[a-f0-9]{32}$/D',$c['Field'])){pds_sql('ALTER TABLE fixture_users DROP COLUMN `'.$c['Field'].'`');}}
  pds_sql("ALTER TABLE fixture_users ADD user_notes VARCHAR(255) DEFAULT 'old default'");return pds_reset();
 }
 function pfc_retire($id=1){$op=bin2hex(phpbb_random_bytes(32));$row=pds_rows('SELECT * FROM fixture_profile_fields WHERE field_id='.(int)$id)[0];$w=new PhpbbProfileDefinitionRetirement($GLOBALS['main'],array('sid'=>'exact-session'));$w->retire($id,phpbb_profile_definition_revision($row),$op);return $op;}
 function pfc_plan($kind,$op){$w=null;try{$w=new PhpbbProfileFieldCleanup($GLOBALS['main']);return $w->plan($kind,$op);}finally{if($w){$w->release();}}}
 function pfc_apply($kind,$op,$confirmation,$flags=array(true,true,true)){$w=null;$GLOBALS['pfc_driver_error']=array();try{$w=new PhpbbProfileFieldCleanup($GLOBALS['main']);return $w->apply($kind,$op,$confirmation,$flags[0],$flags[1],$flags[2]);}catch(Exception $e){$GLOBALS['pfc_error']=$e->getMessage().' '.json_encode($GLOBALS['pfc_driver_error']);return false;}finally{if($w){$w->release();}}}
 function pfc_present($column){return (bool)pds_rows("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='fixture_users' AND COLUMN_NAME='".$GLOBALS['peer']->sql_escape($column)."'");}
 function pfc_snapshot(){return array(pds_rows('SELECT * FROM fixture_users ORDER BY user_id'),pds_rows('SELECT * FROM fixture_profile_fields ORDER BY field_id'),pds_rows('SELECT * FROM fixture_profile_field_actions ORDER BY operation_key'),pds_rows('SELECT * FROM fixture_profile_field_jobs ORDER BY operation_key'));}
 function pfc_staged($missing=false){$op=bin2hex(phpbb_random_bytes(32));$GLOBALS['pds_failure']=$missing?'ddl':'publish';ats_check(pds_create($op,pds_values())===false,'Interrupted actual creation');$GLOBALS['pds_failure']='';$GLOBALS['pds_commit_count']=0;return $op;}
 $cases=0;
 pfc_reset();pds_sql("UPDATE fixture_profile_fields SET text_field_default='Private default'");$old=pfc_retire();$w=new PhpbbProfileDefinitionRetirement($main,array('sid'=>'exact-session'));$w->restore($old);$op=pfc_retire();
 $before=pfc_snapshot();$plan=pfc_plan('retired',$op);ats_check(pfc_snapshot()===$before,'Preview never modifies data');
 $endpoint=$main->server;$main->server='localhost:'.$port;try{$otherEndpoint=pfc_plan('retired',$op);}finally{$main->server=$endpoint;}
 ats_check($otherEndpoint['confirmation']!==$plan['confirmation'],'Preview binds the configured endpoint as well as database/receipt');
 $out=pfc_apply('retired',$op,$plan['confirmation']);ats_check($out&&$out['state']==='purged'&&!pfc_present('user_notes'),'Retired values physically erased: '.(isset($pfc_error)?$pfc_error:''));
 foreach($before[0] as &$row){unset($row['user_notes']);}unset($row);ats_check(pfc_snapshot()[0]===$before[0],'Only selected profile storage removed');
 foreach(pds_rows('SELECT definition_snapshot FROM fixture_profile_field_actions') as $r){ats_check($r['definition_snapshot']==='','Old/default snapshots erased, tombstones retained');}
 $pds_queries=array();ats_check(pfc_apply('retired',$op,$plan['confirmation'])!==false,'Completed cleanup retry');foreach($pds_queries as $sql){ats_check(!preg_match('/^(UPDATE|DELETE|ALTER|INSERT) /',$sql),'Completed retry makes no writes');}
 foreach(array($old,$op) as $restore){$denied=false;try{$w=new PhpbbProfileDefinitionRetirement($main,array('sid'=>'exact-session'));$w->restore($restore);}catch(PhpbbAclException $e){$denied=true;}ats_check($denied,'Purged current and stale restored forms cannot restore');}
 pds_sql("ALTER TABLE fixture_users ADD user_notes VARCHAR(255) DEFAULT 'Independent replacement'");$before=pfc_snapshot();ats_check(!pfc_apply('retired',$op,$plan['confirmation'])&&pfc_snapshot()===$before,'Old successful receipt never deletes a recreated column');$cases++;
 foreach(array('backup','maintenance','erase','confirmation','core','snapshot','physical','active','index','trigger','missing','other-receipt','legacy-engine','legacy-column') as $case){
  pfc_reset();$op=pfc_retire();$plan=pfc_plan('retired',$op);$flags=array(true,true,true);$confirmation=$plan['confirmation'];
  if($case==='backup'){$flags[0]=false;}elseif($case==='maintenance'){$flags[1]=false;}elseif($case==='erase'){$flags[2]=false;}elseif($case==='confirmation'){$confirmation=str_repeat('0',64);}
  elseif($case==='core'){pds_sql("UPDATE fixture_profile_field_actions SET field_column='user_password'");}
  elseif($case==='snapshot'){pds_sql("UPDATE fixture_profile_field_actions SET definition_snapshot='{}'");}
  elseif($case==='physical'){pds_sql("ALTER TABLE fixture_users MODIFY user_notes VARCHAR(255) DEFAULT 'changed'");}
  elseif($case==='active'){pds_insert('fixture_profile_fields',array_merge(pds_values(),array('field_id'=>9,'field_column'=>'user_notes')));}
  elseif($case==='index'){pds_sql('CREATE INDEX fixture_custom_index ON fixture_users (username,user_notes)');}
  elseif($case==='trigger'){pds_sql('CREATE TRIGGER fixture_cleanup_trigger BEFORE UPDATE ON fixture_users FOR EACH ROW SET NEW.user_posts=OLD.user_posts');}
  elseif($case==='missing'){pds_sql('ALTER TABLE fixture_users DROP COLUMN user_notes');}
  elseif($case==='legacy-engine'){pds_sql('ALTER TABLE fixture_profile_field_actions ENGINE=MyISAM');}
  elseif($case==='legacy-column'){pds_sql('ALTER TABLE fixture_profile_field_actions MODIFY session_hash CHAR(64) CHARACTER SET latin1 NOT NULL');}
  else{$other=pds_rows('SELECT * FROM fixture_profile_field_actions')[0];$other['operation_key']=bin2hex(phpbb_random_bytes(32));pds_insert('fixture_profile_field_actions',$other);}
  $before=pfc_snapshot();ats_check(!pfc_apply('retired',$op,$confirmation,$flags)&&pfc_snapshot()===$before,'Unsafe purge writes nothing '.$case);
  if($case==='legacy-engine'){pds_sql('ALTER TABLE fixture_profile_field_actions ENGINE=InnoDB ROW_FORMAT=DYNAMIC');}
  if($case==='legacy-column'){pds_sql('ALTER TABLE fixture_profile_field_actions MODIFY session_hash CHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');}$cases++;
 }
 foreach(array('stage-commit','stage-ack','ddl','ddl-ack','publish-commit','publish-ack','action-update','final-write','kill') as $failure){
  pfc_reset();$op=pfc_retire();$plan=pfc_plan('retired',$op);$pds_commit_count=0;$pds_failure=$failure;$seen=0;
  if($failure==='final-write'){$pds_failure='';$pds_hook=function($sql)use(&$seen){if(strpos($sql,'UPDATE fixture_profile_field_actions SET action_state=')===0&&++$seen===2){$GLOBALS['pds_failure']='action-update';}};}
  if($failure==='kill'){$pds_failure='';$pds_hook=function($sql,$connection){if(strpos($sql,'ALTER TABLE `fixture_users` DROP')===0){$GLOBALS['pds_hook']=null;pds_sql('KILL CONNECTION '.(int)$connection->db_connect_id->thread_id);}};}
  ats_check(!pfc_apply('retired',$op,$plan['confirmation']),'Interrupted cleanup not confirmed '.$failure);$pds_failure='';$pds_hook=null;
  $present=pfc_present('user_notes');ats_check($present===in_array($failure,array('stage-commit','stage-ack','ddl','action-update','kill'),true),'Correct durable phase '.$failure);
  $again=pfc_plan('retired',$op);ats_check($again['confirmation']===$plan['confirmation'],'Stable same-operation retry token '.$failure);
  $retry_result=pfc_apply('retired',$op,$plan['confirmation']);ats_check($retry_result!==false&&!pfc_present('user_notes'),'Recover uncertain erasure '.$failure.' '.(isset($pfc_error)?$pfc_error:''));$cases++;
 }
 foreach(array('success','missing','data','empty','zero','marker','default','indexed','published','receipt','ddl-ack','final-ack') as $case){
  pfc_reset();$op=pfc_staged($case==='missing');$column='cpf_'.substr($op,0,32);$plan=pfc_plan('staged',$op);
  if(in_array($case,array('data','empty','zero'),true)){pds_sql('UPDATE fixture_users SET `'.$column."`='".($case==='data'?'Independent data':($case==='zero'?'0':''))."' WHERE user_id=7");}
  elseif($case==='marker'){pds_sql('ALTER TABLE fixture_users MODIFY `'.$column."` MEDIUMTEXT NULL COMMENT 'other'");}
  elseif($case==='default'){pds_sql('ALTER TABLE fixture_users ALTER COLUMN `'.$column."` SET DEFAULT 'new'");}
  elseif($case==='indexed'){pds_sql('CREATE INDEX fixture_staged_index ON fixture_users (`'.$column.'`(16))');}
  elseif($case==='published'){pds_sql("UPDATE fixture_profile_field_jobs SET field_id=99,job_state='published' WHERE operation_key='$op'");}
  elseif($case==='receipt'){$pds_failure='receipt';}elseif($case==='ddl-ack'){$pds_failure='ddl-ack';}elseif($case==='final-ack'){$pds_failure='publish-ack';}
  $before=pfc_snapshot();$out=pfc_apply('staged',$op,$plan['confirmation']);$success=in_array($case,array('success','missing'),true);ats_check((bool)$out===$success,'Staged cleanup result '.$case.' '.(isset($pfc_error)?$pfc_error:''));
  if($success){ats_check(!pfc_present($column),'Only owned empty staged column removed');$again=pfc_plan('staged',$op);ats_check($again['state']==='purged','Staged tombstone retained');ats_check(!pds_create($op,pds_values()),'Old creation form cannot republish purged staging');}
  elseif(in_array($case,array('ddl-ack','final-ack'),true)){$pds_failure='';ats_check(pfc_apply('staged',$op,$plan['confirmation'])!==false,'Retry uncertain staged cleanup');}
  else{ats_check(pfc_snapshot()===$before&&pfc_present($column),'Rejected staging preserves values and receipt '.$case);}
  $pds_failure='';$cases++;
 }
 pfc_reset();$create=bin2hex(phpbb_random_bytes(32));$id=pds_create($create,pds_values());ats_check($id!==false,'Published field before cleanup');$op=pfc_retire($id);$plan=pfc_plan('retired',$op);ats_check(pfc_apply('retired',$op,$plan['confirmation'])!==false,'Retired created field cleanup');
 ats_check(pds_rows("SELECT job_state FROM fixture_profile_field_jobs WHERE operation_key='$create'")[0]['job_state']==='purged'&&!pds_create($create,pds_values()),'Published creation receipt retired with column');$cases++;
 // Real database lock prevents a second worker; release must not steal it.
 pfc_reset();$op=pfc_retire();$lock=new attach_mutation_lock($main);$before=pfc_snapshot();$denied=false;try{$w=new PhpbbProfileFieldCleanup($main);}catch(RuntimeException $e){$denied=true;}finally{$lock->release();}ats_check($denied&&pfc_snapshot()===$before,'Busy cleanup makes no changes');$cases++;
 // Recheck references after intent commit and before DROP, not just preview.
 pfc_reset();$op=pfc_retire();$plan=pfc_plan('retired',$op);$pds_commit_count=0;$inserted=false;
 $pds_hook=function($sql)use(&$inserted){if($GLOBALS['pds_commit_count']===1&&strpos($sql,'SELECT * FROM fixture_profile_fields')===0){$GLOBALS['pds_hook']=null;pds_insert('fixture_profile_fields',array_merge(pds_values(),array('field_id'=>8,'field_column'=>'user_notes')));$inserted=true;}};
 $reference_result=pfc_apply('retired',$op,$plan['confirmation']);
 ats_check(!$reference_result&&$inserted&&pfc_present('user_notes'),'Revalidation protects changed references after intent '.(isset($pfc_error)?$pfc_error:''));$pds_hook=null;$cases++;
 // Repeat interrupted DROP/retry with fresh receipts to catch timing-sensitive
 // metadata/driver failures; do not silently retry or count a refusal as success.
 $repeats=getenv('PHPBB_PROFILE_CLEANUP_REPEATS')?:'20';ats_check(preg_match('/^[0-9]{1,3}$/D',$repeats)&&(int)$repeats>=1&&(int)$repeats<=500,'Bounded cleanup repetition count');
 for($repeat=0;$repeat<(int)$repeats;$repeat++){
  pfc_reset();$op=pfc_retire();$plan=pfc_plan('retired',$op);$pds_failure='ddl';ats_check(!pfc_apply('retired',$op,$plan['confirmation']),'Injected DROP failure');$pds_failure='';
  $retry_result=pfc_apply('retired',$op,$plan['confirmation']);ats_check($retry_result!==false&&!pfc_present('user_notes'),'Repeated DROP recovery '.$repeat.' '.(isset($pfc_error)?$pfc_error:''));$cases++;
 }
 // Exercise the distributed CLI itself with a generated disposable config,
 // never a real forum configuration or credentials. Use its own constants and
 // preview token, including its exact database/prefix lock namespace.
 function pfc_cli($config,$arguments){
  $command=escapeshellarg(PHP_BINARY);
  if(PHP_OS==='WINNT'){
   // The portable Windows fixtures deliberately start without a php.ini.
   $command.=' -n -d '.escapeshellarg('extension_dir='.ini_get('extension_dir')).' -d extension=php_mysqli.dll';
  }else{
   // Preserve the configured CLI module stack. Linux packages can make both
   // mysqlnd and (before PHP 8) JSON separate modules; -n would discard these
   // prerequisites even though the same PHP runtime passed the parent test.
   $ini=php_ini_loaded_file();if($ini!==false){$command.=' -c '.escapeshellarg($ini);}
  }
  $command.=' '.escapeshellarg(dirname($GLOBALS['ats_source']).'/update/purge_profile_fields.php').' '.escapeshellarg('--config='.$config);
  foreach($arguments as $argument){$command.=' '.escapeshellarg($argument);}
  $pipes=array();$process=proc_open($command,array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes,null,null,array('bypass_shell'=>true));ats_check(is_resource($process),'Start real cleanup CLI');fclose($pipes[0]);
  $stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);
  return array($code,$stdout,$stderr,json_decode($stdout,true));
 }
 pfc_reset();$op=pfc_retire();$config=tempnam(sys_get_temp_dir(),'pfc_owned_');ats_check($config!==false,'Owned CLI config path');
 try{
  $content='<?php ';foreach(array('dbhost'=>$host,'dbuser'=>'root','dbpasswd'=>$password,'dbname'=>$fixture,'table_prefix'=>'fixture_','dbms'=>'mysqli') as $key=>$value){$content.='$'.$key.'='.var_export($value,true).';';}
  ats_check(file_put_contents($config,$content)===strlen($content),'Owned CLI config created');$before=pfc_snapshot();
  $list=pfc_cli($config,array());ats_check($list[0]===0&&is_array($list[3])&&$list[3]['mode']==='read-only inventory'&&$list[3]['receipts'][0]['field_id']===1,'Real CLI inventory matches ACP field ID '.$list[1].$list[2]);
  $args=array('--kind=retired','--operation='.$op);$preview=pfc_cli($config,$args);ats_check($preview[0]===0&&isset($preview[3]['plan']['confirmation'])&&pfc_snapshot()===$before,'Real CLI preview is read-only '.$preview[1].$preview[2]);
  $incomplete=pfc_cli($config,array_merge($args,array('--apply')));ats_check($incomplete[0]===2&&pfc_snapshot()===$before,'CLI requires confirmations before connecting/writing');
  $args=array_merge($args,array('--apply','--confirm='.$preview[3]['plan']['confirmation'],'--backup-confirmed','--maintenance-confirmed','--erase-confirmed'));
  $done=pfc_cli($config,$args);ats_check($done[0]===0&&$done[3]['plan']['state']==='purged'&&!pfc_present('user_notes'),'Real CLI erasure '.$done[1].$done[2]);
  $stable=pfc_snapshot();$retry=pfc_cli($config,$args);ats_check($retry[0]===0&&pfc_snapshot()===$stable,'Real CLI completed retry is read-only');
  $cases+=5;
 }finally{ats_check(unlink($config),'Remove only owned temporary CLI config');}
 echo 'Native profile cleanup: '.$cases." physical erasure, ownership, stale replay, failure/ACK and offline intent cases passed.\n";
}finally{$pds_hook=null;$main->sql_close();$peer->sql_close();ats_check($control->sql_query('DROP DATABASE '.$fixture),'Remove owned cleanup schema');$control->sql_close();restore_error_handler();}
PHP;
if(eval($head.$tail)===false){throw new RuntimeException('Native cleanup fixture did not execute');}
