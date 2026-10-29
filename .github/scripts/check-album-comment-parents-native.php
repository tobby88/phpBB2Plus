<?php
if (PHP_SAPI !== 'cli' || getenv('PHPBB_ALBUM_COMMENT_PARENTS_NATIVE') !== '1') { echo "Native Album comment parents fixture skipped.\n"; return; }
require dirname(dirname(__DIR__)).'/update/album_comment_parent_migration.php';
mysqli_report(MYSQLI_REPORT_OFF);
$port = (int)(getenv('PHPBB_ALBUM_COMMENT_PARENTS_PORT') ?: 3306); $password = getenv('PHPBB_ALBUM_COMMENT_PARENTS_PASSWORD') ?: '';
$db = @mysqli_connect('127.0.0.1','root',$password,'',$port);
if (!$db || !mysqli_set_charset($db,'utf8mb4')) { throw new RuntimeException('Own comment parents fixture connection unavailable'); }
$schema = 'codex_album_parents_'.bin2hex(openssl_random_pseudo_bytes(8)); $created = false; $peer = null; $cases = 0;
function acp_check($ok,$message) { if (!$ok) { throw new RuntimeException($message); } $GLOBALS['cases']++; }
function acp_sql($sql) { return plus_storage_query($GLOBALS['db'],$sql); }
function acp_rows($sql) { return plus_storage_rows($GLOBALS['db'],$sql); }
function acp_denied($callback,$message) { $denied=false; try { $callback(); } catch (Exception $error) { $denied=true; } acp_check($denied,$message); }
function acp_snapshot() {
	return array(acp_rows('SELECT * FROM fixture_album_comment ORDER BY comment_id'),acp_rows('SELECT * FROM fixture_album ORDER BY pic_id'),acp_rows('SELECT * FROM fixture_album_cat ORDER BY cat_id'),acp_rows('SELECT * FROM caller_marker'));
}
function acp_reset() {
	foreach (array('album_comment','album','album_cat') as $suffix) { acp_sql('DROP TABLE IF EXISTS fixture_'.$suffix); }
	$storage=' ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
	acp_sql('CREATE TABLE fixture_album_cat(cat_id MEDIUMINT UNSIGNED PRIMARY KEY,cat_title VARCHAR(30))'.$storage);
	acp_sql('CREATE TABLE fixture_album(pic_id INT UNSIGNED PRIMARY KEY,pic_cat_id MEDIUMINT UNSIGNED NULL,pic_title VARCHAR(30))'.$storage);
	acp_sql('CREATE TABLE fixture_album_comment(comment_id INT UNSIGNED PRIMARY KEY,comment_pic_id INT UNSIGNED NOT NULL,comment_cat_id INT NULL,comment_user_id INT NULL,comment_username VARCHAR(255) NULL,comment_text MEDIUMTEXT NULL,comment_time INT UNSIGNED,comment_edit_time INT NULL,custom_bytes VARBINARY(32))'.$storage);
	acp_sql("INSERT INTO fixture_album_cat VALUES(1,'One'),(2,'Two'),(3,'Three')");
	acp_sql("INSERT INTO fixture_album VALUES(1,2,'Current picture'),(2,1,'Unmoved picture'),(3,999,'Missing category'),(4,NULL,'Unknown category')");
	$body="Grüße 😀 [b:abc]literal[/b:abc] < & \" ' \\ ".str_repeat('ä',1024);
	$hex="CONVERT(0x".bin2hex($body).' USING utf8mb4)';
	acp_sql("INSERT INTO fixture_album_comment VALUES(1,1,1,7,'Exact Author',".$hex.",1,88,0x00FF4142),(2,1,NULL,-1,'Guest',NULL,2,NULL,NULL),(3,2,1,8,'Unchanged','Correct',3,77,0x00),(4,999,77,NULL,NULL,'Missing picture',4,NULL,0xFF),(5,3,1,7,'Orphan','Missing category',5,NULL,NULL),(6,4,NULL,7,'Unknown','Unknown parent',6,NULL,NULL)");
}
function acp_context() { return acp_rows('SELECT @@SESSION.sql_mode mode,@@SESSION.innodb_lock_wait_timeout row_wait,@@SESSION.lock_wait_timeout metadata_wait'); }
function acp_cli($args,$config) {
	$command=escapeshellarg(PHP_BINARY); if(DIRECTORY_SEPARATOR==='\\') { $command.=' -n -d '.escapeshellarg('extension_dir='.ini_get('extension_dir')).' -d extension=php_mysqli.dll -d extension=php_openssl.dll'; }
	$command.=' '.escapeshellarg(dirname(dirname(__DIR__)).'/update/update_from_153a.php').' --album-comment-parents-only '.escapeshellarg('--config='.$config); foreach($args as$arg) { $command.=' '.escapeshellarg($arg); }
	$p=proc_open($command,array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes,null,null,array('bypass_shell'=>true)); acp_check(is_resource($p),'Own updater process'); fclose($pipes[0]); $out=stream_get_contents($pipes[1]);fclose($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);return array(proc_close($p),$out,$err);
}
try {
	acp_sql('CREATE DATABASE `'.$schema.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); $created=true; mysqli_select_db($db,$schema);
	$peer=mysqli_connect('127.0.0.1','root',$password,$schema,$port); acp_check($peer && mysqli_set_charset($peer,'utf8mb4'),'Own independent peer');
	acp_sql('CREATE TABLE caller_marker(value VARCHAR(30)) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'); acp_sql("INSERT INTO caller_marker VALUES('original')");
	acp_sql("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_BACKSLASH_ESCAPES'"); acp_reset(); $before=acp_snapshot(); $context=acp_context();
	$plan=plus_album_comment_parent_plan($db,'fixture_'); acp_check($plan['installed'] && $plan['changes']===2 && acp_snapshot()===$before,'Dry plan is read-only and counts only stale valid parents');
	foreach(array(array(false,true),array(true,false),array(1,true),array(true,'yes')) as$flags) { acp_denied(function()use($db,$flags){plus_album_comment_parent_apply($db,'fixture_',$flags[0],$flags[1]);},'Typed backup and maintenance precede all mutation'); }
	acp_check(acp_snapshot()===$before && acp_context()===$context,'Refused confirmations preserve data/context');
	acp_sql('START TRANSACTION'); acp_sql("UPDATE caller_marker SET value='pending'");
	acp_denied(function()use($db){plus_album_comment_parent_apply($db,'fixture_',true,true);},'Never adopt caller transaction');
	acp_check(acp_rows('SELECT * FROM caller_marker')[0]['value']==='pending' && plus_storage_rows($peer,'SELECT * FROM caller_marker')[0]['value']==='original','Caller transaction neither committed nor rolled back'); acp_sql('ROLLBACK');
	acp_sql('SET autocommit=0'); acp_sql("UPDATE caller_marker SET value='pending'"); acp_denied(function()use($db){plus_album_comment_parent_apply($db,'fixture_',true,true);},'Refuse non-autocommit caller without changing it'); acp_sql('ROLLBACK'); acp_sql('SET autocommit=1');
	$albumLock='album_files_'.sha1($schema."\0fixture_"); $globalLock='plus_innodb_'.sha1($schema); $id=acp_rows('SELECT CONNECTION_ID() id')[0]['id'];
	acp_sql("SELECT GET_LOCK('".$albumLock."',0)"); acp_denied(function()use($db){plus_album_comment_parent_apply($db,'fixture_',true,true);},'Borrowed Album writer lease is not adopted');
	acp_check((string)acp_rows("SELECT IS_USED_LOCK('".$albumLock."') owner")[0]['owner']===(string)$id && acp_rows("SELECT IS_USED_LOCK('".$globalLock."') owner")[0]['owner']===null,'Borrowed lease retained, new schema lock released'); acp_sql("DO RELEASE_LOCK('".$albumLock."')");
	plus_storage_query($peer,"SELECT GET_LOCK('".$albumLock."',0)"); acp_denied(function()use($db){plus_album_comment_parent_apply($db,'fixture_',true,true);},'Real competing Album writer blocks repair'); plus_storage_query($peer,"DO RELEASE_LOCK('".$albumLock."')");
	acp_check(acp_snapshot()===$before && acp_context()===$context,'Refused locks preserve all data and context');
	plus_storage_query($peer,'START TRANSACTION'); plus_storage_query($peer,'SELECT * FROM fixture_album WHERE pic_id=1 FOR UPDATE');
	acp_denied(function()use($db){plus_album_comment_parent_apply($db,'fixture_',true,true,1);},'Uncoordinated picture writer is bounded by real row locks'); plus_storage_query($peer,'ROLLBACK'); acp_check(acp_snapshot()===$before && acp_context()===$context,'Row lock timeout rolls back and restores SQL settings');
	$expected=plus_album_comment_parent_digest($db,'fixture_',true); acp_check(plus_album_comment_parent_apply($db,'fixture_',true,true)===2,'Repair exactly two stale valid category snapshots');
	acp_check(plus_album_comment_parent_digest($db,'fixture_',false)===$expected && acp_context()===$context,'All author, body, BBCode, binary, edit and unrelated rows preserved');
	$after=acp_snapshot(); acp_check($after[0][0]['comment_cat_id']==='2' && $after[0][1]['comment_cat_id']==='2' && $after[0][3]===$before[0][3] && $after[0][4]===$before[0][4] && $after[0][5]===$before[0][5],'Missing picture/category and unknown parent comments stay unchanged');
	acp_check(plus_album_comment_parent_apply($db,'fixture_',true,true)===0 && acp_snapshot()===$after,'Repeated repair is idempotent');
	acp_reset(); $old=plus_album_comment_parent_plan($db,'fixture_'); acp_sql('UPDATE fixture_album SET pic_cat_id=3 WHERE pic_id=1');
	acp_check($old['changes']===2 && plus_album_comment_parent_apply($db,'fixture_',true,true)===2 && acp_rows('SELECT comment_cat_id FROM fixture_album_comment WHERE comment_id=1')[0]['comment_cat_id']==='3','Apply re-derives current parent instead of executing stale plan');
	acp_reset(); acp_sql('ALTER TABLE fixture_album_comment ADD CONSTRAINT refuse_second_parent CHECK(comment_id<>2 OR comment_cat_id<>2)'); $before=acp_snapshot();
	acp_denied(function()use($db){plus_album_comment_parent_apply($db,'fixture_',true,true);},'Actual multi-row UPDATE constraint failure is reported'); acp_check(acp_snapshot()===$before && acp_context()===$context,'Real SQL failure leaves no partial metadata repair');
	acp_reset(); acp_sql('ALTER TABLE fixture_album_comment ADD generated_category INT AS(COALESCE(comment_cat_id,0)+1) STORED'); $before=acp_snapshot();
	acp_denied(function()use($db){plus_album_comment_parent_apply($db,'fixture_',true,true);},'Readback detects changed custom generated fields'); acp_check(acp_snapshot()===$before,'Readback mismatch rolls back the whole repair');
	acp_reset(); acp_sql("CREATE TRIGGER refuse_unknown_side_effect BEFORE UPDATE ON fixture_album_comment FOR EACH ROW UPDATE caller_marker SET value='triggered'"); $before=acp_snapshot();
	acp_denied(function()use($db){plus_album_comment_parent_apply($db,'fixture_',true,true);},'Custom triggers require manual review'); acp_check(acp_snapshot()===$before,'Unknown trigger never runs or changes other tables');
	foreach(array('ENGINE=MyISAM','ROW_FORMAT=COMPACT','MODIFY comment_username VARCHAR(255) CHARACTER SET latin1') as$change) { acp_reset(); acp_sql('ALTER TABLE fixture_album_comment '.$change);$before=acp_snapshot();acp_denied(function()use($db){plus_album_comment_parent_apply($db,'fixture_',true,true);},'Noncanonical storage is refused before writes');acp_check(acp_snapshot()===$before,'Noncanonical source data preserved'); }
	acp_reset(); acp_sql('ALTER TABLE fixture_album_comment MODIFY comment_cat_id TINYINT NULL');acp_sql("INSERT INTO fixture_album_cat VALUES(200,'Large category')");acp_sql('UPDATE fixture_album SET pic_cat_id=200 WHERE pic_id=1');$before=acp_snapshot();
	acp_denied(function()use($db){plus_album_comment_parent_plan($db,'fixture_');},'Custom narrow category capacity never truncates target');acp_check(acp_snapshot()===$before,'Narrow custom schema and data unchanged');
	acp_sql('ALTER TABLE fixture_album_comment MODIFY comment_cat_id TINYINT UNSIGNED NULL');acp_check(plus_album_comment_parent_apply($db,'fixture_',true,true)===2 && acp_rows('SELECT comment_cat_id FROM fixture_album_comment WHERE comment_id=1')[0]['comment_cat_id']==='200','Sufficient custom unsigned integer capacity retained');
	acp_reset(); acp_sql('ALTER TABLE fixture_album_cat DROP PRIMARY KEY');acp_sql("INSERT INTO fixture_album_cat VALUES(2,'Duplicate')");acp_denied(function()use($db){plus_album_comment_parent_plan($db,'fixture_');},'Ambiguous parent identities refused');
	acp_reset();acp_sql('DROP TABLE fixture_album_cat');$before=acp_rows('SELECT * FROM fixture_album_comment ORDER BY comment_id');acp_denied(function()use($db){plus_album_comment_parent_apply($db,'fixture_',true,true);},'Partial installation is not guessed');acp_check(acp_rows('SELECT * FROM fixture_album_comment ORDER BY comment_id')===$before,'Partial installation comments retained');
	acp_reset();mysqli_set_charset($db,'latin1');acp_denied(function()use($db){plus_album_comment_parent_plan($db,'fixture_');},'Connection charset must already be UTF8');mysqli_set_charset($db,'utf8mb4');
	foreach(array('','../',str_repeat('x',41)) as$prefix) { acp_denied(function()use($db,$prefix){plus_album_comment_parent_plan($db,$prefix);},'Bounded namespace before SQL'); }
	foreach(array(0,31,'1',true) as$wait) { acp_denied(function()use($db,$wait){plus_album_comment_parent_apply($db,'fixture_',true,true,$wait);},'Bounded typed wait before SQL'); }
	if(getenv('PHPBB_ALBUM_COMMENT_PARENTS_CLI')==='1') {
		$directory=sys_get_temp_dir().'/'.$schema;acp_check(mkdir($directory,0700),'Own CLI config directory');$config_file=$directory.'/config.php';
		try {
			$config="<?php\n";foreach(array('dbms'=>'mysqli','dbhost'=>'127.0.0.1:'.$port,'dbuser'=>'root','dbpasswd'=>$password,'dbname'=>$schema,'table_prefix'=>'fixture_')as$key=>$value){$config.='$'.$key.'='.var_export($value,true).";\n";}acp_check(file_put_contents($config_file,$config)!==false&&chmod($config_file,0600),'Own trusted CLI config');
			acp_reset();$before=acp_snapshot();$cli=acp_cli(array(),$config_file);acp_check($cli[0]===0&&strpos($cli[1],'Dry run only')!==false&&acp_snapshot()===$before,'Real targeted dry updater preserves rows: '.$cli[2]);
			foreach(array(array('--apply'),array('--apply','--backup-confirmed'),array('--album-rating-storage-only'),array('--album-comment-parent-only'))as$args){$cli=acp_cli($args,$config_file);acp_check($cli[0]===2&&acp_snapshot()===$before,'Actual updater refuses missing guards, combined modes and typos');}
			$expected=plus_album_comment_parent_digest($db,'fixture_',true);$cli=acp_cli(array('--apply','--backup-confirmed','--maintenance-confirmed'),$config_file);acp_check($cli[0]===0&&plus_album_comment_parent_digest($db,'fixture_',false)===$expected,'Actual scoped updater repairs only derived category: '.$cli[2]);$after=acp_snapshot();
			$cli=acp_cli(array('--apply','--backup-confirmed','--maintenance-confirmed'),$config_file);acp_check($cli[0]===0&&acp_snapshot()===$after,'Actual scoped updater retry is idempotent');
		} finally { if(is_file($config_file)){unlink($config_file);}if(is_dir($directory)){rmdir($directory);} }
	}
	acp_reset();acp_sql('DELETE FROM fixture_album_comment');$body=str_repeat('a',16384);$literal='CONVERT(0x'.bin2hex($body).' USING utf8mb4)';
	for($offset=1;$offset<=4097;$offset+=64){$values=array();for($n=$offset;$n<min($offset+64,4098);$n++){$values[]='('.$n.',1,1,7,\'Author\','.$literal.',1,NULL,0x00FF)';}acp_sql('INSERT INTO fixture_album_comment VALUES'.implode(',',$values));}
	$expected=plus_album_comment_parent_digest($db,'fixture_',true);acp_check(plus_album_comment_parent_apply($db,'fixture_',true,true)===4097&&plus_album_comment_parent_digest($db,'fixture_',false)===$expected,'Over64MiB comment bodies repaired and verified with streaming memory');
	foreach(array('album_comment','album','album_cat')as$suffix){acp_sql('DROP TABLE fixture_'.$suffix);}acp_check(!plus_album_comment_parent_plan($db,'fixture_')['installed']&&plus_album_comment_parent_apply($db,'fixture_',true,true)===0,'Absent optional installation is not created');
	echo 'Native Album comment parents: '.$cases.' cases PASS; actual current parents, orphans/bytes preserved, locks, rollback, custom definitions, streaming over64MiB; peak '.memory_get_peak_usage(true)." bytes.\n";
} finally {
	if($peer){@mysqli_rollback($peer);mysqli_close($peer);}if($created){if(!preg_match('/^codex_album_parents_[a-f0-9]{16}$/D',$schema)){throw new RuntimeException('Refuse unowned schema cleanup');}mysqli_query($db,'DROP DATABASE `'.$schema.'`');}mysqli_close($db);
}
