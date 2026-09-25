<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_STYLE_REMOVAL_NATIVE')!=='1'){echo "Style removal checks require an explicitly enabled disposable database.\n";return;}
putenv('PHPBB_STYLE_DATA_NATIVE=1');putenv('PHPBB_STYLE_DATA_PORT='.(getenv('PHPBB_STYLE_REMOVAL_PORT')?:'3306'));putenv('PHPBB_STYLE_DATA_PASSWORD='.(getenv('PHPBB_STYLE_REMOVAL_PASSWORD')?:''));
$fixture_source=file_get_contents(__DIR__.'/check-style-data-native.php');$cut=strpos($fixture_source," if (getenv('PHPBB_STYLE_DATA_PROBE')");
if($cut===false){throw new RuntimeException('Fixture boundary missing');}
$head=str_replace('__DIR__',var_export(__DIR__,true),substr($fixture_source,5,$cut-5));$head=str_replace('codex_style_data_','codex_style_removal_',$head);
$body=<<<'PHP'
 define('XS_TPL_PATH','');define('XS_FTP_LOCAL','fixture-local');$xs_row_class=array('row1','row2');
 class RemovalTemplate extends StyleDataTemplate {var $blocks=array();function assign_block_vars($name,$data){$this->blocks[$name][]=$data;}function assign_vars($data){}function set_filenames($data){}function pparse($name){throw new StyleDataExit('rendered');}}
 $template=new RemovalTemplate();
 function get_ftp_config($action,$params,$allow){$GLOBALS['sr_ftp_calls']++;if(isset($GLOBALS['sr_before_ftp'])&&is_callable($GLOBALS['sr_before_ftp'])){call_user_func($GLOBALS['sr_before_ftp']);}return true;}
 function xs_ftp_connect($action,$params,$allow){$GLOBALS['ftp']=XS_FTP_LOCAL;}
 foreach(array('functions_style_removal.php','functions_style_files.php') as $file){file_put_contents($sd_root.'/includes/'.$file,'<?php require_once '.var_export($sd_source.'includes/'.$file,true).';');$sd_files[]=$sd_root.'/includes/'.$file;}
 require_once $sd_source.'includes/functions_style_removal.php';require_once $sd_source.'includes/functions_style_files.php';
 sd_check(preg_match('/CREATE TABLE phpbb_config\s*\([\s\S]*?;/',$schema,$m)===1,'Canonical config');sd_sql(str_replace('phpbb_config',CONFIG_TABLE,$m[0]));
 sd_check(preg_match('/^\s*user_style\s+([^,]+),/m',$schema,$m)===1,'Canonical reference column');sd_sql('ALTER TABLE fixture_users ADD user_style '.$m[1]);
 mkdir($sd_root.'/templates',0700);$sd_dirs[]=$sd_root.'/templates';mkdir($sd_root.'/templates/fisubsilversh',0700);$sd_dirs[]=$sd_root.'/templates/fisubsilversh';
 file_put_contents($sd_root.'/templates/fisubsilversh/keep.txt','protected');$sd_files[]=$sd_root.'/templates/fisubsilversh/keep.txt';
 function sr_snapshot(){return array(sd_snapshot(),phpbb_acl_rows($GLOBALS['peer'],'SELECT user_id,user_style FROM fixture_users ORDER BY user_id'),phpbb_acl_rows($GLOBALS['peer'],'SELECT * FROM fixture_config ORDER BY config_name'));}
 function sr_reset($actor='root'){
  global $userdata,$sd_hook,$sd_failure,$sd_after_commit,$sd_queries,$board_config,$template,$sr_ftp_calls;
  $sd_hook=null;$sd_failure='';$sd_after_commit=null;$sd_queries=array();$template->blocks=array();$sr_ftp_calls=0;$GLOBALS['sr_before_ftp']=null;
  foreach(array(THEMES_TABLE,THEMES_NAME_TABLE,CONFIG_TABLE,USERS_TABLE,SESSIONS_TABLE,JR_ADMIN_TABLE) as $table){sd_sql('DELETE FROM '.$table);}
  sd_sql("INSERT INTO fixture_themes (themes_id,template_name,style_name,theme_public) VALUES (1,'fisubsilversh','Default',1),(2,'fisubsilversh','Shared',1),(3,'legacy','Unused template',0)");
  sd_sql("INSERT INTO fixture_names (themes_id,tr_color1_name) VALUES (1,'Keep'),(2,'Other'),(3,'Größe 😀')");
  sd_sql("INSERT INTO fixture_config VALUES ('default_style','1'),('override_user_style','0'),('xs_style_fisubsilversh','preserve'),('xs_style_legacy','old settings'),('unrelated','keep')");
  sd_sql('INSERT INTO fixture_users VALUES (1,'.($actor==='root'?1:0).',1,3),(2,0,1,3),(3,0,1,2),(-1,0,0,1)');
  sd_sql("INSERT INTO fixture_sessions VALUES ('fixture-admin',1,1,1)");
  if($actor==='delegated'){$hash=array_search('xs_frameset.php',jr_admin_authorization_routes(),true);sd_sql("INSERT INTO fixture_jr VALUES (1,'".$hash."')");}
  $userdata=array('user_id'=>1,'session_id'=>'fixture-admin','session_admin'=>1,'session_logged_in'=>1,'user_level'=>$actor==='root'?1:0);$board_config=array('default_style'=>1);$_SERVER['REQUEST_METHOD']='POST';
  foreach(array('themes.cache','config_data.cache') as $file){file_put_contents($GLOBALS['sd_root'].'/cache/'.$file,'old');}
 }
 function sr_run($request){global $db,$phpbb_root_path,$phpEx,$template,$lang,$userdata,$board_config,$HTTP_POST_VARS,$HTTP_GET_VARS,$xs_row_class,$ftp;
  $_POST=$HTTP_POST_VARS=array_merge(array('sid'=>'fixture-admin'),$request);$HTTP_GET_VARS=array();
  try{include $GLOBALS['sd_source'].'admin/xs_uninstall.php';throw new RuntimeException('Controller did not stop');}catch(StyleDataExit $e){return $e->getMessage();}
 }
 function sr_boundary($sql){return preg_match('/^(UPDATE fixture_users |DELETE FROM fixture_|SELECT .*FOR UPDATE)/',$sql)||strpos($sql,' LOCK IN SHARE MODE')!==false||$sql==='COMMIT';}
 $cases=0;$serialized=0;
 foreach(getenv('PHPBB_STYLE_REMOVAL_EXTRA_ONLY')==='1'?array():array('root','delegated') as $actor){
  sr_reset($actor);sd_check(sr_run(array('remove_id'=>'3'))==='rendered','Actual unregister succeeds');$after=sr_snapshot();
  sd_check(count($after[0][0])===2&&count($after[0][1])===2&&$after[1][1]['user_style']===null&&$after[1][2]['user_style']===null&&$after[1][3]['user_style']==='2','Delete own labels and clear only affected preferences');
  sd_check(count($after[2])===5&&!is_file($sd_root.'/cache/themes.cache')&&!is_file($sd_root.'/cache/config_data.cache'),'Remove unused config, store receipt and invalidate caches');sd_unlocked();$cases++;
  $boundaries=array_values(array_filter($sd_queries,'sr_boundary'));
  sr_reset($actor);sd_sql("UPDATE fixture_config SET config_value='2' WHERE config_name='default_style'");$before=sr_snapshot();sd_check(sr_run(array('remove_id'=>'2'))==='error'&&sr_snapshot()===$before,'Current DB default wins over stale bootstrap');sd_unlocked();$cases++;
  sr_reset($actor);$before=sr_snapshot();sd_check(sr_run(array('remove_id'=>'2','remove_files'=>'1'))==='error'&&sr_snapshot()===$before&&$sr_ftp_calls===0,'Shared/reserved files rejected before DB or FTP changes');sd_check(is_file($sd_root.'/templates/fisubsilversh/keep.txt'),'Default fixture files intact');sd_unlocked();$cases++;
  sr_reset($actor);sd_check(sr_run(array('remove_id'=>'2','keep_config'=>'0'))==='rendered','Shared style may be unregistered without files');$rows=phpbb_acl_rows($peer,"SELECT config_value FROM fixture_config WHERE config_name='xs_style_fisubsilversh'");sd_check($rows[0]['config_value']==='preserve','Shared settings preserved despite delete-config flag');$cases++;
  foreach(array('inactive','missing','admin-off','foreign','logout','case',$actor==='root'?'role':'grant') as $kind){sr_reset($actor);sd_sql(sd_revoke($kind));$before=sr_snapshot();sd_check(sr_run(array('remove_id'=>'3'))==='error'&&sr_snapshot()===$before,'Entry revocation denies removal');sd_unlocked();$cases++;}
  foreach(array('missing',$actor==='root'?'role':'grant') as $kind){for($boundary=1;$boundary<=count($boundaries);$boundary++){
   sr_reset($actor);$before=sr_snapshot();$seen=0;$reached=false;$blocked=false;
   $sd_hook=function($sql)use($boundary,$kind,&$seen,&$reached,&$blocked){if(!sr_boundary($sql)||++$seen!==$boundary){return;}$GLOBALS['sd_hook']=null;$reached=true;if(!$GLOBALS['peer']->sql_query(sd_revoke($kind))){$e=$GLOBALS['peer']->sql_error();sd_check((int)$e['code']===1205,'Only native row-lock timeout');$blocked=true;}};
   $out=sr_run(array('remove_id'=>'3'));sd_check($reached,'Every removal boundary exercised');
   if($blocked){sd_check($out==='rendered','Removal serializes before revocation');sd_sql(sd_revoke($kind));$serialized++;}else{sd_check($out==='error'&&sr_snapshot()===$before,'Revocation rolls back whole unregister '.$actor.' '.$kind.' #'.$boundary.' outcome='.$out);}
   sd_unlocked();$cases++;
  }}
  foreach(array('UPDATE fixture_users ','DELETE FROM fixture_names ','DELETE FROM fixture_themes ','DELETE FROM fixture_config ','COMMIT','lost-ack') as $failure){
   sr_reset($actor);$before=sr_snapshot();$sd_failure=$failure;$out=sr_run(array('remove_id'=>'3'));sd_check($out==='error','Write/commit uncertainty reported');
   sd_check($failure==='lost-ack'?count(sr_snapshot()[0][0])===2:sr_snapshot()===$before,'Whole mutation commits or rolls back');sd_check(!is_file($sd_root.'/cache/themes.cache')&&!is_file($sd_root.'/cache/config_data.cache'),'Caches cleared after failed/unknown mutation');sd_unlocked();$cases++;
  }
  echo $actor." actual unregister boundaries passed\n";
 }
 sr_reset();$before=sr_snapshot();$blocked=false;$sd_hook=function($sql)use(&$blocked){if(strpos($sql,'DELETE FROM fixture_themes ')!==0){return;}$GLOBALS['sd_hook']=null;$r=$GLOBALS['peer']->sql_query("INSERT INTO fixture_themes (themes_id,template_name,style_name,theme_public) VALUES (4,'legacy','Concurrent',0)");if(!$r){$e=$GLOBALS['peer']->sql_error();sd_check((int)$e['code']===1205,'Actual next-key lock prevents installer insertion');$blocked=true;}};sd_check(sr_run(array('remove_id'=>'3'))==='rendered'&&$blocked,'Theme gap locked against noncooperating DB insert');$cases++;
 foreach(array('config_data.cache','themes.cache') as $cache){sr_reset();unlink($sd_root.'/cache/'.$cache);mkdir($sd_root.'/cache/'.$cache);try{sd_check(sr_run(array('remove_id'=>'3'))==='error','Cache failure is not success');sd_unlocked();}finally{rmdir($sd_root.'/cache/'.$cache);}$cases++;}
 sr_reset();mkdir($sd_root.'/templates/legacy',0700);file_put_contents($sd_root.'/templates/legacy/fixture.txt','fixture');
 sd_check(sr_run(array('remove_id'=>'3','remove_files'=>'1'))==='rendered'&&!file_exists($sd_root.'/templates/legacy'),'Actual controller removes unregistered local directory');sd_unlocked();$cases++;
 $receipt_row=phpbb_acl_rows($peer,"SELECT config_value FROM fixture_config WHERE config_name='".phpbb_style_removal_receipt_key('legacy')."'");$receipt=json_decode($receipt_row[0]['config_value'],true);
 $cleanup=array('remove'=>'legacy','remove_token'=>$receipt['token']);
 sd_check(sr_run($cleanup)==='rendered','Absent cleanup retry succeeds');sd_unlocked();$cases++;
 sr_reset();mkdir($sd_root.'/templates/legacy',0700);file_put_contents($sd_root.'/templates/legacy/fixture.txt','fixture');$before=sr_snapshot();sd_check(sr_run($cleanup)==='error'&&sr_snapshot()===$before&&is_file($sd_root.'/templates/legacy/fixture.txt')&&$sr_ftp_calls===0,'Newly registered directory protected even with old cleanup token');unlink($sd_root.'/templates/legacy/fixture.txt');rmdir($sd_root.'/templates/legacy');$cases++;
 foreach(array(THEMES_TABLE,THEMES_NAME_TABLE,CONFIG_TABLE,USERS_TABLE,SESSIONS_TABLE,JR_ADMIN_TABLE) as $table){sr_reset();sd_sql('ALTER TABLE '.$table.' ENGINE=MyISAM');$before=sr_snapshot();sd_check(sr_run(array('remove_id'=>'3'))==='error'&&sr_snapshot()===$before,'Nontransactional participant denied');sd_sql('ALTER TABLE '.$table.' ENGINE=InnoDB ROW_FORMAT=DYNAMIC');$cases++;}
 function sr_receipt(){return json_decode(phpbb_acl_rows($GLOBALS['peer'],"SELECT config_value FROM fixture_config WHERE config_name='".phpbb_style_removal_receipt_key('legacy')."'")[0]['config_value'],true);}
 function sr_cleanup_request(){return array('remove'=>'legacy','remove_token'=>sr_receipt()['token']);}
 function sr_directory(){mkdir($GLOBALS['sd_root'].'/templates/legacy',0700);file_put_contents($GLOBALS['sd_root'].'/templates/legacy/fixture.txt','fixture');}
 function sr_discard_directory(){if(is_file($GLOBALS['sd_root'].'/templates/legacy/fixture.txt')){unlink($GLOBALS['sd_root'].'/templates/legacy/fixture.txt');}if(is_dir($GLOBALS['sd_root'].'/templates/legacy')){rmdir($GLOBALS['sd_root'].'/templates/legacy');}}
 sr_reset();$before=sr_snapshot();$sd_failure='INSERT INTO fixture_config ';sd_check(sr_run(array('remove_id'=>'3'))==='error'&&sr_snapshot()===$before,'Receipt insertion failure rolls back all unregister changes');sd_unlocked();$cases++;
 sr_reset();$before=sr_snapshot();sd_check(sr_run(array('remove_id'=>'3','remove'=>'legacy','remove_token'=>str_repeat('0',32)))==='error'&&sr_snapshot()===$before,'Mixed independent removals rejected before writes');$cases++;
 sr_reset();sr_run(array('remove_id'=>'3'));$cleanup=sr_cleanup_request();sr_directory();
 sd_check(isset($template->blocks['orphan'])&&$template->blocks['orphan'][0]['NAME']==='legacy','Actual page offers recorded pending cleanup');
 $bad=$cleanup;$bad['remove_token']=str_repeat('0',32);sd_check(sr_run($bad)==='error'&&is_file($sd_root.'/templates/legacy/fixture.txt')&&$sr_ftp_calls===0,'Forged token rejected before FTP/files');
 $bad=$cleanup;$bad['remove']='unrecorded';sd_check(sr_run($bad)==='error'&&$sr_ftp_calls===0,'No arbitrary orphan deletion without receipt');$cases+=2;
 $sd_failure='UPDATE fixture_config ';sd_check(sr_run($cleanup)==='error'&&!is_dir($sd_root.'/templates/legacy'),'Post-file receipt write failure is not reported as success');sd_unlocked();$sd_failure='';sd_check(sr_receipt()['state']==='pending','Pending receipt survives failed completion');
 sd_check(sr_run($cleanup)==='rendered'&&sr_receipt()['state']==='done','Retry after partial outcome completes without repeating deleted files');$cases++;
 sr_directory();sd_check(sr_run($cleanup)==='error'&&is_file($sd_root.'/templates/legacy/fixture.txt'),'Completed receipt cannot delete a newly created directory');sr_discard_directory();$cases++;
 sr_reset();sr_run(array('remove_id'=>'3'));$old=sr_cleanup_request();sd_sql("INSERT INTO fixture_themes (themes_id,template_name,style_name,theme_public) VALUES (3,'legacy','Reinstalled',0)");sr_run(array('remove_id'=>'3'));sd_check(sr_receipt()['token']!==$old['remove_token']&&sr_run($old)==='error','New unregister invalidates previous cleanup receipt');$cases++;
 foreach(array('root','delegated') as $actor){sr_reset($actor);sr_run(array('remove_id'=>'3'));$cleanup=sr_cleanup_request();sr_directory();
  $GLOBALS['sr_before_ftp']=function()use($actor){sd_sql(sd_revoke($actor==='root'?'role':'grant'));};sd_check(sr_run($cleanup)==='error'&&is_file($sd_root.'/templates/legacy/fixture.txt'),'Revocation after FTP preflight prevents file removal');sd_unlocked();sr_discard_directory();$cases++;
 }
 sr_reset();sr_run(array('remove_id'=>'3'));$cleanup=sr_cleanup_request();sr_directory();$sd_hook=function($sql){if(strpos($sql,'UPDATE fixture_config ')===0){$GLOBALS['sd_hook']=null;$GLOBALS['sd_failure']='lost-ack';}};
 sd_check(sr_run($cleanup)==='error'&&!is_dir($sd_root.'/templates/legacy')&&sr_receipt()['state']==='done','Lost final ACK keeps a durable completed receipt');$sd_failure='';sd_check(sr_run($cleanup)==='rendered','Completed receipt safely retries absent directory');sd_unlocked();$cases++;
 class RemovalInsertionProbe extends PhpbbStyleLocalFiles {var $blocked=false;function erase($relative,$kind){if(!$this->blocked){$r=$GLOBALS['peer']->sql_query("INSERT INTO fixture_themes (themes_id,template_name,style_name,theme_public) VALUES (4,'legacy','Concurrent',0)");$e=$GLOBALS['peer']->sql_error();sd_check(!$r&&(int)$e['code']===1205,'Native theme gap remains pinned during filesystem writes');$this->blocked=true;}return parent::erase($relative,$kind);}}
 sr_reset();sr_run(array('remove_id'=>'3'));$cleanup=sr_cleanup_request();$cleanup['sid']='fixture-admin';sr_directory();$files=new RemovalInsertionProbe($sd_root.'/templates');phpbb_style_remove_files($db,$cleanup,$files);sd_check($files->blocked&&!is_dir($sd_root.'/templates/legacy'),'File cleanup prevents concurrent database registration');sd_unlocked();$cases++;
 sr_reset();$sd_failure='SELECT config_name,config_value FROM fixture_config WHERE config_name LIKE ';sd_check(sr_run(array())==='error','Failed cleanup inventory is not displayed as an empty list');$cases++;
 $markup=file_get_contents($sd_source.'xs_mod/tpl/uninstall.tpl');$stack=array();$orphan_top=false;
 preg_match_all('/<!-- (BEGIN|END) ([a-z_]+) -->/',$markup,$blocks,PREG_SET_ORDER);
 foreach($blocks as $block){if($block[1]==='BEGIN'){if($block[2]==='orphan'){$orphan_top=!$stack;}$stack[]=$block[2];}else{sd_check(array_pop($stack)===$block[2],'Template blocks balance');}}
 sd_check(!$stack&&$orphan_top,'Cleanup form is visible independently of a success message');
 echo 'Style removal native checks: '.$cases.' cases; '.$serialized." revocations serialized\n";
}finally{
 $sd_hook=null;$sd_failure='';$sd_after_commit=null;$db->sql_close();$peer->sql_close();$control->sql_query('DROP DATABASE '.$fixture);$control->sql_close();chdir($sd_previous);
 foreach(array('themes.cache','config_data.cache') as $file){if(is_file($sd_root.'/cache/'.$file)){unlink($sd_root.'/cache/'.$file);}}
 if(is_file($sd_root.'/templates/legacy/fixture.txt')){unlink($sd_root.'/templates/legacy/fixture.txt');}if(is_dir($sd_root.'/templates/legacy')){rmdir($sd_root.'/templates/legacy');}
 foreach(array_reverse($sd_files) as $file){if(is_file($file)){unlink($file);}}foreach(array_reverse($sd_dirs) as $dir){if(is_dir($dir)){rmdir($dir);}}restore_error_handler();
}
PHP;
eval($head.$body);
