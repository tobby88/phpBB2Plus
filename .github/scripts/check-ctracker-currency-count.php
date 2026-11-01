<?php
// Actual posting handler and language files, synthetic input only; no DB/HTTP.
define('IN_PHPBB',true);define('GENERAL_MESSAGE',0);
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
$root=dirname(dirname(__DIR__)).'/phpBB2/';$phpEx='php';require$root.'ctracker/classes/class_ct_userfunctions.php';
class CtCurrencyRejected extends RuntimeException{}
class CtCurrencyNoDatabase{function __call($method,$arguments){throw new RuntimeException('Unexpected database operation');}}
function message_die($type,$message){throw new CtCurrencyRejected($message);}
function cm_check($ok,$message){if(!$ok){throw new RuntimeException($message);}$GLOBALS['checks']++;}
function cm_run($message,$subject,$expected,$policy=array()){
 global$HTTP_POST_VARS,$userdata,$ctracker_config,$lang,$db;
 $HTTP_POST_VARS=array('message'=>$message,'subject'=>$subject);$_POST=$HTTP_POST_VARS;$userdata=array('user_id'=>2,'user_level'=>0,'user_posts'=>0);$ctracker_config=new stdClass();$ctracker_config->settings=array('spammer_blockmode'=>0,'spam_attack_boost'=>1,'spam_keyword_det'=>0);
 foreach($policy as$key=>$value){if(array_key_exists($key,$userdata)){$userdata[$key]=$value;}else{$ctracker_config->settings[$key]=$value;}}
 $db=new CtCurrencyNoDatabase();$before=serialize(array($HTTP_POST_VARS,$_POST,$userdata,$ctracker_config));
 for($repeat=0;$repeat<2;$repeat++){$rejected=false;try{(new ct_userfunctions())->handle_postings();}catch(CtCurrencyRejected$error){cm_check($error->getMessage()===$lang['ctracker_info_post_spammer'],'Correct localized currency rejection');$rejected=true;}cm_check($rejected===$expected,'Actual currency threshold/policy: '.(is_string($message)?substr($message,0,100):'non-scalar'));cm_check(serialize(array($HTTP_POST_VARS,$_POST,$userdata,$ctracker_config))===$before,'Currency comparison preserves input, account and policy');}
}
$checks=0;
foreach(array('english','german')as$locale){
 $lang=array();require$root.'language/lang_'.$locale.'/lang_cback_ctracker.php';
 $forms=array('$ 10','€ 10','USD 10','EUR 10','10USD','EUR10','usd10','10eur','Usd10','us$10','US$ 10','USD$10','$USD 10','$US10','USD $ USD 10','U.S.$10','$U.S.10','EUR€ 10','€EUR10','EUR € EUR 10','US dollars 10','US-Dollar 10','USdollar10','U.S. dollars 10',"USD\xc2\xa0$ 10","EUR\xe2\x80\xaf€ 10","$\xe2\x80\x8bUSD 10","USD\xe2\x80\x8b$ 10");
 foreach($forms as$form){foreach(array(1,4,6,7)as$count){cm_run(implode(' ',array_fill(0,$count,$form)),'ordinary',$count>6);}}
 foreach(array('USB','USA','US','U.S.','business','PLUS','SURPLUS','SUN','USability','USDance','EUROPA','EURidice','prices_USD','USD_variable','öUSD','USDä',"USD\xcc\x81",'漢USD','USD_','_USD','Grüße café Кириллица 漢字 😀')as$word){cm_run(implode(' ',array_fill(0,10,$word)),'ordinary',false);}
 foreach(array('EUR $','USD €','USD$EUR','EUR€USD',"USD\n$","USD\xe2\x80\xa8$")as$two){cm_run(implode('; ',array_fill(0,3,$two)),'ordinary',false);cm_run(implode('; ',array_fill(0,4,$two)),'ordinary',true);}
 foreach(array(0,1,3,6,7)as$count){cm_run(implode(' ',array_fill(0,$count,'USD 10')),implode(' ',array_fill(0,7-$count,'EUR 10')),true);}
 cm_run(implode(' ',array_fill(0,3,'$ 10')),implode(' ',array_fill(0,3,'€ 10')),false);
 cm_run(implode(' ',array_fill(0,5,'USD 10')).' USD','$',true);
 cm_run('$',implode(' ',array_fill(0,6,'USD 10')),true);
 cm_run(implode(' ',array_fill(0,6,'https://www.example.org/path')),implode(' ',array_fill(0,6,'USD 10')),false);
 cm_run(implode(' ',array_fill(0,7,'https://www.example.org/path')),implode(' ',array_fill(0,6,'USD 10')),true);
 cm_run(implode(' ',array_fill(0,6,'https://www.example.org/path')),implode(' ',array_fill(0,7,'USD 10')),true);
 cm_run(str_repeat('x',250000).' '.implode(' ',array_fill(0,6,'US$10')),'ordinary',false);
 cm_run(str_repeat(' ',250000).' '.implode(' ',array_fill(0,7,'USD')),'ordinary',true);
 foreach(array(array('user_level'=>1),array('user_level'=>2),array('user_posts'=>2),array('spam_attack_boost'=>0))as$policy){cm_run(implode(' ',array_fill(0,7,'US$10')),'ordinary',false,$policy);}
 cm_run(array('USD'),'ordinary',false);cm_run('ordinary '.chr(255),'ordinary',true);
 $old=ini_get('pcre.backtrack_limit');try{ini_set('pcre.backtrack_limit','0');cm_run('$ 10','ordinary',true);}finally{ini_set('pcre.backtrack_limit',$old);}
}
echo 'CrackerTracker currency count: ',$checks," actual EN/DE compound/code/word-boundary/six-seven/Unicode/field/policy/resource/input-preservation cases PASS; no database or network.\n";
