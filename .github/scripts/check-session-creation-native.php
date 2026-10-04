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
define('PAGE_INDEX',0);define('POST_FORUM_URL','f');
foreach(array('session_pagestart','session_clean')as$name){ats_load_function($ats_source.'includes/sessions.php',$name);}
preg_match_all("/define\('(AUTH_[A-Z_]+)', ([0-9]+)\);/",file_get_contents($ats_source.'includes/constants.php'),$auth_constants,PREG_SET_ORDER);
foreach($auth_constants as$c){if(!defined($c[1])){define($c[1],(int)$c[2]);}}
define('AUTH_ACCESS_TABLE','fixture_acl');define('USER_GROUP_TABLE','fixture_members');
foreach(array('auth','auth_check_user')as$name){ats_load_function($ats_source.'includes/auth.php',$name);}
ats_load_function($ats_source.'attach_mod/includes/functions_includes.php','attach_setup_basic_auth');
if(PHP_SAPI!=='cli'||getenv('PHPBB_SESSION_CREATION_NATIVE')!=='1'){echo "Session creation native checks require an explicitly enabled disposable database.\n";return;}
require $ats_source.'db/mysqli.php';
class SessionCreationDatabase extends sql_db {
 var $reject_read='';
 function sql_query($sql='',$tx=false){if($this->reject_read!==''&&strpos($sql,$this->reject_read)!==false){return false;}return parent::sql_query($sql,$tx);}
}
$port=getenv('PHPBB_SESSION_CREATION_PORT')?:'3306';$password=getenv('PHPBB_SESSION_CREATION_PASSWORD')?:'';
ats_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Loopback port');
$host='127.0.0.1:'.$port;$fixture='codex_session_creation_'.bin2hex(phpbb_random_bytes(8));
$control=new sql_db($host,'root',$password,'',false);ats_check($control->sql_query('CREATE DATABASE '.$fixture.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Owned schema');$db=new SessionCreationDatabase($host,'root',$password,$fixture,false);
function session_creation_query($sql){$r=$GLOBALS['db']->sql_query($sql);ats_check($r,'Native session SQL');return $r;}
function session_creation_rows($sql){$r=session_creation_query($sql);$rows=$GLOBALS['db']->sql_fetchrowset($r);$GLOBALS['db']->sql_freeresult($r);return $rows;}
function session_creation_insert($table,$values){foreach(session_creation_rows('SHOW COLUMNS FROM '.$table)as$c){if(!array_key_exists($c['Field'],$values)&&$c['Null']==='NO'&&$c['Default']===null&&strpos($c['Extra'],'auto_increment')===false){$values[$c['Field']]=preg_match('/^(tinyint|smallint|mediumint|int|bigint|float|double|decimal)/',$c['Type'])?0:'';}}$quoted=array();foreach($values as$value){$quoted[]="'".$GLOBALS['db']->sql_escape((string)$value)."'";}session_creation_query('INSERT INTO '.$table.'('.implode(',',array_keys($values)).')VALUES('.implode(',',$quoted).')');}
function session_creation_fixture($case){
 global$db,$board_config,$plus_config,$HTTP_COOKIE_VARS,$SID;
 $board_config=array('board_disable'=>0,'max_password_age'=>0,'cookie_name'=>'fixture','cookie_path'=>'/','cookie_domain'=>'','cookie_secure'=>0,'allow_autologin'=>1);$plus_config=array('disable_sid'=>1);$_GET=array();$SID='';$GLOBALS['session_native_cookies']=array();
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
 $schema=file_get_contents($ats_source.'install/schemas/mysql_schema.sql');foreach(array('users'=>'fixture_users','sessions'=>'fixture_sessions','sessions_keys'=>'fixture_keys','banlist'=>'fixture_bans','config'=>'fixture_config','auth_access'=>'fixture_acl','user_group'=>'fixture_members')as$table=>$target){ats_check(preg_match('/CREATE TABLE `?phpbb_'.$table.'`?\s*\([\s\S]*?;/',$schema,$match)===1,'Canonical session participant');session_creation_query(str_replace('phpbb_'.$table,$target,$match[0]));}
 foreach(array('board_disable'=>0,'max_password_age'=>0,'cookie_name'=>'fixture','cookie_path'=>'/','cookie_domain'=>'','cookie_secure'=>0,'allow_autologin'=>1)as$key=>$value){session_creation_insert('fixture_config',array('config_name'=>$key,'config_value'=>$value));}
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
 $resumed=0;
 foreach(array('cookie','url')as$transport){foreach(array('active','admin','guest','elapsed-block','unknown-age','force-change','logged-out','inactive','blocked','guest-admin','guest-logged')as$case){
  list($id,$auto,$persistent,$old_sid,$admin)=session_creation_fixture(strpos($case,'guest')===0?'guest':'active');
  $board_config['session_length']=3600;$plus_config['show_last_visit']=0;$tree=array();
  $user=session_begin($id,'7f000001',0,0,0,$case==='admin'||$case==='logged-out'?1:0);$sid=$user['session_id'];
  if($case==='logged-out'){session_creation_query('UPDATE fixture_sessions SET session_logged_in=0');}
  if($case==='inactive'){session_creation_query('UPDATE fixture_users SET user_active=0 WHERE user_id=2');}
  if($case==='blocked'){session_creation_query('UPDATE fixture_users SET user_blocktime=2147483647 WHERE user_id=2');}
  if($case==='elapsed-block'){session_creation_query('UPDATE fixture_users SET user_blocktime=1 WHERE user_id=2');}
  if($case==='force-change'){session_creation_query('UPDATE fixture_users SET user_passwd_change=-9999 WHERE user_id=2');}
  if($case==='guest-admin'){session_creation_query('UPDATE fixture_sessions SET session_admin=1');}
  if($case==='guest-logged'){session_creation_query('UPDATE fixture_sessions SET session_logged_in=1');}
  // Even a valid persistent credential must not undo this session's explicit
  // rejection. Existing device keys remain intact for separate future logins.
  $key=str_repeat('b',32);session_creation_insert('fixture_keys',array('key_id'=>md5($key),'user_id'=>2,'last_ip'=>'7f000001','last_login'=>time()));
  $HTTP_COOKIE_VARS=$transport==='cookie'?array('fixture_sid'=>$sid,'fixture_data'=>addslashes(serialize(array('userid'=>2,'autologinid'=>$key)))):array();$_GET=$transport==='url'?array('sid'=>$sid):array();$session_native_cookies=array();
  $current=session_pagestart('7f000001',0,0);$valid=in_array($case,array('active','admin','elapsed-block','unknown-age','force-change'),true);
  ats_check((int)$current['user_id']===($valid?2:-1)&&(int)$current['session_user_id']===($valid?2:-1)&&(bool)$current['session_logged_in']===$valid,'Current resumed identity: '.$transport.' '.$case);
  ats_check((int)$current['session_admin']===($case==='admin'?1:0),'Current resumed ACP marker: '.$case);
  $stored=session_creation_rows("SELECT session_user_id,session_logged_in,session_admin FROM fixture_sessions WHERE session_id='".$db->sql_escape($current['session_id'])."'")[0];
  ats_check((int)$stored['session_user_id']===(int)$current['user_id']&&(int)$stored['session_logged_in']===(int)$valid&&(int)$stored['session_admin']===(int)$current['session_admin'],'Stored and returned authority agree');
  $access=auth(AUTH_READ,1,$current,array('forum_id'=>1,'auth_read'=>AUTH_REG));ats_check((bool)$access['auth_read']===$valid,'Actual registered forum ACL follows normalized session');
  if(!$valid&&$case!=='guest'){$data=unserialize($session_native_cookies['fixture_data']);ats_check((int)$data['userid']===-1&&$data['autologinid']===''&&count(session_creation_rows('SELECT key_id FROM fixture_keys WHERE user_id=2'))===1,'Rejected session delivers guest cookie without rotating/revoking unrelated device key');}
  $resumed++;
 }}
 // Execute the complete existing card block up to its success presentation;
 // permission/account checks and both production SQL statements are retained.
 $card=file_get_contents($ats_source.'card.php');$start=strpos($card,"if ( \$mode == 'block' )");$end=strpos($card,"\n\t\$no_error_ban=true;",$start);ats_check($start!==false&&$end>$start,'Actual card block boundary');$card_block=substr($card,$start,$end-$start)."\n}";
 foreach(array('','NO_BACKSLASH_ESCAPES','ANSI_QUOTES')as$sql_mode){$db->sql_query("SET SESSION sql_mode='$sql_mode'");
  foreach(array('allowed','unauthorized','admin','missing')as$case){session_creation_fixture('active');session_begin(2,'7f000001',0,0,0,1);$before=session_creation_rows('SELECT * FROM fixture_sessions');
   $mode='block';$is_auth=array('auth_ban'=>$case!=='unauthorized');$poster_id=$case==='missing'?99:2;$user_ip='7f000001';$configured_block_minutes=5;if($case==='admin'){session_creation_query('UPDATE fixture_users SET user_level=1 WHERE user_id=2');}$denied=false;
   try{eval($card_block);}catch(AttachSettingsExit$e){$denied=true;}
   ats_check($denied===($case!=='allowed'),'Actual card permission/target checks in SQL mode '.$sql_mode.' '.$case);
   if(!$denied){$row=session_creation_rows('SELECT session_user_id,session_logged_in,session_admin FROM fixture_sessions')[0];ats_check((int)$row['session_user_id']===-1&&(int)$row['session_logged_in']===0&&(int)$row['session_admin']===0,'Actual card block clears complete session authority');ats_check((int)session_creation_rows('SELECT user_blocktime FROM fixture_users WHERE user_id=2')[0]['user_blocktime']>time(),'Actual card block sets deadline');}
   else{ats_check(session_creation_rows('SELECT * FROM fixture_sessions')===$before&&(int)session_creation_rows('SELECT user_blocktime FROM fixture_users WHERE user_id=2')[0]['user_blocktime']===0,'Rejected card block does not change account/sessions');}
  }
 }
 $db->sql_query("SET SESSION sql_mode=''");
 foreach(array('SELECT u.*, s.*','WHERE user_id = -1')as$read){
  session_creation_fixture('active');$board_config['session_length']=3600;$plus_config['show_last_visit']=0;$user=session_begin(2,'7f000001',0);session_creation_query('UPDATE fixture_sessions SET session_logged_in=0');$HTTP_COOKIE_VARS=array('fixture_sid'=>$user['session_id']);$session_native_cookies=array();$db->reject_read=str_replace('\\n',"\n",$read);$denied=false;
  try{session_pagestart('7f000001',0,0);}catch(AttachSettingsExit$e){$denied=true;}finally{$db->reject_read='';}
  ats_check($denied&&$session_native_cookies===array(),'Failed current/anonymous read cannot publish rejected identity');
 }
 session_creation_fixture('missing-guest');$denied=false;try{session_begin(-1,'7f000001');}catch(AttachSettingsExit$e){$denied=true;}ats_check($denied&&$session_native_cookies===array(),'Missing anonymous seed fails before session/cookie publication');
 echo 'Native session creation: 10 creation + '.$resumed." existing identity/ACL cases passed.\n";
}finally{$db->sql_close();$control->sql_query('DROP DATABASE '.$fixture);$control->sql_close();restore_error_handler();}
