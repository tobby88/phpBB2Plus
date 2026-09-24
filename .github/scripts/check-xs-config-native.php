<?php
if (PHP_SAPI !== 'cli' || getenv('PHPBB_XS_CONFIG_NATIVE') !== '1') { echo "XS configuration checks require an explicitly enabled disposable database.\n"; return; }
$xc_mode = isset($argv[1]) ? $argv[1] : '';
if (!in_array($xc_mode, array('config','ftp'), true)) { throw new RuntimeException('Choose config or ftp'); }
putenv('PHPBB_STYLE_DATA_NATIVE=1'); putenv('PHPBB_STYLE_DATA_PORT=' . (getenv('PHPBB_XS_CONFIG_PORT') ?: '3306'));
putenv('PHPBB_STYLE_DATA_PASSWORD=' . (getenv('PHPBB_XS_CONFIG_PASSWORD') ?: ''));
define('CONFIG_TABLE', 'fixture_config'); define('XS_TPL_PATH', ''); define('XS_SHOWNAV_MAX', 13); define('XS_SHOWNAV_DOWNLOAD', 8);
$xc_fixture = file_get_contents(__DIR__ . '/check-style-data-native.php');
$xc_cut = strpos($xc_fixture, " if (getenv('PHPBB_STYLE_DATA_PROBE')");
if ($xc_cut === false) { throw new RuntimeException('Native XS fixture boundary missing'); }
$xc_head = str_replace('__DIR__', var_export(__DIR__, true), substr($xc_fixture, 5, $xc_cut - 5));
$xc_head = str_replace('codex_style_data_', 'codex_xs_config_', $xc_head);
$xc_body = <<<'PHP'
 class XsConfigTemplate extends StyleDataTemplate {
  var $xs_versiontxt='2.3.1'; var $root='';
  function assign_vars($data) {} function set_filenames($data) {} function pparse($name) { throw new StyleDataExit('rendered'); }
  function load_config($root,$reload) {} function make_filename($name){return $GLOBALS['sd_root'].'/'.$name;}
  function make_filename_cache($name){return $GLOBALS['sd_root'].'/cache/probe.cache';}
 }
 function xs_check_cache($name){return true;}
 function xs_tpl_name($name){return preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/D',$name)&&strpos($name,'..')===false?$name:'';}
 $template=new XsConfigTemplate(); $HTTP_SERVER_VARS=array();
 foreach(array('templates','templates/fisubsilversh') as $dir){mkdir($sd_root.'/'.$dir,0700);$sd_dirs[]=$sd_root.'/'.$dir;}
 sd_check(preg_match('/CREATE TABLE phpbb_config\s*\([\s\S]*?;/', $schema, $m)===1,'Canonical configuration schema');sd_sql(str_replace('phpbb_config',CONFIG_TABLE,$m[0]));
 $helper=$sd_root.'/includes/functions_xs_config.php';file_put_contents($helper,'<?php require_once '.var_export($sd_source.'includes/functions_xs_config.php',true).';');$sd_files[]=$helper;
 // Execute the actual FTP form handler, without connecting to any FTP server.
 $xs_source=file_get_contents($sd_source.'admin/xs_include.php');$a=strpos($xs_source,'function get_ftp_config(');$b=strpos($xs_source,'function xs_ftp_connect(',$a);
 sd_check($a!==false&&$b>$a,'Actual FTP form function boundary');
 eval(str_replace('__DIR__',var_export($sd_source.'admin',true),substr($xs_source,$a,$b-$a)));
 if($xc_mode==='ftp'){sd_check(function_exists('ftp_connect'),'Native FTP extension loaded; no network connection performed');}
 function xc_defaults(){return array('xs_use_cache'=>'1','xs_auto_compile'=>'1','xs_auto_recompile'=>'1','xs_php'=>'php','xs_def_template'=>'fisubsilversh','xs_check_switches'=>'0',
  'xs_warn_includes'=>'1','xs_add_comments'=>'0','xs_ftp_host'=>'old.example','xs_ftp_login'=>'old-login','xs_ftp_path'=>'old-path','xs_shownav'=>'1','xs_template_time'=>'2000000000','version'=>'preserve');}
 function xc_snapshot(){return phpbb_board_config_read($GLOBALS['peer']);}
 function xc_reset($actor='root'){
  global $userdata,$board_config,$sd_hook,$sd_failure,$sd_queries,$sd_after_commit;
  $sd_hook=null;$sd_failure='';$sd_queries=array();$sd_after_commit=null;
  foreach(array(CONFIG_TABLE,USERS_TABLE,SESSIONS_TABLE,JR_ADMIN_TABLE) as $table){sd_sql('DELETE FROM '.$table);}
  foreach(xc_defaults() as $key=>$value){sd_sql("INSERT INTO fixture_config VALUES ('".$key."','".$value."')");}
  sd_sql('INSERT INTO fixture_users VALUES (1,'.($actor==='root'?1:0).',1)');sd_sql("INSERT INTO fixture_sessions VALUES ('fixture-admin',1,1,1)");
  if($actor==='delegated'){$hash=array_search('xs_frameset.php',jr_admin_authorization_routes(),true);sd_check($hash!==false,'Actual XS grant');sd_sql("INSERT INTO fixture_jr VALUES (1,'".$hash."')");}
  $userdata=array('user_id'=>1,'session_id'=>'fixture-admin','session_logged_in'=>1,'session_admin'=>1,'user_level'=>$actor==='root'?1:0);
  $board_config=xc_snapshot();if($GLOBALS['xc_mode']==='ftp'){$board_config['xs_ftp_local']=false;}$_SERVER['REQUEST_METHOD']='POST';file_put_contents($GLOBALS['sd_root'].'/cache/config_data.cache','old cache');
 }
 function xc_request(){
  if($GLOBALS['xc_mode']==='ftp'){return array('get_ftp_config'=>'1','xs_ftp_local'=>'','xs_ftp_host'=>'new.example','xs_ftp_login'=>addslashes("Ä ' \\ 😀"),'xs_ftp_path'=>addslashes("/Pfad ' \\ 😀"),'xs_ftp_pass'=>addslashes(" opaque-secret ' \\ 😀 "));}
  $request=xc_defaults();unset($request['xs_shownav'],$request['xs_template_time'],$request['version']);$request['submit']='Save';$request['shownav_2']='1';
  $request['xs_use_cache']='0';$request['xs_auto_compile']='0';$request['xs_warn_includes']='0';$request['xs_add_comments']='1';$request['xs_check_switches']='2';
  $request['xs_ftp_login']=addslashes("Ä ' \\ 😀");$request['xs_ftp_path']=addslashes("/Pfad ' \\ 😀");return $request;
 }
 function xc_run($request,$allow_local=false){
  global $db,$phpbb_root_path,$phpEx,$lang,$template,$userdata,$board_config,$HTTP_POST_VARS,$HTTP_GET_VARS,$HTTP_SERVER_VARS;
  $phpbb_root_path=$GLOBALS['sd_root'].'/';$_POST=$HTTP_POST_VARS=array_merge(array('sid'=>'fixture-admin'),$request);$HTTP_GET_VARS=array();
  try{if($GLOBALS['xc_mode']==='ftp'){return get_ftp_config('xs_import.php',array(),$allow_local)?'accepted':'form';}include $GLOBALS['sd_source'].'admin/xs_config.php';throw new RuntimeException('Controller did not terminate');}
  catch(StyleDataExit $error){return $error->getMessage();}
 }
 require_once $sd_source.'includes/functions_board_config.php';
 if(getenv('PHPBB_XS_CONFIG_PROBE')==='1'){
  xc_reset();$before=xc_snapshot();$request=xc_request();
  if($xc_mode==='config'){$sd_failure="UPDATE fixture_config SET config_value = '0' WHERE config_name = 'xs_auto_compile'";}
  else{$request['xs_ftp_pass']=str_repeat('x',1025);}
  $out=xc_run($request);$after=xc_snapshot();sd_check($out==='error'&&$after!==$before,'Reproduce partial actual XS persistence');
  echo $xc_mode." reproduced: error leaves earlier settings committed and old cache intact.\n";
 }else{
  $success=$xc_mode==='ftp'?'accepted':'rendered';$request=xc_request();$cases=0;$serialized=0;
  function xc_boundary($sql){return strpos($sql,'UPDATE fixture_config ')===0||strpos($sql,' LOCK IN SHARE MODE')!==false||$sql==='COMMIT';}
  foreach(array('root','delegated') as $actor){
   xc_reset($actor);$before=xc_snapshot();sd_check(xc_run($request)===$success,'Actual controller/form succeeds');$expected=xc_snapshot();
   sd_check($expected['version']==='preserve'&&$expected['xs_ftp_login']===stripslashes($request['xs_ftp_login'])&&$expected['xs_ftp_path']===stripslashes($request['xs_ftp_path']),'UTF8 and one-time decoding preserve unrelated config');
   if($xc_mode==='config'){sd_check($expected['xs_auto_compile']==='0'&&$expected['xs_auto_recompile']==='0'&&$expected['xs_shownav']==='4'&&$expected['xs_template_time']==='2000000001','Dependent flags/navigation and monotonically increasing compilation clock');}
   else{sd_check(!isset($expected['xs_ftp_pass'])&&$board_config['xs_ftp_pass']===stripslashes($request['xs_ftp_pass']),'Exact secret is request-only');foreach($sd_queries as $sql){sd_check(strpos($sql,'opaque-secret')===false,'Secret never enters SQL');}}
   sd_check(!is_file($sd_root.'/cache/config_data.cache'),'Configuration cache evicted');sd_unlocked();$cases++;
   $boundaries=array_values(array_filter($sd_queries,'xc_boundary'));
   foreach(array('inactive','missing','admin-off','foreign','logout','case',$actor==='root'?'role':'grant') as $kind){
    xc_reset($actor);sd_sql(sd_revoke($kind));$before=xc_snapshot();$local=$board_config;sd_check(xc_run($request)==='error'&&xc_snapshot()===$before&&$board_config===$local,'Entry revocation preserves data and local settings');sd_unlocked();$cases++;
   }
   foreach(array('missing',$actor==='root'?'role':'grant') as $kind){for($boundary=1;$boundary<=count($boundaries);$boundary++){
    xc_reset($actor);$before=xc_snapshot();$seen=0;$reached=false;$blocked=false;
    $sd_hook=function($sql)use($boundary,$kind,&$seen,&$reached,&$blocked){
     if(!xc_boundary($sql)||++$seen!==$boundary){return;}$GLOBALS['sd_hook']=null;$reached=true;
     if(!$GLOBALS['peer']->sql_query(sd_revoke($kind))){$error=$GLOBALS['peer']->sql_error();sd_check((int)$error['code']===1205,'Only real lock timeout serializes');$blocked=true;}
    };
    $out=xc_run($request);sd_check($reached,'Every write/commit boundary reached');
    if($blocked){sd_check($out===$success&&xc_snapshot()===$expected,'Save precedes serialized revocation');sd_sql(sd_revoke($kind));$serialized++;}
    else{sd_check($out==='error'&&xc_snapshot()===$before,'Revoked operation rolls back all settings: '.$xc_mode.'/'.$kind.'/'.$boundary);}
    sd_check(xc_run($request)==='error','Subsequent revoked form denied');sd_unlocked();$cases++;
   }}
   foreach(array('COMMIT','lost-ack',"UPDATE fixture_config SET config_value='".(isset($request['xs_use_cache'])?'0':"new.example")."'", "WHERE config_name='xs_ftp_path'") as $failure){
    xc_reset($actor);$before=xc_snapshot();$local=$board_config;
    if(strpos($failure,'WHERE')===0){$sd_hook=function($sql)use($failure){if(strpos($sql,'UPDATE fixture_config ')===0&&strpos($sql,$failure)!==false){$GLOBALS['sd_failure']='UPDATE fixture_config ';$GLOBALS['sd_hook']=null;}};}
    else{$sd_failure=$failure;}
    sd_check(xc_run($request)==='error'&&xc_snapshot()===($failure==='lost-ack'?$expected:$before)&&$board_config===$local,'SQL/commit failure has no speculative local settings');sd_unlocked();$cases++;
    if($failure==='lost-ack'){$sd_failure='';sd_check(xc_run($request)===$success&&xc_snapshot()===$expected,'Safe no-op retry does not advance compile clock');sd_unlocked();$cases++;}
   }
   xc_reset($actor);$before=xc_snapshot();$sd_hook=function($sql,$owner){if(strpos($sql,'UPDATE fixture_config ')===0){$GLOBALS['sd_hook']=null;sd_sql('KILL CONNECTION '.mysqli_thread_id($owner->db_connect_id));}};
   sd_check(xc_run($request)==='error'&&xc_snapshot()===$before,'Lost owner cannot continue config writes');sd_unlocked();$cases++;
   echo $xc_mode.'/'.$actor." actual native boundaries passed\n";
  }
  $invalid=array(array('xs_ftp_host'=>array('bad')),array('xs_ftp_login'=>"\xc3"),array('xs_ftp_path'=>str_repeat('😀',256)),array('xs_ftp_path'=>addslashes("a\0b")),array('xs_ftp_host'=>'https://invalid.example'),array('sid'=>array('bad')),array('sid'=>'wrong'));
  if($xc_mode==='ftp'){$invalid[]=array('xs_ftp_pass'=>str_repeat('x',1025));$invalid[]=array('xs_ftp_pass'=>array('bad'));$invalid[]=array('xs_ftp_pass'=>addslashes("a\0b"));$invalid[]=array('xs_ftp_local'=>array('1'));}
  else{$invalid[]=array('xs_use_cache'=>'2');$invalid[]=array('xs_php'=>'../php');$invalid[]=array('xs_def_template'=>'other');$invalid[]=array('shownav_2'=>array('1'));$invalid[]=array('xs_template_time'=>'1');}
  foreach($invalid as $bad){xc_reset();$before=xc_snapshot();$local=$board_config;sd_check(xc_run(array_merge($request,$bad))!==$success&&xc_snapshot()===$before&&$board_config===$local,'Complete invalid form has no partial persistence');
   sd_check(!array_filter($sd_queries,function($sql){return preg_match('/^(UPDATE|INSERT|DELETE)\b/',$sql);}), 'Complete validation precedes all writes');sd_unlocked();$cases++;
  }
  xc_reset();$incomplete=$request;unset($incomplete['xs_ftp_path']);$before=xc_snapshot();sd_check(xc_run($incomplete)==='error'&&xc_snapshot()===$before,'Missing rendered field cannot silently reset settings');sd_unlocked();$cases++;
  xc_reset();$long=$request;$long['xs_ftp_path']=str_repeat('😀',255);sd_check(xc_run($long)===$success&&xc_snapshot()['xs_ftp_path']===str_repeat('😀',255),'Full VARCHAR character capacity without broken UTF8');sd_unlocked();$cases++;
  if($xc_mode==='ftp'){
   xc_reset();$local=$request;foreach(array('xs_ftp_host','xs_ftp_login','xs_ftp_path') as $field){$local[$field]='';}$local['xs_ftp_local']='1';$before=xc_snapshot();
   sd_check(xc_run($local,true)===$success&&xc_snapshot()===$before&&$board_config['xs_ftp_local']===true,'Local action preserves saved FTP defaults');sd_unlocked();$cases++;
   xc_reset();$before=xc_snapshot();sd_check(xc_run($local,false)==='error'&&xc_snapshot()===$before,'Local mode cannot bypass caller policy');sd_unlocked();$cases++;
  }
  foreach(array(CONFIG_TABLE,USERS_TABLE,SESSIONS_TABLE,JR_ADMIN_TABLE) as $table){foreach(array('ENGINE=MyISAM','ROW_FORMAT=COMPACT','CONVERT TO CHARACTER SET latin1') as $ddl){
   xc_reset();sd_sql('ALTER TABLE '.$table.' '.$ddl);$before=xc_snapshot();sd_check(xc_run($request)==='error'&&xc_snapshot()===$before,'Legacy storage refused');
   sd_sql('ALTER TABLE '.$table.' ENGINE=InnoDB ROW_FORMAT=DYNAMIC, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');sd_unlocked();$cases++;
  }}
  foreach(array('missing','alias') as $bad){xc_reset();sd_sql($bad==='missing'?"DELETE FROM fixture_config WHERE config_name='xs_ftp_path'":"UPDATE fixture_config SET config_name='XS_ftp_path' WHERE config_name='xs_ftp_path'");$before=xc_snapshot();sd_check(xc_run($request)==='error'&&xc_snapshot()===$before,'Missing/aliased setting requires explicit migration/repair');sd_unlocked();$cases++;}
  xc_reset();unlink($sd_root.'/cache/config_data.cache');mkdir($sd_root.'/cache/config_data.cache');
  try{sd_check(xc_run($request)==='error'&&xc_snapshot()===$expected,'Cache failure reports incomplete cleanup, not fictional rollback');sd_unlocked();}
  finally{rmdir($sd_root.'/cache/config_data.cache');}
  file_put_contents($sd_root.'/cache/config_data.cache','stale');sd_check(xc_run($request)===$success&&!is_file($sd_root.'/cache/config_data.cache'),'Successful no-op retry evicts leftover cache');sd_unlocked();$cases++;
  foreach(array('missing','role','disconnect') as $change){xc_reset();$sd_after_commit=function($owner)use($change){if($change==='disconnect'){sd_sql('KILL CONNECTION '.mysqli_thread_id($owner->db_connect_id));}else{sd_sql(sd_revoke($change));}$GLOBALS['sd_hook']=function($sql,$connection)use($owner){sd_check($connection!==$owner,'No writer SQL after confirmed commit');};};
   sd_check(xc_run($request)===$success,'Confirmed settings not reported failed by later revocation/disconnect');sd_unlocked();$cases++;
  }
  echo $xc_mode.' XS configuration native checks: '.$cases.' cases; '.$serialized." serialized revocations\n";
 }
}finally{
 $sd_hook=null;$sd_failure='';$sd_after_commit=null;$db->sql_close();$peer->sql_close();$control->sql_query('DROP DATABASE '.$fixture);$control->sql_close();chdir($sd_previous);
 foreach(array('config_data.cache','themes.cache','probe.cache') as $file){if(is_file($sd_root.'/cache/'.$file)){unlink($sd_root.'/cache/'.$file);}}
 foreach(array_reverse($sd_files) as $file){unlink($file);}foreach(array_reverse($sd_dirs) as $dir){rmdir($dir);}restore_error_handler();
}
PHP;
eval($xc_head . $xc_body);
