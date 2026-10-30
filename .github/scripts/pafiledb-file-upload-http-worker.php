<?php
// Loopback-only router for actual multipart tests; never a forum endpoint.
$secret=getenv('PHPBB_PA_UPLOAD_TOKEN');$schema=getenv('PHPBB_PA_UPLOAD_SCHEMA');$base=getenv('PHPBB_PA_UPLOAD_ROOT');
if(PHP_SAPI!=='cli-server'||!is_string($secret)||!preg_match('/^[a-f0-9]{64}$/D',$secret)||!isset($_SERVER['HTTP_X_FIXTURE_TOKEN'])||!hash_equals($secret,$_SERVER['HTTP_X_FIXTURE_TOKEN'])||!isset($_SERVER['REMOTE_ADDR'])||$_SERVER['REMOTE_ADDR']!=='127.0.0.1'||!preg_match('/^codex_pa_file_coord_[a-f0-9]{16}$/D',(string)$schema)||!is_string($base)||dirname($base)!==realpath(sys_get_temp_dir())||!preg_match('/^codex_pa_upload_[a-f0-9]{16}$/D',basename($base))||is_link($base)||realpath($base)!==$base){http_response_code(404);return;}
if($_SERVER['REQUEST_METHOD']==='GET'){header('Content-Type: application/json');echo '{"ready":true}';return;}
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);return;}
define('IN_PHPBB',true);$acp=isset($_POST['fixture_acp'])&&$_POST['fixture_acp']==='1';if($acp){define('IN_ADMIN',true);}$root=dirname(dirname(__DIR__));$phpbb_root_path=$root.'/phpBB2/';$table_prefix='fixture_';$phpEx='php';
require$phpbb_root_path.'includes/php_compat.php';require$phpbb_root_path.'includes/constants.php';require$phpbb_root_path.'db/mysqli.php';require$phpbb_root_path.'pafiledb/includes/pafiledb_constants.php';require$phpbb_root_path.'pafiledb/includes/functions.php';require$phpbb_root_path.'pafiledb/includes/functions_file_actions.php';require __DIR__.'/pafiledb-file-native-connections.php';
function pc_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
$db=null;$lang=array();require$phpbb_root_path.'language/lang_english/lang_main.php';require$phpbb_root_path.'language/lang_english/lang_pafiledb.php';$phpbb_root_path=$base.'/';
$board_config=array('server_name'=>'board.example.invalid','cookie_secure'=>1,'server_port'=>443,'script_path'=>'/forum');$userdata=array('user_id'=>$acp?7:8,'user_level'=>$acp?ADMIN:USER,'session_id'=>$acp?'files-7':'files-8','session_logged_in'=>true,'session_admin'=>$acp);$user_ip='7f000001';
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{
 $port=getenv('PHPBB_PA_FILE_COORDINATOR_PORT')?:'3306';if(!preg_match('/^[0-9]{1,5}$/D',$port)){throw new RuntimeException('Invalid own native port');}
 $db=new PaCoordinatorDatabase('127.0.0.1:'.$port,'root',getenv('PHPBB_PA_FILE_COORDINATOR_PASSWORD')?:'',$schema,false);$pafiledb_config=array();$r=$db->sql_query('SELECT * FROM fixture_pa_config');if(!$r){throw new RuntimeException('Missing own upload config');}foreach($db->sql_fetchrowset($r)as$row){$pafiledb_config[$row['config_name']]=$row['config_value'];}$db->sql_freeresult($r);foreach(array('auth_search','auth_stats','auth_toplist','auth_viewall')as$key){$pafiledb_config[$key]=AUTH_ALL;}
 $fault=isset($_POST['fixture_failure'])&&is_string($_POST['fixture_failure'])?$_POST['fixture_failure']:'';
 if($fault==='custom'){$db->on_query=function($sql){if(strpos($sql,'INSERT INTO fixture_pa_customdata')===0){return false;}};}
 elseif($fault==='metadata-ack'){$db->lost=1;}elseif($fault==='intent-ack'){$db->lost=2;}
 if(isset($_POST['fixture_legacy'])&&$_POST['fixture_legacy']==='1'){define('PHPBB_LEGACY_REQUEST_ESCAPED',true);$_POST=phpbb_addslashes_recursive($_POST);}
 $pafiledb_functions=new pafiledb_functions();$model=new pafiledb();$file=isset($_POST['file_id'])?(int)$_POST['file_id']:false;$fields=new custom_field();$saved=$model->update_add_file($file,$fields);
 header('Content-Type: application/json');echo json_encode(array('saved'=>$saved,'errors'=>$model->error,'actual_upload'=>isset($_FILES['userfile']['tmp_name'])&&is_uploaded_file($_FILES['userfile']['tmp_name']),'actual_screenshot'=>isset($_FILES['screen_shot']['tmp_name'])&&is_uploaded_file($_FILES['screen_shot']['tmp_name'])));
}catch(Exception$error){http_response_code(500);echo json_encode(array('fixture_error'=>$error->getMessage()));}
catch(Error$error){http_response_code(500);echo json_encode(array('fixture_error'=>$error->getMessage()));}
finally{restore_error_handler();if($db){$db->sql_close();}}
