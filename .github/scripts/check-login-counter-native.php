<?php
// Real credential-controller branch and real counter SQL/driver. Password,
// credential lookup and external logging use the existing isolated boundaries.
if (getenv('PHPBB_LOGIN_COUNTER_NATIVE') !== '1') {
    echo "Failed-login counter checks require an explicitly enabled disposable database.\n"; return;
}
require __DIR__.'/check-login-branches-runtime.php';
require $forum_root.'db/mysqli.php';
function lc_check($ok,$message){if(!$ok){throw new RuntimeException('Native failed-login counter: '.$message);}$GLOBALS['lc_checks']++;}
class LoginCounterDatabase extends LoginBranchDatabase {
    public $native; public $updates=0; public $fail=false; public $affected=null;
    function __construct($native){$this->native=$native;}
    function sql_query($sql){
        parent::sql_query($sql);
        if(strpos($sql,'UPDATE test_users SET user_badlogin = ')!==0){return true;}
        lc_check(preg_match('/WHERE user_id = 42\s*$/D',$sql)===1,'Actual update targets only the DB-resolved account');
        $this->updates++;if($this->fail){return false;}
        $result=$this->native->sql_query($sql);$this->affected=$this->native->sql_affectedrows();return $result;
    }
}
$port=getenv('PHPBB_LOGIN_COUNTER_PORT')?:'3306';$password=getenv('PHPBB_LOGIN_COUNTER_PASSWORD')?:'';
$lc_checks=0;$cases=0;lc_check(preg_match('/\A[0-9]{1,5}\z/',$port)===1&&(int)$port>0&&(int)$port<=65535,'Explicit loopback fixture port');
$host='127.0.0.1:'.$port;$schema='codex_login_counter_'.bin2hex(phpbb_random_bytes(8));
$control=new sql_db($host,'root',$password,'',false);$native=null;$created=false;
try{
    lc_check($control->sql_query('CREATE DATABASE '.$schema.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Own disposable schema');$created=true;
    $native=new sql_db($host,'root',$password,$schema,false);
    $installer=file_get_contents($forum_root.'install/schemas/mysql_schema.sql');
    lc_check(preg_match('/\buser_badlogin\s+([^,\n]+),/',$installer,$column)===1,'Actual installer counter column');
    lc_check($native->sql_query('CREATE TABLE test_users(user_id MEDIUMINT NOT NULL PRIMARY KEY,user_badlogin '.$column[1].') ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'),'Canonical native counter storage');
    $record=array('user_id'=>42,'username'=>'Müller','user_level'=>0,'user_active'=>1,'user_blocktime'=>0,'user_password'=>'synthetic-boundary-hash');
    $read=function()use($native){$r=$native->sql_query('SELECT user_id,user_badlogin FROM test_users ORDER BY user_id');lc_check($r!==false,'Own counter readback');try{return$native->sql_fetchrowset($r);}finally{$native->sql_freeresult($r);}};
    foreach(array('STRICT_ALL_TABLES','STRICT_TRANS_TABLES','')as$mode){
        lc_check($native->sql_query("SET SESSION sql_mode='$mode'"),'Native SQL mode');
        foreach(array(0,1,32766,32767)as$counter){foreach(array(false,true)as$limited){
            lc_check($native->sql_query('DELETE FROM test_users')&&$native->sql_query('INSERT INTO test_users VALUES(42,'.$counter.'),(43,19)'),'Fresh synthetic accounts');
            for($repeat=0;$repeat<2;$repeat++){
                $database=new LoginCounterDatabase($native);$result=run_login_branch($record,'muller',false,true,$limited,$database);
                lc_check($result['outcome']===($limited?'limited':'message')&&$result['names']===array('Müller')&&
                    ($limited||($result['error_code']===GENERAL_MESSAGE&&strpos($result['message'],'failed')===0)),
                    'Full counter retains the ordinary login failure and identity limiter: '.json_encode(array('before'=>$counter,'outcome'=>$result['outcome'],'code'=>$result['error_code'],'limiter_calls'=>count($result['names']))));
                $expected=min(32767,$counter+$repeat+1);$rows=$read();
                lc_check(count($rows)===2&&(string)$rows[0]['user_badlogin']===(string)$expected&&$rows[1]['user_badlogin']==='19','Counter is bounded and unrelated account is untouched');
                lc_check($database->updates===1&&$database->affected===($expected===min(32767,$counter+$repeat)?0:1),'One acknowledged update, including unchanged saturated rows');$cases++;
            }
        }}
    }
    // Preserve deliberately wider/custom historical counters instead of
    // silently reducing their saved value to the canonical signed limit.
    lc_check($native->sql_query('ALTER TABLE test_users MODIFY user_badlogin SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0'),'Own historical wider column');
    lc_check($native->sql_query("SET SESSION sql_mode='STRICT_ALL_TABLES'"),'Strict custom-column path');
    foreach(array(32768,65535)as$counter){
        lc_check($native->sql_query('UPDATE test_users SET user_badlogin='.$counter.' WHERE user_id=42'),'Synthetic wider count');
        $database=new LoginCounterDatabase($native);$result=run_login_branch($record,'Müller',false,true,false,$database);
        lc_check($result['error_code']===GENERAL_MESSAGE&&$result['names']===array('Müller')&&$read()[0]['user_badlogin']===(string)$counter&&$database->affected===0,'Larger historical count remains unchanged without a general error');$cases++;
    }
    foreach(array('inactive','blocked','unknown','correct','session','query-failed')as$kind){
        $before=$read();$user=$record;$database=new LoginCounterDatabase($native);
        if($kind==='inactive'){$user['user_active']=0;}elseif($kind==='blocked'){$user['user_blocktime']=time()+3600;}elseif($kind==='unknown'){$user=false;}elseif($kind==='query-failed'){$database->fail=true;}
        $result=run_login_branch($user,'Müller',$kind==='correct',$kind!=='session',false,$database);
        lc_check($read()===$before,'Unsuccessful preconditions/counter SQL failure do not alter stored accounts');
        $expected_updates=$kind==='query-failed'?1:0;
        lc_check($database->updates===$expected_updates,'Only active failed credentials reach the counter update');
        $expected_names=in_array($kind,array('inactive','blocked','unknown'),true)?array('Müller'):array();
        lc_check($result['names']===$expected_names,'Existing identity limiter conditions remain intact');
        if($kind==='query-failed'){lc_check($result['error_code']===GENERAL_ERROR,'A real counter-query failure still surfaces instead of being mistaken for saturation');}
        if($kind==='correct'){lc_check($result['outcome']==='session-created','Correct credentials still enter owned session publication');}$cases++;
    }
    echo 'Native failed-login counter: '.$cases.' actual controller cases, '.$lc_checks." assertions passed; bounded writes, strict SQL, limiter and existing failures.\n";
}finally{
    if($native){$native->sql_close();}
    if($created&&!$control->sql_query('DROP DATABASE '.$schema)){throw new RuntimeException('Own login counter fixture cleanup failed');}
    $control->sql_close();
}
