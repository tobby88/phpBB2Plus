<?php
// Actual posting handler, only synthetic input; no database, network or files.
define('IN_PHPBB',true);define('GENERAL_MESSAGE',0);
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
$root=dirname(dirname(__DIR__)).'/phpBB2/';$phpEx='php';require$root.'ctracker/classes/class_ct_userfunctions.php';
class CtUrlRejected extends RuntimeException{}
class CtUrlNoDatabase{function __call($method,$arguments){throw new RuntimeException('Unexpected database operation');}}
function message_die($level,$message){throw new CtUrlRejected($message);}
function cu_check($ok,$message){if(!$ok){throw new RuntimeException($message);}$GLOBALS['checks']++;}
function cu_run($message,$subject,$rejected,$policy=array()){
 global$HTTP_POST_VARS,$userdata,$ctracker_config,$lang,$db;
 $HTTP_POST_VARS=array('message'=>$message,'subject'=>$subject);$_POST=$HTTP_POST_VARS;
 $userdata=array('user_id'=>2,'user_level'=>0,'user_posts'=>0);
 $ctracker_config=new stdClass();$ctracker_config->settings=array('spammer_blockmode'=>0,'spam_attack_boost'=>1,'spam_keyword_det'=>0);
 foreach($policy as$key=>$value){if(array_key_exists($key,$userdata)){$userdata[$key]=$value;}else{$ctracker_config->settings[$key]=$value;}}
 $db=new CtUrlNoDatabase();$before=serialize(array($HTTP_POST_VARS,$_POST,$userdata,$ctracker_config));
 for($repeat=0;$repeat<2;$repeat++){$blocked=false;try{(new ct_userfunctions())->handle_postings();}catch(CtUrlRejected$error){cu_check($error->getMessage()===$lang['ctracker_info_post_spammer'],'Correct localized URL rejection');$blocked=true;}
  cu_check($blocked===$rejected,'Actual URL threshold/policy: '.(is_string($message)?substr($message,0,100):'non-scalar'));cu_check(serialize(array($HTTP_POST_VARS,$_POST,$userdata,$ctracker_config))===$before,'URL scan preserves original bytes/account/policy');}
}
$checks=0;
foreach(array('english','german')as$locale){
 $lang=array();require$root.'language/lang_'.$locale.'/lang_cback_ctracker.php';
 $forms=array('https://www.example.org/path','HTTP://WWW.example.org/path','http://example.org/path','https://example.org/?next=https://www.other.example/path','www.example.org/path','ftp://ftp.example.org/file','ftps://ftp.example.org/file','ftp.example.org/file','https://[2001:db8::1]/path','https://www.example.org/Grüße😀',
  '[url=https://www.example.org/path]Link[/url]','[URL=https://www.example.org/path]https://www.example.org/path[/URL]','[url=https://www.example.org/path]www.example.org/path[/url]','[url=https://www.example.org/path]Grüße [b]https://www.example.org/path[/b][/url]','[url=www.example.org/path]www.example.org/path[/url]','[url=ftp://ftp.example.org/file]ftp://ftp.example.org/file[/url]','[url=https://www.example.org/Grüße😀]Link[/url]',
  '[url]https://www.example.org/path[/url]','[img]https://www.example.org/pic.jpg[/img]','[code]https://www.example.org/path[/code]','[quote]https://www.example.org/path[/quote]',
  '[url=https://[2001:db8::1]/path]https://[2001:db8::1]/path[/url]','[url=https://www.example.org/Grüße😀]https://www.example.org/Grüße😀[/url]','[url=https://bücher.example/Grüße]https://bücher.example/Grüße[/url]','[url=www.bücher.example/path]www.bücher.example/path[/url]',
  '[url=ftp://user:example@ftp.example.org/file]ftp://user:example@ftp.example.org/file[/url]','[url=https://user:example@www.example.org/path]https://user:example@www.example.org/path[/url]');
 foreach($forms as$form){foreach(array(1,4,6,7)as$number){cu_run(implode("\n",array_fill(0,$number,$form)),'ordinary',$number>6);}}
 foreach(array(0,1,3,6,7)as$number){cu_run(implode("\n",array_fill(0,$number,'https://www.example.org/path')),implode("\n",array_fill(0,7-$number,'https://example.org/other')),true);}
 cu_run(implode("\n",array_fill(0,3,'https://www.example.org/path')),implode("\n",array_fill(0,3,'www.example.org/other')),false);
 $seven=implode(' ',array_fill(0,7,'https://www.example.org/path'));
 foreach(array('[url=https://target.example/]'.$seven.'[/url]','[url=https://www.example.org/path]'.$seven.'[/url]','[url=https://target.example/][url]'.$seven.'[/url][/url]','[url=bad target '.$seven.']label[/url]','[url=https://target.example/]'.$seven,'[url]'.$seven,'[url=https://target.example/]<a href="https://www.example.org/path">'.$seven.'</a>[/url]')as$bad){cu_run($bad,'ordinary',true);}
 cu_run('[url=https://target.example/]https://other.example/[/url] '.implode(' ',array_fill(0,5,'https://example.org/third')),'ordinary',true);
 cu_run('[url=https://target.example/]target[/url] '.implode(' ',array_fill(0,5,'https://example.org/third')),'ordinary',false);
 cu_run('Grüße café 😀 und ganz normaler Text','ordinary',false);
 cu_run(str_repeat('x',260000).' https://www.example.org/path','ordinary',false);
 cu_run(str_repeat('[url]',40000).' '.$seven,'ordinary',true);
 foreach(array(array('user_level'=>1),array('user_level'=>2),array('user_posts'=>2),array('spam_attack_boost'=>0))as$exemption){cu_run($seven,'ordinary',false,$exemption);}
 // A comparison copy must not rewrite malformed transport values either.
 cu_run(array('https://example.org/'),'ordinary',false);
 $old=ini_get('pcre.backtrack_limit');try{ini_set('pcre.backtrack_limit','0');cu_run('https://www.example.org/path','ordinary',true);}finally{ini_set('pcre.backtrack_limit',$old);}
}
echo 'CrackerTracker URL count: ',$checks," actual EN/DE boundary/BBCode/label/nesting/malformed/IPv6/Unicode/policy/resource-failure/input-preservation cases PASS; no database or network.\n";
