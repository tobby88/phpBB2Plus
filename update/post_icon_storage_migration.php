<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(2); }
if (!defined('IN_PHPBB')) { define('IN_PHPBB',true); }
require_once __DIR__.'/innodb_migration.php';
require_once dirname(__DIR__).'/phpBB2/includes/functions_icon_config.php';
foreach (array('AUTH_ALL'=>0,'AUTH_REG'=>1,'AUTH_MOD'=>3,'AUTH_ADMIN'=>5) as $name=>$value) { if (!defined($name)) { define($name,$value); } }

function plus_icon_storage_definition($prefix,$schema=null)
{
    if (!is_string($prefix) || !preg_match('/^[A-Za-z0-9_]{1,42}$/D',$prefix)) { throw new RuntimeException('Exact post icon prefix required'); }
    if ($schema===null) { $schema=file_get_contents(dirname(__DIR__).'/phpBB2/install/schemas/mysql_schema.sql'); }
    if (!is_string($schema) || preg_match_all('/^CREATE TABLE phpbb_post_icon_config \(\r?\n.*?\r?\n\) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;/ms',$schema,$m)!==1) { throw new RuntimeException('One canonical modern post icon table required'); }
    return array($prefix.'post_icon_config',str_replace('CREATE TABLE phpbb_post_icon_config','CREATE TABLE '.plus_storage_identifier($prefix.'post_icon_config'),rtrim($m[0][0],';')));
}
function plus_icon_storage_assert($db,$table,$canonical=true)
{
    $meta=plus_storage_metadata($db,$table); if ($meta===null) { return false; }
    if ($meta['TABLE_TYPE']!=='BASE TABLE' || ($canonical&&($meta['ENGINE']!=='InnoDB'||strtolower($meta['ROW_FORMAT'])!=='dynamic'||$meta['TABLE_COLLATION']!=='utf8mb4_unicode_ci'))
        || $meta['AUTO_INCREMENT']!==null || !phpbb_icon_storage_shape(plus_storage_rows($db,'SHOW FULL COLUMNS FROM '.plus_storage_identifier($table)),plus_storage_rows($db,'SHOW INDEX FROM '.plus_storage_identifier($table)))) { throw new RuntimeException('Existing post icon table differs; inspect before import'); }
    $literal="'".mysqli_real_escape_string($db,$table)."'";
    if (plus_storage_rows($db,'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND HEX(EVENT_OBJECT_TABLE)=HEX('.$literal.')')
        || plus_storage_rows($db,'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND HEX(TABLE_NAME)=HEX('.$literal.') AND REFERENCED_TABLE_NAME IS NOT NULL')) { throw new RuntimeException('Custom post icon constraints require review'); }
    return true;
}
function plus_icon_reference_plan($db,$prefix,$canonical)
{
    $plan=array(); $mode=plus_storage_rows($db,'SELECT @@SESSION.sql_mode mode'); $no_slashes=in_array('NO_BACKSLASH_ESCAPES',explode(',',$mode[0]['mode']),true);
    foreach (array('posts'=>'post_icon','topics'=>'topic_icon') as $suffix=>$field) {
        $table=$prefix.$suffix; $quoted=plus_storage_identifier($table);
        $pin=plus_storage_query($db,'SELECT * FROM '.$quoted.' LIMIT 0');mysqli_free_result($pin);
        $meta=plus_storage_metadata($db,$table);
        if ($meta===null || $meta['TABLE_TYPE']!=='BASE TABLE' || ($canonical&&($meta['ENGINE']!=='InnoDB'||strtolower($meta['ROW_FORMAT'])!=='dynamic'||$meta['TABLE_COLLATION']!=='utf8mb4_unicode_ci'))) { throw new RuntimeException('Modern post/topic storage required before icon migration'); }
        $column=null; foreach (plus_storage_rows($db,'SHOW FULL COLUMNS FROM '.$quoted) as $candidate) { if ($candidate['Field']===$field) { $column=$candidate; } }
        if ($column===null || $column['Extra']!=='' || !preg_match('/^(tinyint|smallint|mediumint|int|bigint)(?:\([0-9]+\))?(?: unsigned)?$/Di',$column['Type'])) { throw new RuntimeException('Unsupported icon reference definition'); }
        $key=$suffix==='posts'?'post_id':'topic_id'; $primary=plus_storage_rows($db,'SHOW INDEX FROM '.$quoted." WHERE Key_name='PRIMARY'");
        if (count($primary)!==1 || $primary[0]['Column_name']!==$key || $primary[0]['Sub_part']!==null) { throw new RuntimeException('Canonical icon reference primary key required'); }
        // Already wider custom integer columns stay wider. Only the old tiny/
        // signed-small references need expansion; negative/NULL history survives.
        if (phpbb_icon_reference_type($column['Type'])) { continue; }
        $definition='INT '.($column['Null']==='YES'?'NULL':'NOT NULL');
        if ($column['Default']!==null) {
            if (!preg_match('/^-?[0-9]{1,10}$/D',(string)$column['Default']) || (float)$column['Default'] < -2147483648 || (float)$column['Default'] > 2147483647) { throw new RuntimeException('Unsupported icon reference default'); }
            $definition.=' DEFAULT '.(string)$column['Default'];
        } elseif ($column['Null']==='YES') { $definition.=' DEFAULT NULL'; }
        $comment=$no_slashes?str_replace("'","''",$column['Comment']):mysqli_real_escape_string($db,$column['Comment']);$definition.=" COMMENT '".$comment."'";
        $plan[$table]=array('field'=>$field,'key'=>$key,'sql'=>'ALTER TABLE '.$quoted.' MODIFY '.plus_storage_identifier($field).' '.$definition);
    }
    return $plan;
}
function plus_icon_storage_plan($db,$prefix,$root,$canonical=false)
{
    if (mysqli_character_set_name($db)!=='utf8mb4') { throw new RuntimeException('Native UTF8 icon migration required'); }
    list($table,$sql)=plus_icon_storage_definition($prefix);$present=plus_icon_storage_assert($db,$table,$canonical);$source=null;
    if ($present) {
        $rows=plus_storage_rows($db,'SELECT config_id,schema_version,config_value FROM '.plus_storage_identifier($table).' ORDER BY config_id LIMIT 2');
        if ($rows) {
            if (count($rows)!==1 || (string)$rows[0]['config_id']!=='1' || (string)$rows[0]['schema_version']!=='1' || ($config=phpbb_icon_config_decode($rows[0]['config_value']))===false) { throw new RuntimeException('Existing database icon configuration is invalid; never replace it from files'); }
            $source=array('kind'=>'database','path'=>null,'sha256'=>null,'config'=>$config);
        }
    }
    $import=$source===null;if ($import) { $source=phpbb_icon_legacy_source($root); }
    return array('table'=>$table,'create'=>$present?array():array($sql),'references'=>plus_icon_reference_plan($db,$prefix,$canonical),'import'=>$import,'source'=>$source);
}
function plus_icon_storage_digest($db,$table,$key)
{
    $result=mysqli_query($db,'SELECT * FROM '.plus_storage_identifier($table).' ORDER BY '.plus_storage_identifier($key),MYSQLI_USE_RESULT);
    if (!$result) { throw new RuntimeException('Icon reference data cannot be verified'); }$hash=hash_init('sha256');
    try { while ($row=mysqli_fetch_assoc($result)) { hash_update($hash,serialize($row)); } } finally { mysqli_free_result($result); }
    return hash_final($hash);
}
function plus_icon_storage_apply($db,$prefix,$root,$backup,$maintenance,$wait=3)
{
    if ($backup!==true || $maintenance!==true || !is_int($wait) || $wait<1 || $wait>30) { throw new RuntimeException('Verified backup, stopped writers and bounded wait required'); }
    $state=plus_storage_rows($db,'SELECT DATABASE() schema_name,CONNECTION_ID() id,@@SESSION.autocommit automatic,@@SESSION.sql_mode mode,@@SESSION.innodb_strict_mode strict_mode,@@SESSION.lock_wait_timeout ddl_wait,@@SESSION.innodb_lock_wait_timeout row_wait');
    if (count($state)!==1 || !is_string($state[0]['schema_name']) || $state[0]['schema_name']==='' || (string)$state[0]['automatic']!=='1' || mysqli_character_set_name($db)!=='utf8mb4') { throw new RuntimeException('Independent idle UTF8 migration connection required'); }$state=$state[0];
    foreach (array('ddl_wait','row_wait') as $key) { if (!preg_match('/^[0-9]{1,10}$/D',(string)$state[$key])) { throw new RuntimeException('Bounded original session settings required'); } }
    plus_storage_query($db,'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$held=array();$owned=$settings=false;
    try {
        plus_storage_query($db,'START TRANSACTION READ ONLY');$owned=true;
        foreach (array('plus_innodb_'.sha1($state['schema_name']),'attachment:'.md5($state['schema_name']."\0".$prefix.'attachments')) as $lock) {
            $owner=plus_storage_rows($db,"SELECT IS_USED_LOCK('$lock') owner"); if (count($owner)!==1 || (string)$owner[0]['owner']===(string)$state['id']) { throw new RuntimeException('Inherited icon migration lease refused'); }
            $lease=plus_storage_rows($db,"SELECT GET_LOCK('$lock',$wait) held"); if (count($lease)!==1 || (string)$lease[0]['held']!=='1') { throw new RuntimeException('Another migration or forum writer is active'); }$held[]=$lock;
        }
        plus_storage_query($db,'SET SESSION lock_wait_timeout='.$wait.',innodb_lock_wait_timeout='.$wait);$settings=true;
        plus_storage_query($db,"SET SESSION sql_mode=CONCAT_WS(',',@@SESSION.sql_mode,'STRICT_ALL_TABLES','NO_ENGINE_SUBSTITUTION')");plus_storage_strict_mode($db,true);
        $plan=plus_icon_storage_plan($db,$prefix,$root,true);plus_storage_query($db,'COMMIT');$owned=false;
        foreach ($plan['create'] as $sql) { plus_storage_query($db,$sql);if (plus_storage_rows($db,'SHOW WARNINGS') || !plus_icon_storage_assert($db,$plan['table'])) { throw new RuntimeException('New icon table verification failed; keep writers stopped'); } }
        foreach ($plan['references'] as $table=>$item) {
            $meta=plus_storage_metadata($db,$table);$signature=plus_storage_signature($db,$table);$digest=plus_icon_storage_digest($db,$table,$item['key']);
            plus_storage_query($db,$item['sql']);if (plus_storage_rows($db,'SHOW WARNINGS')) { throw new RuntimeException('Icon reference DDL warned; retain backup and inspect'); }
            $after=plus_storage_signature($db,$table);foreach ($signature[0] as $i=>$column) { if ($column['Field']===$item['field']) { $signature[0][$i]['Type']=$after[0][$i]['Type']; } }
            $after_meta=plus_storage_metadata($db,$table);$floor=$meta['AUTO_INCREMENT'];$next=$after_meta['AUTO_INCREMENT'];unset($meta['AUTO_INCREMENT'],$after_meta['AUTO_INCREMENT']);
            if ($signature!==$after || $meta!==$after_meta || ($floor!==null&&!plus_storage_counter_at_least($next,$floor)) || $digest!==plus_icon_storage_digest($db,$table,$item['key'])) { throw new RuntimeException('Icon reference data/metadata changed; keep writers stopped'); }
        }
        plus_storage_query($db,'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');plus_storage_query($db,'START TRANSACTION');$owned=true;
        $result=plus_storage_query($db,'SELECT * FROM '.plus_storage_identifier($plan['table']).' LIMIT 0');mysqli_free_result($result);
        if (!plus_icon_storage_assert($db,$plan['table']) || plus_icon_reference_plan($db,$prefix,true)) { throw new RuntimeException('Icon storage changed before import'); }
        $rows=plus_storage_rows($db,'SELECT config_id,schema_version,config_value FROM '.plus_storage_identifier($plan['table']).' ORDER BY config_id LIMIT 2 FOR UPDATE');$imported=0;
        if (!$rows) {
            if (!$plan['import']) { throw new RuntimeException('Existing icon configuration disappeared'); }
            $source=phpbb_icon_legacy_source($root);if ($source!==$plan['source']) { throw new RuntimeException('Legacy icon source changed during import'); }
            $bytes=serialize($source['config']);plus_storage_query($db,'INSERT INTO '.plus_storage_identifier($plan['table'])." (config_id,schema_version,config_value) VALUES(1,1,'".mysqli_real_escape_string($db,$bytes)."')");
            $rows=plus_storage_rows($db,'SELECT config_id,schema_version,config_value FROM '.plus_storage_identifier($plan['table']).' ORDER BY config_id LIMIT 2');
            if (count($rows)!==1 || $rows[0]['config_value']!==$bytes) { throw new RuntimeException('Imported icon settings failed exact readback'); }$imported=1;
        }
        if (count($rows)!==1 || (string)$rows[0]['config_id']!=='1' || (string)$rows[0]['schema_version']!=='1' || phpbb_icon_config_decode($rows[0]['config_value'])===false) { throw new RuntimeException('Current icon state is invalid; no overwrite'); }
        plus_storage_query($db,'COMMIT');$owned=false;
        return array('created'=>count($plan['create']),'references'=>count($plan['references']),'imported'=>$imported,'source'=>$plan['source']['kind']);
    } finally {
        if ($owned) { try { mysqli_query($db,'ROLLBACK'); } catch (Exception $ignored) {} catch (Error $ignored) {} }
        try { if ($settings) { plus_storage_query($db,"SET SESSION sql_mode='".mysqli_real_escape_string($db,$state['mode'])."',lock_wait_timeout=".$state['ddl_wait'].',innodb_lock_wait_timeout='.$state['row_wait']);plus_storage_strict_mode($db,(bool)(int)$state['strict_mode']); } }
        finally { foreach (array_reverse($held) as $lock) { try { mysqli_query($db,"DO RELEASE_LOCK('$lock')"); } catch (Exception $ignored) {} catch (Error $ignored) {} } }
    }
}
