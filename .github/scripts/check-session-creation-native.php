<?php
// Only cookie delivery is substituted; all session reads/writes use the real
// production function and mysqli driver against an owned disposable schema.
function phpbb_setcookie($name,$value,$expires,$path,$domain,$secure){$GLOBALS['session_native_cookies'][$name]=$value;return true;}
putenv('PHPBB_ATTACH_SETTINGS_NATIVE=0');require __DIR__.'/check-attachment-settings-storage.php';
define('ANONYMOUS',-1);define('CRITICAL_ERROR',3);define('CRITICAL_MESSAGE',4);
define('SESSION_METHOD_COOKIE',1);define('SESSION_METHOD_GET',2);
define('SESSIONS_KEYS_TABLE','fixture_keys');define('BANLIST_TABLE','fixture_bans');
define('CONFIG_TABLE','fixture_config');
ats_load_function($ats_source.'includes/sessions.php','session_begin');
if(PHP_SAPI!=='cli'||getenv('PHPBB_SESSION_CREATION_NATIVE')!=='1'){echo "Session creation native checks require an explicitly enabled disposable database.\n";return;}
require $ats_source.'db/mysqli.php';
$port=getenv('PHPBB_SESSION_CREATION_PORT')?:'3306';$password=getenv('PHPBB_SESSION_CREATION_PASSWORD')?:'';
ats_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Loopback port');
$host='127.0.0.1:'.$port;$fixture='codex_session_creation_'.bin2hex(phpbb_random_bytes(8));
$control=new sql_db($host,'root',$password,'',false);ats_check($control->sql_query('CREATE DATABASE '.$fixture.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Owned schema');$db=new sql_db($host,'root',$password,$fixture,false);
function session_creation_query($sql){$r=$GLOBALS['db']->sql_query($sql);ats_check($r,'Native session SQL');return $r;}
function session_creation_rows($sql){$r=session_creation_query($sql);$rows=$GLOBALS['db']->sql_fetchrowset($r);$GLOBALS['db']->sql_freeresult($r);return $rows;}
function session_creation_insert($table,$values){foreach(session_creation_rows('SHOW COLUMNS FROM '.$table)as$c){if(!array_key_exists($c['Field'],$values)&&$c['Null']==='NO'&&$c['Default']===null&&strpos($c['Extra'],'auto_increment')===false){$values[$c['Field']]=preg_match('/^(tinyint|smallint|mediumint|int|bigint|float|double|decimal)/',$c['Type'])?0:'';}}$quoted=array();foreach($values as$value){$quoted[]="'".$GLOBALS['db']->sql_escape((string)$value)."'";}session_creation_query('INSERT INTO '.$table.'('.implode(',',array_keys($values)).')VALUES('.implode(',',$quoted).')');}
function session_creation_fixture($case){
 global$db,$board_config,$plus_config,$HTTP_COOKIE_VARS,$SID;
 $board_config=array('board_disable'=>0,'cookie_name'=>'fixture','cookie_path'=>'/','cookie_domain'=>'','cookie_secure'=>0,'allow_autologin'=>1);$plus_config=array('disable_sid'=>1);$_GET=array();$SID='';$GLOBALS['session_native_cookies']=array();
 foreach(array('fixture_users','fixture_sessions','fixture_keys','fixture_bans')as$table){session_creation_query('DELETE FROM '.$table);}
 foreach(array(-1,2,3)as$id){session_creation_insert('fixture_users',array('user_id'=>$id,'user_active'=>$id===3?0:1,'user_email'=>$id===-1?'':'member@example.invalid'));}
 $old_sid=str_repeat('a',32);$key=str_repeat('b',32);
 session_creation_insert('fixture_sessions',array('session_id'=>$old_sid,'session_user_id'=>-1,'session_start'=>1,'session_time'=>1,'session_ip'=>'7f000001','session_page'=>0,'session_logged_in'=>0,'session_admin'=>0));
 $HTTP_COOKIE_VARS=array('fixture_sid'=>$old_sid);$id=2;$auto=1;$persistent=0;$admin=0;
 if($case==='guest'){$id=-1;}
 elseif($case==='inactive'){$id=3;$auto=0;}
 elseif($case==='inactive-admin'){$id=3;$auto=0;$admin=1;}
 elseif($case==='missing'){$id=4;$auto=0;}
 elseif($case==='active'){$auto=0;}
 elseif($case==='missing-guest'){$id=-1;session_creation_query('DELETE FROM fixture_users WHERE user_id=-1');}
 else{
  if($case==='revoked'){$id=3;}
  if(in_array($case,array('valid','revoked'),true)){session_creation_insert('fixture_keys',array('key_id'=>md5($key),'user_id'=>$id,'last_ip'=>'7f000001','last_login'=>1));}
  $HTTP_COOKIE_VARS['fixture_data']=addslashes(serialize(array('userid'=>$id,'autologinid'=>$case==='malformed'?'invalid-key':$key)));$persistent=1;
 }
 return array($id,$auto,$persistent,$old_sid,$admin);
}
try{
 set_error_handler(function($s,$m){if(error_reporting()&$s){throw new RuntimeException($m);}});
 $schema=file_get_contents($ats_source.'install/schemas/mysql_schema.sql');foreach(array('users'=>'fixture_users','sessions'=>'fixture_sessions','sessions_keys'=>'fixture_keys','banlist'=>'fixture_bans','config'=>'fixture_config')as$table=>$target){ats_check(preg_match('/CREATE TABLE `?phpbb_'.$table.'`?\s*\([\s\S]*?;/',$schema,$match)===1,'Canonical session participant');session_creation_query(str_replace('phpbb_'.$table,$target,$match[0]));}
 foreach(array('board_disable'=>0,'cookie_name'=>'fixture','cookie_path'=>'/','cookie_domain'=>'','cookie_secure'=>0,'allow_autologin'=>1)as$key=>$value){session_creation_insert('fixture_config',array('config_name'=>$key,'config_value'=>$value));}
 foreach(array('guest','expired','revoked','malformed','inactive','inactive-admin','missing','active','valid')as$case){
  list($id,$auto,$persistent,$old_sid,$admin)=session_creation_fixture($case);$user=session_begin($id,'7f000001',0,$auto,$persistent,$admin);$authenticated=in_array($case,array('active','valid'),true);
  ats_check(is_array($user)&&(int)$user['user_id']===($authenticated?2:-1)&&(bool)$user['session_logged_in']===$authenticated,'Actual session fallback: '.$case);
  $rows=session_creation_rows('SELECT * FROM fixture_sessions');ats_check(count($rows)===1&&(int)$rows[0]['session_logged_in']===(int)$authenticated&&(int)$rows[0]['session_user_id']===($authenticated?2:-1),'Native session row agrees: '.$case);
  ats_check((int)$rows[0]['session_admin']===0,'Failed ACP authentication cannot publish a guest admin marker');
  ats_check(isset($session_native_cookies['fixture_sid'])&&$session_native_cookies['fixture_sid']===$rows[0]['session_id'],'Cookie carries actual stored session');
  $data=unserialize($session_native_cookies['fixture_data']);ats_check((int)$data['userid']===($authenticated?2:-1),'Cookie does not retain inactive/missing identity');
  if(!$authenticated){ats_check($data['autologinid']===''&&session_creation_rows("SELECT key_id FROM fixture_keys WHERE key_id<>'".md5(str_repeat('b',32))."'")===array(),'Failed autologin cannot publish another key');}
  else{ats_check($rows[0]['session_id']!==$old_sid&&$SID==='','Authenticated cookie session rotates without URL SID');}
 }
 session_creation_fixture('missing-guest');$denied=false;try{session_begin(-1,'7f000001');}catch(AttachSettingsExit$e){$denied=true;}ats_check($denied&&$session_native_cookies===array(),'Missing anonymous seed fails before session/cookie publication');
 echo "Native session creation: 10 expired/inactive/rotation cases passed.\n";
}finally{$db->sql_close();$control->sql_query('DROP DATABASE '.$fixture);$control->sql_close();restore_error_handler();}
