<?php
if (PHP_SAPI !== 'cli' || getenv('PHPBB_ERC_RECOVERY_NATIVE') !== '1') { return; }
define('IN_PHPBB', true); define('ADMIN', 1);
foreach (array('USERS'=>'users','CONFIG'=>'config','TOPICS'=>'topics') as $constant=>$table) { define($constant.'_TABLE','fixture_'.$table); }
$source_override = getenv('PHPBB_ERC_RECOVERY_SOURCE');
$root = $source_override ? realpath($source_override) : dirname(dirname(__DIR__)) . '/phpBB2';
if (!$root || !is_dir($root)) { throw new RuntimeException('Fixture source unavailable'); } $root .= '/';
require $root . 'includes/php_compat.php';
require $root . 'includes/functions_maintenance_config.php';
$source = file_get_contents($root . 'includes/functions_dbmtnc.php');
$a = strpos($source, 'function check_authorisation('); $b = strpos($source, '/**', $a);
if ($a === false || $b <= $a || eval(substr($source, $a, $b-$a)) === false) { throw new RuntimeException('Actual ERC verifier unavailable'); }
function ercrv_check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
class ErcRecoveryFailure extends RuntimeException {}
function erc_throw_error($message) { throw new ErcRecoveryFailure($message); }
function success_message($message) { $GLOBALS['ercrv_success'] = true; }
class ErcRecoveryDatabase
{
    public $native, $hook = null, $fail = '', $lostAck = false, $queries = array();
    function __construct($native) { $this->native = $native; }
    function sql_query($sql, $transaction = false)
    {
        $this->queries[] = $sql;
        if ($this->hook) { $hook = $this->hook; $hook($sql, $this); }
        $failed = $this->fail !== '' && strpos($sql, $this->fail) === 0;
        if ($failed && !$this->lostAck) { return false; }
        $result = mysqli_query($this->native, $sql);
        ercrv_check($result !== false, 'Native fixture query failed: ' . mysqli_error($this->native));
        return $failed ? false : $result;
    }
    function sql_fetchrow($r) { return mysqli_fetch_assoc($r); }
    function sql_fetchrowset($r) { $rows=array(); while($row=mysqli_fetch_assoc($r)){$rows[]=$row;} return $rows; }
    function sql_freeresult($r) { mysqli_free_result($r); }
    function sql_escape($value) { return mysqli_real_escape_string($this->native, $value); }
    function sql_affectedrows() { return mysqli_affected_rows($this->native); }
}
mysqli_report(MYSQLI_REPORT_OFF);
$port = getenv('PHPBB_ERC_RECOVERY_PORT') ?: '3306'; $password = getenv('PHPBB_ERC_RECOVERY_PASSWORD') ?: '';
ercrv_check(preg_match('/^[0-9]{1,5}$/D', $port) && (int)$port > 0 && (int)$port < 65536, 'Invalid fixture port');
$control = mysqli_connect('127.0.0.1', 'root', $password, '', (int)$port); ercrv_check($control !== false, 'Loopback fixture unavailable');
$schema = 'codex_erc_recovery_' . bin2hex(function_exists('random_bytes') ? random_bytes(8) : openssl_random_pseudo_bytes(8));
ercrv_check(mysqli_query($control, 'CREATE DATABASE ' . $schema . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'), 'Owned fixture creation');
$native = $peer = null;
$phpbb_root_path = sys_get_temp_dir() . '/' . $schema . '/';
ercrv_check(mkdir($phpbb_root_path) && mkdir($phpbb_root_path . 'cache'), 'Owned cache fixture');
try
{
    set_error_handler(function($severity, $message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } });
    $native = mysqli_connect('127.0.0.1', 'root', $password, $schema, (int)$port); $peer = mysqli_connect('127.0.0.1', 'root', $password, $schema, (int)$port);
    ercrv_check($native && $peer, 'Independent fixture connections'); mysqli_set_charset($native, 'utf8mb4'); mysqli_set_charset($peer, 'utf8mb4');
    $db = new ErcRecoveryDatabase($native); $dbuser = 'fixture-owner'; $dbpasswd = 'fixture-owner-password'; $option = 'cct'; $phpEx = 'php';
    $lang = array('Restoring_config'=>'Restore','cct_success'=>'Restored','Auth_failed'=>'auth-failed','Maintenance_config_failed'=>'recovery-failed','Maintenance_config_version_unknown'=>'unknown-version');
    $source = file_get_contents($root . 'admin/erc.php'); $execute = strpos($source, "case 'execute':");
    $a = strpos($source, "case 'cct':", $execute); $b = strpos($source, "case 'rpd':", $a);
    ercrv_check($a !== false && $b > $a, 'Actual recovery controller'); $code = "switch('cct'){" . substr($source, $a, $b-$a) . '}';
    // Keep the real include but pin its read-only source location. The runtime
    // root below belongs solely to this fixture's disposable cache directory.
    $code = str_replace("require_once(\$phpbb_root_path . 'includes/functions_maintenance_config.' . \$phpEx);", 'require_once(' . var_export($root . 'includes/functions_maintenance_config.php', true) . ');', $code, $include_count);
    ercrv_check($include_count === 1, 'Actual bootstrap dependency pinned');
    function ercrv_sql($sql) { ercrv_check(mysqli_query($GLOBALS['peer'], $sql) !== false, 'Fixture mutation failed: ' . mysqli_error($GLOBALS['peer'])); }
    function ercrv_rows()
    {
        $r = mysqli_query($GLOBALS['peer'], 'SELECT config_name,config_value FROM fixture_config ORDER BY config_name'); $rows = array();
        while ($row = mysqli_fetch_assoc($r)) { $rows[$row['config_name']]=$row['config_value']; } mysqli_free_result($r); return $rows;
    }
    function ercrv_reset($method = 'board')
    {
        global $db, $HTTP_POST_VARS, $dbuser, $dbpasswd, $phpbb_root_path, $board_config, $default_config;
        $db->hook = null; $db->fail = ''; $db->lostAck = false; $db->queries = array();
        ercrv_sql('DELETE FROM fixture_users'); ercrv_sql('DELETE FROM fixture_config'); ercrv_sql('DELETE FROM fixture_topics');
        ercrv_sql("INSERT INTO fixture_users VALUES (2,'Admin','" . md5('fixture-password') . "',1,1)");
        ercrv_sql("INSERT INTO fixture_config VALUES ('version','.0.23'),('custom','keep'),('board_disable','0')"); ercrv_sql('INSERT INTO fixture_topics VALUES (100),(200)');
        $board_config=array('version'=>'.0.23','board_disable'=>'0');
        $default_config=array('board_disable'=>'0','cookie_secure'=>'0','server_name'=>'old.invalid','server_port'=>'80','script_path'=>'/old/','site_desc'=>"Grüße <b>O'Connor</b>",'board_startdate'=>'0','version'=>'.0.0');
        $_SERVER=array('REQUEST_METHOD'=>'POST','HTTPS'=>'on','SERVER_PROTOCOL'=>'HTTP/1.1','SERVER_NAME'=>'fixture.invalid','SERVER_PORT'=>'443','SCRIPT_NAME'=>'/forum/admin/erc.php');
        $HTTP_POST_VARS=array('auth_method'=>$method,'board_user'=>'Admin','board_password'=>'fixture-password','db_user'=>$dbuser,'db_password'=>$dbpasswd);
        file_put_contents($phpbb_root_path.'cache/config_data.cache','stale'); file_put_contents($phpbb_root_path.'cache/unrelated.cache','keep');
    }
    function ercrv_run()
    {
        global $db,$HTTP_POST_VARS,$lang,$code,$phpbb_root_path,$phpEx,$default_config;
        $GLOBALS['ercrv_success']=false;$error='';ob_start();
        try{eval($code);}catch(ErcRecoveryFailure $e){$error=$e->getMessage();}finally{$html=ob_get_clean();}
        return array($GLOBALS['ercrv_success'],$error,$html);
    }
    $cases=0;
    foreach(array('InnoDB','MyISAM') as $engine)
    {
        foreach(array('users','config','topics') as $table){ercrv_sql('DROP TABLE IF EXISTS fixture_'.$table);}
        ercrv_sql('CREATE TABLE fixture_users (user_id INT PRIMARY KEY,username VARCHAR(255),user_password VARCHAR(255),user_active INT,user_level INT) ENGINE='.$engine.' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        ercrv_sql('CREATE TABLE fixture_config (config_name VARCHAR(191) PRIMARY KEY,config_value TEXT) ENGINE='.$engine.' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        ercrv_sql('CREATE TABLE fixture_topics (topic_time INT) ENGINE='.$engine);
        ercrv_reset();$before=ercrv_rows();$out=ercrv_run();$saved=ercrv_rows();
        ercrv_check($out[0]&&$out[1]===''&&$saved['site_desc']==="Grüße <b>O'Connor</b>"&&$saved['cookie_secure']==='1'&&$saved['board_startdate']==='100','Actual controller restores exact safe defaults');
        foreach($before as $key=>$value){ercrv_check($saved[$key]===$value,'Existing settings/version untouched');}$cases++;
        foreach(array('demoted','inactive','password','renamed','replaced') as $race)
        {
            ercrv_reset();$before=ercrv_rows();$reached=false;
            $db->hook=function($sql,$connection)use($race,&$reached){
                if(strpos($sql,'INSERT INTO fixture_config')!==0){return;}$connection->hook=null;$reached=true;
                if($race==='demoted'){ercrv_sql('UPDATE fixture_users SET user_level=0 WHERE user_id=2');}
                elseif($race==='inactive'){ercrv_sql('UPDATE fixture_users SET user_active=0 WHERE user_id=2');}
                elseif($race==='password'){ercrv_sql("UPDATE fixture_users SET user_password='independent-password' WHERE user_id=2");}
                elseif($race==='renamed'){ercrv_sql("UPDATE fixture_users SET username='Renamed' WHERE user_id=2");}
                else{ercrv_sql('UPDATE fixture_users SET user_id=8 WHERE user_id=2');}
            };
            $out=ercrv_run();ercrv_check($reached&&!$out[0]&&$out[1]!==''&&ercrv_rows()===$before,'Revoked actor cannot restore missing configuration: '.$race);$cases++;
        }
        foreach(array('board','db') as $method)
        {
            ercrv_reset($method);$out=ercrv_run();$saved=ercrv_rows();
            ercrv_check($out[0]&&!file_exists($phpbb_root_path.'cache/config_data.cache')&&file_get_contents($phpbb_root_path.'cache/unrelated.cache')==='keep','Only config cache expires after credential-authorized recovery');
            file_put_contents($phpbb_root_path.'cache/config_data.cache','stale-after-uncertain-outcome');$out=ercrv_run();
            ercrv_check($out[0]&&ercrv_rows()===$saved&&!file_exists($phpbb_root_path.'cache/config_data.cache'),'Successful no-op retry refreshes cache without replacing settings');$cases+=2;
        }
        foreach(array('read','write','ack','version-read') as $failure)
        {
            ercrv_reset();$before=ercrv_rows();
            $db->fail=$failure==='read'?'SELECT 1 FROM fixture_config':($failure==='version-read'?'SELECT config_value FROM fixture_config':'INSERT INTO fixture_config');$db->lostAck=$failure==='ack';
            $out=ercrv_run();$after=ercrv_rows();
            ercrv_check(!$out[0]&&$out[1]!=='','Failure/unknown insertion is not successful');
            if($failure==='ack'){$before['cookie_secure']='1';ksort($before);}
            if($failure!=='version-read'){ercrv_check($after===$before,'No speculative writes after failed/unknown insertion');}
            ercrv_check(file_exists($phpbb_root_path.'cache/config_data.cache')===($failure==='read'),'Cache cleanup follows actual attempted writes');
            $writes=array_filter($db->queries,function($sql){return strpos($sql,'INSERT INTO fixture_config')===0;});
            if($failure!=='version-read'){ercrv_check(count($writes)<=1,'Unknown insertion is never automatically retried');}
            $db->fail='';$db->lostAck=false;ercrv_check(ercrv_run()[0],'Explicit retry resumes without overwriting completed additions');$cases++;
        }
        ercrv_reset();$nth=0;$injected=null;
        $db->hook=function($sql,$connection)use(&$nth,&$injected){
            if(strpos($sql,'INSERT INTO fixture_config')!==0||++$nth!==2){return;}$connection->hook=null;
            ercrv_sql('UPDATE fixture_users SET user_level=0 WHERE user_id=2');$injected=ercrv_rows();
        };
        $out=ercrv_run();ercrv_check($injected!==null&&!$out[0]&&ercrv_rows()===$injected&&isset($injected['cookie_secure'])&&!isset($injected['server_name']),'Later revocation preserves earlier completion but blocks all remaining additions');$cases++;
        ercrv_reset();$db->hook=function($sql,$connection){
            if(strpos($sql,'INSERT INTO fixture_config')!==0){return;}$connection->hook=null;
            ercrv_sql("INSERT INTO fixture_config VALUES ('cookie_secure','independent-value')");
        };
        $out=ercrv_run();ercrv_check($out[0]&&ercrv_rows()['cookie_secure']==='independent-value'&&strpos($out[2],'<li>cookie_secure</li>')===false,'Independent repair wins at dispatch and is not claimed as our insertion');$cases++;
        ercrv_reset();$db->fail='SELECT MIN(topic_time)';$out=ercrv_run();
        ercrv_check($out[0]&&ercrv_rows()['board_startdate']==='0','Unavailable optional topic hint cannot block config recovery');$cases++;
        foreach(array('missing','zero','known') as $version)
        {
            ercrv_reset();if($version==='missing'){ercrv_sql("DELETE FROM fixture_config WHERE config_name='version'");}elseif($version==='zero'){ercrv_sql("UPDATE fixture_config SET config_value='.0.0' WHERE config_name='version'");}
            $out=ercrv_run();$after=ercrv_rows();
            ercrv_check($out[0]&&($version==='missing'?!isset($after['version']):$after['version']===($version==='zero'?'.0.0':'.0.23')),'Recovery never stamps a database upgrade');
            ercrv_check((strpos($out[2],'unknown-version')!==false)===($version!=='known'),'Missing version is reported without fabricating migration completion');$cases++;
        }
        ercrv_reset();unlink($phpbb_root_path.'cache/config_data.cache');mkdir($phpbb_root_path.'cache/config_data.cache');$out=ercrv_run();$after=ercrv_rows();
        ercrv_check(!$out[0]&&$out[1]!==''&&isset($after['server_name']),'Cache cleanup failure cannot report success after completed additions');
        rmdir($phpbb_root_path.'cache/config_data.cache');file_put_contents($phpbb_root_path.'cache/config_data.cache','stale');
        ercrv_check(ercrv_run()[0]&&ercrv_rows()===$after&&!file_exists($phpbb_root_path.'cache/config_data.cache'),'Retry after fixed cache permissions is non-destructive');$cases++;
        foreach(array(null,-1,'2',8) as $actor)
        {
            ercrv_reset();$before=ercrv_rows();$caught=false;
            try{dbmtnc_erc_recover_config($default_config,$actor);}catch(PhpbbAclException $e){$caught=true;}
            ercrv_check($caught&&ercrv_rows()===$before,'Malformed or rebound actor binding cannot insert defaults');$cases++;
        }
        ercrv_reset('db');$HTTP_POST_VARS['db_password']='wrong';$before=ercrv_rows();$caught=false;
        try{dbmtnc_erc_recover_config($default_config,0);}catch(PhpbbAclException $e){$caught=true;}
        ercrv_check($caught&&ercrv_rows()===$before,'Database owner still needs exact current credentials');$cases++;
        foreach(array('md5','bcrypt') as $scheme)
        {
            ercrv_reset();$credential=addslashes("Quote'\\Grüße!9");$hash=$scheme==='md5'?md5($credential):password_hash($credential,PASSWORD_BCRYPT);
            ercrv_sql("UPDATE fixture_users SET user_password='".mysqli_real_escape_string($peer,$hash)."' WHERE user_id=2");
            $HTTP_POST_VARS['board_password']=$credential;$HTTP_POST_VARS['board_user']='ADMIN';
            ercrv_check(ercrv_run()[0],'Exact escaped credential and database alias survive repeated live requalification');$cases++;
        }
        ercrv_reset();$HTTP_POST_VARS['defaults']=array('version'=>'.0.99','board_disable'=>'1','custom'=>'replace');
        ercrv_check(ercrv_run()[0]&&ercrv_rows()['version']==='.0.23'&&ercrv_rows()['custom']==='keep'&&ercrv_rows()['board_disable']==='0','Submitted maps cannot replace compiled recovery defaults');$cases++;
    }
    echo "ERC config recovery: $cases native actual-controller cases passed.\n";
}
finally
{
    restore_error_handler();
    if(is_dir($phpbb_root_path.'cache/config_data.cache')){rmdir($phpbb_root_path.'cache/config_data.cache');}
    foreach(array('config_data.cache','unrelated.cache') as $file){if(is_file($phpbb_root_path.'cache/'.$file)){unlink($phpbb_root_path.'cache/'.$file);}}
    rmdir($phpbb_root_path.'cache');rmdir($phpbb_root_path);
    if($native){mysqli_close($native);}if($peer){mysqli_close($peer);}
    ercrv_check(mysqli_query($control,'DROP DATABASE '.$schema),'Owned fixture cleanup');mysqli_close($control);
}
