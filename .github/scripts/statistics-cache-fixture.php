<?php
require __DIR__.'/statistics-period-fixture.php';
define('MODULES_TABLE','fixture_stats_modules'); $sc_cases=0;
function sc_samples(){return array('', 'ordinary', 'C:\\archive\\photo.jpg', "A\\'B", "double\\\\slash and 'quote'", 'Grüße 😀 漢字',
    "nul\0line\nreturn\r\ttab", 'literal &amp; &#39; <text>', "end\\", str_repeat("ä\\'",40));}
function sc_query_row($raw,$both=false){return $both?array(0=>$raw,'value'=>$raw,1=>null,'nil'=>null):array('value'=>$raw,'nil'=>null);}
function sc_query_data($raw,$both=false){return array('n'=>array(array(1)), 'fs'=>array(array(array(sc_query_row($raw,$both)))),
    'f'=>array(1=>array(sc_query_row($raw,$both),false)));}
function sc_result_data($raw){return array('var_data'=>array('.'=>array(array('value'=>$raw,'nil'=>null,'flag'=>false,'ratio'=>1.25)),
    'rows.'=>array(array('value'=>$raw,'number'=>1),array('value'=>$raw,'number'=>2))));}
function sc_reader($kind,$bytes,$raw,$both=false){
    if($kind==='query'){
        $cache=new StatisticsDB();$cache->begin_cached_query(true,$bytes);sp_check($cache->use_cache,'Valid query cache selected');
        $r=$cache->sql_query('SELECT cached_fixture_only');sp_check($cache->sql_numrows($r)===1,'Cached row count');
        sp_check($cache->sql_fetchrowset($r)===array(sc_query_row($raw,$both)),'Cached rowset bytes/null preserved');
        $r=$cache->sql_query('SELECT second_cached_fixture_only');sp_check($cache->sql_fetchrow($r)===sc_query_row($raw,$both),'Second cached query row');
        sp_check($cache->sql_fetchrow($r)===false,'Cached end-of-result false preserved');
    }else{
        $cache=new Results();$cache->begin_cached_results(true,$bytes);sp_check($cache->use_cache,'Valid result cache selected');
        sp_check($cache->get_var('value')===$raw && $cache->get_var('nil')===null && $cache->get_var('flag')===false && $cache->get_var('ratio')===1.25,'Cached template scalars preserve bytes and types');
        sp_check($cache->block_num_vars('rows')===2,'Cached block count');
        foreach(array(0,1)as$i){sp_check($cache->get_block_var('rows','value',$i)===$raw && $cache->get_block_var('rows','number',$i)===$i+1,'Cached template block bytes and order');}
    }
    return $cache;
}
function sc_bad_frames(){
    $cycle=array();$cycle['self']=&$cycle;
    $deep='value';for($i=0;$i<40;$i++){$deep=array($deep);}
    $nodes=array(array('leaf'));for($i=0;$i<17;$i++){$next=array();$next[0]=&$nodes[$i];$next[1]=&$nodes[$i];$nodes[]=$next;}$dag=$nodes[17];
    $object='O:19:"StatisticsCacheWake":0:{}';
    return array('', 'broken', 'a:1:{', serialize(false), serialize('scalar'), serialize(array()), serialize(array('n'=>1,'fs'=>array(),'f'=>array())),
        serialize(array('var_data'=>1)), $object, 'a:1:{s:8:"var_data";a:1:{i:0;'.$object.'}}',
        serialize(array('var_data'=>$cycle)),serialize(array('var_data'=>$deep)),serialize(array('var_data'=>$dag)));
}
class StatisticsCacheWake {function __wakeup(){$GLOBALS['sc_wakeups']++;}}
$sc_wakeups=0;
