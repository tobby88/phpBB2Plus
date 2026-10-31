<?php
// Preserve every historical row, default, constraint and identity floor. Never
// infer visitor identities or deduplicate existing statistics while widening.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(2); }
require_once __DIR__ . '/innodb_migration.php';
function plus_pa_download_prefix($prefix)
{
    if(!is_string($prefix)||!preg_match('/^[A-Za-z0-9_]{1,40}$/D',$prefix)){throw new RuntimeException('Invalid download storage prefix');}return $prefix;
}
function plus_pa_download_type($type){return preg_replace('/^(tinyint|smallint|mediumint|int|bigint)\([0-9]+\)/','$1',strtolower($type));}
function plus_pa_download_indexes($db,$table)
{
    $result=array();foreach(plus_storage_rows($db,'SHOW INDEX FROM '.plus_storage_identifier($table))as$row){$result[$row['Key_name']][(int)$row['Seq_in_index']]=$row;}
    foreach($result as&$rows){ksort($rows,SORT_NUMERIC);$rows=array_values($rows);}unset($rows);return$result;
}
function plus_pa_download_index_matches($rows,$columns)
{
    if(count($rows)<count($columns)){return false;}
    foreach($columns as$i=>$column){$r=$rows[$i];if($r['Column_name']!==$column||$r['Sub_part']!==null||$r['Index_type']!=='BTREE'||$r['Collation']!=='A'||(isset($r['Visible'])&&$r['Visible']!=='YES')||(isset($r['Ignored'])&&$r['Ignored']!=='NO')){return false;}}return true;
}
function plus_pa_download_plan($db,$prefix,$canonical=true)
{
    plus_pa_download_prefix($prefix);if(mysqli_character_set_name($db)!=='utf8mb4'){throw new RuntimeException('Download storage migration requires utf8mb4');}$plan=array();
    foreach(array('pa_download_info'=>array('file_id'),'pa_cat'=>array('cat_last_file_id','cat_files'),'pa_auth'=>array('cat_id'),'pa_files'=>array())as$suffix=>$required){
        $table=$prefix.$suffix;$meta=plus_storage_metadata($db,$table);if($meta===null){continue;}
        if($canonical&&($meta['ENGINE']!=='InnoDB'||strtolower($meta['ROW_FORMAT'])!=='dynamic'||$meta['TABLE_COLLATION']!=='utf8mb4_unicode_ci')){throw new RuntimeException('Complete modern storage migration before widening download identities');}
        $columns=array();foreach(plus_storage_rows($db,'SHOW FULL COLUMNS FROM '.plus_storage_identifier($table))as$column){if($canonical&&$column['Collation']!==null&&$column['Collation']!=='utf8mb4_unicode_ci'){throw new RuntimeException('Download column encoding requires migration first');}$columns[$column['Field']]=$column;}
        $parts=array();$modified=array();$added=array();$mode=plus_storage_rows($db,'SELECT @@SESSION.sql_mode mode');$no_slashes=in_array('NO_BACKSLASH_ESCAPES',explode(',',$mode[0]['mode']),true);
        foreach($required as$name){
            if(!isset($columns[$name])){throw new RuntimeException('Missing download identity or counter column');}$c=$columns[$name];$type=plus_pa_download_type($c['Type']);
            if(!preg_match('/^(tinyint|smallint|mediumint|int|bigint)( unsigned)?$/D',$type,$match)||$c['Extra']!==''){throw new RuntimeException('Custom download identity definition requires review');}
            if(in_array($match[1],array('int','bigint'),true)){continue;}$unsigned=!empty($match[2]);$target='int'.($unsigned?' unsigned':'');$definition=strtoupper($target).($c['Null']==='YES'?' NULL':' NOT NULL');
            if($c['Default']!==null){if(!preg_match('/^-?(?:0|[1-9][0-9]*)$/D',(string)$c['Default'])||(float)$c['Default']<($unsigned?0:-2147483648)||(float)$c['Default']>($unsigned?4294967295:2147483647)){throw new RuntimeException('Unsupported download identity default');}$definition.=' DEFAULT '.$c['Default'];}elseif($c['Null']==='YES'){$definition.=' DEFAULT NULL';}
            $comment=$no_slashes?str_replace("'","''",$c['Comment']):mysqli_real_escape_string($db,$c['Comment']);$definition.=" COMMENT '".$comment."'";
            $parts[]='MODIFY '.plus_storage_identifier($name).' '.$definition;$modified[$name]=$target;
        }
        if($suffix==='pa_download_info'||$suffix==='pa_files'){
            $wanted=$suffix==='pa_files'?array('file_category_time'=>array('file_catid','file_approved','file_time','file_id')):array('file_actor'=>array('file_id','user_id'),'file_address'=>array('file_id','downloader_ip'));
            foreach($wanted as$fields){foreach($fields as$field){if(!isset($columns[$field])){throw new RuntimeException('Missing download lookup field');}}}
            $existing=plus_pa_download_indexes($db,$table);
            foreach($wanted as$name=>$fields){
                if(isset($existing[$name])&&(!plus_pa_download_index_matches($existing[$name],$fields)||count($existing[$name])!==count($fields))){throw new RuntimeException('Conflicting download statistics index; no existing key is replaced');}
                $covered=false;foreach($existing as$rows){if(plus_pa_download_index_matches($rows,$fields)){$covered=true;break;}}if($covered){continue;}
                $parts[]='ADD KEY '.plus_storage_identifier($name).' ('.implode(',',array_map('plus_storage_identifier',$fields)).')';$added[$name]=$fields;
            }
        }
        if($parts){if($meta['AUTO_INCREMENT']!==null){if(!preg_match('/^[0-9]+$/D',(string)$meta['AUTO_INCREMENT'])){throw new RuntimeException('Invalid download identity floor');}$parts[]='AUTO_INCREMENT='.$meta['AUTO_INCREMENT'];}$plan[$table]=array('sql'=>'ALTER TABLE '.plus_storage_identifier($table).' '.implode(', ',$parts),'columns'=>$modified,'indexes'=>$added);}
    }return$plan;
}
function plus_pa_download_digest($db,$table)
{
    $order=array();foreach(plus_storage_rows($db,'SHOW FULL COLUMNS FROM '.plus_storage_identifier($table))as$c){$order[]='HEX(CAST('.plus_storage_identifier($c['Field']).' AS BINARY))';}
    $r=mysqli_query($db,'SELECT * FROM '.plus_storage_identifier($table).' ORDER BY '.implode(',',$order),MYSQLI_USE_RESULT);if(!$r){throw new RuntimeException('Cannot inventory download storage');}$hash=hash_init('sha256');
    try{while($row=mysqli_fetch_assoc($r)){hash_update($hash,serialize($row));}if(mysqli_errno($db)){throw new RuntimeException('Download row inventory interrupted');}}finally{mysqli_free_result($r);}return hash_final($hash);
}
function plus_pa_download_apply($db,$prefix,$backup_confirmed,$maintenance_confirmed,$wait_seconds=30)
{
    plus_pa_download_prefix($prefix);if($backup_confirmed!==true||$maintenance_confirmed!==true||!is_int($wait_seconds)||$wait_seconds<1||$wait_seconds>30){throw new RuntimeException('Download storage migration requires verified backup, stopped writers and bounded DDL wait');}
    $state=plus_storage_rows($db,'SELECT DATABASE() db,CONNECTION_ID() id,@@SESSION.autocommit automatic,@@SESSION.sql_mode mode,@@SESSION.lock_wait_timeout wait_timeout,@@SESSION.innodb_strict_mode strict_mode');if(count($state)!==1||!$state[0]['db']||(string)$state[0]['automatic']!=='1'){throw new RuntimeException('Download DDL requires an idle autocommit connection');}$state=$state[0];
    plus_storage_query($db,'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$owned=false;$held=array();
    try{
        plus_storage_query($db,'START TRANSACTION READ ONLY');$owned=true;
        foreach(array('plus_innodb_'.sha1($state['db']),'attachment:'.md5($state['db']."\0".$prefix.'attachments'))as$lock){$rows=plus_storage_rows($db,"SELECT IS_USED_LOCK('".$lock."') owner");if(count($rows)!==1||$rows[0]['owner']!==null){throw new RuntimeException('Download/schema lock already owned; no lease is adopted');}$rows=plus_storage_rows($db,"SELECT GET_LOCK('".$lock."',0) held");if(count($rows)!==1||(string)$rows[0]['held']!=='1'){throw new RuntimeException('Another download writer or schema migration is active');}$held[]=$lock;}
        $plan=plus_pa_download_plan($db,$prefix);$before=array();foreach($plan as$table=>$item){$before[$table]=array('meta'=>plus_storage_metadata($db,$table),'signature'=>plus_storage_signature($db,$table),'digest'=>plus_pa_download_digest($db,$table));}
        plus_storage_query($db,'COMMIT');$owned=false;plus_storage_query($db,"SET SESSION sql_mode=CONCAT_WS(',',@@SESSION.sql_mode,'STRICT_ALL_TABLES','NO_ENGINE_SUBSTITUTION')");plus_storage_query($db,'SET SESSION innodb_strict_mode=ON');plus_storage_query($db,'SET SESSION lock_wait_timeout='.$wait_seconds);
        foreach($plan as$table=>$item){
            foreach($held as$lock){$rows=plus_storage_rows($db,"SELECT IS_USED_LOCK('".$lock."') owner");if(count($rows)!==1||(string)$rows[0]['owner']!==(string)$state['id']){throw new RuntimeException('Download migration lease lost');}}
            plus_storage_query($db,$item['sql']);if(plus_storage_rows($db,'SHOW WARNINGS')){throw new RuntimeException('Download storage DDL reported warnings; keep writers stopped');}
            $signature=$before[$table]['signature'];$after=plus_storage_signature($db,$table);
            foreach($signature[0]as$i=>$column){if(isset($item['columns'][$column['Field']])){if(plus_pa_download_type($after[0][$i]['Type'])!==$item['columns'][$column['Field']]){throw new RuntimeException('Unexpected widened download identity type');}$signature[0][$i]['Type']=$after[0][$i]['Type'];}}
            $actual=plus_pa_download_indexes($db,$table);foreach($item['indexes']as$name=>$fields){if(!isset($actual[$name])||count($actual[$name])!==count($fields)||!plus_pa_download_index_matches($actual[$name],$fields)||(string)$actual[$name][0]['Non_unique']!=='1'){throw new RuntimeException('Unexpected new download statistics index');}}
            $after[1]=array_values(array_filter($after[1],function($r)use($item){return!isset($item['indexes'][$r['Key_name']]);}));
            $meta=$before[$table]['meta'];$next=plus_storage_metadata($db,$table);$floor=$meta['AUTO_INCREMENT'];$next_floor=$next['AUTO_INCREMENT'];unset($meta['AUTO_INCREMENT'],$next['AUTO_INCREMENT']);
            if($signature!==$after||$meta!==$next||($floor!==null&&!plus_storage_counter_at_least($next_floor,$floor))||$before[$table]['digest']!==plus_pa_download_digest($db,$table)){throw new RuntimeException('Download storage data/constraints verification failed; keep writers stopped');}
        }
        if(plus_pa_download_plan($db,$prefix)){throw new RuntimeException('Download storage migration incomplete');}return count($plan);
    }finally{
        if($owned){try{mysqli_query($db,'ROLLBACK');}catch(Exception$ignored){}catch(Error$ignored){}}
        try{plus_storage_query($db,"SET SESSION sql_mode='".mysqli_real_escape_string($db,$state['mode'])."'");plus_storage_query($db,'SET SESSION lock_wait_timeout='.(int)$state['wait_timeout']);plus_storage_query($db,'SET SESSION innodb_strict_mode='.((int)$state['strict_mode']?'ON':'OFF'));}finally{foreach(array_reverse($held)as$lock){plus_storage_query($db,"DO RELEASE_LOCK('".$lock."')");}}
    }
}
