<?php
// Actual export controller with native SQL; all effects use an owned fixture.
if (PHP_SAPI !== 'cli' || getenv('PHPBB_STYLE_EXPORT_NATIVE') !== '1') { echo "Style export checks require an explicitly enabled disposable database.\n"; return; }
$mode = isset($argv[1]) ? $argv[1] : 'normal';
if (!in_array($mode, array('normal','pending','revoked','file','pending-file','revoked-file','delegated','revoked-grant','logout','bad-sid','concurrent-revoke','commit-failure','lost-ack','old-storage','invalid-default','long-preferences','zero','lost-owner-file','cache-failure','existing','update-failure','alias-preferences','ftp-quoted','ftp-zero','ftp-raw','ftp-refused','ftp-commit-failure','ftp-lost-ack'), true)) { throw new RuntimeException('Unknown case'); }
$scripts = __DIR__;
putenv('PHPBB_STYLE_DATA_NATIVE=1'); putenv('PHPBB_STYLE_DATA_PORT='.(getenv('PHPBB_STYLE_EXPORT_PORT')?:'3306')); putenv('PHPBB_STYLE_DATA_PASSWORD='.(getenv('PHPBB_STYLE_EXPORT_PASSWORD')?:''));
$fixture_source = file_get_contents($scripts . '/check-style-data-native.php');
$cut = strpos($fixture_source, " if (getenv('PHPBB_STYLE_DATA_PROBE')");
if ($cut === false) { throw new RuntimeException('Fixture boundary missing'); }
$head = str_replace('__DIR__', var_export($scripts, true), substr($fixture_source, 5, $cut - 5));
$head = str_replace('codex_style_data_', 'codex_style_export_probe_', $head);
$body = <<<'PHP'
 $production = file_get_contents($sd_source . 'admin/xs_include.php');
 foreach (array('STYLE_EXTENSION','STYLE_HEADER_START','STYLE_HEADER_END','TAR_HEADER_PACK','XS_MAX_STYLE_UPLOAD_BYTES','XS_MAX_STYLE_UNPACKED_BYTES','XS_MAX_STYLE_FILES','XS_MAX_ITEMS_PER_STYLE','XS_TPL_PATH') as $constant) {
  if (!preg_match('/define\(\x27'.$constant.'\x27, [^;]+;/', $production, $m)) { throw new RuntimeException('Missing constant'); } eval($m[0]);
 }
 define('XS_TEMP_DIR', $sd_root . '/cache/');
 $tokens = token_get_all($production);
 for ($i=0;$i<count($tokens);$i++) {
  if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) { continue; }
  $j=$i+1; while (is_array($tokens[$j]) && $tokens[$j][0]===T_WHITESPACE) { $j++; }
  if (!is_array($tokens[$j]) || !in_array($tokens[$j][1], array('xs_tpl_name','xs_generate_themeinfo','xs_escape_themeinfo_value','pack_style','pack_dir','set_export_method'), true)) { continue; }
  $function='';$depth=0;$started=false;
  for (;$i<count($tokens);$i++) { $token=$tokens[$i];$function.=is_array($token)?$token[1]:$token;if($token==='{' || (is_array($token)&&in_array($token[0],array(T_CURLY_OPEN,T_DOLLAR_OPEN_CURLY_BRACES),true))){$depth++;$started=true;}elseif($token==='}'){$depth--;}if($started&&!$depth){break;} }
  eval($function);
 }
 function xs_download_file($name,$bytes,$type) { $GLOBALS['download']=$bytes; throw new StyleDataExit('download'); }
 function xs_exit() { throw new StyleDataExit('unexpected exit'); }
 sd_check(preg_match('/CREATE TABLE phpbb_config\s*\([\s\S]*?;/',$schema,$m)===1,'Canonical config');
 sd_sql(str_replace('phpbb_config',CONFIG_TABLE,$m[0]));
 sd_reset(in_array($mode,array('delegated','revoked-grant'),true)?'delegated':'root'); sd_sql("INSERT INTO fixture_config VALUES ('default_style','1'),('unrelated','keep')");
 if(in_array($mode,array('existing','update-failure','alias-preferences'),true)){sd_sql("INSERT INTO fixture_config VALUES ('".($mode==='alias-preferences'?'Xs_export_data':'xs_export_data')."','".$peer->sql_escape(serialize(array('method'=>'file','dir'=>'before')))."')");}
 foreach (array('templates','templates/fisubsilversh') as $dir) { mkdir($sd_root.'/'.$dir,0700);$sd_dirs[]=$sd_root.'/'.$dir; }
 file_put_contents($sd_root.'/templates/fisubsilversh/theme_info.cfg','original');$sd_files[]=$sd_root.'/templates/fisubsilversh/theme_info.cfg';
 file_put_contents($sd_root.'/templates/fisubsilversh/page.tpl','partially imported files');$sd_files[]=$sd_root.'/templates/fisubsilversh/page.tpl';
 if($mode==='cache-failure'){mkdir($sd_root.'/cache/config_data.cache',0700);}else{file_put_contents($sd_root.'/cache/config_data.cache','stale preferences');}$sd_files[]=$sd_root.'/cache/config_data.cache';
 if ($mode==='pending'||$mode==='pending-file') {
  require_once $sd_source.'includes/functions_style_import_receipt.php';
  $receipt=array('v'=>1,'t'=>'fisubsilversh','o'=>str_repeat('a',32),'s'=>'prepared','h'=>str_repeat('b',64),'a'=>str_repeat('c',64));
  sd_sql("INSERT INTO fixture_config VALUES ('".phpbb_style_import_receipt_key('fisubsilversh')."','".$peer->sql_escape(json_encode($receipt))."')");
 }
 if ($mode==='revoked'||$mode==='revoked-file') { sd_sql('UPDATE fixture_users SET user_level=0'); }
 if ($mode==='revoked-grant') { sd_sql('DELETE FROM fixture_jr'); }
 if ($mode==='logout') { sd_sql('UPDATE fixture_sessions SET session_logged_in=0'); }
 if ($mode==='old-storage') { sd_sql('ALTER TABLE fixture_themes ENGINE=MyISAM'); }
 if ($mode==='invalid-default') { sd_sql("DELETE FROM fixture_config WHERE config_name='default_style'"); }
 if ($mode==='zero') {
  sd_sql("UPDATE fixture_themes SET template_name='0' WHERE themes_id=1");mkdir($sd_root.'/templates/0',0700);$sd_dirs[]=$sd_root.'/templates/0';
  file_put_contents($sd_root.'/templates/0/theme_info.cfg','original');$sd_files[]=$sd_root.'/templates/0/theme_info.cfg';
 }
 $_POST=$HTTP_POST_VARS=array('sid'=>'fixture-admin','export'=>'fisubsilversh','total'=>'1','export_style_0'=>'1','export_style_id_0'=>'1','export_style_name_0'=>'Fixture Größe','export_to'=>'save');
 if($mode==='bad-sid'){$_POST['sid']=$HTTP_POST_VARS['sid']='wrong';}
 if($mode==='zero'){$_POST['export']=$HTTP_POST_VARS['export']='0';$_POST['export_template']=$HTTP_POST_VARS['export_template']='0';}
 $file_mode=in_array($mode,array('file','pending-file','revoked-file','lost-owner-file'),true);
 if($file_mode){$_POST['export_to']=$HTTP_POST_VARS['export_to']='file';$sd_files[]=$sd_root.'/cache/fisubsilversh.style';}
 $ftp_mode=in_array($mode,array('ftp-quoted','ftp-zero','ftp-raw','ftp-refused','ftp-commit-failure','ftp-lost-ack'),true);$ftp_process=null;$ftp_pipes=array();
 if($ftp_mode){
  sd_check(function_exists('ftp_connect')&&function_exists('proc_open'),'Native FTP extension and child process required');
  $remote=$sd_root.'/ftp-export';mkdir($remote,0700);$sd_dirs[]=$remote;$sd_files[]=$remote.'/received.style';
  // Only the connection port is routed to the disposable server. Authentication,
  // directory selection, file transfer and preference completion are production.
  $child=escapeshellarg(PHP_BINARY).(DIRECTORY_SEPARATOR==='\\'?' -n':'').' '.escapeshellarg($scripts.'/fixtures/style-export-ftp-server.php').' '.escapeshellarg($remote).' '.escapeshellarg(substr($mode,4));
  $ftp_process=proc_open($child,array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$ftp_pipes,sys_get_temp_dir(),null,array('bypass_shell'=>true));
  sd_check(is_resource($ftp_process),'Owned FTP fixture started');fclose($ftp_pipes[0]);stream_set_timeout($ftp_pipes[1],15);
  $ftp_port=trim(fgets($ftp_pipes[1]));sd_check(ctype_digit($ftp_port)&&(int)$ftp_port>0&&(int)$ftp_port<=65535,'Owned ephemeral loopback FTP port');
  $_POST=array_merge($_POST,array('export_to'=>'ftp','export_to_ftp_host'=>'127.0.0.1','export_to_ftp_login'=>"fixture'\\user",'export_to_ftp_pass'=>"fixture'\\pass",'export_to_ftp_dir'=>$mode==='ftp-zero'?'0':"folder's"));
  if($mode==='ftp-quoted'){define('PHPBB_LEGACY_REQUEST_ESCAPED',true);$_POST=phpbb_addslashes_recursive($_POST);}
  $HTTP_POST_VARS=$_POST;
 }
 if($mode==='commit-failure'){$sd_failure='COMMIT';}if($mode==='lost-ack'){$sd_failure='lost-ack';}
 if($mode==='ftp-commit-failure'){$sd_failure='COMMIT';}if($mode==='ftp-lost-ack'){$sd_failure='lost-ack';}
 if($mode==='update-failure'){$sd_failure='UPDATE fixture_config ';}
 $serialized=false;
 if($mode==='concurrent-revoke'){$sd_hook=function($sql)use(&$serialized){if(strpos($sql,'INSERT INTO fixture_config (')!==0){return;}$GLOBALS['sd_hook']=null;sd_check(!$GLOBALS['peer']->sql_query('UPDATE fixture_users SET user_level=0'),'Current authority must remain locked during delivery');$error=$GLOBALS['peer']->sql_error();sd_check((int)$error['code']===1205,'Native row-lock timeout only');$serialized=true;};}
 $lost_owner=false;$authority_checks=0;
 if($mode==='lost-owner-file'){$sd_hook=function($sql,$connection)use(&$authority_checks,&$lost_owner){if(strpos($sql,'SELECT session_id FROM fixture_sessions ')!==0||++$authority_checks!==2){return;}$GLOBALS['sd_hook']=null;$lost_owner=true;sd_check($GLOBALS['peer']->sql_query('KILL CONNECTION '.mysqli_thread_id($connection->db_connect_id)),'Only this fixture dedicated owner connection killed');};}
 $HTTP_GET_VARS=array();$template_dir='templates/';$board_config=array();$download=null;$phpbb_root_path='../';
 $before=sd_snapshot();$before_config=phpbb_acl_rows($peer,'SELECT * FROM fixture_config ORDER BY config_name');
 if($mode==='long-preferences'){
  require_once $sd_source.'includes/functions_style_export.php';
  list($owner,$rows)=phpbb_style_export_open($db,$HTTP_POST_VARS,'fisubsilversh',array(1=>'Fixture Größe'));
  try{phpbb_style_export_complete($owner,'ftp',array('host'=>str_repeat('h',255),'login'=>str_repeat('u',255),'ftpdir'=>str_repeat('d',255)));}finally{$owner->rollback();$owner->release();}
  $outcome='preferences';
 }else{
  try {
   if($ftp_mode){
    $controller=file_get_contents($sd_source.'admin/xs_export.php');
    $controller=str_replace('@ftp_connect($ftp_host, 21, 10)','@ftp_connect($ftp_host, '.(int)$ftp_port.', 10)',$controller,$replacements);
    sd_check($replacements===1,'Exactly one test-only FTP port seam');
    eval(substr(str_replace('__DIR__',var_export($sd_source.'admin',true),$controller),5));
   }else{include $sd_source.'admin/xs_export.php';}
   throw new RuntimeException('Controller did not terminate');
  }
  catch (StyleDataExit $e) { $outcome=$e->getMessage(); }
 }
 $rows=phpbb_acl_rows($peer,"SELECT config_name FROM fixture_config WHERE config_name='xs_export_data'");
 $denied=in_array($mode,array('pending','revoked','pending-file','revoked-file','revoked-grant','logout','bad-sid','old-storage','commit-failure','lost-owner-file','update-failure','alias-preferences','ftp-refused','ftp-commit-failure'),true);
 $previous_preferences=in_array($mode,array('update-failure','alias-preferences'),true)?1:0;
 if($denied){sd_check(!in_array($outcome,array('saved','download'),true)&&$download===null&&count($rows)===$previous_preferences,'Denied request publishes neither download nor new preferences');sd_check(phpbb_acl_rows($peer,'SELECT * FROM fixture_config ORDER BY config_name')===$before_config,'Denied request preserves all configuration');}
 elseif($mode==='lost-ack'||$mode==='ftp-lost-ack'){sd_check($outcome==='error'&&$download===null&&count($rows)===1,'Lost commit acknowledgement is not reported as confirmed delivery');}
 elseif($mode==='cache-failure'){sd_check($outcome==='error'&&$download===null&&count($rows)===1,'Cache failure does not report success after preferences commit');}
 elseif($mode==='long-preferences'){$stored=phpbb_acl_rows($peer,"SELECT config_value FROM fixture_config WHERE config_name='xs_export_data'");sd_check($stored[0]['config_value']===serialize(array('method'=>'ftp')),'Long optional preferences retain the method without truncation');}
 else{sd_check($outcome===($file_mode||$ftp_mode?'saved':'download')&&count($rows)===1,'Authorized actual delivery and preference write');if(!$file_mode&&!$ftp_mode){sd_check(is_string($download)&&$download!=='','Download bytes returned');}}
 if($ftp_mode){
  if($mode==='ftp-refused'){sd_check(!file_exists($remote.'/received.style'),'Refused transfer creates no remote artifact');}
  else{sd_check(file_get_contents($remote.'/received.style')===$data,'Native FTP preserves the complete actual package bytes, including uncertain SQL completion');}
  if($rows){$stored=phpbb_acl_rows($peer,"SELECT config_value FROM fixture_config WHERE config_name='xs_export_data'");
   sd_check(unserialize($stored[0]['config_value'])===array('host'=>'127.0.0.1','login'=>"fixture'\\user",'ftpdir'=>$mode==='ftp-zero'?'0':"folder's",'method'=>'ftp'),'Preferences decode once and never store the password');}
  sd_check(!glob($sd_root.'/cache/xs_export_*'),'FTP temporary source cleaned');
 }
 if($mode==='existing'){$stored=phpbb_acl_rows($peer,"SELECT config_value FROM fixture_config WHERE config_name='xs_export_data'");sd_check($stored[0]['config_value']===serialize(array('method'=>'save')),'Existing preferences are updated rather than duplicated');}
 sd_check(sd_snapshot()===$before,'Export preserves original theme definitions and labels');
 if($file_mode){sd_check(file_exists($sd_root.'/cache/fisubsilversh.style')!==$denied,'Local delivery guarded before file publication');}
 if($mode==='concurrent-revoke'){sd_check($serialized,'Native concurrent revocation attempted at preference write');sd_sql('UPDATE fixture_users SET user_level=0');}
 if($mode==='lost-owner-file'){sd_check($lost_owner,'Lost owner fault actually reached');}
 if(!$denied||in_array($mode,array('commit-failure','update-failure','ftp-commit-failure'),true)){if($mode!=='cache-failure'){sd_check(!file_exists($sd_root.'/cache/config_data.cache'),'Committed or uncertain preference write evicts stale cache');}}
 sd_unlocked();
 sd_check(file_get_contents($sd_root.'/templates/fisubsilversh/theme_info.cfg')==='original'&&file_get_contents($sd_root.'/templates/fisubsilversh/page.tpl')==='partially imported files','Export never modifies source files');
 echo 'Style export native '.$mode." passed\n";
PHP;
$tail = <<<'PHP'
} finally {
 if(isset($ftp_process)&&is_resource($ftp_process)){foreach($ftp_pipes as $pipe){if(is_resource($pipe)){fclose($pipe);}}$status=proc_get_status($ftp_process);if($status['running']){proc_terminate($ftp_process);}proc_close($ftp_process);}
 $sd_hook=null;$sd_failure='';$db->sql_close();$peer->sql_close();$control->sql_query('DROP DATABASE '.$fixture);$control->sql_close();chdir($sd_previous);
 if(is_file($sd_root.'/cache/themes.cache')){unlink($sd_root.'/cache/themes.cache');}
 if(is_dir($sd_root.'/cache/config_data.cache')){rmdir($sd_root.'/cache/config_data.cache');}
 foreach(array_reverse($sd_files) as $file){if(is_file($file)){unlink($file);}}foreach(array_reverse($sd_dirs) as $dir){rmdir($dir);}restore_error_handler();
}
PHP;
eval($head . $body . $tail);
