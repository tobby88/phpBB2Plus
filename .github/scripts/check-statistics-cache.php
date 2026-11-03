<?php
require __DIR__.'/statistics-cache-fixture.php';
class StatisticsCacheNoDatabase {
    var $escaped=null;
    function sql_query($sql,$transaction=false){throw new RuntimeException('Cache hit unexpectedly touched the database');}
    function sql_escape($raw){$this->escaped=$raw;return 'driver:'.bin2hex($raw);}
}
$db=new StatisticsCacheNoDatabase();
foreach(sc_samples()as$raw){foreach(array('query','result')as$kind){
    $data=$kind==='query'?sc_query_data($raw):sc_result_data($raw);$bytes=serialize($data);
    foreach(array($bytes,addslashes($bytes))as$encoded){
        sp_check(statistics_cache_array($encoded)===$data,'Canonical or legacy decoding preserves all nested bytes');
        $cache=sc_reader($kind,$encoded,$raw);$cache->end_cached_query(1);$sc_cases++;
    }
    sp_check(sql_quote($raw)==='driver:'.bin2hex($raw) && $db->escaped===$raw,'Legacy sql_quote API delegates unchanged data to the active driver');
}}
foreach(sc_bad_frames()as$frame){foreach(array($frame,addslashes($frame))as$encoded){
    foreach(array('query','result')as$kind){$cache=$kind==='query'?new StatisticsDB():new Results();
        if($kind==='query'){$cache->begin_cached_query(true,$encoded);}else{$cache->begin_cached_results(true,$encoded);}
        sp_check(!$cache->use_cache,'Malformed/scalar/object/recursive frames require a fresh query');$sc_cases++;
    }
}}
foreach(array(null,false,7,array('not-a-string'))as$invalid){sp_check(statistics_cache_array($invalid)===array(),'Nonstring input is a cache miss');$sc_cases++;}
$sc_autoloads=0;$loader=function($name){$GLOBALS['sc_autoloads']++;};spl_autoload_register($loader);
$missing='MissingStatisticsCacheFixture';$object='O:'.strlen($missing).':"'.$missing.'":0:{}';
foreach(array($object,'a:1:{s:8:"var_data";a:1:{i:0;'.$object.'}}')as$frame){foreach(array($frame,addslashes($frame))as$encoded){sp_check(statistics_cache_array($encoded)===array(),'Unknown objects are rejected without autoload');$sc_cases++;}}
spl_autoload_unregister($loader);sp_check($sc_autoloads===0,'Cache decoding never invokes autoload');
foreach(array('query','result')as$kind){
    $data=$kind==='query'?sc_query_data('previous'):sc_result_data('previous');$cache=sc_reader($kind,serialize($data),'previous');
    if($kind==='query'){$cache->begin_cached_query(true,'broken');sp_check(!$cache->use_cache && !$cache->numrows_data && !$cache->fetchrowset_data && !$cache->fetchrow_data,'Query miss clears prior request cache state');}
    else{$cache->begin_cached_results(true,'broken');sp_check(!$cache->use_cache && !$cache->var_data,'Result miss clears prior cached values');}$sc_cases++;
}
sp_check($sc_wakeups===0,'Neither cache representation can trigger object wakeup');
echo 'Statistics cache: ',$sc_cases,' cases, ',$sp_checks," assertions PASS; canonical and legacy bytes, cached query/result APIs, driver escaping, invalid-frame refresh and no object execution.\n";
restore_error_handler();
