<?php
if (PHP_SAPI !== 'cli' || getenv('PHPBB_ERC_GRANT_NATIVE') !== '1') { return; }
define('IN_PHPBB', true); define('ADMIN', 1); define('USERS_TABLE', 'fixture_users');
$root = dirname(dirname(__DIR__)) . '/phpBB2/';
require $root . 'includes/php_compat.php';
$source = file_get_contents($root . 'includes/functions_dbmtnc.php');
$a = strpos($source, 'function check_authorisation('); $b = strpos($source, 'function get_config_data(', $a);
if ($a === false || $b <= $a || eval(substr($source, $a, $b-$a)) === false) { throw new RuntimeException('Actual ERC functions unavailable'); }
function ercg_check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
class ErcGrantFailure extends RuntimeException {}
function erc_throw_error($message) { throw new ErcGrantFailure($message); }
function success_message($message) { $GLOBALS['ercg_message'] = $message; }
class ErcGrantDatabase
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
        ercg_check($result !== false, 'Native fixture query failed: ' . mysqli_error($this->native));
        return $failed ? false : $result;
    }
    function sql_fetchrow($r) { return mysqli_fetch_assoc($r); }
    function sql_freeresult($r) { mysqli_free_result($r); }
    function sql_escape($value) { return mysqli_real_escape_string($this->native, $value); }
    function sql_affectedrows() { return mysqli_affected_rows($this->native); }
}
mysqli_report(MYSQLI_REPORT_OFF);
$port = getenv('PHPBB_ERC_GRANT_PORT') ?: '3306'; $password = getenv('PHPBB_ERC_GRANT_PASSWORD') ?: '';
ercg_check(preg_match('/^[0-9]{1,5}$/D', $port) && (int)$port > 0 && (int)$port < 65536, 'Invalid fixture port');
$control = mysqli_connect('127.0.0.1', 'root', $password, '', (int)$port);
ercg_check($control !== false, 'Loopback fixture unavailable');
$schema = 'codex_erc_grant_' . bin2hex(function_exists('random_bytes') ? random_bytes(8) : openssl_random_pseudo_bytes(8));
ercg_check(mysqli_query($control, 'CREATE DATABASE ' . $schema . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'), 'Owned fixture creation');
$native = $peer = null;
try
{
    set_error_handler(function($severity, $message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } });
    $native = mysqli_connect('127.0.0.1', 'root', $password, $schema, (int)$port);
    $peer = mysqli_connect('127.0.0.1', 'root', $password, $schema, (int)$port);
    ercg_check($native && $peer, 'Independent fixture connections');
    mysqli_set_charset($native, 'utf8mb4'); mysqli_set_charset($peer, 'utf8mb4');
    $db = new ErcGrantDatabase($native); $option = 'mua';
    $dbuser = 'fixture-owner'; $dbpasswd = 'fixture-owner-password';
    $lang = array('mua_success'=>'granted', 'mua_failed'=>'not-found');
    $source = file_get_contents($root . 'admin/erc.php'); $execute = strpos($source, "case 'execute':");
    $a = strpos($source, "case 'mua':", $execute); $b = strpos($source, "case 'rcp':", $a);
    ercg_check($a !== false && $b > $a, 'Actual grant controller');
    $code = "switch ('mua') {" . substr($source, $a, $b-$a) . '}';
    function ercg_sql($sql) { ercg_check(mysqli_query($GLOBALS['peer'], $sql) !== false, 'Fixture mutation failed'); }
    function ercg_rows()
    {
        $r = mysqli_query($GLOBALS['peer'], 'SELECT * FROM fixture_users ORDER BY user_id'); $rows = array();
        while ($row = mysqli_fetch_assoc($r)) { $rows[(int)$row['user_id']] = $row; }
        mysqli_free_result($r); return $rows;
    }
    function ercg_reset($method = 'board')
    {
        global $db, $HTTP_POST_VARS, $dbuser, $dbpasswd;
        $db->hook = null; $db->fail = ''; $db->lostAck = false; $db->queries = array();
        ercg_sql('DELETE FROM fixture_users');
        ercg_sql("INSERT INTO fixture_users (user_id,username,user_password,user_active,user_level) VALUES (-1,'Anonymous','',0,0),(2,'Admin','" . md5('fixture-password') . "',1,1),(3,'Target','kept-password',0,0),(4,'Unrelated','other-password',1,0)");
        $HTTP_POST_VARS = array('auth_method'=>$method, 'board_user'=>'Admin', 'board_password'=>'fixture-password',
            'db_user'=>$dbuser, 'db_password'=>$dbpasswd, 'username'=>'Target');
    }
    function ercg_run()
    {
        global $db, $HTTP_POST_VARS, $lang, $code;
        $GLOBALS['ercg_message'] = ''; $error = ''; ob_start();
        try { eval($code); } catch (ErcGrantFailure $e) { $error = $e->getMessage(); }
        finally { ob_end_clean(); }
        return array($GLOBALS['ercg_message'], $error);
    }
    $cases = 0;
    foreach (array('InnoDB', 'MyISAM') as $engine)
    {
        ercg_sql('DROP TABLE IF EXISTS fixture_users');
        ercg_sql('CREATE TABLE fixture_users (user_id INT PRIMARY KEY,username VARCHAR(255),user_password VARCHAR(255),user_active INT,user_level INT,user_login_tries INT DEFAULT 7,user_last_login_try INT DEFAULT 123) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        ercg_reset(); $before = ercg_rows(); $out = ercg_run(); $after = ercg_rows();
        ercg_check($out[0] === 'granted' && $out[1] === '' && (int)$after[3]['user_level'] === 1 && (int)$after[3]['user_active'] === 1, 'Explicit recovery promotes and activates target');
        foreach (array(-1,2,4) as $id) { ercg_check($after[$id] === $before[$id], 'Other users unchanged'); }
        ercg_check($after[3]['user_password'] === $before[3]['user_password'] && (int)$after[3]['user_login_tries'] === 0 && (int)$after[3]['user_last_login_try'] === 0, 'Preserve password and clear only legacy login counters'); $cases++;
        foreach (array('demoted', 'inactive', 'password', 'renamed', 'replaced') as $race)
        {
            ercg_reset(); $injected = null;
            $db->hook = function($sql, $connection) use ($race, &$injected) {
                if (strpos($sql, 'UPDATE fixture_users') !== 0) { return; } $connection->hook = null;
                if ($race === 'demoted') { ercg_sql('UPDATE fixture_users SET user_level=0 WHERE user_id=2'); }
                elseif ($race === 'inactive') { ercg_sql('UPDATE fixture_users SET user_active=0 WHERE user_id=2'); }
                elseif ($race === 'password') { ercg_sql("UPDATE fixture_users SET user_password='changed-password' WHERE user_id=2"); }
                elseif ($race === 'renamed') { ercg_sql("UPDATE fixture_users SET username='Renamed actor' WHERE user_id=2"); }
                else { ercg_sql('UPDATE fixture_users SET user_id=8 WHERE user_id=2'); }
                $injected = ercg_rows();
            };
            $out = ercg_run();
            ercg_check($injected !== null && $out[0] === '' && $out[1] !== '' && ercg_rows() === $injected, 'No promotion or counter reset after revoked actor: ' . $race); $cases++;
        }
        foreach (array('deleted', 'renamed', 'replaced', 'password', 'duplicate-after-read') as $race)
        {
            ercg_reset(); $injected = null;
            $db->hook = function($sql, $connection) use ($race, &$injected) {
                if (strpos($sql, 'UPDATE fixture_users') !== 0) { return; } $connection->hook = null;
                if ($race === 'deleted') { ercg_sql('DELETE FROM fixture_users WHERE user_id=3'); }
                elseif ($race === 'renamed') { ercg_sql("UPDATE fixture_users SET username='Other identity' WHERE user_id=3"); }
                elseif ($race === 'replaced') { ercg_sql('UPDATE fixture_users SET user_id=8 WHERE user_id=3'); }
                elseif ($race === 'password') { ercg_sql("UPDATE fixture_users SET user_password='independently-reset' WHERE user_id=3"); }
                else { ercg_sql("INSERT INTO fixture_users (user_id,username,user_password,user_active,user_level) VALUES (8,'TARGET','other-identity',1,0)"); }
                $injected = ercg_rows();
            };
            $out = ercg_run(); $after = ercg_rows();
            ercg_check($injected !== null, 'Target race reached');
            if (in_array($race, array('deleted','renamed','replaced'), true))
            {
                ercg_check($out[0] === '' && $out[1] !== '' && $after === $injected, 'Changed target never redirects promotion: ' . $race);
            }
            else
            {
                ercg_check($out[0] === 'granted' && (int)$after[3]['user_level'] === 1 && $after[3]['user_password'] === $injected[3]['user_password'], 'Same target retains independent password');
                unset($after[3], $injected[3]); ercg_check($after === $injected, 'A late name alias cannot promote another user');
            }
            $cases++;
        }
        foreach (array('target-read', 'columns', 'write', 'ack', 'post-auth', 'confirmation') as $failure)
        {
            ercg_reset(); $before = ercg_rows();
            $db->fail = $failure === 'target-read' ? 'SELECT user_id, username FROM fixture_users'
                : ($failure === 'columns' ? 'SHOW COLUMNS' : ($failure === 'confirmation' ? 'SELECT user_id FROM fixture_users' : 'UPDATE fixture_users'));
            $db->lostAck = $failure === 'ack';
            if ($failure === 'post-auth')
            {
                $db->fail = '';
                $db->hook = function($sql, $connection) {
                    if (strpos($sql, 'UPDATE fixture_users') !== 0) { return; } $connection->hook = null;
                    $connection->fail = 'SELECT user_id, username, user_password';
                };
            }
            $out = ercg_run(); $after = ercg_rows();
            ercg_check($out[0] === '' && $out[1] !== '', 'No success on failed/uncertain grant: ' . $failure);
            $writes = array_filter($db->queries, function($sql) { return strpos($sql, 'UPDATE fixture_users') === 0; });
            ercg_check(count($writes) <= 1, 'Failed/uncertain grants are not retried or followed by another reset');
            if (in_array($failure, array('ack','post-auth','confirmation'), true))
            {
                ercg_check((int)$after[3]['user_active'] === 1 && (int)$after[3]['user_level'] === 1 && (int)$after[3]['user_login_tries'] === 0 && (int)$after[3]['user_last_login_try'] === 0, 'Unknown outcome can contain only the whole completed statement');
                $after[3] = $before[3];
            }
            ercg_check($after === $before, 'No speculative or unrelated changes on failure'); $cases++;
        }
        foreach (array('board', 'db') as $method)
        {
            ercg_reset($method); ercg_check(ercg_run()[0] === 'granted', 'Board and database-owner recovery');
            $before = ercg_rows(); ercg_check(ercg_run()[0] === 'granted' && ercg_rows() === $before, 'Already active administrator is an idempotent success'); $cases += 2;
        }
        ercg_reset(); $HTTP_POST_VARS['username'] = 'ADMIN';
        ercg_check(ercg_run()[0] === 'granted' && (int)ercg_rows()[2]['user_level'] === 1, 'Self-recovery preserves actor authority'); $cases++;
        foreach (array('TARGET', "Quote' Target", 'Slash\\Target', 'ÄÖÜßGrüße', str_repeat('ü', 25)) as $name)
        {
            ercg_reset(); if ($name !== 'TARGET') { ercg_sql("UPDATE fixture_users SET username='" . mysqli_real_escape_string($peer, $name) . "' WHERE user_id=3"); }
            $HTTP_POST_VARS['username'] = addslashes($name);
            ercg_check(ercg_run()[0] === 'granted' && (int)ercg_rows()[3]['user_level'] === 1, 'Exact escaped/Unicode target or database alias resolves to one ID'); $cases++;
        }
        foreach (array(null, array('Target'), true, '', "Target\0tail", "Target\xFF", str_repeat('a', 256)) as $invalid)
        {
            ercg_reset(); $HTTP_POST_VARS['username'] = is_string($invalid) ? addslashes($invalid) : $invalid; $before = ercg_rows(); $out = ercg_run();
            ercg_check($out[0] === '' && $out[1] !== '' && ercg_rows() === $before, 'Malformed target fails without string warnings or writes'); $cases++;
        }
        foreach (array('Missing', 'Anonymous', 'Zero') as $name)
        {
            ercg_reset(); ercg_sql("INSERT INTO fixture_users (user_id,username,user_password,user_active,user_level) VALUES (0,'Zero','',0,0)");
            $HTTP_POST_VARS['username'] = $name; $before = ercg_rows(); $out = ercg_run();
            ercg_check($out[0] === 'not-found' && $out[1] === '' && ercg_rows() === $before, 'Missing and nonpositive identities are never promoted'); $cases++;
        }
        ercg_reset(); ercg_sql("INSERT INTO fixture_users (user_id,username,user_password,user_active,user_level) VALUES (8,'TARGET','',0,0)");
        $before = ercg_rows(); $out = ercg_run(); ercg_check($out[0] === '' && $out[1] !== '' && ercg_rows() === $before, 'Ambiguous legacy username cannot promote multiple rows'); $cases++;
        foreach (array(null, -1, '2', 8) as $actor)
        {
            ercg_reset(); $before = ercg_rows(); ercg_check(dbmtnc_erc_grant_administrator('Target', $actor) === false && ercg_rows() === $before, 'Invalid or changed actor binding'); $cases++;
        }
        ercg_reset('db'); $HTTP_POST_VARS['db_password'] = 'wrong'; $before = ercg_rows();
        ercg_check(dbmtnc_erc_grant_administrator('Target', 0) === false && ercg_rows() === $before, 'Database-owner recovery still needs current exact credentials'); $cases++;
        foreach (array('user_login_tries', 'user_last_login_try') as $drop)
        {
            ercg_sql('ALTER TABLE fixture_users DROP COLUMN ' . $drop); ercg_reset(); $out = ercg_run();
            ercg_check($out[0] === 'granted' && $out[1] === '' && (int)ercg_rows()[3]['user_level'] === 1, 'Legacy recovery works with one or both counters absent'); $cases++;
        }
    }
    echo "ERC grant: $cases native controller and authority-race cases passed.\n";
}
finally
{
    restore_error_handler();
    if ($native) { mysqli_close($native); } if ($peer) { mysqli_close($peer); }
    ercg_check(mysqli_query($control, 'DROP DATABASE ' . $schema), 'Owned fixture cleanup'); mysqli_close($control);
}
