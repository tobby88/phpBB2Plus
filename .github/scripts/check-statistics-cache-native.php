<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_STATS_CACHE_NATIVE')!=='1'){echo "Statistics cache native checks require an explicitly enabled disposable database.\n";return;}
require __DIR__.'/statistics-cache-fixture.php';require $sp_root.'db/mysqli.php';
class StatisticsCacheNativeDatabase extends sql_db{
    var $setup=true;var $updates=0;
    function sql_query($sql='',$transaction=false){
        if(!$this->setup&&!preg_match('/^SELECT\b/i',ltrim($sql))){
            sp_check(preg_match('/^UPDATE fixture_stats_modules\s+SET module_(?:db|result)_cache = .+WHERE module_id = 1$/sD',$sql)===1,'Actual cache write is scoped to the single owned target');$this->updates++;
        }
        return parent::sql_query($sql,$transaction);
    }
}
function sc_sql($sql){$r=$GLOBALS['db']->sql_query($sql);if($r===false){$e=$GLOBALS['db']->sql_error();throw new RuntimeException('Owned cache SQL failed: '.$e['message']);}return $r;}
function sc_change($sql){$GLOBALS['db']->setup=true;try{return sc_sql($sql);}finally{$GLOBALS['db']->setup=false;}}
function sc_row($id){$r=sc_sql('SELECT * FROM fixture_stats_modules WHERE module_id='.(int)$id);$row=$GLOBALS['db']->sql_fetchrow($r);$GLOBALS['db']->sql_freeresult($r);return $row;}
function sc_record($kind,$raw){
    if($kind==='query'){
        $cache=new StatisticsDB();$cache->begin_cached_query();$sql="SELECT '".$GLOBALS['db']->sql_escape($raw)."' AS value, NULL AS nil";
        $r=$cache->sql_query($sql);sp_check($cache->sql_numrows($r)===1,'Real source query count');$cache->sql_fetchrowset($r);$GLOBALS['db']->sql_freeresult($r);
        $r=$cache->sql_query($sql);$cache->sql_fetchrow($r);$cache->sql_fetchrow($r);$GLOBALS['db']->sql_freeresult($r);
        sp_check(array('n'=>$cache->numrows_data,'fs'=>$cache->fetchrowset_data,'f'=>$cache->fetchrow_data)===sc_query_data($raw,true),'Actual driver recording matches original numeric/associative bytes/types');
    }else{
        $cache=new Results();$cache->begin_cached_results();$cache->init_result_cache();$cache->assign_vars(array('value'=>$raw,'nil'=>null,'flag'=>false,'ratio'=>1.25));
        foreach(array(1,2)as$i){$cache->assign_block_vars('rows',array('value'=>$raw,'number'=>$i));}
        sp_check(array('var_data'=>$cache->var_data)===sc_result_data($raw),'Actual template recording matches original values');
    }return $cache;
}
$db=$control=null;$created=false;$schema='codex_stats_cache_'.bin2hex(phpbb_random_bytes(8));
try{
    $port=getenv('PHPBB_STATS_CACHE_PORT')?:'3306';$password=getenv('PHPBB_STATS_CACHE_PASSWORD')?:'';
    sp_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Own loopback port');$host='127.0.0.1:'.$port;
    $control=new sql_db($host,'root',$password,'',false);sp_check((bool)$control->db_connect_id,'Own server connection');
    sp_check(preg_match('/^codex_stats_cache_[a-f0-9]{16}$/D',$schema)&&$control->sql_query('CREATE DATABASE '.$schema.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Exclusive own schema');$created=true;
    $db=new StatisticsCacheNativeDatabase($host,'root',$password,$schema,false);sp_check((bool)$db->db_connect_id,'Own canonical cache database');
    $canonical=file_get_contents($sp_root.'install/schemas/mysql_schema.sql');sp_check(preg_match('/CREATE TABLE phpbb_stats_modules \(.*?\) ENGINE=InnoDB[^;]*;/s',$canonical,$m)===1,'Canonical InnoDB cache BLOB table');sc_sql(str_replace('phpbb_stats_modules','fixture_stats_modules',$m[0]));
    sc_sql("INSERT INTO fixture_stats_modules(module_id,name,module_db_cache,module_result_cache) VALUES(1,'target','old-query','old-result'),(2,'canary','canary-query','canary-result')");$db->setup=false;$canary=sc_row(2);
    foreach(array('STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION','STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION,NO_BACKSLASH_ESCAPES')as$mode){
        sc_change("SET SESSION sql_mode='".$mode."'");
        foreach(sc_samples()as$raw){foreach(array('query','result')as$kind){
            $column=$kind==='query'?'module_db_cache':'module_result_cache';$other=$kind==='query'?'module_result_cache':'module_db_cache';
            $before=sc_row(1);$cache=sc_record($kind,$raw);$data=$kind==='query'?sc_query_data($raw,true):sc_result_data($raw);$expected=serialize($data);$cache->end_cached_query(1);$after=sc_row(1);
            sp_check($after[$column]===$expected,'Actual cache writer persists byte-exact canonical serialization in either SQL mode');
            sp_check($after[$other]===$before[$other]&&sc_row(2)===$canary,'Other cache column and unrelated module remain unchanged');
            $loaded=sc_reader($kind,$after[$column],$raw,true);$writes=$db->updates;$loaded->end_cached_query(1);sp_check($db->updates===$writes&&sc_row(1)===$after,'Cache hit does not overwrite cache bytes or timestamp');$sc_cases++;
            $legacy=addslashes($expected);sc_change("UPDATE fixture_stats_modules SET ".$column."='".$db->sql_escape($legacy)."' WHERE module_id=1");$legacyRow=sc_row(1);$loaded=sc_reader($kind,$legacyRow[$column],$raw,true);$loaded->end_cached_query(1);sp_check(sc_row(1)===$legacyRow&&sc_row(2)===$canary,'Legacy decoding never rewrites existing data on a hit');$sc_cases++;
        }}
    }
    foreach(array('query','result')as$kind){$column=$kind==='query'?'module_db_cache':'module_result_cache';$cache=$kind==='query'?new StatisticsDB():new Results();
        if($kind==='query'){$cache->begin_cached_query(true,'invalid');}else{$cache->begin_cached_results(true,'invalid');}
        sp_check(!$cache->use_cache,'Invalid cache falls back to collection');$cache->end_cached_query(1);sp_check(sc_row(1)[$column]===''&&sc_row(2)===$canary,'Unused invalid cache is cleared only for the target module');$sc_cases++;
    }
    echo 'Statistics cache native: ',$sc_cases,' cases, ',$sp_checks," assertions PASS; actual canonical InnoDB cache writes/read APIs, binary/Unicode/backslashes, two SQL modes, legacy frames and unchanged canary data.\n";
}finally{if($db){$db->sql_close();}if($created){sp_check(preg_match('/^codex_stats_cache_[a-f0-9]{16}$/D',$schema)&&$control->sql_query('DROP DATABASE '.$schema),'Drop exact owned cache schema');}if($control){$control->sql_close();}restore_error_handler();}
