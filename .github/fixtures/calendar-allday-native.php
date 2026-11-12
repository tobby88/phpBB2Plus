<?php
namespace CalendarAllDayFixture;
if(PHP_SAPI!=='cli'||!isset($schema)||preg_match('/^codex_full_editor_[a-f0-9]{16}$/D',$schema)!==1){throw new \RuntimeException('Own all-day calendar schema required');}
error_reporting(E_ALL);
require_once $phpbb_root_path.'includes/template.php';
function extract_function($source,$name){
    if(preg_match('/^function '.preg_quote($name,'/').'\(/m',$source,$declaration,PREG_OFFSET_CAPTURE)!==1){throw new \RuntimeException('Actual calendar dependency missing: '.$name);}$start=$declaration[0][1];$body='';$depth=0;$begun=false;
    foreach(token_get_all('<?php '.substr($source,$start))as$token){if(is_array($token)&&$token[0]===T_OPEN_TAG){continue;}if($token==='{'){$depth++;$begun=true;}if($token==='}'){$depth--;}$body.=is_array($token)?$token[1]:$token;if($begun&&$depth===0){break;}}return$body;
}
function fragment($source,$first,$last){$a=strpos($source,$first);$b=$a===false?false:strpos($source,$last,$a);if($a===false||$b===false){throw new \RuntimeException('Actual all-day posting branch missing');}return substr($source,$a,$b-$a);}
function auth($kind,$scope,$user){return array(3=>array('auth_read'=>true));}
function get_icon_title($icon,$unused=0,$kind=-1){return '';}
$ad_core=file_get_contents($phpbb_root_path.'includes/functions.php');
foreach(array('phpbb_profile_text','phpbb_stored_text','phpbb_text_excerpt','phpbb_date_number','phpbb_date_integer_sum','phpbb_date_timestamp','phpbb_date_text','create_date')as$name){eval('namespace CalendarAllDayFixture;'.extract_function($ad_core,$name));}
// Template::load_config uses the global path helper, not the date adapter.
if(!function_exists('phpbb_realpath')){eval('namespace {'.extract_function($ad_core,'phpbb_realpath').'}');}
$ad_calendar=file_get_contents($phpbb_root_path.'includes/functions_calendar.php');
foreach(array('calendar_normalize_forum_filter','calendar_html_excerpt','calendar_topic_event','get_event_topics','calendar_date_clock','get_calendar_title_date','date_dsp')as$name){eval('namespace CalendarAllDayFixture;'.extract_function($ad_calendar,$name));}
$ad_posting=file_get_contents($phpbb_root_path.'posting.php');
$ad_parser=fragment($ad_posting,'$calendar_input = calendar_post_input($_POST);','//-- fin mod : calendar');
$ad_resolve=fragment($ad_posting,'// Resolve calendar fields after loading the saved event',"//\n// Set toggles for various options");
$ad_form=fragment($ad_posting,"if ( \$mode == 'newtopic' || ( \$mode == 'editpost' && \$post_data['first_post'] ) )\n{\n\tif( \$is_auth['auth_cal'])",'//-- fin mod : calendar');
if(preg_match('/submit_post\(\$mode,[^\n]+\);/',$ad_posting,$match)!==1){throw new \RuntimeException('Actual all-day submit call missing');}$ad_call=$match[0];
function template(){
    global $phpbb_root_path,$board_config,$theme;
    $board_config+=array('xs_use_cache'=>0,'xs_auto_compile'=>0,'xs_auto_recompile'=>0,'version'=>'.0.23');$theme=array('template_name'=>'fisubsilversh','td_class1'=>'row1','td_class2'=>'row2');
    $template=(new \ReflectionClass('Template'))->newInstanceWithoutConstructor();$template->vars=&$template->_tpldata['.'][0];$template->load_config($phpbb_root_path.'templates/fisubsilversh',false);return$template;
}
function post($date,$days,$hour=0){$parts=explode('-',$date);return array('topic_calendar_year'=>$parts[0],'topic_calendar_month'=>$parts[1],'topic_calendar_day'=>$parts[2],'topic_calendar_hour'=>(string)$hour,'topic_calendar_min'=>'0','topic_calendar_duration_day'=>(string)$days,'topic_calendar_duration_hour'=>'0','topic_calendar_duration_min'=>'0');}
function reference_end($clock,$days){$noon=$clock->setTime(12,0,0);$target=$noon->setDate((int)$noon->format('Y'),(int)$noon->format('n'),(int)$noon->format('j')+$days);$normalized=(new \DateTimeImmutable('@'.$target->getTimestamp()))->setTimezone($clock->getTimezone());return new \DateTimeImmutable($normalized->format('Y-m-d').' 00:00:00',$clock->getTimezone());}
function controller($input,$topic=0,$post=0,$form_only=false){
    global $ad_parser,$ad_resolve,$ad_form,$ad_call,$template,$lang,$userdata;
    $mode=$topic?'editpost':'newtopic';$_POST=$input;$post_data=array('first_post'=>true,'last_post'=>true,'poster_post'=>true,'poster_id'=>8,'has_poll'=>false,'edit_poll'=>false);
    if($topic){$saved=\ae_rows('SELECT topic_calendar_time,topic_calendar_duration FROM fixture_topics WHERE topic_id='.(int)$topic)[0];$post_data['topic_calendar_time']=(int)$saved['topic_calendar_time'];$post_data['topic_calendar_duration']=(int)$saved['topic_calendar_duration'];if($post_data['topic_calendar_duration']){$post_data['topic_calendar_duration']++;}}
    $is_auth=array('auth_cal'=>true);$submit=!$form_only;$refresh=false;$error_msg='';eval($ad_parser);eval($ad_resolve);
    if($error_msg!==''){throw new \RuntimeException('Unexpected all-day controller error: '.$error_msg);}
    if($form_only){
        $template=template();$template->set_filenames(array('body'=>'posting_body.tpl'));eval($ad_form);
        $template->assign_vars(array('S_CALENDAR_YEAR'=>$s_topic_calendar_year,'S_CALENDAR_MONTH'=>$s_topic_calendar_month,'S_CALENDAR_DAY'=>$s_topic_calendar_day,'CALENDAR_HOUR'=>$topic_calendar_hour,'CALENDAR_MIN'=>$topic_calendar_min,'CALENDAR_DURATION_DAY'=>$topic_calendar_duration_day,'CALENDAR_DURATION_HOUR'=>$topic_calendar_duration_hour,'CALENDAR_DURATION_MIN'=>$topic_calendar_duration_min));
        ob_start();try{$template->pparse('body');}finally{$html=ob_get_clean();}$next=array();
        preg_match_all('/<input\b[^>]*name="(topic_calendar_[^"]+)"[^>]*value="([^"]*)"/s',$html,$inputs,PREG_SET_ORDER);foreach($inputs as$field){$next[$field[1]]=html_entity_decode($field[2],ENT_QUOTES,'UTF-8');}
        preg_match_all('/<select\b[^>]*name="(topic_calendar_[^"]+)"[^>]*>(.*?)<\/select>/s',$html,$selects,PREG_SET_ORDER);foreach($selects as$field){\ae_check(preg_match('/<option\b[^>]*value="([^"]*)"[^>]*selected="selected"/s',$field[2],$selected)===1,'Saved all-day form must select every date component');$next[$field[1]]=$selected[1];}
        \ae_check(count($next)===8&&preg_match('//u',$html)===1,'Actual all-day form must render all eight controls');return$next;
    }
    $return_message=$return_meta=$username=$poll_title=$topic_desc='';$subject=addslashes('All-day calendar fixture');$message=addslashes('Calendar body remains unchanged.');$forum_id=3;$topic_id=$topic;$post_id=$post;$poll_id=0;$topic_type=POST_NORMAL;$bbcode_on=1;$html_on=$smilies_on=$attach_sig=0;$bbcode_uid='1234567890';$poll_options=array();$poll_length=$topic_announce_duration=$post_icon=$news_category=0;
    eval($ad_call);return array($topic_id,$post_id);
}
function collect($first,$last){global$template,$db;$before=\fe_snapshot();$offset=count($GLOBALS['ae_queries']);$template=template();$events=array();$number=0;get_event_topics($events,$number,$first,$last,true,0,100,'f3');\ae_check(\fe_snapshot()===$before,'Calendar view must not mutate any owned row');foreach(array_slice($GLOBALS['ae_queries'],$offset)as$sql){\ae_check(!preg_match('/^(INSERT|UPDATE|DELETE)\b/',$sql),'Actual calendar collector must remain read-only');}foreach($events as$event){\ae_check(preg_match('//u',$event['event_message'])===1,'Actual all-day overview template must retain UTF-8');}return$events;}
$ad_failures=array();$ad_cases=0;
foreach(array('english','german')as$locale){foreach(array(
    array('Europe/Berlin','2026-03-29',1,0),array('Europe/Berlin','2026-10-25',1,0),
    array('Australia/Lord_Howe','2026-10-04',1,0),array('Australia/Lord_Howe','2026-04-05',1,0),
    array('UTC','2026-03-29',1,0),array('Europe/Berlin','2026-03-28',3,0),
    array('Europe/Berlin','2026-10-24',3,0),array('Europe/Berlin','2026-03-28',1,10),
    array('Pacific/Apia','2011-12-29',1,0),array('Pacific/Apia','2011-12-29',3,0),
    array('America/Sao_Paulo','2018-11-03',1,0),array('Asia/Kathmandu','1985-12-31',1,0),
    array('Africa/Casablanca','2010-05-01',1,0),array('America/Sao_Paulo','2018-11-04',1,0),
    array('America/Sao_Paulo','2018-11-04',1,1)
)as$case){
    \ps_fixture();date_default_timezone_set($case[0]);$board_config['default_lang']=$locale;$board_config['topics_per_page']=100;$board_config['default_dateformat']='Y-m-d H:i';$userdata['user_dateformat']='Y-m-d H:i';$lang=array();$lang_extend_admin=false;require$phpbb_root_path.'language/lang_'.$locale.'/lang_main.php';require$phpbb_root_path.'language/lang_'.$locale.'/lang_extend_topic_calendar.php';$images=array('icon_calendar'=>'calendar.gif','icon_tiny_topic'=>'topic.gif');
    $clock=new \DateTimeImmutable($case[1].' '.sprintf('%02d',$case[3]).':00:00',new \DateTimeZone($case[0]));$expected_start=$clock->getTimestamp();$expected_end=$case[3]===0?reference_end($clock,$case[2])->getTimestamp():$expected_start+$case[2]*86400;
    list($topic,$post)=controller(post($case[1],$case[2],$case[3]));$saved=\ae_rows('SELECT topic_calendar_time,topic_calendar_duration FROM fixture_topics WHERE topic_id='.(int)$topic)[0];
    $whole=collect($expected_start,$expected_end);$last=collect($expected_end-60,$expected_end);$after=collect($expected_end,$expected_end+60);
    $format=$case[3]===0?$lang['DATE_FORMAT']:$board_config['default_dateformat'];$a=$clock->format($format);$b=(new \DateTimeImmutable('@'.($expected_end-1)))->setTimezone(new \DateTimeZone($case[0]))->format($format);if($locale!=='english'){$a=strtr($a,$lang['datetime']);$b=strtr($b,$lang['datetime']);}$title=sprintf($lang['Calendar_from_to'],$a,$b);
    $form=controller(array(),$topic,$post,true);controller($form,$topic,$post);$edited=\ae_rows('SELECT topic_calendar_time,topic_calendar_duration FROM fixture_topics WHERE topic_id='.(int)$topic)[0];
    $correct=(int)$saved['topic_calendar_time']===$expected_start&&(int)$saved['topic_calendar_duration']===$expected_end-$expected_start-1&&count($whole)===1&&strpos($whole[0]['event_message'],$title)!==false&&count($last)===1&&count($after)===0&&$edited===$saved&&(int)$form['topic_calendar_duration_day']===$case[2];
    if(!$correct){$ad_failures[]=array('locale'=>$locale,'zone'=>$case[0],'date'=>$case[1],'days'=>$case[2],'hour'=>$case[3],'stored_duration'=>(int)$saved['topic_calendar_duration'],'expected_duration'=>$expected_end-$expected_start-1,'last_minute_rows'=>count($last),'next_minute_rows'=>count($after),'form_days'=>$form['topic_calendar_duration_day'],'edited_duration'=>(int)$edited['topic_calendar_duration']);}$ad_cases++;
}}
if($ad_failures){throw new \RuntimeException('Actual all-day writer/SQL/calendar/form chain failed: '.json_encode($ad_failures));}
echo 'Native all-day calendar: ',$ad_cases," actual-controller/write/SQL/overview/form/re-edit cases PASS; EN/DE, civil days, 23/25-hour and half-hour changes, read-only views and timed controls.\n";
