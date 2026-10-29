<?php
define('IN_PHPBB',true);define('POST_USERS_URL','u');define('ADMIN',1);define('ANONYMOUS',-1);
$phpbb_root_path=dirname(dirname(__DIR__)).'/phpBB2/';$phpEx='php';
require$phpbb_root_path.'includes/php_compat.php';require$phpbb_root_path.'includes/functions.php';require$phpbb_root_path.'includes/template.php';
foreach(array('TEXT_FIELD'=>0,'TEXTAREA'=>1,'RADIO'=>2,'CHECKBOX'=>3)as$key=>$value){define($key,$value);}
require$phpbb_root_path.'includes/functions_profile_fields.php';
function append_sid($url,$html=true){return$url;}
function pgv_check($ok,$message){if(!$ok){throw new RuntimeException($message);}$GLOBALS['pgv_cases']++;}
$source=file_get_contents($phpbb_root_path.'includes/usercp_viewprofile.php');
$a=strpos($source,'$temp_url = append_sid("privmsg.');$b=strpos($source,'$temp_url = append_sid("search.',$a);
if($a===false||$b<=$a){throw new RuntimeException('Actual profile contact bindings missing');}$contact_bindings=substr($source,$a,$b-$a);
$a=strpos($source,'if ( phpbb_profile_details_visible() )');$b=strpos($source,"$"."template->pparse('body');",$a);
if($a===false||$b<=$a){throw new RuntimeException('Actual profile visibility switches missing');}$switches=substr($source,$a,$b-$a);
$a=strpos($source,'foreach($profile_data as $field)');$b=strpos($source,'if ( phpbb_profile_details_visible() )',$a);
if($a===false||$b<=$a){throw new RuntimeException('Actual custom-profile contact renderer missing');}$custom=substr($source,$a,$b-$a);
$theme=array('template_name'=>'fisubsilversh');$board_config=array('xs_use_cache'=>0,'xs_auto_compile'=>0,'xs_auto_recompile'=>0,'default_lang'=>'english','board_email_form'=>0);$album_config=array('show_all_in_personal_gallery'=>1);$images=array('icon_pm'=>'pm.gif','icon_email'=>'email.gif','icon_www'=>'website.gif','icon_signal'=>'signal.gif','icon_threema'=>'threema.gif');
$profiledata=array('user_id'=>42,'user_from'=>'private-location-needle','user_from_flag'=>'private-country.png','user_viewemail'=>1,'user_email'=>'private@example.invalid','user_website'=>'https://website.example.invalid','user_fb'=>'privatefacebook','user_ig'=>'privateinstagram','user_twr'=>'privatetwitter','user_tg'=>'privatetelegram','user_li'=>'privatelinkedin','user_tt'=>'privatetiktok','user_dc'=>'privatediscord','user_signal'=>'https://signal.me/#eu/private-signal-token','user_threema'=>'ABCD1234');
$pgv_cases=0;
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{
 foreach(array('english','german')as$locale){
  $lang=array();require$phpbb_root_path.'language/lang_'.$locale.'/lang_main.php';
  foreach(array(array(false,0,1,0),array(false,1,1,1),array(true,0,1,0),array(true,0,0,0),array(true,0,1,1),array(true,1,0,1))as$case){
   $userdata=array('session_logged_in'=>$case[0],'user_level'=>$case[1],'user_lang'=>$locale,'template_name'=>'fisubsilversh');
   $profiledata['user_viewemail']=$case[2];$board_config['board_email_form']=$case[3];if(eval($contact_bindings)===false){throw new RuntimeException('Actual contact bindings did not execute');}
   $template=(new ReflectionClass('Template'))->newInstanceWithoutConstructor();$template->vars=&$template->_tpldata['.'][0];$template->load_config($phpbb_root_path.'templates/fisubsilversh',false);$template->set_filenames(array('body'=>'profile_view_body.tpl'));
   $template->assign_vars(array('L_LOCATION'=>$lang['Location'],'LOCATION'=>$location,'L_WEBSITE'=>$lang['Website'],'WWW'=>$www,'L_EMAIL_ADDRESS'=>$lang['Email_address'],'EMAIL_IMG'=>$email_img,'L_PM'=>$lang['Private_Message'],'PM_IMG'=>$pm_img,'SOCIAL_CONTACT_ROWS'=>$social['PROFILE_ROWS'],'L_ALBUM'=>'Private gallery label','GALLERY_IMG'=>'gallery-fixture','U_TOGGLE_VIEW_ALL'=>'toggle-fixture','U_ALL_IMAGES_BY_USER'=>'all-images-fixture','L_ALL_IMAGES_BY_USER'=>'all-images-label'));
   $profile_data=array(array('field_name'=>'Private custom label','field_column'=>'user_fixture_contact','field_type'=>TEXT_FIELD,'profile_location'=>1));$profiledata['user_fixture_contact']='private-custom-contact-needle';$contacts=$abouts=$profile_names=array();eval($custom);eval($switches);ob_start();try{$template->pparse('body');$html=ob_get_contents();}finally{ob_end_clean();}
   pgv_check(strpos($html,'privmsg.php?mode=post&amp;u=42')!==false,'Guest and member keep the actual private-message link');
   if(!$case[0]){
    foreach(array('private-location-needle','private-country.png','website.example.invalid','private@example.invalid','privatefacebook','private-signal-token','ABCD1234','email.gif','signal.gif','threema.gif','Private gallery label','toggle-fixture','all-images-fixture','Private custom label','private-custom-contact-needle')as$private){pgv_check(strpos($html,$private)===false,'Guest HTML omits private field, label or button: '.$private);}
    pgv_check(strpos($html,$lang['Location'].':')===false&&strpos($html,$lang['Email_address'].':')===false,'Guest template omits location/email labels, rather than blank values');
    pgv_check($location===''&&$social['PROFILE_ROWS']===''&&$email_img==='&nbsp;','Backend does not populate guest contact/location values');
   }else{
    pgv_check(strpos($html,'private-location-needle')!==false&&strpos($html,'private-country.png')!==false,'Members retain location and country flag');
    pgv_check(strpos($html,'website.example.invalid')!==false&&strpos($html,'ABCD1234')!==false,'Members retain website/messenger contact rows');
    pgv_check(strpos($html,'toggle-fixture')!==false&&strpos($html,'all-images-fixture')!==false,'Nested gallery toggle remains available for members');
    pgv_check(strpos($html,'Private custom label')!==false&&strpos($html,'private-custom-contact-needle')!==false,'Actual custom contact fields remain visible to members');
    $allowed=$case[2]||$case[1]===ADMIN;pgv_check((strpos($html,'email.gif')!==false)===$allowed,'Actual email preference and administrator exception remain effective');
    if($allowed){pgv_check(strpos($html,$case[3]?'profile.php?mode=email':'mailto:private@example.invalid')!==false,'Board form versus direct email follows actual configuration');}
   }
  }
 }
 foreach(array(true,1,'1',false,0,'0',null,'false',2,array())as$flag){$userdata=array('session_logged_in'=>$flag);pgv_check(phpbb_profile_details_visible()===in_array($flag,array(true,1,'1'),true),'Only authenticated viewer flags enable personal details');}
 // Execute the actual location expression from each public renderer.
 $surfaces=array('viewtopic.php','album_showpage.php','export.php','groupcp.php','topic_view_users.php','includes/functions_kb.php','shoutbox_max.php','staff.php','pafiledb/includes/functions_comment.php');
 $lang=array('Location'=>'Location');$i=0;$registered=true;$row=$staff=array('user_from'=>'private-location-needle');$postrow=$commentrow=array(array('user_from'=>'private-location-needle','user_id'=>42));$comments_row=$shout_row=array('user_from'=>'private-location-needle','user_id'=>42);
 foreach($surfaces as$path){$lines=preg_split('/\r?\n/',file_get_contents($phpbb_root_path.$path));$found=array();foreach($lines as$line){if(strpos($line,"['user_from']")!==false&&strpos($line,'phpbb_profile_text')!==false){$found[]=$line;}}pgv_check(count($found)===1,'Actual location renderer: '.$path);$line=trim($found[0]);
  foreach(array(false,true)as$logged){$userdata=array('session_logged_in'=>$logged);if($line[0]==="'"){$value=eval('return array('.$line.');');$value=reset($value);}else{$poster_from=$from=$user_from='';eval($line);$value=$poster_from.$from.$user_from;}pgv_check((strpos($value,'private-location-needle')!==false)===$logged,'Location visibility on '.$path);}
 }
 restore_error_handler();echo 'Profile guest visibility: ',$pgv_cases," actual-controller/Extreme-Styles/contact/location/preference cases PASS; no database or account changes.\n";
}finally{restore_error_handler();}

