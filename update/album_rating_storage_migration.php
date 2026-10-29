<?php
// Lossless widening for legacy signed HotOrNot scores; DDL auto-commits.
if(PHP_SAPI!=='cli'){http_response_code(404);exit(2);}require_once __DIR__.'/innodb_migration.php';
function plus_album_rating_table($prefix)
{
	if(!is_string($prefix)||!preg_match('/^[A-Za-z0-9_]{1,40}$/D',$prefix)){throw new RuntimeException('Invalid Album rating namespace');}return$prefix.'album_rate';
}
function plus_album_rating_plan($db,$prefix,$canonical=false)
{
	if(mysqli_character_set_name($db)!=='utf8mb4'){throw new RuntimeException('Album rating migration requires utf8mb4');}$table=plus_album_rating_table($prefix);$meta=plus_storage_metadata($db,$table);if($meta===null){return array();}
	if($canonical&&($meta['ENGINE']!=='InnoDB'||strtolower($meta['ROW_FORMAT'])!=='dynamic'||$meta['TABLE_COLLATION']!=='utf8mb4_unicode_ci')){throw new RuntimeException('Complete canonical storage migration before Album rating widening');}
	$columns=plus_storage_rows($db,'SHOW FULL COLUMNS FROM '.plus_storage_identifier($table));$fields=array();foreach($columns as$c){if($canonical&&$c['Collation']!==null&&$c['Collation']!=='utf8mb4_unicode_ci'){throw new RuntimeException('Complete canonical Album column encoding before rating widening');}$fields[$c['Field']]=$c;}if(!isset($fields['rate_hon_point'])){throw new RuntimeException('Missing HotOrNot score column');}$c=$fields['rate_hon_point'];$type=preg_replace('/\([0-9]+\)/','',strtolower($c['Type']));
	if(!preg_match('/^(tinyint|smallint|mediumint|int|bigint)(?: unsigned)?$/D',$type)||$c['Extra']!==''){throw new RuntimeException('Custom HotOrNot score definition needs manual review');}
	// Signed SMALLINT preserves negative historical values and supports every
	// configured 1..255 score; wider custom integer definitions are retained.
	if($type!=='tinyint'){return array();}
	$names=array_keys($fields);sort($names,SORT_STRING);$expected=array('rate_hon_point','rate_pic_id','rate_point','rate_user_id','rate_user_ip');sort($expected,SORT_STRING);if($names!==$expected){throw new RuntimeException('Custom Album rating fields need manual review before widening');}
	$mode=plus_storage_rows($db,'SELECT @@SESSION.sql_mode AS mode');$no_slashes=in_array('NO_BACKSLASH_ESCAPES',explode(',',$mode[0]['mode']),true);$quote=function($s)use($db,$no_slashes){return"'".($no_slashes?str_replace("'","''",$s):mysqli_real_escape_string($db,$s))."'";};
	$definition='SMALLINT'.($c['Null']==='YES'?' NULL':' NOT NULL');
	if($c['Default']!==null){if(!preg_match('/^-?(?:0|[1-9][0-9]*)$/D',(string)$c['Default'])||(float)$c['Default']<-32768||(float)$c['Default']>32767){throw new RuntimeException('Expression or unrepresentable HotOrNot default needs manual review');}$definition.=' DEFAULT '.$c['Default'];}elseif($c['Null']==='YES'){$definition.=' DEFAULT NULL';}
	$definition.=' COMMENT '.$quote($c['Comment']);$parts=array('MODIFY `rate_hon_point` '.$definition);
	if($meta['AUTO_INCREMENT']!==null){if(!preg_match('/^[0-9]+$/D',(string)$meta['AUTO_INCREMENT'])){throw new RuntimeException('Unsupported Album rating identity floor');}$parts[]='AUTO_INCREMENT='.$meta['AUTO_INCREMENT'];}
	return array($table=>array('sql'=>'ALTER TABLE '.plus_storage_identifier($table).' '.implode(', ',$parts)));
}
function plus_album_rating_digest($db,$table)
{
	$columns=plus_storage_rows($db,'SHOW FULL COLUMNS FROM '.plus_storage_identifier($table));$order=array();foreach($columns as$c){$order[]='HEX(CAST('.plus_storage_identifier($c['Field']).' AS BINARY))';}
	$result=mysqli_query($db,'SELECT * FROM '.plus_storage_identifier($table).' ORDER BY '.implode(',',$order),MYSQLI_USE_RESULT);if(!$result){throw new RuntimeException('Cannot inventory Album rating rows');}$hash=hash_init('sha256');
	try{while($row=mysqli_fetch_assoc($result)){hash_update($hash,serialize($row));}if(mysqli_errno($db)){throw new RuntimeException('Album rating inventory interrupted');}}finally{mysqli_free_result($result);}return hash_final($hash);
}
function plus_album_rating_apply($db,$prefix,$backup_confirmed,$maintenance_confirmed,$wait_seconds=30)
{
	if($backup_confirmed!==true||$maintenance_confirmed!==true){throw new RuntimeException('Album rating migration requires verified backup and stopped web/cron writers');}if(!is_int($wait_seconds)||$wait_seconds<1||$wait_seconds>30){throw new RuntimeException('Invalid Album rating DDL wait limit');}plus_album_rating_table($prefix);
	$identity=plus_storage_rows($db,'SELECT DATABASE() db,CONNECTION_ID() id,@@SESSION.autocommit automatic,@@SESSION.sql_mode mode,@@SESSION.lock_wait_timeout wait_timeout');$identity=$identity[0];if(!$identity['db']||(string)$identity['automatic']!=='1'){throw new RuntimeException('Album rating DDL requires an idle autocommit connection');}
	// Refuses caller transactions without committing or rolling them back.
	plus_storage_query($db,'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$owned=false;$held=array();
	try{
		plus_storage_query($db,'START TRANSACTION READ ONLY');$owned=true;
		foreach(array('plus_innodb_'.sha1($identity['db']),'album_files_'.sha1($identity['db']."\0".$prefix))as$lock){
			$rows=plus_storage_rows($db,"SELECT IS_USED_LOCK('".$lock."') owner");if(count($rows)!==1||($rows[0]['owner']!==null&&(string)$rows[0]['owner']===(string)$identity['id'])){throw new RuntimeException('Cannot adopt a borrowed Album migration lock');}
			$rows=plus_storage_rows($db,"SELECT GET_LOCK('".$lock."',0) held");if(count($rows)!==1||(string)$rows[0]['held']!=='1'){throw new RuntimeException('Another Album writer or schema migration is running');}$held[]=$lock;
		}
		foreach($held as$lock){$rows=plus_storage_rows($db,"SELECT IS_USED_LOCK('".$lock."') owner");if(count($rows)!==1||(string)$rows[0]['owner']!==(string)$identity['id']){throw new RuntimeException('Album migration lock ownership changed');}}
		$plan=plus_album_rating_plan($db,$prefix,true);$before=array();foreach($plan as$table=>$item){$before[$table]=array('meta'=>plus_storage_metadata($db,$table),'signature'=>plus_storage_signature($db,$table),'digest'=>plus_album_rating_digest($db,$table));}
		plus_storage_query($db,'COMMIT');$owned=false;plus_storage_query($db,"SET SESSION sql_mode=CONCAT_WS(',',@@SESSION.sql_mode,'STRICT_ALL_TABLES','NO_ENGINE_SUBSTITUTION')");plus_storage_query($db,'SET SESSION lock_wait_timeout='.$wait_seconds);
		foreach($plan as$table=>$item){
			plus_storage_query($db,$item['sql']);if(plus_storage_rows($db,'SHOW WARNINGS')){throw new RuntimeException('Album score widening reported warnings; keep writers stopped');}
			$after=plus_storage_signature($db,$table);$signature=$before[$table]['signature'];foreach($signature[0]as$i=>$column){if($column['Field']==='rate_hon_point'){if(preg_replace('/\([0-9]+\)/','',strtolower($after[0][$i]['Type']))!=='smallint'){throw new RuntimeException('Unexpected widened Album score type');}$signature[0][$i]['Type']=$after[0][$i]['Type'];}}
			$meta=$before[$table]['meta'];$next=plus_storage_metadata($db,$table);$floor=$meta['AUTO_INCREMENT'];$next_floor=$next['AUTO_INCREMENT'];unset($meta['AUTO_INCREMENT'],$next['AUTO_INCREMENT']);
			if($signature!==$after||$meta!==$next||($floor!==null&&!plus_storage_counter_at_least($next_floor,$floor))||$before[$table]['digest']!==plus_album_rating_digest($db,$table)){throw new RuntimeException('Album score data/schema verification failed; keep writers stopped');}
		}
		if(plus_album_rating_plan($db,$prefix,true)){throw new RuntimeException('Album score widening incomplete');}return count($plan);
	}finally{
		if($owned){try{mysqli_query($db,'ROLLBACK');}catch(Exception$ignored){}catch(Error$ignored){}}
		try{plus_storage_query($db,"SET SESSION sql_mode='".mysqli_real_escape_string($db,$identity['mode'])."'");plus_storage_query($db,'SET SESSION lock_wait_timeout='.(int)$identity['wait_timeout']);}finally{foreach(array_reverse($held)as$lock){plus_storage_query($db,"DO RELEASE_LOCK('".$lock."')");}}
	}
}
