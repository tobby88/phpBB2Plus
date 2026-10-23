<?php
// Actual category text helper and canonical VARCHAR/TEXT storage; owned native DB.
if(PHP_SAPI!=='cli'||getenv('PHPBB_ALBUM_CATEGORY_TEXT_NATIVE')!=='1'){echo "Native Album category text fixture skipped.\n";return;}
define('IN_PHPBB',true);$source=dirname(dirname(__DIR__)).'/phpBB2/';
require $source.'includes/php_compat.php';require $source.'db/mysqli.php';
function act_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
$body=file_get_contents($source.'album_personal_cat_admin.php');act_check(preg_match('/^function album_personal_category_text\(.*?^\}/ms',$body,$m)===1,'Actual category input helper');eval($m[0]);
$port=getenv('PHPBB_ALBUM_CATEGORY_TEXT_PORT')?:'3306';$password=getenv('PHPBB_ALBUM_CATEGORY_TEXT_PASSWORD')?:'';
act_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Owned loopback port');$db=new sql_db('127.0.0.1:'.$port,'root',$password,'',false);act_check($db->db_connect_id,'Owned native connection');
$schema='codex_album_category_text_'.bin2hex(phpbb_random_bytes(8));$created=false;$cases=0;$failures=0;
function act_sql($sql){$r=$GLOBALS['db']->sql_query($sql);act_check($r!==false,'Owned canonical storage setup');return$r;}
function act_case($ok,$message){$GLOBALS['cases']++;if(!$ok){$GLOBALS['failures']++;if($GLOBALS['failures']<=12){echo'Category text mismatch: ',$message,"\n";}}}
$matrix=array(
 array(true,255,'ASCII','ASCII'),array(true,255,'Grüße 😀','Grüße 😀'),array(true,255,"A\\B","A\\B"),
 array(true,255,"'\"",'&#039;&quot;'),array(true,255,'&amp;','&amp;amp;'),array(true,255,'0','0'),
 array(true,255,str_repeat('&',255),str_repeat('&amp;',255)),array(true,255,str_repeat("'",255),str_repeat('&#039;',255)),
 array(true,255,str_repeat('a',254).'😀',str_repeat('a',254).'😀'),array(true,255,str_repeat('a',254).'&',str_repeat('a',254).'&amp;'),
 array(true,255,str_repeat('a',249).'&😀z',str_repeat('a',249).'&amp;😀z'),
 array(true,1,'Ä','Ä'),array(true,1,'&','&amp;'),array(true,0,'Any',''),
 array(true,255,"\xc3","\xef\xbf\xbd"),array(true,255,'  <title>  ','&lt;title&gt;'),
 array(true,255,str_repeat('&',1000000),str_repeat('&amp;',255)),
 array(false,65535,str_repeat('x',65536),str_repeat('x',65535)),
 array(false,65535,str_repeat('漢',30000),str_repeat('漢',30000)),
 array(false,65535,str_repeat('😀',20000),str_repeat('😀',20000)),
 array(false,65535,str_repeat('Ä',40000),str_repeat('Ä',40000)),
 array(false,65535,str_repeat('x',65532).'😀z',str_repeat('x',65532).'😀z'),
 array(false,65535,str_repeat('😀',70000),str_repeat('😀',65535)),
 array(false,65535," line 1\nline 2 &amp; ","line 1\nline 2 &amp;"),
 array(false,65535,"A\\B 'quote'","A\\B 'quote'"),array(false,65535,"\xc3","\xef\xbf\xbd"),
 array(false,3,'😀','😀'),array(false,4,'😀','😀'),array(false,0,'Any',''),array(false,65535,'0','0')
);
$reporting=error_reporting(E_ALL);set_error_handler(function($s,$m){if(error_reporting()&$s){throw new RuntimeException($m);}});
try{
 act_sql('CREATE DATABASE '.$schema.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$created=true;act_sql('USE '.$schema);
 $ddl=file_get_contents($source.'install/schemas/mysql_schema.sql');act_check(preg_match('/CREATE TABLE phpbb_album_cat\s*\([\s\S]*?;/',$ddl,$m)===1,'Canonical category schema');act_sql(str_replace('phpbb_album_cat','fixture_album_cat',$m[0]));
 foreach(array('','STRICT_ALL_TABLES','STRICT_ALL_TABLES,NO_BACKSLASH_ESCAPES','STRICT_ALL_TABLES,ANSI_QUOTES')as$mode){
  act_sql("SET SESSION sql_mode='".$mode."'");
  foreach($matrix as$index=>$case){
   $value=album_personal_category_text(addslashes($case[2]),$case[1],$case[0]);
   act_case($value===$case[3]&&preg_match('//u',$value)===1,'Exact bounded UTF-8/HTML/GPC value '.$index.' mode '.$mode);
   act_sql('DELETE FROM fixture_album_cat');$title=$case[0]?$value:'Fixture';$description=$case[0]?'':$value;
   $r=$db->sql_query("INSERT INTO fixture_album_cat(cat_title,cat_desc,cat_order,cat_parent,cat_user_id) VALUES('".$db->sql_escape($title)."','".$db->sql_escape($description)."',0,0,7)");
   act_case($r!==false,'Canonical storage accepts bounded value without SQL truncation '.$index);
   if($r===false){continue;}
   $warnings=act_sql('SHOW WARNINGS');act_case($db->sql_numrows($warnings)===0,'No silent truncation/encoding warnings '.$index);$db->sql_freeresult($warnings);
   $r=act_sql('SELECT cat_title,cat_desc,CHAR_LENGTH(cat_title) AS title_chars,OCTET_LENGTH(cat_desc) AS description_bytes FROM fixture_album_cat');$row=$db->sql_fetchrow($r);$db->sql_freeresult($r);
   act_case($row&&$row[$case[0]?'cat_title':'cat_desc']===$case[3]&&(int)$row['title_chars']<=1530&&(int)$row['description_bytes']<=262140,'Stored bytes roundtrip exact, preserving original logical character limits '.$index);
  }
 }
 echo'Native Album category text: ',$cases,' helper/storage/SQL-mode/warning/byte-roundtrip cases; differences=',$failures,"; own schemas only, no live data or HTTP.\n";
 if($failures){throw new RuntimeException('Native Album category text differences: '.$failures);}
}finally{if($created){$db->sql_query('DROP DATABASE '.$schema);}$db->sql_close();restore_error_handler();error_reporting($reporting);}
