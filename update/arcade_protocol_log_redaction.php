<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit(2);}
require_once __DIR__.'/innodb_migration.php';

function plus_arcade_log_redact($value)
{
    if($value===null){return null;}
    if(!is_string($value)){throw new RuntimeException('Stored Arcade log text required');}
    // Locate headers once, then slice their values. A repeated lazy-value
    // lookahead can backtrack excessively on long malformed legacy payloads.
    $count=preg_match_all('/(^|[ \t])([^ \t\r\n=>]++)=>/',$value,$fields,PREG_OFFSET_CAPTURE);
    if($count===false){throw new RuntimeException('Arcade log redaction failed');}
    $ambiguous=false;$result='';$cursor=0;
    for($index=0;$index<$count;$index++){
        $key=trim(preg_replace('/[^A-Za-z0-9]+/','_',$fields[2][$index][0]),'_');
        if(!preg_match('/pass|secret|token|cookie|session|arcade_?hash|csrf|xsrf|(?:^|_)sid(?:_|$)/i',$key)){continue;}
        $start=$fields[0][$index][1]+strlen($fields[0][$index][0]);$end=$index+1<$count?$fields[0][$index+1][1]:strlen($value);
        $stored=substr($value,$start,$end-$start);
        if(preg_match('/pass|secret|cookie|session/i',$key)||(trim($stored)!=='[redacted]'&&!preg_match('/^[a-f0-9]{32}$/iD',trim($stored)))){$ambiguous=true;}
        preg_match('/[ \t\r\n]*$/D',$stored,$space);
        $result.=substr($value,$cursor,$start-$cursor).'[redacted]'.$space[0];$cursor=$end;
    }
    $result.=substr($value,$cursor);
    // Old raw POST logs had no escaping or field-length framing. Passwords,
    // cookies and malformed keys cannot be separated reliably from following
    // fields; retain their record metadata but redact the ambiguous payload.
    // Legacy ERROR rows also embedded SQL containing quoted MD5 game/session
    // keys. Redact long hex tokens even without the raw-POST field framing.
    return $ambiguous?'[redacted legacy request containing credential fields]':preg_replace('/[a-f0-9]{32,}/i','[redacted]',$result);
}
function plus_arcade_log_table($prefix)
{
    if(!is_string($prefix)||!preg_match('/^[A-Za-z0-9_]{1,42}$/D',$prefix)){throw new RuntimeException('Exact Arcade table prefix required');}
    return $prefix.'ina_log';
}
function plus_arcade_log_scan($db,$table,$locked=false,$statement=null)
{
    $total=$changed=0;$last='-1';$before=hash_init('sha256');$expected=hash_init('sha256');
    do{
        $rows=plus_storage_rows($db,'SELECT * FROM '.plus_storage_identifier($table).' WHERE record_no>'.$last.' ORDER BY record_no LIMIT 256'.($locked?' FOR UPDATE':''));
        foreach($rows as$row){
            if(!isset($row['record_no'])||!preg_match('/^(?:0|[1-9][0-9]{0,19})$/D',(string)$row['record_no'])||!array_key_exists('value',$row)){throw new RuntimeException('Stable Arcade log identity unavailable');}
            $last=$row['record_no'];hash_update($before,serialize($row));$new=plus_arcade_log_redact($row['value']);
            if($new!==$row['value']){
                $changed++;
                if($statement!==null){
                    $id=$row['record_no'];
                    if(!mysqli_stmt_bind_param($statement,'ss',$new,$id)||!mysqli_stmt_execute($statement)||mysqli_stmt_affected_rows($statement)!==1){throw new RuntimeException('Arcade log redaction update failed');}
                }
                $row['value']=$new;
            }
            hash_update($expected,serialize($row));$total++;
        }
    }while(count($rows)===256);
    return array('present'=>true,'rows'=>$total,'redacted_rows'=>$changed,'before_sha256'=>hash_final($before),'after_sha256'=>hash_final($expected));
}
function plus_arcade_log_redaction_plan($db,$prefix)
{
    $table=plus_arcade_log_table($prefix);$meta=plus_storage_metadata($db,$table);
    if($meta===null){return array('present'=>false,'rows'=>0,'redacted_rows'=>0);}
    return plus_arcade_log_scan($db,$table);
}
function plus_arcade_log_redaction_apply($db,$prefix,$backup_confirmed,$maintenance_confirmed,$wait=3,$expected_before=null)
{
    if($backup_confirmed!==true||$maintenance_confirmed!==true||!is_int($wait)||$wait<1||$wait>30){throw new RuntimeException('Confirmed backup, stopped writers and bounded wait required');}
    if($expected_before!==null&&(!is_string($expected_before)||!preg_match('/^[a-f0-9]{64}$/D',$expected_before))){throw new RuntimeException('Exact optional backup snapshot digest required');}
    $table=plus_arcade_log_table($prefix);
    $state=plus_storage_rows($db,'SELECT DATABASE() db,CONNECTION_ID() id,@@SESSION.in_transaction active,@@SESSION.autocommit automatic,@@SESSION.lock_wait_timeout ddl_wait,@@SESSION.innodb_lock_wait_timeout row_wait');$state=$state[0];
    if(!is_string($state['db'])||$state['db']===''||(string)$state['active']!=='0'||(string)$state['automatic']!=='1'||mysqli_character_set_name($db)!=='utf8mb4'){throw new RuntimeException('Independent idle UTF-8 migration connection required');}
    foreach(array('ddl_wait','row_wait')as$key){if(!preg_match('/^[0-9]{1,10}$/D',(string)$state[$key])){throw new RuntimeException('Original migration waits unavailable');}}
    $locks=array('plus_innodb_'.sha1($state['db']),'attachment:'.md5($state['db']."\0".$prefix.'attachments'));$held=array();$owned=false;$statement=null;$waits_changed=false;
    try{
        foreach($locks as$lock){
            $owner=plus_storage_rows($db,"SELECT IS_USED_LOCK('$lock') owner");
            if((string)$owner[0]['owner']===(string)$state['id']){throw new RuntimeException('Caller already owns a migration lease');}
            $rows=plus_storage_rows($db,"SELECT GET_LOCK('$lock',$wait) held");
            if(count($rows)!==1||(string)$rows[0]['held']!=='1'){throw new RuntimeException('Another migration or forum write is running');}$held[]=$lock;
        }
        plus_storage_query($db,'SET SESSION lock_wait_timeout='.$wait.',innodb_lock_wait_timeout='.$wait);$waits_changed=true;
        if(plus_storage_metadata($db,$table)===null){if($expected_before!==null){throw new RuntimeException('Backed-up Arcade table disappeared');}return array('present'=>false,'rows'=>0,'redacted_rows'=>0);}
        plus_storage_query($db,'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');plus_storage_query($db,'START TRANSACTION');$owned=true;
        $probe=plus_storage_query($db,'SELECT * FROM '.plus_storage_identifier($table).' LIMIT 0');mysqli_free_result($probe);
        $meta=plus_storage_metadata($db,$table);
        if(!$meta||$meta['ENGINE']!=='InnoDB'||strtolower($meta['ROW_FORMAT'])!=='dynamic'||$meta['TABLE_COLLATION']!=='utf8mb4_unicode_ci'){throw new RuntimeException('Complete modern storage migration before redacting Arcade logs');}
        $indexes=plus_storage_rows($db,'SHOW INDEX FROM '.plus_storage_identifier($table)." WHERE Key_name='PRIMARY'");
        if(count($indexes)!==1||$indexes[0]['Column_name']!=='record_no'){throw new RuntimeException('Stable unique Arcade log identities required');}
        // Updating diagnostic values must not change account data via custom
        // triggers, foreign-key cascades or automatic timestamp columns.
        $literal=mysqli_real_escape_string($db,$table);
        if(plus_storage_rows($db,"SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE='$literal'")
            ||plus_storage_rows($db,"SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$literal' AND COLUMN_NAME='value' AND REFERENCED_TABLE_NAME IS NOT NULL")
            ||plus_storage_rows($db,"SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='$literal' AND REFERENCED_COLUMN_NAME='value'")){throw new RuntimeException('Custom Arcade log side effects require manual review');}
        foreach(plus_storage_rows($db,'SHOW FULL COLUMNS FROM '.plus_storage_identifier($table))as$column){if(stripos($column['Extra'],'on update')!==false){throw new RuntimeException('Automatic log metadata changes require manual review');}}
        $statement=mysqli_prepare($db,'UPDATE '.plus_storage_identifier($table).' SET value=? WHERE record_no=?');
        if(!$statement){throw new RuntimeException('Arcade log redaction statement unavailable');}
        $result=plus_arcade_log_scan($db,$table,true,$statement);
        if($expected_before!==null&&!hash_equals($expected_before,$result['before_sha256'])){throw new RuntimeException('Arcade log changed after the confirmed backup');}
        $verify=plus_arcade_log_scan($db,$table,true);
        if($verify['rows']!==$result['rows']||$verify['before_sha256']!==$result['after_sha256']||$verify['redacted_rows']!==0){throw new RuntimeException('Arcade log redaction verification failed');}
        plus_storage_query($db,'COMMIT');$owned=false;
        return $result;
    }finally{
        if($statement instanceof mysqli_stmt){try{mysqli_stmt_close($statement);}catch(Exception$ignored){}catch(Error$ignored){}}
        if($owned){try{mysqli_query($db,'ROLLBACK');}catch(Exception$ignored){}catch(Error$ignored){}}
        foreach(array_reverse($held)as$lock){try{mysqli_query($db,"SELECT RELEASE_LOCK('$lock')");}catch(Exception$ignored){}catch(Error$ignored){}}
        if($waits_changed){plus_storage_query($db,'SET SESSION lock_wait_timeout='.$state['ddl_wait'].',innodb_lock_wait_timeout='.$state['row_wait']);}
    }
}
