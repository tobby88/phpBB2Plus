<?php
require dirname(dirname(__DIR__)).'/update/ip_ban_migration.php';
function ipm_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
$root=dirname(dirname(__DIR__));$schema=file_get_contents($root.'/phpBB2/install/schemas/mysql_schema.sql');
ipm_check(strpos($schema,"ban_ip_mask char(8) NOT NULL DEFAULT ''")!==false,'Fresh installer has explicit neutral mask');
$updater=file_get_contents($root.'/update/update_from_153a.php');ipm_check(strpos($updater,"require_once __DIR__ . '/ip_ban_migration.php'")!==false&&substr_count($updater,'plus_ip_ban_plan(')===2,'Actual updater plans and verifies additive migration');
echo "IP mask migration wiring passed.\n";
if(getenv('PHPBB_IP_MIGRATION_NATIVE')!=='1'){return;}
mysqli_report(MYSQLI_REPORT_OFF);$port=getenv('PHPBB_IP_MIGRATION_PORT')?:'3306';$password=getenv('PHPBB_IP_MIGRATION_PASSWORD')?:'';
ipm_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Explicit loopback fixture port');
$db=mysqli_connect('127.0.0.1','root',$password,'',(int)$port);ipm_check((bool)$db,'Native fixture connection');mysqli_set_charset($db,'utf8mb4');
$fixture='codex_ip_migration_'.bin2hex(function_exists('random_bytes')?random_bytes(8):openssl_random_pseudo_bytes(8));plus_storage_query($db,'CREATE DATABASE '.$fixture.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');mysqli_select_db($db,$fixture);$cases=0;
try{
 foreach(array('', 'STRICT_ALL_TABLES','ANSI_QUOTES','NO_BACKSLASH_ESCAPES')as$mode){
  plus_storage_query($db,"SET SESSION sql_mode='$mode'");
  ipm_check(preg_match('/CREATE TABLE phpbb_banlist\s*\([\s\S]*?;/',$schema,$m)===1,'Canonical table');plus_storage_query($db,str_replace('phpbb_banlist','fixture_banlist',$m[0]));
  ipm_check(plus_ip_ban_plan($db,'fixture_banlist')===array(),'Fresh canonical migration is no-op');
  plus_storage_query($db,'ALTER TABLE fixture_banlist DROP ban_ip_mask');plus_storage_query($db,"INSERT INTO fixture_banlist(ban_userid,ban_ip,ban_email) VALUES(7,'7F0000FF','mixed@example.invalid'),(0,'7fff00ff','')");
  $old=plus_storage_rows($db,'SELECT ban_id,ban_userid,ban_ip,ban_email FROM fixture_banlist ORDER BY ban_id');$plan=plus_ip_ban_plan($db,'fixture_banlist');ipm_check(count($plan)===1,'One additive operation only');foreach($plan as$sql){plus_storage_query($db,$sql);}
  ipm_check(plus_storage_rows($db,'SELECT ban_id,ban_userid,ban_ip,ban_email FROM fixture_banlist ORDER BY ban_id')===$old&&plus_ip_ban_plan($db,'fixture_banlist')===array(),'Old rules unchanged and application converges');$cases++;
  plus_storage_query($db,"INSERT INTO fixture_banlist(ban_userid,ban_ip,ban_email,ban_ip_mask)VALUES(0,'7f0000ff','','ffffffff')");$explicit=plus_storage_rows($db,'SELECT * FROM fixture_banlist ORDER BY ban_id');
  $comment="reviewer's \\ notes \"preserved\"";$literal=str_replace("'","''",$mode==='NO_BACKSLASH_ESCAPES'?$comment:str_replace('\\','\\\\',$comment));
  plus_storage_query($db,"ALTER TABLE fixture_banlist MODIFY ban_ip_mask CHAR(8) CHARACTER SET latin1 NOT NULL DEFAULT '' COMMENT '$literal'");
  $columns=plus_storage_rows($db,'SHOW FULL COLUMNS FROM fixture_banlist');foreach($columns as$column){if($column['Field']==='ban_ip_mask'){ipm_check($column['Comment']===$comment,'Fixture column comment is byte-exact');}}
  $plan=plus_ip_ban_plan($db,'fixture_banlist');ipm_check(count($plan)===1,'Compatible mask collation migration');foreach($plan as$sql){plus_storage_query($db,$sql);}
  $columns=plus_storage_rows($db,'SHOW FULL COLUMNS FROM fixture_banlist');foreach($columns as$column){if($column['Field']==='ban_ip_mask'){ipm_check($column['Comment']===$comment&&$column['Collation']==='utf8mb4_unicode_ci','Compatible custom comment retained in every SQL mode');}}
  ipm_check(plus_storage_rows($db,'SELECT * FROM fixture_banlist ORDER BY ban_id')===$explicit&&plus_ip_ban_plan($db,'fixture_banlist')===array(),'Existing explicit/legacy values preserved on charset repair');$cases++;
  plus_storage_query($db,'DROP TABLE fixture_banlist');
 }
 echo 'Native IP mask migration: '.$cases." old/fresh/custom-comment preservation cases passed.\n";
}finally{plus_storage_query($db,'DROP DATABASE '.$fixture);mysqli_close($db);}
