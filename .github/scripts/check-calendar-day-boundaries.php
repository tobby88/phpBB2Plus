<?php
// Actual header, calendar links, search SQL and relative formatter. No forum
// configuration/bootstrap/database; DateTime supplies an independent oracle.
define('IN_PHPBB',true);define('POSTS_TABLE','fixture_posts');
$root=dirname(dirname(__DIR__)).'/phpBB2/';
require $root.'includes/functions.php';
define('IN_MINI_CAL',true);require $root.'includes/mini_cal/mini_cal_config.php';
function day_check($ok,$message){if(!$ok){throw new RuntimeException($message);}$GLOBALS['checks']++;}
function day_fragment($source,$start,$end,$include_end=false){
    $a=strpos($source,$start);$b=$a===false?false:strpos($source,$end,$a);
    day_check($a!==false&&$b>$a,'Actual day boundary source fragment');
    return substr($source,$a,$b-$a+($include_end?strlen($end):0));
}
function append_sid($url){return $url;}
// Freeze only the clock used by the real header. Its other calls resolve to
// the actual global production functions, including the shared day helper.
eval('namespace CalendarFixtureClock; function time(){return $GLOBALS["day_now"];}');
$checks=0;$cases=0;
$header=day_fragment(file_get_contents($root.'includes/page_header.php'),'$today_ary = explode(', 'unset($today_ary);',true);
$calendar=day_fragment(file_get_contents($root.'includes/mini_cal/mini_cal.php'),'$nix_mini_cal_today =','$mini_cal_day = (');
$search=day_fragment(file_get_contents($root.'search.php'),'$nix_tomorrow =','$show_results =');
error_reporting(E_ALL);
set_error_handler(function($severity,$message){throw new RuntimeException($message);});
$phpbb_root_path='./';$phpEx='php';
foreach(array('english','german')as$locale){
    $lang=array();require $root.'language/lang_'.$locale.'/lang_main.php';
    $lang_extend_admin=false;require $root.'language/lang_'.$locale.'/lang_extend_categories_hierarchy.php';
    $offsets=array_unique(array_merge(array_map('strval',array_keys($lang['tz'])),array('-9.5','12.75','14')));
    foreach(array('UTC','Europe/Berlin','Pacific/Auckland')as$server_zone){
        day_check(date_default_timezone_set($server_zone),'Explicit fixture server timezone');
        $summer=(int)date('I');
        foreach($offsets as$offset){
            // Preserve the existing formatter's current-server DST policy.
            // This check does not change stored times or invent named user zones.
            $seconds=(int)round(((float)$offset+$summer)*3600);
            $absolute=abs($seconds);$zone=new DateTimeZone(($seconds<0?'-':'+').sprintf('%02d:%02d',(int)floor($absolute/3600),(int)(($absolute%3600)/60)));
            foreach(array('1971-01-01','2000-02-29','2024-02-29','2026-03-29','2026-10-25','2037-12-31')as$date){
                $midnight=new DateTimeImmutable($date.' 00:00:00',$zone);$expected=$midnight->getTimestamp();
                $ymd=explode('-',$date);$year=(int)$ymd[0];$month=(int)$ymd[1];$day=(int)$ymd[2];
                day_check(phpbb_local_day_start($year,$month,$day,$offset)===$expected,'Whole local midnight including quarter-hour and negative offsets');
                $board_config=array('default_lang'=>$locale,'board_timezone'=>$offset,'default_timeformat'=>'H:i');
                // Exact midnight, then late local night, also when UTC is in a
                // different month/year. Real header must choose the local date.
                foreach(array(0,86399)as$since_midnight){
                    $day_now=$expected+$since_midnight;eval('namespace CalendarFixtureClock;'.$header);
                    day_check($board_config['time_today']===$expected&&$board_config['time_yesterday']===$expected-86400,
                        'Real header keeps the complete local today/yesterday interval');
                }
                foreach(array(-86401,-86400,-1,0,1,900,86399,86400,86401,172800)as$delta){
                    $timestamp=$expected+$delta;$local=(new DateTimeImmutable('@'.$timestamp))->setTimezone($zone);
                    if($delta>=0&&$delta<86400){$display=sprintf($lang['Today_at'],$local->format('H:i'));}
                    elseif($delta>=-86400&&$delta<0){$display=sprintf($lang['Yesterday_at'],$local->format('H:i'));}
                    else{$display=$local->format('Y-m-d H:i');}
                    day_check(create_date_day('Y-m-d H:i',$timestamp,$offset)===$display,
                        'Actual translated relative formatter has inclusive midnight and bounded today/yesterday');
                }
                $mini_cal_this_year=$year;$mini_cal_this_month=$month;$mini_cal_this_day=$day;$mini_cal_day=(string)$day;
                eval($calendar);
                day_check($nix_mini_cal_today===$expected,'Actual mini-calendar timestamp has the correct sign and all minutes');
                day_check(preg_match('/href="([^"]+)"/',$mini_cal_day_link,$match)===1,'Actual date link');
                $url=html_entity_decode($match[1],ENT_QUOTES,'UTF-8');parse_str(parse_url($url,PHP_URL_QUERY),$params);
                day_check($params===array('search_id'=>'mini_cal','d'=>(string)$expected),'Existing timestamp URL contract');
                $search_date=(int)$params['d'];eval($search);
                day_check($nix_tomorrow===$expected+86400,'Actual search covers the full 24-hour fixed-offset local day');
                day_check(preg_match('/WHERE\s+post_time >= '.preg_quote((string)$expected,'/').'\s+AND post_time < '.preg_quote((string)($expected+86400),'/').'\s*$/D',$sql)===1,
                    'Actual generated search SQL includes the first second and excludes the next day');
                $cases++;
            }
        }
    }
}
echo 'Calendar day boundaries: '.$cases.' actual EN/DE header/link/search/formatter cases, '.$checks." assertions passed without warnings.\n";
