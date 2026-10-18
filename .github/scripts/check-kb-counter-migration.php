<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_KB_COUNTER_NATIVE')!=='1'){echo "KB counter migration checks require a disposable native database.\n";return;}
$kbm_root=dirname(dirname(__DIR__));define('IN_PHPBB',true);
require $kbm_root.'/phpBB2/includes/php_compat.php';require $kbm_root.'/update/kb_counter_migration.php';
function kbm_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
mysqli_report(MYSQLI_REPORT_OFF);$port=getenv('PHPBB_KB_COUNTER_PORT')?:'3306';$password=getenv('PHPBB_KB_COUNTER_PASSWORD')?:'';
kbm_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Loopback port');
$control=mysqli_connect('127.0.0.1','root',$password,'',(int)$port);kbm_check($control,'Own server');
$fixture='codex_kb_repair_'.bin2hex(phpbb_random_bytes(8));plus_kb_counter_query($control,'CREATE DATABASE '.$fixture.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$db=null;$peer=null;$config_file=null;
function kbm_sql($sql){return plus_kb_counter_query($GLOBALS['peer'],$sql);}
function kbm_rows($sql){return plus_kb_counter_rows($GLOBALS['peer'],$sql);}
function kbm_snapshot(){return array(kbm_rows('SELECT * FROM fixture_kb_categories ORDER BY category_id,category_name'),kbm_rows('SELECT * FROM fixture_kb_articles ORDER BY article_id'));}
function kbm_counts(){return kbm_rows('SELECT category_id,number_articles FROM fixture_kb_categories ORDER BY category_id');}
function kbm_reset(){
 kbm_sql('DELETE FROM fixture_kb_articles');kbm_sql('DELETE FROM fixture_kb_categories');
 kbm_sql("INSERT INTO fixture_kb_categories(category_id,category_name,category_details,number_articles,parent,cat_order) VALUES(1,'Root','Kept',77,0,0),(2,'Größe 😀','Kept',0,1,1),(3,'Empty','Kept',99,0,2)");
 kbm_sql("INSERT INTO fixture_kb_articles(article_id,article_category_id,article_title,article_body,approved) VALUES(1,2,'First 😀','Kept body',1),(2,2,'Second','Kept body',1),(3,1,'Pending','Kept body',0)");
}
function kbm_cli($arguments){
 $command=escapeshellarg(PHP_BINARY);if(DIRECTORY_SEPARATOR==='\\'){$command.=' -n -d '.escapeshellarg('extension_dir='.ini_get('extension_dir')).' -d extension=php_mysqli.dll';}
 $command.=' '.escapeshellarg($GLOBALS['kbm_root'].'/update/update_from_153a.php').' '.escapeshellarg('--config='.$GLOBALS['config_file']);
 foreach($arguments as$argument){$command.=' '.escapeshellarg($argument);}
 $pipes=array();$process=proc_open($command,array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes,null,null,array('bypass_shell'=>true));
 kbm_check(is_resource($process),'Actual updater process');fclose($pipes[0]);$out=stream_get_contents($pipes[1]);fclose($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);return array(proc_close($process),$out,$err);
}
try{
 $db=mysqli_connect('127.0.0.1','root',$password,$fixture,(int)$port);$peer=mysqli_connect('127.0.0.1','root',$password,$fixture,(int)$port);
 kbm_check($db&&$peer&&mysqli_set_charset($db,'utf8mb4')&&mysqli_set_charset($peer,'utf8mb4'),'Own UTF-8 connections');
 $schema=file_get_contents($kbm_root.'/phpBB2/install/schemas/mysql_schema.sql');
 foreach(array('kb_categories','kb_articles')as$suffix){kbm_check(preg_match('/CREATE TABLE phpbb_'.$suffix.'\s*\([\s\S]*?;/',$schema,$m)===1,'Canonical KB table');kbm_sql(str_replace('phpbb_'.$suffix,'fixture_'.$suffix,$m[0]));}
 $expected=array(array('category_id'=>'1','number_articles'=>'2'),array('category_id'=>'2','number_articles'=>'2'),array('category_id'=>'3','number_articles'=>'0'));$cases=0;
 kbm_reset();$before=kbm_snapshot();$plan=plus_kb_counter_plan($db,'fixture_');
 kbm_check(count($plan['changes'])===3&&$plan['expected']===array(1=>2,2=>2,3=>0)&&kbm_snapshot()===$before,'Native dry plan sees exact derived changes without writes');$cases++;
 foreach(array(array(false,true),array(true,false))as$flags){$denied=false;try{plus_kb_counter_apply($db,'fixture_',$flags[0],$flags[1]);}catch(RuntimeException $error){$denied=true;}kbm_check($denied&&kbm_snapshot()===$before,'Backup and stopped-writer acknowledgement required');$cases++;}
 $articles=$before[1];$definitions=kbm_rows('SHOW CREATE TABLE fixture_kb_categories');
 kbm_check(plus_kb_counter_apply($db,'fixture_',true,true)===3&&kbm_counts()===$expected,'Actual atomic apply repairs leaf, ancestors and empty categories');
 kbm_check(kbm_snapshot()[1]===$articles&&kbm_rows('SHOW CREATE TABLE fixture_kb_categories')===$definitions,'Article bytes, definitions and indexes preserved');$cases++;
 kbm_check(plus_kb_counter_apply($db,'fixture_',true,true)===0&&!plus_kb_counter_plan($db,'fixture_')['changes'],'Repeated repair is a verified no-op');$cases++;
 // Exercise the actual locked planner with an independent native connection.
 // Require server lock errors, not a timeout guessed from elapsed wall time.
 kbm_reset();plus_kb_counter_query($db,'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');plus_kb_counter_query($db,'START TRANSACTION');
 plus_kb_counter_plan($db,'fixture_',true);kbm_sql('SET SESSION innodb_lock_wait_timeout=0');kbm_sql('SET SESSION lock_wait_timeout=0');
 try{
  foreach(array('UPDATE fixture_kb_categories SET number_articles=88 WHERE category_id=1',
   "INSERT INTO fixture_kb_categories(category_id,category_name,category_details,number_articles,parent,cat_order) VALUES(9,'Peer','Kept',0,0,9)",
   'UPDATE fixture_kb_articles SET approved=1 WHERE article_id=3',
   "INSERT INTO fixture_kb_articles(article_id,article_category_id,article_title,article_body,approved) VALUES(9,2,'Peer','Kept',1)",
   'ALTER TABLE fixture_kb_categories ENGINE=MyISAM')as$blocked_sql){
   kbm_check(@mysqli_query($peer,$blocked_sql)===false&&mysqli_errno($peer)===1205,'Locked plan excludes native row/gap/metadata replacement');$cases++;
  }
 }finally{plus_kb_counter_query($db,'ROLLBACK');kbm_sql('SET SESSION innodb_lock_wait_timeout=1');kbm_sql('SET SESSION lock_wait_timeout=1');}
 kbm_sql('UPDATE fixture_kb_articles SET approved=1 WHERE article_id=3');kbm_check(plus_kb_counter_apply($db,'fixture_',true,true)===3,'After lock release the next complete repair includes the new approval');$cases++;
 kbm_reset();$before=kbm_snapshot();
 kbm_sql("CREATE TRIGGER kbm_failure BEFORE UPDATE ON fixture_kb_categories FOR EACH ROW BEGIN IF NEW.category_id=2 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='SyntheticRawSecret'; END IF; END");
 $denied=false;try{plus_kb_counter_apply($db,'fixture_',true,true);}catch(RuntimeException $error){$denied=strpos($error->getMessage(),'SyntheticRawSecret')===false;}
 kbm_check($denied&&kbm_snapshot()===$before,'Second-row server failure rolls back first counter and never exposes driver value');kbm_sql('DROP TRIGGER kbm_failure');$cases++;
 kbm_check(plus_kb_counter_apply($db,'fixture_',true,true)===3&&kbm_counts()===$expected,'Retry after rolled-back repair succeeds');$cases++;
 kbm_reset();plus_kb_counter_query($db,'START TRANSACTION');plus_kb_counter_query($db,'UPDATE fixture_kb_categories SET number_articles=88 WHERE category_id=1');
 $denied=false;try{plus_kb_counter_apply($db,'fixture_',true,true);}catch(RuntimeException $error){$denied=true;}
 $own=plus_kb_counter_rows($db,'SELECT number_articles FROM fixture_kb_categories WHERE category_id=1');$outside=kbm_rows('SELECT number_articles FROM fixture_kb_categories WHERE category_id=1');
 kbm_check($denied&&$own[0]['number_articles']==='88'&&$outside[0]['number_articles']==='77','Existing caller transaction is neither implicitly committed nor rolled back');plus_kb_counter_query($db,'ROLLBACK');$cases++;
 foreach(array('ENGINE=MyISAM','ROW_FORMAT=COMPACT','DEFAULT CHARACTER SET latin1')as$legacy){
  kbm_reset();kbm_sql('ALTER TABLE fixture_kb_categories '.$legacy);$before=kbm_snapshot();$denied=false;try{plus_kb_counter_apply($db,'fixture_',true,true);}catch(RuntimeException $error){$denied=true;}
  kbm_check($denied&&kbm_snapshot()===$before,'Targeted repair refuses unsupported storage without data changes');
  kbm_sql('ALTER TABLE fixture_kb_categories ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARACTER SET utf8mb4 COLLATE=utf8mb4_unicode_ci');$cases++;
 }
 foreach(array('duplicate','cycle','orphan')as$bad){
  kbm_reset();if($bad==='duplicate'){kbm_sql("INSERT INTO fixture_kb_categories(category_id,category_name,category_details,number_articles,parent,cat_order) VALUES(2,'Duplicate','Kept',123,1,3)");}
  elseif($bad==='cycle'){kbm_sql('UPDATE fixture_kb_categories SET parent=2 WHERE category_id=1');}
  else{kbm_sql('UPDATE fixture_kb_articles SET article_category_id=99 WHERE article_id=1');}
  $before=kbm_snapshot();$denied=false;try{plus_kb_counter_apply($db,'fixture_',true,true);}catch(RuntimeException $error){$denied=true;}
  kbm_check($denied&&kbm_snapshot()===$before,'Ambiguous hierarchy/source refuses whole repair without changing data');$cases++;
 }
 kbm_reset();$before=kbm_snapshot();$config_file=tempnam(sys_get_temp_dir(),'kb-counter-cli-');chmod($config_file,0600);$config="<?php\n";
 foreach(array('dbhost'=>'127.0.0.1:'.$port,'dbuser'=>'root','dbpasswd'=>$password,'dbname'=>$fixture,'table_prefix'=>'fixture_','dbms'=>'mysqli')as$key=>$value){$config.='$'.$key.'='.var_export($value,true).";\n";}file_put_contents($config_file,$config);
 kbm_sql('UPDATE fixture_kb_categories SET parent=2 WHERE category_id=1');$invalid_before=kbm_snapshot();$isolated=kbm_cli(array('--storage-only'));
 kbm_check($isolated[0]===0&&kbm_snapshot()===$invalid_before,'Storage-only planning does not run unrelated KB data-repair validation');kbm_reset();$before=kbm_snapshot();$cases++;
 foreach(array('dry','missing-backup','missing-maintenance','apply','retry','mixed-modes','unknown-target','positional')as$case){
  $args=array('--kb-counters-only');if($case!=='dry'){$args[]='--apply';}if(!in_array($case,array('dry','missing-backup'),true)){$args[]='--backup-confirmed';}if($case!=='missing-maintenance'){$args[]='--maintenance-confirmed';}if($case==='mixed-modes'){$args[]='--storage-only';}
  if($case==='unknown-target'){$args[0]='--kb-counter-onyl';}if($case==='positional'){$args[]='unexpected';}
  $out=kbm_cli($args);$refused=in_array($case,array('missing-backup','missing-maintenance','mixed-modes','unknown-target','positional'),true);
  kbm_check($out[0]===($refused?2:0),'Actual targeted CLI returns expected status: '.$case.' '.$out[2]);
  if(in_array($case,array('dry','missing-backup','missing-maintenance'),true)){kbm_check(kbm_snapshot()===$before,'Dry/refused CLI leaves all data intact');}
  else{kbm_check(kbm_counts()===$expected&&kbm_snapshot()[1]===$before[1],'Actual CLI repair/retry preserves articles and exact derived counts');}$cases++;
 }
 kbm_sql('DROP TABLE fixture_kb_articles');$denied=false;try{plus_kb_counter_plan($db,'fixture_');}catch(RuntimeException $error){$denied=true;}kbm_check($denied,'Partial table inventory refuses guessing');$cases++;
 kbm_sql('DROP TABLE fixture_kb_categories');kbm_check(!plus_kb_counter_plan($db,'fixture_')['installed']&&plus_kb_counter_apply($db,'fixture_',true,true)===0,'Absent optional KB is not recreated');$cases++;
 echo 'Knowledge Base native counter migration/CLI: ',$cases," cases passed\n";
}finally{
 if($config_file!==null&&is_file($config_file)){unlink($config_file);}if($db){mysqli_close($db);}if($peer){mysqli_close($peer);}
 plus_kb_counter_query($control,'DROP DATABASE '.$fixture);mysqli_close($control);
}
