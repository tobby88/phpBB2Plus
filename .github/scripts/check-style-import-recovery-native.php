<?php
if (PHP_SAPI !== 'cli' || getenv('PHPBB_STYLE_IMPORT_RECOVERY_NATIVE') !== '1') { echo "Style import recovery checks require an explicitly enabled disposable database.\n"; return; }
putenv('PHPBB_STYLE_DATA_NATIVE=1'); putenv('PHPBB_STYLE_DATA_PORT=' . (getenv('PHPBB_STYLE_IMPORT_PORT') ?: '3306'));
putenv('PHPBB_STYLE_DATA_PASSWORD=' . (getenv('PHPBB_STYLE_IMPORT_PASSWORD') ?: ''));
$source = file_get_contents(__DIR__ . '/check-style-data-native.php'); $cut = strpos($source, " if (getenv('PHPBB_STYLE_DATA_PROBE')");
if ($cut === false) { throw new RuntimeException('Missing native fixture boundary'); }
$head = str_replace('__DIR__', var_export(__DIR__, true), substr($source, 5, $cut - 5));
$head = str_replace('codex_style_data_', 'codex_style_import_recovery_', $head);
$source = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/check-style-import-native.php'));
$marker = "\$body=<<<'PHP'\n"; $start = strpos($source, $marker); $end = strpos($source, ' $cases=0;$serialized=0;', $start);
if ($start === false || $end === false) { throw new RuntimeException('Missing import fixture setup boundary'); }
$setup = substr($source, $start + strlen($marker), $end - $start - strlen($marker));
$body = <<<'PHP'
 require_once $sd_source . 'includes/functions_style_import_recovery.php';
 require $sd_source . 'includes/functions_style_clone.php';
 $sr_checks = 0;
 function sr_check($ok, $message) { $GLOBALS['sr_checks']++; sd_check($ok, $message); }
 function sr_reset($actor='root', $template='fisubsilversh') {
  sim_reset($actor); sim_archive($template); $GLOBALS['board_config']=array('default_style'=>1);
  $GLOBALS['sr_operation']=bin2hex(phpbb_random_bytes(16)); $GLOBALS['sr_template']=$template;
  file_put_contents($GLOBALS['sd_root'].'/templates/'.$template.'/theme_info.cfg','original config');
  $file=$GLOBALS['sd_root'].'/cache/input.style'; $header=xs_get_style_header($file); $bytes=file_get_contents($file);
  $archive=gzuncompress(substr($bytes,$header['offset']));
  $GLOBALS['sr_start']=array('header'=>$header,'archive'=>$archive,'entries'=>phpbb_style_archive_entries($archive));
 }
 function sr_request($mode='start') { return array('sid'=>'fixture-admin','total'=>'2','import_install_0'=>'1','import_install_1'=>'1','import_default'=>'-1','recovery_action'=>$mode,'recovery_template'=>$GLOBALS['sr_template'],'recovery_operation'=>$GLOBALS['sr_operation']); }
 function sr_run($mode='start', $overrides=array()) {
  global $phpbb_root_path; $phpbb_root_path=$GLOBALS['sd_root'].'/';
  return phpbb_style_import_recovery($GLOBALS['db'],array_merge(sr_request($mode),$overrides),new PhpbbStyleImportLocal($GLOBALS['sd_root'].'/templates'),$GLOBALS['sd_root'].'/cache','native-local',$mode==='start'?$GLOBALS['sr_start']:null);
 }
 function sr_denied($call, $message) { $failed=false; try { $call(); } catch (PhpbbAclException $error) { $failed=true; } sr_check($failed,$message); sd_unlocked(); }
 function sr_pending($call, $message) { $failed=false; try { $call(); } catch (PhpbbAclException $error) { $failed=$error->getMessage()===(isset($GLOBALS['lang']['xs_import_pending'])?$GLOBALS['lang']['xs_import_pending']:'xs_import_pending'); } sr_check($failed,$message); sd_unlocked(); }
 function sr_receipt() { return phpbb_style_import_read_receipt($GLOBALS['peer'],$GLOBALS['sr_template']); }
 function sr_file() { return file_get_contents($GLOBALS['sd_root'].'/templates/'.$GLOBALS['sr_template'].'/theme_info.cfg'); }
 function sr_cleanup($path,$root) {
  if($path!==$root&&strpos($path,$root.DIRECTORY_SEPARATOR)!==0){throw new RuntimeException('Cleanup outside own fixture');}
  if(is_link($path)||is_file($path)){unlink($path);return;}
  foreach(scandir($path) as $name){if($name!=='.'&&$name!=='..'){sr_cleanup($path.DIRECTORY_SEPARATOR.$name,$root);}}rmdir($path);
 }

 foreach(array('root','delegated') as $actor) {
  sr_reset($actor); $before=sd_snapshot(); $result=sr_run('start',array('import_default'=>'1'));
  sr_check($result['state']==='committed'&&!$result['retry']&&$result['installed']===2,'Whole import committed');
  sr_check(sr_receipt()['s']==='committed'&&count(sd_snapshot()[0])===4&&$board_config['default_style']>2,'Metadata, default and receipt committed together');
  sr_check(sr_file()!=='original config'&&!is_file($sd_root.'/cache/themes.cache')&&!is_file($sd_root.'/cache/config_data.cache'),'Files published and caches evicted'); sd_unlocked();
  file_put_contents($sd_root.'/templates/fisubsilversh/theme_info.cfg','later edit');$after=sd_snapshot();
  $result=sr_run('resume');sr_check($result['retry']&&sr_file()==='later edit'&&sd_snapshot()===$after,'Confirmed retry does not overwrite later edits');
  sr_denied(function(){sr_run('rollback');},'Committed import cannot roll back files');

  sr_reset($actor);$before=sd_snapshot();$sd_failure='INSERT INTO fixture_themes ';
  sr_denied(function(){sr_run();},'Registration failure reports failure');$sd_failure='';
  sr_check(sr_receipt()['s']==='prepared'&&sd_snapshot()===$before&&sr_file()!=='original config','Prepared job survives failed registration with exact originals');
  $result=sr_run('rollback');sr_check($result['state']==='rolledback'&&sr_file()==='original config'&&sd_snapshot()===$before,'Explicit authorized rollback restores files without metadata changes');
  sr_check(sr_run('rollback')['retry'],'Rollback retry is idempotent');sr_denied(function(){sr_run('resume');},'Rolled-back operation cannot resume');

  sr_reset($actor);$sd_failure='INSERT INTO fixture_themes ';sr_denied(function(){sr_run();},'Create resumable failed batch');$sd_failure='';
  $result=sr_run('resume');sr_check($result['state']==='committed'&&count(sd_snapshot()[0])===4,'Fresh owned connection resumes failed registration');sd_unlocked();

  foreach(array(1,2) as $commit) {
   sr_reset($actor);$before=sd_snapshot();$seen=0;
   $sd_hook=function($sql)use($commit,&$seen){if($sql==='COMMIT'&&++$seen===$commit){$GLOBALS['sd_failure']='lost-ack';$GLOBALS['sd_hook']=null;}};
   sr_denied(function(){sr_run('start',array('import_default'=>'1'));},'Lost acknowledgement never reports success');$sd_failure='';
   sr_check($seen===$commit&&sr_receipt()['s']===($commit===1?'prepared':'committed'),'Receipt proves actual native commit outcome');
   sr_check($board_config['default_style']===1,'Unconfirmed default not published in request');
   if($commit===1){sr_check(sr_file()==='original config'&&sd_snapshot()===$before,'No destination effects before acknowledged preparation');}
   else{file_put_contents($sd_root.'/templates/fisubsilversh/theme_info.cfg','after uncertain commit');}
   $result=sr_run('resume');sr_check($result['state']==='committed'&&count(sd_snapshot()[0])===4,'Resume resolves lost acknowledgement from receipt');
   if($commit===2){sr_check($result['retry']&&sr_file()==='after uncertain commit','Unknown prior success never restores or republishes files');}
  }
  sr_reset($actor);$before=sd_snapshot();$sd_failure='COMMIT';sr_denied(function(){sr_run();},'Rejected preparation commit');$sd_failure='';
  sr_check(sr_receipt()===null&&sr_file()==='original config'&&sd_snapshot()===$before,'Failed preparation commits no receipt or destination writes');

  foreach(array('missing',$actor==='root'?'role':'grant') as $change) {
   sr_reset($actor);$before=sd_snapshot();$sd_after_commit=function()use($change){$GLOBALS['sd_after_commit']=null;sd_sql(sd_revoke($change));};
   sr_denied(function(){sr_run();},'Authority rechecked after durable preparation');
   sr_check(sr_receipt()['s']==='prepared'&&sr_file()==='original config'&&sd_snapshot()===$before,'Revocation between transactions prevents first file effect');
  }
 }
 sr_reset();$sd_failure='INSERT INTO fixture_themes ';sr_denied(function(){sr_run();},'Pending job for conflict tests');$sd_failure='';$original_operation=$sr_operation;
 sd_sql("INSERT INTO fixture_config VALUES ('override_user_style','0')");$before=sd_snapshot();
 sr_pending(function(){phpbb_style_data_save($GLOBALS['db'],array('sid'=>'fixture-admin','edit'=>'2','edit_style_name'=>'blocked'));},'Real data editor respects pending import');
 sr_pending(function(){phpbb_style_clone($GLOBALS['db'],array('sid'=>'fixture-admin','clone_style'=>'2','clone_name'=>'blocked'));},'Real definition clone respects pending import');
 sr_pending(function(){phpbb_style_install($GLOBALS['db'],array('sid'=>'fixture-admin','install_one'=>'fisubsilversh:0'));},'Real installer respects pending import before reading partial cfg');
 sr_pending(function(){phpbb_style_unregister($GLOBALS['db'],array('sid'=>'fixture-admin','remove_id'=>'2'));},'Unregister respects pending import');
 sr_pending(function(){phpbb_style_remove_files($GLOBALS['db'],array('sid'=>'fixture-admin','remove'=>'fisubsilversh','remove_token'=>str_repeat('a',32)),false);},'File cleanup respects pending import');
 foreach(array('default:2','admin:2:0','override:1','moveusers:2') as $action){sr_pending(function()use($action){phpbb_style_action_save($GLOBALS['db'],array('sid'=>'fixture-admin','style_action'=>$action));},'Actual style action respects pending import: '.$action);}
 sr_pending(function(){phpbb_style_policy_select($GLOBALS['peer'],'2','Board_config_failed');},'Board configuration style selection respects pending import');
 $published=false;sr_pending(function()use(&$published){$p=$GLOBALS['sr_start'];phpbb_style_import($GLOBALS['db'],sr_request(),$p['header'],$p['entries'],$p['archive'],function()use(&$published){$published=true;});},'Legacy import path cannot bypass pending job');
 sr_check(!$published&&sd_snapshot()===$before,'Blocked mutators change neither files nor metadata');
 sd_sql("INSERT INTO fixture_themes (themes_id,template_name,style_name,theme_public) VALUES (99,'legacy','Separate legacy style',0)");
 phpbb_style_data_save($db,array('sid'=>'fixture-admin','edit'=>'99','edit_style_name'=>'Separate changed'));
 $separate=phpbb_acl_rows($peer,'SELECT style_name FROM fixture_themes WHERE themes_id=99');sr_check($separate[0]['style_name']==='Separate changed','Unrelated template remains editable');
 $clone_id=phpbb_style_clone($db,array('sid'=>'fixture-admin','clone_style'=>'99','clone_name'=>'Separate cloned'));sr_check($clone_id>99,'Unrelated template remains cloneable');
 phpbb_style_action_save($db,array('sid'=>'fixture-admin','style_action'=>'admin:99:0'));sr_check(sr_receipt()['s']==='prepared','Unrelated style action does not clear pending job');
 $sr_operation=bin2hex(phpbb_random_bytes(16));sr_denied(function(){sr_run();},'New import cannot supersede pending job');$sr_operation=$original_operation;
 $sr_start['archive']=str_replace('Test','Else',$sr_start['archive']);$sr_start['entries']=phpbb_style_archive_entries($sr_start['archive']);
 sr_denied(function(){sr_run();},'Different valid archive cannot reuse operation');
 file_put_contents($sd_root.'/templates/fisubsilversh/theme_info.cfg','foreign content');
 sr_denied(function(){sr_run('rollback');},'Foreign content prevents rollback');sr_check(sr_file()==='foreign content'&&sr_receipt()['s']==='prepared','Conflicting rollback leaves foreign file and recoverable receipt intact');
 foreach(array('wrong',array('bad')) as $sid){sr_denied(function()use($sid){sr_run('resume',array('sid'=>$sid));},'Recovery requires current POST session');}
 $_SERVER['REQUEST_METHOD']='GET';sr_denied(function(){sr_run('resume');},'GET cannot recover');$_SERVER['REQUEST_METHOD']='POST';

 sr_reset('root','legacy');$cleanup=array('template'=>'legacy','id'=>99,'token'=>str_repeat('a',32),'state'=>'pending');
 sd_sql("INSERT INTO fixture_config VALUES ('".phpbb_style_removal_receipt_key('legacy')."','".$peer->sql_escape(json_encode($cleanup))."')");
 $sd_failure='INSERT INTO fixture_themes ';sr_denied(function(){sr_run();},'Legacy import failure retains preparation');$sd_failure='';
 sr_denied(function(){phpbb_style_remove_files($GLOBALS['db'],array('sid'=>'fixture-admin','remove'=>'legacy','remove_token'=>str_repeat('a',32)),new PhpbbStyleLocalFiles($GLOBALS['sd_root'].'/templates'));},'Old cleanup token retired atomically with prepared intent');
 sr_check(sr_run('rollback')['state']==='rolledback'&&sr_file()==='original config','Legacy rollback preserves original assets');

 sr_reset();$sd_failure='INSERT INTO fixture_themes ';sr_denied(function(){sr_run();},'Pending import before independent default damage');$sd_failure='';sd_sql("DELETE FROM fixture_config WHERE config_name='default_style'");
 sr_check(sr_run('rollback')['state']==='rolledback'&&sr_file()==='original config','Rollback stays available when an unrelated broken default would block normal style edits');
 sr_check(!phpbb_acl_rows($peer,"SELECT * FROM fixture_config WHERE config_name='default_style'"),'Rollback does not invent configuration to hide independent damage');
 sr_reset();$max=array('v'=>1,'t'=>str_repeat('a',30),'o'=>str_repeat('b',32),'s'=>'rolledback','h'=>str_repeat('c',64),'a'=>str_repeat('d',64));
 sr_check(strlen(json_encode($max))<=255&&phpbb_style_import_receipt(json_encode($max))===$max,'Worst-case receipt fits existing canonical config schema');
 $short=$max;$short['t']='fisubsilversh';$duplicate='{"v":1,"v":1,'.substr(json_encode($short),7);
 sr_check(strlen($duplicate)<=255&&json_decode($duplicate,true)===$short&&phpbb_style_import_receipt($duplicate)===false,'Duplicate receipt JSON keys rejected independently of size/shape');
 $writer=new PhpbbStyleImportRecoveryWriter($db);$attempted=false;
 try{phpbb_style_removal_start($writer,false);phpbb_style_import_store_receipt($writer,$max);$attempted=true;$writer->sql_query('COMMIT');}
 finally{phpbb_style_actions_finish($writer,$attempted,$sd_root.'/');}
 sr_check(phpbb_style_import_read_receipt($peer,str_repeat('a',30))===$max,'Worst-case receipt round-trips native utf8mb4 config');
 foreach(array('published','staged') as $interrupt){foreach(array('resume','rollback') as $recovery){
  sr_reset();$before=sd_snapshot();$sd_failure='lost-ack';sr_denied(function(){sr_run();},'Persist preparation before interrupted child');$sd_failure='';
  $command=escapeshellarg(PHP_BINARY).' -n -d '.escapeshellarg('extension_dir='.ini_get('extension_dir')).' -d extension=php_mysqli.dll -d extension=php_openssl.dll ';
  // Linux CI already configures extensions in the CLI ini; OpenSSL may be
  // built in there and must not be loaded a second time as a shared module.
  if(DIRECTORY_SEPARATOR!=='\\'){$command=escapeshellarg(PHP_BINARY).' ';}
  $command.=escapeshellarg(dirname($sd_source).'/.github/scripts/run-native-style-import-interruption.php').' '.escapeshellarg($fixture).' '.escapeshellarg($sd_root).' '.escapeshellarg($sr_operation).' '.escapeshellarg($interrupt);
  $process=proc_open($command,array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes,null,null,array('bypass_shell'=>true));sr_check(is_resource($process),'Native interruption child started');fclose($pipes[0]);
  $output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
  sr_check($exit===73&&$output===''&&$errors==='','Child truly exits inside file publication: '.$output.$errors);sd_unlocked();
  sr_check(sr_receipt()['s']==='prepared'&&sd_snapshot()===$before,'Process interruption preserves committed preparation and rolls back metadata transaction');
  sr_check(($interrupt==='staged')===(sr_file()==='original config'),'Interruption reached requested filesystem boundary');
  $result=sr_run($recovery);sr_check($result['state']===($recovery==='resume'?'committed':'rolledback'),'Fresh owner resolves real process interruption');
  sr_check($recovery==='resume'?count(sd_snapshot()[0])===4:(sd_snapshot()===$before&&sr_file()==='original config'),'Recovery produces matching filesystem and SQL state');
  sr_check(!glob($sd_root.'/templates/fisubsilversh/xs_import_*.tmp'),'Interrupted operation stages cleaned after recovery');
 }}
 echo 'Style import recovery native: '.$sr_checks." assertions; real InnoDB receipts and commit ambiguity\n";
}finally{
 $sd_hook=null;$sd_failure='';$sd_after_commit=null;$db->sql_close();$peer->sql_close();$control->sql_query('DROP DATABASE '.$fixture);$control->sql_close();chdir($sd_previous);
 sr_cleanup(realpath($sd_root),realpath($sd_root));restore_error_handler();
}
PHP;
eval($head . $setup . $body);
