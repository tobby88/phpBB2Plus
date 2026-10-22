<?php
// Actual Album helpers/controllers, mysqli and canonical account columns.
if(PHP_SAPI!=='cli'||getenv('PHPBB_ALBUM_OWNER_NATIVE')!=='1'){echo "Native Album owner identity fixture skipped.\n";return;}
define('IN_PHPBB',true);define('GENERAL_ERROR',202);define('GENERAL_MESSAGE',200);define('USERS_TABLE','fixture_users');$table_prefix='fixture_';
$source=dirname(dirname(__DIR__)).'/phpBB2/';
require $source.'includes/php_compat.php';require $source.'album_mod/album_constants.php';require $source.'album_mod/album_hierarchy_sql.php';
class AlbumIdentityFailure extends RuntimeException {}
function message_die($type,$message){throw new AlbumIdentityFailure($message);}
function ai_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
$port=getenv('PHPBB_ALBUM_OWNER_PORT')?:'3306';$password=getenv('PHPBB_ALBUM_OWNER_PASSWORD')?:'';
ai_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Owned loopback port');
mysqli_report(MYSQLI_REPORT_OFF);$link=mysqli_connect('127.0.0.1','root',$password,'',(int)$port);
ai_check($link&&mysqli_set_charset($link,'utf8mb4'),'Owned native connection');
$schema='codex_album_identity_'.bin2hex(phpbb_random_bytes(8));$created=false;
function ai_sql($sql){$r=mysqli_query($GLOBALS['link'],$sql);ai_check($r!==false,'Owned fixture SQL');return$r;}
class AlbumIdentityReadDatabase{
 var$queries=0;var$freed=0;var$failure=false;
 function sql_query($sql){
  ai_check(preg_match('/^SELECT username\s+FROM fixture_users\s+WHERE user_id = [0-9]+\s+LIMIT 1$/D',$sql)===1,'Only bounded actual owner SELECTs');
  $this->queries++;return $this->failure?false:ai_sql($sql);
 }
 function sql_fetchrow($r){return mysqli_fetch_assoc($r);}
 function sql_freeresult($r){$this->freed++;mysqli_free_result($r);}
}
function ai_rows(){ $r=ai_sql('SELECT user_id,username FROM fixture_users ORDER BY user_id');$rows=array();while($row=mysqli_fetch_assoc($r)){$rows[]=$row;}mysqli_free_result($r);return$rows; }
function ai_controller($file,$owner){
 global$db,$lang;$body=file_get_contents($GLOBALS['source'].$file);
 ai_check(preg_match('/\$username = album_find_user_name\(\$album_user_id\);\s*if \(\$username === false\)\s*\{.*?^\}/ms',$body,$match)===1,'Actual personal controller guard: '.$file);
 $album_user_id=$owner;$username=null;eval($match[0]);return$username;
}
set_error_handler(function($s,$m){if(error_reporting()&$s){throw new RuntimeException($m);}});
try{
 ai_sql('CREATE DATABASE '.$schema.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$created=true;ai_sql('USE '.$schema);
 $schema_source=file_get_contents($source.'install/schemas/mysql_schema.sql');
 ai_check(preg_match('/CREATE TABLE phpbb_users\s*\([\s\S]*?;/',$schema_source,$m)===1,'Canonical account table');
 $columns=array();foreach(array('user_id','username')as$column){ai_check(preg_match('/^\s+'.$column.'\s+([^\r\n]+),\s*$/m',$m[0],$c)===1,'Canonical account column');$columns[]=$column.' '.$c[1];}
 ai_sql('CREATE TABLE fixture_users('.implode(',',$columns).',PRIMARY KEY(user_id)) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
 ai_sql("INSERT INTO fixture_users VALUES(-1,'Anonymous'),(0,'Physical zero'),(7,'Grüße 😀'),(9,'0'),(10,'Gast'),(11,'A &amp; B'),(12,''),(8388607,'Last account')");
 $snapshot=ai_rows();ai_sql('START TRANSACTION READ ONLY');$db=new AlbumIdentityReadDatabase();$cases=0;
 foreach(array('Guest','Gast')as$guest){
  $lang=array('Guest'=>$guest,'No_such_user'=>'missing-owner','No_user_id_specified'=>'missing-owner');
  foreach(array(7=>'Grüße 😀',9=>'0',10=>'Gast',11=>'A &amp; B',8388607=>'Last account')as$id=>$name){
   foreach(array($id,(string)$id,'000'.$id)as$input){
    $queries=$db->queries;$freed=$db->freed;
    ai_check(album_find_user_name($input)===$name&&album_check_user_exists($input)===true&&album_get_user_name($input)===$name,'Current valid/raw owner name including zero and localized-fallback spelling');
    ai_check($db->queries===$queries+3&&$db->freed===$freed+3,'One bounded query and freed result per owner helper');$cases++;
   }
  }
  foreach(array(1234,12)as$missing){$queries=$db->queries;$freed=$db->freed;ai_check(album_find_user_name($missing)===false&&album_check_user_exists($missing)===false&&album_get_user_name($missing)===$guest,'Missing/blank owner is not a display fallback identity');ai_check($db->queries===$queries+3&&$db->freed===$freed+3,'Missing lookup frees real results');$cases++;}
  foreach(array(0,'0','000')as$public){$queries=$db->queries;ai_check(album_find_user_name($public)===false&&album_check_user_exists($public)===true&&album_get_user_name($public)==='','Explicit public marker is not an account lookup');ai_check($db->queries===$queries,'Public marker ignores even a physical zero row');$cases++;}
  foreach(array(-1,'-1','-0',null,false,true,0.0,7.0,array(7),'invalid','7 OR 1=1',' 7','7 ','+7','7e0','8388608',8388608,'999999999999999999999999999','')as$invalid){
   $queries=$db->queries;ai_check(album_find_user_name($invalid)===false&&album_check_user_exists($invalid)===false&&album_get_user_name($invalid)===$guest,'Malformed/sentinel/out-of-range identity is not a public or real owner');ai_check($db->queries===$queries,'Invalid identity performs no query');$cases++;
  }
  foreach(array('album_personal_cat_admin.php','album_mod/album_personal.php','album_mod/album_memberlist.php')as$file){
   foreach(array(7=>'Grüße 😀',9=>'0',10=>'Gast',11=>'A &amp; B')as$id=>$name){ai_check(ai_controller($file,$id)===$name,'Actual personal controller accepts complete existing name');$cases++;}
   foreach(array(0,1234,'invalid')as$id){$denied=false;try{ai_controller($file,$id);}catch(AlbumIdentityFailure$e){$denied=$e->getMessage()==='missing-owner';}ai_check($denied,'Actual controller rejects before gallery processing');$cases++;}
  }
  $personal=file_get_contents($source.'album_personal.php');$start=strpos($personal,'$row = $db->sql_fetchrow($result);');$end=strpos($personal,'$username_html =',$start);
  ai_check($start!==false&&$end!==false,'Actual legacy personal controller boundary');
  foreach(array(9,7,1234,12)as$id){$result=$db->sql_query('SELECT username FROM fixture_users WHERE user_id = '.$id.' LIMIT 1');$denied=false;
   try{eval(substr($personal,$start,$end-$start));}catch(AlbumIdentityFailure$e){$denied=$e->getMessage()==='missing-owner';}
   ai_check($denied===in_array($id,array(1234,12),true),'Legacy personal controller preserves zero name and rejects missing owner');$cases++;
  }
 }
 foreach(array('album_find_user_name','album_check_user_exists','album_get_user_name')as$helper){$db->failure=true;$denied=false;try{$helper(7);}catch(AlbumIdentityFailure$e){$denied=true;}ai_check($denied,'Read failure remains an explicit storage error');$db->failure=false;$cases++;}
 ai_check(ai_rows()===$snapshot,'Account names and ownership are unchanged');ai_sql('ROLLBACK');
 echo 'Native Album owner identity: '.$cases." real lookup/controller/public-marker/Unicode/failure cases passed; original accounts unchanged.\n";
}finally{
 try{if($created){ai_sql('ROLLBACK');ai_sql('DROP DATABASE '.$schema);}}
 finally{mysqli_close($link);restore_error_handler();}
}
