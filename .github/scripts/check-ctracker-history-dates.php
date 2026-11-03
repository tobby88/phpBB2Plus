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
restore_error_handler();echo 'CrackerTracker history dates: ',$locale,' ',$cases," actual-output cases PASS; user offsets, calendar boundaries, translated formats and existing DST policy.\n";
