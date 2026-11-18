<?php
// Every schema, connection and pending row belongs to this native fixture.
if (PHP_SAPI !== 'cli' || getenv('PHPBB_FILE_OWNER_NATIVE') !== '1') { echo "Native file participant ownership skipped.\n"; return; }
define('IN_PHPBB', true);
$root = dirname(dirname(__DIR__)); $cases = $assertions = 0;
require $root . '/phpBB2/includes/php_compat.php';
require $root . '/phpBB2/db/mysqli.php';
require $root . '/phpBB2/album_mod/album_file_coordinator.php';
require $root . '/phpBB2/pafiledb/includes/functions_file_coordinator.php';
function fo_check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } $GLOBALS['assertions']++; }
function fo_sql($database, $sql) { $result = $database->sql_query($sql); fo_check($result !== false, 'Own SQL: ' . $sql); return $result; }
function fo_rows($database, $sql) { $result = fo_sql($database, $sql); try { return $database->sql_fetchrowset($result); } finally { $database->sql_freeresult($result); } }
class FileOwnerObserved
{
    public $inner, $db_connect_id, $fault = '', $closed = 0, $queries = array();
    function __construct($inner) { $this->inner = $inner; $this->db_connect_id = $inner->db_connect_id; }
    function __call($method, $args) { return call_user_func_array(array($this->inner, $method), $args); }
    function sql_query($sql, $transaction = false)
    {
        $this->queries[] = $sql;
        $identity = strpos($sql, 'SELECT CONNECTION_ID() AS id,DATABASE() AS schema_name,') === 0;
        $sentinel = $sql === 'SET TRANSACTION READ WRITE';
        if (($identity && $this->fault === 'identity-false') || ($sentinel && $this->fault === 'sentinel-false')) { return false; }
        if (($identity && $this->fault === 'identity-throw') || ($sentinel && $this->fault === 'sentinel-throw')) { throw new RuntimeException('Owned synthetic query exception'); }
        return $this->inner->sql_query($sql, $transaction);
    }
    function sql_fetchrowset($result)
    {
        $rows = $this->inner->sql_fetchrowset($result);
        if ($this->fault === 'identity-malformed' && isset($rows[0]['schema_name'], $rows[0]['id'])) { $rows[0]['id'] = 'unknown'; }
        return $rows;
    }
    function sql_close() { $this->closed++; return $this->inner->sql_close(); }
}
class FileOwnerSource extends sql_db
{
    public $slot = 0, $borrowed = null, $calls = 0, $owned = array();
    function sql_dedicated_connection()
    {
        $this->calls++;
        if ($this->calls === $this->slot) { return $this->borrowed; }
        $connection = new FileOwnerObserved(parent::sql_dedicated_connection());
        $this->owned[] = $connection;
        return $connection;
    }
}
$port = getenv('PHPBB_FILE_OWNER_PORT') ?: '3306';
fo_check(preg_match('/^[0-9]{1,5}$/D', $port) && (int) $port > 0 && (int) $port <= 65535, 'Explicit loopback port');
$host = '127.0.0.1:' . $port; $password = getenv('PHPBB_FILE_OWNER_PASSWORD') ?: '';
$schema = 'codex_file_owner_' . bin2hex(phpbb_random_bytes(8)); $other = $schema . '_other';
$control = new sql_db($host, 'root', $password, '', false);
$created = $other_created = false; $source = $borrowed = $coordinator = null;
set_error_handler(function ($severity, $message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } });
try
{
    $version = fo_rows($control, 'SELECT VERSION() AS version');
    $mysql = getenv('PHPBB_FILE_OWNER_MYSQL') === '8.4';
    fo_check(!$mysql || (strpos($version[0]['version'], '8.4.') === 0 && stripos($version[0]['version'], 'MariaDB') === false), 'Actual requested MySQL 8.4 server');
    // The exact former Download identity SELECT must really fail on MySQL,
    // rather than modelling a MariaDB-only variable on the wrong server.
    $old = $control->sql_query('SELECT CONNECTION_ID() AS id,DATABASE() AS schema_name,@@SESSION.autocommit AS automatic,@@SESSION.in_transaction AS active,@@SESSION.character_set_connection AS charset');
    if ($mysql) { $error = $control->sql_error(); fo_check($old === false && (int) $error['code'] === 1193, 'Original MySQL unknown system variable 1193'); }
    else { fo_check($old !== false, 'MariaDB reference identity supported'); $control->sql_freeresult($old); }
    $cases++;
    fo_sql($control, 'CREATE DATABASE ' . $schema . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); $created = true;
    fo_sql($control, 'CREATE DATABASE ' . $other . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); $other_created = true;
    fo_sql($control, 'USE ' . $schema);
    $ddl = file_get_contents($root . '/phpBB2/install/schemas/mysql_schema.sql');
    $tables = array('users','sessions','user_group','album','album_cat','album_comment','album_rate','album_config','album_file_jobs','album_file_items',
        'groups','config','jr_admin_users','pa_config','pa_cat','pa_files','pa_votes','pa_comments','pa_custom','pa_customdata','pa_mirrors','pa_license','pa_auth','pa_file_journal','pa_download_info');
    foreach ($tables as $suffix)
    {
        fo_check(preg_match('/^CREATE TABLE \x60?phpbb_' . $suffix . '\x60?\s*\([\s\S]*?;/m', $ddl, $match) === 1, 'Canonical table ' . $suffix);
        fo_sql($control, str_replace('phpbb_' . $suffix, 'fixture_' . $suffix, $match[0]));
    }
    foreach (array($schema, $other) as $database) { fo_sql($control, 'CREATE TABLE ' . $database . '.caller_marker(id INT PRIMARY KEY,value VARCHAR(40)) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'); }
    foreach (array('AlbumFileCoordinator', 'PaFileDbFileCoordinator') as $class)
    {
        // Both owned writers commit while the original caller has pending work.
        $source = new FileOwnerSource($host, 'root', $password, $schema, false);
        fo_sql($source, 'START TRANSACTION'); fo_sql($source, "INSERT INTO caller_marker VALUES(1,'Caller pending')");
        $coordinator = new $class($source, 'fixture_', 0); $coordinator->begin();
        $coordinator->guard_commit(function () { return true; });
        $coordinator->durable_begin(); $coordinator->durable_commit(); $coordinator->commit(); $coordinator->release(); $coordinator = null;
        fo_check(count(fo_rows($source, 'SELECT * FROM caller_marker')) === 1 && !fo_rows($control, 'SELECT * FROM caller_marker'), 'Successful commits preserve caller transaction: ' . $class);
        fo_check(count($source->owned) === 2 && $source->owned[0]->closed === 1 && $source->owned[1]->closed === 1, 'Both proven idle handles close once');
        fo_sql($source, 'ROLLBACK'); fo_check(!fo_rows($control, 'SELECT * FROM caller_marker'), 'Caller retains rollback');
        $source->sql_close(); $source = null; $cases++;
        foreach (array(1, 2) as $slot)
        {
            foreach (array('active','read-only','autocommit-off-active','autocommit-off-idle','other-schema','other-charset','identity-false','identity-throw','identity-malformed','sentinel-false','sentinel-throw') as $kind)
            {
                $source = new FileOwnerSource($host, 'root', $password, $schema, false);
                $peer_schema = $kind === 'other-schema' ? $other : $schema;
                $borrowed = new FileOwnerObserved(new sql_db($host, 'root', $password, $peer_schema, false));
                $source->slot = $slot; $source->borrowed = $borrowed;
                if ($kind === 'other-charset') { fo_check(mysqli_set_charset($borrowed->db_connect_id, 'latin1'), 'Own foreign charset'); }
                if (strpos($kind, 'autocommit-off') === 0) { fo_sql($borrowed->inner, 'SET autocommit=0'); }
                $active = $kind !== 'autocommit-off-idle';
                if ($active)
                {
                    fo_sql($borrowed->inner, 'START TRANSACTION');
                    fo_sql($borrowed->inner, "INSERT INTO caller_marker VALUES(2,'Foreign pending')");
                }
                if ($kind === 'read-only')
                {
                    fo_sql($borrowed->inner, 'ROLLBACK'); fo_sql($borrowed->inner, 'START TRANSACTION READ ONLY');
                    fo_rows($borrowed->inner, 'SELECT * FROM caller_marker');
                }
                if (strpos($kind, 'identity-') === 0 || strpos($kind, 'sentinel-') === 0) { $borrowed->fault = $kind; }
                $denied = false;
                try { $coordinator = new $class($source, 'fixture_', 0); }
                catch (Exception $error) { $denied = true; }
                finally { if ($coordinator) { $coordinator->release(); $coordinator = null; } }
                fo_check($denied, 'Foreign participant refused: ' . $class . '/' . $slot . '/' . $kind);
                fo_check($borrowed->closed === 0 && $borrowed->inner->db_connect_id instanceof mysqli, 'Foreign handle remains open');
                foreach ($borrowed->queries as $sql)
                {
                    fo_check(strpos($sql, 'SELECT CONNECTION_ID() AS id,DATABASE() AS schema_name,') === 0 || $sql === 'SET TRANSACTION READ WRITE', 'No commit, rollback, lock or session change on rejected handle');
                }
                if ($active && $kind !== 'read-only') { fo_check(count(fo_rows($borrowed->inner, 'SELECT * FROM caller_marker')) === 1, 'Original pending work retained'); }
                fo_check(!fo_rows($control, 'SELECT * FROM ' . $peer_schema . '.caller_marker'), 'Foreign work not committed');
                foreach ($source->owned as $owned) { fo_check($owned->closed === 1, 'Proven idle sibling closes on refusal'); }
                $borrowed->fault = ''; fo_sql($borrowed->inner, 'ROLLBACK');
                fo_check(!fo_rows($control, 'SELECT * FROM ' . $peer_schema . '.caller_marker'), 'Foreign caller still owns rollback');
                $borrowed->sql_close(); $source->sql_close(); $borrowed = $source = null; $cases++;
            }
            foreach (array('idle-wrong-schema', 'idle-wrong-charset') as $kind)
            {
                $source = new FileOwnerSource($host, 'root', $password, $schema, false);
                $borrowed = new FileOwnerObserved(new sql_db($host, 'root', $password, $kind === 'idle-wrong-schema' ? $other : $schema, false));
                if ($kind === 'idle-wrong-charset') { fo_check(mysqli_set_charset($borrowed->db_connect_id, 'latin1'), 'Idle invalid charset'); }
                $source->slot = $slot; $source->borrowed = $borrowed; $denied = false;
                try { $coordinator = new $class($source, 'fixture_', 0); }
                catch (Exception $error) { $denied = true; }
                finally { if ($coordinator) { $coordinator->release(); $coordinator = null; } }
                fo_check($denied && $borrowed->closed === 1 && !$borrowed->inner->db_connect_id, 'Proven idle invalid context closes once');
                foreach ($source->owned as $owned) { fo_check($owned->closed === 1, 'Owned sibling released'); }
                $source->sql_close(); $borrowed = $source = null; $cases++;
            }
        }
    }
    echo 'Native file participant ownership: ', $cases, ' cases, ', $assertions, ' assertions PASS; both production coordinators, both factory slots, caller rollback, native active/read-only transactions, autocommit, uncertain SQL, invalid idle contexts and portable MySQL identity.', "\n";
    if ($mysql) { echo "Native file participant MySQL 8.4 reference: former identity fails 1193; current Album and Download owners pass.\n"; }
}
finally
{
    restore_error_handler();
    if ($coordinator) { $coordinator->release(); }
    if ($borrowed && $borrowed->inner->db_connect_id) { $borrowed->inner->sql_query('ROLLBACK'); $borrowed->sql_close(); }
    if ($source) { $source->sql_query('ROLLBACK'); $source->sql_close(); }
    if (!preg_match('/^codex_file_owner_[a-f0-9]{16}$/D', $schema)) { throw new RuntimeException('Own schema required'); }
    if ($other_created) { $control->sql_query('DROP DATABASE ' . $other); }
    if ($created) { $control->sql_query('DROP DATABASE ' . $schema); }
    $control->sql_close();
}
