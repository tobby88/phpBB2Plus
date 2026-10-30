<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_CT_RECOVERY_NATIVE')!=='1'){echo "Native CrackerTracker metadata scope fixture skipped.\n";return;}
define('IN_PHPBB',true);define('CTRACKER_ACP',true);define('CRITICAL_ERROR',3);
$source=dirname(dirname(__DIR__)).'/phpBB2/';require$source.'db/mysqli.php';require$source.'ctracker/classes/class_ct_adminfunctions.php';
class CtMetadataExit extends RuntimeException{}
function message_die($level,$text){throw new CtMetadataExit($text);}
function cms_check($ok,$message){if(!$ok){throw new RuntimeException($message);}$GLOBALS['cms_cases']++;}
class CtMetadataReader{
 public$inner,$queries=array();function __construct($inner){$this->inner=$inner;}function __call($name,$args){return call_user_func_array(array($this->inner,$name),$args);}
 function sql_query($sql){$this->queries[]=$sql;return$this->inner->sql_query($sql);}
}
function cms_sql($sql){$r=$GLOBALS['db']->sql_query($sql);if(!$r){throw new RuntimeException('Own metadata fixture SQL failed');}return$r;}
function cms_rows($sql){$r=cms_sql($sql);try{return$GLOBALS['db']->sql_fetchrowset($r);}finally{$GLOBALS['db']->sql_freeresult($r);}}
function cms_allowed($table,$expected){
 $GLOBALS['reader']->queries=array();$denied=false;
 try{$GLOBALS['method']->invoke($GLOBALS['admin'],$GLOBALS['reader'],$table);}catch(CtMetadataExit$e){$denied=true;cms_check($e->getMessage()==='migration','Controlled storage-upgrade rejection');}
 cms_check($denied!==$expected,'Actual canonical storage decision: '.$table);
}
$port=getenv('PHPBB_CT_RECOVERY_TEST_PORT')?:'3306';$password=getenv('PHPBB_CT_RECOVERY_TEST_PASSWORD')?:'';
if(!preg_match('/^[0-9]{1,5}$/D',$port)||(int)$port<1||(int)$port>65535){throw new RuntimeException('Own loopback fixture port required');}
$fixture='codex_ct_metadata_scope_'.bin2hex(function_exists('random_bytes')?random_bytes(8):openssl_random_pseudo_bytes(8));$host='127.0.0.1:'.$port;
$control=new sql_db($host,'root',$password,'',false);$db=null;$created=false;$cms_cases=0;
$lang=array('ctracker_error_storage_migration'=>'migration');set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{
 cms_check($control->sql_query('CREATE DATABASE '.$fixture.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Own disposable metadata schema');$created=true;$db=new sql_db($host,'root',$password,$fixture,false);
 $schema=file_get_contents($source.'install/schemas/mysql_schema.sql');cms_check(preg_match('/^CREATE TABLE phpbb_config\s*\([\s\S]*?;/m',$schema,$m)===1,'Actual canonical source schema');cms_sql(str_replace('phpbb_config','fixture_config',$m[0]));
 cms_sql('CREATE TABLE fixture_unrelated (value varchar(255)) ENGINE=MyISAM DEFAULT CHARSET=latin1');
 $reader=new CtMetadataReader($db);$admin=new ct_adminfunctions();$method=new ReflectionMethod('ct_adminfunctions','require_modern_storage');if(PHP_VERSION_ID<80100){$method->setAccessible(true);}
 cms_allowed('fixture_config',true);$queries=$reader->queries;cms_check(count($queries)===1,'Actual storage gate inspected one metadata statement');
 $version=cms_rows('SELECT VERSION() version');
 if(stripos($version[0]['version'],'MariaDB')!==false){
  $plan=cms_rows('EXPLAIN '.$queries[0]);$columns=array();foreach($plan as$row){if(isset($row['table'])&&$row['table']==='c'){$columns[]=$row;}}
  cms_check(count($columns)===1,'Actual column metadata plan identified');$row=$columns[0];
  cms_check(stripos($row['Extra'],'Scanned all databases')===false&&strpos((string)$row['key'],'TABLE_SCHEMA')!==false&&strpos((string)$row['key'],'TABLE_NAME')!==false,'Actual column metadata lookup stays bounded to selected schema/table');
 }
 cms_sql('ALTER TABLE fixture_config MODIFY config_value varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci');cms_allowed('fixture_config',false);
 cms_sql('ALTER TABLE fixture_config CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');cms_allowed('fixture_config',false);
 cms_sql('ALTER TABLE fixture_config CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,ROW_FORMAT=COMPACT');cms_allowed('fixture_config',false);
 cms_sql('ALTER TABLE fixture_config ROW_FORMAT=DYNAMIC');cms_allowed('fixture_config',true);
 cms_sql('ALTER TABLE fixture_config ENGINE=MyISAM');cms_allowed('fixture_config',false);
 cms_sql('DROP TABLE fixture_config');cms_sql('CREATE VIEW fixture_config AS SELECT value FROM fixture_unrelated');cms_allowed('fixture_config',false);
 cms_sql('DROP VIEW fixture_config');cms_allowed('fixture_config',false);cms_allowed('fixture_unrelated',false);
 echo 'Native CrackerTracker metadata scope: ',$cms_cases," actual gate/plan/encoding/collation/row-format/engine/view/missing cases PASS; own disposable schema only.\n";
}finally{
 if($db){$db->sql_close();}if(!preg_match('/^codex_ct_metadata_scope_[a-f0-9]{16}$/D',$fixture)){throw new RuntimeException('Owned metadata schema required');}if($created){$control->sql_query('DROP DATABASE '.$fixture);}$control->sql_close();restore_error_handler();
}
