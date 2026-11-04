<?php
// Actual tracking branch, real database errors, disposable canonical tables.
if (PHP_SAPI !== 'cli' || getenv('PHPBB_BANNER_CLICK_NATIVE') !== '1') {
    echo "Banner click checks require an explicitly enabled disposable database.\n"; return;
}
define('IN_PHPBB',true);define('GENERAL_ERROR',1);
define('BANNERS_TABLE','fixture_banner');define('BANNER_STATS_TABLE','fixture_banner_stats');
$root=dirname(dirname(__DIR__)).'/phpBB2/';
require $root.'includes/php_compat.php';require $root.'db/mysqli.php';
function bc_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
class BannerClickExit extends RuntimeException {}
function message_die($level,$message){throw new BannerClickExit($message);}
function phpbb_setcookie($name,$value,$expires,$path,$domain,$secure){$GLOBALS['bc_cookies'][]=array($name,$value,$expires,$path,$domain,$secure);}
$source=file_get_contents($root.'redirect.php');
$function=strpos($source,'function phpbb_record_banner_click(');
if($function!==false){$end=strpos($source,'$banner_id_value =',$function);bc_check($end>$function,'Complete actual click writer');eval(substr($source,$function,$end-$function));}
$start=strpos($source,'$cookie_name =');$end=strpos($source,'$db->sql_close();',$start);
bc_check($start!==false&&$end>$start,'Complete actual tracking and cookie branch');$bc_body=substr($source,$start,$end-$start);
function bc_track($cookies=array(),$data=array(),$filter=600){
    global $db,$userdata,$board_config,$banner_id,$banner_data,$HTTP_COOKIE_VARS,$bc_cookies,$bc_body,$lang;
    $banner_id=1;$banner_data=array('banner_filter_time'=>$filter);$HTTP_COOKIE_VARS=$cookies;$bc_cookies=array();
    $userdata=array_merge(array('session_ip'=>'c0000201','user_id'=>7,'session_start'=>1000,'session_time'=>1100),$data);
    $board_config=array('cookie_name'=>'fixture','cookie_path'=>'/','cookie_domain'=>'','cookie_secure'=>true,'session_length'=>300);
    $lang=array('Banner_click_failed'=>'Banner click could not be saved.');
    try{eval($bc_body);return true;}catch(BannerClickExit$error){return false;}
}
class BannerClickSource extends sql_db {
    var $factory=null;var $factory_calls=0;var $identity_failure=false;
    function sql_dedicated_connection(){
        $this->factory_calls++;
        if($this->factory!==null){return call_user_func($this->factory);}
        $writer=new BannerClickWriter($this->server,$this->user,$this->password,$this->dbname,false);
        bc_check($writer->sql_query("SET SESSION sql_mode='".$GLOBALS['bc_mode']."'"),'Owned writer SQL mode');
        $writer->hook=$GLOBALS['bc_hook'];return$writer;
    }
    function sql_query($sql='',$transaction=false){
        if($this->identity_failure&&strpos($sql,'SELECT DATABASE()')===0){return false;}
        return parent::sql_query($sql,$transaction);
    }
}
class BannerClickWriter extends sql_db {
    var $hook=null;
    function sql_query($sql='',$transaction=false){
        if($this->hook!==null){$r=call_user_func($this->hook,$this,$sql);if($r!==null){return$r;}}
        return parent::sql_query($sql,$transaction);
    }
    function direct($sql){return parent::sql_query($sql);}
}
function bc_sql($sql){$r=$GLOBALS['peer']->sql_query($sql);bc_check($r!==false,'Owned fixture query');return$r;}
function bc_rows($sql){$r=bc_sql($sql);try{return$GLOBALS['peer']->sql_fetchrowset($r);}finally{$GLOBALS['peer']->sql_freeresult($r);}}
function bc_state(){return array(bc_rows('SELECT banner_id,banner_click FROM fixture_banner ORDER BY banner_id'),bc_rows('SELECT * FROM fixture_banner_stats ORDER BY click_date,click_user'));}
function bc_reset(){
    bc_sql('DROP TRIGGER IF EXISTS fixture_reject_stats');bc_sql('DROP TRIGGER IF EXISTS fixture_reject_counter');bc_sql('TRUNCATE TABLE fixture_banner_stats');bc_sql('TRUNCATE TABLE fixture_banner');
    bc_sql("INSERT INTO fixture_banner (banner_id,banner_name,banner_spot,banner_forum,banner_description,banner_url,banner_owner,banner_click,banner_view,banner_weigth,banner_active,banner_timetype,time_begin,time_end,date_begin,date_end,banner_level,banner_level_type,banner_comment,banner_type,banner_width,banner_height,banner_filter,banner_filter_time) VALUES(1,'Synthetic',0,0,'Fixture','https://example.invalid/',7,0,0,50,1,0,0,0,0,0,0,0,'',2,0,0,1,600)");
}
$port=getenv('PHPBB_BANNER_CLICK_PORT')?:'3306';bc_check(preg_match('/^[0-9]{1,5}$/D',$port)&&$port>0&&$port<=65535,'Loopback fixture port');
$host='127.0.0.1:'.$port;$password=getenv('PHPBB_BANNER_CLICK_PASSWORD')?:'';
$fixture='codex_banner_click_'.bin2hex(phpbb_random_bytes(8));$control=new sql_db($host,'root',$password,'',false);
bc_check($control->sql_query('CREATE DATABASE '.$fixture.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Owned fixture schema');
$db=$peer=null;$bc_hook=null;$bc_mode='';$cases=0;
try{
    $db=new BannerClickSource($host,'root',$password,$fixture,false);$peer=new sql_db($host,'root',$password,$fixture,false);
    $schema=file_get_contents($root.'install/schemas/mysql_schema.sql');
    foreach(array('banner','banner_stats')as$table){bc_check(preg_match('/CREATE TABLE phpbb_'.$table.' \([\s\S]+?;/',$schema,$m)===1,'Canonical click schema');bc_sql(str_replace('phpbb_'.$table,'fixture_'.$table,$m[0]));}
    bc_sql('CREATE TABLE fixture_sentinel(id INT PRIMARY KEY) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4');
    foreach(array('','NO_BACKSLASH_ESCAPES','STRICT_TRANS_TABLES','STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION,ANSI_QUOTES,NO_BACKSLASH_ESCAPES')as$bc_mode){
        bc_sql("SET SESSION sql_mode='$bc_mode'");bc_check($db->sql_query("SET SESSION sql_mode='$bc_mode'"),'Source SQL mode');
        foreach(array(array(),array('user_id'=>-1,'session_time'=>800),array('session_ip'=>"A'B\\C"))as$data){
            bc_reset();$now=time();$checks=0;
            $bc_hook=function($writer,$sql)use(&$checks){if(strpos($sql,'UPDATE fixture_banner ')===0||strpos($sql,'INSERT INTO fixture_banner_stats ')===0||$sql==='COMMIT'){bc_check(count($GLOBALS['bc_cookies'])===0,'Cookie is absent before confirmed commit');$checks++;}return null;};
            bc_check(bc_track(array(),$data)&&$checks===3,'Actual tracking reaches both writes and commit');$bc_hook=null;
            $state=bc_state();bc_check($state[0][0]['banner_click']==='1'&&count($state[1])===1&&count($bc_cookies)===1,'One successful counter/stat/cookie');
            $expected=array_merge(array('user_id'=>7,'session_ip'=>'c0000201','session_time'=>1100,'session_start'=>1000),$data);
            bc_check($state[1][0]['click_ip']===$expected['session_ip']&&(int)$state[1][0]['click_user']===$expected['user_id']&&(int)$state[1][0]['user_duration']===max(0,$expected['session_time']-$expected['session_start']+300),'Exact account/IP/nonnegative duration retained');
            bc_check($bc_cookies[0][0]==='fixture_b_1'&&$bc_cookies[0][1]===1&&$bc_cookies[0][2]>=$now+600&&$bc_cookies[0][2]<=time()+600&&array_slice($bc_cookies[0],3)===array('/','',true),'Established cookie scope/secure/expiry preserved');$cases++;
            $calls=$db->factory_calls;bc_check(bc_track(array('fixture_b_1'=>'1'))&&bc_state()===$state&&count($bc_cookies)===0&&$db->factory_calls===$calls,'Filtered repeat makes no transaction or writes');$cases++;
        }
        foreach(array('stats','counter')as$failure){
            bc_reset();$before=bc_state();$table=$failure==='stats'?'fixture_banner_stats':'fixture_banner';$operation=$failure==='stats'?'INSERT':'UPDATE';
            bc_sql("CREATE TRIGGER fixture_reject_$failure BEFORE $operation ON $table FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Owned fixture write failure'");
            bc_check(!bc_track()&&bc_state()===$before&&count($bc_cookies)===0,'Real failed '.$failure.' write preserves both tables and cookie');$cases++;
            bc_sql('DROP TRIGGER fixture_reject_'.$failure);bc_check(bc_track()&&bc_rows('SELECT banner_click FROM fixture_banner')[0]['banner_click']==='1'&&count(bc_rows('SELECT * FROM fixture_banner_stats'))===1,'Retry after actual rollback counts exactly once');$cases++;
        }
        bc_reset();bc_sql('DELETE FROM fixture_banner');$before=bc_state();bc_check(!bc_track()&&bc_state()===$before&&count($bc_cookies)===0,'Banner removed after initial read creates no orphan statistic');$cases++;
        foreach(array('16777215'=>'16777216','2147483647'=>'2147483648','9007199254740991'=>'9007199254740992','9223372036854775807'=>'9223372036854775808')as$before_count=>$after_count){
            bc_reset();bc_sql('UPDATE fixture_banner SET banner_click='.$before_count);bc_check(bc_track()&&bc_rows('SELECT banner_click FROM fixture_banner')[0]['banner_click']===(string)$after_count&&count(bc_rows('SELECT * FROM fixture_banner_stats'))===1,'Counter grows exactly beyond old, native-integer and floating-point boundaries');$cases++;
        }
        bc_reset();bc_sql('UPDATE fixture_banner SET banner_click=18446744073709551615');$before=bc_state();bc_check(!bc_track()&&bc_state()===$before&&count($bc_cookies)===0,'Supported counter limit cannot silently clamp or create an unmatched statistic even in non-strict caller mode');$cases++;
        foreach(array('SET TRANSACTION','START TRANSACTION','SELECT banner_id FROM fixture_banner_stats','SELECT ENGINE FROM','UPDATE fixture_banner ','INSERT INTO fixture_banner_stats ','COMMIT')as$failure){
            bc_reset();$before=bc_state();$bc_hook=function($writer,$sql)use($failure){return strpos($sql,$failure)===0?false:null;};
            bc_check(!bc_track()&&bc_state()===$before&&count($bc_cookies)===0,'Control/metadata/write/commit failure never leaves a partial pair: '.$failure);$bc_hook=null;$cases++;
        }
        foreach(array('exception','error')as$kind){
            if($kind==='error'&&PHP_VERSION_ID<70000){continue;}
            bc_reset();$before=bc_state();$bc_hook=function($writer,$sql)use($kind){if(strpos($sql,'INSERT INTO fixture_banner_stats ')===0){if($kind==='error'){throw new Error('Owned fixture error');}throw new RuntimeException('Owned fixture exception');}return null;};
            bc_check(!bc_track()&&bc_state()===$before&&count($bc_cookies)===0,'Thrown write error releases only owned transaction');$bc_hook=null;$cases++;
        }
        // An uncertain COMMIT acknowledgement is never automatically replayed.
        bc_reset();$commits=0;$bc_hook=function($writer,$sql)use(&$commits){if($sql==='COMMIT'){$commits++;bc_check($writer->direct($sql),'Real successful commit before lost acknowledgement');return false;}return null;};
        bc_check(!bc_track()&&$commits===1&&bc_rows('SELECT banner_click FROM fixture_banner')[0]['banner_click']==='1'&&count(bc_rows('SELECT * FROM fixture_banner_stats'))===1&&count($bc_cookies)===0,'Unknown commit acknowledgement retains coherent records without a replay or premature cookie');$bc_hook=null;$cases++;
        foreach(array(false,true)as$fail){
            bc_reset();bc_check($db->sql_query('START TRANSACTION')&&$db->sql_query('INSERT INTO fixture_sentinel VALUES(1)'),'Caller owns unrelated pending work');
            if($fail){bc_sql("CREATE TRIGGER fixture_reject_stats BEFORE INSERT ON fixture_banner_stats FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Owned fixture write failure'");}
            bc_check(bc_track()===!$fail&&count(bc_rows('SELECT * FROM fixture_sentinel'))===0,'Writer does not commit caller transaction');
            $r=$db->sql_query('SELECT id FROM fixture_sentinel');bc_check($r&&count($db->sql_fetchrowset($r))===1,'Caller pending work has not been rolled back');$db->sql_freeresult($r);
            bc_check($db->sql_query('ROLLBACK')&&count(bc_rows('SELECT * FROM fixture_sentinel'))===0,'Caller can roll back its own unrelated work');$cases++;
        }
    }
    $bc_mode='STRICT_ALL_TABLES';
    foreach(array('self','shared','absent','malformed','wrong-schema','foreign-transaction')as$kind){
        bc_reset();$before=bc_state();$foreign=null;
        if($kind==='self'){$db->factory=function()use($db){return$db;};}
        elseif($kind==='shared'){$shared=clone$db;$db->factory=function()use($shared){return$shared;};}
        elseif($kind==='absent'){$db->factory=function(){return false;};}
        elseif($kind==='malformed'){$db->factory=function(){return(object)array('db_connect_id'=>'invalid');};}
        elseif($kind==='wrong-schema'){$db->factory=function()use($control){return$control;};}
        else{$foreign=new sql_db($host,'root',$password,$fixture,false);bc_check($foreign->sql_query('START TRANSACTION')&&$foreign->sql_query('INSERT INTO fixture_sentinel VALUES(2)'),'Foreign factory connection has pending work');$db->factory=function()use($foreign){return$foreign;};}
        try{
            bc_check(!bc_track()&&bc_state()===$before&&count($bc_cookies)===0,'Invalid borrowed connection cannot record a click: '.$kind);
            bc_check($db->sql_query('SELECT 1')&&$control->sql_query('SELECT 1'),'Rejected alias/cross-schema connection not closed');
            if($foreign){$r=$foreign->sql_query('SELECT id FROM fixture_sentinel');bc_check($r&&count($foreign->sql_fetchrowset($r))===1&&count(bc_rows('SELECT * FROM fixture_sentinel'))===0,'Foreign pending transaction neither committed, rolled back nor closed');$foreign->sql_freeresult($r);}
        }finally{$db->factory=null;if($foreign){$foreign->sql_query('ROLLBACK');$foreign->sql_close();}}
        $cases++;
    }
    foreach(array('fixture_banner','fixture_banner_stats')as$table){
        bc_reset();bc_sql('ALTER TABLE '.$table.' ENGINE=MyISAM');$before=bc_state();
        try{bc_check(!bc_track()&&bc_state()===$before&&count($bc_cookies)===0,'Legacy nontransactional table cannot permit partial writes');}
        finally{bc_sql('ALTER TABLE '.$table.' ENGINE=InnoDB ROW_FORMAT=DYNAMIC');}$cases++;
    }
    bc_reset();$before=bc_state();$calls=$db->factory_calls;$db->identity_failure=true;
    try{bc_check(!bc_track()&&bc_state()===$before&&count($bc_cookies)===0&&$db->factory_calls===$calls,'Unavailable caller identity cannot open a writer or consume the cookie');}
    finally{$db->identity_failure=false;}$cases++;
    foreach(array('fixture_banner','fixture_banner_stats')as$table){
        bc_reset();bc_sql('SET SESSION lock_wait_timeout=1');$denied=false;
        $bc_hook=function($writer,$sql)use($table,&$denied){
            if(strpos($sql,'UPDATE fixture_banner ')===0){
                $denied=$GLOBALS['peer']->sql_query('ALTER TABLE '.$table.' ENGINE=MyISAM')===false;
                bc_check($denied,'Metadata locks prevent engine replacement after both storage checks');
            }
            return null;
        };
        try{bc_check(bc_track()&&$denied,'Both real table metadata locks survive until commit');}
        finally{$bc_hook=null;bc_sql('ALTER TABLE '.$table.' ENGINE=InnoDB ROW_FORMAT=DYNAMIC');}$cases++;
    }
    // Bound the test's real row-lock conflict instead of waiting five seconds.
    require __DIR__.'/native-fixture-locks.php';
    bc_reset();$before=bc_state();bc_sql('START TRANSACTION');$r=bc_sql('SELECT banner_id FROM fixture_banner WHERE banner_id=1 FOR UPDATE');$peer->sql_freeresult($r);
    $bc_hook=function($writer,$sql){if($sql==='SET SESSION innodb_lock_wait_timeout=5'){native_fixture_peer_nowait($writer);return true;}return null;};
    try{bc_check(!bc_track()&&count($bc_cookies)===0,'Real row-lock conflict leaves cookie available');}finally{$bc_hook=null;bc_sql('ROLLBACK');}
    bc_check(bc_state()===$before&&bc_track(),'A real lock conflict rolls back its own work and allows later success');$cases++;
    echo 'Native banner clicks: '.$cases." actual tracking/rollback/cookie/connection cases PASS.\n";
}finally{
    if($db){$db->sql_close();}if($peer){$peer->sql_close();}
    bc_check($control->sql_query('DROP DATABASE '.$fixture),'Remove only owned fixture schema');$control->sql_close();
}
