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

// Numeric/range behavior is separate from the ordinary calendar matrix.
// DateTime supplies the calendar oracle; bounded fractional vectors preserve
// the previous native arithmetic convention rather than changing timestamps.
function range_check($ok,$message){if(!$ok){throw new RuntimeException($message);}$GLOBALS['range_checks']++;}
class DateRangeStringProbe {function __toString(){$GLOBALS['range_string_calls']++;return'1700000000';}}
$range_checks=$range_cases=$range_string_calls=0;date_default_timezone_set('UTC');
foreach(array('english','german')as$locale){
 $lang=array();require$root.'language/lang_'.$locale.'/lang_main.php';$lang_extend_admin=false;require$root.'language/lang_'.$locale.'/lang_extend_categories_hierarchy.php';$board_config=array('default_lang'=>$locale,'default_timeformat'=>'H:i');$unknown=$lang['Unknown'];
 foreach(array(0,1,-1,946684800,1700000000,-2208988800)as$stamp){if(phpbb_date_number((string)$stamp)===null){continue;}foreach(array(0,-3.5,5.75,14,25)as$zone){$shift=(int)($zone*3600);$expected=(new DateTimeImmutable('@'.$stamp))->modify(($shift>=0?'+':'').$shift.' seconds')->format('l j F Y H:i:s');if($locale!=='english'){$expected=strtr($expected,$lang['datetime']);}
  foreach(array($stamp,(string)$stamp,($stamp>=0?'+':'-').'000'.ltrim((string)$stamp,'-'),(string)$stamp.'.000')as$input){range_check(create_date('l j F Y H:i:s',$input,$zone)===$expected,'Actual date retains ordinary/epoch/negative/numeric-string calendar values');$range_cases++;}
 }}
 foreach(array(array(1700000000.125,5.75),array('1.7e9','-3.5'),array(' 1700000000.25 ',0.000001),array(-0.9,0.00025),array(1700000000.1,-0.1/3600))as$case){$sum=(float)$case[0]+3600*(float)$case[1];$expected=(new DateTimeImmutable('@'.(int)$sum))->format('Y-m-d H:i:s');range_check(create_date('Y-m-d H:i:s',$case[0],$case[1])===$expected,'Supported fractional inputs keep existing native rounding/truncation');$range_cases++;}
 $min=-PHP_INT_MAX-1;$max=PHP_INT_MAX;
 foreach(array(array($max,0,$max),array((string)$max,0,$max),array((string)$max.'.000',0,$max),array($max,-3.5,$max-12600),array($min,5.75,$min+20700),array($max-50400,14,$max),array($min+43200,-12,$min))as$case){range_check(create_date('U',$case[0],$case[1])===(string)$case[2],'Integral boundary epochs and timezone shifts never pass through a lossy double');$range_cases++;}
 foreach(array(array($max,-0.5/3600,$max-1),array($min,0.5/3600,$min+1),array($max,0.5/3600,$max),array($min,-0.5/3600,$min))as$case){range_check(create_date('U',$case[0],$case[1])===(string)$case[2],'Fractional timezone seconds round toward zero without losing wide integer bits');$range_cases++;}
 if(PHP_INT_SIZE===4){foreach(array(array($max+0.75,$max),array($min-0.75,$min))as$case){range_check(create_date('U',$case[0],0)===(string)$case[1],'Representable whole seconds retain legacy fractional boundary coercion');$range_cases++;}}
 foreach(array(array($max,5.75),array($min,-3.5),array((string)$max.'0',0),array((string)$min.'0',0),array($max,PHP_INT_MAX),array(0,INF),array(0,NAN))as$case){range_check(create_date('U',$case[0],$case[1])===$unknown&&create_date_day('U',$case[0],$case[1])===$unknown,'Unrepresentable dates return translated Unknown without clamp, exception or relative label');$range_cases++;}
 foreach(array(null,false,true,'','bad','12tail','0x10','1e999',INF,-INF,NAN,array('1700000000'),new DateRangeStringProbe())as$bad){range_check(create_date('Y-m-d',$bad,0)===$unknown&&create_date_day('Y-m-d',$bad,0)===$unknown,'Invalid date inputs never become epoch-zero dates or Today labels');range_check(create_date('Y-m-d',1700000000,$bad)===$unknown,'Invalid timezone input cannot invent a default offset');$range_cases++;}
 foreach(array(null,array('Y'),new DateRangeStringProbe())as$bad){range_check(create_date($bad,1700000000,0)===$unknown&&create_date_day($bad,1700000000,0)===$unknown,'Invalid format types are contained without object coercion');$range_cases++;}
 $board_config['time_today']=$max-1;$board_config['time_yesterday']=$max-86401;range_check(create_date_day('U',$max,0)===sprintf($lang['Today_at'],gmdate('H:i',$max)),'Relative upper day boundary does not overflow');$range_cases++;
 foreach(array(null,false,'bad',array(1))as$bad){$board_config['time_today']=$bad;range_check(create_date_day('U',1700000000,0)==='1700000000','Unavailable relative boundary falls back to the actual absolute date');$range_cases++;}
}
// Locale changes inside one process must not reuse another language's cache.
foreach(array('english','german','english','german')as$locale){$lang=array();require$root.'language/lang_'.$locale.'/lang_main.php';$board_config['default_lang']=$locale;$raw=(new DateTimeImmutable('@1700000000'))->format('l j F Y');$expected=$locale==='english'?$raw:strtr($raw,$lang['datetime']);range_check(create_date('l j F Y',1700000000,0)===$expected,'Actual formatter cache follows the active language');$range_cases++;}
$lang['datetime']['Tuesday']='Synthetic weekday';range_check(strpos(create_date('l j F Y',1700000000,0),'Synthetic weekday')===0,'Changed translation dictionary is not retained as stale text');$range_cases++;
$lang['datetime']['Tuesday']=array('not-text');range_check(strpos(create_date('l j F Y',1700000000,0),'Tuesday')===0,'Malformed translation entry cannot crash an otherwise valid date');$range_cases++;
range_check($range_string_calls===0,'Date validation never invokes arbitrary object string conversions');
echo 'Forum date formatting: '.$range_cases.' cases, '.$range_checks." assertions PASS; exact integers, range errors, numeric types, fractional compatibility, locale cache and relative dates.\n";
