<?php
// Reuse the actual account/session/SQL transport fixtures, including the native
// MariaDB option. The existing recovery suite runs first and remains independent.
require __DIR__ . '/check-maintenance-config.php';
if (!defined('GENERAL_MESSAGE')) { define('GENERAL_MESSAGE', 1); }
if (!defined('GENERAL_ERROR')) { define('GENERAL_ERROR', 2); }
function append_sid($url) { return $url; }
$controller=file_get_contents($root.'admin/admin_db_maintenance.php');
$a=strpos($controller,"case 'unlock_db':");$b=strpos($controller,'default:',$a);
config_check($a!==false&&$b>$a,'Actual unlock branch');
$control_branches=array('unlock'=>'switch("unlock_db"){'.substr($controller,$a,$b-$a).'}');
$a=strpos($controller,"case 'config': // General maintenance configuration");
$a=strpos($controller,"if (isset(\$_POST['submit']))",$a);$b=strpos($controller,'$template->set_filenames',$a);
config_check($a!==false&&$b>$a,'Actual configuration submit branch');
$control_branches['settings']=substr($controller,$a,$b-$a);
foreach($control_branches as &$branch){$branch=str_replace("\$phpbb_root_path . 'includes/functions_maintenance_config.' . \$phpEx",var_export($root.'includes/functions_maintenance_config.php',true),$branch);}unset($branch);
$cache_root=sys_get_temp_dir().'/maintenance-controls-'.md5(uniqid('',true)).'/';mkdir($cache_root.'cache',0700,true);
function controls_fixture($engine,$actor=1){
 config_fixture($engine,$actor);
 global $config_server,$phpbb_root_path,$cache_root,$board_config;
 $phpbb_root_path=$cache_root;
 $config_server->pdo->exec("UPDATE fixture_config SET config_value='1' WHERE config_name='board_disable'");
 $config_server->pdo->exec("INSERT INTO fixture_config VALUES ('dbmtnc_disallow_rebuild','0'),('dbmtnc_disallow_postcounter','0')");
 $_POST=array('sid'=>'fixture-sid','submit'=>'1','disallow_rebuild'=>'1','disallow_postcounter'=>'1');
 $board_config['board_disable']='1';file_put_contents($cache_root.'cache/config_data.cache','stale');
}
function controls_run($action,$expected=''){
 global $db,$lang,$phpbb_root_path,$phpEx,$control_branches;
 $db=new ConfigForum();$caught='';ob_start();
 try{eval($control_branches[$action]);}catch(RuntimeException $e){$caught=$e->getMessage();}finally{$html=ob_get_clean();}
 if($expected===''&&$action==='settings'){config_check(strpos($caught,$lang['Dbmtnc_config_updated'])===0,'Settings success message');}
 else{config_check($caught===$expected,'Controls outcome: '.$action.' '.$caught.' / '.$expected);}
 config_check($GLOBALS['config_server']->owner===null,'Controls release lock before rendering/return');return $html;
}
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{foreach($native?array('InnoDB','MyISAM'):array('SQLite') as $engine){foreach(array('english','german') as $locale){
 $lang=array('Not_Authorised'=>'not-authorized','Session_invalid'=>'session-invalid','Attachment_storage_busy'=>'busy');include $root.'language/lang_'.$locale.'/lang_dbmtnc.php';
 foreach(array('unlock','settings') as $action){
  foreach(array(1,20) as $actor){
   controls_fixture($engine,$actor);if($actor===20){$config_server->pdo->exec("INSERT INTO fixture_junior VALUES (20,'".md5('GeneralDB_Maintenanceadmin_db_maintenance.php')."')");}
   controls_run($action);config_check(config_value('board_disable')===($action==='unlock'?'0':'1'),'Only explicit unlock changes availability');
   config_check(config_value('dbmtnc_disallow_rebuild')===($action==='settings'?'1':'0')&&config_value('dbmtnc_disallow_postcounter')===($action==='settings'?'1':'0'),'Settings pair saved together');
   config_check(config_value('version')==='.0.23'&&config_value('custom')==='keep','Unrelated values unchanged');
   config_check(!file_exists($cache_root.'cache/config_data.cache'),'Successful writes invalidate stale config');
   controls_run($action); // unchanged values are legitimate repeat success
  }
  foreach(array('missing','foreign','logged-out','not-admin','case-changed','actor','grant','role','deleted') as $case){foreach(array('entry','update') as $phase){
   controls_fixture($engine,$case==='grant'?20:1);if($case==='grant'){$config_server->pdo->exec("INSERT INTO fixture_junior VALUES (20,'".md5('GeneralDB_Maintenanceadmin_db_maintenance.php')."')");}
   $before=config_snapshot();$revoke=function()use($case){if($case==='role'){$GLOBALS['config_server']->pdo->exec('UPDATE fixture_users SET user_level=0');}elseif($case==='deleted'){$GLOBALS['config_server']->pdo->exec('DELETE FROM fixture_users');}else{config_revoke($case);}};
   if($phase==='entry'){$revoke();}else{$config_server->hook=function($sql)use($revoke){if(strpos($sql,'UPDATE fixture_config')===0){$GLOBALS['config_server']->hook=null;$revoke();}};}
   controls_run($action,$lang['Not_Authorised']);config_check(config_snapshot()===$before,'Revoked authority prevents configuration write at '.$phase);
  }}
  foreach(array('get','bad-sid','array-sid') as $case){controls_fixture($engine);if($case==='get'){$_SERVER['REQUEST_METHOD']='GET';}else{$_POST['sid']=$case==='array-sid'?array():'wrong';}controls_run($action,$lang['Session_invalid']);config_check(!$config_server->queries,'Invalid request has no database access');}
  foreach(array('lock','SELECT user_id','UPDATE fixture_config') as $failure){controls_fixture($engine);$before=config_snapshot();$config_server->failure=$failure;controls_run($action,$failure==='lock'?$lang['Attachment_storage_busy']:$lang['Maintenance_config_failed']);config_check(config_snapshot()===$before,'Failure before write leaves values unchanged');}
  controls_fixture($engine);$config_server->lostAck='UPDATE fixture_config';controls_run($action,$lang['Maintenance_config_failed']);config_check(!file_exists($cache_root.'cache/config_data.cache'),'Lost write acknowledgement also invalidates cache');$config_server->lostAck='';controls_run($action);
  controls_fixture($engine);$config_server->pdo->exec("DELETE FROM fixture_config WHERE config_name='".($action==='unlock'?'board_disable':'dbmtnc_disallow_rebuild')."'");$before=config_snapshot();controls_run($action,$lang['Maintenance_config_failed']);config_check(config_snapshot()===$before,'Missing config fails without filling defaults');
 }
 foreach(array(array(),'2','1.0','-1','0 OR 1') as $bad){controls_fixture($engine);$_POST['disallow_rebuild']=$bad;controls_run('settings',$lang['Invalid_dbmtnc_request']);config_check(!$config_server->queries,'Malformed settings rejected before database');}
 echo $engine.' '.$locale." maintenance unlock/settings controller authority passed\n";
}}}finally{restore_error_handler();if(file_exists($cache_root.'cache/config_data.cache')){unlink($cache_root.'cache/config_data.cache');}rmdir($cache_root.'cache');rmdir($cache_root);}
$helpers=file_get_contents($root.'includes/functions_dbmtnc.php');
config_check(strpos($helpers,'function lock_db(')===false&&strpos($helpers,'function update_config(')===false,'Unused unguarded legacy writers removed');
