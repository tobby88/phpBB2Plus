<?php
// Real credential verifier and controller branches, two independent native
// connections, and only a randomly named disposable loopback database.
if (PHP_SAPI !== 'cli' || getenv('PHPBB_ERC_CLEAR_NATIVE') !== '1') { return; }
define('IN_PHPBB', true); define('ADMIN', 1); define('ANONYMOUS', -1);
foreach (array('USERS'=>'users', 'SESSIONS'=>'sessions', 'SEARCH'=>'search', 'BANLIST'=>'bans', 'DISALLOW'=>'disallow') as $key=>$name) {
    define($key . '_TABLE', 'fixture_' . $name);
}
$root = dirname(dirname(__DIR__)) . '/phpBB2/';
require $root . 'includes/php_compat.php';
$source = file_get_contents($root . 'includes/functions_dbmtnc.php');
$a = strpos($source, 'function check_authorisation('); $b = strpos($source, 'function get_config_data(', $a);
if ($a === false || $b <= $a || eval(substr($source, $a, $b-$a)) === false) { throw new RuntimeException('ERC verifier unavailable'); }
function ercc_check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
class ErcClearFailure extends RuntimeException {}
function erc_throw_error($message) { throw new ErcClearFailure($message); }
function success_message($message) { $GLOBALS['ercc_success'] = true; }
class ErcClearDatabase {
    public $native, $peer, $hook, $failure = '', $lostAck = false, $queries = array();
    function __construct($native, $peer) { $this->native = $native; $this->peer = $peer; }
    function sql_query($sql) {
        $this->queries[] = $sql;
        if ($this->hook) { $hook = $this->hook; $hook($sql, $this); }
        $fail = $this->failure !== '' && strpos($sql, $this->failure) === 0;
        if ($fail && !$this->lostAck) { return false; }
        $result = mysqli_query($this->native, $sql);
        if (!$result) { throw new RuntimeException('Unexpected native fixture SQL failure: ' . mysqli_error($this->native)); }
        return $fail ? false : $result;
    }
    function sql_fetchrow($result) { return mysqli_fetch_assoc($result); }
    function sql_freeresult($result) { mysqli_free_result($result); }
    function sql_escape($value) { return mysqli_real_escape_string($this->native, $value); }
}
mysqli_report(MYSQLI_REPORT_OFF);
$port = getenv('PHPBB_ERC_CLEAR_PORT') ?: '3306';
ercc_check(preg_match('/^[0-9]{1,5}$/D', $port) && (int)$port > 0 && (int)$port < 65536, 'Invalid loopback port');
$password = getenv('PHPBB_ERC_CLEAR_PASSWORD') ?: '';
$control = mysqli_connect('127.0.0.1', 'root', $password, '', (int)$port);
ercc_check($control !== false, 'Loopback fixture connection unavailable');
$schema = 'codex_erc_clear_' . bin2hex(function_exists('random_bytes') ? random_bytes(8) : openssl_random_pseudo_bytes(8));
ercc_check(mysqli_query($control, 'CREATE DATABASE ' . $schema . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'), 'Create owned fixture');
$native = $peer = null;
try {
    $native = mysqli_connect('127.0.0.1', 'root', $password, $schema, (int)$port);
    $peer = mysqli_connect('127.0.0.1', 'root', $password, $schema, (int)$port);
    ercc_check($native && $peer, 'Independent fixture connections');
    mysqli_set_charset($native, 'utf8mb4'); mysqli_set_charset($peer, 'utf8mb4');
    $db = new ErcClearDatabase($native, $peer);
    $dbuser = 'fixture-owner'; $dbpasswd = 'owner-only'; $option = 'cbl';
    $lang = array('cls_success'=>'cleared', 'cbl_success'=>'cleared');
    $tables = array(SESSIONS_TABLE, SEARCH_TABLE, BANLIST_TABLE, DISALLOW_TABLE);
    function ercc_sql($sql) { ercc_check(mysqli_query($GLOBALS['peer'], $sql) !== false, 'Fixture SQL failed'); }
    function ercc_count($table) {
        $result = mysqli_query($GLOBALS['peer'], 'SELECT COUNT(*) FROM ' . $table);
        $row = mysqli_fetch_row($result); mysqli_free_result($result); return (int)$row[0];
    }
    function ercc_reset($method = 'board') {
        global $db, $HTTP_POST_VARS, $dbuser, $dbpasswd, $tables;
        $db->hook = null; $db->failure = ''; $db->lostAck = false; $db->queries = array();
        ercc_sql('DELETE FROM fixture_users');
        ercc_sql("INSERT INTO fixture_users VALUES (2,'Admin','" . md5('fixture-password') . "',1,1),(-1,'Anonymous','',0,0)");
        foreach ($tables as $table) { ercc_sql('DELETE FROM ' . $table); ercc_sql('INSERT INTO ' . $table . ' VALUES (1),(2)'); }
        $HTTP_POST_VARS = array('auth_method'=>$method, 'board_user'=>'Admin', 'board_password'=>'fixture-password', 'db_user'=>$dbuser, 'db_password'=>$dbpasswd);
        $GLOBALS['ercc_success'] = false;
    }
    function ercc_race($table, $race) {
        $GLOBALS['db']->hook = function($sql, $connection) use ($table, $race) {
            if (strpos($sql, 'DELETE FROM ' . $table . ' ') !== 0) { return; }
            $connection->hook = null;
            $change = $race === 'demoted' ? 'user_level=0' : ($race === 'inactive' ? 'user_active=0' : "user_password='independent-password'");
            ercc_sql('UPDATE fixture_users SET ' . $change . ' WHERE user_id=2');
        };
    }
    $controller = file_get_contents($root . 'admin/erc.php');
    $execute = strpos($controller, "case 'execute':"); $branches = array();
    foreach (array('cls'=>'rdb', 'cbl'=>'raa') as $case=>$next) {
        $a = strpos($controller, "case '$case':", $execute); $b = strpos($controller, "case '$next':", $a);
        ercc_check($a !== false && $b > $a, 'Actual ERC branch extraction');
        $branches[$case] = "switch('$case'){" . substr($controller, $a, $b-$a) . '}';
    }
    $cases = 0;
    foreach (array('InnoDB', 'MyISAM') as $engine) {
        foreach (array_merge(array(USERS_TABLE), $tables) as $table) {
            ercc_sql('DROP TABLE IF EXISTS ' . $table);
            $columns = $table === USERS_TABLE ? 'user_id INTEGER PRIMARY KEY,username VARCHAR(255),user_password VARCHAR(255),user_active INTEGER,user_level INTEGER' : 'id INTEGER PRIMARY KEY';
            ercc_sql('CREATE TABLE ' . $table . ' (' . $columns . ') ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4');
        }
        foreach ($tables as $table) {
            foreach (array('board', 'db') as $method) {
                ercc_reset($method); ercc_check(dbmtnc_erc_clear_table($table) && ercc_count($table) === 0, 'Authorized clear ' . $table);
                ercc_check(dbmtnc_erc_clear_table($table), 'Already empty table is a successful no-op'); $cases++;
            }
            foreach (array('demoted', 'inactive', 'password') as $race) {
                ercc_reset(); ercc_race($table, $race);
                ercc_check(!dbmtnc_erc_clear_table($table) && ercc_count($table) === 2, 'Revocation at DELETE dispatch: ' . $race); $cases++;
            }
            foreach (array('invalid-auth', 'read-failure', 'delete-failure', 'lost-ack') as $failure) {
                ercc_reset();
                if ($failure === 'invalid-auth') { $HTTP_POST_VARS['board_password'] = 'wrong'; }
                else { $db->failure = $failure === 'read-failure' ? 'SELECT user_id, username' : 'DELETE FROM ' . $table; $db->lostAck = $failure === 'lost-ack'; }
                ercc_check(!dbmtnc_erc_clear_table($table), 'Failures cannot report success: ' . $failure);
                ercc_check(ercc_count($table) === ($failure === 'lost-ack' ? 0 : 2), 'No implicit retry or unauthorized deletion'); $cases++;
            }
        }
        foreach (array('cls'=>array(SESSIONS_TABLE, SEARCH_TABLE), 'cbl'=>array(BANLIST_TABLE, DISALLOW_TABLE)) as $option=>$pair) {
            ercc_reset(); eval($branches[$option]);
            ercc_check($ercc_success && ercc_count($pair[0]) === 0 && ercc_count($pair[1]) === 0, 'Actual controller authorized success'); $cases++;
            foreach ($pair as $position=>$table) {
                foreach (array('demoted', 'inactive', 'password') as $race) {
                    ercc_reset(); ercc_race($table, $race); $denied = false;
                    try { eval($branches[$option]); } catch (ErcClearFailure $e) { $denied = true; }
                    ercc_check($denied && !$ercc_success, 'Controller stops without false success');
                    ercc_check(ercc_count($pair[0]) === ($position === 0 ? 2 : 0) && ercc_count($pair[1]) === 2, 'Only writes before revocation can complete'); $cases++;
                }
            }
        }
        ercc_reset(); $before = count($db->queries);
        ercc_check(!dbmtnc_erc_clear_table(USERS_TABLE) && !dbmtnc_erc_clear_table('fixture_bans; DROP TABLE fixture_users'), 'Only the four explicit recovery tables are allowed');
        ercc_check(count($db->queries) === $before && ercc_count(USERS_TABLE) === 2, 'Invalid target performs no query'); $cases++;
    }
    echo "ERC clear: $cases native authorization, dispatch-race, controller and failure cases passed.\n";
} finally {
    if ($native) { mysqli_close($native); } if ($peer) { mysqli_close($peer); }
    ercc_check(mysqli_query($control, 'DROP DATABASE ' . $schema), 'Owned fixture cleanup failed'); mysqli_close($control);
}
