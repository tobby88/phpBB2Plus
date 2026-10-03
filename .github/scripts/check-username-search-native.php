<?php
putenv('PHPBB_ATTACH_SETTINGS_NATIVE=0'); require __DIR__.'/check-attachment-settings-storage.php';
define('ANONYMOUS',-1); define('POSTS_TABLE','fixture_posts');
require __DIR__.'/profile-request-fixture.php';
require $ats_source.'includes/functions_search.php';
ats_load_function($ats_source.'ajax.php','ajax_scalar_value');
ats_load_function($ats_source.'ajax.php','ajax_request_value');
ats_load_function($ats_source.'ajax.php','ajax_request_int');
foreach(array('AJAX_PM_USERNAME_FOUND'=>101,'AJAX_PM_USERNAME_ERROR'=>102,'AJAX_OP_COMPLETED'=>103,'AJAX_PM_USERNAME_SELECT'=>104)as$key=>$value){define($key,$value);}
class UsernameSearchExit extends RuntimeException {var $response;function __construct($response){$this->response=$response;}}
function AJAX_message_die($response){throw new UsernameSearchExit($response);}
ats_check(phpbb_username_search_allowed('ä*',1)&&!phpbb_username_search_allowed('ä*',2),'Wildcard minimum counts Unicode characters, not bytes');
ats_check(phpbb_username_search_allowed('0',3)&&phpbb_username_search_allowed('%',3),'Literal short names remain searchable');
foreach(array('',array('bad'),str_repeat('ä',26),str_repeat('😀',26),"bad\xc3","bad\0name")as$bad){ats_check(!phpbb_username_search_allowed($bad,1),'Malformed/oversized search denied');}
$source=file_get_contents($ats_source.'search.php');$a=strpos($source,'$search_author_value =');$b=strpos($source,'$search_id =',$a);ats_check($a!==false&&$b>$a,'Actual author request parser');$request_parser=substr($source,$a,$b-$a);
foreach(array('C@ro','0','A&B',"O'Reilly",'C:\\notes','A_*')as$raw){profile_fixture_request(array('search_author'=>$raw));$board_config=array('search_min_chars'=>1);eval($request_parser);ats_check($search_author===$raw,'Actual author parser decodes request once');}
foreach(array(array('bad'),str_repeat('ä',26),"bad\xc3","bad\0name",'ä*','*')as$bad){profile_fixture_request(array('search_author'=>$bad));$board_config=array('search_min_chars'=>2);$denied=false;try{eval($request_parser);}catch(AttachSettingsExit$e){$denied=true;}ats_check($denied,'Invalid author never falls through to unrestricted keyword search');}
// Run the actual rendered-link expressions, not a reimplementation of them.
foreach(array('viewtopic.php','topic_view_users.php','includes/usercp_viewprofile.php','groupcp.php','privmsg.php','memberlist.php','shoutbox_max.php')as$file){
 $source=file_get_contents($ats_source.$file);preg_match_all('/append_sid\("search\.\$phpEx\?search_author=" .*? "&amp;showresults=(?:posts|topics)"\)/',$source,$links);ats_check(count($links[0])>0,'Actual author link '.$file);
 foreach(array('A&B',"O'Reilly",'&#39;','C:\\notes','😀')as$raw){$key=phpbb_username_key($raw);$username=$username_from=$shout_username=$key;$row=$profiledata=array('username'=>$key);$postrow=array($row);$i=0;$phpEx='php';
  foreach($links[0]as$expression){eval('$url='.$expression.';');parse_str(str_replace('&amp;','&',parse_url($url,PHP_URL_QUERY)),$parameters);ats_check($parameters['search_author']===$raw,'Actual author link transmits decoded identity once '.$file);}
 }
}
if(PHP_SAPI!=='cli'||getenv('PHPBB_USERNAME_SEARCH_NATIVE')!=='1'){echo "Username search native checks require an explicitly enabled disposable database.\n";return;}
require $ats_source.'db/mysqli.php';
$port=getenv('PHPBB_USERNAME_SEARCH_PORT')?:'3306';$password=getenv('PHPBB_USERNAME_SEARCH_PASSWORD')?:'';
ats_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Loopback port');
$host='127.0.0.1:'.$port;$fixture='codex_username_search_'.bin2hex(phpbb_random_bytes(8));
$control=new sql_db($host,'root',$password,'',false);ats_check($control->sql_query('CREATE DATABASE '.$fixture.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Owned schema');
$db=new sql_db($host,'root',$password,$fixture,false);
function username_search_query($sql){$result=$GLOBALS['db']->sql_query($sql);ats_check($result,'Actual search SQL: '.$sql);return $result;}
function username_search_ids($sql){$r=username_search_query($sql);$rows=$GLOBALS['db']->sql_fetchrowset($r);$GLOBALS['db']->sql_freeresult($r);$ids=array();foreach($rows as$row){$ids[]=(int)$row['post_id'];}sort($ids);return $ids;}
$source=file_get_contents($ats_source.'search.php');
$a=strpos($source,'$author_username_sql =');$b=strpos($source,"\n\t\t\t}",$a);ats_check($a!==false&&$b>$a,'Actual author-only query branch');$author_body=substr($source,$a,$b-$a);
$a=strpos($source,'// Author name search');$a=strpos($source,"\t\tif ( \$search_author != '' )",$a);$b=strpos($source,"\n\t\tif ( \$total_match_count )",$a);ats_check($a!==false&&$b>$a,'Actual combined author filter parser');$filter_parser=substr($source,$a,$b-$a);
preg_match_all('/\$where_sql \.= " AND u\.user_id = p\.poster_id.*?";/s',$source,$matches);ats_check(count($matches[0])===2,'Both actual topic/post author filter branches');$filter_bodies=$matches[0];
$source=file_get_contents($ats_source.'includes/functions_search.php');$a=strpos($source,"\t\$username_list = '';",strpos($source,'function username_search'));$b=strpos($source,"\t\$page_title =",$a);ats_check($a!==false&&$b>$a,'Actual popup query/options branch');$popup_body=substr($source,$a,$b-$a);
$source=file_get_contents($ats_source.'ajax.php');$a=strpos($source,'// Get username',strpos($source,"else if ((\$mode == 'checkusername_pm')"));$b=strpos($source,"\nelse if (\$mode == 'checkemail')",$a);ats_check($a!==false&&$b>$a,'Actual AJAX selector branch');$ajax_body=substr($source,$a,$b-$a);$ajax_body=substr($ajax_body,0,strrpos($ajax_body,"\n}"));
$source=file_get_contents($ats_source.'attach_mod/includes/functions_admin.php');$a=strpos($source,'$search_author = phpbb_request_raw_value');$b=strpos($source,"\n\t}",$a);ats_check($a!==false&&$b>$a,'Actual attachment author filter');$attach_body=substr($source,$a,$b-$a);
function username_search_author($input){global$db,$lang,$author_body;$search_author=$input;$only_bluecards=false;$search_time=0;eval($author_body);return username_search_ids($sql);}
function username_search_filter($input,$body){global$db,$filter_parser;$search_author=$input;$where_sql='';eval($filter_parser);eval($body);return username_search_ids('SELECT p.post_id FROM fixture_posts p,fixture_users u WHERE 1=1 '.$where_sql);}
function username_search_popup($input){global$db,$lang,$popup_body;profile_fixture_request(array('search_username'=>$input));$search_match=$_POST['search_username'];eval($popup_body);return $username_list;}
function username_search_ajax($input,$mode){global$db,$lang,$ajax_body;profile_fixture_request(array('username'=>$input));$search=false;try{eval($ajax_body);}catch(UsernameSearchExit$e){return $e->response;}throw new RuntimeException('Selector must terminate');}
function username_search_attach($input){global$db,$lang,$attach_body,$HTTP_POST_VARS,$HTTP_GET_VARS;profile_fixture_request(array('search_author'=>$input));$where_sql=array();eval($attach_body);return username_search_ids('SELECT t.user_id_1 AS post_id FROM (SELECT user_id AS user_id_1 FROM fixture_users) t WHERE '.$where_sql[0]);}
try{
 set_error_handler(function($s,$m){if(error_reporting()&$s){throw new RuntimeException($m);}});
 username_search_query('CREATE TABLE fixture_users(user_id INT PRIMARY KEY,username VARCHAR(25) NOT NULL) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
 username_search_query('CREATE TABLE fixture_posts(post_id INT PRIMARY KEY,poster_id INT NOT NULL,post_username VARCHAR(25) NOT NULL,post_time INT NOT NULL DEFAULT 1,post_bluecard INT NOT NULL DEFAULT 0) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
 $cases=0;
 foreach(array('','NO_BACKSLASH_ESCAPES','ANSI_QUOTES')as$sql_mode){username_search_query("SET SESSION sql_mode='$sql_mode'");
  foreach(array('C@ro','A_B','A%B','A!B','C:\\notes','Trailing\\',"O'Reilly",'A&B','%C3%A4','0',str_repeat('ä',25),str_repeat('😀',25),str_repeat("'",25))as$raw){
   foreach(phpbb_username_keys($raw)as$key){
    username_search_query('DELETE FROM fixture_users');username_search_query('DELETE FROM fixture_posts');
    $quoted="'".$db->sql_escape($key)."'";
    username_search_query("INSERT INTO fixture_users VALUES(-1,'Anonymous'),(2,$quoted),(3,'Noise'),(4,'AXBY'),(5,'C:notes'),(6,'Trailing')");
    username_search_query("INSERT INTO fixture_posts(post_id,poster_id,post_username)VALUES(1,2,''),(2,-1,$quoted),(3,3,''),(4,-1,'AXBY'),(5,5,''),(6,6,'')");
    ats_check(username_search_author($raw)===array(1,2),'Actual author-only query finds exact registered and guest names, not wildcard/backslash aliases');
    ats_check(username_search_attach($raw)===array(2),'Actual attachment author filter finds exact historical name without aliases');
    foreach($filter_bodies as$body){ats_check(username_search_filter($raw,$body)===array(1,2),'Actual keyword-filtered topic/post branches agree');}
    $safe=htmlspecialchars($raw,ENT_QUOTES,'UTF-8');ats_check(strpos(username_search_popup($raw),'<option value="'.$safe.'">'.$safe.'</option>')!==false,'Actual popup finds stored name and returns raw browser spelling');
    ats_check(username_search_ajax($raw,'checkusername_pm')['result']===AJAX_PM_USERNAME_FOUND&&username_search_ajax($raw,'search_user')['result']===AJAX_PM_USERNAME_FOUND,'Exact AJAX recipient/search still resolves identity');
    $prefix=preg_replace('/.$/us','',$raw);if($prefix!==''){
     $expected=phpbb_username_lookup($db,$prefix,array('user_id'))?AJAX_PM_USERNAME_FOUND:AJAX_PM_USERNAME_SELECT;
     ats_check(username_search_ajax($prefix,'checkusername_pm')['result']===$expected,'PM literal prefix completion finds historical spelling: '.$raw);
     ats_check(username_search_ajax($prefix.'*','search_user')['result']===AJAX_PM_USERNAME_SELECT,'Search wildcard completion finds historical spelling');
    }
    $cases++;
   }
  }
  // Only the documented star is a wildcard. Literal SQL metacharacters do not
  // enumerate unrelated accounts, even when a literal prefix ends with '%'.
  username_search_query('DELETE FROM fixture_users');username_search_query('DELETE FROM fixture_posts');
  username_search_query("INSERT INTO fixture_users VALUES(-1,'Anonymous'),(2,'A_B'),(3,'AXB'),(4,'A%B'),(5,'A!B'),(6,'A*B')");
  username_search_query("INSERT INTO fixture_posts(post_id,poster_id,post_username)VALUES(2,2,''),(3,3,''),(4,4,''),(5,5,''),(6,6,'')");
  ats_check(username_search_author('A*B')===array(2,3,4,5,6),'Documented star matches multiple accounts');
  foreach(array('A_'=>array(2),'A%'=>array(4),'A!'=>array(5),'A*'=>array(6))as$prefix=>$expected){$sql=phpbb_username_search_sql($db,'u.username',$prefix,true,false);ats_check(username_search_ids('SELECT p.post_id FROM fixture_posts p JOIN fixture_users u ON u.user_id=p.poster_id WHERE '.$sql)===$expected,'Literal PM prefix never interprets metacharacters');$cases++;}
  foreach(array(str_repeat('ä',26),str_repeat('😀',26),"bad\xc3","bad\0name")as$bad){ats_check(username_search_author($bad)===array(),'Malformed author filter cannot return all posts');ats_check(username_search_popup($bad)==='','Malformed popup cannot enumerate accounts');ats_check(username_search_ajax($bad,'search_user')['result']===AJAX_PM_USERNAME_ERROR,'Malformed AJAX cannot enumerate accounts');$cases++;}
  username_search_query('DELETE FROM fixture_users');
  for($id=1;$id<=60;$id++){username_search_query("INSERT INTO fixture_users VALUES($id,'Member$id')");}
  ats_check(substr_count(username_search_popup('Member*'),'<option value=')===50,'Actual popup bounds wildcard suggestions');
  $response=username_search_ajax('Member*','search_user');ats_check($response['result']===AJAX_PM_USERNAME_SELECT&&substr_count($response['error_msg'],'<option value=')===51,'Actual AJAX bounds wildcard suggestions, plus placeholder');$cases++;
 }
 echo 'Native username searches: '.$cases." author/popup/AJAX cases passed.\n";
}finally{$db->sql_close();$control->sql_query('DROP DATABASE '.$fixture);$control->sql_close();restore_error_handler();}
