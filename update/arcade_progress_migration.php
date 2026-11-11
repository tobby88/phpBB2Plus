<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit(2);}
require_once __DIR__.'/innodb_migration.php';
require_once dirname(__DIR__).'/phpBB2/includes/functions_arcade_progress_schema.php';

function plus_arcade_progress_definition($prefix,$schema=null)
{
    if(!is_string($prefix)||!preg_match('/^[A-Za-z0-9_]{1,42}$/D',$prefix)){throw new RuntimeException('Exact Arcade progress prefix required');}
    if($schema===null){$schema=file_get_contents(dirname(__DIR__).'/phpBB2/install/schemas/mysql_schema.sql');}
    if(!is_string($schema)||preg_match_all('/^CREATE TABLE phpbb_ina_game_saves \(\r?\n.*?\r?\n\) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;/ms',$schema,$m)!==1){throw new RuntimeException('One canonical modern progress schema required');}
    return array($prefix.'ina_game_saves',str_replace('CREATE TABLE phpbb_ina_game_saves','CREATE TABLE '.plus_storage_identifier($prefix.'ina_game_saves'),rtrim($m[0][0],';')));
}
function plus_arcade_progress_assert($db,$table)
{
    $meta=plus_storage_metadata($db,$table);if($meta===null){return false;}
    if($meta['TABLE_TYPE']!=='BASE TABLE'||$meta['ENGINE']!=='InnoDB'||strtolower($meta['ROW_FORMAT'])!=='dynamic'||$meta['TABLE_COLLATION']!=='utf8mb4_unicode_ci'||$meta['AUTO_INCREMENT']!==null
        ||!phpbb_arcade_progress_schema_matches(plus_storage_rows($db,'SHOW FULL COLUMNS FROM '.plus_storage_identifier($table)),plus_storage_rows($db,'SHOW INDEX FROM '.plus_storage_identifier($table)))){throw new RuntimeException('Existing progress table differs; no data or schema will be adopted or rewritten');}
    $name=mysqli_real_escape_string($db,$table);
    if(plus_storage_rows($db,"SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE='$name'")
        ||plus_storage_rows($db,"SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$name' AND REFERENCED_TABLE_NAME IS NOT NULL")
        ||plus_storage_rows($db,"SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='$name'")){throw new RuntimeException('Custom progress side effects require manual review');}
    return true;
}
function plus_arcade_progress_plan($db,$prefix)
{
    list($table,$sql)=plus_arcade_progress_definition($prefix);return plus_arcade_progress_assert($db,$table)?array():array($table=>$sql);
}
function plus_arcade_progress_sources($db,$prefix)
{
    $tables=array();$present=0;
    foreach(array('users','ina_games','ina_scores')as$suffix){
        $table=$prefix.$suffix;$tables[$suffix]=plus_storage_identifier($table);$meta=plus_storage_metadata($db,$table);
        if($meta===null){continue;}$present++;
        if($meta['TABLE_TYPE']!=='BASE TABLE'||$meta['ENGINE']!=='InnoDB'||strtolower($meta['ROW_FORMAT'])!=='dynamic'||$meta['TABLE_COLLATION']!=='utf8mb4_unicode_ci'){throw new RuntimeException('Migrate existing legacy progress source storage before copying');}
    }
    if($present===0){return array();}if($present!==3){throw new RuntimeException('Incomplete historical progress source tables');}return $tables;
}
function plus_arcade_progress_legacy_plan($db,$prefix)
{
    $tables=plus_arcade_progress_sources($db,$prefix);if(!$tables){return array('missing'=>0);}
    $join=' FROM '.$tables['ina_scores'].' s INNER JOIN '.$tables['users'].' u ON u.user_id=s.player_id INNER JOIN '.$tables['ina_games'].' g ON g.game_name=s.game_name AND HEX(g.game_name)=HEX(s.game_name)';
    $where=' WHERE u.user_id>0 AND g.game_id>0 AND s.gameData IS NOT NULL';
    if(plus_storage_rows($db,'SELECT g.game_id'.$join.$where.' AND EXISTS (SELECT 1 FROM '.$tables['ina_games'].' other WHERE other.game_id<>g.game_id AND other.game_name=g.game_name AND HEX(other.game_name)=HEX(g.game_name)) LIMIT 1')){throw new RuntimeException('Ambiguous historical game identity: no progress can be copied automatically');}
    $target=$prefix.'ina_game_saves';
    if(plus_storage_metadata($db,$target)!==null){$where.=' AND NOT EXISTS (SELECT 1 FROM '.plus_storage_identifier($target).' p WHERE p.user_id=u.user_id AND p.game_id=g.game_id)';}
    $r=plus_storage_rows($db,'SELECT COUNT(DISTINCT u.user_id,g.game_id) missing'.$join.$where);return array('missing'=>(int)$r[0]['missing']);
}
function plus_arcade_progress_import($db,$prefix)
{
    $tables=plus_arcade_progress_sources($db,$prefix);if(!$tables){return 0;}
    $target=plus_storage_identifier($prefix.'ina_game_saves');$last_user=$last_game=0;$count=0;$statement=null;
    try{
        $statement=mysqli_prepare($db,'INSERT INTO '.$target.' (user_id,game_id,game_data,saved_at) VALUES(?,?,?,0)');if(!$statement){throw new RuntimeException('Historical progress copy statement unavailable');}
        do{
            $rows=plus_storage_rows($db,'SELECT DISTINCT u.user_id,g.game_id FROM '.$tables['ina_scores'].' s INNER JOIN '.$tables['users'].' u ON u.user_id=s.player_id INNER JOIN '.$tables['ina_games'].' g ON g.game_name=s.game_name AND HEX(g.game_name)=HEX(s.game_name) WHERE u.user_id>0 AND g.game_id>0 AND s.gameData IS NOT NULL AND (u.user_id>'.$last_user.' OR (u.user_id='.$last_user.' AND g.game_id>'.$last_game.')) ORDER BY u.user_id,g.game_id LIMIT 128');
            foreach($rows as$row){
                $user=(int)$row['user_id'];$game=(int)$row['game_id'];$last_user=$user;$last_game=$game;
                if(!plus_storage_rows($db,'SELECT user_id FROM '.$tables['users'].' WHERE user_id='.$user.' LOCK IN SHARE MODE')){throw new RuntimeException('Historical progress owner changed');}
                $games=plus_storage_rows($db,'SELECT game_name,reverse_list FROM '.$tables['ina_games'].' WHERE game_id='.$game.' LOCK IN SHARE MODE');if(count($games)!==1){throw new RuntimeException('Historical progress game changed');}
                if(plus_storage_rows($db,'SELECT game_data FROM '.$target.' WHERE user_id='.$user.' AND game_id='.$game.' FOR UPDATE')){continue;}
                $name=mysqli_real_escape_string($db,$games[0]['game_name']);
                $legacy=plus_storage_rows($db,'SELECT gameData FROM '.$tables['ina_scores']." WHERE player_id=$user AND game_name='$name' AND HEX(game_name)=HEX('$name') AND gameData IS NOT NULL ORDER BY score ".(!empty($games[0]['reverse_list'])?'ASC':'DESC').',date DESC,BINARY gameData ASC LIMIT 1 LOCK IN SHARE MODE');
                if(count($legacy)!==1||!is_string($legacy[0]['gameData'])||strlen($legacy[0]['gameData'])>65535||preg_match('//u',$legacy[0]['gameData'])!==1){throw new RuntimeException('Historical progress changed or has invalid text');}$data=$legacy[0]['gameData'];
                if(!mysqli_stmt_bind_param($statement,'iis',$user,$game,$data)||!mysqli_stmt_execute($statement)||mysqli_stmt_affected_rows($statement)!==1){throw new RuntimeException('Historical progress copy failed');}$count++;
            }
        }while(count($rows)===128);
        return $count;
    }finally{if($statement instanceof mysqli_stmt){mysqli_stmt_close($statement);}}
}
function plus_arcade_progress_apply($db,$prefix,$backup_confirmed,$maintenance_confirmed,$wait=3)
{
    if($backup_confirmed!==true||$maintenance_confirmed!==true||!is_int($wait)||$wait<1||$wait>30){throw new RuntimeException('Confirmed backup, stopped writers and bounded wait required');}
    list($table,$sql)=plus_arcade_progress_definition($prefix);
    $state=plus_storage_rows($db,'SELECT DATABASE() schema_name,CONNECTION_ID() id,@@SESSION.in_transaction active,@@SESSION.autocommit automatic,@@SESSION.lock_wait_timeout ddl_wait,@@SESSION.innodb_lock_wait_timeout row_wait,@@SESSION.sql_mode mode,@@SESSION.innodb_strict_mode strict_mode');$state=$state[0];
    if(!is_string($state['schema_name'])||$state['schema_name']===''||(string)$state['active']!=='0'||(string)$state['automatic']!=='1'||mysqli_character_set_name($db)!=='utf8mb4'||!preg_match('/^[0-9]{1,10}$/D',(string)$state['ddl_wait'])||!preg_match('/^[0-9]{1,10}$/D',(string)$state['row_wait'])){throw new RuntimeException('Independent idle native UTF-8 progress migration connection required');}
    $held=array();$settings=false;$owned=false;
    try{
        foreach(array('plus_innodb_'.sha1($state['schema_name']),'attachment:'.md5($state['schema_name']."\0".$prefix.'attachments'))as$lock){
            $owner=plus_storage_rows($db,"SELECT IS_USED_LOCK('$lock') owner");if((string)$owner[0]['owner']===(string)$state['id']){throw new RuntimeException('Caller-owned progress migration lease refused');}
            $r=plus_storage_rows($db,"SELECT GET_LOCK('$lock',$wait) held");if(count($r)!==1||(string)$r[0]['held']!=='1'){throw new RuntimeException('Another migration or forum writer is running');}$held[]=$lock;
        }
        plus_storage_query($db,'SET SESSION lock_wait_timeout='.$wait.',innodb_lock_wait_timeout='.$wait);$settings=true;
        plus_storage_query($db,"SET SESSION sql_mode=CONCAT_WS(',',@@SESSION.sql_mode,'STRICT_ALL_TABLES','NO_ENGINE_SUBSTITUTION')");plus_storage_query($db,'SET SESSION innodb_strict_mode=ON');
        $plan=plus_arcade_progress_plan($db,$prefix);plus_arcade_progress_legacy_plan($db,$prefix);
        if($plan){
            // A foreign object appearing after preview is an error. Never
            // DROP, alter or silently adopt it with CREATE IF NOT EXISTS.
            plus_storage_query($db,$sql);
            if(!plus_arcade_progress_assert($db,$table)){throw new RuntimeException('New progress schema not verified; keep writers stopped and inspect');}
        }
        plus_storage_query($db,'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');plus_storage_query($db,'START TRANSACTION');$owned=true;
        $probe=plus_storage_query($db,'SELECT * FROM '.plus_storage_identifier($table).' LIMIT 0');mysqli_free_result($probe);
        if(!plus_arcade_progress_assert($db,$table)){throw new RuntimeException('Progress storage changed before import');}
        plus_arcade_progress_legacy_plan($db,$prefix);$imported=plus_arcade_progress_import($db,$prefix);
        if(plus_arcade_progress_legacy_plan($db,$prefix)['missing']!==0){throw new RuntimeException('Historical progress copy not complete');}
        plus_storage_query($db,'COMMIT');$owned=false;
        return array('created'=>count($plan),'imported'=>$imported);
    }finally{
        if($owned){try{mysqli_query($db,'ROLLBACK');}catch(Exception$ignored){}catch(Error$ignored){}}
        try{if($settings){plus_storage_query($db,"SET SESSION sql_mode='".mysqli_real_escape_string($db,$state['mode'])."',lock_wait_timeout=".$state['ddl_wait'].',innodb_lock_wait_timeout='.$state['row_wait'].',innodb_strict_mode='.(int)$state['strict_mode']);}}
        finally{foreach(array_reverse($held)as$lock){try{mysqli_query($db,"SELECT RELEASE_LOCK('$lock')");}catch(Exception$ignored){}catch(Error$ignored){}}}
    }
}
