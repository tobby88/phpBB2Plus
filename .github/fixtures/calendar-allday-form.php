<?php
// Continue the existing actual-controller/Extreme Styles form fixture.
$ad_form_cases=0;$ad_checks_before=$cp_checks;
function ad_form_values($html){
    $post=array();preg_match_all('/<input\b[^>]*name="(topic_calendar_[^"]+)"[^>]*value="([^"]*)"/s',$html,$inputs,PREG_SET_ORDER);foreach($inputs as$field){$post[$field[1]]=html_entity_decode($field[2],ENT_QUOTES,'UTF-8');}
    preg_match_all('/<select\b[^>]*name="(topic_calendar_[^"]+)"[^>]*>(.*?)<\/select>/s',$html,$selects,PREG_SET_ORDER);foreach($selects as$field){cp_check(preg_match('/<option\b[^>]*value="([^"]*)"[^>]*selected="selected"/s',$field[2],$selected)===1,'Civil-day form retains every selected date');$post[$field[1]]=$selected[1];}
    cp_check(count($post)===8,'Civil-day form retains all eight controls');return$post;
}
function ad_noon($clock,$days){
    $noon=$clock->setTime(12,0,0);$target=$noon->setDate((int)$noon->format('Y'),(int)$noon->format('n'),(int)$noon->format('j')+$days);
    // setDate can retain a nonexistent cached civil date across Apia's skipped
    // date. Round-trip the instant before taking this independent reference.
    return (new DateTimeImmutable('@'.$target->getTimestamp()))->setTimezone($clock->getTimezone());
}
function ad_end($clock,$days){return new DateTimeImmutable(ad_noon($clock,$days)->format('Y-m-d').' 00:00:00',$clock->getTimezone());}
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{
foreach(array('english','german')as$locale){$lang=array();require$cp_root.'language/lang_'.$locale.'/lang_main.php';
    foreach(array(
        array('Europe/Berlin','2026-03-29',1,0),array('Europe/Berlin','2026-10-25',1,0),
        array('Australia/Lord_Howe','2026-10-04',1,0),array('Australia/Lord_Howe','2026-04-05',1,0),
        array('UTC','2026-03-29',1,0),array('Europe/Berlin','2026-03-28',3,0),
        array('Europe/Berlin','2026-10-24',3,0),array('Europe/Berlin','2026-03-28',1,10),
        array('Pacific/Apia','2011-12-29',1,0),array('Pacific/Apia','2011-12-29',3,0),
        array('America/Sao_Paulo','2018-11-03',1,0),array('Asia/Kathmandu','1985-12-31',1,0),
        array('Africa/Casablanca','2010-05-01',1,0),array('America/Sao_Paulo','2018-11-04',1,0),
        array('America/Sao_Paulo','2018-11-04',1,1)
    )as$case){
        date_default_timezone_set($case[0]);$clock=new DateTimeImmutable($case[1].' '.sprintf('%02d',$case[3]).':00:00',new DateTimeZone($case[0]));$time=$clock->getTimestamp();
        $end=$case[3]===0?ad_end($clock,$case[2])->getTimestamp():$time+$case[2]*86400;$duration=$end-$time;
        $date=array((int)$clock->format('Y'),(int)$clock->format('n'),(int)$clock->format('j'),$case[3],0);$post=cp_post($date,array($case[2],0,0));
        $result=cp_run($post);cp_check($result['storage']===array($time,$duration-1)&&$result['preview']===array($time,$duration-1),'New event and preview use the expected civil/elapsed interval');
        $retry=cp_run($post,array(),true,'newtopic','Unrelated error');$rendered=ad_form_values(cp_render_form($retry,$locale));
        cp_check((int)$rendered['topic_calendar_duration_day']===$case[2]&&cp_run($rendered)['storage']===array($time,$duration-1),'Validation retry through actual rendered form keeps whole civil days');
        $saved=array('topic_calendar_time'=>$time,'topic_calendar_duration'=>$duration);$edit=cp_run(array(),$saved,true,'editpost');$rendered=ad_form_values(cp_render_form($edit,$locale));
        cp_check((int)$rendered['topic_calendar_duration_day']===$case[2]&&cp_run($rendered,$saved,true,'editpost')['storage']===array($time,$duration-1),'Saved all-day event round-trips through actual editor unchanged');
        $rendered['topic_calendar_duration_day']=(string)($case[2]+1);$changed=cp_run($rendered,$saved,true,'editpost');$longer=$case[3]===0?ad_end($clock,$case[2]+1)->getTimestamp()-$time:($case[2]+1)*86400;
        cp_check($changed['storage']===array($time,$longer-1),'Explicit duration change must not retain old seconds');
        $next_noon=ad_noon($clock,1);$moved=new DateTimeImmutable($next_noon->format('Y-m-d').' '.sprintf('%02d',$case[3]).':00:00',new DateTimeZone($case[0]));$post=cp_post(array((int)$moved->format('Y'),(int)$moved->format('n'),(int)$moved->format('j'),$case[3],0),array($case[2],0,0));$moved_duration=$case[3]===0?ad_end($moved,$case[2])->getTimestamp()-$moved->getTimestamp():$case[2]*86400;
        cp_check(cp_run($post,$saved,true,'editpost')['storage']===array($moved->getTimestamp(),$moved_duration-1),'Moving the date recalculates civil duration at the new boundary: '.json_encode(array($case,cp_run($post,$saved,true,'editpost')['storage'],$moved->getTimestamp(),$moved_duration)));$ad_form_cases++;
    }
    foreach(array(array('Europe/Berlin','2026-03-29',0,86400),array('Europe/Berlin','2026-10-25',0,86400),array('UTC','2026-03-29',10,43),array('UTC','2026-03-29',10,3599),array('UTC','2026-03-29',10,2147483648.0),array('UTC','2026-03-29',0,3600),array('UTC','2026-03-29',0,43),array('America/Sao_Paulo','2018-11-04',1,3600),array('UTC','2026-03-29',0,2147483648.0))as$old){
        date_default_timezone_set($old[0]);$clock=new DateTimeImmutable($old[1].' '.sprintf('%02d',$old[2]).':00:00',new DateTimeZone($old[0]));$time=$clock->getTimestamp();$saved=array('topic_calendar_time'=>$time,'topic_calendar_duration'=>$old[3]);
        $fields=ad_form_values(cp_render_form(cp_run(array(),$saved,true,'editpost'),$locale));$result=cp_run($fields,$saved,true,'editpost');
        cp_check($result['storage']===array($time,(int)($old[3]-1)),'Unchanged legacy/sub-minute/high-limit interval must remain exact');
        $fields['topic_calendar_year']='0';cp_check(cp_run($fields,$saved,true,'editpost')['storage']===array(0,0),'Preserved historical seconds must not prevent explicit calendar removal');$ad_form_cases++;
    }
    date_default_timezone_set('Pacific/Apia');cp_check(cp_run(cp_post(array(2011,12,30,0,0),array(1,0,0)))['error']!=='','A skipped calendar date cannot silently become another date');$ad_form_cases++;
}
date_default_timezone_set('UTC');
foreach(array(array(-1,1),array(1700000000,-1),array(1700000000,24856),array(array(1),1))as$bad){cp_check(calendar_post_day_duration($bad[0],$bad[1])===null,'Malformed/oversized day inputs cannot overflow');$ad_form_cases++;}
cp_check(calendar_post_day_duration(1700000000,24855)===2147472000,'Long civil durations work without overflowing 32-bit timestamp arithmetic');$ad_form_cases++;
}finally{restore_error_handler();}
echo 'Calendar all-day forms: ',$ad_form_cases,' actual-parser/preview/template/re-edit cases, ',($cp_checks-$ad_checks_before)," assertions PASS; civil days, DST, timed controls, changed dates and exact legacy intervals.\n";
