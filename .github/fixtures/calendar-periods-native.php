<?php
namespace CalendarAllDayFixture;
if(PHP_SAPI!=='cli'||!isset($schema)||preg_match('/^codex_full_editor_[a-f0-9]{16}$/D',$schema)!==1){throw new \RuntimeException('Owned calendar period schema required');}
// Reuse the actual parser/writer/collector/template loader. Only the unrelated
// category selector is an output adapter; birthdays are explicitly disabled.
function calendar_get_tree_option($selected=''){return '<option value="f3" selected="selected">Fixture forum</option>';}
foreach(array('display_calendar','get_birthday','calendar_event_message_attribute')as$name){eval('namespace CalendarAllDayFixture;'.extract_function($ad_calendar,$name));}
foreach(array('realdate','mkrealdate')as$name){eval('namespace CalendarAllDayFixture;'.extract_function($ad_core,$name));}
function attribute_view($html,$url_pattern,$expected,$expected_count){
    if(!class_exists('DOMDocument')){throw new \RuntimeException('Native calendar DOM test dependency missing');}
    $was=libxml_use_internal_errors(true);
    try{$document=new \DOMDocument('1.0','UTF-8');$loaded=$document->loadHTML('<html><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"></head><body>'.$html.'</body></html>');if(!$loaded){return false;}$count=0;
        foreach($document->getElementsByTagName('a')as$anchor){if(preg_match($url_pattern,$anchor->getAttribute('href'))!==1){continue;}$count++;if($anchor->getAttribute('data-calendar-message')!==$expected){return false;}$names=array();foreach($anchor->attributes as$attribute){$names[]=$attribute->name;}sort($names);if($names!==array('class','data-calendar-message','href','onmouseout','onmouseover')){return false;}}
        return$count===$expected_count;
    }finally{libxml_clear_errors();libxml_use_internal_errors($was);}
}
$period_cases=0;$period_failures=array();
foreach(array('english','german')as$locale){foreach(array('UTC','Europe/Berlin')as$zone){foreach(array(
    array(1,0,24855,3,14,2147483640,28),
    array(0,30,0,0,30,1800,1),
    array(0,30,0,0,0,0,1),
    array(10,0,0,1,0,3600,1)
)as$case){
    \ps_fixture();date_default_timezone_set($zone);$board_config+=array('topics_per_page'=>100,'calendar_birthday'=>0,'calendar_display_open'=>1,'calendar_week_start'=>1,'calendar_nb_row'=>5);$board_config['default_lang']=$locale;$board_config['default_dateformat']='Y-m-d H:i';$userdata['user_dateformat']='Y-m-d H:i';$lang=array();$lang_extend_admin=false;require$phpbb_root_path.'language/lang_'.$locale.'/lang_main.php';require$phpbb_root_path.'language/lang_'.$locale.'/lang_extend_topic_calendar.php';$images=array('icon_calendar'=>'calendar.gif','icon_tiny_topic'=>'topic.gif','up_arrow'=>'up.gif','down_arrow'=>'down.gif');
    $input=post('2026-11-03',$case[2],$case[0]);$input['topic_calendar_min']=(string)$case[1];$input['topic_calendar_duration_hour']=(string)$case[3];$input['topic_calendar_duration_min']=(string)$case[4];
    list($topic,$post_id)=controller($input);$saved=\ae_rows('SELECT topic_calendar_time,topic_calendar_duration FROM fixture_topics WHERE topic_id='.(int)$topic)[0];
    $clock=new \DateTimeImmutable('2026-11-03 '.sprintf('%02d:%02d:00',$case[0],$case[1]),new \DateTimeZone($zone));$start=$clock->getTimestamp();$duration=max(0,$case[5]-1);
    // Independent MariaDB DATETIME arithmetic: only the start is converted
    // from a native timestamp; DATE_ADD itself can represent the 2094 end.
    \ae_sql("SET time_zone='+00:00'");$reference=\ae_rows("SELECT DATE_FORMAT(DATE_ADD(FROM_UNIXTIME($start),INTERVAL $duration SECOND),'%Y-%m-%d %H:%i:%s') AS period_end")[0]['period_end'];\ae_check(is_string($reference),'Independent SQL end must be available');$last=(new \DateTimeImmutable($reference,new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone($zone));
    $a=$clock->format($board_config['default_dateformat']);$b=$last->format($board_config['default_dateformat']);if($locale!=='english'){$a=strtr($a,$lang['datetime']);$b=strtr($b,$lang['datetime']);}$expected=$duration?sprintf($lang['Calendar_from_to'],$a,$b):sprintf($lang['Calendar_time'],$a);
    $events=collect($start,$start+3600);$template=template();$before=\fe_snapshot();display_calendar('PERIOD_CALENDAR',0,$start,'f3');$html=$template->_tpldata['.'][0]['PERIOD_CALENDAR'];\ae_check(\fe_snapshot()===$before,'Actual month view cannot mutate stored fixture rows');
    $visible=preg_match_all('~href="[^"<>]*viewtopic\.php\?t='.(int)$topic.'"~',$html);
    $isolated=$events&&attribute_view($html,'~viewtopic\.php\?t='.(int)$topic.'$~',$events[0]['event_message'],$case[6]);
    if((int)$saved['topic_calendar_time']!==$start||(int)$saved['topic_calendar_duration']!==$duration||count($events)!==1||strpos($events[0]['event_message'],$expected)===false||$visible!==$case[6]||!$isolated){$period_failures[]=array('locale'=>$locale,'zone'=>$zone,'hour'=>$case[0],'minute'=>$case[1],'duration'=>$duration,'expected'=>$expected,'overview'=>$events?strip_tags($events[0]['event_message']):null,'month_links'=>$visible,'expected_month_links'=>$case[6],'attribute_exact'=>$isolated);}$period_cases++;
}}}
foreach(array('english','german')as$locale){
    \ps_fixture();date_default_timezone_set('UTC');$board_config+=array('topics_per_page'=>100,'calendar_display_open'=>1,'calendar_week_start'=>1,'calendar_nb_row'=>5);$board_config['calendar_birthday']=1;$board_config['default_lang']=$locale;$userdata['user_dateformat']='Y-m-d H:i';$lang=array();$lang_extend_admin=false;require$phpbb_root_path.'language/lang_'.$locale.'/lang_main.php';require$phpbb_root_path.'language/lang_'.$locale.'/lang_extend_topic_calendar.php';$images=array('icon_calendar'=>'calendar.gif','icon_tiny_topic'=>'topic.gif','up_arrow'=>'up.gif','down_arrow'=>'down.gif','icon_birthday'=>'birthday.gif','icon_tiny_profile'=>'profile.gif');
    \ae_sql('UPDATE fixture_users SET user_birthday='.(int)mkrealdate(3,11,1989).' WHERE user_id=8');$first=mktime(0,0,0,11,1,2026);$last=mktime(0,0,0,12,1,2026);$birthdays=array();$number=0;get_birthday($birthdays,$number,$first,$last);\ae_check($number===1&&count($birthdays)===1,'Actual stored birthday fixture must produce one event');$expected=html_entity_decode($birthdays[0]['event_message'],ENT_QUOTES,'UTF-8');
    $before=\fe_snapshot();$template=template();display_calendar('BIRTHDAY_CALENDAR',0,$first,'f3');$html=$template->_tpldata['.'][0]['BIRTHDAY_CALENDAR'];\ae_check(\fe_snapshot()===$before,'Birthday month view is read-only');if(!attribute_view($html,'~profile\.php\?mode=viewprofile&u=8$~',$expected,1)){$period_failures[]=array('locale'=>$locale,'birthday_attribute_exact'=>false);}$period_cases++;
    $userdata['session_logged_in']=false;$template=template();display_calendar('GUEST_CALENDAR',0,$first,'f3');$html=$template->_tpldata['.'][0]['GUEST_CALENDAR'];if(!attribute_view($html,'~profile\.php\?mode=viewprofile&u=8$~','',0)){$period_failures[]=array('locale'=>$locale,'birthday_disclosed_to_guest'=>true);}$period_cases++;
}
if($period_failures){throw new \RuntimeException('Actual calendar period overview/month view differs: '.json_encode($period_failures));}
echo 'Native calendar periods: ',$period_cases," actual-controller/write/SQL/overview/month-view cases PASS; EN/DE, minute-only times, point events, wide ends, bounded placement and isolated topic/birthday attributes.\n";
