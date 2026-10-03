<?php
putenv('PHPBB_ATTACH_SETTINGS_NATIVE=0'); require __DIR__.'/check-attachment-settings-storage.php';
define('ANONYMOUS',-1); define('PAGE_INDEX',0);
require __DIR__.'/profile-request-fixture.php';
ats_load_function($ats_source.'includes/functions.php','phpbb_clean_username');
ats_load_function($ats_source.'includes/functions.php','get_userdata');
ats_load_function($ats_source.'includes/functions.php','utf8_rawurldecode');
ats_load_function($ats_source.'ajax.php','ajax_scalar_value');
ats_load_function($ats_source.'ajax.php','ajax_request_value');
ats_load_function($ats_source.'ajax.php','ajax_request_int');
foreach(array('AJAX_PM_USERNAME_FOUND'=>101,'AJAX_PM_USERNAME_ERROR'=>102,'AJAX_OP_COMPLETED'=>103,'AJAX_PM_USERNAME_SELECT'=>104) as $key=>$value){define($key,$value);}
foreach(array('GROUPS_TABLE'=>'fixture_groups','DISALLOW_TABLE'=>'fixture_disallow','WORDS_TABLE'=>'fixture_words')as$key=>$value){define($key,$value);}
require $ats_source.'includes/functions_validate.php';
// Browser maxlength counts UTF-16 units; supplementary characters need two.
foreach(array('login_body.tpl','profile_send_pass.tpl','profile_add_body.tpl','posting_body.tpl','shoutbox_max_body.tpl','admin/admin_add_user_body.tpl','admin/user_edit_body.tpl') as $file){
 $markup=file_get_contents($ats_source.'templates/fisubsilversh/'.$file);preg_match_all('/<input\b[^>]*name="username"[^>]*>/i',$markup,$inputs);
 ats_check(count($inputs[0])>0,'Actual username form field');foreach($inputs[0] as $input){if(strpos($input,'type="hidden"')!==false){continue;}ats_check(preg_match('/maxlength="([0-9]+)"/',$input,$length)===1&&(int)$length[1]>=50,'Form can submit all 25 supplementary Unicode characters');}
}
if(PHP_SAPI!=='cli'||getenv('PHPBB_USERNAME_NATIVE')!=='1'){echo "Username identity checks require an explicitly enabled disposable database.\n";return;}
require $ats_source.'db/mysqli.php';
$port=getenv('PHPBB_USERNAME_PORT')?:'3306';$password=getenv('PHPBB_USERNAME_PASSWORD')?:'';
ats_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Loopback port');
$host='127.0.0.1:'.$port;$fixture='codex_username_'.bin2hex(phpbb_random_bytes(8));
$control=new sql_db($host,'root',$password,'',false);ats_check($control->sql_query('CREATE DATABASE '.$fixture.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Owned schema');
$db=new sql_db($host,'root',$password,$fixture,false);
class UsernameSessionExit extends RuntimeException {}
class UsernameAjaxExit extends RuntimeException {var $response;function __construct($response){$this->response=$response;}}
function AJAX_message_die($response){throw new UsernameAjaxExit($response);}
function session_begin($id,$ip,$page,$update,$autologin,$admin){throw new UsernameSessionExit('session:'.$id);}
function redirect($url){throw new UsernameSessionExit('redirect');}
function ctracker_enforce_login_identity_limit($name){$GLOBALS['username_limits'][]=$name;}
class log_manager {function prepare_log($username){}function write_general_logfile($limit,$kind){}}
class UsernameLoginTemplate {function assign_vars($v){}}
$source=file_get_contents($ats_source.'login.php');$a=strpos($source,'$submitted_username =');$b=strpos($source,"\n\telse if( ( isset(\$_GET['logout'])",$a);
ats_check($a!==false&&$b>$a,'Actual login branch');$login_body=substr($source,$a,$b-$a)."\n}";
$include='include_once($phpbb_root_path . \'ctracker/classes/class_log_manager.\' . $phpEx);';
ats_check(substr_count($login_body,$include)===1,'Only logging boundary substituted');$login_body=str_replace($include,'/* Owned fixture logger. */',$login_body);
$source=file_get_contents($ats_source.'privmsg.php');$a=strpos($source,'$submitted_username =',strpos($source,'privmsg_post_session_is_valid'));
$b=strpos($source,"\n\t\tif ( isset(\$to_userdata)",$a);
ats_check($a!==false&&$b>$a,'Actual PN recipient branch');$recipient_body=substr($source,$a,$b-$a);
$source=file_get_contents($ats_source.'ajax.php');$a=strpos($source,'// Get username',strpos($source,"else if ((\$mode == 'checkusername_pm')"));$b=strpos($source,"\nelse if (\$mode == 'checkemail')",$a);
ats_check($a!==false&&$b>$a,'Actual complete AJAX recipient branch');$ajax_body=substr($source,$a,$b-$a);$ajax_body=substr($ajax_body,0,strrpos($ajax_body,"\n}"));
$installer=file_get_contents($ats_source.'install/install.php');ats_load_function($ats_source.'install/install.php','install_slash_request_value');
$a=strpos($installer,'$admin_name =');$b=strpos($installer,'// Undo only the installer',$a);ats_check($a!==false&&$b>$a,'Actual installer name parser');$installer_input=substr($installer,$a,$b-$a);
$a=strpos($installer,'$sql = "UPDATE " . $table_prefix . "users');$b=strpos($installer,'if (!$db->sql_query($sql))',$a);ats_check($a!==false&&$b>$a,'Actual installer admin write');$installer_write=substr($installer,$a,$b-$a);
function username_sql($sql){$r=$GLOBALS['db']->sql_query($sql);ats_check($r,'Owned identity SQL');return $r;}
function username_login($input){
 global $db,$login_body,$userdata,$board_config,$ctracker_config,$template,$phpEx,$user_ip,$lang;
 profile_fixture_request(array('login'=>'1','username'=>$input,'password'=>'FixturePassword123!','sid'=>'identity-session'));
 $sid='identity-session';$userdata=array('session_id'=>$sid,'session_logged_in'=>false);$board_config=array('board_disable'=>false,'password_hashing'=>false);
 $ctracker_config=new stdClass();$ctracker_config->settings=array('logsize_logins'=>10);$template=new UsernameLoginTemplate();$phpEx='php';$user_ip='127.0.0.1';$GLOBALS['username_limits']=array();
 try{eval($login_body);}catch(UsernameSessionExit $e){return $e->getMessage();}catch(AttachSettingsExit $e){return 'denied';}throw new RuntimeException('Login must terminate');
}
function username_recipient($input){global $db,$lang,$recipient_body;profile_fixture_request(array('username'=>$input));$error=false;$error_msg='';eval($recipient_body);return !$error&&is_array($to_userdata)?(int)$to_userdata['user_id']:null;}
function username_ajax($input){global $db,$lang,$ajax_body;profile_fixture_request(array('username'=>$input));$mode='checkusername_pm';try{eval($ajax_body);}catch(UsernameAjaxExit $e){return $e->response['result'];}throw new RuntimeException('AJAX must terminate');}
try{
 set_error_handler(function($s,$m){if(error_reporting()&$s){throw new RuntimeException($m);}});
 username_sql("CREATE TABLE fixture_users(user_id INT PRIMARY KEY,username VARCHAR(25) NOT NULL,user_password VARCHAR(255) NOT NULL,user_active INT NOT NULL DEFAULT 1,user_level INT NOT NULL DEFAULT 0,user_blocktime INT NOT NULL DEFAULT 0,user_notify_pm INT NOT NULL DEFAULT 0,user_email VARCHAR(255) NOT NULL DEFAULT '',user_lang VARCHAR(30) NOT NULL DEFAULT 'english',user_absence INT NOT NULL DEFAULT 0,user_absence_mode INT NOT NULL DEFAULT 0,user_absence_text TEXT NOT NULL) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
 foreach(array('groups'=>'group_name','disallow'=>'disallow_username','words'=>'word') as $table=>$field){username_sql('CREATE TABLE fixture_'.$table.' ('.$field.' VARCHAR(255) NOT NULL) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');}
 $cases=0;
 foreach(array('', 'NO_BACKSLASH_ESCAPES')as$mode){username_sql("SET SESSION sql_mode='$mode'");
  foreach(array('Plain','Müller','í',str_repeat('ä',25),str_repeat('😀',25),'A&B',"O'Reilly",'C:\\notes',"A\\'B",'Trailing\\','%C3%A4','&#39;','0')as$raw){
   username_sql('DELETE FROM fixture_users');$key=phpbb_clean_username($raw);ats_check($key!==''&&preg_match('//u',$key)===1,'Full valid Unicode key');
   ats_check(!validate_username($raw)['error']&&!validate_username($key,false,0,true)['error'],'Raw and explicit stored validation agree before creation');
   username_sql("INSERT INTO fixture_users(user_id,username,user_password,user_absence_text)VALUES(2,'".$db->sql_escape($key)."','".md5('FixturePassword123!')."','')");
   ats_check(username_login($raw)==='session:2','Actual login selects full identity');ats_check(username_recipient($raw)===2,'Actual recipient selects same identity');ats_check((int)get_userdata($raw,true)['user_id']===2,'Shared reader agrees');ats_check(username_ajax($raw)===AJAX_PM_USERNAME_FOUND,'Actual AJAX recipient agrees');
   ats_check(validate_username($raw)['error']&&validate_username($key,false,0,true)['error'],'Existing identity cannot be registered twice');
   $GLOBALS['mode']='editprofile';$GLOBALS['userdata']=array('username'=>$key);profile_fixture_request(array('username'=>$raw));
   $parsed=phpbb_username_form(phpbb_request_raw_value($_POST['username']),$key);ats_check($parsed===$key,'Unrelated profile save preserves stored identity');$cases++;
  }
  foreach(array("O'Reilly",str_repeat("'",25))as$raw){
   username_sql('DELETE FROM fixture_users');$legacy=phpbb_username_key($raw,ENT_COMPAT);ats_check($legacy!=='','Historical quote key');username_sql("INSERT INTO fixture_users(user_id,username,user_password,user_absence_text)VALUES(2,'".$db->sql_escape($legacy)."','".md5('FixturePassword123!')."','')");
   ats_check(username_login($raw)==='session:2'&&username_recipient($raw)===2&&username_ajax($raw)===AJAX_PM_USERNAME_FOUND,'Existing ENT_COMPAT identity remains accessible');ats_check(phpbb_username_form($raw,$legacy)===$legacy,'Unchanged historical name is never silently rewritten');$cases++;
  }
  username_sql('DELETE FROM fixture_users');foreach(array(2=>phpbb_username_key("O'Reilly"),3=>phpbb_username_key("O'Reilly",ENT_COMPAT))as$id=>$key){username_sql("INSERT INTO fixture_users(user_id,username,user_password,user_absence_text)VALUES($id,'".$db->sql_escape($key)."','".md5('FixturePassword123!')."','')");}
  ats_check(username_login("O'Reilly")==='denied'&&username_recipient("O'Reilly")===null&&get_userdata("O'Reilly",true)===false,'Ambiguous historical quote namespace never selects either account');$cases++;
  username_sql('DELETE FROM fixture_users');$prefix=str_repeat('a',25);username_sql("INSERT INTO fixture_users(user_id,username,user_password,user_absence_text)VALUES(2,'$prefix','".md5('FixturePassword123!')."',''),(3,'','".md5('FixturePassword123!')."','')");
  foreach(array($prefix.'x',str_repeat('ä',26),str_repeat('😀',26),"bad\xc3","bad\0name",'')as$bad){ats_check(phpbb_clean_username($bad)===''&&username_login($bad)==='denied'&&username_recipient($bad)===null&&get_userdata($bad,true)===false&&username_ajax($bad)===AJAX_PM_USERNAME_ERROR&&validate_username($bad)['error'],'Invalid/oversized input never resolves a prefix or empty-name account');$cases++;}
  username_sql('DELETE FROM fixture_users');ats_check(validate_username('<b>',false,0,true)['error']&&validate_username('A&B',false,0,true)['error'],'Claimed stored identity cannot bypass HTML canonicalization');
  ats_check(!validate_username('<b>')['error']&&!validate_username('A&B')['error'],'Raw inputs validate their correctly encoded identities');
  foreach(array(str_repeat('ä',25),str_repeat('😀',25),'A&B',"O'Reilly",'C:\\notes',"A\\'B")as$raw){
   $_POST=install_slash_request_value(array('admin_name'=>$raw));eval($installer_input);ats_check(!$install_username_invalid&&$admin_name_key===phpbb_username_key($raw),'Actual installer accepts full identity');
   username_sql('DELETE FROM fixture_users');username_sql("INSERT INTO fixture_users(user_id,username,user_password,user_absence_text)VALUES(2,'Admin','before','')");
   $table_prefix='fixture_';$admin_password=md5('FixturePassword123!');$language='english';$board_email='owner@example.invalid';eval($installer_write);username_sql($sql);
   ats_check(username_login($raw)==='session:2','Actual installer SQL creates login-compatible identity in each SQL mode');$cases++;
  }
 }
 echo 'Native username identities: '.$cases." login/recipient/reader/profile cases passed.\n";
}finally{$db->sql_close();$control->sql_query('DROP DATABASE '.$fixture);$control->sql_close();restore_error_handler();}
