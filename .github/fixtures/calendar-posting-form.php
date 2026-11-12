<?php
if(PHP_SAPI!=='cli'){exit(2);}
error_reporting(E_ALL);
if(!defined('IN_PHPBB')){define('IN_PHPBB',true);}
$cp_root=dirname(dirname(__DIR__)).'/phpBB2/';
require_once $cp_root.'includes/functions_calendar_posting.php';
$cp_source=file_get_contents($cp_root.'posting.php');
function cp_between($source,$first,$last){$a=strpos($source,$first);$b=$a===false?false:strpos($source,$last,$a);if($a===false||$b===false){throw new RuntimeException('Actual calendar posting branch missing');}return substr($source,$a,$b-$a);}
$cp_parse=cp_between($cp_source,'$calendar_input = calendar_post_input($_POST);','//-- fin mod : calendar');
$cp_resolve=cp_between($cp_source,'// Resolve calendar fields after loading the saved event',"//\n// Set toggles for various options");
$cp_form=cp_between($cp_source,"\t\t// get the date\n",'//-- fin mod : calendar');
$cp_form=preg_replace('/\n\t\}\n\}\n$/D','',$cp_form);
if(preg_match('/if \(!empty\(\$topic_calendar_time\)\)\s*\{\s*\$topic_calendar_duration_preview.*?\n\t\t\}/s',$cp_source,$match)!==1){throw new RuntimeException('Actual calendar preview branch missing');}$cp_preview=$match[0];
if(preg_match('/submit_post\(\$mode,[^\n]+\);/',$cp_source,$match)!==1){throw new RuntimeException('Actual calendar submit call missing');}$cp_call=$match[0];
function submit_post(){ $GLOBALS['cp_arguments']=func_get_args(); }
function append_sid($url,$unused=false){return $url;}
function get_calendar_title($time,$duration){$GLOBALS['cp_preview_values']=array($time,$duration);return 'Fixture date';}
function cp_check($ok,$message){if(!$ok){throw new RuntimeException($message);}$GLOBALS['cp_checks']++;}
function cp_run($input,$saved=array(),$authorized=true,$mode='newtopic',$existing_error=''){
    global $cp_parse,$cp_resolve,$cp_form,$cp_preview,$cp_call,$lang;
    $_POST=$input;$post_data=array_merge(array('first_post'=>true),$saved);$is_auth=array('auth_cal'=>$authorized);$submit=true;$refresh=false;$error_msg=$existing_error;$months=range(0,12);
    eval($cp_parse);eval($cp_resolve);$values=array($topic_calendar_time,$topic_calendar_duration);$preview_subject='';$GLOBALS['cp_preview_values']=null;eval($cp_preview);
    $return_message=$return_meta=$username=$subject=$message=$poll_title=$topic_desc='';$forum_id=$topic_id=$post_id=$poll_id=$topic_type=$bbcode_on=$html_on=$smilies_on=$attach_sig=$poll_length=$topic_announce_duration=$post_icon=$news_category=0;$bbcode_uid='';$poll_options=array();
    $GLOBALS['cp_arguments']=null;if($error_msg===''){eval($cp_call);}
    eval($cp_form);
    return array('time'=>$values[0],'duration'=>$values[1],'error'=>$error_msg,'input'=>$calendar_input,'storage'=>$GLOBALS['cp_arguments']===null?null:array($GLOBALS['cp_arguments'][23],$GLOBALS['cp_arguments'][24]),'preview'=>$GLOBALS['cp_preview_values'],'fields'=>array($s_topic_calendar_year,$s_topic_calendar_month,$s_topic_calendar_day,$topic_calendar_hour,$topic_calendar_min,$topic_calendar_duration_day,$topic_calendar_duration_hour,$topic_calendar_duration_min));
}
function cp_post($date=array(),$duration=array(0,1,0)){
    $date=array_replace(array(2026,11,3,10,0),$date);$out=array();foreach(array('year','month','day','hour','min')as$i=>$key){$out['topic_calendar_'.$key]=(string)$date[$i];}foreach(array('day','hour','min')as$i=>$key){$out['topic_calendar_duration_'.$key]=(string)$duration[$i];}return$out;
}
function cp_render_form($result,$locale){
    global $cp_root,$phpbb_root_path,$phpEx,$board_config,$theme,$lang;
    require_once $cp_root.'includes/php_compat.php';require_once $cp_root.'includes/template.php';
    if(!function_exists('phpbb_realpath')){if(preg_match('/^function phpbb_realpath\(.*?^\}/ms',file_get_contents($cp_root.'includes/functions.php'),$match)!==1){throw new RuntimeException('Actual template path helper missing');}eval($match[0]);}
    $phpbb_root_path=$cp_root;$phpEx='php';$board_config=array('default_lang'=>$locale,'xs_use_cache'=>0,'xs_auto_compile'=>0,'xs_auto_recompile'=>0,'version'=>'.0.23');$theme=array('template_name'=>'fisubsilversh','td_class1'=>'row1','td_class2'=>'row2');
    $out=(new ReflectionClass('Template'))->newInstanceWithoutConstructor();$out->vars=&$out->_tpldata['.'][0];$out->load_config($cp_root.'templates/fisubsilversh',false);$out->set_filenames(array('body'=>'posting_body.tpl'));
    $out->assign_block_vars('switch_type_cal',array());$vars=array();foreach(array('S_CALENDAR_YEAR','S_CALENDAR_MONTH','S_CALENDAR_DAY','CALENDAR_HOUR','CALENDAR_MIN','CALENDAR_DURATION_DAY','CALENDAR_DURATION_HOUR','CALENDAR_DURATION_MIN')as$i=>$key){$vars[$key]=$result['fields'][$i];}$out->assign_vars($vars);
    ob_start();try{$out->pparse('body');}finally{$html=ob_get_clean();}return$html;
}
$cp_checks=0;$cp_cases=0;
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{
foreach(array('english','german')as$locale){$lang=array();$lang_extend_admin=false;require$cp_root.'language/lang_'.$locale.'/lang_main.php';
    foreach(array('UTC','Europe/Berlin','Australia/Lord_Howe')as$zone){date_default_timezone_set($zone);
        foreach(array(array(0,1,0,3600),array(1,2,3,93780),array(0,24,0,86400),array(0,0,0,0),array(24855,0,0,2147472000))as$d){
            $input=cp_post(array(),array_slice($d,0,3));$result=cp_run($input);$stamp=(new DateTimeImmutable('2026-11-03 10:00:00',new DateTimeZone($zone)))->getTimestamp();
            cp_check($result['error']===''&&$result['storage']===array($stamp,max(0,$d[3]-1)),'Actual submit call must convert exclusive duration exactly once');
            cp_check($result['preview']===array($stamp,max(0,$d[3]-1)),'Actual preview and final storage duration must agree');
            $retry=cp_run($input,array(),true,'newtopic','Unrelated message error');
            cp_check($retry['duration']===$d[3]&&$retry['fields']===$result['fields']&&$retry['storage']===null,'Form errors must preserve complete duration controls');$cp_cases++;
        }
        foreach(array(array(2026,2,31,10,0),array(2026,2,29,10,0),array(2026,4,31,10,0),array(2026,13,1,10,0),array(2026,11,3,24,0),array(2026,11,3,10,60),array(2039,1,1,10,0))as$date){$result=cp_run(cp_post($date));cp_check($result['error']!==''&&$result['storage']===null&&$result['preview']===null,'Invalid original date/time must not normalize into another saved date: '.json_encode(array($date,$result['error'],$result['storage'],$result['preview'])));$cp_cases++;}
        foreach(array('topic_calendar_year','topic_calendar_hour','topic_calendar_duration_min')as$key){foreach(array('-1','1e2','abc',array('1'),'999999999999999999999')as$bad){$input=cp_post();$input[$key]=$bad;$result=cp_run($input);cp_check($result['error']!==''&&$result['storage']===null,'Malformed scalar/array date or duration cannot publish');$cp_cases++;}}
        foreach(array(array(2024,2,29,10,0),array(1971,1,1,10,0))as$date){$result=cp_run(cp_post($date));cp_check($result['error']===''&&$result['storage']!==null,'Valid leap/historical dates must remain accepted');$cp_cases++;}
        $result=cp_run(cp_post(array(2026,11,3,0,0),array(2,3,4)));cp_check($result['duration']===172800&&$result['storage'][1]===172799,'All-day input preserves existing whole-day duration policy');$cp_cases++;
        $input=cp_post();$input['topic_calendar_year']='0';$result=cp_run($input);cp_check($result['storage']===array(0,0)&&$result['error']==='','Existing clear-date control must still remove the event');$cp_cases++;
        $saved=array('topic_calendar_time'=>1793696400,'topic_calendar_duration'=>3600);
        foreach(array(array(),array('topic_calendar_year'=>array('bad')))as$input){$authorized=$input===array();$result=cp_run($input,$saved,$authorized,'editpost');cp_check($result['error']===''&&$result['storage']===array(1793696400,3599),'Missing/unauthorized controls preserve saved event metadata');$cp_cases++;}
        $result=cp_run(cp_post(),$saved,true,'reply');cp_check($result['storage']===array(0,0)&&$result['preview']===null,'Reply controls cannot change or preview first-topic metadata');$cp_cases++;
        foreach(array(array(24855,3,15),array(99999999,0,0))as$d){$result=cp_run(cp_post(array(),$d));cp_check($result['error']!==''&&$result['storage']===null,'Oversized duration must fail before overflow or database clipping');$cp_cases++;}
    }
    date_default_timezone_set('Europe/Berlin');
    foreach(array(1792888200,1792891800)as$stamp){ // 2026-10-25 02:30 CEST/CET
        $input=cp_post(array(2026,10,25,2,30));$result=cp_run($input,array('topic_calendar_time'=>$stamp,'topic_calendar_duration'=>3600),true,'editpost');
        cp_check($result['storage']===array($stamp,3599),'Unchanged repeated hour must retain its saved occurrence');$cp_cases++;
    }
    $result=cp_run(cp_post(array(2026,3,29,2,30)));cp_check($result['error']!==''&&$result['storage']===null,'Nonexistent spring hour must not move the event silently');$cp_cases++;
    date_default_timezone_set('UTC');foreach(array(array(3,14,true),array(3,15,false))as$edge){$result=cp_run(cp_post(array(2038,1,19,$edge[0],$edge[1])));cp_check(($result['storage']!==null)===$edge[2],'Native and stored timestamp boundary must agree');$cp_cases++;}
}
foreach(array(array(-1,0),array(2147483648.0,0),array(1,2147483648.0),array(0,1),array('1e2',0),array(array(1),0))as$bad){cp_check(calendar_post_stored_values($bad[0],$bad[1])===null,'Storage helper rejects malformed or unrepresentable integral values');$cp_cases++;}
foreach(array(array(0,0),array(1234567890,3599),array('2147483647','2147483647'))as$valid){cp_check(calendar_post_stored_values($valid[0],$valid[1])===array('time'=>(int)$valid[0],'duration'=>(int)$valid[1]),'Canonical stored values must remain exact');$cp_cases++;}
foreach(array('english','german')as$locale){$lang=array();require$cp_root.'language/lang_'.$locale.'/lang_main.php';date_default_timezone_set('UTC');
    foreach(array(array(),array(2026,2,31,10,0),array(1971,1,1,10,0),array(2038,1,18,10,0))as$date){
        $result=cp_run(cp_post($date),array(),true,'newtopic','Unrelated message error');$html=cp_render_form($result,$locale);$post=array();
        preg_match_all('/<input\b[^>]*name="(topic_calendar_[^"]+)"[^>]*value="([^"]*)"/s',$html,$inputs,PREG_SET_ORDER);foreach($inputs as$input){$post[$input[1]]=html_entity_decode($input[2],ENT_QUOTES,'UTF-8');}
        preg_match_all('/<select\b[^>]*name="(topic_calendar_[^"]+)"[^>]*>(.*?)<\/select>/s',$html,$selects,PREG_SET_ORDER);foreach($selects as$select){cp_check(preg_match('/<option\b[^>]*value="([^"]*)"[^>]*selected="selected"/s',$select[2],$option)===1,'Actual template must retain a selected date component');$post[$select[1]]=$option[1];}
        cp_check(count($post)===8&&preg_match('//u',$html)===1,'Actual Extreme Styles form must contain all eight valid UTF-8 controls');
        cp_check($post['topic_calendar_duration_hour']==='1'&&(int)$post['topic_calendar_duration_min']===0,'Actual retry form must show one complete hour');
        $retry=cp_run($post);cp_check($retry['duration']===$result['duration']&&$retry['time']===$result['time']&&($retry['error']!=='')===($result['input']['error']!==''),'Submitting the actual rendered form preserves event fields or the invalid date error');$cp_cases++;
    }
}
}finally{restore_error_handler();}
echo 'Calendar posting forms: ',$cp_cases,' actual-parser/authority/form/preview/submit-call cases, ',$cp_checks," assertions PASS; EN/DE, invalid dates, durations, retries, native ranges and DST occurrences.\n";
