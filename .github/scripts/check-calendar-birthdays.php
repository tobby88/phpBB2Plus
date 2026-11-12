<?php
define('IN_PHPBB', true); define('ANONYMOUS', -1); define('USERS_TABLE', 'fixture_users');
define('POST_USERS_URL', 'u'); define('POST_BIRTHDAY', 91); define('GENERAL_ERROR', 2);
require dirname(__DIR__) . '/fixtures/calendar-birthday-functions.php';
$root = dirname(dirname(__DIR__)) . '/phpBB2/';
function append_sid($url) { return $url; }
function cb_check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } $GLOBALS['checks']++; }
function cb_fragment($source, $start, $end)
{
    $a = strpos($source, $start); $b = $a === false ? false : strpos($source, $end, $a);
    if ($a === false || $b === false) { throw new RuntimeException('Actual calendar branch missing'); }
    return substr($source, $a, $b - $a);
}
class CalendarBirthdayRows
{
    public $rows = array(); public $queries = 0; public $frees = 0; public $index = 0;
    function sql_query($sql) { $this->queries++; $this->index = 0; return true; }
    function sql_fetchrow($result) { return isset($this->rows[$this->index]) ? $this->rows[$this->index++] : false; }
    function sql_freeresult($result) { $this->frees++; }
}
function cb_collect($start, $end, $limit=false, $offset=0, $max=-1)
{
    $events = array(); $count = -1; get_birthday($events, $count, $start, $end, $limit, $offset, $max);
    return array($events, $count);
}
$checks = 0; $cases = 0; $db = new CalendarBirthdayRows(); $phpbb_root_path = './'; $phpEx = 'php';
$board_config = array('calendar_birthday'=>1, 'topics_per_page'=>3, 'board_timezone'=>0, 'summer_time'=>0);
$userdata = array('user_id'=>7, 'session_logged_in'=>true);
$images = array('icon_birthday'=>'images/birthday.gif', 'icon_tiny_profile'=>'images/profile.gif');
$hostile = 'O\'Reilly "Grüße" \\ &amp; <img src=x onerror=fixture()> ' . "\n";
$dates = array(array(1,1989,11,3),array(2,1940,11,3),array(3,1970,1,1),array(4,1992,2,29),array(5,1990,12,31));
foreach ($dates as $date)
{
    $db->rows[] = array('user_id'=>$date[0], 'username'=>$date[0]===1 ? $hostile : 'Member '.$date[0], 'user_birthday'=>(string)mkrealdate($date[3],$date[2],$date[1]));
}
foreach (array(999999,'20101103','invalid',null,'999998',array(1)) as $invalid)
{
    $db->rows[] = array('user_id'=>90, 'username'=>'Invalid', 'user_birthday'=>$invalid);
}
$db->rows[] = array('user_id'=>-1, 'username'=>'Anonymous', 'user_birthday'=>'0');
set_error_handler(function($severity,$message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } });
date_default_timezone_set('UTC');
foreach (array('english','german') as $locale)
{
    $board_config['default_lang'] = $locale;
    $lang = array(); $lang_extend_admin = false;
    require $root.'language/lang_'.$locale.'/lang_main.php';
    require $root.'language/lang_'.$locale.'/lang_extend_topic_calendar.php';
    // Inspect the actual module metadata without running its configuration loader.
    $metadata=cb_fragment(file_get_contents($root.'includes/mods_settings/mod_calendar.php'),'$config_fields = array(','// init config table');
    $list_yes_no=array('Yes'=>1,'No'=>0);$calendar_sunday='Sunday';$calendar_monday='Monday';
    $userdata['user_birthday']=999999;eval($metadata);
    cb_check($config_fields['calendar_birthday']['hide']===false,'Birthday preference must be available for the installed Birthday MOD');
    unset($userdata['user_birthday']);eval($metadata);
    cb_check($config_fields['calendar_birthday']['hide']===true,'Absent birthday module field must retain its metadata guard');
    $a = mktime(0,0,0,11,3,2026); $b = mktime(0,0,0,11,4,2026);
    foreach (array(array(false,7,1),array(true,-1,1),array(true,-2,1),array(true,0,1),array(true,7,0)) as $policy)
    {
        $userdata = array('session_logged_in'=>$policy[0], 'user_id'=>$policy[1]); $board_config['calendar_birthday']=$policy[2]; $before=$db->queries;
        $result=cb_collect($a,$b); cb_check($result===array(array(),0) && $db->queries===$before,'Guest/disabled birthday access must not query or expose profiles'); $cases++;
    }
    $userdata=array('session_logged_in'=>true,'user_id'=>7); $board_config['calendar_birthday']=1;
    foreach (array(array($a,$b,2),array($a+43200,$a+46800,2),array($a-86400,$a,0),array($b,$b+86400,0),
        array(mktime(0,0,0,12,31,2026),mktime(0,0,0,1,2,2027),2),
        array(mktime(0,0,0,2,29,2024),mktime(0,0,0,3,1,2024),1),
        array(mktime(0,0,0,2,28,2025),mktime(0,0,0,3,1,2025),0),
        array(mktime(0,0,0,3,1,2025),mktime(0,0,0,3,2,2025),1)) as $range)
    {
        $result=cb_collect($range[0],$range[1]); cb_check(count($result[0])===$range[2] && $result[1]===$range[2],'Supported stored date / range / leap-day occurrence differs'); $cases++;
    }
    foreach (array(array(0,1,1),array(1,1,1),array(2,1,0),array(0,0,0),array(0,-1,2),array(array(1),1,1)) as $page)
    {
        $result=cb_collect($a,$b,true,$page[0],$page[1]); cb_check(count($result[0])===$page[2] && $result[1]===2,'Count must include all matching birthdays before paging'); $cases++;
    }
    $result=cb_collect($a,$b); $event=$result[0][0];
    cb_check($event['event_title']===phpbb_stored_text($hostile) && strpos($event['event_title'],'<img')===false,'Birthday title must escape stored usernames once');
    $markup=html_entity_decode($event['event_message'],ENT_QUOTES,'UTF-8');
    cb_check(strpos($markup,'&nbsp;[37]')!==false && strpos($markup,phpbb_stored_text($hostile))!==false && strpos($markup,'<img src=x')===false,'Age and tooltip content must not depend on localized DATE_FORMAT or interpret a username as HTML');
    cb_check($event['event_calendar_time']===$a && $event['event_calendar_duration']===86399,'Birthday spans exactly its local day');
    $events=array(array('existing'=>'topic')); $number=-1; get_birthday($events,$number,$a,$b,true,0,1);
    cb_check(count($events)===2 && $events[0]===array('existing'=>'topic') && $number===2,'Birthday collection must append without counting existing topic events');
}
cb_check($db->queries===$db->frees,'Birthday result resources must always be freed');
foreach (array(array($a,$a),array($b,$a),array(array(1),$b),array('invalid',$b),array(mktime(0,0,0,1,1,1970),$b)) as $invalid)
{
    $before=$db->queries;$result=cb_collect($invalid[0],$invalid[1]);
    cb_check($result===array(array(),0)&&$db->queries===$before,'Invalid intervals must return before database access');$cases++;
}
$saved_rows=$db->rows;
date_default_timezone_set('Europe/Berlin');
foreach(array(array(3,29,2026,82799),array(10,25,2026,89999))as$dst)
{
    $db->rows=array(array('user_id'=>1,'username'=>'DST member','user_birthday'=>mkrealdate($dst[1],$dst[0],1990)));
    $from=mktime(0,0,0,$dst[0],$dst[1],$dst[2]);$to=mktime(0,0,0,$dst[0],$dst[1]+1,$dst[2]);
    $result=cb_collect($from,$to);
    cb_check(count($result[0])===1&&$result[0][0]['event_calendar_duration']===$dst[3],'Birthday must span the real local day across DST');$cases++;
}
$db->rows=$saved_rows;date_default_timezone_set('UTC');
// The popup value is HTML data in a quoted data attribute, never a JS literal.
$template=file_get_contents($root.'templates/fisubsilversh/calendar_box.tpl');
$line=''; foreach(explode("\n",$template) as $candidate){if(strpos($candidate,'data-calendar-message=')!==false){$line=$candidate;break;}}
cb_check($line!=='' && strpos($line,"this.getAttribute('data-calendar-message')")!==false,'Actual template must read tooltip data without JavaScript interpolation');
$functions=file_get_contents($root.'includes/functions_calendar.php');
eval(calendar_fixture_function($functions,'calendar_event_message_attribute'));
if(preg_match('/^\s*\x27EVENT_MESSAGE\x27\s*=>\s*(.+),\s*$/m',$functions,$binding)!==1){throw new RuntimeException('Actual calendar message binding missing');}
$events=array($event);$ind=0;$encoded=eval('return '.$binding[1].';');
$html=str_replace('{_calendar_box._row._cell.switch_filled._event.EVENT_MESSAGE}',$encoded,$line);
preg_match('/data-calendar-message="([^"]*)"/',$html,$data);
cb_check(isset($data[1]) && html_entity_decode($data[1],ENT_QUOTES,'UTF-8')===$markup,'Tooltip data attribute must retain quotes, UTF-8, backslashes, entities and line breaks');
preg_match('/onMouseOver="([^"]*)"/',$html,$handler);
cb_check(isset($handler[1]) && strpos($handler[1],'O\'Reilly')===false && strpos($handler[1],'EVENT_MESSAGE')===false,'User text must not enter the executable event handler');
cb_check($encoded===$event['event_message'],'Actual calendar binding must preserve the already encoded birthday tooltip value');
// Actual scheduler arithmetic, isolated from only its two data collectors.
eval('namespace BirthdaySchedulerFixture; function get_birthday(&$e,&$n,$a,$b,$limit,$start,$max){$n=count($GLOBALS["cb_brows"]);foreach(array_slice($GLOBALS["cb_brows"],$start,$max)as$r){$e[]=$r;}} function get_event_topics(&$e,&$n,$a,$b,$limit,$start,$max,$fid){if($start<0||$max<0){throw new \\RuntimeException("Negative topic page");}$n=count($GLOBALS["cb_trows"]);foreach(array_slice($GLOBALS["cb_trows"],$start,$max)as$r){$e[]=$r;}}');
$scheduler=file_get_contents($root.'calendar_scheduler.php');
$branch=cb_fragment($scheduler,'// Collect the selected page.','$page_title =');
$start_date=1;$end_date=2;$fid='';
for($birthdays=0;$birthdays<=7;$birthdays++){for($topics=0;$topics<=7;$topics++){for($size=1;$size<=5;$size++){
    $GLOBALS['cb_brows']=array();$GLOBALS['cb_trows']=array();
    for($i=0;$i<$birthdays;$i++){$GLOBALS['cb_brows'][]='B'.$i;}for($i=0;$i<$topics;$i++){$GLOBALS['cb_trows'][]='T'.$i;}
    $all=array_merge($GLOBALS['cb_brows'],$GLOBALS['cb_trows']);$board_config['topics_per_page']=$size;
    for($requested_start=0;$requested_start<=count($all)+1;$requested_start++){
        $start=$requested_start;$events=array();eval('namespace BirthdaySchedulerFixture;'.$branch);
        $expected_start=$requested_start<count($all)?$requested_start:0;
        cb_check($events===array_slice($all,$expected_start,$size)&&$start===$expected_start&&$birthdays_count===$birthdays&&$topics_count===$topics,'Mixed pagination must preserve valid offsets and recover unavailable pages without skipping or duplicating events');$cases++;
    }
}}}
// The page count must include birthdays, including a birthday-only page.
class CalendarPageTemplate {public $values=array();function assign_vars($values){$this->values=$values;}}
$footer=cb_fragment($scheduler,'$total = $topics_count + $birthdays_count;','// send to browser');
$template=new CalendarPageTemplate();$date=1;$mode='';$nav_separator='';$s_hidden_fields='';$board_config['topics_per_page']=3;
foreach(array(array(0,0),array(4,0),array(2,3))as$totals)
{
    $birthdays_count=$totals[0];$topics_count=$totals[1];$start=0;
    eval($footer);$expected=max(1,array_sum($totals));
    cb_check($template->values['PAGINATION']===generate_pagination('calendar_scheduler.php?d=1&mode=&fid=',$expected,3,0)&&$template->values['PAGE_NUMBER']===sprintf($lang['Page_of'],1,ceil($expected/3)),'Page label and links must use the same birthday-plus-topic count');$cases++;
}
restore_error_handler();
echo 'Calendar birthdays: ',$cases,' collector/pagination cases, ',$checks," assertions PASS; real EN/DE messages, privacy, supported dates, counts, paging and tooltip data.\n";
