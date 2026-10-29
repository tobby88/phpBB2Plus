<?php
if (PHP_SAPI !== 'cli' || getenv('PHPBB_ALBUM_DISCUSSION_TEXT_NATIVE') !== '1') { echo "Native Album comment capacity fixture skipped.\n"; return; }
define('IN_PHPBB', true); $root=dirname(dirname(__DIR__));
require $root.'/phpBB2/includes/php_compat.php'; require $root.'/update/album_text_storage_migration.php';
mysqli_report(MYSQLI_REPORT_OFF);
function dmt_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
$port=getenv('PHPBB_ALBUM_DISCUSSION_TEXT_PORT')?:'3306';$password=getenv('PHPBB_ALBUM_DISCUSSION_TEXT_PASSWORD')?:'';
dmt_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Owned loopback port');
$db=@mysqli_connect('127.0.0.1','root',$password,'',(int)$port);dmt_check($db&&mysqli_set_charset($db,'utf8mb4'),'Owned migration control');
$schema='codex_album_comment_mig_'.bin2hex(phpbb_random_bytes(8));$created=false;$directory=sys_get_temp_dir().'/'.$schema;$cases=0;$peer=null;
function dmt_sql($sql){$r=mysqli_query($GLOBALS['db'],$sql);dmt_check($r!==false,'Owned migration fixture SQL: '.mysqli_error($GLOBALS['db']));return$r;}
function dmt_rows($sql){$r=dmt_sql($sql);$rows=array();while($row=mysqli_fetch_assoc($r)){$rows[]=$row;}mysqli_free_result($r);return$rows;}
function dmt_quote($s){return "CONVERT(0x".bin2hex($s)." USING utf8mb4)";}
function dmt_reset($filled=true){
 dmt_check(dmt_rows('SELECT DATABASE() AS db')[0]['db']===$GLOBALS['schema'],'Exact own schema before replacement');dmt_sql('DROP TABLE IF EXISTS fixture_album_comment');dmt_sql($GLOBALS['legacy']);
 dmt_sql("ALTER TABLE fixture_album_comment ADD fixture_private VARCHAR(40) DEFAULT 'keep', ADD KEY fixture_private_idx(fixture_private), AUTO_INCREMENT=900");
 if($filled){dmt_sql("INSERT INTO fixture_album_comment(comment_id,comment_pic_id,comment_cat_id,comment_user_id,comment_username,comment_user_ip,comment_time,comment_text) VALUES(1,42,8,7,NULL,'7f000001',1,NULL),(9,42,8,-1,".dmt_quote('Guest Größe 😀  ').",'7f000002',2,".dmt_quote("Café &amp; ' \\ 😀\n".str_repeat('x',5000)).")");}
}
function dmt_snapshot(){return array(plus_storage_metadata($GLOBALS['db'],'fixture_album_comment'),plus_storage_signature($GLOBALS['db'],'fixture_album_comment'),plus_album_discussion_text_digest($GLOBALS['db'],'fixture_album_comment'));}
function dmt_denied($callback,$message){$refused=false;try{$callback();}catch(Exception$error){$refused=true;}dmt_check($refused,$message);$GLOBALS['cases']++;}
function dmt_cli($args){
 $cmd=escapeshellarg(PHP_BINARY);if(DIRECTORY_SEPARATOR==='\\'){$cmd.=' -n -d '.escapeshellarg('extension_dir='.ini_get('extension_dir')).' -d extension=php_mysqli.dll -d extension=php_openssl.dll';}
 $cmd.=' '.escapeshellarg($GLOBALS['root'].'/update/update_from_153a.php').' --album-discussion-text-storage-only '.escapeshellarg('--config='.$GLOBALS['directory'].'/config.php');foreach($args as$arg){$cmd.=' '.escapeshellarg($arg);}
 $p=proc_open($cmd,array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes,null,null,array('bypass_shell'=>true));dmt_check(is_resource($p),'Own updater child');fclose($pipes[0]);
 $out=stream_get_contents($pipes[1]);fclose($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);return array(proc_close($p),$out,$err);
}
$ddl=file_get_contents($root.'/phpBB2/install/schemas/mysql_schema.sql');dmt_check(preg_match('/CREATE TABLE phpbb_album_comment\s*\([\s\S]*?;/',$ddl,$m)===1,'Canonical comment schema');
$legacy=str_replace(array('phpbb_album_comment','comment_username varchar(255)','comment_text mediumtext'),array('fixture_album_comment','comment_username varchar(32)','comment_text text'),$m[0]);
$reporting=error_reporting(E_ALL);set_error_handler(function($s,$m){if(error_reporting()&$s){throw new RuntimeException($m);}});
try{
 dmt_sql('CREATE DATABASE '.$schema.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$created=true;dmt_check(mysqli_select_db($db,$schema),'Select exact owned schema');
 $peer=mysqli_connect('127.0.0.1','root',$password,$schema,(int)$port);dmt_check($peer&&mysqli_set_charset($peer,'utf8mb4'),'Own independent peer');
 foreach(array('','STRICT_ALL_TABLES','STRICT_ALL_TABLES,NO_BACKSLASH_ESCAPES','STRICT_ALL_TABLES,ANSI_QUOTES','STRICT_ALL_TABLES,ONLY_FULL_GROUP_BY')as$mode){foreach(array(false,true)as$filled){
  dmt_sql("SET SESSION sql_mode='".$mode."'");dmt_reset($filled);$before=dmt_snapshot();$settings=dmt_rows('SELECT @@SESSION.sql_mode AS mode,@@SESSION.lock_wait_timeout AS wait_time');
  $plan=plus_album_discussion_text_plan($db,'fixture_',true);dmt_check(count($plan)===1&&count($plan['fixture_album_comment']['types'])===2&&dmt_snapshot()===$before,'Dry plan widens both fields and changes nothing');$cases++;
  dmt_check(plus_album_discussion_text_apply($db,'fixture_',true,true)===1&&!plus_album_discussion_text_plan($db,'fixture_',true),'Native widening completes');$cases++;
  dmt_check(plus_album_discussion_text_digest($db,'fixture_album_comment')===$before[2]&&plus_storage_counter_at_least(plus_storage_metadata($db,'fixture_album_comment')['AUTO_INCREMENT'],$before[0]['AUTO_INCREMENT']),'Exact comments, usernames, NULLs, unrelated fields and identity floor preserved');$cases++;
  dmt_check(dmt_rows('SELECT @@SESSION.sql_mode AS mode,@@SESSION.lock_wait_timeout AS wait_time')===$settings,'Caller SQL mode and timeout restored');$cases++;
  $after=dmt_snapshot();dmt_check(plus_album_discussion_text_apply($db,'fixture_',true,true)===0&&dmt_snapshot()===$after,'Repeated migration no-op');$cases++;
 }}
 dmt_sql("SET SESSION sql_mode='STRICT_ALL_TABLES'");dmt_reset();
 foreach(array(array(false,false),array(false,true),array(true,false))as$flags){$before=dmt_snapshot();dmt_denied(function()use($db,$flags){plus_album_discussion_text_apply($db,'fixture_',$flags[0],$flags[1]);},'Both backup and stopped writers required');dmt_check(dmt_snapshot()===$before,'Refused apply leaves all bytes unchanged');}
 foreach(array('',array(),'../bad',str_repeat('a',52))as$prefix){dmt_denied(function()use($db,$prefix){plus_album_discussion_text_plan($db,$prefix,true);},'Invalid/overlong prefix rejected before SQL');}
 dmt_check(plus_album_text_table(str_repeat('a',51),'discussion')===str_repeat('a',51).'album_comment','Longest legal table prefix retained');$cases++;
 dmt_reset();dmt_sql('ALTER TABLE fixture_album_comment ADD KEY full_name(comment_username)');$before=dmt_snapshot();dmt_denied(function()use($db){plus_album_discussion_text_plan($db,'fixture_',true);},'Full custom index requires review');dmt_check(dmt_snapshot()===$before,'Custom full index unchanged');
 dmt_reset();dmt_sql('ALTER TABLE fixture_album_comment ADD KEY name_prefix(comment_username(20))');dmt_check(plus_album_discussion_text_apply($db,'fixture_',true,true)===1,'Explicit prefix index preserved');$cases++;
 dmt_reset();dmt_sql('ALTER TABLE fixture_album_comment MODIFY comment_username VARCHAR(500) NULL, MODIFY comment_text LONGTEXT NULL');$before=dmt_snapshot();dmt_check(plus_album_discussion_text_apply($db,'fixture_',true,true)===0&&dmt_snapshot()===$before,'Wider custom fields never narrowed');$cases++;
 foreach(array('STRICT_ALL_TABLES,ONLY_FULL_GROUP_BY','STRICT_ALL_TABLES,NO_BACKSLASH_ESCAPES')as$mode){foreach(array(false,true)as$filled){
  dmt_sql("SET SESSION sql_mode='".$mode."'");dmt_reset($filled);$literal="Guest's \\ 😀 default";
  $escaped=in_array('NO_BACKSLASH_ESCAPES',explode(',',$mode),true)?str_replace("'","''",$literal):mysqli_real_escape_string($db,$literal);$quoted="'".$escaped."'";
  dmt_sql('ALTER TABLE fixture_album_comment MODIFY comment_username VARCHAR(32) NULL DEFAULT '.$quoted.' COMMENT '.$quoted.', MODIFY comment_text TEXT NULL DEFAULT '.$quoted.' COMMENT '.$quoted);$before=dmt_snapshot();
  dmt_check(plus_album_discussion_text_apply($db,'fixture_',true,true)===1&&plus_album_discussion_text_digest($db,'fixture_album_comment')===$before[2],'Filled/empty Unicode defaults and column comments retained');$cases++;
  dmt_sql("INSERT INTO fixture_album_comment(comment_pic_id,comment_cat_id,comment_user_id,comment_user_ip,comment_time) VALUES(43,8,7,'7f000001',9)");$row=dmt_rows('SELECT comment_username,comment_text FROM fixture_album_comment WHERE comment_pic_id=43');dmt_check($row===array(array('comment_username'=>$literal,'comment_text'=>$literal)),'Future omitted fields use exact original defaults');$cases++;
 }}
 dmt_sql("SET SESSION sql_mode='STRICT_ALL_TABLES'");
 foreach(array('engine','format','charset')as$change){dmt_reset();dmt_sql($change==='engine'?'ALTER TABLE fixture_album_comment ENGINE=MyISAM':($change==='format'?'ALTER TABLE fixture_album_comment ROW_FORMAT=COMPACT':'ALTER TABLE fixture_album_comment DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci'));$before=dmt_snapshot();dmt_denied(function()use($db){plus_album_discussion_text_apply($db,'fixture_',true,true);},'Canonical storage required first');dmt_check(dmt_snapshot()===$before,'Capacity migration never performs unrelated storage DDL');}
 dmt_reset();dmt_sql("ALTER TABLE fixture_album_comment MODIFY comment_text TEXT NULL DEFAULT (CONCAT('expression','value'))");$before=dmt_snapshot();dmt_denied(function()use($db){plus_album_discussion_text_apply($db,'fixture_',true,true);},'Expression defaults require manual review');dmt_check(dmt_snapshot()===$before,'Expression semantics unchanged');
 dmt_reset();dmt_sql('START TRANSACTION');dmt_sql('UPDATE fixture_album_comment SET comment_time=99 WHERE comment_id=1');dmt_denied(function()use($db){plus_album_discussion_text_apply($db,'fixture_',true,true);},'Caller transaction never adopted');dmt_check(dmt_rows('SELECT comment_time FROM fixture_album_comment WHERE comment_id=1')[0]['comment_time']==='99','Caller write still pending');dmt_sql('ROLLBACK');dmt_check(dmt_rows('SELECT comment_time FROM fixture_album_comment WHERE comment_id=1')[0]['comment_time']==='1','Caller still controls rollback');
 dmt_reset();$lock='plus_innodb_'.sha1($schema);mysqli_free_result(mysqli_query($peer,"SELECT GET_LOCK('".$lock."',0)"));$before=dmt_snapshot();dmt_denied(function()use($db){plus_album_discussion_text_apply($db,'fixture_',true,true);},'Other migration owner respected');dmt_check(dmt_snapshot()===$before,'Competing migration unchanged');mysqli_query($peer,"DO RELEASE_LOCK('".$lock."')");
 dmt_reset();mysqli_query($peer,'START TRANSACTION');mysqli_free_result(mysqli_query($peer,'SELECT comment_id FROM fixture_album_comment LIMIT 1'));$before=dmt_snapshot();dmt_denied(function()use($db){plus_album_discussion_text_apply($db,'fixture_',true,true,1);},'Bounded DDL metadata wait');dmt_check(dmt_snapshot()===$before,'Timed-out DDL unchanged');mysqli_rollback($peer);
 dmt_check(mkdir($directory,0700),'Own CLI fixture directory');$config="<?php\n";foreach(array('dbms'=>'mysqli','dbhost'=>'127.0.0.1:'.$port,'dbuser'=>'root','dbpasswd'=>$password,'dbname'=>$schema,'table_prefix'=>'fixture_')as$key=>$value){$config.='$'.$key.'='.var_export($value,true).";\n";}dmt_check(file_put_contents($directory.'/config.php',$config)!==false&&chmod($directory.'/config.php',0600),'Own CLI config');
 dmt_reset();$before=dmt_snapshot();$result=dmt_cli(array());dmt_check($result[0]===0&&strpos($result[1],'Dry run only')!==false&&dmt_snapshot()===$before,'Actual targeted dry updater is read-only: '.$result[2]);$cases++;
 foreach(array(array('--apply'),array('--album-photo-text-storage-only'),array('--album-discussion-text-storage-onyl'))as$args){$result=dmt_cli($args);dmt_check($result[0]!==0&&dmt_snapshot()===$before,'Missing confirmations, conflicting or misspelled mode safely refused');$cases++;}
 $result=dmt_cli(array('--apply','--backup-confirmed','--maintenance-confirmed'));dmt_check($result[0]===0&&!plus_album_discussion_text_plan($db,'fixture_',true)&&plus_album_discussion_text_digest($db,'fixture_album_comment')===$before[2],'Actual targeted updater migrates existing table: '.$result[2]);$cases++;
 $after=dmt_snapshot();$result=dmt_cli(array('--apply','--backup-confirmed','--maintenance-confirmed'));dmt_check($result[0]===0&&dmt_snapshot()===$after,'Repeated actual updater preserves exact data/schema');$cases++;
 dmt_check(plus_album_discussion_text_plan($db,'absent_',true)===array()&&plus_album_discussion_text_apply($db,'absent_',true,true)===0,'Absent optional table not invented');$cases++;
 echo 'Native Album comment capacity: '.$cases." cases PASS; actual scoped updater, modes, exact defaults, Unicode rows, indexes, identity floor and refusal boundaries; no live data.\n";
}finally{
 if($peer){@mysqli_rollback($peer);mysqli_close($peer);}if($created){dmt_check(preg_match('/^codex_album_comment_mig_[a-f0-9]{16}$/D',$schema)===1,'Owned schema cleanup');mysqli_query($db,'DROP DATABASE '.$schema);}mysqli_close($db);
 if(is_file($directory.'/config.php')){unlink($directory.'/config.php');}if(is_dir($directory)){rmdir($directory);}restore_error_handler();error_reporting($reporting);
}
