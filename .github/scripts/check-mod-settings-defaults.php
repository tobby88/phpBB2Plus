<?php
// Registration must be safe in guest/profile/bootstrap reads, without SQL.
set_error_handler(function($severity,$message,$file,$line){if(error_reporting()&$severity){throw new ErrorException($message,0,$severity,$file,$line);}return false;});
define('IN_PHPBB',true);define('USER',0);define('ANONYMOUS',-1);
define('POST_NORMAL',0);define('POST_STICKY',1);define('GENERAL_ERROR',1);define('CRITICAL_ERROR',2);
define('CONFIG_TABLE','fixture_config');
function md_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
class ModDefaultsExit extends RuntimeException {}
function message_die($level,$message){throw new ModDefaultsExit($message);}
class ModDefaultsReadOnly {
 var $rows=array();var $position=0;var $queries=0;
 function sql_query($sql){md_check($sql==='SELECT * FROM '.CONFIG_TABLE,'No bootstrap/default writes');$this->queries++;$this->position=0;return true;}
 function sql_fetchrow($result){return isset($this->rows[$this->position])?$this->rows[$this->position++]:false;}
}
$source=dirname(dirname(__DIR__)).'/';$phpbb_root_path=$source.'phpBB2/';$phpEx='php';
$lang=array('Mod_settings_update_required'=>'migration');$mods=array();$board_config=array();
$userdata=array('user_id'=>ANONYMOUS,'session_logged_in'=>false);$db=new ModDefaultsReadOnly();
$registry=glob($phpbb_root_path.'includes/mods_settings/mod_*.php');
foreach($registry as $path){include $path;}
$defaults=require $source.'update/mod_settings_defaults.php';ksort($defaults);
$actual=array_map('strval',$board_config);ksort($actual);
md_check(count($registry)===6&&count($defaults)===55&&$actual===$defaults,'All actual registry defaults, hidden fields and override flags');
md_check($db->queries===0,'Guest registration never queries SQL');
$board_config=array('use_ajax_edit'=>'0','calendar_nb_row'=>'9');
foreach($registry as $path){include $path;}
md_check($board_config['use_ajax_edit']==='0'&&$board_config['calendar_nb_row']==='9','Existing customized values survive registration');
init_board_config_key('use_ajax_edit',1,true);
md_check($board_config['use_ajax_edit']===1&&$db->queries===0,'Force is request-local only');
$userdata=array('user_id'=>12,'session_logged_in'=>true,'user_use_ajax_edit'=>0);
$board_config=array('use_ajax_edit'=>1,'use_ajax_edit_over'=>0);user_board_config_key('use_ajax_edit');
md_check($board_config['use_ajax_edit']===0,'Logged-in user preference preserved');
$board_config=array('use_ajax_edit'=>1,'use_ajax_edit_over'=>1);user_board_config_key('use_ajax_edit');
md_check($board_config['use_ajax_edit']===1&&$userdata['user_use_ajax_edit']===1,'Board override preserved');
$seeds=array();$basic=file_get_contents($source.'phpBB2/install/schemas/mysql_basic.sql');
foreach($defaults as $key=>$value){
 $pattern="/INSERT INTO phpbb_config \\(config_name, config_value\\) VALUES \\('".preg_quote($key,'/')."', '".preg_quote($value,'/')."'\\);/";
 md_check(preg_match_all($pattern,$basic,$matches)===1,'Exactly one matching fresh-install seed: '.$key);$seeds[]=$matches[0][0];
}
// Run the real controller through its missing-row gate in an owned temporary
// tree. Only bootstrap identity is supplied; registry/controller are unchanged.
function phpbb_admin_require_post_session(){md_check(!empty($GLOBALS['md_valid_session']),'Session gate');}
$temporary=sys_get_temp_dir().'/codex_mod_defaults_'.bin2hex(function_exists('random_bytes')?random_bytes(8):openssl_random_pseudo_bytes(8));
$files=array();$directories=array($temporary,$temporary.'/admin',$temporary.'/includes',$temporary.'/includes/mods_settings');
$cwd=getcwd();
try{
 foreach($directories as $directory){md_check(mkdir($directory,0700),'Owned fixture directory');}
 $files[$temporary.'/extension.inc']="<?php \$phpEx='php';";$files[$temporary.'/admin/pagestart.php']="<?php\n";
 $files[$temporary.'/includes/functions_mods_settings.php']='<?php require_once '.var_export($source.'phpBB2/includes/functions_mods_settings.php',true).';';
 // Forward all bootstrap paths to the actual registry and helper files.
 foreach($registry as $path){$files[$temporary.'/includes/mods_settings/'.basename($path)]='<?php include '.var_export($path,true).';';}
 foreach($files as $path=>$body){md_check(file_put_contents($path,$body)!==false,'Fixture bootstrap');}
 chdir($temporary.'/admin');$md_valid_session=true;
 $board_config=array();$userdata=array('user_id'=>ANONYMOUS,'session_logged_in'=>false);
 $_POST=array('submit'=>'Save');$_GET=array();$outcome='';
 try{include $source.'phpBB2/admin/admin_board_extend.php';}catch(ModDefaultsExit $e){$outcome=$e->getMessage();}
 md_check($outcome==='migration'&&$db->queries===1,'Missing persisted settings abort actual controller before updates');
 $required=array();
 foreach($mods[$menu_name]['data'][$mod_name]['data'][$sub_name]['data'] as $key=>$field){
  if(!empty($field['user_only'])){continue;}$required[]=$key;if(!empty($field['user'])){$required[]=$key.'_over';}
 }
 foreach($required as $missing){
  $db->rows=array();foreach($defaults as $key=>$value){if($key!==$missing){$db->rows[]=array('config_name'=>$key,'config_value'=>$value);}}
  $outcome='';try{include $source.'phpBB2/admin/admin_board_extend.php';}catch(ModDefaultsExit $e){$outcome=$e->getMessage();}
  md_check($outcome==='migration','Single missing field/override blocks save: '.$missing);
 }
 $md_valid_session=false;$outcome='';
 try{include $source.'phpBB2/admin/admin_board_extend.php';}catch(RuntimeException $e){$outcome=$e->getMessage();}
 md_check($outcome==='Session gate','POST session guard precedes missing-row reporting');
}finally{chdir($cwd);foreach($files as $path=>$body){if(is_file($path)){unlink($path);}}foreach(array_reverse($directories) as $directory){if(is_dir($directory)){rmdir($directory);}}}
echo "Mod Settings: 55 defaults match six registries and fresh seeds; read-only registration and controller gate passed\n";
if(PHP_SAPI!=='cli'||getenv('PHPBB_MOD_SETTINGS_NATIVE')!=='1'){return;}
// Load the exact updater functions, without executing its CLI entry point.
$updater=file_get_contents($source.'update/update_from_153a.php');
md_check(strpos($updater,'$config_defaults += $mod_settings_defaults;')!==false,'Updater includes registry defaults');
foreach(array('update_quote_identifier','update_query_or_fail','update_scalar','update_queue_default') as $name){
 $tokens=token_get_all($updater);$function='';$capturing=false;$body=false;$depth=0;
 for($i=0;$i<count($tokens);$i++){
  if(is_array($tokens[$i])&&$tokens[$i][0]===T_FUNCTION){$j=$i+1;while(is_array($tokens[$j])&&$tokens[$j][0]===T_WHITESPACE){$j++;}if(is_array($tokens[$j])&&$tokens[$j][1]===$name){$capturing=true;}}
  if(!$capturing){continue;}$token=$tokens[$i];$function.=is_array($token)?$token[1]:$token;
  if($token==='{'){$body=true;$depth++;}elseif($token==='}'&&--$depth===0&&$body){break;}
 }
 md_check($function!==''&&$depth===0,'Exact updater function: '.$name);eval($function);
}
mysqli_report(MYSQLI_REPORT_OFF);$port=getenv('PHPBB_MOD_SETTINGS_PORT')?:'3306';
md_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Loopback port');
$password=getenv('PHPBB_MOD_SETTINGS_PASSWORD')?:'';
$connection=mysqli_connect('127.0.0.1','root',$password,'',(int)$port);md_check($connection,'Native fixture connection');
$fixture='codex_mod_defaults_'.bin2hex(function_exists('random_bytes')?random_bytes(8):openssl_random_pseudo_bytes(8));
update_query_or_fail($connection,'CREATE DATABASE '.$fixture.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
try{
 md_check(mysqli_select_db($connection,$fixture)&&mysqli_set_charset($connection,'utf8mb4'),'Own fixture schema');
 $schema=file_get_contents($source.'phpBB2/install/schemas/mysql_schema.sql');
 md_check(preg_match('/CREATE TABLE phpbb_config\s*\(.*?;/s',$schema,$match)===1,'Actual config DDL');
 update_query_or_fail($connection,$match[0]);
 foreach($seeds as $sql){update_query_or_fail($connection,$sql);}
 md_check((int)update_scalar($connection,'SELECT COUNT(*) FROM phpbb_config')===55,'Actual fresh-install seeds execute');
 update_query_or_fail($connection,'DELETE FROM phpbb_config');
 update_query_or_fail($connection,"INSERT INTO phpbb_config VALUES ('use_ajax_edit','0')");
 $operations=array();foreach($defaults as $key=>$value){update_queue_default($operations,$connection,'phpbb_config','config_name','config_value',$key,$value);}
 md_check(count($operations)===54,'Only missing defaults queued');
 // Another connection can initialize a value after planning; INSERT IGNORE
 // must preserve that value, not merely avoid a duplicate-key page failure.
 $peer=mysqli_connect('127.0.0.1','root',$password,$fixture,(int)$port);md_check($peer,'Independent fixture peer');
 update_query_or_fail($peer,"INSERT INTO phpbb_config VALUES ('calendar_nb_row','9')");mysqli_close($peer);
 foreach($operations as $sql){update_query_or_fail($connection,$sql);}
 md_check((int)update_scalar($connection,'SELECT COUNT(*) FROM phpbb_config')===55,'Migration completes all missing keys');
 md_check(update_scalar($connection,"SELECT config_value FROM phpbb_config WHERE config_name='use_ajax_edit'")==='0','Existing administrator value preserved');
 md_check(update_scalar($connection,"SELECT config_value FROM phpbb_config WHERE config_name='calendar_nb_row'")==='9','Concurrent administrator value preserved');
 $operations=array();foreach($defaults as $key=>$value){update_queue_default($operations,$connection,'phpbb_config','config_name','config_value',$key,$value);}
 md_check(!$operations,'Repeated updater is idempotent');
 // The registration helper never gets a connection capability. Even a stale
 // snapshot or legacy force flag cannot change the actual stored values.
 $db=new ModDefaultsReadOnly();$board_config=array();init_board_config_key('use_ajax_edit',1);init_board_config_key('calendar_nb_row',5,true);
 md_check($db->queries===0&&update_scalar($connection,"SELECT config_value FROM phpbb_config WHERE config_name='use_ajax_edit'")==='0'&&update_scalar($connection,"SELECT config_value FROM phpbb_config WHERE config_name='calendar_nb_row'")==='9','Stale/forced registration is read-only against native data');
 echo "Native MariaDB: fresh install, missing defaults, concurrent initialization and repeated migration passed\n";
}finally{update_query_or_fail($connection,'DROP DATABASE '.$fixture);mysqli_close($connection);}
