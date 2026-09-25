<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_STYLE_CLONE_NATIVE')!=='1'){echo "Style clone checks require an explicitly enabled disposable database.\n";return;}
putenv('PHPBB_STYLE_DATA_NATIVE=1');putenv('PHPBB_STYLE_DATA_PORT='.(getenv('PHPBB_STYLE_CLONE_PORT')?:'3306'));putenv('PHPBB_STYLE_DATA_PASSWORD='.(getenv('PHPBB_STYLE_CLONE_PASSWORD')?:''));
$source=file_get_contents(__DIR__.'/check-style-data-native.php');$cut=strpos($source," if (getenv('PHPBB_STYLE_DATA_PROBE')");
if($cut===false){throw new RuntimeException('Missing fixture boundary');}
$head=str_replace('__DIR__',var_export(__DIR__,true),substr($source,5,$cut-5));$head=str_replace('codex_style_data_','codex_style_clone_',$head);
$body=<<<'PHP'
 require_once $sd_source.'includes/functions_style_clone.php';
 file_put_contents($sd_root.'/includes/functions_style_clone.php','<?php require_once '.var_export($sd_source.'includes/functions_style_clone.php',true).';');$sd_files[]=$sd_root.'/includes/functions_style_clone.php';
 sd_check(preg_match('/CREATE TABLE phpbb_config\s*\([\s\S]*?;/',$schema,$m)===1,'Canonical config');sd_sql(str_replace('phpbb_config',CONFIG_TABLE,$m[0]));
 function sc_reset($actor='root'){
  sd_reset($actor);sd_sql('DELETE FROM fixture_config');sd_sql("INSERT INTO fixture_config VALUES ('default_style','1'),('unrelated','keep')");
  sd_sql("UPDATE fixture_themes SET fontface1='Größe 😀',body_background=NULL,fontsize1=NULL,fontsize2=-128,img_size_poll=65535 WHERE themes_id=1");
  sd_sql("UPDATE fixture_names SET tr_color1_name='Farbe 😀',fontface1_name=NULL WHERE themes_id=1");file_put_contents($GLOBALS['sd_root'].'/cache/config_data.cache','stale');
 }
 function sc_run($request=array()) {global $db,$phpbb_root_path,$phpEx,$template,$lang,$userdata,$HTTP_POST_VARS,$HTTP_GET_VARS;
  $_POST=$HTTP_POST_VARS=array_merge(array('sid'=>'fixture-admin','clone_style'=>'1','clone_name'=>addslashes('Clone Größe 😀')),$request);$HTTP_GET_VARS=array();
  try{include $GLOBALS['sd_source'].'admin/xs_clone.php';throw new RuntimeException('Controller did not terminate');}catch(StyleDataExit $e){return $e->getMessage();}
 }
 function sc_boundary($sql){return strpos($sql,'INSERT INTO fixture_')===0||strpos($sql,'FOR UPDATE')!==false||strpos($sql,' LOCK IN SHARE MODE')!==false||$sql==='COMMIT';}
 function sc_check_copy(){
  $rows=phpbb_acl_rows($GLOBALS['peer'],'SELECT * FROM fixture_themes ORDER BY themes_id');$original=$rows[0];$copy=$rows[2];$clone_id=$copy['themes_id'];$original['style_name']='Clone Größe 😀';foreach(array_keys($original) as $key){if(is_int($key)||$key==='themes_id'){unset($original[$key],$copy[$key]);}}sd_check($original===$copy,'All metadata including NULL/numeric/Unicode copied exactly');
  $rows=phpbb_acl_rows($GLOBALS['peer'],'SELECT * FROM fixture_names WHERE themes_id IN (1,'.(int)$clone_id.') ORDER BY themes_id');sd_check(count($rows)===2,'Labels cloned too');foreach(array_keys($rows[0]) as $key){if(is_int($key)||$key==='themes_id'){unset($rows[0][$key],$rows[1][$key]);}}sd_check($rows[0]===$rows[1],'NULL and Unicode labels preserved');
 }
 $cases=0;$serialized=0;
 foreach(getenv('PHPBB_STYLE_CLONE_EXTRA_ONLY')==='1'?array():array('root','delegated') as $actor){
  sc_reset($actor);sd_check(sc_run()==='saved','Actual clone controller saves');sc_check_copy();sd_check(!is_file($sd_root.'/cache/themes.cache')&&!is_file($sd_root.'/cache/config_data.cache'),'Both caches invalidated');sd_unlocked();$boundaries=array_values(array_filter($sd_queries,'sc_boundary'));$cases++;
  foreach(array('inactive','missing','admin-off','foreign','logout','case',$actor==='root'?'role':'grant') as $kind){sc_reset($actor);sd_sql(sd_revoke($kind));$before=sd_snapshot();sd_check(sc_run()==='error'&&sd_snapshot()===$before,'Entry revocation denies clone');sd_unlocked();$cases++;}
  foreach(array('missing',$actor==='root'?'role':'grant') as $kind){for($boundary=1;$boundary<=count($boundaries);$boundary++){
   sc_reset($actor);$before=sd_snapshot();$seen=0;$reached=false;$blocked=false;
   $sd_hook=function($sql)use($boundary,$kind,&$seen,&$reached,&$blocked){if(!sc_boundary($sql)||++$seen!==$boundary){return;}$GLOBALS['sd_hook']=null;$reached=true;if(!$GLOBALS['peer']->sql_query(sd_revoke($kind))){$e=$GLOBALS['peer']->sql_error();sd_check((int)$e['code']===1205,'Only native authority lock timeout');$blocked=true;}};
   $out=sc_run();sd_check($reached,'Every actual clone boundary reached');if($blocked){sd_check($out==='saved','Clone serializes before revocation');sd_sql(sd_revoke($kind));$serialized++;}else{sd_check($out==='error'&&sd_snapshot()===$before,'Revocation rolls back clone and labels');}sd_unlocked();$cases++;
  }}
  foreach(array('INSERT INTO fixture_themes ','INSERT INTO fixture_names ','COMMIT','lost-ack') as $failure){sc_reset($actor);$before=sd_snapshot();$sd_failure=$failure;sd_check(sc_run()==='error','Write/commit/cache uncertainty not success');sd_check($failure==='lost-ack'?count(sd_snapshot()[0])===3:sd_snapshot()===$before,'Clone+labels commit or roll back together');sd_check(!is_file($sd_root.'/cache/themes.cache')&&!is_file($sd_root.'/cache/config_data.cache'),'Failed write clears caches');sd_unlocked();$cases++;}
  echo $actor." native clone boundaries passed\n";
 }
 foreach(array(array('clone_style'=>array('1')),array('clone_style'=>'1junk'),array('clone_style'=>'0'),array('clone_style'=>'16777216'),array('clone_style'=>'99'),array('clone_name'=>array()),array('clone_name'=>str_repeat('😀',31)),array('clone_name'=>"bad\x00name"),array('clone_name'=>"bad\xff"),array('clone_tpl'=>'fisubsilversh'),array('clone_name'=>'Before'),array('clone_name'=>'before'),array('clone_name'=>'Other')) as $bad){sc_reset();$before=sd_snapshot();sd_check(sc_run($bad)==='error'&&sd_snapshot()===$before,'Malformed or colliding clone rejected');$cases++;}
 sc_reset();sd_check(sc_run()==='saved','Retry fixture created');$before=sd_snapshot();file_put_contents($sd_root.'/cache/themes.cache','stale');file_put_contents($sd_root.'/cache/config_data.cache','stale');sd_check(sc_run()==='saved'&&sd_snapshot()===$before&&!is_file($sd_root.'/cache/themes.cache')&&!is_file($sd_root.'/cache/config_data.cache'),'Exact retry keeps ID and repairs caches');$cases++;
 sd_sql("UPDATE fixture_names SET tr_color1_name='Different' WHERE themes_id=1");$before=sd_snapshot();sd_check(sc_run()==='error'&&sd_snapshot()===$before,'Retry does not overwrite differing existing labels');$cases++;
 sc_reset();$sd_failure='lost-ack';sd_check(sc_run()==='error','Lost ACK visible');$before=sd_snapshot();$sd_failure='';sd_check(sc_run()==='saved'&&sd_snapshot()===$before,'Unknown commit retry preserves original IDs');$cases++;
 sc_reset();sd_sql('DELETE FROM fixture_names WHERE themes_id=1');sd_check(sc_run()==='saved'&&count(sd_snapshot()[1])===0,'Absent source labels stay absent');$cases++;
 sc_reset();sd_sql("UPDATE fixture_themes SET template_name='legacy',theme_public=1 WHERE themes_id=2");sd_check(sc_run(array('clone_style'=>'2'))==='saved','Retired template metadata can be cloned');$row=phpbb_acl_rows($peer,"SELECT theme_public FROM fixture_themes WHERE style_name='Clone Größe 😀'");sd_check($row[0]['theme_public']==='0','Retired template not published');$cases++;
 sc_reset();sd_sql('ALTER TABLE fixture_themes AUTO_INCREMENT=40000');sd_check(sc_run()==='saved','Clone uses full-width native IDs');sc_check_copy();$cases++;
 foreach(array('themes.cache','config_data.cache') as $cache){sc_reset();unlink($sd_root.'/cache/'.$cache);mkdir($sd_root.'/cache/'.$cache);try{sd_check(sc_run()==='error','Cache failure is not success');sd_unlocked();}finally{rmdir($sd_root.'/cache/'.$cache);}sd_check(sc_run()==='saved','Cache failure retry repairs without duplicate');$cases++;}
 foreach(array(THEMES_TABLE,THEMES_NAME_TABLE,CONFIG_TABLE,USERS_TABLE,SESSIONS_TABLE,JR_ADMIN_TABLE) as $table){sc_reset();sd_sql('ALTER TABLE '.$table.' ENGINE=MyISAM');$before=sd_snapshot();sd_check(sc_run()==='error'&&sd_snapshot()===$before,'Nontransactional participant refused');sd_sql('ALTER TABLE '.$table.' ENGINE=InnoDB ROW_FORMAT=DYNAMIC');$cases++;}
 sc_reset();$blocked=false;$sd_hook=function($sql)use(&$blocked){if(strpos($sql,'INSERT INTO fixture_themes ')!==0){return;}$GLOBALS['sd_hook']=null;$r=$GLOBALS['peer']->sql_query("UPDATE fixture_names SET tr_color1_name='Concurrent' WHERE themes_id=1");$e=$GLOBALS['peer']->sql_error();sd_check(!$r&&(int)$e['code']===1205,'Source label locked against concurrent mutation');$blocked=true;};sd_check(sc_run()==='saved'&&$blocked,'Theme and labels taken from one pinned source');sc_check_copy();sd_unlocked();$cases++;
 echo 'Style clone native checks: '.$cases.' cases; '.$serialized." revocations serialized\n";
}finally{
 $sd_hook=null;$sd_failure='';$sd_after_commit=null;$db->sql_close();$peer->sql_close();$control->sql_query('DROP DATABASE '.$fixture);$control->sql_close();chdir($sd_previous);
 foreach(array('themes.cache','config_data.cache') as $cache){if(is_file($sd_root.'/cache/'.$cache)){unlink($sd_root.'/cache/'.$cache);}}
 foreach(array_reverse($sd_files) as $file){if(is_file($file)){unlink($file);}}foreach(array_reverse($sd_dirs) as $dir){if(is_dir($dir)){rmdir($dir);}}restore_error_handler();
}
PHP;
eval($head.$body);
