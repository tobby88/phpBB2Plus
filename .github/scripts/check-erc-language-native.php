<?php
if (PHP_SAPI !== 'cli' || getenv('PHPBB_ERC_LANGUAGE_NATIVE') !== '1') { return; }
define('IN_PHPBB', true); define('ADMIN', 1); define('USERS_TABLE', 'fixture_users'); define('CONFIG_TABLE', 'fixture_config');
$root = dirname(dirname(__DIR__)) . '/phpBB2/';
require $root . 'includes/php_compat.php';
$source = file_get_contents($root . 'includes/functions_dbmtnc.php');
$a = strpos($source, 'function check_authorisation('); $b = strpos($source, 'function success_message(', $a);
if ($a === false || $b <= $a || eval(substr($source, $a, $b-$a)) === false) { throw new RuntimeException('Actual ERC functions unavailable'); }
function ercl_check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
function phpbb_realpath($path) { return realpath($path); }
class ErcLanguageFailure extends RuntimeException {}
function erc_throw_error($message) { throw new ErcLanguageFailure($message); }
function success_message($message) { $GLOBALS['ercl_message'] = $message; }
class ErcLanguageDatabase
{
    public $native, $hook=null, $fail='', $lostAck=false, $queries=array();
    function __construct($native) { $this->native=$native; }
    function sql_query($sql)
    {
        $this->queries[]=$sql;
        if ($this->hook) { $hook=$this->hook; $hook($sql,$this); }
        $failed=$this->fail!=='' && strpos($sql,$this->fail)===0;
        if ($failed && !$this->lostAck) { return false; }
        $result=mysqli_query($this->native,$sql);
        ercl_check($result!==false,'Native query failed: '.mysqli_error($this->native));
        return $failed ? false : $result;
    }
    function sql_fetchrow($r) { return mysqli_fetch_assoc($r); }
    function sql_freeresult($r) { mysqli_free_result($r); }
    function sql_escape($value) { return mysqli_real_escape_string($this->native,$value); }
    function sql_affectedrows() { return mysqli_affected_rows($this->native); }
}
mysqli_report(MYSQLI_REPORT_OFF);
$port=getenv('PHPBB_ERC_LANGUAGE_PORT')?:'3306'; $password=getenv('PHPBB_ERC_LANGUAGE_PASSWORD')?:'';
ercl_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<65536,'Invalid fixture port');
$control=mysqli_connect('127.0.0.1','root',$password,'',(int)$port); ercl_check($control!==false,'Loopback fixture unavailable');
$schema='codex_erc_language_'.bin2hex(function_exists('random_bytes')?random_bytes(8):openssl_random_pseudo_bytes(8));
ercl_check(mysqli_query($control,'CREATE DATABASE '.$schema.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Owned fixture creation');
$native=$peer=null; $phpbb_root_path=sys_get_temp_dir().'/'.$schema.'/';
try
{
    ercl_check(mkdir($phpbb_root_path)&&mkdir($phpbb_root_path.'cache')&&mkdir($phpbb_root_path.'language'),'Owned file fixture');
    foreach(array('english','german') as $language)
    {
        mkdir($phpbb_root_path.'language/lang_'.$language);
        foreach(array('main','admin') as $file){file_put_contents($phpbb_root_path.'language/lang_'.$language.'/lang_'.$file.'.php','<?php // Fixture, never included.');}
    }
    set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
    $native=mysqli_connect('127.0.0.1','root',$password,$schema,(int)$port);$peer=mysqli_connect('127.0.0.1','root',$password,$schema,(int)$port);
    ercl_check($native&&$peer,'Independent connections');mysqli_set_charset($native,'utf8mb4');mysqli_set_charset($peer,'utf8mb4');
    $db=new ErcLanguageDatabase($native);$dbuser='fixture-owner';$dbpasswd='fixture-owner-password';$option='rld';$phpEx='php';
    $lang=array('rld_success'=>'saved','rld_failed'=>'failed','ERC_language_failed'=>'failed');
    $source=file_get_contents($root.'admin/erc.php');$execute=strpos($source,"case 'execute':");$a=strpos($source,"case 'rld':",$execute);$b=strpos($source,"case 'rtd':",$a);
    ercl_check($a!==false&&$b>$a,'Actual language controller');$code="switch('rld'){".substr($source,$a,$b-$a).'}';
    function ercl_sql($sql){ercl_check(mysqli_query($GLOBALS['peer'],$sql)!==false,'Fixture mutation failed: '.mysqli_error($GLOBALS['peer']));}
    function ercl_rows()
    {
        $out=array();foreach(array('users'=>'user_id','config'=>'config_name,config_value') as $table=>$order)
        {
            $r=mysqli_query($GLOBALS['peer'],'SELECT * FROM fixture_'.$table.' ORDER BY '.$order);$out[$table]=array();
            while($row=mysqli_fetch_assoc($r)){$out[$table][]=$row;}mysqli_free_result($r);
        }return $out;
    }
    function ercl_reset()
    {
        global $db,$HTTP_POST_VARS,$phpbb_root_path,$board_config;
        $db->hook=null;$db->fail='';$db->lostAck=false;$db->queries=array();
        ercl_sql('DELETE FROM fixture_users');ercl_sql('DELETE FROM fixture_config');
        ercl_sql("INSERT INTO fixture_users VALUES (2,'Admin','".md5('Fixture!9')."',1,1,'english'),(3,'Other','',1,0,'english')");
        ercl_sql("INSERT INTO fixture_config VALUES ('default_lang','english'),('unrelated','keep')");
        $HTTP_POST_VARS=array('auth_method'=>'board','board_user'=>'Admin','board_password'=>'Fixture!9','new_lang'=>'german');$board_config=array('default_lang'=>'english');
        file_put_contents($phpbb_root_path.'cache/config_data.cache','stale');file_put_contents($phpbb_root_path.'cache/unrelated.cache','keep');
    }
    function ercl_run()
    {
        global $db,$HTTP_POST_VARS,$lang,$code,$phpbb_root_path,$phpEx;
        $GLOBALS['ercl_message']='';$error='';ob_start();
        try{eval($code);}catch(ErcLanguageFailure $e){$error=$e->getMessage();}finally{ob_end_clean();}
        return array($GLOBALS['ercl_message'],$error);
    }
    $cases=0;
    foreach(array('InnoDB','MyISAM') as $engine)
    {
        ercl_sql('DROP TABLE IF EXISTS fixture_users');ercl_sql('DROP TABLE IF EXISTS fixture_config');
        ercl_sql('CREATE TABLE fixture_users(user_id INT PRIMARY KEY,username VARCHAR(255),user_password VARCHAR(255),user_active INT,user_level INT,user_lang VARCHAR(255)) ENGINE='.$engine.' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        // No unique key: recovery must also fail closed on damaged legacy tables.
        ercl_sql('CREATE TABLE fixture_config(config_name VARCHAR(191),config_value TEXT) ENGINE='.$engine.' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        ercl_reset();$out=ercl_run();$after=ercl_rows();
        ercl_check($out[0]==='saved'&&$out[1]===''&&$after['users'][0]['user_lang']==='german'&&$after['users'][1]['user_lang']==='english'&&$after['config'][0]['config_value']==='german','Only authenticated actor and board language change');$cases++;
        foreach(array('demoted','inactive','password','renamed','rebound','config-missing','config-duplicate','config-alias') as $race)
        {
            ercl_reset();$injected=null;
            $db->hook=function($sql,$connection)use($race,&$injected){
                if(strpos($sql,'UPDATE fixture_users')!==0){return;}$connection->hook=null;
                if($race==='demoted'){ercl_sql('UPDATE fixture_users SET user_level=0 WHERE user_id=2');}
                elseif($race==='inactive'){ercl_sql('UPDATE fixture_users SET user_active=0 WHERE user_id=2');}
                elseif($race==='password'){ercl_sql("UPDATE fixture_users SET user_password='changed' WHERE user_id=2");}
                elseif($race==='renamed'){ercl_sql("UPDATE fixture_users SET username='Changed' WHERE user_id=2");}
                elseif($race==='rebound'){ercl_sql('UPDATE fixture_users SET user_id=8 WHERE user_id=2');}
                elseif($race==='config-missing'){ercl_sql("DELETE FROM fixture_config WHERE config_name='default_lang'");}
                elseif($race==='config-duplicate'){ercl_sql("INSERT INTO fixture_config VALUES ('default_lang','independent')");}
                else{ercl_sql("UPDATE fixture_config SET config_name='DEFAULT_LANG' WHERE config_name='default_lang'");}
                $injected=ercl_rows();
            };
            $out=ercl_run();ercl_check($injected!==null&&$out[0]===''&&$out[1]!==''&&ercl_rows()===$injected,'Dispatch race cannot change language: '.$race);$cases++;
        }
        ercl_reset();$out=ercl_run();$saved=ercl_rows();
        ercl_check(!file_exists($phpbb_root_path.'cache/config_data.cache')&&file_get_contents($phpbb_root_path.'cache/unrelated.cache')==='keep','Only config cache expires');
        file_put_contents($phpbb_root_path.'cache/config_data.cache','stale');$out=ercl_run();
        ercl_check($out[0]==='saved'&&ercl_rows()===$saved&&!file_exists($phpbb_root_path.'cache/config_data.cache'),'Same-language retry is harmless and clears stale cache');$cases+=2;
        foreach(array(null,array('german'),'../lang_german','german/','GERMAN',"german\0",'',"german'",'missing') as $invalid)
        {
            ercl_reset();$before=ercl_rows();$HTTP_POST_VARS['new_lang']=$invalid;$out=ercl_run();
            ercl_check($out[0]===''&&$out[1]!==''&&ercl_rows()===$before,'Malformed/uninstalled language is rejected before writes');$cases++;
        }
        ercl_reset();unlink($phpbb_root_path.'language/lang_german/lang_admin.php');$before=ercl_rows();$out=ercl_run();
        ercl_check($out[0]===''&&$out[1]!==''&&ercl_rows()===$before,'Incomplete language pack cannot be selected');
        file_put_contents($phpbb_root_path.'language/lang_german/lang_admin.php','<?php');$cases++;
        foreach(array('read','write','ack','confirm') as $fault)
        {
            ercl_reset();$before=ercl_rows();
            if($fault==='read'){$db->fail='SELECT config_name';}
            elseif($fault==='confirm'){$db->hook=function($sql,$connection){if(strpos($sql,'UPDATE fixture_users')===0){$connection->hook=null;$connection->fail='SELECT config_name';}};}
            else{$db->fail='UPDATE fixture_users';$db->lostAck=$fault==='ack';}
            $out=ercl_run();ercl_check($out[0]===''&&$out[1]!=='','Failed/unknown outcome cannot report success');
            if($fault==='read'||$fault==='write'){ercl_check(ercl_rows()===$before,'Failed dispatch leaves stored language intact');}
            ercl_check(file_exists($phpbb_root_path.'cache/config_data.cache')===($fault==='read'),'Cache expires after attempted write');
            $writes=array_filter($db->queries,function($sql){return strpos($sql,'UPDATE fixture_users')===0;});ercl_check(count($writes)<=1,'No automatic retry or sequential partial update');
            $db->fail='';$db->lostAck=false;$db->hook=null;ercl_check(ercl_run()[0]==='saved','Explicit retry completes safely');$cases++;
        }
        ercl_reset();unlink($phpbb_root_path.'cache/config_data.cache');mkdir($phpbb_root_path.'cache/config_data.cache');$out=ercl_run();
        ercl_check($out[0]===''&&$out[1]!==''&&ercl_rows()['users'][0]['user_lang']==='german','Cache failure reports incomplete outcome, not rollback');rmdir($phpbb_root_path.'cache/config_data.cache');$cases++;
        foreach(array('md5','bcrypt') as $scheme)
        {
            ercl_reset();$candidate=addslashes("Grüße'\\!9");$hash=$scheme==='md5'?md5($candidate):password_hash($candidate,PASSWORD_BCRYPT);
            ercl_sql("UPDATE fixture_users SET user_password='".mysqli_real_escape_string($peer,$hash)."' WHERE user_id=2");$HTTP_POST_VARS['board_user']='ADMIN';$HTTP_POST_VARS['board_password']=$candidate;
            ercl_check(ercl_run()[0]==='saved','Database alias and exact escaped board credential update resolved actor');$cases++;
        }
        foreach(array('missing','duplicate','alias') as $damage)
        {
            ercl_reset();
            if($damage==='missing'){ercl_sql("DELETE FROM fixture_config WHERE config_name='default_lang'");}
            elseif($damage==='duplicate'){ercl_sql("INSERT INTO fixture_config VALUES ('default_lang','independent')");}
            else{ercl_sql("UPDATE fixture_config SET config_name='DEFAULT_LANG' WHERE config_name='default_lang'");}
            $before=ercl_rows();$out=ercl_run();
            ercl_check($out[0]===''&&$out[1]!==''&&ercl_rows()===$before,'Already damaged default setting is rejected before updates');$cases++;
        }
        foreach(array('demoted','inactive','password','independent-language') as $change)
        {
            ercl_reset();$observed=null;
            $db->hook=function($sql,$connection)use($change,&$observed){
                if(strpos($sql,'SELECT user_lang FROM fixture_users')!==0){return;}$connection->hook=null;
                if($change==='demoted'){ercl_sql('UPDATE fixture_users SET user_level=0 WHERE user_id=2');}
                elseif($change==='inactive'){ercl_sql('UPDATE fixture_users SET user_active=0 WHERE user_id=2');}
                elseif($change==='password'){ercl_sql("UPDATE fixture_users SET user_password='changed' WHERE user_id=2");}
                else{ercl_sql("UPDATE fixture_users SET user_lang='independent' WHERE user_id=2");}
                $observed=ercl_rows();
            };
            $out=ercl_run();ercl_check($observed!==null&&$out[0]===''&&$out[1]!==''&&ercl_rows()===$observed,'Final current-authority/outcome verification cannot overwrite independent changes');$cases++;
        }
        ercl_reset();$HTTP_POST_VARS['auth_method']='db';$HTTP_POST_VARS['db_user']=$dbuser;$HTTP_POST_VARS['db_password']=$dbpasswd;$HTTP_POST_VARS['board_password']='wrong';$before=ercl_rows();
        ercl_check(dbmtnc_erc_reset_language('german',2)===false&&ercl_rows()===$before,'Owner credentials cannot substitute for the required board actor');$cases++;
        foreach(array(null,-1,0,'2',8) as $actor)
        {
            ercl_reset();$before=ercl_rows();ercl_check(dbmtnc_erc_reset_language('german',$actor)===false&&ercl_rows()===$before,'Invalid/rebound/non-board actor refused');$cases++;
        }
    }
    echo "ERC language: $cases native actual-controller cases passed.\n";
}
finally
{
    restore_error_handler();
    if($native){mysqli_close($native);}if($peer){mysqli_close($peer);}
    ercl_check(mysqli_query($control,'DROP DATABASE '.$schema),'Owned fixture cleanup');mysqli_close($control);
    foreach(array('english','german') as $language){foreach(array('main','admin') as $file){$path=$phpbb_root_path.'language/lang_'.$language.'/lang_'.$file.'.php';if(is_file($path)){unlink($path);}}if(is_dir($phpbb_root_path.'language/lang_'.$language)){rmdir($phpbb_root_path.'language/lang_'.$language);}}
    if(is_dir($phpbb_root_path.'cache/config_data.cache')){rmdir($phpbb_root_path.'cache/config_data.cache');}
    foreach(array('config_data.cache','unrelated.cache') as $file){if(is_file($phpbb_root_path.'cache/'.$file)){unlink($phpbb_root_path.'cache/'.$file);}}
    foreach(array('cache','language') as $directory){if(is_dir($phpbb_root_path.$directory)){rmdir($phpbb_root_path.$directory);}}if(is_dir($phpbb_root_path)){rmdir($phpbb_root_path);}
}
