<?php
// Exercise login.php and session_begin together. Only logging, final redirect
// and cookie transport are substituted; password checks and SQL are real.
class LoginPublicationExit extends RuntimeException {}
function phpbb_setcookie($name,$value,$expires,$path,$domain,$secure){ats_check($GLOBALS['lp_open']===0&&$GLOBALS['lp_committed'],'Cookies only after confirmed commit and owner release');$GLOBALS['lp_cookies'][$name]=$value;return true;}
function redirect($url){throw new LoginPublicationExit(count($GLOBALS['lp_cookies'])===2?'published':'redirect');}
function ctracker_enforce_login_identity_limit($name){}
class log_manager {function prepare_log($name){}function write_general_logfile($size,$kind){}}
putenv('PHPBB_ATTACH_SETTINGS_NATIVE=0');require __DIR__.'/check-attachment-settings-storage.php';
foreach(array('ANONYMOUS'=>-1,'PAGE_INDEX'=>0,'CRITICAL_ERROR'=>3,'CRITICAL_MESSAGE'=>4,'SESSION_METHOD_COOKIE'=>1,'SESSION_METHOD_GET'=>2,'SESSIONS_KEYS_TABLE'=>'fixture_sessions_keys','BANLIST_TABLE'=>'fixture_banlist','CONFIG_TABLE'=>'fixture_config')as$key=>$value){define($key,$value);}
require __DIR__.'/profile-request-fixture.php';ats_load_function($ats_source.'includes/functions.php','phpbb_clean_username');ats_load_function($ats_source.'includes/sessions.php','session_begin');ats_load_function($ats_source.'includes/sessions.php','session_pagestart');
require $ats_source.'includes/functions_login_storage.php';
if(PHP_SAPI!=='cli'||getenv('PHPBB_LOGIN_PUBLICATION_NATIVE')!=='1'){echo "Login publication checks require an explicitly enabled disposable database.\n";return;}
require $ats_source.'db/mysqli.php';$port=getenv('PHPBB_LOGIN_PUBLICATION_PORT')?:'3306';$password=getenv('PHPBB_LOGIN_PUBLICATION_PASSWORD')?:'';
ats_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Loopback port');
$host='127.0.0.1:'.$port;$fixture='codex_login_publication_'.bin2hex(phpbb_random_bytes(8));$control=new sql_db($host,'root',$password,'',false);ats_check($control->sql_query('CREATE DATABASE '.$fixture.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Owned schema');
class LoginPublicationDatabase extends sql_db {
 function sql_dedicated_connection(){if($GLOBALS['lp_reuse']){return $this;}return new LoginPublicationConnection(parent::sql_dedicated_connection());}
 function sql_query($sql='',$tx=false){
  if(preg_match('/^\s*(INSERT|UPDATE|DELETE)\b/i',$sql)){ats_check($GLOBALS['lp_fixture_write'],'No manual-login publication writes on main connection');}
  if($GLOBALS['lp_race']&&!$GLOBALS['lp_changed']&&preg_match('/SELECT \*\s+FROM fixture_users\s+WHERE user_id = 2\b/s',$sql)){
   $GLOBALS['lp_changed']=true;lp_query("UPDATE fixture_users SET user_password='".md5('ReplacedPassword123!')."' WHERE user_id=2");lp_query('DELETE FROM fixture_sessions WHERE session_user_id=2');lp_query('DELETE FROM fixture_sessions_keys WHERE user_id=2');
  }
  return parent::sql_query($sql,$tx);
 }
}
class LoginPublicationConnection {
 var $db;var $db_connect_id;
 function __construct($db){$this->db=$db;$this->db_connect_id=$db->db_connect_id;$GLOBALS['lp_open']++;}
 function __call($method,$args){return call_user_func_array(array($this->db,$method),$args);}
 function sql_close(){if($this->db_connect_id!==null){$GLOBALS['lp_open']--;try{$this->db->sql_close();}finally{$this->db_connect_id=null;}}}
 function sql_query($sql,$tx=false){
  $GLOBALS['lp_owner_queries'][]=$sql;
  if(is_callable($GLOBALS['lp_hook'])){call_user_func($GLOBALS['lp_hook'],$sql,$this);}
  if($sql!=='ROLLBACK'&&++$GLOBALS['lp_query_count']===$GLOBALS['lp_failure']){return false;}
  if($sql==='COMMIT'&&$GLOBALS['lp_commit_failure']==='before'){return false;}
  if($GLOBALS['lp_race']&&!$GLOBALS['lp_changed']&&preg_match('/SELECT \* FROM fixture_users WHERE user_id=2 FOR UPDATE/',$sql)){
   $GLOBALS['lp_changed']=true;lp_query("UPDATE fixture_users SET user_password='".md5('ReplacedPassword123!')."' WHERE user_id=2");lp_query('DELETE FROM fixture_sessions WHERE session_user_id=2');lp_query('DELETE FROM fixture_sessions_keys WHERE user_id=2');
  }
  $r=$this->db->sql_query($sql,$tx);
  if($sql==='COMMIT'&&$r){if($GLOBALS['lp_commit_failure']==='ack'){return false;}$GLOBALS['lp_committed']=true;if(is_callable($GLOBALS['lp_after_commit'])){call_user_func($GLOBALS['lp_after_commit']);}}
  return $r;
 }
}
class LoginPublicationTemplate {function assign_vars($values){}}
$lp_open=$lp_query_count=$lp_failure=0;$lp_hook=$lp_after_commit=null;$lp_fixture_write=$lp_reuse=$lp_committed=false;$lp_commit_failure='';$lp_owner_queries=array();
$main=new LoginPublicationDatabase($host,'root',$password,$fixture,false);$peer=new sql_db($host,'root',$password,$fixture,false);$db=$main;$lp_race=$lp_changed=false;$lp_cookies=array();
function lp_query($sql){$r=$GLOBALS['peer']->sql_query($sql);if(!$r){$error=$GLOBALS['peer']->sql_error();throw new RuntimeException('Owned disposable login SQL: '.$error['code'].' '.$error['message']);}return $r;}
function lp_rows($sql){$r=lp_query($sql);$rows=$GLOBALS['peer']->sql_fetchrowset($r);$GLOBALS['peer']->sql_freeresult($r);return $rows;}
function lp_insert($table,$values){foreach(lp_rows('SHOW COLUMNS FROM '.$table)as$c){if(!array_key_exists($c['Field'],$values)&&$c['Null']==='NO'&&$c['Default']===null&&strpos($c['Extra'],'auto_increment')===false){$values[$c['Field']]=preg_match('/^(tinyint|smallint|mediumint|int|bigint|float|double|decimal)/',$c['Type'])?0:'';}}$quoted=array();foreach($values as$value){$quoted[]="'".$GLOBALS['peer']->sql_escape((string)$value)."'";}lp_query('INSERT INTO '.$table.' ('.implode(',',array_keys($values)).')VALUES('.implode(',',$quoted).')');}
$source=file_get_contents($ats_source.'login.php');$a=strpos($source,'$submitted_username =');$b=strpos($source,"\n\telse if( ( isset(\$_GET['logout'])",$a);ats_check($a!==false&&$b>$a,'Actual complete credential branch');$lp_body=substr($source,$a,$b-$a)."\n}";
$logger='include_once($phpbb_root_path . \'ctracker/classes/class_log_manager.\' . $phpEx);';ats_check(substr_count($lp_body,$logger)===1,'Only external logger substituted');$lp_body=str_replace($logger,'/* Owned fixture logger. */',$lp_body);
function lp_login($race=false,$options=array()){
 global$db,$main,$lp_race,$lp_changed,$lp_cookies,$lp_body,$userdata,$board_config,$plus_config,$HTTP_COOKIE_VARS,$HTTP_POST_VARS,$ctracker_config,$template,$phpEx,$phpbb_root_path,$user_ip,$lang,$SID,$lp_open,$lp_query_count,$lp_failure,$lp_hook,$lp_after_commit,$lp_committed,$lp_commit_failure,$lp_reuse,$lp_owner_queries;
 ats_check($lp_open===0,'No leaked previous login owner');$lp_race=$race;$lp_changed=false;$lp_cookies=$lp_owner_queries=array();$db=$main;$SID='';$lp_query_count=0;$lp_committed=false;$lp_failure=isset($options['failure'])?$options['failure']:0;$lp_commit_failure=isset($options['commit'])?$options['commit']:'';$lp_hook=isset($options['hook'])?$options['hook']:null;$lp_after_commit=isset($options['after'])?$options['after']:null;$lp_reuse=!empty($options['reuse']);foreach(array('users','sessions','sessions_keys','banlist','config')as$table){lp_query('DELETE FROM fixture_'.$table);}
 $raw=isset($options['name'])?$options['name']:'Member';$name=phpbb_username_key($raw,!empty($options['legacy'])?ENT_COMPAT:ENT_QUOTES);
 foreach(array(-1,2)as$id){lp_insert('fixture_users',array('user_id'=>$id,'username'=>$id===2?$name:'Anonymous','user_active'=>1,'user_level'=>0,'user_blocktime'=>0,'user_email'=>$id===2?(isset($options['email'])?$options['email']:'member@example.invalid'):'','user_password'=>md5('FixturePassword123!'),'user_passwd_change'=>time()));}
 $sid=str_repeat('a',32);lp_insert('fixture_sessions',array('session_id'=>$sid,'session_user_id'=>-1,'session_ip'=>'7f000001','session_logged_in'=>0,'session_admin'=>0));
 profile_fixture_request(array('login'=>'1','username'=>$raw,'password'=>'FixturePassword123!','sid'=>$sid));$HTTP_COOKIE_VARS=array('fixture_sid'=>$sid);
 $userdata=array('user_id'=>-1,'session_id'=>$sid,'session_logged_in'=>false);$board_config=array('board_disable'=>0,'password_hashing'=>0,'cookie_name'=>'fixture','cookie_path'=>'/','cookie_domain'=>'','cookie_secure'=>0,'allow_autologin'=>1,'session_length'=>3600,'min_password_len'=>8,'password_not_login'=>0,'force_complex_password'=>0,'max_password_age'=>0);$plus_config=array('disable_sid'=>1);
 if(!empty($options['rehash'])){$board_config['password_hashing']=1;}
 foreach($board_config as$key=>$value){lp_insert('fixture_config',array('config_name'=>$key,'config_value'=>$value));}
 if(!empty($options['persistent'])){$_POST['autologin']=$HTTP_POST_VARS['autologin']='1';}
 if(!empty($options['stale_cookie'])){$HTTP_COOKIE_VARS['fixture_data']=addslashes(serialize(array('userid'=>2,'autologinid'=>str_repeat('b',32))));}
 if(!empty($options['existing_cookie'])||!empty($options['automatic'])){if(empty($options['missing_key'])){lp_insert('fixture_sessions_keys',array('user_id'=>2,'key_id'=>md5(str_repeat('b',32)),'last_ip'=>'7f000001','last_login'=>time()));}$HTTP_COOKIE_VARS['fixture_data']=addslashes(serialize(array('userid'=>2,'autologinid'=>str_repeat('b',32))));}
 if(!empty($options['admin'])){$_POST['admin']=$HTTP_POST_VARS['admin']='1';}
 $ctracker_config=new stdClass();$ctracker_config->settings=array('logsize_logins'=>10,'login_history'=>0,'login_ip_check'=>0);$template=new LoginPublicationTemplate();$phpEx='php';$user_ip='7f000001';$_SERVER['REQUEST_METHOD']='POST';
 if(isset($options['setup'])){call_user_func($options['setup']);}
 try{if(!empty($options['automatic'])){$_SERVER['REQUEST_METHOD']='GET';$user=session_pagestart($user_ip,0,0);return !empty($user['session_logged_in'])?'published':'guest';}eval($lp_body);}catch(LoginPublicationExit$e){return $e->getMessage();}catch(AttachSettingsExit$e){return 'denied';}throw new RuntimeException('Login must terminate');
}
try{
 set_error_handler(function($s,$m){if(error_reporting()&$s){throw new RuntimeException($m);}});
 $schema=file_get_contents($ats_source.'install/schemas/mysql_schema.sql');foreach(array('users','sessions','sessions_keys','banlist','config')as$table){ats_check(preg_match('/CREATE TABLE `?phpbb_'.$table.'`?\s*\([\s\S]*?;/',$schema,$match)===1,'Canonical login participant');lp_query(str_replace('phpbb_'.$table,'fixture_'.$table,$match[0]));}
 $lp_owner_queries=array();$normal=lp_login(false);if($normal!=='published'){fwrite(STDERR,'Owned fixture diagnostic: '.json_encode(array('outcome'=>$normal,'last_queries'=>array_slice($lp_owner_queries,-4)))."\n");}
 ats_check($normal==='published'&&count(lp_rows('SELECT session_id FROM fixture_sessions WHERE session_user_id=2 AND session_logged_in=1'))===1,'Actual normal login publishes session');
 $result=lp_login(true);ats_check($lp_changed,'Race occurs after password verification before session read');
 ats_check($result==='denied'&&$lp_cookies===array()&&lp_rows('SELECT session_id FROM fixture_sessions WHERE session_user_id=2')===array(),'A replaced verified password cannot publish a new authenticated session or cookies');
 $cases=2;$serialized=0;
 foreach(array(array('persistent'=>1),array('stale_cookie'=>1),array('existing_cookie'=>1),array('existing_cookie'=>1,'persistent'=>1),array('rehash'=>1),array('admin'=>1))as$options){
  ats_check(lp_login(false,$options)==='published'&&$lp_open===0&&$db===$main,'Manual login with optional features commits/releases');$rows=lp_rows('SELECT * FROM fixture_sessions WHERE session_user_id=2');ats_check(count($rows)===1&&(int)$rows[0]['session_admin']===(empty($options['admin'])?0:1),'Actual stored session authority');
  $keys=lp_rows('SELECT key_id FROM fixture_sessions_keys WHERE user_id=2');ats_check(count($keys)===(empty($options['persistent'])?0:1),'Only requested persistence remains, old device cookie cannot override password login');
  ats_check($SID===(empty($options['admin'])?'':'sid='.$rows[0]['session_id']),'Full helper publishes exact final cookie/ACP SID only after commit');
  if(!empty($options['rehash'])){$row=lp_rows('SELECT user_password FROM fixture_users WHERE user_id=2')[0];ats_check($row['user_password']!==md5('FixturePassword123!')&&phpbb_password_verify('FixturePassword123!',$row['user_password']),'Optional hash upgrade and session commit together');}$cases++;
 }
 foreach(array('', 'NO_BACKSLASH_ESCAPES','ANSI_QUOTES')as$mode){$main->sql_query("SET SESSION sql_mode='$mode'");lp_query("SET SESSION sql_mode='$mode'");
  foreach(array('A&B',"O'Reilly",'C:\\notes',"A\\'B",str_repeat('ä',25),str_repeat('😀',25),str_repeat("'",25))as$name){ats_check(lp_login(false,array('name'=>$name,'legacy'=>1))==='published','Full controller and owned session retain raw/historical Unicode identity');$cases++;}
  ats_check(lp_login(false,array('email'=>"o'reilly@example.invalid"))==='published','Ban reader quotes exact current email in all SQL modes');$cases++;
 }
 // Every observed owner query may fail. Partial user/session/key writes must
 // roll back; no cookie or success survives a failed/uncertain publication.
 ats_check(lp_login(false,array('persistent'=>1,'rehash'=>1,'existing_cookie'=>1))==='published','Record complete owned path');$query_total=$lp_query_count;
 for($at=1;$at<=$query_total;$at++){
  $out=lp_login(false,array('persistent'=>1,'rehash'=>1,'existing_cookie'=>1,'failure'=>$at));ats_check($out==='denied'&&$lp_cookies===array()&&$lp_open===0&&$db===$main,'Each failed owned query prevents delivery');
  ats_check(lp_rows('SELECT session_id FROM fixture_sessions WHERE session_user_id=2')===array()&&count(lp_rows('SELECT session_id FROM fixture_sessions WHERE session_user_id=-1'))===1,'Failed path preserves old guest and no new session');
  ats_check(lp_rows('SELECT user_password FROM fixture_users WHERE user_id=2')[0]['user_password']===md5('FixturePassword123!')&&count(lp_rows('SELECT key_id FROM fixture_sessions_keys WHERE user_id=2'))===1,'Failed path preserves old hash/device key');$cases++;
 }
 foreach(array('before','ack')as$mode){ats_check(lp_login(false,array('persistent'=>1,'commit'=>$mode))==='denied'&&$lp_cookies===array()&&$lp_open===0,'Unconfirmed commit never delivers capability');$count=count(lp_rows('SELECT session_id FROM fixture_sessions WHERE session_user_id=2'));ats_check($count===($mode==='ack'?1:0),'Lost acknowledgement is distinct from failed commit');$cases++;}
 foreach(array('password'=>"UPDATE fixture_users SET user_password='replacement' WHERE user_id=2",'inactive'=>'UPDATE fixture_users SET user_active=0 WHERE user_id=2','level'=>'UPDATE fixture_users SET user_level=1 WHERE user_id=2','blocked'=>'UPDATE fixture_users SET user_blocktime=2147483647 WHERE user_id=2','rename'=>"UPDATE fixture_users SET username='Changed' WHERE user_id=2",'deleted'=>'DELETE FROM fixture_users WHERE user_id=2')as$kind=>$change){
  $fired=false;$hook=function($sql)use(&$fired,$change){if(!$fired&&strpos($sql,'SELECT * FROM fixture_users WHERE user_id=2 FOR UPDATE')===0){$fired=true;lp_query($change);}};
  ats_check(lp_login(false,array('hook'=>$hook))==='denied'&&$fired&&$lp_cookies===array(),'Changed account snapshot cannot authenticate: '.$kind);$cases++;
 }
 foreach(array(strtoupper(md5('FixturePassword123!')),password_hash('FixturePassword123!',PASSWORD_BCRYPT,array('cost'=>13)))as$hash){$fired=false;$hook=function($sql)use(&$fired,$hash){if(!$fired&&strpos($sql,'SELECT * FROM fixture_users WHERE user_id=2 FOR UPDATE')===0){$fired=true;lp_query("UPDATE fixture_users SET user_password='".$GLOBALS['peer']->sql_escape($hash)."' WHERE user_id=2");}};ats_check(lp_login(false,array('rehash'=>1,'hook'=>$hook))==='denied'&&$fired&&lp_rows('SELECT user_password FROM fixture_users WHERE user_id=2')[0]['user_password']===$hash,'Exact case-only or stronger concurrent rehash cannot be overwritten');$cases++;}
 foreach(array('session'=>"DELETE FROM fixture_sessions WHERE session_id='".str_repeat('a',32)."'",'policy'=>"UPDATE fixture_config SET config_value='1' WHERE config_name='board_disable'",'ban'=>"INSERT INTO fixture_banlist(ban_userid,ban_ip)VALUES(2,'')")as$kind=>$change){
  $fired=false;$hook=function($sql)use(&$fired,$change){if(!$fired&&strpos($sql,'SELECT config_name,config_value FROM fixture_config LOCK IN SHARE MODE')===0){$fired=true;lp_query($change);}};
  ats_check(lp_login(false,array('hook'=>$hook))==='denied'&&$fired&&$lp_cookies===array(),'Current caller/policy/ban must permit login: '.$kind);$cases++;
 }
 // Independent revocation SQL must serialize until the complete publication.
 lp_query('SET SESSION innodb_lock_wait_timeout=1');
 foreach(array("UPDATE fixture_users SET user_password='replacement' WHERE user_id=2","DELETE FROM fixture_sessions WHERE session_user_id=2","DELETE FROM fixture_sessions_keys WHERE user_id=2","UPDATE fixture_config SET config_value='1' WHERE config_name='board_disable'","INSERT INTO fixture_banlist(ban_userid,ban_ip)VALUES(2,'')")as$change){
  $blocked=false;$hook=function($sql)use(&$blocked,$change){if($sql==='COMMIT'){$r=$GLOBALS['peer']->sql_query($change);$error=$GLOBALS['peer']->sql_error();ats_check(!$r&&(int)$error['code']===1205,'Independent revocation serialized through commit');$blocked=true;}};
  ats_check(lp_login(false,array('persistent'=>1,'hook'=>$hook))==='published'&&$blocked,'Owned session remains valid before later revocation');$serialized++;$cases++;
 }
 foreach(array('users','sessions','sessions_keys','banlist','config')as$table){
  foreach(array('ENGINE=MyISAM','ENGINE=InnoDB ROW_FORMAT=COMPACT','CONVERT TO CHARACTER SET utf8 COLLATE utf8_general_ci')as$legacy){$setup=function()use($table,$legacy){
   // Only the disposable peer may construct a historically oversized COMPACT
   // row. Production connections retain innodb_strict_mode=ON and must refuse it.
   lp_query('SET SESSION innodb_strict_mode=OFF');
   try{lp_query('ALTER TABLE fixture_'.$table.' '.$legacy);}finally{lp_query('SET SESSION innodb_strict_mode=ON');}
  };ats_check(lp_login(false,array('setup'=>$setup))==='denied'&&$lp_cookies===array(),'Each unmigrated participant prevents authentication');lp_query('ALTER TABLE fixture_'.$table.' ENGINE=InnoDB ROW_FORMAT=DYNAMIC');lp_query('ALTER TABLE fixture_'.$table.' CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$cases++;}
 }
 lp_query('CREATE TABLE fixture_caller(marker INT PRIMARY KEY) ENGINE=InnoDB ROW_FORMAT=DYNAMIC');$main->sql_query('START TRANSACTION');$lp_fixture_write=true;$main->sql_query('INSERT INTO fixture_caller VALUES(1)');$lp_fixture_write=false;
 ats_check(lp_login(false)==='published'&&lp_rows('SELECT * FROM fixture_caller')===array(),'Login cannot commit caller transaction');$main->sql_query('ROLLBACK');ats_check(lp_rows('SELECT * FROM fixture_caller')===array(),'Caller can still roll back');$cases++;
 $main->sql_query('START TRANSACTION');$lp_fixture_write=true;$main->sql_query('INSERT INTO fixture_caller VALUES(2)');$lp_fixture_write=false;
 ats_check(lp_login(false,array('reuse'=>1))==='denied'&&$main->db_connect_id!==null&&lp_rows('SELECT * FROM fixture_caller')===array(),'Reused factory cannot close/commit caller');$main->sql_query('ROLLBACK');$cases++;
 echo 'Native manual login publication: '.$cases.' boundary/failure cases and '.$serialized." serialized revocations passed.\n";
 // Actual session_pagestart -> session_begin -> persistent-key owner, with
 // only cookie transport substituted. No pre-existing guest session is needed.
 $auto_cases=0;
 foreach(array(array(),array('missing_key'=>1),array('setup'=>function(){lp_query('UPDATE fixture_users SET user_active=0 WHERE user_id=2');}),array('setup'=>function(){lp_query('UPDATE fixture_users SET user_blocktime=2147483647 WHERE user_id=2');}),array('setup'=>function(){lp_query('DELETE FROM fixture_users WHERE user_id=2');}),array('setup'=>function(){lp_query('DELETE FROM fixture_sessions');}))as$options){
  $options['automatic']=1;
  $expect_auth=empty($options['missing_key'])&&(!isset($options['setup'])||$auto_cases===5);
  ats_check(lp_login(false,$options)===($expect_auth?'published':'guest')&&$lp_open===0&&$db===$main,'Persistent login or safe anonymous fallback commits/releases');
  $rows=lp_rows('SELECT * FROM fixture_sessions');ats_check(count($rows)===1&&(int)$rows[0]['session_logged_in']===(int)$expect_auth&&(int)$rows[0]['session_admin']===0,'Persistent login cannot grant ACP authority');
  $data=unserialize($lp_cookies['fixture_data']);ats_check((int)$data['userid']===($expect_auth?2:-1)&&($expect_auth?$data['autologinid']!==str_repeat('b',32):$data['autologinid']===''),'Persistent credential rotates, denied credentials clear from delivered cookie');$auto_cases++;
 }
 ats_check(lp_login(true,array('automatic'=>1))==='guest'&&$lp_changed&&lp_rows('SELECT session_id FROM fixture_sessions WHERE session_user_id=2')===array(),'Concurrent reset/key revocation before owned read cannot recreate authentication');$auto_cases++;
 ats_check(lp_login(false,array('automatic'=>1))==='published','Record complete automatic path');$query_total=$lp_query_count;
 for($at=1;$at<=$query_total;$at++){
  ats_check(lp_login(false,array('automatic'=>1,'failure'=>$at))==='denied'&&$lp_cookies===array()&&$lp_open===0,'Automatic query failure never delivers a key/session');
  ats_check(lp_rows('SELECT session_id FROM fixture_sessions WHERE session_user_id=2')===array()&&count(lp_rows("SELECT key_id FROM fixture_sessions_keys WHERE key_id='".md5(str_repeat('b',32))."'"))===1,'Automatic failure preserves original key, never publishes a new session');$auto_cases++;
 }
 foreach(array('before','ack')as$mode){ats_check(lp_login(false,array('automatic'=>1,'commit'=>$mode))==='denied'&&$lp_cookies===array()&&$lp_open===0,'Automatic lost/failed commit never delivers capability');ats_check(count(lp_rows('SELECT session_id FROM fixture_sessions WHERE session_user_id=2'))===($mode==='ack'?1:0),'Automatic uncertain commit is distinct from rollback');$auto_cases++;}
 foreach(array('account'=>'UPDATE fixture_users SET user_active=0 WHERE user_id=2','key'=>'DELETE FROM fixture_sessions_keys WHERE user_id=2','sessions'=>'DELETE FROM fixture_sessions WHERE session_user_id=2','policy'=>"UPDATE fixture_config SET config_value='0' WHERE config_name='allow_autologin'",'ban'=>"INSERT INTO fixture_banlist(ban_userid,ban_ip)VALUES(2,'')")as$kind=>$change){$blocked=false;$hook=function($sql)use(&$blocked,$change){if($sql==='COMMIT'){$r=$GLOBALS['peer']->sql_query($change);$error=$GLOBALS['peer']->sql_error();ats_check(!$r&&(int)$error['code']===1205,'Automatic revocation serialized through publication');$blocked=true;}};ats_check(lp_login(false,array('automatic'=>1,'hook'=>$hook))==='published'&&$blocked,'Current automatic authority held until commit: '.$kind);$auto_cases++;}
 foreach(array('account'=>'UPDATE fixture_users SET user_active=0 WHERE user_id=2','key'=>'DELETE FROM fixture_sessions_keys WHERE user_id=2')as$kind=>$change){$blocked=false;$hook=function($sql)use(&$blocked,$change){if(strpos($sql,'FROM fixture_users u, fixture_sessions_keys k')!==false){$r=$GLOBALS['peer']->sql_query($change);$error=$GLOBALS['peer']->sql_error();ats_check(!$r&&(int)$error['code']===1205,'Actual legacy credential reader cannot be overtaken by revocation');$blocked=true;}};ats_check(lp_login(false,array('automatic'=>1,'hook'=>$hook))==='published'&&$blocked,'Reproduced reader boundary is held: '.$kind);$auto_cases++;}
 foreach(array('key'=>'DELETE FROM fixture_sessions_keys WHERE user_id=2','account'=>'UPDATE fixture_users SET user_active=0 WHERE user_id=2','ban'=>"INSERT INTO fixture_banlist(ban_userid,ban_ip)VALUES(2,'')",'policy'=>"UPDATE fixture_config SET config_value='0' WHERE config_name='allow_autologin'")as$kind=>$change){$fired=false;$hook=function($sql)use(&$fired,$change){if(!$fired&&strpos($sql,'SELECT config_name,config_value FROM fixture_config LOCK IN SHARE MODE')===0){$fired=true;lp_query($change);}};$expected=($kind==='key'||$kind==='account')?'guest':'denied';ats_check(lp_login(false,array('automatic'=>1,'hook'=>$hook))===$expected&&$fired,'Already current revocation cannot authenticate: '.$kind);ats_check(lp_rows('SELECT session_id FROM fixture_sessions WHERE session_user_id=2')===array(),'No stale authenticated row after prior revocation');if($expected==='denied'){ats_check($lp_cookies===array(),'Refused current policy/ban delivers no capability');}$auto_cases++;}
 foreach(array('users','sessions','sessions_keys','banlist','config')as$table){$setup=function()use($table){lp_query('ALTER TABLE fixture_'.$table.' ENGINE=MyISAM');};ats_check(lp_login(false,array('automatic'=>1,'setup'=>$setup))==='denied'&&$lp_cookies===array(),'Automatic login requires every storage participant migrated');lp_query('ALTER TABLE fixture_'.$table.' ENGINE=InnoDB ROW_FORMAT=DYNAMIC');$auto_cases++;}
 $main->sql_query('START TRANSACTION');$lp_fixture_write=true;$main->sql_query('INSERT INTO fixture_caller VALUES(3)');$lp_fixture_write=false;
 ats_check(lp_login(false,array('automatic'=>1))==='published'&&lp_rows('SELECT * FROM fixture_caller')===array(),'Automatic login cannot commit caller transaction');$main->sql_query('ROLLBACK');ats_check(lp_rows('SELECT * FROM fixture_caller')===array(),'Automatic caller can roll back');$auto_cases++;
 $main->sql_query('START TRANSACTION');$lp_fixture_write=true;$main->sql_query('INSERT INTO fixture_caller VALUES(4)');$lp_fixture_write=false;
 ats_check(lp_login(false,array('automatic'=>1,'reuse'=>1))==='denied'&&$main->db_connect_id!==null&&lp_rows('SELECT * FROM fixture_caller')===array(),'Automatic factory reuse cannot close/commit caller');$main->sql_query('ROLLBACK');$auto_cases++;
 $setup=function(){lp_query('DELETE FROM fixture_users WHERE user_id=-1');};ats_check(lp_login(false,array('automatic'=>1,'missing_key'=>1,'setup'=>$setup))==='denied'&&$lp_cookies===array(),'Missing guest seed cannot publish automatic fallback');$auto_cases++;
 echo 'Native automatic login publication: '.$auto_cases." boundary/failure cases passed.\n";
}finally{$main->sql_close();$peer->sql_close();$control->sql_query('DROP DATABASE '.$fixture);$control->sql_close();restore_error_handler();}
