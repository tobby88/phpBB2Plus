<?php
if (PHP_SAPI !== 'cli' || getenv('PHPBB_ERC_CONFIG_NATIVE') !== '1') { return; }
define('IN_PHPBB', true); define('ADMIN', 1); define('USERS_TABLE', 'fixture_users'); define('CONFIG_TABLE', 'fixture_config');
$root = dirname(dirname(__DIR__)) . '/phpBB2/';
require $root . 'includes/php_compat.php';
$source = file_get_contents($root . 'includes/functions_dbmtnc.php');
$a = strpos($source, 'function check_authorisation('); $b = strpos($source, 'function success_message(', $a);
if ($a === false || $b <= $a || eval(substr($source, $a, $b-$a)) === false) { throw new RuntimeException('Actual ERC functions unavailable'); }
function ercc_check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
class ErcConfigFailure extends RuntimeException {}
function erc_throw_error($message) { throw new ErcConfigFailure($message); }
function success_message($message) { $GLOBALS['ercc_message'] = $message; }
class ErcConfigDatabase
{
    public $native, $hook = null, $fail = '', $lostAck = false, $queries = array();
    function __construct($native) { $this->native = $native; }
    function sql_query($sql)
    {
        $this->queries[] = $sql;
        if ($this->hook) { $hook = $this->hook; $hook($sql, $this); }
        $failed = $this->fail !== '' && strpos($sql, $this->fail) === 0;
        if ($failed && !$this->lostAck) { return false; }
        $result = mysqli_query($this->native, $sql);
        ercc_check($result !== false, 'Native fixture query failed: ' . mysqli_error($this->native));
        return $failed ? false : $result;
    }
    function sql_fetchrow($r) { return mysqli_fetch_assoc($r); }
    function sql_freeresult($r) { mysqli_free_result($r); }
    function sql_escape($value) { return mysqli_real_escape_string($this->native, $value); }
    function sql_affectedrows() { return mysqli_affected_rows($this->native); }
}
mysqli_report(MYSQLI_REPORT_OFF);
$port = getenv('PHPBB_ERC_CONFIG_PORT') ?: '3306'; $password = getenv('PHPBB_ERC_CONFIG_PASSWORD') ?: '';
ercc_check(preg_match('/^[0-9]{1,5}$/D', $port) && (int)$port > 0 && (int)$port < 65536, 'Invalid fixture port');
$control = mysqli_connect('127.0.0.1', 'root', $password, '', (int)$port); ercc_check($control !== false, 'Loopback fixture unavailable');
$schema = 'codex_erc_config_' . bin2hex(function_exists('random_bytes') ? random_bytes(8) : openssl_random_pseudo_bytes(8));
ercc_check(mysqli_query($control, 'CREATE DATABASE ' . $schema . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'), 'Owned fixture creation');
$native = $peer = null;
$phpbb_root_path = sys_get_temp_dir() . '/' . $schema . '/';
ercc_check(mkdir($phpbb_root_path) && mkdir($phpbb_root_path . 'cache'), 'Owned cache fixture');
try
{
    set_error_handler(function($severity, $message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } });
    $native = mysqli_connect('127.0.0.1', 'root', $password, $schema, (int)$port); $peer = mysqli_connect('127.0.0.1', 'root', $password, $schema, (int)$port);
    ercc_check($native && $peer, 'Independent fixture connections'); mysqli_set_charset($native, 'utf8mb4'); mysqli_set_charset($peer, 'utf8mb4');
    $db = new ErcConfigDatabase($native); $dbuser = 'fixture-owner'; $dbpasswd = 'fixture-owner-password';
    $lang = array('rpd_success'=>'paths-saved', 'rcd_success'=>'cookies-saved', 'dgc_success'=>'gzip-saved', 'ERC_config_invalid'=>'invalid');
    $source = file_get_contents($root . 'admin/erc.php'); $execute = strpos($source, "case 'execute':"); $codes = array();
    foreach (array('rpd'=>'rcd', 'rcd'=>'rld', 'dgc'=>'cbl') as $action=>$next)
    {
        $a = strpos($source, "case '$action':", $execute); $b = strpos($source, "case '$next':", $a);
        ercc_check($a !== false && $b > $a, 'Actual config controller'); $codes[$action] = "switch('$action'){" . substr($source, $a, $b-$a) . '}';
    }
    function ercc_sql($sql) { ercc_check(mysqli_query($GLOBALS['peer'], $sql) !== false, 'Fixture mutation failed: ' . mysqli_error($GLOBALS['peer'])); }
    function ercc_rows()
    {
        $r = mysqli_query($GLOBALS['peer'], 'SELECT config_name,config_value FROM fixture_config ORDER BY config_name'); $rows = array();
        while ($row = mysqli_fetch_assoc($r)) { $rows[$row['config_name']] = $row['config_value']; } mysqli_free_result($r); return $rows;
    }
    function ercc_entries()
    {
        $r = mysqli_query($GLOBALS['peer'], 'SELECT config_name,config_value FROM fixture_config ORDER BY config_name,config_value'); $rows = array();
        while ($row = mysqli_fetch_assoc($r)) { $rows[] = $row; } mysqli_free_result($r); return $rows;
    }
    function ercc_reset($action, $method = 'board')
    {
        global $db, $HTTP_POST_VARS, $dbuser, $dbpasswd, $phpbb_root_path, $board_config, $option;
        $db->hook = null; $db->fail = ''; $db->lostAck = false; $db->queries = array(); $option = $action;
        ercc_sql('DELETE FROM fixture_users'); ercc_sql('DELETE FROM fixture_config');
        ercc_sql("INSERT INTO fixture_users VALUES (2,'Admin','" . md5('fixture-password') . "',1,1)");
        $board_config = array('cookie_secure'=>'0','server_name'=>'old.example.invalid','server_port'=>'80','script_path'=>'/old/',
            'cookie_domain'=>'','cookie_name'=>'old_cookie','cookie_path'=>'/old/','gzip_compress'=>'1','unrelated'=>'keep');
        foreach ($board_config as $key=>$value) { ercc_sql("INSERT INTO fixture_config VALUES ('$key','$value')"); }
        file_put_contents($phpbb_root_path . 'cache/config_data.cache', 'stale-config');
        file_put_contents($phpbb_root_path . 'cache/unrelated.cache', 'keep');
        $HTTP_POST_VARS = array('auth_method'=>$method, 'board_user'=>'Admin', 'board_password'=>'fixture-password', 'db_user'=>$dbuser, 'db_password'=>$dbpasswd,
            'secure_select'=>'1', 'domain_select'=>'1', 'port_select'=>'1', 'path_select'=>'1', 'secure'=>'1', 'domain'=>'new.example.invalid', 'port'=>'443', 'path'=>'/new/',
            'cookie_domain'=>'.example.invalid', 'cookie_name'=>'new_cookie', 'cookie_path'=>'/new/');
    }
    function ercc_run($action)
    {
        global $db, $HTTP_POST_VARS, $lang, $codes;
        $GLOBALS['ercc_message'] = ''; $error = ''; ob_start();
        try { eval($codes[$action]); } catch (ErcConfigFailure $e) { $error = $e->getMessage(); }
        finally { ob_end_clean(); } return array($GLOBALS['ercc_message'], $error);
    }
    $cases = 0;
    foreach (array('InnoDB','MyISAM') as $engine)
    {
        ercc_sql('DROP TABLE IF EXISTS fixture_users'); ercc_sql('DROP TABLE IF EXISTS fixture_config');
        ercc_sql('CREATE TABLE fixture_users (user_id INT PRIMARY KEY,username VARCHAR(255),user_password VARCHAR(255),user_active INT,user_level INT) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        ercc_sql('CREATE TABLE fixture_config (config_name VARCHAR(191) PRIMARY KEY,config_value VARCHAR(255)) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        foreach (array('rpd','rcd','dgc') as $action)
        {
            ercc_reset($action); $before = ercc_rows(); $out = ercc_run($action);
            ercc_check($out[0] === $lang[$action . '_success'] && $out[1] === '' && ercc_rows() !== $before, 'Valid actual controller changes settings'); $cases++;
            $saved = ercc_rows();
            ercc_check(!file_exists($phpbb_root_path . 'cache/config_data.cache') && file_get_contents($phpbb_root_path . 'cache/unrelated.cache') === 'keep', 'Only stale config cache removed');
            $runtime_config = $board_config; ksort($runtime_config);
            ercc_check($runtime_config === $saved, 'In-request configuration matches saved settings');
            ercc_check(count(array_filter($db->queries, function($sql) { return strpos($sql, 'UPDATE fixture_config') === 0; })) === 1, 'One UPDATE per settings form');
            file_put_contents($phpbb_root_path . 'cache/config_data.cache', 'current-config');
            ercc_check(ercc_run($action)[0] === $lang[$action . '_success'] && ercc_rows() === $saved && !file_exists($phpbb_root_path . 'cache/config_data.cache'), 'Already saved values succeed and invalidate cache'); $cases++;
            ercc_reset($action, 'db'); ercc_check(ercc_run($action)[0] === $lang[$action . '_success'], 'Explicit database-owner recovery'); $cases++;
            foreach (array('demoted','inactive','password','renamed','replaced') as $race)
            {
                ercc_reset($action); $before = ercc_rows(); $reached = false;
                $db->hook = function($sql, $connection) use ($race, &$reached) {
                    if (strpos($sql, 'UPDATE fixture_config') !== 0) { return; } $connection->hook = null; $reached = true;
                    if ($race === 'demoted') { ercc_sql('UPDATE fixture_users SET user_level=0 WHERE user_id=2'); }
                    elseif ($race === 'inactive') { ercc_sql('UPDATE fixture_users SET user_active=0 WHERE user_id=2'); }
                    elseif ($race === 'password') { ercc_sql("UPDATE fixture_users SET user_password='independent-password' WHERE user_id=2"); }
                    elseif ($race === 'renamed') { ercc_sql("UPDATE fixture_users SET username='Renamed' WHERE user_id=2"); }
                    else { ercc_sql('UPDATE fixture_users SET user_id=8 WHERE user_id=2'); }
                };
                $out = ercc_run($action);
                ercc_check($reached && $out[0] === '' && $out[1] !== '' && ercc_rows() === $before, 'Revoked actor cannot reset configuration: ' . $action . '/' . $race); $cases++;
            }
            $key = $action === 'rpd' ? 'server_name' : ($action === 'rcd' ? 'cookie_name' : 'gzip_compress');
            foreach (array('missing','renamed','case-renamed','duplicate') as $race)
            {
                ercc_reset($action); $injected = null;
                if ($race === 'duplicate') { ercc_sql('ALTER TABLE fixture_config DROP PRIMARY KEY'); }
                $db->hook = function($sql, $connection) use ($key, $race, &$injected) {
                    if (strpos($sql, 'UPDATE fixture_config') !== 0) { return; } $connection->hook = null;
                    if ($race === 'missing') { ercc_sql("DELETE FROM fixture_config WHERE config_name='$key'"); }
                    elseif ($race === 'duplicate') { ercc_sql("INSERT INTO fixture_config VALUES ('$key','independent-duplicate')"); }
                    else { ercc_sql("UPDATE fixture_config SET config_name='" . ($race === 'case-renamed' ? strtoupper($key) : 'independent_name') . "' WHERE config_name='$key'"); }
                    $injected = ercc_entries();
                };
                $out = ercc_run($action);
                ercc_check($injected !== null && $out[0] === '' && $out[1] !== '' && ercc_entries() === $injected, 'Missing/ambiguous key at dispatch cannot leave the rest of a form saved'); $cases++;
                if ($race === 'duplicate') { ercc_reset($action); ercc_sql('ALTER TABLE fixture_config ADD PRIMARY KEY (config_name)'); }
            }
            foreach (array('read','write','ack','post-auth','confirmation') as $failure)
            {
                ercc_reset($action); $before = ercc_rows();
                $db->fail = $failure === 'read' ? 'SELECT config_name, config_value' : 'UPDATE fixture_config';
                $db->lostAck = $failure === 'ack';
                if ($failure === 'post-auth' || $failure === 'confirmation')
                {
                    $db->fail = '';
                    $db->hook = function($sql, $connection) use ($failure) {
                        if (strpos($sql, 'UPDATE fixture_config') !== 0) { return; } $connection->hook = null;
                        $connection->fail = $failure === 'post-auth' ? 'SELECT user_id, username, user_password' : 'SELECT config_name, config_value';
                    };
                }
                $out = ercc_run($action); $after = ercc_rows();
                ercc_check($out[0] === '' && $out[1] !== '', 'No success on failed/uncertain configuration write');
                ercc_check($after === (in_array($failure, array('ack','post-auth','confirmation'), true) ? $saved : $before), 'Failure leaves only the acknowledged or unknown complete statement, not speculative follow-ups');
                ercc_check(file_exists($phpbb_root_path . 'cache/config_data.cache') === ($failure === 'read'), 'Invalidate cache after every attempted write, including unknown outcomes');
                ercc_check(count(array_filter($db->queries, function($sql) { return strpos($sql, 'UPDATE fixture_config') === 0; })) <= 1, 'No automatic write retry'); $cases++;
            }
        }
        foreach (array('secure_select','domain_select','port_select','path_select','secure','domain','port','path') as $field)
        {
            ercc_reset('rpd'); $HTTP_POST_VARS[$field] = array('1'); $before = ercc_rows(); $out = ercc_run('rpd');
            ercc_check($out[0] === '' && $out[1] !== '' && ercc_rows() === $before, 'Malformed path input cannot turn an array into a selected or altered setting'); $cases++;
        }
        foreach (array(array('cookie_domain','evil.invalid/path'),array('cookie_domain','..invalid'),array('cookie_domain',str_repeat('x',64).'.invalid'),
            array('cookie_name',''),array('cookie_name','bad.name'),array('cookie_name',"bad\r\nname"),array('cookie_name',"x', config_value='evil"),
            array('cookie_path','/../'),array('cookie_path','/%2e%2e/'),array('cookie_path','/; domain=evil'),array('cookie_path',array('/'))) as $invalid)
        {
            ercc_reset('rcd'); $HTTP_POST_VARS[$invalid[0]] = is_string($invalid[1]) ? addslashes($invalid[1]) : $invalid[1];
            $before = ercc_rows(); $out = ercc_run('rcd'); ercc_check($out[0] === '' && $out[1] !== '' && ercc_rows() === $before, 'Unsafe cookie settings rejected before any field is saved'); $cases++;
        }
        ercc_reset('rpd'); $before = ercc_rows(); foreach (array('secure_select','domain_select','port_select','path_select') as $field) { $HTTP_POST_VARS[$field] = '0'; }
        ercc_check(ercc_run('rpd')[0] === 'paths-saved' && ercc_rows() === $before && file_exists($phpbb_root_path . 'cache/config_data.cache'), 'Empty selection is a read-only success'); $cases++;
        $HTTP_POST_VARS['domain_select'] = '1'; $out = ercc_run('rpd'); $before['server_name'] = 'new.example.invalid';
        ercc_check($out[0] === 'paths-saved' && ercc_rows() === $before, 'Only selected setting changes'); $cases++;
        ercc_reset('rcd'); $HTTP_POST_VARS['cookie_domain'] = ''; $HTTP_POST_VARS['cookie_path'] = '';
        ercc_check(ercc_run('rcd')[0] === 'cookies-saved' && ercc_rows()['cookie_domain'] === '' && ercc_rows()['cookie_path'] === '/', 'Host-only root cookies remain supported'); $cases++;
        ercc_reset('rcd'); $HTTP_POST_VARS['cookie_path'] = '/new';
        ercc_check(ercc_run('rcd')[0] === 'cookies-saved' && ercc_rows()['cookie_path'] === '/new', 'Valid cookie path without a trailing slash is preserved'); $cases++;
        foreach (array(array('unrelated'=>'change'),array('version'=>'.0.99'),array('cookie_secure'=>2),array('server_port'=>'70000'),array('cookie_name'=>null)) as $invalid)
        {
            ercc_reset('dgc'); $before = ercc_rows(); ercc_check(dbmtnc_erc_update_config($invalid, 2) === false && ercc_rows() === $before, 'Helper refuses arbitrary imports and malformed values'); $cases++;
        }
        foreach (array(null, -1, '2', 8) as $actor)
        {
            ercc_reset('dgc'); $before = ercc_rows(); ercc_check(dbmtnc_erc_update_config(array('gzip_compress'=>'0'), $actor) === false && ercc_rows() === $before, 'Invalid or rebound actor cannot change configuration'); $cases++;
        }
        ercc_reset('dgc', 'db'); $HTTP_POST_VARS['db_password'] = 'wrong'; $before = ercc_rows();
        ercc_check(dbmtnc_erc_update_config(array('gzip_compress'=>'0'), 0) === false && ercc_rows() === $before, 'Database recovery still requires current exact credentials'); $cases++;
        ercc_reset('dgc'); unlink($phpbb_root_path . 'cache/config_data.cache'); mkdir($phpbb_root_path . 'cache/config_data.cache');
        $out = ercc_run('dgc'); ercc_check($out[0] === '' && $out[1] !== '' && ercc_rows()['gzip_compress'] === '0', 'Cache removal failure never falsely reports complete recovery');
        rmdir($phpbb_root_path . 'cache/config_data.cache'); $cases++;
        ercc_reset('rpd');
        $a = strpos($source, '$secure_cur ='); $b = strpos($source, '$domain_cur =', $a); $recommendation = substr($source, $a, $b-$a);
        foreach (array(array('on','443','1'),array('','443','1'),array('off','80','0')) as $server)
        {
            $_SERVER = array('HTTPS'=>$server[0], 'SERVER_PORT'=>$server[1], 'SERVER_PROTOCOL'=>'HTTP/1.1'); $HTTP_SERVER_VARS = $_SERVER; $HTTP_ENV_VARS = array();
            eval($recommendation); ercc_check($secure_rec === $server[2], 'Secure-cookie recommendation uses actual transport, not protocol version'); $cases++;
        }
    }
    echo "ERC config: $cases native actual-controller cases passed.\n";
}
finally
{
    restore_error_handler();
    if (is_dir($phpbb_root_path . 'cache/config_data.cache')) { rmdir($phpbb_root_path . 'cache/config_data.cache'); }
    foreach (array('config_data.cache','unrelated.cache') as $file) { if (is_file($phpbb_root_path . 'cache/' . $file)) { unlink($phpbb_root_path . 'cache/' . $file); } }
    rmdir($phpbb_root_path . 'cache'); rmdir($phpbb_root_path);
    if ($native) { mysqli_close($native); } if ($peer) { mysqli_close($peer); }
    ercc_check(mysqli_query($control, 'DROP DATABASE ' . $schema), 'Owned fixture cleanup'); mysqli_close($control);
}
