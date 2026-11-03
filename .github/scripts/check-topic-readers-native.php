<?php
// Full actual listing controller against a new, disposable MariaDB schema.
if(PHP_SAPI!=='cli'||getenv('PHPBB_TOPIC_READERS_NATIVE')!=='1'){echo "Native topic readers fixture skipped.\n";return;}
define('IN_PHPBB',true);define('ANONYMOUS',-1);define('ADMIN',1);define('POST_USERS_URL','u');define('POST_TOPIC_URL','t');define('USERS_TABLE','fixture_users');define('TOPIC_VIEW_TABLE','fixture_topic_view');
$app=dirname(dirname(__DIR__)).'/phpBB2/';require$app.'includes/php_compat.php';require$app.'includes/functions.php';require$app.'db/mysqli.php';
function append_sid($url){return$url;}
function color_group_colorize_name($id,$plain=false){return'fixture-user-'.(int)$id;}
function tr_check($ok,$message){if(!$ok){throw new RuntimeException($message);}$GLOBALS['checks']++;}
class TopicReadersTemplate {
 var $vars=array();var $rows=array();var $parsed=array();var $files=array();
 function assign_vars($vars){$this->vars=array_merge($this->vars,$vars);}function assign_block_vars($block,$row){tr_check($block==='memberrow','Actual member row block');$this->rows[]=$row;}
 function set_filenames($files){$this->files=$files;}function pparse($name){$this->parsed[]=$name;}
}
function tr_setup($sql){$r=$GLOBALS['admin']->sql_query($sql);if(!$r){throw new RuntimeException('Own fixture SQL failed');}return$r;}
function tr_snapshot($db){$data=array();foreach(array(USERS_TABLE,TOPIC_VIEW_TABLE)as$table){$r=$db->sql_query('SELECT * FROM '.$table.' ORDER BY '.($table===USERS_TABLE?'user_id':'topic_id,user_id'));if(!$r){throw new RuntimeException('Own snapshot failed');}$data[$table]=$db->sql_fetchrowset($r);$db->sql_freeresult($r);}return serialize($data);}
$source=file_get_contents($app.'topic_view_users.php');$a=strpos($source,'$start = max(');$b=strpos($source,"\$template->pparse('body');",$a);
if($a===false||$b==false){throw new RuntimeException('Actual topic readers controller boundaries required');}
$body=substr($source,$a,$b+strlen("\$template->pparse('body');")-$a);
$body=str_replace("include(\$phpbb_root_path . 'includes/page_header.'.\$phpEx);",'',$body,$headers);
$body=str_replace("make_jumpbox('viewforum.'.\$phpEx);",'',$body,$jumpboxes);
if($headers!==1||$jumpboxes!==1){throw new RuntimeException('Only surrounding header/jumpbox may be replaced');}
$port=getenv('PHPBB_TOPIC_READERS_PORT')?:'3306';if(!preg_match('/^[0-9]{1,5}$/D',$port)||(int)$port<1||(int)$port>65535){throw new RuntimeException('Disposable port required');}
$password=getenv('PHPBB_TOPIC_READERS_PASSWORD')?:'';$admin=new sql_db('127.0.0.1:'.$port,'root',$password,'',false);if(!$admin->db_connect_id){throw new RuntimeException('Local disposable MariaDB unavailable');}
$schema='phpbb_topic_readers_'.bin2hex(phpbb_random_bytes(6));$created=false;$db=null;$checks=0;$cases=0;
try{
 tr_setup('CREATE DATABASE `'.$schema.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$created=true;
 $columns=array('user_id INT PRIMARY KEY','username VARCHAR(255)','user_website VARCHAR(255)','user_email VARCHAR(255)','user_from VARCHAR(255)','user_avatar VARCHAR(255)','user_icq VARCHAR(255)','user_aim VARCHAR(255)','user_yim VARCHAR(255)','user_msnm VARCHAR(255)');
 foreach(array('user_level','user_viewemail','user_posts','user_regdate','user_avatar_type','user_allowavatar','user_allow_viewonline','user_session_time')as$column){$columns[]=$column.' INT NOT NULL DEFAULT 0';}
 tr_setup('CREATE TABLE `'.$schema.'`.fixture_users('.implode(',',$columns).') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
 tr_setup('CREATE TABLE `'.$schema.'`.fixture_topic_view(topic_id INT,user_id INT,view_time INT,view_count INT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
 $ids=array_merge(array(-1),range(1,12));foreach($ids as$id){tr_setup('INSERT INTO `'.$schema.'`.fixture_users(user_id,username,user_website,user_email,user_from,user_avatar,user_icq,user_aim,user_yim,user_msnm,user_regdate,user_allow_viewonline) VALUES('.$id.",'Grüße ".$id."','','','','','','','','',".(100000+$id).',1)');tr_setup('INSERT INTO `'.$schema.'`.fixture_topic_view VALUES(1,'.$id.',1700000000,1)');}
 foreach(array(array(1,999),array(1,1000),array(3,1),array(3,3),array(3,999),array(4,999))as$view){tr_setup('INSERT INTO `'.$schema.'`.fixture_topic_view VALUES('.$view[0].','.$view[1].',1700000000,1)');}
 $db=new sql_db('127.0.0.1:'.$port,'root',$password,$schema,false);tr_check((bool)$db->db_connect_id,'Own fixture connection');$snapshot=tr_snapshot($db);
 $phpEx='php';$phpbb_root_path='./';$theme=array('td_class1'=>'row1','td_class2'=>'row2','td_color1'=>'eee','td_color2'=>'fff');$userdata=array('user_id'=>2,'user_level'=>0,'session_logged_in'=>true);$images=array();foreach(array('email','profile','pm','www','search','gallery','online','offline','hidden')as$icon){$images['icon_'.$icon]=$icon.'.gif';}
 error_reporting(E_ALL);set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
 foreach(array('english','german')as$locale){$lang=array();require$app.'language/lang_'.$locale.'/lang_main.php';
  foreach(array(1=>13,2=>0,3=>2,4=>0)as$topic_id=>$visible){foreach(array('joined','topten')as$requestedMode){foreach(array(5,20)as$pageSize){foreach(array(0,5,1000000)as$requestedStart){
   $_GET=array('start'=>(string)$requestedStart,'mode'=>$requestedMode,'order'=>'ASC');$_POST=array();$board_config=array('topics_per_page'=>$pageSize,'board_timezone'=>0,'default_lang'=>$locale,'default_dateformat'=>'Y-m-d','board_email_form'=>0);$template=new TopicReadersTemplate();$beforeQueries=$db->num_queries;
   eval($body);
   $expectedStart=$requestedMode==='topten'?0:min($requestedStart,max(0,(int)floor(($visible-1)/$pageSize)*$pageSize));$limit=$requestedMode==='topten'?10:$pageSize;$expectedRows=min($limit,max(0,$visible-$expectedStart));$total=$requestedMode==='topten'?min(10,$visible):$visible;
   tr_check((int)$total_members===$total,'Visible reader count must exclude missing user accounts; topic '.$topic_id.' mode '.$requestedMode);
   tr_check($start===$expectedStart&&count($template->rows)===$expectedRows,'Actual list offset/row count must match normalized pagination');
   tr_check($template->vars['PAGE_NUMBER']===sprintf($lang['Page_of'],(int)floor($expectedStart/$limit)+1,max(1,(int)ceil($total/$limit))),'Empty/out-of-range/top-ten pages must have a consistent page label');
   if($template->rows){tr_check($template->rows[0]['ROW_NUMBER']===$expectedStart+1,'Actual row numbering uses the selected page');}
   tr_check($template->files===array('body'=>'topic_view_body.tpl')&&$template->parsed===array('body'),'Actual reader template rendered once');
   tr_check($db->num_queries-$beforeQueries===2,'Exactly one count and one bounded reader selection');
   tr_check(strpos($template->vars['PAGINATION'],'start=15')===false,'Deleted accounts must not create phantom pages');
   tr_check($requestedMode!=='topten'||trim(str_replace('&nbsp;','',$template->vars['PAGINATION']))==='','Top-ten result has no later-page navigation');$cases++;
  }}}}
 }
 restore_error_handler();tr_check(tr_snapshot($db)===$snapshot,'Actual listing controller must not mutate users or view history');
 echo 'Native topic readers: ',$cases,' actual EN/DE controller cases, ',$checks," assertions PASS; MariaDB/InnoDB, production mysqli driver, orphan history, empty lists, offsets and top-ten pagination.\n";
}finally{if($db){$db->sql_close();}if($created){if(!preg_match('/^phpbb_topic_readers_[a-f0-9]{12}$/D',$schema)){throw new RuntimeException('Own disposable schema required');}tr_setup('DROP DATABASE `'.$schema.'`');}$admin->sql_close();}
