<?php
// Actual profile handler and dictionaries; no registration/profile save or SQL.
define('IN_PHPBB',true);define('GENERAL_MESSAGE',0);error_reporting(E_ALL);
$app=dirname(dirname(__DIR__)).'/phpBB2/';$phpEx='php';
require$app.'ctracker/classes/class_ct_userfunctions.php';
class ModernContactRejected extends RuntimeException{}
class ModernContactNoDatabase {function __call($name,$args){throw new RuntimeException('Unexpected database access');}}
function message_die($type,$message){throw new ModernContactRejected($message);}
function mc_check($ok,$message){if(!$ok){throw new RuntimeException($message);}$GLOBALS['checks']++;}
$fields=array('website','signal','threema','tg','dc','fb','ig','li','tt','twr');
$form=file_get_contents($app.'templates/fisubsilversh/profile_add_body.tpl');
$checks=0;$cases=0;$db=new ModernContactNoDatabase();
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
foreach($fields as$field){mc_check(strpos($form,'name="'.$field.'"')!==false,'Contact test must cover a real current profile field');}
foreach(array('english','german')as$locale){
 $lang=array();require$app.'language/lang_'.$locale.'/lang_cback_ctracker.php';
 foreach(array('register','editprofile')as$mode){foreach($fields as$field){
  foreach(array(array('Grüße 😀 ordinary',false,1,true),array('VIAGRA',true,1,true),array("vi\xC2\xADagra",true,1,true),
   array(str_repeat("\xF0\x9F\x98\x80",249).'viagra',true,1,true),array(array('viagra'),false,1,true),
   array('viagra',false,0,true),array('viagra',false,1,false))as$sample){
   $ctracker_config=new stdClass();$ctracker_config->settings=array('spam_keyword_det'=>$sample[2],'reg_protection'=>0,'autoban_mails'=>0);
   $userdata=array('user_id'=>2,'user_level'=>0,'user_posts'=>0);$HTTP_POST_VARS=array($field=>$sample[0]);if($sample[3]){$HTTP_POST_VARS['submit']=1;}$_POST=$HTTP_POST_VARS;
   $snapshot=serialize(array($HTTP_POST_VARS,$_POST,$userdata,$ctracker_config));$functions=new ct_userfunctions();
   for($round=0;$round<3;$round++){
    if($round===2){$functions=new ct_userfunctions();}$rejected=false;
    try{$functions->handle_profile();}catch(ModernContactRejected$e){$rejected=true;$key=$mode==='register'?'ctracker_info_profile_spammer':'ctracker_info_profile_content';mc_check($e->getMessage()===$lang[$key],'Wrong localized contact-policy message');}
    mc_check($rejected===$sample[1],'Modern contact field did not follow the configured keyword policy');
    mc_check(serialize(array($HTTP_POST_VARS,$_POST,$userdata,$ctracker_config))===$snapshot,'Contact comparison changed input, account or policy');
   }$cases++;
  }
 }}
}
restore_error_handler();echo 'CrackerTracker modern contacts: ',$cases,' actual EN/DE register/edit cases, ',$checks," assertions PASS; current form fields, UTF-8, existing switches and unchanged request/account data.\n";
