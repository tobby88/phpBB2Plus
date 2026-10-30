<?php
// Add tombstone state only; never delete, blank or rewrite an existing post.
if(PHP_SAPI!=='cli'){http_response_code(404);exit(2);}
require_once __DIR__.'/innodb_migration.php';

function plus_post_tombstone_names($prefix)
{
    if(!is_string($prefix)||!preg_match('/^[A-Za-z0-9_]{1,40}$/D',$prefix)){throw new RuntimeException('Invalid post tombstone namespace');}return array('posts'=>$prefix.'posts','config'=>$prefix.'config');
}
function plus_post_tombstone_columns(){return array('post_deleted'=>'tinyint','post_deleted_time'=>'int unsigned','post_deleted_by'=>'mediumint');}
function plus_post_tombstone_plan($db,$prefix,$canonical=false)
{
    if(mysqli_character_set_name($db)!=='utf8mb4'){throw new RuntimeException('Post tombstone migration requires utf8mb4');}$names=plus_post_tombstone_names($prefix);$plan=array();$present=array();
    foreach($names as$kind=>$table){$meta=plus_storage_metadata($db,$table);$present[$kind]=$meta!==null;if($meta===null){continue;}if($canonical&&($meta['ENGINE']!=='InnoDB'||strtolower($meta['ROW_FORMAT'])!=='dynamic'||$meta['TABLE_COLLATION']!=='utf8mb4_unicode_ci')){throw new RuntimeException('Complete modern storage migration before adding tombstones');}
        if($kind==='posts'){
            $columns=array();foreach(plus_storage_rows($db,'SHOW FULL COLUMNS FROM '.plus_storage_identifier($table))as$column){$columns[$column['Field']]=$column;}$add=array();
            foreach(plus_post_tombstone_columns()as$name=>$type){
                if(!isset($columns[$name])){$add[]='ADD '.plus_storage_identifier($name).' '.$type.' NOT NULL DEFAULT 0';continue;}$column=$columns[$name];$actual=preg_replace('/^(tinyint|mediumint|int)\([0-9]+\)/','$1',strtolower($column['Type']));
                if($actual!==$type||$column['Null']!=='NO'||$column['Default']!=='0'||$column['Extra']!==''){throw new RuntimeException('Existing tombstone column conflicts; no definition is rewritten');}
            }
            if($add){$plan[]='ALTER TABLE '.plus_storage_identifier($table).' '.implode(',',$add);}
        }else{
            $rows=plus_storage_rows($db,'SELECT config_value FROM '.plus_storage_identifier($table)." WHERE config_name='post_delete_allow_permanent'");if(count($rows)>1||(count($rows)===1&&!in_array($rows[0]['config_value'],array('0','1'),true))){throw new RuntimeException('Conflicting permanent-delete policy');}
            if(!$rows){$plan[]='INSERT INTO '.plus_storage_identifier($table)." (config_name,config_value) VALUES('post_delete_allow_permanent','0')";}
        }
    }
    return $plan;
}
function plus_post_tombstone_digest($db,$table,$columns)
{
    $hash=hash_init('sha256');$count=0;$r=plus_storage_query($db,'SELECT '.implode(',',array_map('plus_storage_identifier',$columns)).' FROM '.plus_storage_identifier($table).' ORDER BY post_id');try{while($row=mysqli_fetch_row($r)){hash_update($hash,serialize($row));$count++;}}finally{mysqli_free_result($r);}return array($count,hash_final($hash));
}
function plus_post_tombstone_apply($db,$prefix,$backup_confirmed,$maintenance_confirmed,$wait=3)
{
    if($backup_confirmed!==true||$maintenance_confirmed!==true||!is_int($wait)||$wait<1||$wait>30){throw new RuntimeException('Typed backups, maintenance and bounded wait required');}$names=plus_post_tombstone_names($prefix);
    $state=plus_storage_rows($db,'SELECT DATABASE() db,@@SESSION.in_transaction active,@@SESSION.autocommit automatic,@@SESSION.lock_wait_timeout waiting,@@SESSION.sql_mode mode');$state=$state[0];
    if(!is_string($state['db'])||$state['db']===''||$state['active']!=='0'||$state['automatic']!=='1'||!preg_match('/^[A-Z0-9_,]*$/D',$state['mode'])){throw new RuntimeException('Independent tombstone migration connection required');}
    $locks=array('plus_innodb_'.sha1($state['db']),'attachment:'.md5($state['db']."\0".$prefix.'attachments'));$held=array();$changed=0;
    try{
        foreach($locks as$lock){$owner=plus_storage_rows($db,"SELECT IS_USED_LOCK('".$lock."') owner");if($owner[0]['owner']!==null){throw new RuntimeException('Migration lease already belongs to a caller or worker');}$lease=plus_storage_rows($db,"SELECT GET_LOCK('".$lock."',0) held");if($lease[0]['held']!=='1'){throw new RuntimeException('Tombstone migration lease busy');}$held[]=$lock;}
        foreach($names as$table){if(!plus_storage_metadata($db,$table)){throw new RuntimeException('Install forum storage before adding tombstones');}}
        $plan=plus_post_tombstone_plan($db,$prefix,true);
        if($plan){
        $before=plus_storage_signature($db,$names['posts']);$columns=array();foreach($before[0]as$column){$columns[]=$column['Field'];}$digest=plus_post_tombstone_digest($db,$names['posts'],$columns);$meta=plus_storage_metadata($db,$names['posts']);$config=plus_storage_rows($db,'SELECT * FROM '.plus_storage_identifier($names['config'])." WHERE config_name<>'post_delete_allow_permanent' ORDER BY config_name");
        plus_storage_query($db,'SET SESSION lock_wait_timeout='.$wait);plus_storage_query($db,"SET SESSION sql_mode=CONCAT_WS(',',@@SESSION.sql_mode,'STRICT_ALL_TABLES','NO_ENGINE_SUBSTITUTION')");
        foreach($plan as$sql){plus_storage_query($db,$sql);if(plus_storage_rows($db,'SHOW WARNINGS')){throw new RuntimeException('Tombstone migration reported warnings; inspect before retrying');}}
        $after=plus_storage_signature($db,$names['posts']);$after[0]=array_values(array_filter($after[0],function($column)use($columns){return in_array($column['Field'],$columns,true);}));$after_meta=plus_storage_metadata($db,$names['posts']);$floor=$meta['AUTO_INCREMENT'];$new_floor=$after_meta['AUTO_INCREMENT'];unset($meta['AUTO_INCREMENT'],$after_meta['AUTO_INCREMENT']);
        if($before!==$after||$meta!==$after_meta||($floor!==null&&!plus_storage_counter_at_least($new_floor,$floor))||plus_post_tombstone_digest($db,$names['posts'],$columns)!==$digest||plus_storage_rows($db,'SELECT * FROM '.plus_storage_identifier($names['config'])." WHERE config_name<>'post_delete_allow_permanent' ORDER BY config_name")!==$config||plus_post_tombstone_plan($db,$prefix,true)){throw new RuntimeException('Tombstone schema/data readback differs; keep writers stopped');}
        $changed=count($plan);
        }
    }finally{
        try{plus_storage_query($db,'SET SESSION lock_wait_timeout='.(int)$state['waiting']);plus_storage_query($db,"SET SESSION sql_mode='".$state['mode']."'");}finally{foreach(array_reverse($held)as$lock){plus_storage_query($db,"DO RELEASE_LOCK('".$lock."')");}}
    }
    return $changed;
}
