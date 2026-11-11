<?php
define('IN_PHPBB',true);$app=dirname(dirname(__DIR__)).'/phpBB2/';
require$app.'includes/php_compat.php';require$app.'includes/functions.php';
$locale=isset($argv[1])?$argv[1]:'english';if(!in_array($locale,array('english','german'),true)){throw new RuntimeException('Known date locale required');}
$lang=array();require$app.'language/lang_'.$locale.'/lang_main.php';$board_config=array('default_lang'=>$locale);
class HistoryDateRows{private$seen=false;public$stamp;function __construct($stamp){$this->stamp=$stamp;}function sql_fetchrow($unused){if($this->seen){return false;}$this->seen=true;return array('ct_login_time'=>$this->stamp,'ct_login_ip'=>'192.0.2.1');}}
class HistoryDateTemplate{public$rows=array();function assign_block_vars($name,$row){$this->rows[]=$row;}}
$source=file_get_contents($app.'ct_login_history.php');$a=strpos($source,"\t\$count = 0;");$b=strpos($source,'// Output settings for Login Checker',$a);
if($a===false||$b===false){throw new RuntimeException('Actual history date boundary unavailable');}$body='if(true){'.substr($source,$a,$b-$a);$cases=0;
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
foreach(array('UTC','Europe/Berlin','Pacific/Auckland')as$server){date_default_timezone_set($server);foreach(array(-12,-3.5,0,1,5.75,12,14)as$offset){
 foreach(array(946684800,1700000000,1711846799,1735689599)as$stamp){foreach(array('Y-m-d H:i','l j F Y H:i')as$format){
  $board_config['board_timezone']=$offset;$userdata=array('user_dateformat'=>$format);$db=new HistoryDateRows($stamp);$template=new HistoryDateTemplate();$result=true;eval($body);
  $seconds=(int)round(3600*($offset+(int)date('I')));$date=(new DateTimeImmutable('@'.$stamp))->modify(($seconds>=0?'+':'').$seconds.' seconds');
  $text=$date->format($format);if($locale!=='english'){$text=strtr($text,$lang['datetime']);}
  $expected=htmlspecialchars($text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
  if($template->rows[0]['VALUE_2']!==$expected||$template->rows[0]['VALUE_2']!==phpbb_profile_text(create_date($format,$stamp,$offset))){throw new RuntimeException('Login history differs from the forum date and independent calendar oracle');}$cases++;
 }}
}}
echo 'CrackerTracker history dates: ',$locale,' ',$cases," actual-output cases PASS; user offsets, calendar boundaries, translated formats and existing DST policy.\n";

// Render the actual log-manager view too, including the writer's physical
// metadata framing. The fixture replaces only its separately native-tested
// authorization boundary; real date, escaping, log reader and controller run.
define('CTRACKER_ACP',true);if(!defined('GENERAL_ERROR')){define('GENERAL_ERROR',2);}
$phpEx='php';require$app.'ctracker/classes/class_log_manager.php';require __DIR__.'/ctracker-log-controller-fixture.php';require$app.'language/lang_'.$locale.'/lang_cback_ctracker.php';
function append_sid($url){return$url;}
$bootstrap=file_get_contents($app.'admin/pagestart.php');$a=strpos($bootstrap,"if (!function_exists('phpbb_admin_post_session_valid'))");$b=strpos($bootstrap,'if (empty($no_page_header))',$a);if($a===false||$b<=$a){throw new RuntimeException('Actual ACP output helpers unavailable');}eval(substr($bootstrap,$a,$b-$a));
class AcpLogDateTemplate {
 public$blocks=array();function set_filenames($files){}function assign_vars($vars){}function assign_block_vars($name,$row){$this->blocks[$name][]=$row;}function pparse($name){}
}
function acp_log_date_expected($format,$stamp,$offset){global$lang;$seconds=(int)round(3600*($offset+(int)date('I')));$text=(new DateTimeImmutable('@'.$stamp))->modify(($seconds>=0?'+':'').$seconds.' seconds')->format($format);return htmlspecialchars(strtr($text,$lang['datetime']),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function acp_log_date_case($server,$offset,$stamp,$format,$kind,$ending){
 global$path,$board_config,$template,$app,$lang,$images,$phpEx,$phpbb_root_path,$locale,$log_cases;
 date_default_timezone_set($server);$board_config['board_timezone']=$offset;$board_config['default_dateformat']=$format;$event=$stamp+86401;
 $header='1|||'.$stamp.'|||null|||null|||null|||null|||null'.($kind==='legacy'?'':'|||'.$kind);
 // An empty ending exercises an unterminated event; metadata still needs LF.
 $bytes=$header.($ending===''?"\n":$ending).'0|||'.$event.'|||Grüße & <event>|||referrer|||agent|||address|||host'.$ending;
 if(file_put_contents($path,$bytes)!==strlen($bytes)){throw new RuntimeException('Own complete date log unavailable');}$before=hash_file('sha256',$path);$_GET=array('mode'=>'view','logid'=>'5');$_POST=array();$template=new AcpLogDateTemplate();
 // Preserve the successful rendering path; rethrow controller failures so
 // this isolated view test reports their cause without a full error page.
 $controller=ctracker_log_fixture_source($app);$failure='message_die(GENERAL_ERROR, ctracker_log_error_message($error));';if(substr_count($controller,$failure)!==2){throw new RuntimeException('Actual ACP error boundary unavailable');}eval(str_replace($failure,'throw $error;',$controller));
 $caption=$kind==='legacy'?'ctracker_log_manager_legacy_marker':($kind==='created'?'ctracker_log_manager_initialized':'ctracker_log_manager_sysmsg');$want=sprintf($lang[$caption],acp_log_date_expected($format,$stamp,$offset));
 if(count($template->blocks['show_system_message'])!==1||$template->blocks['show_system_message'][0]['L_SYS_MSG']!==$want){throw new RuntimeException('Actual ACP metadata caption/date differs for '.$locale.'/'.$kind.'/'.$server);}
 if(count($template->blocks['show_log'])!==1||$template->blocks['show_log'][0]['L_OUTPUT_1']!==acp_log_date_expected($format,$event,$offset)||$template->blocks['show_log'][0]['L_OUTPUT_2']!=='Grüße &amp; &lt;event&gt;'){throw new RuntimeException('Actual ACP event date/escaping differs from the independent calendar');}
 if(hash_file('sha256',$path)!==$before){throw new RuntimeException('ACP date rendering changed original log bytes');}$log_cases++;
}
$base=sys_get_temp_dir().'/codex_ct_log_dates_'.bin2hex(phpbb_random_bytes(8));$made=false;$log_cases=0;$HTTP_SERVER_VARS=$HTTP_ENV_VARS=array();$phpEx='php';$images=array('ctracker_log_manager'=>'fixture.gif');$userdata['session_id']='log-date-fixture';
try{
 if(!mkdir($base,0700)||!mkdir($base.'/ctracker',0700)||!mkdir($base.'/ctracker/logfiles',0700)||!mkdir($base.'/cache',0700)){throw new RuntimeException('Own log date directories unavailable');}$made=true;$phpbb_root_path=$base.'/';$path=$base.'/ctracker/logfiles/logfile_spammer.txt';
 // Cover date and framing boundaries independently instead of multiplying
 // every orthogonal combination into thousands of equivalent file renders.
 $stamps=array(946684800,1700000000,1711846799,1735689599);$kinds=array('legacy','created','cleared');$endings=array('',"\n","\r\n");$index=0;
 foreach(array('UTC','Europe/Berlin','Pacific/Auckland')as$server){foreach(array(-12,-3.5,0,1,5.75,12,14)as$offset){foreach(array('Y-m-d H:i','l j F Y H:i','Y-m-d < H:i > "')as$format){acp_log_date_case($server,$offset,$stamps[$index%4],$format,$kinds[$index%3],$endings[$index%3]);$index++;}}}
 foreach($kinds as$kind){foreach($endings as$ending){acp_log_date_case('Europe/Berlin',5.75,1735689599,'l j F Y H:i',$kind,$ending);}}
 echo 'CrackerTracker ACP log dates: ',$locale,' ',$log_cases," actual-controller/metadata-kind/LF/CRLF/unterminated/timezone/translation/escaping/read-only cases PASS.\n";
}finally{restore_error_handler();if($made){$real=realpath($base);if(!$real||is_link($base)||dirname($real)!==realpath(sys_get_temp_dir())||!preg_match('/^codex_ct_log_dates_[a-f0-9]{16}$/D',basename($real))){throw new RuntimeException('Exact own date fixture cleanup required');}if(is_file($path)){unlink($path);}rmdir($base.'/cache');rmdir($base.'/ctracker/logfiles');rmdir($base.'/ctracker');rmdir($base);}}
