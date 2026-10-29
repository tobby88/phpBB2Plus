<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_POST_EDIT_NATIVE')!=='1'){echo "Native post-edit migration fixture skipped.\n";return;}
define('IN_PHPBB',true);$root=dirname(dirname(__DIR__));require$root.'/phpBB2/includes/php_compat.php';
require$root.'/phpBB2/includes/functions_user_ids.php';
function pem_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
class PostEditorMigrationReader {
 var $connection;function __construct($connection){$this->connection=$connection;}
 function sql_query($sql){return update_query_or_fail($this->connection,$sql);}
 function sql_fetchrowset($result){$rows=array();while($row=mysqli_fetch_assoc($result)){$rows[]=$row;}return$rows;}
 function sql_freeresult($result){mysqli_free_result($result);}
}
$source=file_get_contents($root.'/update/update_from_153a.php');
foreach(array('update_quote_identifier','update_query_or_fail','update_scalar','update_column_exists','update_queue_column')as$name){pem_check(preg_match('/^function '.$name.'\(.*?^\}/ms',$source,$m)===1,'Actual updater primitive '.$name);eval($m[0]);}
$calls=array();foreach(explode("\n",$source)as$line){if(strpos($line,'update_queue_column($operations,')===0&&(strpos($line,"'post_edit_user_id'")!==false||strpos($line,"'post_edit_username'")!==false)){$calls[]=$line;}}
pem_check(count($calls)===2,'Actual full-updater column calls');
$port=getenv('PHPBB_POST_EDIT_PORT')?:'3306';pem_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>=1&&(int)$port<=65535,'Loopback port');$password=getenv('PHPBB_POST_EDIT_PASSWORD')?:'';
mysqli_report(MYSQLI_REPORT_OFF);$control=mysqli_connect('127.0.0.1','root',$password,'',(int)$port);pem_check($control,'Owned local server');$dbname='codex_post_edit_'.bin2hex(phpbb_random_bytes(8));$table_prefix='fixture_';$connection=null;$created=false;$cases=0;
try{
 update_query_or_fail($control,'CREATE DATABASE '.$dbname.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$created=true;
 $connection=mysqli_connect('127.0.0.1','root',$password,$dbname,(int)$port);pem_check($connection&&mysqli_set_charset($connection,'utf8mb4'),'Own canonical connection');
 $schema=file_get_contents($root.'/phpBB2/install/schemas/mysql_schema.sql');pem_check(preg_match('/CREATE TABLE phpbb_posts\s*\([\s\S]*?;/',$schema,$m)===1,'Fresh posts schema');update_query_or_fail($connection,str_replace('phpbb_posts','fixture_posts',$m[0]));
 update_query_or_fail($connection,'ALTER TABLE fixture_posts DROP post_edit_user_id,DROP post_edit_username,ADD fixture_payload LONGBLOB NULL');
 update_query_or_fail($connection,"INSERT INTO fixture_posts(post_id,topic_id,forum_id,poster_id,post_time,poster_ip,post_username,post_edit_count,post_edit_time,fixture_payload) VALUES(17,1,1,-1,1,'7f000001','Former author',1,123,0x0001ff),(9,1,1,8,1,'7f000001','Grüße 😀',0,NULL,0x00)");
 update_query_or_fail($connection,'ALTER TABLE fixture_posts AUTO_INCREMENT=50000');
 $rows=function()use($connection){$r=update_query_or_fail($connection,'SELECT * FROM fixture_posts ORDER BY post_id');$out=array();while($row=mysqli_fetch_assoc($r)){unset($row['post_edit_user_id'],$row['post_edit_username']);$out[]=$row;}mysqli_free_result($r);return$out;};$before=$rows();
 $operations=array();eval(implode("\n",$calls));pem_check(count($operations)===2&&!update_column_exists($connection,$dbname,'fixture_posts','post_edit_user_id')&&$rows()===$before,'Preview queues additions without modifying old rows or schema');$cases++;
 update_query_or_fail($connection,$operations[0]);$operations=array();eval(implode("\n",$calls));pem_check(count($operations)===1,'Partially completed schema resumes only the missing column');$cases++;
 update_query_or_fail($connection,$operations[0]);pem_check($rows()===$before,'Automatic migration preserves all original bytes, attribution, count/time and binary fields');$cases++;
 pem_check((int)update_scalar($connection,'SELECT COUNT(*) FROM fixture_posts WHERE post_edit_user_id IS NOT NULL OR post_edit_username IS NOT NULL')===0,'Historical editors remain unknown instead of being inferred');$cases++;
 $columns=update_query_or_fail($connection,'SHOW FULL COLUMNS FROM fixture_posts');$found=array();while($c=mysqli_fetch_assoc($columns)){if(in_array($c['Field'],array('post_edit_user_id','post_edit_username'),true)){$found[$c['Field']]=$c;}}mysqli_free_result($columns);
 pem_check(count($found)===2&&$found['post_edit_user_id']['Null']==='YES'&&$found['post_edit_username']['Null']==='YES'&&$found['post_edit_username']['Collation']==='utf8mb4_unicode_ci'&&$found['post_edit_username']['Type']==='varchar(255)','New fields retain canonical UTF8 storage and nullable historical defaults');$cases++;
 pem_check((int)update_scalar($connection,"SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA='".$dbname."' AND TABLE_NAME='fixture_posts'")>=50000,'Existing post identity floor retained');$cases++;
 update_query_or_fail($connection,"UPDATE fixture_posts SET post_edit_user_id=8,post_edit_username='Current editor 😀' WHERE post_id=17");$operations=array();eval(implode("\n",$calls));pem_check(!$operations&&update_scalar($connection,'SELECT post_edit_username FROM fixture_posts WHERE post_id=17')==='Current editor 😀','Repeated updater preserves already recorded editors');$cases++;
 update_query_or_fail($connection,'CREATE TABLE fixture_users(user_id MEDIUMINT NOT NULL PRIMARY KEY) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
 update_query_or_fail($connection,'INSERT INTO fixture_users VALUES(2),(20)');update_query_or_fail($connection,'UPDATE fixture_posts SET post_edit_user_id=700 WHERE post_id=17');
 $reader=new PostEditorMigrationReader($connection);
 pem_check(phpbb_user_id_reference_floor($reader,'fixture_')===700,'Real metadata discovery reserves a deleted editor identity above all surviving users and original authors');$cases++;
 echo 'Native post editor migration: ',$cases," actual-updater/partial/repeat/fresh-schema/data-preservation cases PASS; own disposable schema only.\n";
}finally{if($connection){mysqli_close($connection);}pem_check(preg_match('/^codex_post_edit_[a-f0-9]{16}$/D',$dbname),'Owned schema identity');if($created){update_query_or_fail($control,'DROP DATABASE '.$dbname);}mysqli_close($control);}
