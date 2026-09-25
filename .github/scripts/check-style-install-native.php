<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_STYLE_INSTALL_NATIVE')!=='1'){echo "Style installation checks require an explicitly enabled disposable database.\n";return;}
putenv('PHPBB_STYLE_DATA_NATIVE=1');putenv('PHPBB_STYLE_DATA_PORT='.(getenv('PHPBB_STYLE_INSTALL_PORT')?:'3306'));putenv('PHPBB_STYLE_DATA_PASSWORD='.(getenv('PHPBB_STYLE_INSTALL_PASSWORD')?:''));
$source=file_get_contents(__DIR__.'/check-style-data-native.php');$cut=strpos($source," if (getenv('PHPBB_STYLE_DATA_PROBE')");
if($cut===false){throw new RuntimeException('Missing fixture boundary');}
$head=str_replace('__DIR__',var_export(__DIR__,true),substr($source,5,$cut-5));$head=str_replace('codex_style_data_','codex_style_install_',$head);
$body=<<<'PHP'
 define('XS_MAX_ITEMS_PER_STYLE',32);define('XS_MAX_TIMEOUT',30);define('XS_TPL_PATH','');
 class InstallTemplate extends StyleDataTemplate {function assign_vars($data){}function set_filenames($data){}function pparse($name){throw new StyleDataExit('rendered');}}
 $template=new InstallTemplate();
 $code=file_get_contents($sd_source.'admin/xs_include.php');$a=strpos($code,'function xs_get_themeinfo(');$b=strpos($code,'function xs_escape_themeinfo_value(',$a);eval(substr($code,$a,$b-$a));
 $a=strpos($code,'function xs_tpl_name(');$b=strpos($code,'// close database',$a);eval(substr($code,$a,$b-$a));
 require_once $sd_source.'includes/functions_style_install.php';
 file_put_contents($sd_root.'/includes/functions_style_install.php','<?php require_once '.var_export($sd_source.'includes/functions_style_install.php',true).';');$sd_files[]=$sd_root.'/includes/functions_style_install.php';
 sd_check(preg_match('/CREATE TABLE phpbb_config\s*\([\s\S]*?;/',$schema,$m)===1,'Canonical config');sd_sql(str_replace('phpbb_config',CONFIG_TABLE,$m[0]));
 foreach(array('templates','templates/fisubsilversh','templates/legacy') as $dir){mkdir($sd_root.'/'.$dir);$sd_dirs[]=$sd_root.'/'.$dir;}
 foreach(array('fisubsilversh','legacy') as $tpl){$sd_files[]=$sd_root.'/templates/'.$tpl.'/theme_info.cfg';}
 function si_config($tpl='fisubsilversh',$override=array()){
  $cfg='<?php'."\n";
  foreach(array('First Größe 😀','Second') as $n=>$name){$data=array_merge(array('template_name'=>$tpl,'style_name'=>$name,'fontface1'=>'Test\\Family','fontsize1'=>'12','img_size_poll'=>'65535'),$n===1?$override:array());foreach($data as $key=>$value){$cfg.='$'.$tpl.'['.$n.']["'.$key.'"] = "'.addcslashes($value,'\\"$').'";'."\n";}}
  file_put_contents($GLOBALS['sd_root'].'/templates/'.$tpl.'/theme_info.cfg',$cfg);
 }
 function si_reset($actor='root'){sd_reset($actor);sd_sql('DELETE FROM fixture_config');sd_sql("INSERT INTO fixture_config VALUES ('default_style','1'),('unrelated','keep')");si_config();si_config('legacy');file_put_contents($GLOBALS['sd_root'].'/cache/config_data.cache','old');}
 function si_run($request){global $db,$phpbb_root_path,$phpEx,$lang,$template,$userdata,$HTTP_POST_VARS,$HTTP_GET_VARS;
  $_POST=$HTTP_POST_VARS=array_merge(array('sid'=>'fixture-admin'),$request);$HTTP_GET_VARS=array();
  try{include $GLOBALS['sd_source'].'admin/xs_install.php';throw new RuntimeException('No controller outcome');}catch(StyleDataExit $e){return $e->getMessage();}
 }
 function si_batch(){return array('total'=>'2','install_0'=>'on','install_0_style'=>'fisubsilversh','install_0_num'=>'0','install_1'=>'on','install_1_style'=>'fisubsilversh','install_1_num'=>'1');}
 function si_boundary($sql){return strpos($sql,'INSERT INTO fixture_themes ')===0||strpos($sql,'FOR UPDATE')!==false||strpos($sql,' LOCK IN SHARE MODE')!==false||$sql==='COMMIT';}
 $cases=0;$serialized=0;
 foreach(getenv('PHPBB_STYLE_INSTALL_EXTRA_ONLY')==='1'?array():array('root','delegated') as $actor){
  si_reset($actor);sd_check(si_run(si_batch())==='saved','Actual batch installs');$rows=phpbb_acl_rows($peer,"SELECT style_name,fontface1,theme_public FROM fixture_themes WHERE style_name LIKE 'First%'");sd_check(count(sd_snapshot()[0])===4&&$rows[0]['style_name']==='First Größe 😀'&&$rows[0]['fontface1']==='Test\\Family'&&$rows[0]['theme_public']==='1','Unicode and literal slash preserved, supported template public');sd_check(!is_file($sd_root.'/cache/themes.cache')&&!is_file($sd_root.'/cache/config_data.cache'),'Both caches invalidated');sd_unlocked();$boundaries=array_values(array_filter($sd_queries,'si_boundary'));$cases++;
  foreach(array('inactive','missing','admin-off','foreign','logout','case',$actor==='root'?'role':'grant') as $kind){si_reset($actor);sd_sql(sd_revoke($kind));$before=sd_snapshot();sd_check(si_run(si_batch())==='error'&&sd_snapshot()===$before,'Revoked authority denies actual controller');sd_unlocked();$cases++;}
  foreach(array('missing',$actor==='root'?'role':'grant') as $kind){for($boundary=1;$boundary<=count($boundaries);$boundary++){
   si_reset($actor);$before=sd_snapshot();$seen=0;$reached=false;$blocked=false;
   $sd_hook=function($sql)use($boundary,$kind,&$seen,&$reached,&$blocked){if(!si_boundary($sql)||++$seen!==$boundary){return;}$GLOBALS['sd_hook']=null;$reached=true;if(!$GLOBALS['peer']->sql_query(sd_revoke($kind))){$e=$GLOBALS['peer']->sql_error();sd_check((int)$e['code']===1205,'Native authority lock timeout only');$blocked=true;}};
   $out=si_run(si_batch());sd_check($reached,'Every installation boundary reached');if($blocked){sd_check($out==='saved','Installation serializes before revocation');sd_sql(sd_revoke($kind));$serialized++;}else{sd_check($out==='error'&&sd_snapshot()===$before,'Revocation rolls back complete batch');}sd_unlocked();$cases++;
  }}
  foreach(array('first','second','COMMIT','lost-ack') as $failure){si_reset($actor);$before=sd_snapshot();$inserts=0;
   if($failure==='first'||$failure==='second'){$target=$failure==='first'?1:2;$sd_hook=function($sql)use($target,&$inserts){if(strpos($sql,'INSERT INTO fixture_themes ')===0&&++$inserts===$target){$GLOBALS['sd_hook']=null;$GLOBALS['sd_failure']='INSERT INTO fixture_themes ';}};}else{$sd_failure=$failure;}
   sd_check(si_run(si_batch())==='error','No false success on failed/uncertain write');sd_check($failure==='lost-ack'?count(sd_snapshot()[0])===4:sd_snapshot()===$before,'Whole batch or no rows');sd_check(!is_file($sd_root.'/cache/themes.cache')&&!is_file($sd_root.'/cache/config_data.cache'),'Failed/uncertain write invalidates caches');sd_unlocked();$cases++;
  }
  echo $actor." native installation boundaries passed\n";
 }
 foreach(array(array('style_name'=>str_repeat('a',31)),array('fontsize1'=>'128'),array('img_size_poll'=>'-1'),array('body_text'=>str_repeat('a',7)),array('fontface1'=>"bad\xff"),array('template_name'=>'legacy'),array('unknown'=>'value'),array('style_name'=>'First Größe 😀')) as $bad){si_reset();si_config('fisubsilversh',$bad);$before=sd_snapshot();sd_check(si_run(si_batch())==='error'&&sd_snapshot()===$before,'Invalid second definition or duplicate rolls back batch');sd_unlocked();$cases++;}
 foreach(array(array('install_one'=>array()),array('install_one'=>'../escape:0'),array('install_one'=>'fisubsilversh:32'),array('total'=>'1001'),array('total'=>array()),array('total'=>'1','install_0'=>array()),array('total'=>'1','install_0'=>'on','install_0_style'=>'fisubsilversh','install_0_num'=>'1oops')) as $bad){si_reset();$before=sd_snapshot();sd_check(si_run($bad)==='error'&&sd_snapshot()===$before,'Malformed whole selection denied');$cases++;}
 si_reset();$request=si_batch();$request['install_1_num']='0';$before=sd_snapshot();sd_check(si_run($request)==='error'&&sd_snapshot()===$before,'Duplicate selected entries denied');$cases++;
 si_reset();sd_check(si_run(array('install_one'=>'legacy:0'))==='saved','Legacy metadata may be preserved');$row=phpbb_acl_rows($peer,"SELECT theme_public FROM fixture_themes WHERE template_name='legacy'");sd_check($row[0]['theme_public']==='0','Retired template not published');$cases++;
 si_reset();sd_sql('ALTER TABLE fixture_themes AUTO_INCREMENT=40000');sd_check(si_run(si_batch())==='saved','Native ID allocation beyond old reference width');$rows=phpbb_acl_rows($peer,'SELECT themes_id FROM fixture_themes ORDER BY themes_id DESC LIMIT 2');sd_check((int)$rows[0]['themes_id']>40000,'No MAX+1 ID allocation');$cases++;
 foreach(array('themes.cache','config_data.cache') as $cache){si_reset();unlink($sd_root.'/cache/'.$cache);mkdir($sd_root.'/cache/'.$cache);try{sd_check(si_run(si_batch())==='error','Cache failure surfaced');sd_unlocked();}finally{rmdir($sd_root.'/cache/'.$cache);}$cases++;}
 foreach(array(THEMES_TABLE,CONFIG_TABLE,USERS_TABLE,SESSIONS_TABLE,JR_ADMIN_TABLE) as $table){si_reset();sd_sql('ALTER TABLE '.$table.' ENGINE=MyISAM');$before=sd_snapshot();sd_check(si_run(si_batch())==='error'&&sd_snapshot()===$before,'Nontransactional storage refused');sd_sql('ALTER TABLE '.$table.' ENGINE=InnoDB ROW_FORMAT=DYNAMIC');$cases++;}
 si_reset();$blocked=false;$sd_hook=function($sql)use(&$blocked){if(strpos($sql,'INSERT INTO fixture_themes ')!==0){return;}$GLOBALS['sd_hook']=null;$r=$GLOBALS['peer']->sql_query("INSERT INTO fixture_themes (template_name,style_name) VALUES ('fisubsilversh','Concurrent')");$e=$GLOBALS['peer']->sql_error();sd_check(!$r&&(int)$e['code']===1205,'Theme native key range pinned');$blocked=true;};sd_check(si_run(si_batch())==='saved'&&$blocked,'Concurrent native installer serialized');sd_unlocked();$cases++;
 si_reset();sd_check(si_run(si_batch())==='saved','Initial retry fixture');$before=sd_snapshot();file_put_contents($sd_root.'/cache/themes.cache','stale');file_put_contents($sd_root.'/cache/config_data.cache','stale');sd_check(si_run(si_batch())==='saved'&&sd_snapshot()===$before&&!is_file($sd_root.'/cache/themes.cache')&&!is_file($sd_root.'/cache/config_data.cache'),'Matching no-op retry clears stale caches without duplicate IDs');$cases++;
 si_config('fisubsilversh',array('fontsize1'=>'13'));$before=sd_snapshot();sd_check(si_run(si_batch())==='error'&&sd_snapshot()===$before,'Retry cannot overwrite differing existing metadata');$cases++;
 si_reset();$sd_failure='lost-ack';sd_check(si_run(si_batch())==='error','Commit acknowledgement loss reported');$before=sd_snapshot();$sd_failure='';sd_check(si_run(si_batch())==='saved'&&sd_snapshot()===$before,'Lost-ACK retry keeps exact original IDs');sd_unlocked();$cases++;
 si_reset();$before=sd_snapshot();sd_check(si_run(array('install_one'=>'fisubsilversh:0','sid'=>'wrong'))==='Invalid administration request.'&&sd_snapshot()===$before,'Wrong session token cannot install');$cases++;
 si_reset();$_SERVER['REQUEST_METHOD']='GET';$before=sd_snapshot();sd_check(si_run(si_batch())==='Invalid administration request.'&&sd_snapshot()===$before,'GET cannot install');$cases++;
 si_reset();si_config('fisubsilversh',array('style_name'=>'FIRST GRÖSSE 😀'));$before=sd_snapshot();sd_check(si_run(si_batch())==='error'&&sd_snapshot()===$before,'Database collation-equivalent duplicate does not leave half a batch');$cases++;
 si_reset();$HTTP_POST_VARS=array('sid'=>'fixture-admin');sd_sql(sd_revoke('role'));$before=sd_snapshot();$denied=false;try{xs_install_style('fisubsilversh',0);}catch(PhpbbAclException $e){$denied=true;}sd_check($denied&&sd_snapshot()===$before,'Compatibility function also checks current authority');$cases++;
 echo 'Style installation native checks: '.$cases.' cases; '.$serialized." revocations serialized\n";
}finally{
 $sd_hook=null;$sd_failure='';$sd_after_commit=null;$db->sql_close();$peer->sql_close();$control->sql_query('DROP DATABASE '.$fixture);$control->sql_close();chdir($sd_previous);
 foreach(array('themes.cache','config_data.cache') as $cache){if(is_file($sd_root.'/cache/'.$cache)){unlink($sd_root.'/cache/'.$cache);}}
 foreach(array_reverse($sd_files) as $file){if(is_file($file)){unlink($file);}}foreach(array_reverse($sd_dirs) as $dir){if(is_dir($dir)){rmdir($dir);}}restore_error_handler();
}
PHP;
eval($head.$body);
