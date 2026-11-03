<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_STATS_RENDER_NATIVE')!=='1'){echo "Statistics rendering checks require an explicitly enabled disposable database.\n";return;}
require __DIR__.'/statistics-rendering-fixture.php';require $sp_root.'db/mysqli.php';
class StatisticsRenderingNativeDatabase extends sql_db {
    var $setup=true;var $fail_current=false;
    function sql_query($sql='',$transaction=false){
        if(!$this->setup&&!preg_match('/^SELECT\b/i',ltrim($sql))){throw new RuntimeException('Statistics rendering must remain read-only');}
        if($this->fail_current&&strpos($sql,'SELECT user_id, username FROM ')===0&&strpos($sql,'WHERE user_id IN (')!==false){return false;}
        return parent::sql_query($sql,$transaction);
    }
}
function sr_sql($sql){$r=$GLOBALS['db']->sql_query($sql);if($r===false){$error=$GLOBALS['db']->sql_error();throw new RuntimeException('Owned statistics SQL: '.$error['message']);}$GLOBALS['sp_checks']++;return $r;}
function sr_change($sql){$GLOBALS['db']->setup=true;try{return sr_sql($sql);}finally{$GLOBALS['db']->setup=false;}}
function sr_snapshot(){
    $out=array();foreach(array('users','posts','topics','forums','attachments','attachments_desc','config')as$table){$r=sr_sql('SELECT * FROM fixture_'.$table);$out[$table]=$GLOBALS['db']->sql_fetchrowset($r);$GLOBALS['db']->sql_freeresult($r);}return serialize($out);
}
function sr_native_name($key){
    if(isset($GLOBALS['sr_before_names'])){sp_check(sr_snapshot()===$GLOBALS['sr_before_names'],'Every preceding real module read leaves canonical data unchanged');}
    sr_change("UPDATE fixture_users SET username='".$GLOBALS['db']->sql_escape($key)."' WHERE user_id=7");$GLOBALS['sr_before_names']=sr_snapshot();
}
$db=$control=null;$created=false;$schema='codex_stats_render_'.bin2hex(phpbb_random_bytes(8));
try{
    $port=getenv('PHPBB_STATS_RENDER_PORT')?:'3306';$password=getenv('PHPBB_STATS_RENDER_PASSWORD')?:'';
    sp_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Own loopback database port');$host='127.0.0.1:'.$port;
    $control=new sql_db($host,'root',$password,'',false);sp_check((bool)$control->db_connect_id,'Own server connection');
    sp_check(preg_match('/^codex_stats_render_[a-f0-9]{16}$/D',$schema)&&$control->sql_query('CREATE DATABASE '.$schema.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Exclusive owned schema');$created=true;
    $db=new StatisticsRenderingNativeDatabase($host,'root',$password,$schema,false);sp_check((bool)$db->db_connect_id,'Own schema connection');
    $canonical=file_get_contents($sp_root.'install/schemas/mysql_schema.sql');foreach(array('users','posts','topics','forums','attachments','attachments_desc','config')as$table){
        sp_check(preg_match('/CREATE TABLE phpbb_'.$table.' \(.*?\) ENGINE=InnoDB[^;]*;/s',$canonical,$m)===1,'Canonical InnoDB schema');sr_sql(str_replace('phpbb_'.$table,'fixture_'.$table,$m[0]));
    }
    sr_sql("INSERT INTO fixture_users(user_id,username,user_password,user_posts,user_regdate) VALUES(7,'Member','',3,1),(6,'Other','',1,1),(-1,'Anonymous','',0,1)");
    sr_sql('INSERT INTO fixture_forums(forum_id,cat_id) VALUES(3,1),(8,1),(9,1),(10,1)');
    sr_sql("INSERT INTO fixture_topics(topic_id,forum_id,topic_title) VALUES(1,3,'Fixture'),(2,8,'Hidden marker'),(3,9,'Read only marker'),(4,10,'Download only marker')");
    $now=strtotime('2026-11-08 12:00:00 UTC');
    sr_sql("INSERT INTO fixture_posts(post_id,topic_id,forum_id,poster_id,post_time,poster_ip) VALUES(1,1,3,7,$now,'7f000001'),(2,2,8,7,$now,'7f000001'),(3,3,9,7,$now,'7f000001'),(4,4,10,6,$now,'7f000001')");
    sr_sql('INSERT INTO fixture_attachments(attach_id,post_id,user_id_1,user_id_2) VALUES(11,1,7,0),(12,2,7,0),(13,3,7,0),(14,4,6,0)');
    sr_sql("INSERT INTO fixture_attachments_desc(attach_id,physical_filename,real_filename,comment,filesize,download_count,filetime) VALUES(11,'owned11','Fixture','Fixture',2048,9,1),(12,'owned12','Hidden marker','Hidden marker',2048,99,1),(13,'owned13','Read only marker','Read only marker',2048,99,1),(14,'owned14','Download only marker','Download only marker',2048,99,1)");
    sr_sql("INSERT INTO fixture_config(config_name,config_value) VALUES('record_online_users','3'),('record_online_date','1')");$db->setup=false;
    sr_name_suite('sr_native_name');sp_check(sr_snapshot()===$GLOBALS['sr_before_names'],'Last name rendering leaves all tables unchanged');unset($GLOBALS['sr_before_names']);
    sr_cache_suite(function($key){sr_change("UPDATE fixture_users SET username='".$GLOBALS['db']->sql_escape($key)."' WHERE user_id=7");},function(){
        sr_change('DELETE FROM fixture_users WHERE user_id=7');sr_change("INSERT INTO fixture_users(user_id,username,user_password) VALUES(9,'Old account','')");
    },function(){sr_change('DELETE FROM fixture_users WHERE user_id IN (7,9)');sr_change("INSERT INTO fixture_users(user_id,username,user_password,user_posts,user_regdate) VALUES(7,'Member','',3,1)");});
    foreach(sr_excerpt_cases()as$case){
        $value=$db->sql_escape($case[0]);sr_change("UPDATE fixture_topics SET topic_title='$value' WHERE topic_id=1");sr_change("UPDATE fixture_attachments_desc SET real_filename='$value',comment='$value' WHERE attach_id=11");
        $before=sr_snapshot();sr_check_excerpt(sr_attachments(),$case);sp_check(sr_snapshot()===$before,'Actual native attachment JOIN and renderer leave history unchanged');
    }
    $before=sr_snapshot();$denied=sr_attachments(false);sp_check(!$denied->blocks&&sr_snapshot()===$before,'Denied viewer sees no data and cannot modify source');$sr_cases++;
    echo 'Statistics rendering native: ',$sr_cases,' cases, ',$sp_checks," assertions PASS; canonical InnoDB joins, current/legacy names, real cache rename/retirement/name reuse, attachment ACLs, Unicode excerpts and unchanged data.\n";
}finally{
    if($db){$db->sql_close();}if($created){sp_check(preg_match('/^codex_stats_render_[a-f0-9]{16}$/D',$schema)&&$control->sql_query('DROP DATABASE '.$schema),'Drop exact owned schema');}if($control){$control->sql_close();}restore_error_handler();
}
