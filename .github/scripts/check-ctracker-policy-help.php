<?php
// Match public labels to actual unchanged validators, not a new password policy.
if (PHP_SAPI !== 'cli') { exit(2); }
define('IN_PHPBB', true); define('CTRACKER_ACP', true); define('GENERAL_MESSAGE', 0);
set_error_handler(function($s,$m){if(error_reporting()&$s){throw new RuntimeException($m);}});
$root=dirname(dirname(__DIR__)).'/phpBB2/';
$phpbb_root_path=$root;$phpEx='php';
// Language files build a few help links while loading; no session is needed.
function append_sid($url,$non_html_amp=false){return $url;}
require $root.'includes/php_compat.php';
require $root.'includes/functions_validate.php';
require $root.'includes/functions_board_config.php';
require $root.'ctracker/classes/class_ct_userfunctions.php';
require $root.'ctracker/classes/class_ct_adminfunctions.php';
class PolicyHelpRejection extends RuntimeException {}
function message_die($type,$message){throw new PolicyHelpRejection($message);}
function help_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
$cases=0;
foreach(array('english','german')as$locale){
 $lang=array();
 require $root.'language/lang_'.$locale.'/lang_main.php';
 require $root.'language/lang_'.$locale.'/lang_admin.php';
 require $root.'language/lang_'.$locale.'/lang_cback_ctracker.php';
 foreach(array('Password_len','Password_len_explain','Password_to_short','ctracker_settings_m26','ctracker_settings_e26','ctracker_info_password_minlng')as$key){
  help_check(isset($lang[$key])&&stripos($lang[$key],'byte')!==false,'Missing byte units: '.$locale.'/'.$key);$cases++;
 }
 help_check(substr_count($lang['ctracker_info_password_minlng'],'%s')===2&&substr_count($lang['Password_to_short'],'%d')===1,'Retain formatter contracts');
 help_check(stripos($lang['ctracker_settings_e36'],'Logfile')===false&&stripos($lang['ctracker_settings_e36'],'check the Log')===false,'No obsolete spam-log instruction');
 help_check(strpos($lang['Password_len_explain'],'0')!==false&&strpos($lang['Password_len_explain'],'72')!==false,'Document current ACP byte range');
 foreach(array('0','1','32','33','72')as$value){
  $values=phpbb_board_config_values(array('min_password_len'=>$value),array('min_password_len'=>'8'));
  help_check($values===array('min_password_len'=>$value),'Actual ACP accepts documented byte range');$cases++;
 }
 foreach(array('-1','73','100','invalid')as$value){
  $denied=false;
  try{phpbb_board_config_values(array('min_password_len'=>$value),array('min_password_len'=>'8'));}
  catch(PhpbbAclException$e){$denied=true;help_check($e->getMessage()===$lang['Password_minimum_invalid'],'Current localized invalid range message');}
  help_check($denied,'Actual ACP rejects out-of-range value');$cases++;
 }
 $admin=new ct_adminfunctions();
 foreach(array(0,1,2)as$setting){
  $html=$admin->ct_keyword_b_block($setting);
  help_check(substr_count($html,'<option ' )===3&&substr_count($html,'selected="selected"')===1&&strpos($html,'value="'.$setting.'" selected="selected"')!==false,'Actual off/profile/profile+post choices unchanged');$cases++;
 }
 $samples=array(
  array('1234567',true),array('12345678',false),
  array("\xF0\x9F\x98\x80",true),array(str_repeat("\xF0\x9F\x98\x80",2),false),
  array(str_repeat('ä',3),true),array(str_repeat('ä',4),false),
  array(str_repeat("\\'",3),true),array(str_repeat("\\'",4),false),
  array(str_repeat('漢',2),true),array(str_repeat('漢',3),false),
  array('select<>9',false),array('a&b',true)
 );
 foreach($samples as$sample){
  // These are the legacy internal values received after common.php adds
  // request quoting. Do not decode, normalize, hash or mutate credentials.
  $HTTP_POST_VARS=array('new_password'=>$sample[0]);$before=$HTTP_POST_VARS;
  $ctracker_config=new stdClass();$ctracker_config->settings=array('pw_complex'=>0,'pw_complex_min'=>8);
  $rejected=false;
  try{$user=new ct_userfunctions();$user->password_functions();}
  catch(PolicyHelpRejection$e){$rejected=true;help_check($e->getMessage()===sprintf($lang['ctracker_info_password_minlng'],8,strlen($sample[0])),'Localized CT rejection numbers match actual byte count');}
  help_check($rejected===$sample[1]&&$HTTP_POST_VARS===$before,'Actual CT byte policy/input unchanged');$cases++;
  $board_config=array('min_password_len'=>8,'password_not_login'=>0,'force_complex_password'=>0);
  $result=validate_complex_password('fixture',$sample[0],false);
  help_check($result['error']===$sample[1],'Actual core byte policy unchanged');
  if($sample[1]){help_check($result['error_msg']===$lang['Password_not_complex'].sprintf($lang['Password_to_short'],8),'Localized core rejection matches byte units');}$cases++;
 }
}
echo 'CrackerTracker policy help: '.$cases." actual localized byte-policy, formatter and selector cases passed; no hash, database or HTTP operations.\n";
