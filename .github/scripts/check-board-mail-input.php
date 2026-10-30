<?php
define('IN_PHPBB',true);if(isset($argv[1])&&$argv[1]==='legacy'){define('PHPBB_LEGACY_REQUEST_ESCAPED',true);}
$root=dirname(dirname(__DIR__)).'/phpBB2/';$phpbb_root_path=$root;$phpEx='php';$table_prefix='fixture_';require$root.'includes/php_compat.php';require$root.'includes/constants.php';require$root.'includes/functions_board_mail.php';
$source=file_get_contents($root.'includes/functions.php');if(preg_match('/^function allow_send_to_absent\(\).*?^\}/ms',$source,$m)!==1){throw new RuntimeException('Actual absence helper missing');}eval($m[0]);
$cases=0;function bi_check($ok,$message){if(!$ok){throw new RuntimeException($message);}$GLOBALS['cases']++;}
function bi_post($post){return defined('PHPBB_LEGACY_REQUEST_ESCAPED')?phpbb_addslashes_recursive($post):$post;}
function bi_bad($kind,$post,$key){$caught=null;try{phpbb_board_mail_payload($kind,bi_post($post));}catch(PhpbbMailFailure$e){$caught=$e->getMessage();}bi_check($caught===$key,'Controlled payload failure: '.$key);}
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{
 foreach(array('english','german')as$locale){$lang=array();require$root.'language/lang_'.$locale.'/lang_main.php';bi_check(phpbb_mail_suggested_subject('')===$lang['Mail_recommendation_subject'],'Localized empty topic fallback');}
 $profile=array('email_subject'=>str_repeat('😀',200),'email_message'=>"C:\\Pfad\\Grüße O'Reilly &amp;\nNew line",'cc_email'=>'1');$data=phpbb_board_mail_payload('profile',bi_post($profile));bi_check($data['subject']===$profile['email_subject']&&$data['message']===$profile['email_message']&&$data['copy']===true,'Actual subject/body capacities, raw boundary and own copy');
 $profile['email_subject'].='ä';bi_bad('profile',$profile,'Mail_too_long');$profile['email_subject']='Subject';
 foreach(array('email_subject','email_message')as$field){foreach(array(array(),true,123,"bad\0value","\xff",' ',"\t\n")as$value){$invalid=$profile;$invalid[$field]=$value;bi_bad('profile',$invalid,'Mail_invalid');}}
 foreach(array(array(),'yes',2)as$copy){$invalid=$profile;$invalid['cc_email']=$copy;bi_bad('profile',$invalid,'Mail_invalid');}
 $friend=array('friendname'=>'','friendemail'=>'friend@example.invalid','topic'=>str_repeat('😀',255),'message'=>'Grüße \\ Pfad');$data=phpbb_board_mail_payload('friend',bi_post($friend));bi_check($data['fname']==='friend'&&$data['subject']===str_repeat('😀',200)&&$data['message']===$friend['message'],'Recipient fallback and UTF8-safe suggested subject');
 $friend['friendemail']="friend@example.invalid\r\nBcc: other@example.invalid";bi_bad('friend',$friend,'Email_invalid');
 $board_config=array('server_name'=>'fixture.invalid','server_port'=>443,'cookie_secure'=>1,'script_path'=>'');$board=rtrim(phpbb_board_url(),'/');
 foreach(array('viewtopic.php?t=1#p2',$board.'/viewtopic.php?t=1#p2',$board.':443/viewtopic.php?t=1')as$link){$result=phpbb_mail_board_link($link);bi_check(strpos($result,'fixture.invalid')!==false&&strpos($result,'viewtopic.php?t=1')!==false,'Same-origin link retained');}
 foreach(array('https://other.invalid/topic','https://fixture.invalid:444/topic','https://user@fixture.invalid/topic',"https://fixture.invalid/\r\nother",'https://fixture.invalid\\@other.invalid/topic',str_repeat('x',2049),array())as$link){bi_check(phpbb_mail_board_link($link)===$board,'Unsafe/off-site link replaced by board URL');}
 foreach(array(array(USER,0,false),array(MOD,0,false),array(MOD,1,true),array(ADMIN,0,true))as$policy){$userdata=array('user_level'=>$policy[0]);$board_config=array('board_email_form'=>1,'mod_able_sent_absent'=>$policy[1]);$recipient=array('user_active'=>1,'user_viewemail'=>1,'user_absence'=>1);bi_check(allow_send_to_absent()===$policy[2],'Actual legacy absence helper reads configured moderator policy');bi_check((phpbb_profile_mail_permission($recipient,$userdata,$board_config)==='')===$policy[2],'Shared recipient absence policy agrees with legacy helper');}
 $recipient=array('user_active'=>1,'user_viewemail'=>0,'user_absence'=>0);foreach(array(USER,MOD,ADMIN)as$role){bi_check((phpbb_profile_mail_permission($recipient,array('user_level'=>$role),array('board_email_form'=>1))==='')===($role===ADMIN),'Member opt-out/admin exception preserved');}
 echo 'Board mail input: ',$cases,' actual Unicode/field/URL/absence/preference cases PASS (',defined('PHPBB_LEGACY_REQUEST_ESCAPED')?'legacy':'raw',").\n";
}finally{restore_error_handler();}
