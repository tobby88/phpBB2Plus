<?php

function calendar_runtime_assert($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, "Calendar runtime safety test failed: $message\n");
		exit(1);
	}
}

$root = dirname(dirname(__DIR__));
$functions = file_get_contents($root . '/phpBB2/includes/functions_calendar.php');
$calendar = file_get_contents($root . '/phpBB2/calendar.php');
$scheduler = file_get_contents($root . '/phpBB2/calendar_scheduler.php');
$mini_cal_english = file_get_contents($root . '/phpBB2/language/lang_english/lang_main_mini_cal.php');
$mini_cal_german = file_get_contents($root . '/phpBB2/language/lang_german/lang_main_mini_cal.php');
$mini_cal_suite = file_get_contents($root . '/phpBB2/includes/mini_cal/calendarSuite.php');
$mini_cal_runtime = file_get_contents($root . '/phpBB2/includes/mini_cal/mini_cal.php');
$mini_cal_common = file_get_contents($root . '/phpBB2/includes/mini_cal/mini_cal_common.php');
$mini_cal_topic = file_get_contents($root . '/phpBB2/includes/mini_cal/mini_cal_TOPIC.php');

calendar_runtime_assert(strpos($functions, 'function calendar_normalize_forum_filter') !== false, 'forum filters need one strict normalizer');
calendar_runtime_assert(strpos($functions, "preg_match('/^([") !== false, 'forum filters must enforce a type-and-ID grammar');
calendar_runtime_assert(substr_count($functions, "htmlspecialchars(\$row[") >= 2, 'forum and category labels must be escaped');
calendar_runtime_assert(strpos($calendar, 'checkdate($month, $day, $year)') !== false, 'month view dates must be real calendar dates');
calendar_runtime_assert(strpos($calendar, 'calendar_normalize_forum_filter') !== false, 'month view forum filters must be normalized');
calendar_runtime_assert(strpos($scheduler, 'intval($_POST[\'selected_id\'])') === false, 'scheduler must not destroy typed forum IDs before validation');
calendar_runtime_assert(strpos($scheduler, 'calendar_normalize_forum_filter') !== false, 'scheduler forum filters must be normalized');
calendar_runtime_assert(strpos($scheduler, 'min(1000000, intval($start_value))') !== false, 'scheduler pagination must be bounded');
calendar_runtime_assert(strpos($mini_cal_english, '?>') === false && strpos($mini_cal_german, '?>') === false, 'mini-calendar language includes must not emit trailing output before headers');
calendar_runtime_assert(strpos($mini_cal_suite, 'strftime(') === false, 'Mini Calendar must not use the PHP 8.1-deprecated strftime function');
calendar_runtime_assert(strpos($mini_cal_suite, 'setlocale(') === false, 'Mini Calendar rendering must not mutate the process-wide locale');
calendar_runtime_assert(strpos($mini_cal_runtime, "max(-1200, min(1200") !== false, 'Mini Calendar month offsets must be bounded');
calendar_runtime_assert(strpos($mini_cal_runtime, "is_scalar(\$_SERVER['SCRIPT_NAME'])") !== false, 'Mini Calendar navigation must validate the current script name');
calendar_runtime_assert(strpos($mini_cal_runtime, "['PHP_SELF']") === false, 'Mini Calendar links must not reflect attacker-controlled PHP_SELF values');
calendar_runtime_assert(strpos($mini_cal_common, "http_build_query(\$params") !== false, 'Mini Calendar navigation must rebuild an allowlisted query string');
calendar_runtime_assert(strpos($mini_cal_common, "htmlspecialchars(substr((string) \$row['forum_name']") !== false, 'Mini Calendar forum options must escape stored names');
calendar_runtime_assert(strpos($mini_cal_topic, "if (\$auth_view_forums === '')") !== false, 'Mini Calendar must render no events when no forums are visible');
calendar_runtime_assert(strpos($mini_cal_topic, "' AND t.forum_id IN (' . \$auth_view_forums") !== false, 'Mini Calendar event queries must always carry a forum authorization filter');
calendar_runtime_assert(strpos($mini_cal_topic, "phpbb_stored_text(\$row['topic_title'])") !== false, 'Mini Calendar must escape stored event titles');

if (!defined('POST_USERS_URL'))
{
	define('POST_USERS_URL', 'u');
}
require_once $root . '/phpBB2/includes/mini_cal/mini_cal_common.php';
$_POST = array();
$_GET = array('mode' => 'personal', 'u' => '7', 'month' => '1', 'unsafe' => '\"><script>alert(1)</script>');
calendar_runtime_assert(setQueryStringVal('month', 2) === '?mode=personal&amp;u=7&amp;month=2', 'Mini Calendar query rebuilding must preserve only its allowlisted context');
$_GET = array('mode' => array('personal'), 'u' => array('7'));
calendar_runtime_assert(setQueryStringVal('month', -1) === '?month=-1', 'Mini Calendar query rebuilding must reject array-valued context');
calendar_runtime_assert(miniCalForumIds('3, 2,3, 0') === '3,2', 'Mini Calendar forum lists must contain unique positive IDs');
calendar_runtime_assert(miniCalForumIds('1,2 OR 1=1') === '', 'Mini Calendar forum lists must reject SQL fragments');

define('IN_MINI_CAL', true);
require_once $root . '/phpBB2/includes/mini_cal/calendarSuite.php';
date_default_timezone_set('UTC');
$mini_cal = new calendarSuite();
calendar_runtime_assert($mini_cal->dayOfYear(mktime(12, 0, 0, 1, 1, 2024)) === '001', 'day-of-year formatting must retain the legacy three-digit format');
calendar_runtime_assert($mini_cal->sundayWeek(mktime(12, 0, 0, 1, 1, 2023)) === '01', 'a year beginning on Sunday must start in week 01');
calendar_runtime_assert($mini_cal->sundayWeek(mktime(12, 0, 0, 1, 1, 2024)) === '00', 'days before the first Sunday must remain in week 00');
calendar_runtime_assert($mini_cal->sundayWeek(mktime(12, 0, 0, 1, 7, 2024)) === '01', 'the first Sunday must begin week 01');
if (PHP_VERSION_ID < 80100)
{
	$comparison_start = gmmktime(12, 0, 0, 1, 1, 2023);
	for ($day = 0; $day < 731; $day++)
	{
		$timestamp = $comparison_start + ($day * 86400);
		calendar_runtime_assert($mini_cal->dayOfYear($timestamp) === strftime('%j', $timestamp), 'day-of-year compatibility differs from the legacy formatter');
		calendar_runtime_assert($mini_cal->sundayWeek($timestamp) === strftime('%U', $timestamp), 'Sunday-week compatibility differs from the legacy formatter');
	}
}

echo "Calendar runtime safety tests passed.\n";

require dirname(__DIR__) . '/fixtures/calendar-navigation.php';
error_reporting(E_ALL);
set_error_handler(function($severity,$message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } });
$navigation_cases=0;$navigation_checks=0;
function cn_check($ok,$message) { if (!$ok) { throw new RuntimeException($message); } $GLOBALS['navigation_checks']++; }
function cn_query($url) { $parts=parse_url(html_entity_decode($url,ENT_QUOTES,'UTF-8'));$query=array();parse_str(isset($parts['query'])?$parts['query']:'',$query);return $query; }
function cn_day_window($result) { $windows=array_values(array_filter($result['windows'],function($row) { return $row[0]==='topic' && $row[3]===true; }));cn_check(count($windows)===1,'Actual scheduler queries exactly one selected topic interval');return array($windows[0][1],$windows[0][2]); }
foreach (array('english','german') as $locale) {
    foreach (array(1971,1979,1980,1984,1990,1999,2000,2026,2037,2069) as $year) {
        $oracle=new DateTimeImmutable($year.'-01-01 00:00:00',new DateTimeZone('UTC'));
        if ((float)$oracle->format('U')>PHP_INT_MAX) { continue; }
        $result=cn_run('calendar',array('start'=>$year.'0101'),array(),'UTC',1,$locale);
        cn_check(gmdate('Ymd',$result['date'])===$year.'0101','Month view accepts every supported year, including 1980 and 1990');$navigation_cases++;
    }
    foreach (array('19700101','20700101','19800230','19900229','20260001','20260100','20261301',array('20260101')) as $invalid) {
        $result=cn_run('calendar',array('start'=>$invalid),array(),'UTC',1,$locale);
        cn_check(gmdate('Ymd',$result['date'])===gmdate('Ymd'),'Invalid or unsupported month-view dates retain the current-day fallback');$navigation_cases++;
    }
    $result=cn_run('calendar',array('start'=>'19800229'),array(),'UTC',1,$locale);
    cn_check(gmdate('Ymd',$result['date'])==='19800229','Valid leap date in a formerly rejected year is retained');$navigation_cases++;
    foreach (array('UTC','Europe/Berlin','America/New_York','Pacific/Auckland','Australia/Lord_Howe') as $server) {
        $zone=new DateTimeZone($server);
        foreach (array('2027-01-31','2024-01-31','2024-03-31','2026-12-31','2026-03-29','2026-10-25','2026-03-08','2026-11-01','2026-04-05','2026-09-27','2026-10-04') as $day) {
            $selected=new DateTimeImmutable($day.' 12:34:56',$zone);
            $first=$selected->setTime(0,0,0);$last=$first->modify('+1 day');
            foreach (array(0,1) as $week) {
                $result=cn_run('calendar_scheduler',array('d'=>$selected->format('U'),'fid'=>'f6'),array(),$server,$week,$locale);
                cn_check(cn_grid_days($result)===range(1,(int)$first->format('t')),'Actual month grid contains each civil day once across server DST changes');
                $leading=((int)$first->modify('first day of this month')->format('w')-$week+7)%7;
                $rows=(int)ceil(($leading+(int)$first->format('t'))/7);
                cn_check(count($result['blocks']['row'])===$rows && count($result['blocks']['row.cell'])===$rows*7,'Actual month grid retains complete weekday-aligned rows');
                cn_check(cn_day_window($result)===array($first->getTimestamp(),$last->getTimestamp()),'All-events query covers the real complete civil day');
                foreach (array('previous'=>'U_PREC','next'=>'U_NEXT') as $direction=>$key) {
                    $target=$first->modify('first day of '.$direction.' month');
                    $target=$target->setDate((int)$target->format('Y'),(int)$target->format('n'),min((int)$first->format('j'),(int)$target->format('t')));
                    cn_check(cn_url_date($result['vars'][$key])===$target->getTimestamp(),'Month arrow stays in the requested month and clamps only a missing end day');
                    if ($week===1) {
                        $jump=cn_run('calendar_scheduler',array(),array('date'=>$selected->format('U'),'start_month'=>$target->format('n'),'start_year'=>$target->format('Y'),'selected_id'=>'f6'),$server,$week,$locale);
                        cn_check($jump['date']===$target->getTimestamp(),'Month/year dropdown uses the same clamped civil date');$navigation_cases++;
                    }
                }
                foreach (array('U_PREC','U_NEXT','U_CALENDAR','U_CALENDAR_SCHEDULER') as $key) { $query=cn_query($result['vars'][$key]);cn_check(isset($query['fid']) && $query['fid']==='f6','Calendar links preserve the normalized forum filter'); }
                $query=cn_query($result['paging']);cn_check(isset($query['fid']) && $query['fid']==='f6','Scheduler pagination preserves its forum filter');
                $hours=$result['blocks']['hour'];$count=(int)ceil(($last->getTimestamp()-$first->getTimestamp())/3600);
                cn_check(count($hours)===$count+1,'Hour bar covers the real 23/24/25-hour day, including half-hour transitions');
                $cursor=$first->getTimestamp();$labels=array();
                foreach ($hours as $index=>$hour) {
                    $query=cn_query($hour['U_HOUR']);cn_check(isset($query['fid']) && $query['fid']==='f6','Every hour link retains its forum filter');
                    if ($index===0) { cn_check($hour['CLASS']==='quote' && $query['d']===(string)$cursor && !isset($query['mode']),'All-events link selects the same day');continue; }
                    cn_check((int)$query['d']===$cursor && $query['mode']==='hour','Real hour links are ordered, unique and gap-free');
                    $clock=(new DateTimeImmutable('@'.$cursor))->setTimezone($zone);$clock=$clock->format($clock->format('i')==='00'?'H':'H:i');
                    cn_check(strpos($hour['HOUR'],$clock)===0,'Visible hour label matches the actual linked local clock time');
                    cn_check(!isset($labels[$hour['HOUR']]),'Repeated clock hours have distinct visible timezone labels');$labels[$hour['HOUR']]=true;
                    // Test each transition slot once; other locale/week variants
                    // retain complete render checks without multiplying requests.
                    if ($week===1 && $locale==='english' && (($last->getTimestamp()-$first->getTimestamp())!==86400 || $day==='2027-01-31')) {
                        $slot=cn_run('calendar_scheduler',$query,array(),$server,$week,$locale);
                        cn_check(cn_day_window($slot)===array($cursor,min($cursor+3600,$last->getTimestamp())),'Following every hour link selects its exact interval without gaps or overlap');
                        $active=array_values(array_filter($slot['blocks']['hour'],function($row) { return $row['CLASS']==='quote'; }));
                        cn_check(count($active)===1 && cn_url_date($active[0]['U_HOUR'])===$cursor,'Only the selected real hour is highlighted, including repeated autumn hours');$navigation_cases++;
                    }
                    $cursor=min($cursor+3600,$last->getTimestamp());
                }
                cn_check($cursor===$last->getTimestamp(),'Hour slots cover through the next midnight');$navigation_cases++;
            }
        }
    }
}
date_default_timezone_set('UTC');
foreach (array('1971-01-01','2037-12-31','2069-12-31') as $boundary) {
    $oracle=new DateTimeImmutable($boundary.' 12:00:00',new DateTimeZone('UTC'));if ((float)$oracle->format('U')>PHP_INT_MAX) { continue; }
    $date=$oracle->getTimestamp();$result=cn_run('calendar_scheduler',array('d'=>(string)$date),array());
    if ($boundary==='2037-12-31') { $target=new DateTimeImmutable('2038-01-31 00:00:00',new DateTimeZone('UTC'));$expected=(float)$target->format('U')>PHP_INT_MAX?$date:$target->getTimestamp();cn_check(cn_url_date($result['vars']['U_NEXT'])===$expected,'Unrepresentable target month retains the current date on a 32-bit runtime'); }
    else { $key=$boundary==='1971-01-01'?'U_PREC':'U_NEXT';cn_check(cn_url_date($result['vars'][$key])===$date,'Supported navigation boundaries never link to a rejected year'); }
    $navigation_cases++;
}
foreach (array('c6'=>'c6','Root'=>'Root','f6 OR 1=1'=>'') as $input=>$expected) {
    $result=cn_run('calendar_scheduler',array('d'=>'1793016000','fid'=>$input),array());$query=cn_query($result['blocks']['hour'][1]['U_HOUR']);cn_check(isset($query['fid']) && $query['fid']===$expected,'Navigation retains only a normalized category/root/forum filter');$navigation_cases++;
}
if (PHP_INT_SIZE===4) { $result=cn_run('calendar_scheduler',array('d'=>(string)PHP_INT_MAX),array());cn_check(gmdate('Ymd',$result['date'])===gmdate('Ymd'),'Unrepresentable next midnight uses the normal current-day fallback');$navigation_cases++; }
foreach (array('english','german') as $locale) {
    date_default_timezone_set('Europe/Berlin');$result=cn_run('calendar_scheduler',array('d'=>(string)mktime(12,0,0,10,25,2026),'fid'=>'f6'),array(),'Europe/Berlin',1,$locale);$html=cn_render($result);
    cn_check(preg_match('//u',$html)===1 && substr_count($html,'mode=hour')===25,'Actual fiSubSilver/Extreme Styles template renders every autumn hour in valid UTF-8');
    cn_check(strpos($html,'02 CEST')!==false && strpos($html,'02 CET')!==false && strpos($html,'{hour.')===false,'Actual rendered template distinguishes both autumn hours without unresolved placeholders');$navigation_cases++;
}
cn_check(calendar_month_date(1,1970,1)===false && calendar_month_date(1,2070,1)===false,'Month helper refuses unsupported target years');
restore_error_handler();
echo 'Calendar navigation: ',$navigation_cases,' actual-controller cases, ',$navigation_checks," assertions PASS; supported years, clamped months, DST grids/hour windows, EN/DE and persistent forum filters.\n";

// Follow the real rendered form from an existing mixed-result page. The
// providers supply isolated rows, while controller parsing, row conversion,
// count arithmetic, hidden fields and Extreme Styles output stay unmodified.
$selection_cases=0;$selection_checks_before=$navigation_checks;
set_error_handler(function($severity,$message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } });
foreach (array('english','german') as $locale) {
    foreach (array(array('UTC','2027-01-31 15:12:00'),array('Europe/Berlin','2026-10-25 00:30:00'),array('Europe/Berlin','2026-10-25 01:30:00'),array('Australia/Lord_Howe','2026-04-04 15:15:00'),array('Pacific/Auckland','2024-02-29 15:12:00')) as $vector) {
        $stamp=(new DateTimeImmutable($vector[1],new DateTimeZone('UTC')))->getTimestamp();
        $clock=(new DateTimeImmutable('@'.$stamp))->setTimezone(new DateTimeZone($vector[0]));
        foreach (array('','hour') as $mode) { foreach (array(3,6) as $offset) {
            $original=cn_run('calendar_scheduler',array('d'=>(string)$stamp,'mode'=>$mode,'start'=>(string)$offset,'fid'=>'f6'),array(),$vector[0],1,$locale,array('birthdays'=>5,'topics'=>6));
            $expected=array_slice(array('b0','b1','b2','b3','b4','t0','t1','t2','t3','t4','t5'),$offset,3);
            cn_check(array_column($original['display_rows'],'topic_id')===$expected,'Ordinary GET pagination retains mixed birthday/topic rows and offset');
            cn_check($original['vars']['PAGE_NUMBER']===sprintf($lang['Page_of'],(int)($offset/3)+1,4),'Ordinary current-selection page count stays unchanged');
            $form=cn_form(cn_render($original));cn_check($form['action']==='./calendar_scheduler.php','Rendered form uses the assigned canonical action');
            cn_check(!array_key_exists('start',$form['post']),'Selection form does not carry the previous result page');
            cn_check(isset($form['post']['date'],$form['post']['mode'],$form['post']['selected_id']) && $form['post']['date']===(string)$stamp && $form['post']['mode']===$mode && $form['post']['selected_id']==='f6','Rendered form retains exact date/mode/filter context');
            foreach (array('month','year','forum') as $change) { foreach (array(false,true) as $cached) {
                $post=$form['post'];if($cached){$post['start']=(string)$offset;}
                if($change==='month'){$post['start_month']=(string)((int)$clock->format('n')===12?1:(int)$clock->format('n')+1);$post['start_year']=(string)((int)$clock->format('Y')+((int)$clock->format('n')===12?1:0));}
                elseif($change==='year'){$post['start_year']=(string)((int)$clock->format('Y')+1);}
                else{$post['selected_id']='f7';}
                // A stale query string and cached pre-fix hidden start must
                // not take precedence over a newly submitted selection.
                $result=cn_run('calendar_scheduler',array('start'=>'999','fid'=>'f6'),$post,$vector[0],1,$locale,array('birthdays'=>0,'topics'=>1));
                cn_check(array_column($result['display_rows'],'topic_id')===array('t0'),'New selection starts at its available first result, including cached forms');
                cn_check($result['vars']['PAGE_NUMBER']===sprintf($lang['Page_of'],1,1),'Selection never carries an old page number into a smaller result set');
                $selected=cn_form(cn_render($result));cn_check(!isset($selected['post']['start']),'Next rendered selection form stays free of a page offset');
                if($change==='forum'){
                    cn_check($result['date']===$stamp,'Forum-only form submission preserves the exact selected timestamp');
                    cn_check(cn_day_window($result)===cn_day_window($original),'Forum-only selection preserves its real day/hour interval, including repeated/half-hour DST slots');
                    cn_check($selected['post']['selected_id']==='f7','New forum remains selected in the next rendered form');
                }else{
                    $target=new DateTimeImmutable($post['start_year'].'-'.sprintf('%02d',(int)$post['start_month']).'-01 00:00:00',new DateTimeZone($vector[0]));
                    $target=$target->setDate((int)$post['start_year'],(int)$post['start_month'],min((int)$clock->format('j'),(int)$target->format('t')));
                    cn_check($result['date']===$target->getTimestamp(),'Month/year selection keeps the existing clamped-day behavior');
                }
                $selection_cases++;
            }}
            $selection_cases++;
        }}
    }
}
restore_error_handler();
echo 'Calendar selections: ',$selection_cases,' actual rendered-form/controller cases, ',($navigation_checks-$selection_checks_before)," assertions PASS; mixed pagination, new/cached selections, exact DST hours, EN/DE and canonical action.\n";
