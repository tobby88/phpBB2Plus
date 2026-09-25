<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_STYLE_IMPORT_NATIVE')!=='1'){echo "Style import checks require an explicitly enabled disposable database.\n";return;}
putenv('PHPBB_STYLE_DATA_NATIVE=1');putenv('PHPBB_STYLE_DATA_PORT='.(getenv('PHPBB_STYLE_IMPORT_PORT')?:'3306'));putenv('PHPBB_STYLE_DATA_PASSWORD='.(getenv('PHPBB_STYLE_IMPORT_PASSWORD')?:''));
$source=file_get_contents(__DIR__.'/check-style-data-native.php');$cut=strpos($source," if (getenv('PHPBB_STYLE_DATA_PROBE')");
if($cut===false){throw new RuntimeException('Missing fixture boundary');}
$head=str_replace('__DIR__',var_export(__DIR__,true),substr($source,5,$cut-5));$head=str_replace('codex_style_data_','codex_style_import_',$head);
$body=<<<'PHP'
 define('IN_XS',true);define('XS_TEMP_DIR',$sd_root.'/cache/');
 $code=file_get_contents($sd_source.'admin/xs_include.php');
 foreach(array('STYLE_HEADER_START','STYLE_HEADER_END','TAR_HEADER_PACK','TAR_HEADER_UNPACK','XS_MAX_STYLE_UPLOAD_BYTES','XS_MAX_STYLE_UNPACKED_BYTES','XS_MAX_STYLE_FILES','XS_MAX_ITEMS_PER_STYLE') as $constant){preg_match('/define\(\''.$constant.'\', ([^;]+);/',$code,$m);eval($m[0]);}
 $tokens=token_get_all($code);
 for($i=0;$i<count($tokens);$i++){
  if(!is_array($tokens[$i])||$tokens[$i][0]!==T_FUNCTION){continue;}$j=$i+1;while(is_array($tokens[$j])&&$tokens[$j][0]===T_WHITESPACE){$j++;}
  if(!is_array($tokens[$j])||!in_array($tokens[$j][1],array('xs_get_style_header','xs_fix_dir','xs_tpl_name','xs_create_dir','xs_write_file','xs_get_themeinfo','xs_parse_themeinfo'),true)){continue;}
  $function='';$depth=0;$started=false;for(;$i<count($tokens);$i++){$token=$tokens[$i];$function.=is_array($token)?$token[1]:$token;if($token==='{'){$depth++;$started=true;}elseif($token==='}'){$depth--;}if($started&&!$depth){break;}}eval($function);
 }
 foreach(array('functions_style_archive.php','functions_style_import.php','functions_style_import_files.php') as $helper){if(is_file($sd_source.'includes/'.$helper)){file_put_contents($sd_root.'/includes/'.$helper,'<?php require_once '.var_export($sd_source.'includes/'.$helper,true).';');$sd_files[]=$sd_root.'/includes/'.$helper;}}
 sd_check(preg_match('/CREATE TABLE phpbb_config\s*\([\s\S]*?;/',$schema,$m)===1,'Canonical config');sd_sql(str_replace('phpbb_config',CONFIG_TABLE,$m[0]));
 foreach(array('templates','templates/fisubsilversh','templates/legacy') as $dir){mkdir($sd_root.'/'.$dir);$sd_dirs[]=$sd_root.'/'.$dir;}
 foreach(array('templates/fisubsilversh/theme_info.cfg','templates/legacy/theme_info.cfg','cache/input.style') as $file){$sd_files[]=$sd_root.'/'.$file;}
 function sim_cfg($tpl='fisubsilversh',$second=array()){
  $cfg='<?php'."\n";foreach(array('First Größe 😀','Second') as $n=>$name){$values=array_merge(array('template_name'=>$tpl,'style_name'=>$name,'fontface1'=>'Test\\Family','fontsize1'=>'12'),$n===1?$second:array());foreach($values as $key=>$value){$cfg.='$'.$tpl.'['.$n.']["'.$key.'"] = "'.addcslashes($value,'\\"$').'";'."\n";}}return $cfg;
 }
 function sim_archive($tpl='fisubsilversh',$second=array()){
  $cfg=sim_cfg($tpl,$second);$tar=pack(TAR_HEADER_PACK,'theme_info.cfg','100644','0','0',decoct(strlen($cfg)),'0','0','0','','ustar','','','','','','','').$cfg.str_repeat("\0",(512-strlen($cfg)%512)%512).str_repeat("\0",1024);
  $items=array($tpl,'Fixture','First Größe 😀',isset($second['style_name'])?$second['style_name']:'Second');$lengths='';foreach($items as $item){$lengths.=chr(strlen($item));}$size=strlen(STYLE_HEADER_START)+9+strlen($lengths)+strlen(implode('',$items))+strlen(STYLE_HEADER_END);$zip=gzcompress($tar);
  file_put_contents($GLOBALS['sd_root'].'/cache/input.style',STYLE_HEADER_START.pack('NN',$size,$size+strlen($zip)).chr(count($items)).$lengths.implode('',$items).STYLE_HEADER_END.$zip);clearstatcache();
 }
 function sim_reset($actor='root'){sd_reset($actor);sd_sql('DELETE FROM fixture_config');sd_sql("INSERT INTO fixture_config VALUES ('default_style','1'),('unrelated','keep')");sim_archive();file_put_contents($GLOBALS['sd_root'].'/cache/config_data.cache','old');}
 function sim_run($request=array()){
  global $db,$phpbb_root_path,$phpEx,$lang,$template,$userdata,$HTTP_POST_VARS,$HTTP_GET_VARS,$board_config,$xs_header_error;
  $HTTP_POST_VARS=array_merge(array('sid'=>'fixture-admin','total'=>'2','import_install_0'=>'1','import_install_1'=>'1','import_default'=>'-1'),$request);$HTTP_GET_VARS=array();$board_config=array('default_style'=>1);
  $phpbb_root_path='../';$filename='input.style';$write_local=true;$write_local_dir='../templates/';$list_only=false;$get_file='';
  try{include $GLOBALS['sd_source'].'admin/xs_include_import2.php';throw new RuntimeException('No import outcome');}catch(StyleDataExit $e){return $e->getMessage();}
 }
 function sim_snapshot(){return array(sd_snapshot(),phpbb_acl_rows($GLOBALS['peer'],'SELECT * FROM fixture_config ORDER BY config_name'));}
 function sim_boundary($sql){return preg_match('/^(INSERT INTO fixture_themes |UPDATE fixture_(themes|config) )/',$sql)||strpos($sql,'FOR UPDATE')!==false||strpos($sql,' LOCK IN SHARE MODE')!==false||$sql==='COMMIT';}
 $cases=0;$serialized=0;
 foreach(getenv('PHPBB_STYLE_IMPORT_EXTRA_ONLY')==='1'?array():array('root','delegated') as $actor){
  sim_reset($actor);sd_check(sim_run(array('import_default'=>'1'))==='saved','Actual import and default');$rows=phpbb_acl_rows($peer,"SELECT themes_id,fontface1,theme_public FROM fixture_themes WHERE style_name='First Größe 😀'");sd_check(count(sd_snapshot()[0])===4&&$rows[0]['fontface1']==='Test\\Family'&&$rows[0]['theme_public']==='1','Unicode, literal slash and public supported theme');sd_check($board_config['default_style']>2,'Request-local default published after commit');sd_check(!is_file($sd_root.'/cache/themes.cache')&&!is_file($sd_root.'/cache/config_data.cache'),'Both caches invalidated');sd_unlocked();$boundaries=array_values(array_filter($sd_queries,'sim_boundary'));$cases++;
  foreach(array('inactive','missing','admin-off','foreign','logout','case',$actor==='root'?'role':'grant') as $kind){
   sim_reset($actor);file_put_contents($sd_root.'/templates/fisubsilversh/theme_info.cfg','preserve');sd_sql(sd_revoke($kind));$before=sim_snapshot();
   sd_check(sim_run()==='error'&&sim_snapshot()===$before&&file_get_contents($sd_root.'/templates/fisubsilversh/theme_info.cfg')==='preserve','Revocation denies files AND database');sd_unlocked();$cases++;
  }
  foreach(array('missing',$actor==='root'?'role':'grant') as $kind){for($boundary=1;$boundary<=count($boundaries);$boundary++){
   sim_reset($actor);$before=sim_snapshot();$seen=0;$reached=false;$blocked=false;
   $sd_hook=function($sql)use($boundary,$kind,&$seen,&$reached,&$blocked){if(!sim_boundary($sql)||++$seen!==$boundary){return;}$GLOBALS['sd_hook']=null;$reached=true;if(!$GLOBALS['peer']->sql_query(sd_revoke($kind))){$e=$GLOBALS['peer']->sql_error();sd_check((int)$e['code']===1205,'Only native lock timeout serializes authority');$blocked=true;}};
   $out=sim_run(array('import_default'=>'1'));sd_check($reached,'Every import boundary reached');if($blocked){sd_check($out==='saved','Import completes before serialized revocation');sd_sql(sd_revoke($kind));$serialized++;}else{sd_check($out==='error'&&sim_snapshot()===$before,'Earlier revocation denies whole registration');}sd_unlocked();$cases++;
  }}
  foreach(array('first','second','default','COMMIT','lost-ack') as $failure){
   sim_reset($actor);$before=sim_snapshot();$inserts=0;
   if($failure==='first'||$failure==='second'){$target=$failure==='first'?1:2;$sd_hook=function($sql)use($target,&$inserts){if(strpos($sql,'INSERT INTO fixture_themes ')===0&&++$inserts===$target){$GLOBALS['sd_hook']=null;$GLOBALS['sd_failure']='INSERT INTO fixture_themes ';}};}else{$sd_failure=$failure==='default'?'UPDATE fixture_config ':$failure;}
   sd_check(sim_run(array('import_default'=>'1'))==='error','Failed/uncertain batch not reported as success');sd_check($failure==='lost-ack'?count(sd_snapshot()[0])===4:sim_snapshot()===$before,'All metadata/default committed or none');sd_check($board_config['default_style']===1,'Unconfirmed result does not publish local default');sd_unlocked();$cases++;
  }
  echo $actor." native import authority boundaries passed\n";
 }
 sim_reset();sd_check(sim_run()==='saved','Initial batch');$first=sd_snapshot();sim_archive('fisubsilversh',array('fontsize1'=>''));sd_check(sim_run()==='saved','Update existing style batch');$after=sd_snapshot();sd_check(count($after[0])===4&&$after[0][2]['themes_id']===$first[0][2]['themes_id']&&$after[0][3]['fontsize1']===null&&$after[1]===$first[1],'Existing IDs/labels preserved and blank numeric becomes NULL');sd_unlocked();$cases++;
 sim_reset();$sd_failure='lost-ack';sd_check(sim_run()==='error','Lost commit acknowledgement');$before=sim_snapshot();$sd_failure='';sd_check(sim_run()==='saved'&&sim_snapshot()===$before,'Retry reuses committed IDs');sd_unlocked();$cases++;
 foreach(array(array('fontsize1'=>'128'),array('style_name'=>str_repeat('x',31)),array('style_name'=>'FIRST GRÖSSE 😀'),array('template_name'=>'legacy'),array('fontface1'=>"bad\xff")) as $bad){
  sim_reset();sim_archive('fisubsilversh',$bad);file_put_contents($sd_root.'/templates/fisubsilversh/theme_info.cfg','preserve');$before=sim_snapshot();sd_check(sim_run()==='error'&&sim_snapshot()===$before&&file_get_contents($sd_root.'/templates/fisubsilversh/theme_info.cfg')==='preserve','Invalid full selection leaves files and metadata unchanged');sd_unlocked();$cases++;
 }
 foreach(array(array('total'=>array()),array('total'=>'2oops'),array('import_install_0'=>array()),array('import_install_01'=>'1'),array('import_default'=>'2'),array('import_default'=>array()),array('import_install_1'=>'0','import_default'=>'1'),array('sid'=>'wrong')) as $bad){sim_reset();$before=sim_snapshot();sd_check(sim_run($bad)==='error'&&sim_snapshot()===$before,'Malformed selection/token denied');sd_unlocked();$cases++;}
 sim_reset();$_SERVER['REQUEST_METHOD']='GET';$before=sim_snapshot();sd_check(sim_run()==='error'&&sim_snapshot()===$before,'GET never imports');$cases++;
 sim_reset();sim_archive('legacy');sd_check(sim_run()==='saved','Retired template metadata preserved privately');$rows=phpbb_acl_rows($peer,"SELECT theme_public FROM fixture_themes WHERE template_name='legacy'");sd_check(count($rows)===2&&$rows[0]['theme_public']==='0'&&$rows[1]['theme_public']==='0','No retired public theme');sd_unlocked();$cases++;
 sim_reset();sim_archive('legacy');$before=sim_snapshot();sd_check(sim_run(array('import_default'=>'0'))==='error'&&sim_snapshot()===$before,'Retired template cannot be default');$cases++;
 sim_reset();sd_sql("INSERT INTO fixture_themes (template_name,style_name) VALUES ('legacy','First Größe 😀')");$before=sim_snapshot();sd_check(sim_run()==='error'&&sim_snapshot()===$before,'Cross-template name collision refused');sd_unlocked();$cases++;
 sim_reset();sd_sql('ALTER TABLE fixture_themes AUTO_INCREMENT=40000');sd_check(sim_run()==='saved','Native ID allocation beyond old smallint range');$rows=phpbb_acl_rows($peer,'SELECT MAX(themes_id) AS id FROM fixture_themes');sd_check((int)$rows[0]['id']>40000,'No MAX+1 allocation');$cases++;
 sim_reset();$before=sim_snapshot();sd_check(sim_run(array('import_install_0'=>'0','import_install_1'=>'0'))==='saved'&&sim_snapshot()===$before,'Authorized upload-only supported');sd_unlocked();$cases++;
 foreach(array(THEMES_TABLE,CONFIG_TABLE,USERS_TABLE,SESSIONS_TABLE,JR_ADMIN_TABLE) as $table){sim_reset();sd_sql('ALTER TABLE '.$table.' ENGINE=MyISAM');$before=sim_snapshot();sd_check(sim_run()==='error'&&sim_snapshot()===$before,'Nontransactional import refused');sd_sql('ALTER TABLE '.$table.' ENGINE=InnoDB ROW_FORMAT=DYNAMIC');$cases++;}
 foreach(array('themes.cache','config_data.cache') as $cache){sim_reset();unlink($sd_root.'/cache/'.$cache);mkdir($sd_root.'/cache/'.$cache);try{sd_check(sim_run()==='error','Cache error never reports success');sd_unlocked();}finally{rmdir($sd_root.'/cache/'.$cache);}$cases++;}
 sim_reset();unlink($sd_root.'/templates/fisubsilversh/theme_info.cfg');mkdir($sd_root.'/templates/fisubsilversh/theme_info.cfg');$before=sim_snapshot();try{sd_check(sim_run()==='error'&&sim_snapshot()===$before,'File/directory destination conflict preserves registration');sd_unlocked();}finally{rmdir($sd_root.'/templates/fisubsilversh/theme_info.cfg');}$cases++;
 sim_reset();file_put_contents($sd_root.'/templates/fisubsilversh/theme_info.cfg','preserve');$before=sim_snapshot();$killed=false;
 $sd_hook=function($sql,$connection)use(&$killed){if(!glob($GLOBALS['sd_root'].'/templates/fisubsilversh/xs_*')){return;}$GLOBALS['sd_hook']=null;sd_sql('KILL CONNECTION '.mysqli_thread_id($connection->db_connect_id));$killed=true;};
 sd_check(sim_run()==='error'&&$killed&&sim_snapshot()===$before&&file_get_contents($sd_root.'/templates/fisubsilversh/theme_info.cfg')==='preserve','Lost owner connection before rename preserves old file and registration');sd_check(!glob($sd_root.'/templates/fisubsilversh/xs_*'),'Failed stage cleaned');sd_unlocked();$cases++;
 sim_reset();$blocked=false;$sd_hook=function($sql)use(&$blocked){if(strpos($sql,'INSERT INTO fixture_themes ')!==0){return;}$GLOBALS['sd_hook']=null;$r=$GLOBALS['peer']->sql_query("INSERT INTO fixture_themes (template_name,style_name) VALUES ('fisubsilversh','Concurrent')");$e=$GLOBALS['peer']->sql_error();sd_check(!$r&&(int)$e['code']===1205,'Native installer excluded by theme range lock');$blocked=true;};sd_check(sim_run()==='saved'&&$blocked,'Concurrent registration serialized');sd_unlocked();$cases++;
 echo 'Style import native checks: '.$cases.' cases; '.$serialized." revocations serialized\n";
}finally{
 $sd_hook=null;$sd_failure='';$sd_after_commit=null;$db->sql_close();$peer->sql_close();$control->sql_query('DROP DATABASE '.$fixture);$control->sql_close();chdir($sd_previous);
 foreach(array('themes.cache','config_data.cache') as $cache){if(is_file($sd_root.'/cache/'.$cache)){unlink($sd_root.'/cache/'.$cache);}}
 foreach(array_reverse($sd_files) as $file){if(is_file($file)){unlink($file);}}foreach(array_reverse($sd_dirs) as $dir){if(is_dir($dir)){rmdir($dir);}}restore_error_handler();
}
PHP;
eval($head.$body);
