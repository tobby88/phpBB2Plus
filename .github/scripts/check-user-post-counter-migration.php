<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_KB_COUNTER_NATIVE')!=='1'){echo "User post counter migration requires a disposable native database.\n";return;}
$upc_root=dirname(dirname(__DIR__));define('IN_PHPBB',true);require $upc_root.'/phpBB2/includes/php_compat.php';require $upc_root.'/update/user_post_counter_migration.php';
function upc_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
mysqli_report(MYSQLI_REPORT_OFF);$port=getenv('PHPBB_KB_COUNTER_PORT')?:'3306';$password=getenv('PHPBB_KB_COUNTER_PASSWORD')?:'';
upc_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Loopback port');
$control=mysqli_connect('127.0.0.1','root',$password,'',(int)$port);upc_check($control,'Own server');$fixture='codex_user_counters_'.bin2hex(phpbb_random_bytes(8));
plus_user_posts_query($control,'CREATE DATABASE '.$fixture.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$db=null;$peer=null;$config_file=null;
function upc_sql($sql){return plus_user_posts_query($GLOBALS['peer'],$sql);}
function upc_rows($sql){return plus_user_posts_rows($GLOBALS['peer'],$sql);}
function upc_snap(){return array(upc_rows('SELECT * FROM fixture_users ORDER BY user_id'),upc_rows('SELECT * FROM fixture_posts ORDER BY post_id'),upc_rows('SELECT * FROM fixture_forums ORDER BY forum_id'));}
function upc_counts(){return upc_rows('SELECT user_id,user_posts FROM fixture_users ORDER BY user_id');}
function upc_reset(){
 foreach(array('posts','forums','users')as$table){upc_sql('DELETE FROM fixture_'.$table);}
 upc_sql("INSERT INTO fixture_users(user_id,username,user_password,user_email,user_posts) VALUES(-1,'Guest','synthetic','guest@example.invalid',99),(1,'Größe 😀','synthetic','first@example.invalid',77),(2,'Empty','synthetic','empty@example.invalid',55)");
 upc_sql("INSERT INTO fixture_forums(forum_id,cat_id,forum_name,forum_desc,forum_order,count_posts) VALUES(1,1,'Counted','Keep',0,'1'),(2,1,'Not counted','Keep',1,'0')");
 upc_sql("INSERT INTO fixture_posts(post_id,topic_id,forum_id,poster_id,poster_ip) VALUES(1,1,1,1,'7f000001'),(2,1,1,1,'7f000001'),(3,2,2,1,'7f000001'),(4,1,1,-1,'7f000001'),(5,3,99,1,'7f000001')");
}
function upc_cli($arguments){
 $cmd=escapeshellarg(PHP_BINARY);if(DIRECTORY_SEPARATOR==='\\'){$cmd.=' -n -d '.escapeshellarg('extension_dir='.ini_get('extension_dir')).' -d extension=php_mysqli.dll';}
 $cmd.=' '.escapeshellarg($GLOBALS['upc_root'].'/update/update_from_153a.php').' '.escapeshellarg('--config='.$GLOBALS['config_file']);foreach($arguments as$argument){$cmd.=' '.escapeshellarg($argument);}
 $pipes=array();$process=proc_open($cmd,array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes,null,null,array('bypass_shell'=>true));upc_check(is_resource($process),'Actual CLI process');fclose($pipes[0]);$out=stream_get_contents($pipes[1]);fclose($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);return array(proc_close($process),$out,$err);
}
try{
 $db=mysqli_connect('127.0.0.1','root',$password,$fixture,(int)$port);$peer=mysqli_connect('127.0.0.1','root',$password,$fixture,(int)$port);upc_check($db&&$peer&&mysqli_set_charset($db,'utf8mb4')&&mysqli_set_charset($peer,'utf8mb4'),'Own Unicode connections');
 $schema=file_get_contents($upc_root.'/phpBB2/install/schemas/mysql_schema.sql');foreach(array('users','posts','forums')as$suffix){upc_check(preg_match('/CREATE TABLE phpbb_'.$suffix.'\s*\([\s\S]*?;/',$schema,$m)===1,'Canonical table');upc_sql(str_replace('phpbb_'.$suffix,'fixture_'.$suffix,$m[0]));}
 $expected=array(array('user_id'=>'-1','user_posts'=>'99'),array('user_id'=>'1','user_posts'=>'2'),array('user_id'=>'2','user_posts'=>'0'));$cases=0;
 foreach(array('STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION','STRICT_ALL_TABLES,ANSI_QUOTES,NO_BACKSLASH_ESCAPES,NO_ENGINE_SUBSTITUTION')as$mode){
  plus_user_posts_query($db,"SET SESSION sql_mode='".$mode."'");upc_sql("SET SESSION sql_mode='".$mode."'");upc_reset();$before=upc_snap();
  upc_check(plus_user_posts_plan($db,'fixture_')['changes']===2&&upc_snap()===$before,'Dry plan has no writes');$cases++;
  foreach(array(array(false,true),array(true,false))as$flags){$denied=false;try{plus_user_posts_apply($db,'fixture_',$flags[0],$flags[1]);}catch(RuntimeException $error){$denied=true;}upc_check($denied&&upc_snap()===$before,'Backup/stopped writers required');$cases++;}
  upc_check(plus_user_posts_apply($db,'fixture_',true,true)===2&&upc_counts()===$expected,'Counting forum policy and empty users repaired; guest unchanged');$after=upc_snap();
  foreach($after[0]as&$row){unset($row['user_posts']);}unset($row);foreach($before[0]as&$row){unset($row['user_posts']);}unset($row);
  upc_check($after===$before,'Every non-counter user byte, post and forum preserved, including orphaned/guest/non-counting posts');$cases++;
  upc_check(plus_user_posts_apply($db,'fixture_',true,true)===0,'Repeated apply is a no-op');$cases++;
 }
 upc_reset();$before=upc_snap();upc_sql("CREATE TRIGGER upc_failure BEFORE UPDATE ON fixture_users FOR EACH ROW BEGIN IF NEW.user_id=2 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='SyntheticRawSecret'; END IF; END");
 $denied=false;try{plus_user_posts_apply($db,'fixture_',true,true);}catch(RuntimeException $error){$denied=strpos($error->getMessage(),'SyntheticRawSecret')===false;}upc_check($denied&&upc_snap()===$before,'Real second-user failure leaves first user unchanged and sanitizes diagnostics');upc_sql('DROP TRIGGER upc_failure');$cases++;
 upc_check(plus_user_posts_apply($db,'fixture_',true,true)===2&&upc_counts()===$expected,'Retry after rollback succeeds');$cases++;
 upc_reset();plus_user_posts_query($db,'START TRANSACTION');plus_user_posts_query($db,'UPDATE fixture_users SET user_posts=88 WHERE user_id=1');$denied=false;
 try{plus_user_posts_apply($db,'fixture_',true,true);}catch(RuntimeException $error){$denied=true;}
 $inside=plus_user_posts_rows($db,'SELECT user_posts FROM fixture_users WHERE user_id=1');upc_check($denied&&$inside[0]['user_posts']==='88'&&upc_counts()[1]['user_posts']==='77','Caller pending work neither commits nor rolls back');plus_user_posts_query($db,'ROLLBACK');$cases++;
 // Numeric post indexes fit legacy key limits; the canonical wide user indexes
 // intentionally do not, so using users would only test a failed ALTER setup.
 foreach(array('ENGINE=MyISAM','ROW_FORMAT=COMPACT','DEFAULT CHARACTER SET latin1')as$legacy){upc_reset();upc_sql('ALTER TABLE fixture_posts '.$legacy);$before=upc_snap();$denied=false;try{plus_user_posts_apply($db,'fixture_',true,true);}catch(RuntimeException $error){$denied=true;}upc_check($denied&&upc_snap()===$before,'Unsafe storage refuses all writes');upc_sql('ALTER TABLE fixture_posts ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARACTER SET utf8mb4 COLLATE=utf8mb4_unicode_ci');$cases++;}
 upc_reset();upc_sql('ALTER TABLE fixture_users DROP PRIMARY KEY');$before=upc_snap();$denied=false;try{plus_user_posts_apply($db,'fixture_',true,true);}catch(RuntimeException $error){$denied=true;}upc_check($denied&&upc_snap()===$before,'Missing unique identity is refused');upc_sql('ALTER TABLE fixture_users ADD PRIMARY KEY(user_id)');$cases++;
 upc_reset();upc_sql("UPDATE fixture_forums SET count_posts='Y' WHERE forum_id=1");$before=upc_snap();$denied=false;try{plus_user_posts_plan($db,'fixture_');}catch(RuntimeException $error){$denied=true;}upc_check($denied&&upc_snap()===$before,'Unknown forum counting flag is never guessed');$cases++;
 upc_reset();plus_user_posts_query($db,'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');plus_user_posts_query($db,'START TRANSACTION');plus_user_posts_plan($db,'fixture_',true);upc_sql('SET SESSION innodb_lock_wait_timeout=0');upc_sql('SET SESSION lock_wait_timeout=0');
 try{foreach(array('UPDATE fixture_users SET user_posts=88 WHERE user_id=1',"INSERT INTO fixture_posts(post_id,poster_id,forum_id,poster_ip) VALUES(9,1,1,'7f000001')","UPDATE fixture_forums SET count_posts='0' WHERE forum_id=1",'ALTER TABLE fixture_users ENGINE=MyISAM')as$sql){upc_check(@mysqli_query($peer,$sql)===false&&mysqli_errno($peer)===1205,'Real counter/source/metadata locks exclude concurrent changes');$cases++;}}
 finally{plus_user_posts_query($db,'ROLLBACK');upc_sql('SET SESSION innodb_lock_wait_timeout=1');upc_sql('SET SESSION lock_wait_timeout=1');}
 upc_reset();$before=upc_snap();$config_file=tempnam(sys_get_temp_dir(),'user-counter-cli-');chmod($config_file,0600);$config="<?php\n";foreach(array('dbhost'=>'127.0.0.1:'.$port,'dbuser'=>'root','dbpasswd'=>$password,'dbname'=>$fixture,'table_prefix'=>'fixture_','dbms'=>'mysqli')as$key=>$value){$config.='$'.$key.'='.var_export($value,true).";\n";}file_put_contents($config_file,$config);
 $out=upc_cli(array('--kb-counters-only','--apply','--backup-confirmed','--maintenance-confirmed'));upc_check($out[0]===0&&upc_snap()===$before,'Category-only mode does not silently repair user counters');$cases++;
 upc_sql("UPDATE fixture_forums SET count_posts='Y' WHERE forum_id=1");$before_invalid=upc_snap();$out=upc_cli(array('--storage-only'));upc_check($out[0]===0&&upc_snap()===$before_invalid,'Storage-only planning ignores unrelated counter policy validation');upc_reset();$before=upc_snap();$cases++;
 foreach(array('dry','missing-backup','missing-maintenance','apply','retry','mixed','typo')as$case){
  $args=array('--user-post-counters-only');if($case!=='dry'){$args[]='--apply';}if(!in_array($case,array('dry','missing-backup'),true)){$args[]='--backup-confirmed';}if($case!=='missing-maintenance'){$args[]='--maintenance-confirmed';}if($case==='mixed'){$args[]='--kb-counters-only';}if($case==='typo'){$args[0]='--user-post-counter-only';}
  $out=upc_cli($args);$refused=in_array($case,array('missing-backup','missing-maintenance','mixed','typo'),true);upc_check($out[0]===($refused?2:0),'Actual targeted CLI exit '.$case.': '.$out[2]);
  if(in_array($case,array('dry','missing-backup','missing-maintenance'),true)){upc_check(upc_snap()===$before,'Dry/refused CLI cannot write');}else{upc_check(upc_counts()===$expected&&upc_snap()[1]===$before[1],'Targeted repair/retry/refusal keeps exact posts and expected counts');}$cases++;
 }
 echo 'Native user post counter migration/CLI: '.$cases." policy/preservation/lock/fault/targeted cases passed.\n";
}finally{if($config_file!==null&&is_file($config_file)){unlink($config_file);}if($db){mysqli_close($db);}if($peer){mysqli_close($peer);}plus_user_posts_query($control,'DROP DATABASE '.$fixture);mysqli_close($control);}
