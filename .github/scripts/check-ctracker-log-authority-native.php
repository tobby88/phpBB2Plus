<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_CT_LOG_AUTHORITY_NATIVE')!=='1'){echo "Native CrackerTracker log authority fixture skipped.\n";return;}
define('IN_PHPBB',true);define('IN_ADMIN',true);define('CTRACKER_ACP',true);
$root=dirname(dirname(__DIR__));$app=$root.'/phpBB2/';$phpbb_root_path=$app;$phpEx='php';$table_prefix='fixture_';
require$app.'includes/php_compat.php';require$app.'includes/constants.php';require$app.'attach_mod/includes/constants.php';require$app.'db/mysqli.php';
require$app.'ctracker/classes/class_log_manager.php';require$app.'ctracker/classes/class_log_authority.php';require$app.'includes/functions_jr_admin.php';require __DIR__.'/native-fixture-locks.php';date_default_timezone_set('UTC');
class CtLogControllerExit extends RuntimeException{}
function message_die($level,$message){throw new CtLogControllerExit($message);}
function append_sid($url){return$url;}
class CtLogNativeTemplate {
 public$blocks=array();public$rendered=0;
 function set_filenames($values){}function assign_vars($values){}
 function assign_block_vars($key,$values){$this->blocks[$key][]=$values;}
 function pparse($key){$this->rendered++;}
}
class CtLogNativeDb extends sql_db {
 public$fixturePassword,$connections=array(),$kind='',$fail='',$closed=false,$query_hook=null,$setting_writes=0,$mutation_writes=0,$lose_commit=false;
 function __construct($host,$user,$password,$schema,$persistency=false){$this->fixturePassword=$password;parent::__construct($host,$user,$password,$schema,$persistency);}
 function sql_dedicated_connection(){if($this->kind==='same'){return$this;}$next=new self($this->server,$this->user,$this->fixturePassword,$this->dbname,false);$next->fail=$this->fail;$next->query_hook=$this->query_hook;$next->lose_commit=$this->lose_commit;$this->connections[]=$next;return$next;}
 function sql_query($sql='',$transaction=false){if(preg_match('/^(INSERT|UPDATE|DELETE)\b/i',$sql)){$this->mutation_writes++;}if(strpos($sql,'INSERT INTO '.CTRACKER_CONFIG.' ')===0){$this->setting_writes++;}if(is_callable($this->query_hook)&&call_user_func($this->query_hook,$sql,$this)===false){return false;}if($this->fail!==''&&strpos($sql,$this->fail)!==false){return false;}$result=parent::sql_query($sql,$transaction);if($this->lose_commit&&$sql==='COMMIT'&&$this->mutation_writes>0){return false;}return$result;}
 function sql_close(){$this->closed=true;return parent::sql_close();}
}
function cl_check($ok,$message){if(!$ok){throw new RuntimeException($message);}$GLOBALS['checks']++;}
function cl_sql($sql){$r=$GLOBALS['peer']->sql_query($sql);if(!$r){throw new RuntimeException('Own CT log fixture SQL failed: '.$sql);}return$r;}
function cl_rows($sql){$r=cl_sql($sql);try{return$GLOBALS['peer']->sql_fetchrowset($r);}finally{$GLOBALS['peer']->sql_freeresult($r);}}
function cl_insert($table,$values){foreach(cl_rows('SHOW COLUMNS FROM '.$table)as$c){if(!array_key_exists($c['Field'],$values)&&$c['Null']==='NO'&&$c['Default']===null&&strpos($c['Extra'],'auto_increment')===false){$values[$c['Field']]=preg_match('/^(tinyint|smallint|mediumint|int|bigint|float|double|decimal)/',$c['Type'])?0:'';}}$encoded=array();foreach($values as$value){$encoded[]="'".$GLOBALS['peer']->sql_escape((string)$value)."'";}cl_sql('INSERT INTO '.$table.'('.implode(',',array_keys($values)).')VALUES('.implode(',',$encoded).')');}
function cl_fingerprint(){clearstatcache();$out=array();foreach($GLOBALS['files']as$name){$out[$name]=hash_file('sha256',$GLOBALS['base'].'/ctracker/logfiles/'.$name);}return$out;}
function cl_closed(){foreach($GLOBALS['db']->connections as$c){if(!$c->closed){return false;}}return true;}
function cl_reset($id=7){
 global$userdata,$db,$template;
 foreach(array('users','sessions','jr_admin_users','config')as$suffix){cl_sql('DELETE FROM fixture_'.$suffix);}
 foreach(array(7=>ADMIN,11=>USER,12=>MOD)as$user=>$level){cl_insert(USERS_TABLE,array('user_id'=>$user,'user_active'=>1,'user_level'=>$level));}
 cl_insert(SESSIONS_TABLE,array('session_id'=>'log-own-session','session_user_id'=>$id,'session_logged_in'=>1,'session_admin'=>1,'session_time'=>time()));
 cl_insert(CONFIG_TABLE,array('config_name'=>'session_length','config_value'=>'3600'));
 cl_insert(JR_ADMIN_TABLE,array('user_id'=>11,'user_jr_admin'=>$GLOBALS['log_hash']));
 $userdata=array('user_id'=>$id,'user_level'=>$id===7?ADMIN:USER,'session_id'=>'log-own-session','session_logged_in'=>true,'session_admin'=>true);
 $header="1|||0|||null|||null|||null|||null|||null\n";
 foreach($GLOBALS['files']as$i=>$name){file_put_contents($GLOBALS['base'].'/ctracker/logfiles/'.$name,$i===0?'7':($i===5?"Synthetic debug Grüße 😀\n":$header."0|||1|||Synthetic & <event>|||referrer|||agent|||address|||host\n"));}
 $cache=$GLOBALS['base'].'/cache/ctracker_counter.cache';if(is_file($cache)){unlink($cache);}
 $_SERVER['REQUEST_METHOD']='POST';$_POST=array('sid'=>'log-own-session');$_GET=array();$db->fail=$db->kind='';$db->query_hook=null;$db->lose_commit=false;$db->setting_writes=$db->mutation_writes=0;$template=new CtLogNativeTemplate();
}
function cl_controller($mode,$id='5',$overrides=array()){
 global$template,$lang,$images,$phpEx,$phpbb_root_path,$board_config;
 $_POST=array_merge(array('mode'=>$mode,'logid'=>$id,'sid'=>'log-own-session'),$overrides);
 include$GLOBALS['app'].'ctracker/admin/acp_module_logmanager.php';
}
function cl_denied($call,$message){$before=cl_fingerprint();$denied=false;ob_start();try{$call();}catch(CtrackerLogAuthorityFailure$error){$denied=true;}catch(CtLogControllerExit$error){$denied=true;}finally{$output=ob_get_clean();}cl_check($denied&&$output===''&&cl_fingerprint()===$before&&cl_closed(),$message.' before log bytes/output; owned connection released');}
// Use the actual post-session and serialized-counter-cache helpers.
$bootstrap=file_get_contents($app.'admin/pagestart.php');$a=strpos($bootstrap,"if (!function_exists('phpbb_admin_post_session_valid'))");$b=strpos($bootstrap,'if (empty($no_page_header))',$a);if($a===false||$b<=$a){throw new RuntimeException('Actual ACP request helpers unavailable');}eval(substr($bootstrap,$a,$b-$a));
$cache=file_get_contents($app.'includes/functions.php');$a=strpos($cache,'function phpbb_serialized_data_read(');$b=strpos($cache,'function phpbb_data_store_read(',$a);if($a===false||$b<=$a){throw new RuntimeException('Actual counter cache helpers unavailable');}eval(substr($cache,$a,$b-$a));
$routes=jr_admin_authorization_routes();$log_hash=array_search('admin_cracker_tracker.php?modu=6',$routes,true);$other_hash=array_search('admin_cracker_tracker.php?modu=3',$routes,true);if($log_hash===false||$other_hash===false){throw new RuntimeException('Actual exact log module registrations unavailable');}
$port=getenv('PHPBB_CT_LOG_AUTHORITY_PORT')?:'3306';$password=getenv('PHPBB_CT_LOG_AUTHORITY_PASSWORD')?:'';if(!preg_match('/^[0-9]{1,5}$/D',$port)||(int)$port<1||(int)$port>65535){throw new RuntimeException('Own loopback fixture port required');}
$schema='codex_ct_log_authority_'.bin2hex(phpbb_random_bytes(8));$base=sys_get_temp_dir().'/'.$schema;$host='127.0.0.1:'.$port;$control=new sql_db($host,'root',$password,'',false);$peer=$db=$scope=null;$created=$made=false;$checks=0;
$files=array('logfile_attempt_counter.txt','logfile_worms.txt','logfile_blocklist.txt','logfile_malformed_logins.txt','logfile_spammer.txt','logfile_debug_mode.txt');$HTTP_SERVER_VARS=$HTTP_ENV_VARS=array();$images=array('ctracker_log_manager'=>'fixture.gif');$board_config=array('default_dateformat'=>'Y-m-d H:i:s');$lang=array('Not_Authorised'=>'denied','Session_invalid'=>'session','Owned_storage_upgrade'=>'upgrade');require$app.'language/lang_english/lang_cback_ctracker.php';
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{
 cl_check($control->sql_query('CREATE DATABASE '.$schema.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')!==false,'Own disposable native schema');$created=true;$peer=new sql_db($host,'root',$password,$schema,false);$db=new CtLogNativeDb($host,'root',$password,$schema,false);
 $canonical=file_get_contents($app.'install/schemas/mysql_schema.sql');foreach(array('users','sessions','jr_admin_users','config')as$suffix){cl_check(preg_match('/^CREATE TABLE phpbb_'.$suffix.'\s*\([\s\S]*?;/m',$canonical,$m)===1,'Actual canonical authority table');cl_sql(str_replace('phpbb_'.$suffix,'fixture_'.$suffix,$m[0]));}
 cl_check(mkdir($base,0700)&&mkdir($base.'/ctracker',0700)&&mkdir($base.'/ctracker/logfiles',0700)&&mkdir($base.'/cache',0700),'Own synthetic log directories');$made=true;$phpbb_root_path=$base.'/';
 if(getenv('PHPBB_CT_ACTIONS_NATIVE')==='1'){require __DIR__.'/ctracker-actions-native-fixture.php';}
 elseif(getenv('PHPBB_CT_SETTINGS_NATIVE')==='1'){require __DIR__.'/ctracker-settings-native-fixture.php';}
 else{
 foreach(array(7,11)as$id){
  foreach(array(2,3,4,5,6)as$log){cl_reset($id);$manager=new log_manager();$count=$manager->get_counter_value();cl_controller('delete',(string)$log);cl_check($manager->check_log_size($log)===0&&$manager->get_counter_value()===$count&&$template->rendered===1&&cl_closed(),'Actual root/delegated selected clear preserves attack accounting');}
  cl_reset($id);$manager=new log_manager();$count=$manager->get_counter_value();cl_controller('delete_all');foreach(array(2,3,4,5,6)as$log){cl_check($manager->check_log_size($log)===0,'Actual authorized clear-all clears selected files');}cl_check($manager->get_counter_value()===$count&&cl_closed(),'Actual authorized clear-all preserves aggregate and releases owner');
  cl_reset($id);$before=cl_fingerprint();cl_controller('view');cl_check(count($template->blocks['show_log'])===1&&$template->blocks['show_log'][0]['L_OUTPUT_2']==='Synthetic &amp; &lt;event&gt;'&&cl_fingerprint()===$before&&cl_closed(),'Actual authorized view escaped/read-only/released');
  cl_reset($id);$before=cl_fingerprint();ob_start();try{$ok=ctracker_log_debug_download();$body=ob_get_contents();}finally{ob_end_clean();}cl_check($ok&&$body===file_get_contents($base.'/ctracker/logfiles/logfile_debug_mode.txt')&&cl_fingerprint()===$before&&cl_closed(),'Actual raw helper respects exact route6 delegation and unchanged bytes');
 }
 $changes=array('root demoted'=>'UPDATE fixture_users SET user_level=0 WHERE user_id=7','inactive root'=>'UPDATE fixture_users SET user_active=0 WHERE user_id=7','removed root'=>'DELETE FROM fixture_users WHERE user_id=7','missing ACP session'=>'DELETE FROM fixture_sessions','foreign session'=>'UPDATE fixture_sessions SET session_user_id=12','logged out'=>'UPDATE fixture_sessions SET session_logged_in=0','ACP flag revoked'=>'UPDATE fixture_sessions SET session_admin=0','expired session'=>'UPDATE fixture_sessions SET session_time=1','session case mismatch'=>"UPDATE fixture_sessions SET session_id='LOG-OWN-SESSION'",'session leading-padding mismatch'=>"UPDATE fixture_sessions SET session_id=' log-own-session'",'invalid current TTL'=>"UPDATE fixture_config SET config_value='0' WHERE config_name='session_length'",'missing current TTL'=>"DELETE FROM fixture_config WHERE config_name='session_length'");
 foreach($changes as$label=>$sql){foreach(array('delete','view','raw')as$action){cl_reset();cl_sql($sql);cl_denied(function()use($action){if($action==='raw'){ctracker_log_debug_download();}else{cl_controller($action);}},$label.' / '.$action);}}
 foreach(array('different route'=>"UPDATE fixture_jr_admin_users SET user_jr_admin='".$other_hash."' WHERE user_id=11",'missing delegation'=>'DELETE FROM fixture_jr_admin_users')as$label=>$sql){foreach(array('delete','view','raw')as$action){cl_reset(11);cl_sql($sql);cl_denied(function()use($action){if($action==='raw'){ctracker_log_debug_download();}else{cl_controller($action);}},$label.' / '.$action);}}
 foreach(array('',array('bad'),'wrong')as$token){cl_reset();cl_denied(function()use($token){cl_controller('delete','5',array('sid'=>$token));},'Rejected POST nonce');}
 cl_reset();$_SERVER['REQUEST_METHOD']='GET';cl_denied(function(){cl_controller('delete');},'GET cannot clear logs');
 foreach(array(array('5'),'5junk','05','1','7','')as$id){cl_reset();cl_denied(function()use($id){cl_controller('delete',$id);},'Malformed/unregistered log selector rejected');}
 cl_reset();$userdata['session_admin']=false;cl_denied(function(){cl_controller('delete_all');},'Cached non-ACP context rejected');
 cl_reset();$scope=ctracker_log_scope_open();$userdata['user_id']=11;cl_denied(function()use(&$scope){try{$scope->check();}finally{$scope->release();$scope=null;}},'Identity switch cannot reuse scope');
 // Real current row locks prevent peer revocation halfway through file work.
 foreach(array(7,11)as$id){
  foreach(array('UPDATE fixture_users SET user_active=0 WHERE user_id='.$id,'DELETE FROM fixture_sessions',"UPDATE fixture_config SET config_value='0' WHERE config_name='session_length'")as$sql){cl_reset($id);$scope=ctracker_log_scope_open(true);$conflict=native_fixture_conflict_query($peer,$sql);cl_check($conflict[0]===false&&(int)$conflict[1]['code']===1205,'Current authority rows block peer revocation');$scope->finish();$scope=null;cl_sql($sql);cl_denied(function(){cl_controller('delete');},'Next request observes committed revocation');}
 }
 cl_reset(11);$scope=ctracker_log_scope_open();$conflict=native_fixture_conflict_query($peer,'DELETE FROM fixture_jr_admin_users WHERE user_id=11');cl_check($conflict[0]===false&&(int)$conflict[1]['code']===1205,'Delegated exact route held throughout scope');$scope->finish();$scope=null;cl_sql('DELETE FROM fixture_jr_admin_users WHERE user_id=11');cl_denied(function(){ctracker_log_debug_download();},'Raw helper observes committed grant revocation');
 cl_reset();$scope=ctracker_log_scope_open();$lock='attachment:'.md5($schema."\0".ATTACHMENTS_TABLE);$r=cl_rows("SELECT GET_LOCK('".$lock."',0) held");cl_check((string)$r[0]['held']==='0','Manual scope cooperates with the shared mutation lease');
 foreach(array('UPDATE fixture_config SET config_value=0','COMMIT','ALTER TABLE fixture_config ENGINE=MyISAM')as$sql){$blocked=false;try{$scope->sql_query($sql);}catch(CtrackerLogAuthorityFailure$error){$blocked=true;}cl_check($blocked,'Public SQL adapter rejects writes and transaction control');$blocked=false;try{$scope->rows($sql);}catch(CtrackerLogAuthorityFailure$error){$blocked=true;}cl_check($blocked,'Public row adapter also rejects writes');}$scope->finish();$scope=null;
 // No COMMIT/ROLLBACK may reach the caller's independently pending work.
 cl_reset();cl_check($db->sql_query('START TRANSACTION')!==false&&$db->sql_query("INSERT INTO fixture_config(config_name,config_value)VALUES('caller_pending','preserved')")!==false,'Own caller transaction started');$scope=ctracker_log_scope_open();$scope->finish();$scope=null;$r=$db->sql_query("SELECT config_value FROM fixture_config WHERE config_name='caller_pending'");cl_check($db->sql_fetchrow($r)!==false&&!cl_rows("SELECT config_value FROM fixture_config WHERE config_name='caller_pending'"),'Scope leaves caller pending work connected and uncommitted');$db->sql_freeresult($r);$db->sql_query('ROLLBACK');cl_check(!cl_rows("SELECT config_value FROM fixture_config WHERE config_name='caller_pending'"),'Caller can still roll back');
 cl_reset();$db->kind='same';cl_denied(function(){cl_controller('delete');},'Factory cannot adopt/close the caller handle');cl_check($db->sql_query('SELECT 1')!==false&&!$db->closed,'Rejected factory leaves caller usable');
 foreach(array('FROM fixture_sessions WHERE','FROM fixture_users WHERE','FROM fixture_jr_admin_users WHERE')as$failure){cl_reset();$db->fail=$failure;cl_denied(function(){cl_controller('delete_all');},'Current-authority read failure');}
 foreach(array('ENGINE=MyISAM','CONVERT TO CHARACTER SET latin1 COLLATE latin1_swedish_ci','ROW_FORMAT=COMPACT')as$change){cl_reset();cl_sql('ALTER TABLE fixture_config '.$change);cl_denied(function(){cl_controller('delete');},'Noncanonical storage rejected before files');cl_sql('DROP TABLE fixture_config');preg_match('/^CREATE TABLE phpbb_config\s*\([\s\S]*?;/m',$canonical,$m);cl_sql(str_replace('phpbb_config','fixture_config',$m[0]));}
 cl_reset();$debug=$base.'/ctracker/logfiles/logfile_debug_mode.txt';unlink($debug);mkdir($debug);$denied=false;try{ctracker_log_debug_download();}catch(CtrackerLogAuthorityFailure$error){$denied=true;}cl_check($denied&&cl_closed(),'Raw debug helper rejects a directory and releases authority');rmdir($debug);file_put_contents($debug,'');
 cl_reset();$bad=$base.'/ctracker/logfiles/logfile_spammer.txt';unlink($bad);mkdir($bad);$previous_error_log=ini_get('error_log');ini_set('error_log',$base.'/cache/fixture-errors.log');$denied=false;try{cl_controller('delete');}catch(CtLogControllerExit$error){$denied=true;}finally{ini_set('error_log',$previous_error_log);rmdir($bad);file_put_contents($bad,'');if(is_file($base.'/cache/fixture-errors.log')){unlink($base.'/cache/fixture-errors.log');}}cl_check($denied&&$template->rendered===0&&cl_closed(),'Actual filesystem failure releases authority before error output');
 $outside=$base.'/cache/synthetic-outside.txt';file_put_contents($outside,'Never stream a linked target');unlink($debug);$linked=@symlink($outside,$debug);try{if($linked){$denied=false;ob_start();try{ctracker_log_debug_download();}catch(CtrackerLogAuthorityFailure$error){$denied=true;}finally{$output=ob_get_clean();}cl_check($denied&&$output===''&&cl_closed(),'Actual raw helper rejects a symbolic link before bytes');}}finally{if($linked){unlink($debug);}unlink($outside);file_put_contents($debug,'');}
 $controller=file_get_contents($app.'admin/admin_cracker_tracker.php');cl_check(substr_count($controller,'try { ctracker_log_debug_download(); }')===1,'Actual dispatcher routes raw helper through the tested scope');
 echo 'Native CrackerTracker log authority: ',$checks," actual-controller/current-role/session/exact-JR/row-lock/caller-transaction/storage/raw-stream cases PASS; own schema and synthetic log bytes only.\n";
 }
}finally{
 if($scope){$scope->release();}if($db){foreach($db->connections as$c){if(!$c->closed){$c->sql_close();}}$db->sql_close();}if($peer){$peer->sql_close();}
 if($created){if(!preg_match('/^codex_ct_log_authority_[a-f0-9]{16}$/D',$schema)){throw new RuntimeException('Own schema required');}$control->sql_query('DROP DATABASE '.$schema);}$control->sql_close();
 if($made){if(is_link($base)||dirname(realpath($base))!==realpath(sys_get_temp_dir())){throw new RuntimeException('Own file root required');}foreach($files as$name){$path=$base.'/ctracker/logfiles/'.$name;if(is_file($path)){unlink($path);}}$path=$base.'/cache/ctracker_counter.cache';if(is_file($path)){unlink($path);}rmdir($base.'/cache');rmdir($base.'/ctracker/logfiles');rmdir($base.'/ctracker');rmdir($base);}
 restore_error_handler();
}
