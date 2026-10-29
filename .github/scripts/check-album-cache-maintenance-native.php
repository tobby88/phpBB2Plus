<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_ALBUM_CACHE_MAINTENANCE_NATIVE')!=='1'){echo "Native Album cache maintenance fixture skipped.\n";return;}
define('IN_PHPBB',true);define('ADMIN',1);define('ANONYMOUS',-1);define('GENERAL_ERROR',202);define('GENERAL_MESSAGE',200);
$phpEx='php';$table_prefix='fixture_';foreach(array('USERS_TABLE'=>'users','SESSIONS_TABLE'=>'sessions','USER_GROUP_TABLE'=>'user_group','CONFIG_TABLE'=>'config','JR_ADMIN_TABLE'=>'jr_admin_users')as$name=>$suffix){define($name,$table_prefix.$suffix);}
$source=dirname(dirname(__DIR__)).'/phpBB2/';$phpbb_root_path=$source;
require$source.'includes/php_compat.php';require$source.'db/mysqli.php';require$source.'album_mod/album_constants.php';require$source.'album_mod/album_cache_maintenance.php';require __DIR__.'/album-native-observer.php';
class AcmControllerMessage extends RuntimeException {var$kind;function __construct($kind,$text){$this->kind=$kind;parent::__construct($text);}}
function message_die($kind,$text){throw new AcmControllerMessage($kind,$text);}
$bootstrap=file_get_contents($source.'admin/pagestart.php');foreach(array('phpbb_admin_post_session_valid','phpbb_admin_require_post_session')as$function){if(!preg_match('/\tfunction '.$function.'\(\)\s*\{[\s\S]*?^\t\}/m',$bootstrap,$m)){throw new RuntimeException('Actual administration POST helper');}eval($m[0]);}
function acm_check($ok,$message){if(!$ok){throw new RuntimeException($message);}$GLOBALS['cases']++;}
class AcmSource extends sql_db {
	var$queries=array();var$failure=0;var$count=0;var$hook=null;var$lost=false;var$armed=false;
	function sql_dedicated_connection(){return new AlbumNativeObservedConnection(parent::sql_dedicated_connection(),$this);}
	function observed_query($database,$sql,$transaction=false){
		$this->queries[]=$sql;if(++$this->count===$this->failure){return false;}
		if(is_callable($this->hook)){call_user_func($this->hook,$database,$sql);}
		$result=$database->sql_query($sql,$transaction);if($sql==='START TRANSACTION'){$this->armed=true;}
		if($sql==='COMMIT'&&$this->armed){$this->armed=false;if($this->lost){$this->lost=false;return false;}}return$result;
	}
	function reset_case($failure=0){$this->queries=array();$this->failure=$failure;$this->count=0;$this->hook=null;$this->lost=false;$this->armed=false;}
}
$port=getenv('PHPBB_ALBUM_CACHE_MAINTENANCE_PORT')?:'3306';$password=getenv('PHPBB_ALBUM_CACHE_MAINTENANCE_PASSWORD')?:'';
if(!preg_match('/^[0-9]{1,5}$/D',$port)||(int)$port<1||(int)$port>65535){throw new RuntimeException('Owned loopback port');}
$host='127.0.0.1:'.$port;$schema='codex_album_cache_admin_'.bin2hex(phpbb_random_bytes(8));$base=sys_get_temp_dir().'/'.$schema;$created=false;$created_dir=false;$db=null;$cases=0;$link_cases=0;
$control=new sql_db($host,'root',$password,'',false);$cache_names=array('cache/one.jpg','cache/Größe 😀.png','cache/legacy.WEBP','med_cache/one.png','med_cache/legacy.webp');
$protected_names=array('one.jpg','original.webp','cache/index.html','cache/.htaccess','cache/.album-stage-owned.tmp','cache/keep.php','cache/nested.jpg/inside.png','med_cache/index.html','med_cache/unfinished.jpg.tmp');
function acm_rows($sql){$r=$GLOBALS['control']->sql_query($sql);acm_check($r!==false,'Own fixture SQL');$rows=$GLOBALS['control']->sql_fetchrowset($r);$GLOBALS['control']->sql_freeresult($r);return$rows;}
function acm_sql($sql){$r=$GLOBALS['control']->sql_query($sql);if(!$r){throw new RuntimeException('Own cache fixture SQL failed');}return$r;}
function acm_literal($value){return "CONVERT(0x".bin2hex($value).' USING utf8mb4)';}
function acm_file($relative){return$GLOBALS['base'].'/album_mod/upload/'.$relative;}
function acm_files(){foreach($GLOBALS['cache_names']as$name){acm_check(file_put_contents(acm_file($name),'CACHE:'.$name)!==false,'Own cache fixture file');}foreach($GLOBALS['protected_names']as$name){acm_check(file_put_contents(acm_file($name),'PROTECTED:'.$name)!==false,'Own protected fixture file');}}
function acm_protected(){foreach($GLOBALS['protected_names']as$name){acm_check(is_file(acm_file($name))&&file_get_contents(acm_file($name))==='PROTECTED:'.$name,'Original/stage/index/nested content preserved: '.$name);}}
function acm_existing_cache(){return count(array_filter($GLOBALS['cache_names'],function($name){return is_file(acm_file($name));}));}
function acm_frame($junior=false){
	// Fixture setup is one committed transaction, not dozens of fsyncs per case.
	// Application transactions and contention probes remain real and unchanged.
	acm_sql('START TRANSACTION');try{
	foreach(array('album','album_file_items','album_file_jobs','album_cat','album_config','config','sessions','user_group','jr_admin_users','users')as$suffix){acm_sql('DELETE FROM fixture_'.$suffix);}
	acm_sql("INSERT INTO fixture_users(user_id,username,user_password,user_level,user_active) VALUES(-1,'Anonymous','',0,0),(7,'Current admin','',".($junior?0:1).",1)");
	acm_sql("INSERT INTO fixture_config VALUES('session_length','3600'),('unrelated','preserve')");foreach($GLOBALS['seeds']as$sql){acm_sql($sql);}
	acm_sql("INSERT INTO fixture_sessions(session_id,session_user_id,session_logged_in,session_time,session_ip,session_admin) VALUES('".str_repeat('a',32)."',7,1,".time().",'7f000001',1)");
	if($junior){acm_sql('INSERT INTO fixture_jr_admin_users(user_id,user_jr_admin,admin_notes) VALUES(7,'.acm_literal(md5('Photo_AlbumClear_Cacheadmin_album_clearcache.php')).",'')");}
	acm_sql('COMMIT');}catch(Exception$error){$GLOBALS['control']->sql_query('ROLLBACK');throw$error;}catch(Error$error){$GLOBALS['control']->sql_query('ROLLBACK');throw$error;}
	$GLOBALS['userdata']=array('user_id'=>7,'username'=>'Stale name','user_level'=>ADMIN,'user_active'=>1,'session_id'=>str_repeat('a',32),'session_logged_in'=>true,'session_admin'=>1,'session_ip'=>'ffffffff');
	$GLOBALS['album_config']=array('marker'=>'preserve');$GLOBALS['album_data']=array('marker'=>'preserve');$GLOBALS['phpbb_root_path']=$GLOBALS['source'];$GLOBALS['lang']=array('Thumbnail_cache_cleared_successfully'=>'CLEARED','Album_cache_directory_error'=>'CACHE_ERROR');
	$_POST=array('confirm'=>'yes','sid'=>str_repeat('a',32));$_SERVER['REQUEST_METHOD']='POST';$_SERVER['SCRIPT_NAME']='/admin/admin_album_clearcache.php';acm_files();$GLOBALS['db']->reset_case();
}
function acm_apply(){return(new AlbumCacheMaintenance($GLOBALS['db'],'fixture_',$GLOBALS['base']))->clear($GLOBALS['userdata']);}
function acm_denied(){ $denied=false;try{acm_apply();}catch(Exception$error){$denied=true;}acm_check($denied&&acm_existing_cache()===5,'Rejected fresh authority/request leaves all caches untouched');acm_protected();}
function acm_controller(){
	global$db,$userdata,$phpbb_root_path,$phpEx,$table_prefix,$lang,$board_config;
	$code=file_get_contents($GLOBALS['source'].'admin/admin_album_clearcache.php');$code=preg_replace('/^<\?php\s*/','',$code,1);$code=preg_replace('/^require\([^\r\n]+\);\s*$/m','',$code,-1,$removed);acm_check($removed===4,'Only actual legacy bootstrap replaced by fixture');
	$needle="\$phpbb_root_path = '../';";acm_check(substr_count($code,$needle)===1,'Actual controller root boundary');$code=str_replace($needle,'$phpbb_root_path = '.var_export($GLOBALS['source'],true).';',$code);
	$needle='new AlbumCacheMaintenance($db, $table_prefix, $phpbb_root_path)';acm_check(substr_count($code,$needle)===1,'Actual cache controller capability boundary');$code=str_replace($needle,'new AlbumCacheMaintenance($db, $table_prefix, '.var_export($GLOBALS['base'],true).')',$code);
	try{eval($code);}catch(AcmControllerMessage$message){return$message->kind;}throw new RuntimeException('Actual cache controller did not complete');
}
try{
	acm_check($control->db_connect_id,'Own loopback cache control');acm_sql('CREATE DATABASE '.$schema.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$created=true;acm_sql('USE '.$schema);
	$ddl=file_get_contents($source.'install/schemas/mysql_schema.sql');foreach(array('users','sessions','user_group','config','jr_admin_users','album','album_cat','album_comment','album_rate','album_config','album_file_jobs','album_file_items')as$suffix){acm_check(preg_match('/^CREATE TABLE `?phpbb_'.$suffix.'`?\s*\([\s\S]*?;/m',$ddl,$m)===1,'Canonical cache dependency schema');acm_sql(str_replace('phpbb_'.$suffix,'fixture_'.$suffix,$m[0]));}
	preg_match_all('/^INSERT INTO phpbb_album_config\b[^\r\n]*;/m',file_get_contents($source.'install/schemas/mysql_basic.sql'),$m);$seeds=array();foreach($m[0]as$sql){$seeds[]=str_replace('phpbb_album_config','fixture_album_config',$sql);}acm_check(count($seeds)>40,'Actual current Album defaults');
	acm_check(mkdir($base,0700),'Own cache filesystem root');$created_dir=true;foreach(array('album_mod','album_mod/upload','album_mod/upload/cache','album_mod/upload/med_cache','album_mod/upload/cache/nested.jpg')as$directory){acm_check(mkdir($base.'/'.$directory,0700),'Own cache directory');}
	$db=new AcmSource($host,'root',$password,$schema,false);$reporting=error_reporting(E_ALL);set_error_handler(function($level,$message){if(error_reporting()&$level){throw new RuntimeException($message);}});
	foreach(array(false,true)as$junior){acm_frame($junior);$result=acm_apply();acm_check($result===array('removed'=>5)&&acm_existing_cache()===0,'Root/delegated current ACP clears both caches including Unicode/WebP, without requiring a category');acm_protected();acm_check(acm_apply()===array('removed'=>0),'Clearing empty caches succeeds');}
	foreach(array("UPDATE fixture_users SET user_level=0 WHERE user_id=7","UPDATE fixture_users SET user_active=0 WHERE user_id=7","DELETE FROM fixture_sessions","UPDATE fixture_sessions SET session_admin=0","UPDATE fixture_sessions SET session_logged_in=0","UPDATE fixture_sessions SET session_time=1","UPDATE fixture_sessions SET session_id=UPPER(session_id)","UPDATE fixture_sessions SET session_user_id=-1","DELETE FROM fixture_config WHERE config_name='session_length'","UPDATE fixture_config SET config_value='0' WHERE config_name='session_length'")as$change){acm_frame();acm_sql($change);acm_denied();}
	foreach(array('',md5('Photo_AlbumCategoriesadmin_album_cat.php'),md5('Photo_AlbumClear_Cacheadmin_album_clearcache.php').'0')as$grant){acm_frame(true);acm_sql('UPDATE fixture_jr_admin_users SET user_jr_admin='.($grant===''?"''":acm_literal($grant)));acm_denied();}
	foreach(array(array('method','GET'),array('sid','bad'),array('sid',array()),array('missing',null))as$case){acm_frame();if($case[0]==='method'){$_SERVER['REQUEST_METHOD']=$case[1];}elseif($case[0]==='missing'){unset($_POST['sid']);}else{$_POST['sid']=$case[1];}acm_denied();}
	acm_frame();$_POST['root']='../';$_POST['scope']='original';acm_check(acm_controller()===GENERAL_MESSAGE&&acm_existing_cache()===0,'Actual controller ignores forged roots and only clears declared cache directories');acm_protected();
	acm_frame();acm_sql('UPDATE fixture_users SET user_level=0 WHERE user_id=7');acm_check(acm_controller()===GENERAL_ERROR&&acm_existing_cache()===5,'Actual controller rejects stale privileged request before unlink');acm_protected();
	acm_frame(true);acm_check(acm_controller()===GENERAL_MESSAGE,'Actual controller preserves exact delegated cache permission');acm_protected();
	acm_frame();$namespace='album_files_'.sha1($schema."\0fixture_");acm_sql("SELECT GET_LOCK('".$namespace."',0)");acm_denied();acm_check(acm_rows("SELECT IS_USED_LOCK('".$namespace."') owner")[0]['owner']!==null,'Source-owned competing lease is not released');acm_sql("DO RELEASE_LOCK('".$namespace."')");
	acm_frame();acm_sql('SET SESSION innodb_lock_wait_timeout=0');$probed=false;
	$db->hook=function($connection,$sql)use(&$probed){if(!$probed&&strpos($sql,'SELECT config_name,config_value FROM fixture_album_config')===0){$probed=true;foreach(array('UPDATE fixture_users SET user_level=0 WHERE user_id=7','UPDATE fixture_sessions SET session_admin=0',"UPDATE fixture_config SET config_value='1' WHERE config_name='session_length'")as$revoke){acm_check($GLOBALS['control']->sql_query($revoke)===false,'Actual actor/session/lifetime locks serialize revocation until cache operation finishes');}}};
	acm_check(acm_apply()===array('removed'=>5)&&$probed,'Current administration stays locked during file removal');acm_sql('UPDATE fixture_users SET user_level=0 WHERE user_id=7');acm_sql('SET SESSION innodb_lock_wait_timeout=5');acm_protected();
	acm_frame();$db->sql_query('START TRANSACTION');$db->sql_query("UPDATE fixture_config SET config_value='pending' WHERE config_name='unrelated'");acm_check(acm_apply()===array('removed'=>5),'Dedicated Album connections preserve caller pending transaction');$pending=$db->sql_query("SELECT config_value FROM fixture_config WHERE config_name='unrelated'");$row=$db->sql_fetchrow($pending);$db->sql_freeresult($pending);acm_check($row['config_value']==='pending'&&acm_rows("SELECT config_value FROM fixture_config WHERE config_name='unrelated'")[0]['config_value']==='preserve','Caller write neither committed nor rolled back');$db->sql_query('ROLLBACK');acm_protected();
	acm_frame();$db->lost=true;$failed=false;try{acm_apply();}catch(Exception$error){$failed=true;}acm_check($failed&&acm_existing_cache()===0,'Lost final commit acknowledgement never restores or deletes original data');acm_protected();
	acm_frame();$originals=acm_file('one.jpg');$small=acm_file('cache');$held=acm_file('cache-held');acm_check(rename($small,$held),'Own alias fixture boundary');
	try{if(function_exists('symlink')&&@symlink(realpath(acm_file('')),$small)){$link_cases++;$failed=false;try{acm_apply();}catch(Exception$error){$failed=true;}acm_check($failed&&is_file($originals)&&file_get_contents($originals)==='PROTECTED:one.jpg','Aliased cache-to-original root refuses before any unlink');}else{acm_check(DIRECTORY_SEPARATOR===chr(92),'POSIX cache alias coverage required');$failed=false;try{acm_apply();}catch(Exception$error){$failed=true;}acm_check($failed&&is_file($originals),'Missing configured cache root refuses all file work');}}finally{if(is_link($small)){unlink($small);}acm_check(!file_exists($small)&&rename($held,$small),'Restore only own cache directory');}
	acm_frame();if(function_exists('symlink')&&@symlink(realpath(acm_file('one.jpg')),acm_file('cache/linked.jpg'))){$link_cases++;try{acm_check(acm_apply()===array('removed'=>5)&&is_link(acm_file('cache/linked.jpg')),'Linked cache entries preserved without following targets');acm_protected();}finally{unlink(acm_file('cache/linked.jpg'));}}else{acm_check(DIRECTORY_SEPARATOR===chr(92),'POSIX cache entry link coverage required');}
	acm_frame();$start=time();acm_sql("UPDATE fixture_config SET config_value='2' WHERE config_name='session_length'");acm_sql('UPDATE fixture_sessions SET session_time='.$start);$expired=false;
	$db->hook=function($connection,$sql)use(&$expired,$start){if(!$expired&&acm_existing_cache()<5){$expired=true;while(time()<$start+2){usleep(10000);}}};$failed=false;try{acm_apply();}catch(Exception$error){$failed=true;}acm_check($failed&&$expired&&acm_existing_cache()===4,'Wall-clock expiry after first derived deletion prevents all later file removals');acm_protected();
	$boundaries=0;foreach(array(false,true)as$junior){
		acm_frame($junior);acm_apply();$baseline=$db->queries;$boundaries+=count($baseline);
		foreach($baseline as$index=>$sql){acm_frame($junior);$db->reset_case($index+1);$failed=false;try{acm_apply();}catch(Exception$error){$failed=true;}acm_check($failed,'Every native root/delegated SQL boundary reports failure: '.($index+1));acm_protected();acm_check(!acm_rows('SELECT * FROM fixture_album_file_jobs')&&!acm_rows('SELECT * FROM fixture_album_file_items'),'Cache-only operation never invents file receipts');}
	}
	echo 'Native Album cache maintenance: '.$cases.' checks PASS; '.$boundaries.' real SQL boundaries, actual ACP controller, root/delegated/current authority, original/stage preservation, both caches/WebP, lease serialization, expiry, uncertain commit and caller transaction; symlink cases='.$link_cases.".\n";
}finally{
	if(isset($reporting)){restore_error_handler();error_reporting($reporting);}if($db){$db->sql_close();}if($created){if(!preg_match('/^codex_album_cache_admin_[a-f0-9]{16}$/D',$schema)){throw new RuntimeException('Unowned schema cleanup');}$control->sql_query('DROP DATABASE '.$schema);}$control->sql_close();
	if($created_dir){if(is_link($base)||realpath(dirname($base))!==realpath(sys_get_temp_dir())||!preg_match('/^codex_album_cache_admin_[a-f0-9]{16}$/D',basename($base))){throw new RuntimeException('Refuse unowned filesystem cleanup');}foreach(array_merge($cache_names,$protected_names)as$name){$file=acm_file($name);if(is_file($file)||is_link($file)){unlink($file);}}foreach(array('album_mod/upload/cache/nested.jpg','album_mod/upload/cache','album_mod/upload/med_cache','album_mod/upload','album_mod')as$directory){if(is_dir($base.'/'.$directory)){rmdir($base.'/'.$directory);}}rmdir($base);}
}
