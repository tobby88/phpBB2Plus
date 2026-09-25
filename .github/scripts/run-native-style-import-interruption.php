<?php
// Child of check-style-import-recovery-native.php, never a web entry point.
if (PHP_SAPI !== 'cli' || getenv('PHPBB_STYLE_IMPORT_RECOVERY_NATIVE') !== '1' || count($argv) !== 5) { exit(2); }
$schema=$argv[1];$fixture_root=realpath($argv[2]);$operation=$argv[3];$mode=$argv[4];
if (!preg_match('/^codex_style_import_recovery_[a-f0-9]{16}$/D',$schema) || !$fixture_root || is_link($argv[2]) || basename($fixture_root)!==$schema
 || !preg_match('/^[a-f0-9]{32}$/D',$operation) || !in_array($mode,array('published','staged'),true)) { exit(2); }
$port=getenv('PHPBB_STYLE_IMPORT_PORT')?:'3306';if(!preg_match('/^[0-9]{1,5}$/D',$port)||(int)$port<1||(int)$port>65535){exit(2);}
define('IN_PHPBB',true);define('ADMIN',1);define('THEMES_TABLE','fixture_themes');define('THEMES_NAME_TABLE','fixture_names');
define('USERS_TABLE','fixture_users');define('SESSIONS_TABLE','fixture_sessions');define('JR_ADMIN_TABLE','fixture_jr');define('CONFIG_TABLE','fixture_config');define('ATTACHMENTS_TABLE','fixture_attachments');
$source=dirname(dirname(__DIR__)).'/phpBB2/';$phpEx='php';$phpbb_root_path=$fixture_root.'/';
require $source.'includes/php_compat.php';require $source.'includes/functions_acl_storage.php';require $source.'includes/functions_jr_admin.php';require $source.'db/mysqli.php';
$code=file_get_contents($source.'admin/xs_include.php');if(!preg_match('/define\(\'XS_MAX_ITEMS_PER_STYLE\', ([^;]+);/',$code,$m)){exit(2);}eval($m[0]);
require $source.'includes/functions_style_import_recovery.php';
$userdata=array('user_id'=>1,'session_id'=>'fixture-admin','session_admin'=>1,'session_logged_in'=>1,'user_level'=>1);$_SERVER['REQUEST_METHOD']='POST';$board_config=array('default_style'=>1);
class RecoveryInterruptedFiles extends PhpbbStyleImportLocal
{
 function put($path,$bytes,$guard,$tag=null){
  $boundary=function()use($guard,$path,$tag){call_user_func($guard);if($GLOBALS['mode']==='staged'&&$this->kind($this->stage_name($path,$tag))==='file'){exit(73);}};
  parent::put($path,$bytes,$boundary,$tag);exit(73);
 }
}
$db=new sql_db('127.0.0.1:'.$port,'root',getenv('PHPBB_STYLE_IMPORT_PASSWORD')?:'',$schema,false);
phpbb_style_import_recovery($db,array('sid'=>'fixture-admin','recovery_action'=>'resume','recovery_template'=>'fisubsilversh','recovery_operation'=>$operation),
 new RecoveryInterruptedFiles($fixture_root.'/templates'),$fixture_root.'/cache','native-local');
exit(74);
