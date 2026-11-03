<?php
define('IN_PHPBB',true);$app=dirname(dirname(__DIR__)).'/phpBB2/';
require$app.'includes/php_compat.php';require$app.'ctracker/classes/class_user_actions.php';
$phpEx='php';$checks=0;
function nr_check($ok,$label){if(!$ok){throw new RuntimeException($label);}$GLOBALS['checks']++;}
class NoticeReturnExit extends RuntimeException{}
function redirect($url){throw new NoticeReturnExit($url);}
$source=file_get_contents($app.'includes/page_header.php');$tails=array();
foreach(array('ipfeature'=>"if ( \$ctracker_settings['login_ip_check'] == 1 &&",'globmsg'=>"if ( \$userdata['ct_global_msg_read'] == 1 &&")as$action=>$end){
 $a=strpos($source,"if ( \$marknow == '$action'");$b=strpos($source,$end,$a);nr_check($a!==false&&$b>$a,'Actual notice handler boundary');
 $body=substr($source,$a,$b-$a);$start=strrpos($body,'redirect(');$finish=strpos($body,';',$start);
 nr_check($start!==false&&$finish!==false,'Actual shared redirect statement');$tails[$action]=substr($body,$start,$finish-$start+1);
}
$_SERVER=array('HTTPS'=>'on','SERVER_PORT'=>443,'HTTP_HOST'=>'untrusted.invalid');
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
$cases=0;
foreach(array('','/forum','/forum/')as$script_path){
 $base=$script_path===''?'/':'/forum/';
 $board_config=array('server_name'=>'www.example.invalid','server_port'=>443,'script_path'=>$script_path);
 $valid=array('viewtopic.php?t=13&start=15#p42','profile.php?mode=viewprofile&u=7','ftopic13.html','album_showpage.php?pic_id=28&full=',
  'search.php?search_keywords=Gr%C3%BC%C3%9Fe%20%26%20mehr','posting.php?mode=editpost&p=42','sub/page.php?key=a%2Fb','', '?first=1');
 $examples=array();
 foreach($valid as$path){foreach(array('https://www.example.invalid','http://example.invalid','HTTPS://WWW.EXAMPLE.INVALID.:443')as$origin){$examples[]=array($origin.$base.$path,$path);}}
 foreach(array('',null,array('invalid'),'//www.example.invalid'.$base.'viewtopic.php','javascript:alert(1)','https://evil.invalid'.$base.'viewtopic.php',
  'https://www.example.invalid.evil.invalid'.$base.'viewtopic.php','https://sub.example.invalid'.$base.'viewtopic.php',
  'https://evil@www.example.invalid'.$base.'viewtopic.php','https://www.example.invalid@evil.invalid'.$base.'viewtopic.php',
  'https://www.example.invalid:8443'.$base.'viewtopic.php',"https://www.example.invalid".$base."viewtopic.php?x=1\r\nInjected: yes",
  'https://www.example.invalid'.$base.'viewtopic.php?x=%0d%0aInjected:yes','https://www.example.invalid'.$base.'viewtopic.php?x=%00',
  'https://www.example.invalid'.$base.'viewtopic.php?x=%3BURL=https://evil.invalid/',
  'https://www.example.invalid'.$base.'../outside.php','https://www.example.invalid'.$base.'./viewtopic.php',
  'https://www.example.invalid'.$base.'%2e%2e/outside.php','https://www.example.invalid'.$base.'%252e%252e/outside.php',
  'https://www.example.invalid'.$base.'%25252e%25252e/outside.php','https://www.example.invalid'.$base.'%2foutside.php',
  'https://www.example.invalid'.$base.'%5coutside.php','https://www.example.invalid'.$base.'sub//page.php',
  'https://www.example.invalid'.$base.'//evil.invalid','https://www.example.invalid'.$base.'viewtopic.php%3fx=1',
  'https://www.example.invalid'.$base.'viewtopic.php%23anchor','https://www.example.invalid'.$base.'%68ttp:evil',
  ' https://www.example.invalid'.$base.'viewtopic.php','https://www.example.invalid'.$base.'with space.php')as$bad){$examples[]=array($bad,'portal.php');}
 if($script_path!==''){$examples[]=array('https://www.example.invalid/other/viewtopic.php?t=13','portal.php');$examples[]=array('https://www.example.invalid/forumish/viewtopic.php?t=13','portal.php');}
 foreach($examples as$example){
  nr_check(ctracker_user_return_page($example[0])===$example[1],'Validated local return with intact query/fragment or fixed fallback');
  foreach($tails as$action=>$tail){
   $HTTP_SERVER_VARS=array('HTTP_REFERER'=>$example[0]);$destination=null;
   try{eval($tail);}catch(NoticeReturnExit$error){$destination=$error->getMessage();}
   nr_check($destination===$example[1],'Both actual notice handlers use the validated return path without warnings');
  }
  $absolute=phpbb_board_url($example[1]);nr_check(parse_url($absolute,PHP_URL_HOST)==='www.example.invalid','Request Host cannot supply a redirect origin');$cases++;
 }
}
$board_config=array('server_name'=>'example.invalid','server_port'=>8080,'script_path'=>'/forum');
nr_check(ctracker_user_return_page('https://example.invalid:8080/forum/viewtopic.php?t=13')==='viewtopic.php?t=13','Configured nonstandard port works');
nr_check(ctracker_user_return_page('https://example.invalid/forum/viewtopic.php?t=13')==='portal.php','Foreign standard port rejected for a custom-port board');
$board_config=array('server_name'=>'[2001:db8::1]','server_port'=>443,'script_path'=>'/forum');
nr_check(ctracker_user_return_page('https://[2001:db8::1]/forum/viewtopic.php?t=13')==='viewtopic.php?t=13','Configured IPv6 board works');
$phpEx='phtml';nr_check(ctracker_user_return_page(null)==='portal.phtml','Fallback respects the configured extension');
restore_error_handler();echo 'CrackerTracker notice returns: ',$cases,' URL cases, ',$checks," actual-handler/helper assertions PASS; queries, aliases, subdirectories and safe fallback.\n";
