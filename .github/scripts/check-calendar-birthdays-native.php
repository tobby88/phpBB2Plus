<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_CALENDAR_BIRTHDAYS_NATIVE')!=='1'){echo "Native calendar birthdays fixture skipped.\n";return;}
define('IN_PHPBB',true);define('ANONYMOUS',-1);define('USERS_TABLE','fixture_users');define('POST_USERS_URL','u');define('POST_BIRTHDAY',91);define('GENERAL_ERROR',2);
$app=dirname(dirname(__DIR__)).'/phpBB2/';
require$app.'includes/php_compat.php';require$app.'db/mysqli.php';
require dirname(__DIR__).'/fixtures/calendar-birthday-functions.php';
function append_sid($url){return$url;}
function cn_check($ok,$message){if(!$ok){throw new RuntimeException($message);}$GLOBALS['checks']++;}
function cn_sql($sql){$result=$GLOBALS['admin']->sql_query($sql);if(!$result){throw new RuntimeException('Own calendar fixture setup SQL failed');}return$result;}
$port=getenv('PHPBB_CALENDAR_BIRTHDAYS_PORT')?:'3306';
if(!preg_match('/^[0-9]{1,5}$/D',$port)||(int)$port<1||(int)$port>65535){throw new RuntimeException('Invalid disposable database port');}
$password=getenv('PHPBB_CALENDAR_BIRTHDAYS_PASSWORD')?:'';
$admin=new sql_db('127.0.0.1:'.$port,'root',$password,'',false);
if(!$admin->db_connect_id){throw new RuntimeException('Local disposable database unavailable');}
$schema='phpbb_calendar_birthdays_'.bin2hex(phpbb_random_bytes(6));$db=null;$created=false;$checks=0;$cases=0;
try{
 cn_sql('CREATE DATABASE `'.$schema.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$created=true;
 cn_sql('CREATE TABLE `'.$schema.'`.fixture_users(user_id INT NOT NULL PRIMARY KEY,username VARCHAR(255) NOT NULL,user_birthday INT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
 foreach(array(array(-1,'Anonymous',mkrealdate(3,11,1989)),array(1,'Änne',mkrealdate(3,11,1989)),array(2,"O\'Reilly <b>quoted</b>",mkrealdate(3,11,1940)),array(3,'Unset',999999),array(4,'Epoch',0),array(5,'Leap',mkrealdate(29,2,1992)),array(6,'Old PCP format',19891103))as$row){
  cn_sql('INSERT INTO `'.$schema.'`.fixture_users VALUES('.(int)$row[0].",'".$admin->sql_escape($row[1])."',".(int)$row[2].')');
 }
 $db=new sql_db('127.0.0.1:'.$port,'root',$password,$schema,false);cn_check((bool)$db->db_connect_id,'Own calendar database connection');
 $phpbb_root_path='./';$phpEx='php';$images=array('icon_birthday'=>'birthday.gif','icon_tiny_profile'=>'profile.gif');
 $board_config=array('calendar_birthday'=>1,'topics_per_page'=>1,'default_lang'=>'english');$userdata=array('user_id'=>1,'session_logged_in'=>true);
 date_default_timezone_set('UTC');set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
 foreach(array('english','german')as$locale){
  $board_config['default_lang']=$locale;$lang=array();$lang_extend_admin=false;require$app.'language/lang_'.$locale.'/lang_main.php';require$app.'language/lang_'.$locale.'/lang_extend_topic_calendar.php';
  foreach(array(array(11,3,2026,2),array(11,4,2026,0),array(1,1,2026,1),array(2,29,2024,1),array(3,1,2025,1))as$date){
   $a=mktime(0,0,0,$date[0],$date[1],$date[2]);$b=mktime(0,0,0,$date[0],$date[1]+1,$date[2]);$events=array();$number=-1;
   get_birthday($events,$number,$a,$b);cn_check(count($events)===$date[3]&&$number===$date[3],'Actual MariaDB birthday selection/range differs');$cases++;
  }
  $a=mktime(0,0,0,11,3,2026);$b=$a+86400;$all=array();$number=0;get_birthday($all,$number,$a,$b);
  foreach(array(0,1,2)as$start){$events=array();$count=-1;get_birthday($events,$count,$a,$b,true,$start,1);cn_check($events===array_slice($all,$start,1)&&$count===2,'Actual driver birthday pagination/count differs');$cases++;}
  $before=$db->num_queries;$userdata['session_logged_in']=false;$events=array();$number=-1;get_birthday($events,$number,$a,$b);
  cn_check($events===array()&&$number===0&&$db->num_queries===$before,'Guest must not query the native user table');$userdata['session_logged_in']=true;$cases++;
 }
 restore_error_handler();
 $r=cn_sql('SELECT COUNT(*) AS n FROM `'.$schema.'`.fixture_users');$row=$admin->sql_fetchrow($r);$admin->sql_freeresult($r);cn_check((int)$row['n']===7,'Read-only collector changed fixture rows');
 echo 'Native calendar birthdays: ',$cases,' actual collector cases, ',$checks," assertions PASS; MariaDB/InnoDB, production mysqli driver, EN/DE, ranges, privacy and paging.\n";
}finally{
 if($db){$db->sql_close();}
 if($created){if(!preg_match('/^phpbb_calendar_birthdays_[a-f0-9]{12}$/D',$schema)){throw new RuntimeException('Own fixture schema identity invalid');}cn_sql('DROP DATABASE `'.$schema.'`');}
 $admin->sql_close();
}
