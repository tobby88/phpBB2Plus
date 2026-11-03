<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_BIRTHDAY_POPUP_NATIVE')!=='1'){echo "Birthday popup native checks require an explicitly enabled disposable database.\n";return;}
require __DIR__.'/birthday-popup-fixture.php';require $bp_root.'db/mysqli.php';
class BirthdayPopupNativeDatabase extends sql_db{
    var $setup=true;
    function sql_query($sql='',$transaction=false){if(!$this->setup&&!preg_match('/^SELECT\b/i',ltrim($sql))){throw new RuntimeException('Birthday popup must not alter account state');}return parent::sql_query($sql,$transaction);}
}
function bn_sql($sql){$r=$GLOBALS['db']->sql_query($sql);if($r===false){$error=$GLOBALS['db']->sql_error();throw new RuntimeException('Own birthday fixture SQL failed: '.$error['message']);}return $r;}
function bn_change($days){$GLOBALS['db']->setup=true;try{bn_sql('UPDATE fixture_users SET user_birthday='.(int)$days.' WHERE user_id=7');}finally{$GLOBALS['db']->setup=false;}}
function bn_actor($id=7){$r=bn_sql('SELECT * FROM fixture_users WHERE user_id='.(int)$id);$row=$GLOBALS['db']->sql_fetchrow($r);$GLOBALS['db']->sql_freeresult($r);$row['session_logged_in']=true;$row['template_name']='fisubsilversh';return $row;}
function bn_snapshot(){$r=bn_sql('SELECT * FROM fixture_users ORDER BY user_id');$rows=$GLOBALS['db']->sql_fetchrowset($r);$GLOBALS['db']->sql_freeresult($r);return serialize($rows);}
$db=$control=null;$created=false;$schema='codex_birthday_popup_'.bin2hex(phpbb_random_bytes(8));
try{
    $port=getenv('PHPBB_BIRTHDAY_POPUP_PORT')?:'3306';$password=getenv('PHPBB_BIRTHDAY_POPUP_PASSWORD')?:'';
    bp_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Own loopback database port');$host='127.0.0.1:'.$port;
    $control=new sql_db($host,'root',$password,'',false);bp_check((bool)$control->db_connect_id,'Own server connection');
    bp_check(preg_match('/^codex_birthday_popup_[a-f0-9]{16}$/D',$schema)&&$control->sql_query('CREATE DATABASE '.$schema.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Exclusive owned schema');$created=true;
    $db=new BirthdayPopupNativeDatabase($host,'root',$password,$schema,false);bp_check((bool)$db->db_connect_id,'Own database connection');
    $canonical=file_get_contents($bp_root.'install/schemas/mysql_schema.sql');bp_check(preg_match('/CREATE TABLE phpbb_users \(.*?\) ENGINE=InnoDB[^;]*;/s',$canonical,$m)===1,'Canonical InnoDB users schema');bn_sql(str_replace('phpbb_users','fixture_users',$m[0]));
    bn_sql("INSERT INTO fixture_users(user_id,username,user_password,user_birthday,user_next_birthday_greeting) VALUES(7,'Synthetic member','',999999,2035),(8,'Canary','',999999,2034),(-1,'Anonymous','',7246,2033)");$db->setup=false;
    foreach(array('english','german')as$locale){bp_locale($locale);
        foreach(bp_vectors()as$vector){bn_change(bp_days($vector[0]));$actor=bn_actor();$before=bn_snapshot();bp_verify(bp_run($actor,$vector[1],$vector[2],$vector[3]),$vector);bp_check(bn_snapshot()===$before,'Actual rendered popup preserves canonical accounts and annual counters');}
        foreach(array(999999,20101103,-999999,999998,bp_days('20991231'))as$days){bn_change($days);$before=bn_snapshot();bp_rejected(bn_actor());bp_check(bn_snapshot()===$before,'Invalid canonical value cannot alter current or other accounts');}
        bn_change(bp_days('19891103'));$actor=bn_actor();$actor['session_logged_in']=false;$before=bn_snapshot();bp_rejected($actor);bp_rejected(bn_actor(-1));bp_check(bn_snapshot()===$before,'Guest and anonymous popup denial leaves all counters untouched');
    }
    echo 'Birthday popup native: ',$bp_cases,' cases, ',$bp_checks," assertions PASS; canonical InnoDB day counters, actual popup/template, localized boundary cases and unchanged account/greeting state.\n";
}finally{if($db){$db->sql_close();}if($created){bp_check(preg_match('/^codex_birthday_popup_[a-f0-9]{16}$/D',$schema)&&$control->sql_query('DROP DATABASE '.$schema),'Drop exact owned birthday schema');}if($control){$control->sql_close();}restore_error_handler();}
