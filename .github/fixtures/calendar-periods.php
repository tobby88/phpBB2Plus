<?php
namespace CalendarPeriodFixture;
if(PHP_SAPI!=='cli'){exit(2);}
$root=dirname(dirname(__DIR__)).'/phpBB2/';
require_once $root.'includes/functions_calendar_posting.php';
foreach(array('POST_USERS_URL'=>'u','POST_BIRTHDAY'=>91)as$name=>$value){if(!defined($name)){define($name,$value);}}
function actual($source,$name){if(preg_match('/^function '.preg_quote($name,'/').'\(/m',$source,$match,PREG_OFFSET_CAPTURE)!==1){throw new \RuntimeException('Actual calendar period function missing');}$body='';$depth=0;$started=false;foreach(token_get_all('<?php '.substr($source,$match[0][1]))as$token){if(is_array($token)&&$token[0]===T_OPEN_TAG){continue;}if($token==='{'){$depth++;$started=true;}if($token==='}'){$depth--;}$body.=is_array($token)?$token[1]:$token;if($started&&$depth===0){break;}}return$body;}
$source=file_get_contents($root.'includes/functions_calendar.php');foreach(array('calendar_date_clock','calendar_event_message_attribute','date_dsp','get_calendar_title_date')as$name){eval('namespace CalendarPeriodFixture;'.actual($source,$name));}
function check($ok,$message){if(!$ok){throw new \RuntimeException($message);}$GLOBALS['period_checks']++;}
$period_checks=0;$period_count=0;
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new \RuntimeException($message);}});
try{
foreach(array('english','german','english')as$locale){$lang=array();$lang_extend_admin=false;require$root.'language/lang_'.$locale.'/lang_main.php';require$root.'language/lang_'.$locale.'/lang_extend_topic_calendar.php';
    foreach(array('Y-m-d H:i:s','U','D, d M Y H:i')as$format){$board_config=array('default_lang'=>$locale,'default_dateformat'=>$format);
        foreach(array(
            array('UTC','2026-11-03 00:30:00',0,true),array('UTC','2026-11-03 00:30:00',1799,true),
            array('UTC','2026-11-03 00:30:00',3599,true),array('UTC','2026-11-03 01:00:00',2147483647,true),
            array('Europe/Berlin','2026-11-03 01:00:00',2147483647,true),
            array('Europe/Berlin','2026-03-29 00:00:00',82799,false),array('Europe/Berlin','2026-10-25 00:00:00',89999,false),
            array('America/Sao_Paulo','2018-11-04 00:00:00','civil-day',false),array('America/Sao_Paulo','2018-11-04 01:00:00',3599,true),
            array('Asia/Kathmandu','1986-01-01 00:15:00',85499,false),array('UTC','2026-11-03 10:00:00',0,true),
            array('UTC','2026-11-03 00:00:00',0,false),array('UTC','2026-11-03 00:00:00',2147483647,false)
        )as$case){
            date_default_timezone_set($case[0]);$first=new \DateTimeImmutable($case[1],new \DateTimeZone($case[0]));$stamp=$first->getTimestamp();
            if($case[2]==='civil-day'){
                // Older timezone data predates this Brazilian rule change.
                // Use the installed runtime's actual civil-day reference.
                $y=(int)$first->format('Y');$m=(int)$first->format('n');$d=(int)$first->format('j');$stamp=mktime(0,0,0,$m,$d,$y);$first=(new \DateTimeImmutable('@'.$stamp))->setTimezone(new \DateTimeZone($case[0]));$case[2]=mktime(0,0,0,$m,$d+1,$y)-$stamp-1;
            }
            $last=(new \DateTimeImmutable('@'.$stamp))->modify(sprintf('%+d seconds',$case[2]))->setTimezone(new \DateTimeZone($case[0]));$selected=$case[3]?$format:$lang['DATE_FORMAT'];$a=$first->format($selected);$b=$last->format($selected);if($locale!=='english'){$a=strtr($a,$lang['datetime']);$b=strtr($b,$lang['datetime']);}$expected=$case[2]===0?sprintf($lang['Calendar_time'],$a):sprintf($lang['Calendar_from_to'],$a,$b);
            $actual=get_calendar_title_date($stamp,$case[2]);check($actual===$expected,'Actual calendar period must retain the complete time/date and current locale: '.json_encode(array($locale,$format,$case,$expected,$actual)));
            check(date_dsp($format,$stamp)===date_dsp($format,$first),'Existing numeric dates and equivalent local objects must agree');
            $mutable=\DateTime::createFromFormat('!Y-m-d H:i:s',$case[1],new \DateTimeZone($case[0]));$before=$mutable->format('U P');$text=date_dsp($format,$mutable);check($text===date_dsp($format,$first)&&$mutable->format('U P')===$before,'Formatting a mutable date must not change it');$period_count++;
        }
    }
    check(get_calendar_title_date(0,2147483647)==='','An absent calendar event remains absent');$period_count++;
}
$raw='<table class="forumline"><tr><td title="Ä😀 > &amp;">Literal &lt;b&gt; &quot;quote&quot; O&#039;Reilly</td></tr></table>';
foreach(array(array('t42',0,$raw,$raw),array('t42',POST_BIRTHDAY,$raw,$raw),array('u8',POST_BIRTHDAY,htmlspecialchars($raw,ENT_QUOTES,'UTF-8'),$raw))as$event){$attribute=calendar_event_message_attribute(array('event_id'=>$event[0],'event_type'=>$event[1],'event_message'=>$event[2]));check(strpos($attribute,'"')===false&&strpos($attribute,"'")===false&&strpos($attribute,'<')===false,'Preview HTML must not terminate its quoted attribute');check(html_entity_decode($attribute,ENT_QUOTES,'UTF-8')===$event[3],'Attribute decoding must recover the original rendered HTML exactly once');$period_count++;}
}finally{restore_error_handler();}
echo 'Calendar periods: ',$period_count,' actual-formatter/attribute cases, ',$period_checks," assertions PASS; numeric/object dates, wide ends, minute-only times, locales and original preview markup.\n";
