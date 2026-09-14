<?php
define('IN_PHPBB',true);define('ADMIN',1);define('USER',0);define('ANONYMOUS',-1);define('POST_NORMAL',0);define('POST_STICKY',1);
define('GENERAL_MESSAGE',1);define('GENERAL_ERROR',2);define('CRITICAL_ERROR',3);
define('CONFIG_TABLE','fixture_config');define('USERS_TABLE','fixture_users');define('SESSIONS_TABLE','fixture_sessions');define('JR_ADMIN_TABLE','fixture_jr');
function ms_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
class ModSettingsExit extends RuntimeException {}
function message_die($level,$message){throw new ModSettingsExit($message);}
function append_sid($url){return $url;}
$ms_source=dirname(dirname(__DIR__)).'/phpBB2/';$phpbb_root_path=$ms_source;$phpEx='php';
require $ms_source.'includes/php_compat.php';require $ms_source.'includes/functions_mod_settings_storage.php';require $ms_source.'includes/functions_jr_admin.php';
$bootstrap=file_get_contents($ms_source.'admin/pagestart.php');$a=strpos($bootstrap,"if (!function_exists('phpbb_admin_post_session_valid'))");$b=strpos($bootstrap,'if (empty($no_page_header))',$a);
ms_check($a!==false&&$b>$a,'Actual ACP form helpers');eval(substr($bootstrap,$a,$b-$a));
$lang=array('Board_config_invalid'=>'invalid','Board_config_failed'=>'storage','Mod_settings_update_required'=>'migration',
 'Config_updated'=>'saved','Click_return_config'=>'%sconfig%s','Click_return_admin_index'=>'%sadmin%s','Happy_birthday'=>'birthday','Post_Global_Announcement'=>'global');
$userdata=array('user_id'=>1,'user_level'=>1,'session_logged_in'=>true,'session_admin'=>true,'session_id'=>'fixture-admin','user_birthday'=>0);
$board_config=array();$mods=array();foreach(glob($ms_source.'includes/mods_settings/mod_*.php') as $file){include $file;}
$ms_defaults=require dirname($ms_source).'/update/mod_settings_defaults.php';
$ms_sections=array();$ms_selectors=array();$ms_users=array('user_birthday'=>0);ksort($mods);$mi=0;
foreach($mods as $menu){ksort($menu['data']);$di=0;foreach($menu['data'] as $name=>$mod){ksort($mod['data']);$si=0;foreach($mod['data'] as $subname=>$sub){
 ms_check(empty($menu['sort'])&&empty($mod['sort'])&&empty($sub['sort']),'Registry currently uses lexical ordering');
 $section=$name.($subname===''?'':' / '.$subname);$ms_sections[$section]=$sub['data'];
 $ms_selectors[$section]=array('menu_id'=>(string)$mi,'mod_id'=>(string)$di,'sub_id'=>(string)$si++);
 foreach($sub['data'] as $field){if(!empty($field['user'])){$ms_users[$field['user']]=0;}}
 }$di++;}$mi++;}
ms_check(count($ms_sections)===6,'Six actual sections');$userdata+=$ms_users;
foreach($ms_sections as $name=>$fields){$request=array();foreach($fields as $key=>$field){$request[$key]=(string)$ms_defaults[$key];if(!empty($field['user'])){$request[$key.'_over']='0';}}
 ms_check(phpbb_mod_settings_values($request,$fields,$ms_defaults)===$request,'All displayed controls/overrides: '.$name);
}
$ajax=$ms_sections['AJAX_features'];
foreach(array(array('use_ajax_edit'=>'2'),array('use_ajax_edit'=>array('1')),array('use_ajax_edit_over'=>'2'),array('use_ajax_edit_over'=>array('1')),array('version'=>'2'),array('max_posts'=>'2'),array('calendar_nb_row'=>'2'),array()) as $request){
 $denied=false;try{phpbb_mod_settings_values($request,$ajax,$ms_defaults);}catch(PhpbbAclException $e){$denied=true;}ms_check($denied,'Reject forged/hidden/foreign/empty controls');
}
foreach(array('-1','2x','1.5','1000',str_repeat('9',20)) as $value){$denied=false;try{phpbb_mod_settings_values(array('calendar_nb_row'=>$value),$ms_sections['Calendar'],$ms_defaults);}catch(PhpbbAclException $e){$denied=true;}ms_check($denied,'Strict bounded numeric input');}
ms_check(phpbb_mod_settings_values(array('calendar_title_length'=>'365'),$ms_sections['Calendar'],$ms_defaults)===array('calendar_title_length'=>'365'),'Preserve existing three-digit form values beyond SQL TINYINT range');
$textfields=array('example'=>array('type'=>'TEXT'));ms_check(phpbb_mod_settings_values(array('example'=>addslashes("Ä ' \\ 😀")),$textfields,array('example'=>''))===array('example'=>"Ä ' \\ 😀"),'Extension text decodes once');
foreach(array("\xc3",str_repeat('x',256),"a\0b") as $value){$denied=false;try{phpbb_mod_settings_values(array('example'=>addslashes($value)),$textfields,array('example'=>''));}catch(PhpbbAclException $e){$denied=true;}ms_check($denied,'Invalid extension text rejected');}
echo "Mod Settings input/section/override checks passed\n";
if(PHP_SAPI!=='cli'||getenv('PHPBB_MOD_SETTINGS_NATIVE')!=='1'){return;}
require $ms_source.'db/mysqli.php';$port=getenv('PHPBB_MOD_SETTINGS_PORT')?:'3306';$password=getenv('PHPBB_MOD_SETTINGS_PASSWORD')?:'';
ms_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Loopback port');$host='127.0.0.1:'.$port;
$control=new sql_db($host,'root',$password,'',false);$fixture='codex_mod_settings_'.bin2hex(function_exists('random_bytes')?random_bytes(8):openssl_random_pseudo_bytes(8));
ms_check($control->sql_query('CREATE DATABASE '.$fixture.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Own schema');
class ModSettingsConnection {
 var $connection;var $db_connect_id;
 function __construct($db){$this->connection=$db;$this->db_connect_id=$db->db_connect_id;}
 function __call($method,$args){return call_user_func_array(array($this->connection,$method),$args);}
 function sql_query($sql,$transaction=false){
  $GLOBALS['ms_queries'][]=$sql;if(is_callable($GLOBALS['ms_hook'])){call_user_func($GLOBALS['ms_hook'],$sql);}
  if($GLOBALS['ms_failure']!==''&&strpos($sql,$GLOBALS['ms_failure'])===0){return false;}
  $result=$this->connection->sql_query($sql,$transaction);if($sql==='COMMIT'&&$GLOBALS['ms_failure']==='commit-ack'){return false;}return $result;
 }
}
class ModSettingsDatabase extends sql_db {function sql_dedicated_connection(){return new ModSettingsConnection(parent::sql_dedicated_connection());}}
$db=new ModSettingsDatabase($host,'root',$password,$fixture,false);unset($db->password);$peer=new sql_db($host,'root',$password,$fixture,false);
$ms_queries=array();$ms_hook=null;$ms_failure='';
function ms_sql($sql){$result=$GLOBALS['peer']->sql_query($sql);ms_check($result,'Fixture SQL');return $result;}
function ms_snapshot(){return phpbb_board_config_read($GLOBALS['peer']);}
function ms_reset($actor){
 global $ms_hook,$ms_failure,$ms_queries,$userdata,$board_config;
 $ms_hook=null;$ms_failure='';$ms_queries=array();foreach(array(CONFIG_TABLE,USERS_TABLE,SESSIONS_TABLE,JR_ADMIN_TABLE) as $table){ms_sql('DELETE FROM '.$table);}
 foreach($GLOBALS['ms_defaults'] as $key=>$value){ms_sql("INSERT INTO fixture_config VALUES ('".$key."','".$value."')");}ms_sql("INSERT INTO fixture_config VALUES ('version','original')");
 ms_sql('INSERT INTO fixture_users VALUES (1,'.($actor==='root'?1:0).',1)');ms_sql("INSERT INTO fixture_sessions VALUES ('fixture-admin',1,1,1)");
 if($actor!=='root'){$route=$actor==='delegated'?'admin_board_extend.php':'admin_board.php';$hash=array_search($route,jr_admin_authorization_routes(),true);ms_check($hash!==false,'Registered exact route');ms_sql("INSERT INTO fixture_jr VALUES (1,'".$hash."')");}
 $userdata=array('user_id'=>1,'user_level'=>$actor==='root'?1:0,'session_logged_in'=>true,'session_admin'=>true,'session_id'=>'fixture-admin')+$GLOBALS['ms_users'];
 $board_config=$GLOBALS['ms_defaults'];file_put_contents($GLOBALS['ms_root'].'/cache/config_data.cache','old-cache');$_SERVER['REQUEST_METHOD']='POST';$_GET=array();
}
function ms_run($request){
 global $db,$lang,$phpEx,$phpbb_root_path,$userdata,$board_config,$mods,$list_yes_no;
 $_POST=array_merge(array('sid'=>'fixture-admin','submit'=>'Save','menu_id'=>'0','mod_id'=>'0','sub_id'=>'0'),$request);
 try{include $GLOBALS['ms_source'].'admin/admin_board_extend.php';throw new RuntimeException('Controller did not stop');}catch(ModSettingsExit $e){return $e->getMessage();}
}
function ms_boundary($sql){return strpos($sql,'UPDATE fixture_config ')===0||$sql==='COMMIT'||strpos($sql,' LOCK IN SHARE MODE')!==false;}
function ms_revoke($kind){$sql=array('inactive'=>'UPDATE fixture_users SET user_active=0','role'=>'UPDATE fixture_users SET user_level=0','grant'=>'DELETE FROM fixture_jr','missing'=>'DELETE FROM fixture_sessions','admin-off'=>'UPDATE fixture_sessions SET session_admin=0','foreign'=>'UPDATE fixture_sessions SET session_user_id=99','logout'=>'UPDATE fixture_sessions SET session_logged_in=0','case'=>"UPDATE fixture_sessions SET session_id='FIXTURE-ADMIN'");return $sql[$kind];}
$ms_root=sys_get_temp_dir().'/'.$fixture;$previous=getcwd();$files=array();$dirs=array();
try{
 set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
 foreach(array('','admin','includes','includes/mods_settings','cache') as $dir){$path=$ms_root.($dir===''?'':'/'.$dir);mkdir($path,0700);$dirs[]=$path;}
 $files['extension.inc']="<?php \$phpEx='php';";$files['admin/pagestart.php']="<?php // Fixture identity only; real writer revalidates all authority.\n";
 foreach(array('includes/functions_mods_settings.php','includes/functions_mod_settings_storage.php') as $file){$files[$file]='<?php require_once '.var_export($ms_source.$file,true).';';}
 foreach(glob($ms_source.'includes/mods_settings/mod_*.php') as $file){$files['includes/mods_settings/'.basename($file)]='<?php include '.var_export($file,true).';';}
 foreach($files as $file=>$body){file_put_contents($ms_root.'/'.$file,$body);}chdir($ms_root.'/admin');
 $schema=file_get_contents($ms_source.'install/schemas/mysql_schema.sql');ms_check(preg_match('/CREATE TABLE phpbb_config\s*\([\s\S]*?;/',$schema,$m)===1,'Canonical configDDL');ms_sql(str_replace('phpbb_config',CONFIG_TABLE,$m[0]));
 foreach(array('fixture_users'=>'user_id INT PRIMARY KEY,user_level INT,user_active INT','fixture_sessions'=>'session_id VARCHAR(32) PRIMARY KEY,session_user_id INT,session_logged_in INT,session_admin INT','fixture_jr'=>'user_id INT PRIMARY KEY,user_jr_admin TEXT') as $table=>$columns){ms_sql('CREATE TABLE '.$table.' ('.$columns.') ENGINE=InnoDB ROW_FORMAT=DYNAMIC');}ms_sql('SET SESSION innodb_lock_wait_timeout=1');
 $cases=0;$serialized=0;
 foreach(array('root','delegated') as $actor){
  ms_reset($actor);ms_check(strpos(ms_run(array('use_ajax_preview'=>'0','use_ajax_edit'=>'0','use_ajax_edit_over'=>'1')),'saved')===0,'Actual controller save: '.$actor);$after=ms_snapshot();
  ms_check($after['use_ajax_preview']==='0'&&$after['use_ajax_edit']==='0'&&$after['use_ajax_edit_over']==='1'&&$after['version']==='original','Only selected requested settings changed');
  ms_check(!is_file($ms_root.'/cache/config_data.cache'),'Successful save evicts cache');$boundaries=array_values(array_filter($ms_queries,'ms_boundary'));
  foreach(array('inactive','missing','admin-off','foreign','logout','case',$actor==='root'?'role':'grant') as $kind){ms_reset($actor);ms_sql(ms_revoke($kind));$before=ms_snapshot();ms_check(ms_run(array('use_ajax_edit'=>'0'))==='Not_Authorised'&&ms_snapshot()===$before,'Entry revocation');$cases++;}
  foreach(array('missing',$actor==='root'?'role':'grant') as $kind){for($boundary=1;$boundary<=count($boundaries);$boundary++){
   ms_reset($actor);$before=ms_snapshot();$seen=0;$reached=false;$blocked=false;
   $ms_hook=function($sql)use($boundary,$kind,&$seen,&$reached,&$blocked){if(!ms_boundary($sql)||++$seen!==$boundary){return;}$GLOBALS['ms_hook']=null;$reached=true;if(!$GLOBALS['peer']->sql_query(ms_revoke($kind))){$error=$GLOBALS['peer']->sql_error();ms_check((int)$error['code']===1205,'Only lock timeout serializes');$blocked=true;}};
   $outcome=ms_run(array('use_ajax_preview'=>'0','use_ajax_edit'=>'0','use_ajax_edit_over'=>'1'));ms_check($reached,'Every write/commit boundary reached');
   if($blocked){ms_check(strpos($outcome,'saved')===0,'Commit before serialized revocation');ms_sql(ms_revoke($kind));$serialized++;}
   else{ms_check($outcome==='Not_Authorised'&&ms_snapshot()===$before,'Revocation rolls back all settings');}
   ms_check(ms_run(array('use_ajax_edit'=>'0'))==='Not_Authorised','Following request denied');$cases++;
  }}
  foreach(array(array('use_ajax_edit'=>'0','use_ajax_preview'=>'bad'),array('use_ajax_edit'=>'0','use_ajax_edit_over'=>'2'),array('use_ajax_edit'=>array('0')),array('use_ajax_edit'=>'0','max_posts'=>'3'),array('use_ajax_edit'=>'0','menu_id'=>array('0')),array('use_ajax_edit'=>'0','mod_id'=>'99'),array('use_ajax_edit'=>'0','sid'=>'wrong')) as $request){
   ms_reset($actor);$before=ms_snapshot();ms_check(strpos(ms_run($request),'saved')!==0&&ms_snapshot()===$before,'Complete invalid request rejected');ms_check(!array_filter($ms_queries,function($sql){return preg_match('/^(UPDATE|INSERT|DELETE|COMMIT)\b/',$sql);}), 'No writes before validation');$cases++;
  }
  ms_reset($actor);$before=ms_snapshot();$ms_failure="UPDATE fixture_config SET config_value='0' WHERE config_name='use_ajax_edit'";
  ms_check(ms_run(array('use_ajax_preview'=>'0','use_ajax_edit'=>'0'))==='storage'&&ms_snapshot()===$before,'Second write failure rolls back first');ms_check(!is_file($ms_root.'/cache/config_data.cache'),'Failure after writes evicts cache');$cases++;
  foreach(array('COMMIT','commit-ack') as $failure){ms_reset($actor);$before=ms_snapshot();$ms_failure=$failure;ms_check(ms_run(array('use_ajax_edit'=>'0'))==='storage','Commit uncertainty reports error');ms_check($failure==='COMMIT'?ms_snapshot()===$before:ms_snapshot()['use_ajax_edit']==='0','Known rollback versus lost acknowledgement');ms_check(!is_file($ms_root.'/cache/config_data.cache'),'Uncertain commit evicts stale cache');$cases++;}
  ms_reset($actor);$ms_hook=function($sql){if(strpos($sql,'UPDATE fixture_config ')===0){$GLOBALS['ms_hook']=null;ms_sql("UPDATE fixture_config SET config_value='concurrent' WHERE config_name='version'");}};
  ms_check(strpos(ms_run(array('use_ajax_edit'=>'0')),'saved')===0&&ms_snapshot()['version']==='concurrent','Concurrent unrelated configuration preserved');$cases++;
  echo $actor." Config+ native controller and revocation checks passed\n";
 }
 ms_reset('wrong-route');$before=ms_snapshot();ms_check(ms_run(array('use_ajax_edit'=>'0'))==='Not_Authorised'&&ms_snapshot()===$before,'Main config grant cannot authorize Config+');
 foreach($ms_sections as $name=>$fields){ms_reset('root');$request=$ms_selectors[$name];foreach($fields as $key=>$field){$request[$key]=(string)$ms_defaults[$key];if(!empty($field['user'])){$request[$key.'_over']='1';}}ms_check(strpos(ms_run($request),'saved')===0,'Actual controller entire section: '.$name);}
 ms_reset('root');ms_check(strpos(ms_run(array_merge($ms_selectors['Calendar'],array('calendar_title_length'=>'365'))),'saved')===0&&ms_snapshot()['calendar_title_length']==='365','Actual controller preserves existing three-digit settings');
 foreach(array('ENGINE=MyISAM','ROW_FORMAT=COMPACT','CONVERT TO CHARACTER SET latin1','MODIFY config_value VARCHAR(255) CHARACTER SET latin1 NOT NULL') as $legacy){ms_reset('root');ms_sql('ALTER TABLE fixture_config '.$legacy);$before=ms_snapshot();ms_check(ms_run(array('use_ajax_edit'=>'0'))==='storage'&&ms_snapshot()===$before,'Reject legacy table/column policy');ms_sql('DROP TABLE fixture_config');ms_sql(str_replace('phpbb_config',CONFIG_TABLE,$m[0]));}
 ms_reset('root');$ms_hook=function($sql){if(strpos($sql,'START TRANSACTION')===0){$GLOBALS['ms_hook']=null;ms_sql("DELETE FROM fixture_config WHERE config_name='use_ajax_edit_over'");}};
 ms_check(ms_run(array('use_ajax_edit'=>'0'))==='migration'&&ms_snapshot()['use_ajax_edit']==='1','Required row disappears before transactional lock');
 echo 'Native Config+ checks: '.$cases.' cases; '.$serialized." serialized before revocation; all six sections passed\n";
}finally{
 $ms_hook=null;$ms_failure='';$db->sql_close();$peer->sql_close();$control->sql_query('DROP DATABASE '.$fixture);$control->sql_close();chdir($previous);
 if(is_file($ms_root.'/cache/config_data.cache')){unlink($ms_root.'/cache/config_data.cache');}foreach($files as $file=>$body){unlink($ms_root.'/'.$file);}foreach(array_reverse($dirs) as $dir){rmdir($dir);}restore_error_handler();
}
