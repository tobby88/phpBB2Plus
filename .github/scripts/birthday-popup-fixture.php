<?php
if(PHP_SAPI!=='cli'){exit(2);}date_default_timezone_set('UTC');define('IN_PHPBB',true);
error_reporting(E_ALL);set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
$bp_root=dirname(dirname(__DIR__)).'/phpBB2/';$phpbb_root_path=$bp_root;$phpEx='php';$table_prefix='fixture_';
require $bp_root.'includes/php_compat.php';require $bp_root.'includes/constants.php';require $bp_root.'includes/template.php';
$bp_cases=$bp_checks=0;
function bp_check($value,$message){if(!$value){throw new RuntimeException($message);}$GLOBALS['bp_checks']++;}
function bp_time(){return $GLOBALS['bp_now'];}
function bp_date($format,$timestamp=null){return date($format,$timestamp===null?bp_time():$timestamp);}
function bp_freeze($source){
    $out='';foreach(token_get_all('<?php '.$source)as$token){
        if(is_array($token)){if($token[0]===T_OPEN_TAG){continue;}$out.=$token[0]===T_STRING&&in_array(strtolower($token[1]),array('time','date'),true)?'bp_'.strtolower($token[1]):$token[1];}
        else{$out.=$token;}
    }return $out;
}
$bp_functions=file_get_contents($bp_root.'includes/functions.php');
foreach(array('create_date','mkrealdate','realdate','phpbb_realpath')as$fn){
    if(function_exists($fn)){continue;}bp_check(preg_match('/^function '.$fn.'\(.*?^\}/ms',$bp_functions,$m)===1,'Actual date/path function '.$fn);eval(bp_freeze($m[0]));
}
$bp_sessions=file_get_contents($bp_root.'includes/sessions.php');bp_check(preg_match('/^function append_sid\(.*?^\}/ms',$bp_sessions,$m)===1,'Actual template URL helper');eval($m[0]);$SID='sid=birthday_fixture';
$bp_source=file_get_contents($bp_root.'birthday_popup.php');$bp_start=strpos($bp_source,'$userdata = session_pagestart');
bp_check($bp_start!==false,'Actual controller starts with session resolution');$bp_controller=substr($bp_source,$bp_start);
$bp_controller=preg_replace_callback('/^include\(\$phpbb_root_path\s*\.\s*\'includes\/page_(header|tail)\.\'\.\$phpEx\);\s*$/m',function($m){return $m[1]==='header'?'bp_header($gen_simple_header,$page_title);':'bp_tail();';},$bp_controller,-1,$bp_hooks);
bp_check($bp_hooks===2&&strpos($bp_controller,'include(')===false,'Only standard header/tail integration is hooked; actual session/guards/calculation/template run');$bp_controller=bp_freeze($bp_controller);
class BirthdayPopupDenied extends RuntimeException {}
function message_die($level,$message){bp_check($level===GENERAL_MESSAGE,'Expected authorization rejection');throw new BirthdayPopupDenied($message);}
function session_pagestart($ip,$page){bp_check($ip==='127.0.0.1'&&$page===PAGE_INDEX,'Actual session call remains scoped to current viewer');return $GLOBALS['bp_actor'];}
function init_userprefs($row){bp_check($row===$GLOBALS['bp_actor'],'Controller resolves preferences for its own current account');}
function bp_header($simple,$title){bp_check($simple===true&&$title===$GLOBALS['lang']['Greeting_Messaging'],'Simple popup header suppresses regular annual-greeting side effects');$GLOBALS['bp_headers']++;}
function bp_tail(){$GLOBALS['bp_tails']++;}
function bp_locale($locale){global $lang,$phpEx;$lang=array();require $GLOBALS['bp_root'].'language/lang_'.$locale.'/lang_main.php';$GLOBALS['bp_locale']=$locale;}
function bp_run($actor,$clock,$offset=0,$server='UTC'){
    global $board_config,$phpbb_root_path,$phpEx,$userdata,$template,$lang,$theme;
    date_default_timezone_set($server);$GLOBALS['bp_now']=strtotime($clock.' UTC');$GLOBALS['bp_actor']=$actor;$GLOBALS['bp_headers']=$GLOBALS['bp_tails']=0;
    $phpbb_root_path=$GLOBALS['bp_root'];$phpEx='php';$user_ip='127.0.0.1';$board_config=array('default_lang'=>$GLOBALS['bp_locale'],'board_timezone'=>$offset,'xs_use_cache'=>0,'xs_auto_compile'=>0,'xs_auto_recompile'=>0,'version'=>'.0.23');
    $theme=array('template_name'=>'fisubsilversh');$template=(new ReflectionClass('Template'))->newInstanceWithoutConstructor();$template->vars=&$template->_tpldata['.'][0];$template->load_config($phpbb_root_path.'templates/fisubsilversh',false);
    $before=serialize($actor);$error=null;ob_start();try{eval($GLOBALS['bp_controller']);}catch(BirthdayPopupDenied$e){$error=$e->getMessage();}finally{$html=ob_get_clean();}
    bp_check(serialize($userdata)===$before,'Popup leaves birthday, preferences and annual-greeting counter untouched');
    return array('html'=>$html,'error'=>$error,'vars'=>$template->vars,'headers'=>$GLOBALS['bp_headers'],'tails'=>$GLOBALS['bp_tails'], 'dates'=>array(isset($birthdate)?$birthdate:null,isset($date_today)?$date_today:null));
}
function bp_vectors(){return array(
    array('19891103','2026-11-03 12:00:00',0,'UTC',37,'20261103'),
    array('19401103','2026-11-03 12:00:00',0,'UTC',86,'20261103'),
    array('19700101','2026-01-01 12:00:00',0,'UTC',56,'20260101'),
    array('19010101','2026-01-01 12:00:00',0,'UTC',125,'20260101'),
    array('19920229','2024-02-29 12:00:00',0,'UTC',32,'20240229'),
    array('19920229','2025-03-01 12:00:00',0,'UTC',33,'20250301'),
    array('19920229','2025-02-28 12:00:00',0,'UTC',32,'20240229'),
    array('19920229','2024-02-28 12:00:00',0,'UTC',31,'20230301'),
    array('19891231','2027-01-01 12:00:00',0,'UTC',37,'20261231'),
    array('19890101','2026-12-31 23:30:00',2,'UTC',38,'20270101'),
    array('19891231','2027-01-01 01:00:00',-5,'UTC',37,'20261231'),
    array('19890101','2027-01-01 00:00:00',-5,'UTC',37,'20260101'),
    array('19890101','2026-12-31 18:45:00',5.5,'UTC',38,'20270101'),
    array('19891231','2027-01-01 00:00:00',-3.5,'UTC',37,'20261231'),
    array('19890706','2026-07-05 22:45:00',1,'Europe/Berlin',37,'20260706'),
    array('19890101','2027-01-01 01:00:00',0,'America/New_York',38,'20270101'),
    array('19890701','2026-06-30 23:30:00',0,'America/New_York',37,'20260701'),
    array('19890701','2026-07-02 12:00:00',0,'UTC',37,'20260701'),
    array('19400229','2025-03-01 12:00:00',0,'UTC',85,'20250301'),
    array('20261103','2026-11-03 12:00:00',0,'UTC',0,'20261103'),
    array('19891103','2026-11-02 23:59:59',0,'UTC',36,'20251103'),
    array('19891103','2026-11-03 00:00:00',0,'UTC',37,'20261103'),
    array('19890101','2026-12-31 18:20:00',5.75,'UTC',38,'20270101'));
}
function bp_days($ymd){return (int)mkrealdate((int)substr($ymd,6,2),(int)substr($ymd,4,2),(int)substr($ymd,0,4));}
function bp_actor($days){return array('user_id'=>7,'session_logged_in'=>true,'user_birthday'=>$days,'user_next_birthday_greeting'=>2035,'template_name'=>'fisubsilversh');}
function bp_verify($result,$vector){
    global $lang,$bp_cases;$local=new DateTimeImmutable('@'.($GLOBALS['bp_now']+(int)(3600*($vector[2]+(int)date('I',$GLOBALS['bp_now'])))));
    $today=$local->format('Ymd');$occurrence=DateTimeImmutable::createFromFormat('!Ymd',$vector[5],new DateTimeZone('UTC'));
    $date=strtr($occurrence->format(str_replace('Y','',$lang['DATE_FORMAT'])),$lang['datetime']);
    $expected=$vector[5]===$today?sprintf($lang['Birthday_greeting_today'],$vector[4]):sprintf($lang['Birthday_greeting_prev'],$vector[4],$date);
    bp_check($result['error']===null&&$result['headers']===1&&$result['tails']===1,'Valid canonical stored day renders one complete popup: '.json_encode(array($vector,$GLOBALS['bp_locale'],$result['error'],$result['headers'],$result['tails'])));
    bp_check($result['vars']['L_MESSAGE']===$expected,'Actual localized message has the correct most-recent age/day');
    bp_check(strpos($result['html'],$expected)!==false&&strpos($result['html'],$lang['Close_window'])!==false,'Shipped Extreme Styles template renders greeting and close link');
    bp_check(strpos($result['html'],'{L_MESSAGE}')===false&&preg_match('//u',$result['html'])===1,'Localized popup has valid UTF-8 and no unresolved message slot');$bp_cases++;
}
function bp_rejected($actor,$clock='2026-11-03 12:00:00'){
    $result=bp_run($actor,$clock);bp_check($result['error']===$GLOBALS['lang']['Not_Authorised']&&$result['html']===''&&$result['headers']===0&&$result['tails']===0,'Guest/invalid/future birthdate is denied before popup output: '.json_encode(array($actor,$result['error'],$result['headers'],$result['tails'],$result['dates'])));$GLOBALS['bp_cases']++;
}
