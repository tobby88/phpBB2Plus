<?php
if (PHP_SAPI !== 'cli' || getenv('PHPBB_ALBUM_FILE_COORDINATOR_NATIVE') !== '1') { echo "Native Album file coordinator fixture skipped.\n"; return; }
define('IN_PHPBB', true); $root = dirname(dirname(__DIR__));
require $root . '/phpBB2/includes/php_compat.php'; require $root . '/phpBB2/db/mysqli.php';
require $root . '/phpBB2/album_mod/album_file_coordinator.php'; require __DIR__ . '/album-native-observer.php';
function afc_check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
function afc_denied($callback, $message) { $denied = false; try { $callback(); } catch (AlbumFileCoordinatorFailure $error) { $denied = true; } afc_check($denied, $message); $GLOBALS['cases']++; }
class AlbumFileCoordinatorSource extends sql_db
{
    var $queries = array(); var $failure = 0; var $kind = ''; var $count = 0; var $closed = 0; var $other = ''; var $lost = ''; var $factory_count = 0; var $first = null;
    function sql_dedicated_connection()
    {
        $this->factory_count++;
        if ($this->kind === 'same-object') { return $this; }
        if ($this->kind === 'borrowed-handle') { return new AlbumNativeObservedConnection($this, $this); }
        if ($this->kind === 'throw-factory') { throw new RuntimeException('Synthetic factory failure'); }
        if ($this->kind === 'second-borrowed' && $this->factory_count === 2) { return $this->first; }
        $connection = parent::sql_dedicated_connection();
        if ($this->kind === 'wrong-schema') { afc_check($connection->sql_query('USE ' . $this->other) !== false, 'Own alternate schema'); }
        if ($this->kind === 'autocommit-off') { afc_check($connection->sql_query('SET autocommit=0') !== false, 'Own invalid dedicated setting'); }
        if ($this->kind === 'non-utf8') { afc_check(mysqli_set_charset($connection->db_connect_id, 'latin1'), 'Own invalid connection charset'); }
        $wrapped = new AlbumFileCoordinatorObserved($connection, $this); $wrapped->role = $this->factory_count;
        if ($this->factory_count === 1) { $this->first = $wrapped; }
        if ($this->kind === 'inherited-lock' && $this->factory_count === 1) { afc_check($connection->sql_query("SELECT GET_LOCK('album_files_" . sha1($this->dbname . "\0fixture_") . "',0)") !== false, 'Owned reused participant already holds namespace'); }
        return $wrapped;
    }
    function observed_query($database, $sql, $transaction = false)
    {
        $this->queries[] = $sql; $this->count++;
        if ($this->failure === $this->count) { return false; }
        return $database->sql_query($sql, $transaction);
    }
    function reset_case($failure = 0, $kind = '') { $this->queries = array(); $this->count = 0; $this->failure = $failure; $this->kind = $kind; $this->factory_count = 0; $this->closed = 0; $this->lost = ''; }
}
class AlbumFileCoordinatorObserved extends AlbumNativeObservedConnection
{
    var $role;
    function sql_query($sql = '', $transaction = false)
    {
        if ($sql === 'COMMIT' && $this->observer->lost === (string) $this->role) {
            $this->observer->lost = ''; afc_check($this->database->sql_query($sql) !== false, 'Actual unacknowledged native commit'); return false;
        }
        return parent::sql_query($sql, $transaction);
    }
    function sql_close() { $this->observer->closed++; return $this->database->sql_close(); }
}
$port = getenv('PHPBB_ALBUM_FILE_COORDINATOR_PORT') ?: '3306'; $password = getenv('PHPBB_ALBUM_FILE_COORDINATOR_PASSWORD') ?: '';
afc_check(preg_match('/^[0-9]{1,5}$/D', $port) && (int) $port > 0 && (int) $port <= 65535, 'Own loopback port'); $host = '127.0.0.1:' . $port;
$schema = 'codex_album_coord_' . bin2hex(phpbb_random_bytes(8)); $other = $schema . '_other';
$control = new sql_db($host, 'root', $password, '', false); $source = null; $coordinator = null; $peer = null; $created = false; $other_created = false; $cases = 0;
function afc_sql($sql) { $result = $GLOBALS['control']->sql_query($sql); afc_check($result !== false, 'Owned fixture SQL'); return $result; }
function afc_rows($sql) { $result = afc_sql($sql); try { return $GLOBALS['control']->sql_fetchrowset($result); } finally { $GLOBALS['control']->sql_freeresult($result); } }
function afc_clear() { foreach (array('album_file_items','album_file_jobs','album') as $table) { afc_sql('DELETE FROM fixture_' . $table); } }
function afc_prepare($coordinator)
{
    $operation = str_repeat('a', 32); $hash = str_repeat('b', 64);
    $coordinator->durable_begin();
    $coordinator->durable_query("INSERT INTO fixture_album_file_jobs(operation_id,operation_kind,operation_state,actor_id,binding_hash,capability_hash,item_count,picture_count,created_at) VALUES('" . $operation . "','upload','prepared',-1,'" . $hash . "','" . $hash . "',1,1,123)");
    $coordinator->durable_query("INSERT INTO fixture_album_file_items(operation_id,item_index,item_hash,file_scope,filename,file_present,file_bytes,file_sha256,item_state) VALUES('" . $operation . "',0,'" . $hash . "','original','owned.jpg',1,42,'" . $hash . "','pending')");
    $coordinator->durable_commit();
}
function afc_publish($coordinator)
{
    $coordinator->sql_query("INSERT INTO fixture_album(pic_id,pic_filename,pic_title,pic_user_id,pic_time) VALUES(7,'owned.jpg','Own synthetic picture',-1,123)");
    $coordinator->sql_query("UPDATE fixture_album_file_jobs SET operation_state='published',picture_id=7 WHERE operation_id='" . str_repeat('a', 32) . "' AND operation_state='prepared'");
    $coordinator->commit();
}
function afc_consistent()
{
    $jobs = afc_rows('SELECT operation_state FROM fixture_album_file_jobs'); $items = afc_rows('SELECT item_index FROM fixture_album_file_items'); $pictures = afc_rows('SELECT pic_id FROM fixture_album');
    afc_check(count($jobs) <= 1 && count($jobs) === count($items), 'Prepared receipt and item always commit together');
    afc_check(!$pictures ? (!$jobs || $jobs[0]['operation_state'] === 'prepared') : (count($pictures) === 1 && count($jobs) === 1 && $jobs[0]['operation_state'] === 'published'), 'Picture and published receipt always commit together');
}
$reporting = error_reporting(E_ALL); set_error_handler(function ($severity, $message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } });
try {
    afc_check($control->db_connect_id, 'Own native control');
    afc_sql('CREATE DATABASE ' . $schema . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); $created = true;
    afc_sql('CREATE DATABASE ' . $other . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); $other_created = true; afc_sql('USE ' . $schema);
    $ddl = file_get_contents($root . '/phpBB2/install/schemas/mysql_schema.sql');
    foreach (array('users','sessions','user_group','album','album_cat','album_comment','album_rate','album_config','album_file_jobs','album_file_items') as $suffix) {
        afc_check(preg_match('/^CREATE TABLE `?phpbb_' . $suffix . '`?\s*\([\s\S]*?;/m', $ddl, $match) === 1, 'Canonical coordinator table');
        afc_sql(str_replace('phpbb_' . $suffix, 'fixture_' . $suffix, $match[0]));
    }
    afc_sql('CREATE TABLE caller_marker(id INT PRIMARY KEY,value INT) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'); afc_sql('INSERT INTO caller_marker VALUES(1,1)');
    $source = new AlbumFileCoordinatorSource($host, 'root', $password, $schema, false); $source->other = $other;
    $source->reset_case(); $coordinator = new AlbumFileCoordinator($source, 'fixture_', 0); $coordinator->begin(); afc_prepare($coordinator);
    afc_check(afc_rows('SELECT operation_state FROM fixture_album_file_jobs')[0]['operation_state'] === 'prepared' && !afc_rows('SELECT pic_id FROM fixture_album'), 'Upload receipt durably commits before picture'); $cases++;
    afc_publish($coordinator); afc_consistent(); afc_check($coordinator->assert_lock() && $coordinator->durable_rows('SELECT operation_state FROM fixture_album_file_jobs')[0]['operation_state'] === 'published', 'Keep namespace and read committed receipt after publication'); $cases++;
    afc_denied(function () use ($coordinator) { $coordinator->sql_query('DELETE FROM fixture_album'); }, 'Finished writer cannot mutate');
    afc_denied(function () use ($coordinator) { $coordinator->begin(); }, 'Finished operation cannot restart');
    $coordinator->release(); $coordinator = null; afc_check($source->closed === 2, 'Close two distinct owned handles'); $cases++;
    // Establish the exact successful path, then fail every actual native SQL
    // statement in turn. No in-memory transaction/database model is involved.
    afc_clear(); $source->reset_case(); $coordinator = new AlbumFileCoordinator($source, 'fixture_', 0); $coordinator->begin(); afc_prepare($coordinator); afc_publish($coordinator); $coordinator->durable_rows('SELECT operation_state FROM fixture_album_file_jobs'); $coordinator->release(); $coordinator = null; $baseline = $source->queries;
    foreach ($baseline as $index => $sql) {
        afc_clear(); $source->reset_case($index + 1); $failed = false;
        try { $coordinator = new AlbumFileCoordinator($source, 'fixture_', 0); $coordinator->begin(); afc_prepare($coordinator); afc_publish($coordinator); $coordinator->durable_rows('SELECT operation_state FROM fixture_album_file_jobs'); }
        catch (AlbumFileCoordinatorFailure $error) { $failed = true; }
        finally { if ($coordinator) { $coordinator->release(); $coordinator = null; } }
        afc_check($failed, 'Actual statement fault was observed: ' . ($index + 1)); afc_consistent(); afc_check($source->sql_query('SELECT 1') !== false, 'Caller survives statement failure'); $cases++;
    }
    foreach (array('same-object','borrowed-handle','second-borrowed','throw-factory','wrong-schema','autocommit-off','non-utf8') as $kind) {
        afc_clear(); $source->reset_case(0, $kind); afc_denied(function () use ($source) { new AlbumFileCoordinator($source, 'fixture_', 0); }, 'Reject unsafe factory: ' . $kind);
        afc_check($source->sql_query('SELECT 1') !== false, 'Never close request handle: ' . $kind); afc_consistent();
    }
    $source->reset_case(0, 'inherited-lock'); afc_denied(function () use ($source) { new AlbumFileCoordinator($source, 'fixture_', 0); }, 'Do not adopt existing namespace on reused participant');
    $inherited = 'album_files_' . sha1($schema . "\0fixture_");
    afc_check($source->first->database->db_connect_id instanceof mysqli && afc_rows("SELECT IS_USED_LOCK('" . $inherited . "') AS owner")[0]['owner'] !== null, 'Rejecting reused participant preserves its existing connection and named lock'); $cases++; $source->first->sql_close();
    foreach (array('1','2') as $role) {
        afc_clear(); $source->reset_case(); $coordinator = new AlbumFileCoordinator($source, 'fixture_', 0); $coordinator->begin();
        if ($role === '2') { $source->lost = $role; afc_denied(function () use ($coordinator) { afc_prepare($coordinator); }, 'Lost actual prepared commit acknowledgement'); }
        else { afc_prepare($coordinator); $source->lost = $role; afc_denied(function () use ($coordinator) { afc_publish($coordinator); }, 'Lost actual published commit acknowledgement'); }
        afc_denied(function () use ($coordinator) { $coordinator->assert_lock(); }, 'Uncertain commit closes coordinator instead of claiming ownership');
        $coordinator->release(); $coordinator = null; afc_consistent();
        afc_check(afc_rows('SELECT operation_state FROM fixture_album_file_jobs')[0]['operation_state'] === ($role === '2' ? 'prepared' : 'published'), 'Independent connection sees actual committed receipt'); $cases++;
    }
    afc_clear(); $source->reset_case(); $coordinator = new AlbumFileCoordinator($source, 'fixture_', 0); $coordinator->begin(); afc_prepare($coordinator); $coordinator->release(); $coordinator = null;
    afc_check(afc_rows('SELECT operation_state FROM fixture_album_file_jobs')[0]['operation_state'] === 'prepared' && !afc_rows('SELECT pic_id FROM fixture_album'), 'Metadata rollback retains durable preparation for recovery'); $cases++;
    $source->reset_case(); $coordinator = new AlbumFileCoordinator($source, 'fixture_', 0); $coordinator->begin();
    afc_denied(function () use ($coordinator) { $coordinator->sql_query('COMMIT'); }, 'No accidental caller commit through query facade');
    afc_denied(function () use ($coordinator) { $coordinator->sql_query('ALTER TABLE fixture_album ENGINE=MyISAM'); }, 'No implicit DDL commit');
    afc_denied(function () use ($coordinator) { $coordinator->sql_query('DELETE FROM caller_marker'); }, 'No unrelated metadata table mutation');
    $coordinator->sql_query("INSERT INTO fixture_album(pic_id,pic_filename,pic_title,pic_user_id,pic_time) VALUES(19,'driver.jpg','Driver metadata',-1,123)");
    afc_check((int) $coordinator->sql_affectedrows() === 1 && (int) $coordinator->sql_nextid() === 19, 'Ownership SELECT preserves affected rows and inserted ID'); $cases++;
    $coordinator->sql_query('SELECT pic_id FROM fixture_album WHERE pic_id=19');
    afc_check((int) $coordinator->sql_numrows() === 1 && (int) $coordinator->sql_fetchrow()['pic_id'] === 19, 'Ownership SELECT preserves implicit legacy query result'); $coordinator->sql_freeresult(); $cases++;
    $coordinator->durable_begin();
    afc_denied(function () use ($coordinator) { $coordinator->durable_query('DELETE FROM fixture_album'); }, 'Separate receipt writer cannot modify pictures');
    afc_denied(function () use ($coordinator) { $coordinator->commit(); }, 'Cannot publish while prepared receipt transaction remains open');
    afc_denied(function () use ($coordinator) { $coordinator->durable_rows('SELECT * FROM fixture_album_file_jobs'); }, 'Do not confuse uncommitted receipt with durable state');
    $coordinator->release(); $coordinator = null;
    foreach (array('fixture_album','fixture_album_file_jobs','fixture_album_file_items') as $table) {
        foreach (array('ENGINE=MyISAM','ROW_FORMAT=COMPACT','DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci') as $change) {
            afc_sql('ALTER TABLE ' . $table . ' ' . $change); $source->reset_case();
            $coordinator = new AlbumFileCoordinator($source, 'fixture_', 0); afc_denied(function () use ($coordinator) { $coordinator->begin(); }, 'Legacy storage cannot participate: ' . $table . '/' . $change);
            afc_denied(function () use ($coordinator) { $coordinator->durable_begin(); }, 'Failed table pin cannot prepare receipts');
            afc_denied(function () use ($coordinator) { $coordinator->sql_query('DELETE FROM fixture_album'); }, 'Failed table pin cannot mutate metadata');
            afc_denied(function () use ($coordinator) { $coordinator->commit(); }, 'Failed table pin cannot commit'); $coordinator->release(); $coordinator = null;
            afc_sql('ALTER TABLE ' . $table . ' ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        }
    }
    afc_clear(); $source->reset_case(); afc_check($source->sql_query('START TRANSACTION') && $source->sql_query('UPDATE caller_marker SET value=2 WHERE id=1'), 'Unrelated pending caller transaction');
    $before = $source->sql_query('SELECT @@SESSION.sql_mode AS mode,@@SESSION.autocommit AS automatic,@@SESSION.innodb_lock_wait_timeout AS wait_time'); $settings = $source->sql_fetchrow($before); $source->sql_freeresult($before);
    $lock = 'owned_fixture_' . substr(sha1($schema), 0, 30); afc_check($source->sql_query("SELECT GET_LOCK('" . $lock . "',0)") !== false, 'Unrelated caller named lock');
    $coordinator = new AlbumFileCoordinator($source, 'fixture_', 0); $coordinator->begin(); afc_prepare($coordinator); afc_publish($coordinator); $coordinator->release(); $coordinator = null;
    $result = $source->sql_query('SELECT value FROM caller_marker'); $own = $source->sql_fetchrow($result); $source->sql_freeresult($result);
    afc_check((int) $own['value'] === 2 && (int) afc_rows('SELECT value FROM caller_marker')[0]['value'] === 1, 'Caller transaction neither committed nor rolled back'); $cases++;
    $result = $source->sql_query('SELECT @@SESSION.sql_mode AS mode,@@SESSION.autocommit AS automatic,@@SESSION.innodb_lock_wait_timeout AS wait_time'); $after = $source->sql_fetchrow($result); $source->sql_freeresult($result); afc_check($settings === $after, 'Caller session settings preserved'); $cases++;
    afc_check(afc_rows("SELECT IS_USED_LOCK('" . $lock . "')=IS_USED_LOCK('" . $lock . "') AS held")[0]['held'] === '1', 'Caller named lock still held'); $cases++;
    afc_check($source->sql_query('ROLLBACK') !== false && $source->sql_query("SELECT RELEASE_LOCK('" . $lock . "')") !== false, 'Caller controls own rollback and lock');
    // Real independent native connections serialize one namespace; unrelated
    // table prefixes remain independent. Failed contenders own no metadata rows.
    $source->reset_case(); $coordinator = new AlbumFileCoordinator($source, 'fixture_', 0); $coordinator->begin();
    afc_denied(function () use ($source) { new AlbumFileCoordinator($source, 'fixture_', 0); }, 'Concurrent request cannot adopt held namespace');
    $peer = new AlbumFileCoordinator($source, 'other_', 0); afc_check($peer->assert_lock(), 'Different prefix has independent namespace'); $peer->release(); $peer = null; $cases++;
    $coordinator->release(); $coordinator = null; $peer = new AlbumFileCoordinator($source, 'fixture_', 0); afc_check($peer->assert_lock(), 'Successor obtains namespace after release'); $peer->release(); $peer = null; $cases++;
    $source->reset_case(); $coordinator = new AlbumFileCoordinator($source, 'fixture_', 0); $coordinator->begin();
    $coordinator->sql_query("SELECT RELEASE_LOCK('album_files_" . sha1($schema . "\0fixture_") . "')");
    afc_denied(function () use ($coordinator) { $coordinator->durable_begin(); }, 'Lost namespace cannot prepare files');
    afc_denied(function () use ($coordinator) { $coordinator->sql_query('DELETE FROM fixture_album'); }, 'Lost namespace cannot mutate metadata');
    afc_denied(function () use ($coordinator) { $coordinator->commit(); }, 'Lost namespace cannot commit'); $coordinator->release(); $coordinator = null;
    foreach (array(null, '', '../bad', str_repeat('a', 41)) as $prefix) { afc_denied(function () use ($source, $prefix) { new AlbumFileCoordinator($source, $prefix, 0); }, 'Typed bounded prefix required'); }
    foreach (array(-1, 4, '0', null) as $wait) { afc_denied(function () use ($source, $wait) { new AlbumFileCoordinator($source, 'fixture_', $wait); }, 'Typed bounded lock wait required'); }
    echo 'Native Album file coordinator: ' . $cases . ' cases PASS; ' . count($baseline) . " native statement boundaries, owned two-phase commits, lost acknowledgements and caller preservation; no live files/data.\n";
} finally {
    if ($peer) { $peer->release(); } if ($coordinator) { $coordinator->release(); } if ($source) { $source->sql_close(); }
    if ($created) { $control->sql_query('DROP DATABASE ' . $schema); } if ($other_created) { $control->sql_query('DROP DATABASE ' . $other); } $control->sql_close(); restore_error_handler(); error_reporting($reporting);
}
