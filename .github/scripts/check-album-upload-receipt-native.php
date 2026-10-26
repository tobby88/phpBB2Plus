<?php
if (PHP_SAPI !== 'cli' || getenv('PHPBB_ALBUM_UPLOAD_RECEIPT_NATIVE') !== '1') { echo "Native Album upload receipt fixture skipped.\n"; return; }
define('IN_PHPBB', true); $root = dirname(dirname(__DIR__));
require $root . '/phpBB2/includes/php_compat.php'; require $root . '/phpBB2/db/mysqli.php';
require $root . '/phpBB2/album_mod/album_upload_receipt.php'; require __DIR__ . '/album-native-observer.php';
function aur_check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
function aur_denied($callback, $message) { $denied = false; try { $callback(); } catch (AlbumFileCoordinatorFailure $error) { $denied = true; } catch (AlbumFileStorageFailure $error) { $denied = true; } aur_check($denied, $message); $GLOBALS['cases']++; }
class AlbumUploadReceiptSource extends sql_db
{
    var $queries = array(); var $failure = 0; var $count = 0; var $factory_count = 0; var $lost = '';
    function sql_dedicated_connection() { $connection = new AlbumUploadReceiptObserved(parent::sql_dedicated_connection(), $this); $connection->role = ++$this->factory_count; return $connection; }
    function observed_query($database, $sql, $transaction = false) { $this->queries[] = $sql; if (++$this->count === $this->failure) { return false; } return $database->sql_query($sql, $transaction); }
    function reset_case($failure = 0) { $this->queries = array(); $this->failure = $failure; $this->count = 0; $this->factory_count = 0; $this->lost = ''; }
}
class AlbumUploadReceiptObserved extends AlbumNativeObservedConnection
{
    var $role;
    function sql_query($sql = '', $transaction = false) { if ($sql === 'COMMIT' && $this->observer->lost === (string) $this->role) { $this->observer->lost = ''; aur_check($this->database->sql_query($sql) !== false, 'Actual lost commit acknowledgement'); return false; } return parent::sql_query($sql, $transaction); }
}
$port = getenv('PHPBB_ALBUM_UPLOAD_RECEIPT_PORT') ?: '3306'; $password = getenv('PHPBB_ALBUM_UPLOAD_RECEIPT_PASSWORD') ?: '';
aur_check(preg_match('/^[0-9]{1,5}$/D', $port) && (int) $port > 0 && (int) $port <= 65535, 'Own loopback port');
$schema = 'codex_album_receipt_' . bin2hex(phpbb_random_bytes(8)); $base = sys_get_temp_dir() . '/' . $schema;
$control = new sql_db('127.0.0.1:' . $port, 'root', $password, '', false); $source = null; $coordinator = null; $created = false; $created_directory = false; $input = null; $cases = 0;
$scopes = array('original' => '/album_mod/upload/', 'small' => '/album_mod/upload/cache/', 'medium' => '/album_mod/upload/med_cache/');
$payload = str_repeat("Only owned opaque fixture bytes Größe 😀\n", 50); $metadata = array('present' => true, 'bytes' => strlen($payload), 'sha256' => hash('sha256', $payload));
$descriptor = array('operation' => str_repeat('a', 32), 'actor' => -1, 'owner' => 0, 'category' => 1, 'binding' => str_repeat('b', 64), 'capability' => str_repeat('c', 64));
$items = array(); foreach ($scopes as $scope => $relative) { $items[] = array('scope' => $scope, 'name' => "Größe's 😀.jpg", 'metadata' => $metadata); }
function aur_sql($sql) { $result = $GLOBALS['control']->sql_query($sql); aur_check($result !== false, 'Own native fixture SQL'); return $result; }
function aur_rows($sql) { $result = aur_sql($sql); try { return $GLOBALS['control']->sql_fetchrowset($result); } finally { $GLOBALS['control']->sql_freeresult($result); } }
function aur_literal($value) { return 'CONVERT(0x' . bin2hex($value) . ' USING utf8mb4)'; }
function aur_clear()
{
    foreach (array('album_file_items','album_file_jobs','album') as $suffix) { aur_sql('DELETE FROM fixture_' . $suffix); }
    foreach ($GLOBALS['items'] as $item) {
        $path = $GLOBALS['base'] . $GLOBALS['scopes'][$item['scope']] . $item['name'];
        if (is_file($path) && !is_link($path)) { aur_check(unlink($path), 'Remove only own enumerated completed test file'); }
        $stage = dirname($path) . '/.album-stage-' . $GLOBALS['descriptor']['operation'] . '-' . substr(hash('sha256', $item['scope'] . "\0" . $item['name']), 0, 16) . '.tmp';
        if (is_file($stage) && !is_link($stage)) { aur_check(unlink($stage), 'Remove only own enumerated prepared test stage'); }
    }
}
function aur_photo($coordinator, $descriptor)
{
    $name = aur_literal($GLOBALS['items'][0]['name']);
    $coordinator->sql_query('INSERT INTO fixture_album(pic_id,pic_filename,pic_thumbnail,pic_title,pic_user_id,pic_cat_id,pic_time) VALUES(7,' . $name . ',' . $name . ",'Synthetic image'," . $descriptor['actor'] . ',' . $descriptor['category'] . ',123)');
}
function aur_consistent()
{
    $jobs = aur_rows('SELECT operation_state FROM fixture_album_file_jobs'); $rows = aur_rows('SELECT item_state FROM fixture_album_file_items'); $photos = aur_rows('SELECT pic_id FROM fixture_album');
    aur_check(count($jobs) <= 1 && count($rows) === count($jobs) * 3, 'Header and three items commit atomically');
    $published = $jobs && $jobs[0]['operation_state'] === 'published';
    aur_check(count($photos) === ($published ? 1 : 0), 'Picture and published receipt commit atomically');
    foreach ($rows as $row) { aur_check($row['item_state'] === ($published ? 'published' : 'pending'), 'All committed item states match header'); }
    foreach ($GLOBALS['items'] as $item) {
        $path = $GLOBALS['base'] . $GLOBALS['scopes'][$item['scope']] . $item['name'];
        if (is_file($path)) { aur_check($jobs && hash_file('sha256', $path) === $GLOBALS['metadata']['sha256'], 'Every published byte has a durable receipt'); }
        if ($published) { aur_check(is_file($path), 'A committed picture retains all three exact files'); }
    }
    aur_check(is_resource($GLOBALS['input']) && hash_file('sha256', $GLOBALS['base'] . '/source.bin') === $GLOBALS['metadata']['sha256'] && ftell($GLOBALS['input']) === 7, 'Borrowed source bytes, position and handle preserved');
}
function aur_attempt($descriptor)
{
    $GLOBALS['coordinator'] = new AlbumFileCoordinator($GLOBALS['source'], 'fixture_', 0); $coordinator = $GLOBALS['coordinator']; $coordinator->begin();
    $receipt = new AlbumUploadReceipt($coordinator, 'fixture_', $GLOBALS['base']); $receipt->prepare($descriptor, $GLOBALS['items']); $files = $receipt->files($descriptor);
    foreach ($GLOBALS['items'] as $item) { $files->publish($descriptor['operation'], $item['scope'], $item['name'], $GLOBALS['input'], $item['metadata']); }
    aur_photo($coordinator, $descriptor); $receipt->mark_published($descriptor, 7); $coordinator->commit(); return $receipt;
}
if (isset($argv[1]) && $argv[1] === 'crash-worker') {
    aur_check(count($argv) === 4 && preg_match('/^codex_album_receipt_[a-f0-9]{16}$/D', $argv[2]) && in_array($argv[3], array('prepared','files','published'), true), 'Only exact owned child context');
    $schema = $argv[2]; $base = sys_get_temp_dir() . '/' . $schema;
    aur_check(is_dir($base) && !is_link($base) && realpath(dirname($base)) === realpath(sys_get_temp_dir()), 'Own existing parent directory');
    $source = new AlbumUploadReceiptSource('127.0.0.1:' . $port, 'root', $password, $schema, false); $input = fopen($base . '/source.bin', 'rb'); aur_check($input && fseek($input, 7) === 0, 'Own child borrowed source');
    $coordinator = new AlbumFileCoordinator($source, 'fixture_', 0); $coordinator->begin(); $receipt = new AlbumUploadReceipt($coordinator, 'fixture_', $base); $receipt->prepare($descriptor, $items);
    if ($argv[3] !== 'prepared') { $files = $receipt->files($descriptor); foreach ($items as $item) { $files->publish($descriptor['operation'], $item['scope'], $item['name'], $input, $metadata); } }
    if ($argv[3] === 'published') { aur_photo($coordinator, $descriptor); $receipt->mark_published($descriptor, 7); $coordinator->commit(); }
    aur_check(file_put_contents($base . '/crash-ready', $argv[3]) === strlen($argv[3]), 'Own child reached exact crash boundary');
    $deadline = microtime(true) + 20; while (microtime(true) < $deadline) { usleep(10000); }
    throw new RuntimeException('Parent must terminate actual worker before its shutdown/finally handlers');
}
function aur_crash_worker($boundary)
{
    $command = (DIRECTORY_SEPARATOR === chr(92) ? '' : 'exec ') . escapeshellarg(PHP_BINARY) . ' ';
    if (DIRECTORY_SEPARATOR === chr(92)) { $command .= '-n -d ' . escapeshellarg('extension_dir=' . ini_get('extension_dir')) . ' -d extension=php_mysqli.dll -d extension=php_openssl.dll '; if (extension_loaded('mbstring')) { $command .= '-d extension=php_mbstring.dll '; } }
    $command .= escapeshellarg(__FILE__) . ' crash-worker ' . escapeshellarg($GLOBALS['schema']) . ' ' . escapeshellarg($boundary);
    $process = proc_open($command, array(0 => array('pipe','r'), 1 => array('pipe','w'), 2 => array('pipe','w')), $pipes, null, null, array('bypass_shell' => true)); aur_check(is_resource($process), 'Start actual owned worker'); fclose($pipes[0]);
    stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false); $out = ''; $err = ''; $ready = $GLOBALS['base'] . '/crash-ready';
    try {
        $deadline = microtime(true) + 10;
        do {
            // Windows anonymous pipes do not implement nonblocking reads.
            // This worker emits nothing before its barrier; read diagnostics
            // only after it exits, never block the parent before the kill.
            $status = proc_get_status($process); clearstatcache(true, $ready);
            if (!$status['running']) { $out .= stream_get_contents($pipes[1]); $err .= stream_get_contents($pipes[2]); }
            // Creation precedes the short barrier write, so existence alone
            // is not a completed handshake with the independently running PHP.
            if (is_file($ready) && file_get_contents($ready) === $boundary) { break; }
            aur_check($status['running'] && microtime(true) < $deadline, 'Worker reaches exact journal boundary: ' . $out . $err); usleep(10000);
        } while (true);
        aur_check(file_get_contents($ready) === $boundary && $status['running'], 'Kill only owned live worker at confirmed boundary: ' . json_encode($status) . ' ready=' . file_get_contents($ready) . ' output=' . $out . $err);
        aur_check(proc_terminate($process, 9), 'Forced native process termination, without PHP shutdown cleanup'); $deadline = microtime(true) + 5;
        do { $status = proc_get_status($process); if (!$status['running']) { break; } aur_check(microtime(true) < $deadline, 'Bounded actual worker termination'); usleep(10000); } while (true);
        $out .= stream_get_contents($pipes[1]); $err .= stream_get_contents($pipes[2]); aur_check($out === '' && $err === '', 'Actual killed child emitted no PHP error');
        fclose($pipes[1]); fclose($pipes[2]); proc_close($process); $process = null; aur_check(unlink($ready), 'Remove own exact barrier');
    } finally { if (is_resource($process)) { proc_terminate($process, 9); foreach (array(1,2) as $index) { if (is_resource($pipes[$index])) { fclose($pipes[$index]); } } proc_close($process); } }
}
$reporting = error_reporting(E_ALL); set_error_handler(function ($severity, $message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } });
try {
    aur_check($control->db_connect_id, 'Own loopback control'); aur_sql('CREATE DATABASE ' . $schema . ' CHARACTER SET utf8mb4 COLLATE=utf8mb4_unicode_ci'); $created = true; aur_sql('USE ' . $schema);
    $ddl = file_get_contents($root . '/phpBB2/install/schemas/mysql_schema.sql');
    foreach (array('users','sessions','user_group','album','album_cat','album_comment','album_rate','album_config','album_file_jobs','album_file_items') as $suffix) { aur_check(preg_match('/^CREATE TABLE `?phpbb_' . $suffix . '`?\s*\([\s\S]*?;/m', $ddl, $match) === 1, 'Canonical native receipt tables'); aur_sql(str_replace('phpbb_' . $suffix, 'fixture_' . $suffix, $match[0])); }
    aur_check(!file_exists($base) && mkdir($base, 0700), 'Own isolated file root'); $created_directory = true;
    foreach (array('/album_mod','/album_mod/upload','/album_mod/upload/cache','/album_mod/upload/med_cache') as $directory) { aur_check(mkdir($base . $directory, 0700), 'Own isolated album directories'); }
    aur_check(file_put_contents($base . '/source.bin', $payload) === strlen($payload), 'Own borrowed source'); $input = fopen($base . '/source.bin', 'rb'); aur_check($input && fseek($input, 7) === 0, 'Keep borrowed position');
    $source = new AlbumUploadReceiptSource('127.0.0.1:' . $port, 'root', $password, $schema, false);
    $baseline = array();
    if (!isset($argv[1]) || $argv[1] !== 'crash-only') {
    foreach (array(array(-1,0),array(7,8)) as $identity) {
        foreach (array('', 'NO_BACKSLASH_ESCAPES', 'ANSI_QUOTES') as $mode) {
            $descriptor['actor'] = $identity[0]; $descriptor['owner'] = $identity[1]; aur_clear(); $source->reset_case(); aur_check($source->sql_query("SET SESSION sql_mode='" . $mode . "'") !== false, 'Caller SQL mode');
            $receipt = aur_attempt($descriptor); aur_consistent(); $saved = $receipt->committed($descriptor);
            aur_check($saved['state'] === 'published' && $saved['picture'] === 7 && $saved['items'] === $items, 'Guest/public and personal upload retain exact UTF8 bytes through commit'); $cases++;
            $coordinator->release(); $coordinator = null;
        }
    }
    $descriptor['actor'] = -1; $descriptor['owner'] = 0; aur_clear(); $source->reset_case(); $receipt = aur_attempt($descriptor); $baseline = $source->queries; $coordinator->release(); $coordinator = null;
    foreach ($baseline as $index => $sql) {
        aur_clear(); $source->reset_case($index + 1); $failed = false;
        try { aur_attempt($descriptor); } catch (AlbumFileCoordinatorFailure $error) { $failed = true; } catch (AlbumFileStorageFailure $error) { $failed = true; }
        finally { if ($coordinator) { $coordinator->release(); $coordinator = null; } }
        aur_check($failed, 'Fault reached actual receipt/file boundary ' . ($index + 1)); aur_consistent(); $cases++;
    }
    aur_clear(); $source->reset_case(); $coordinator = new AlbumFileCoordinator($source, 'fixture_', 0); $coordinator->begin(); $receipt = new AlbumUploadReceipt($coordinator, 'fixture_', $base);
    aur_denied(function () use ($coordinator, $base) { new AlbumUploadReceipt($coordinator, 'other_', $base); }, 'Do not bind foreign table prefix');
    aur_denied(function () use ($receipt, $descriptor) { $receipt->files($descriptor); }, 'No filesystem publication capability without committed receipt');
    $saved = $receipt->prepare($descriptor, $items); aur_check($receipt->prepare($descriptor, $items) === $saved, 'Exact prepared retry adopts no new content'); $cases++;
    foreach (array('operation','binding','capability','actor','owner','category') as $field) {
        $changed = $descriptor; $changed[$field] = in_array($field, array('actor','owner','category'), true) ? 9 : str_repeat('d', strlen($descriptor[$field]));
        if ($field === 'operation') { aur_check($receipt->committed($changed) === null, 'Foreign operation has no receipt'); $cases++; }
        else { aur_denied(function () use ($receipt, $changed) { $receipt->committed($changed); }, 'Exact receipt identity required: ' . $field); }
    }
    $changed_items = $items; $changed_items[0]['metadata']['sha256'] = str_repeat('d', 64); aur_denied(function () use ($receipt, $descriptor, $changed_items) { $receipt->prepare($descriptor, $changed_items); }, 'Retry cannot adopt different source hash');
    $files = $receipt->files($descriptor);
    aur_denied(function () use ($files, $descriptor, $input, $metadata) { $files->publish($descriptor['operation'], 'original', 'foreign.jpg', $input, $metadata); }, 'Guard binds exact recorded filename');
    aur_denied(function () use ($files, $input, $metadata, $items) { $files->publish(str_repeat('e', 32), 'original', $items[0]['name'], $input, $metadata); }, 'Guard binds exact operation');
    aur_denied(function () use ($files, $descriptor, $input, $metadata, $items) { $changed = $metadata; $changed['sha256'] = str_repeat('d', 64); $files->publish($descriptor['operation'], 'original', $items[0]['name'], $input, $changed); }, 'Guard binds exact source bytes');
    aur_denied(function () use ($files, $descriptor, $items) { $files->remove_stage($descriptor['operation'], 'original', $items[0]['name']); }, 'Publication guard grants no unlink/stage cleanup capability');
    aur_photo($coordinator, $descriptor); aur_denied(function () use ($receipt, $descriptor) { $receipt->mark_published($descriptor, 7); }, 'Cannot commit picture receipt before exact files exist');
    $coordinator->release(); $coordinator = null; aur_consistent();
    foreach (array('operation_kind'=>'delete','operation_state'=>'finished','item_count'=>2,'picture_id'=>7,'binding_hash'=>str_repeat('d',64)) as $field => $value) {
        aur_clear(); $source->reset_case(); $coordinator = new AlbumFileCoordinator($source, 'fixture_', 0); $coordinator->begin(); $receipt = new AlbumUploadReceipt($coordinator, 'fixture_', $base); $receipt->prepare($descriptor, $items);
        aur_sql('UPDATE fixture_album_file_jobs SET ' . $field . '=' . (is_int($value) ? $value : aur_literal($value)));
        aur_denied(function () use ($receipt, $descriptor) { $receipt->files($descriptor); }, 'Malformed current header cannot grant file capability: ' . $field); $coordinator->release(); $coordinator = null;
    }
    foreach (array('item_hash'=>str_repeat('d',64),'file_bytes'=>0,'file_present'=>0,'item_state'=>'removed','filename'=>'../outside.jpg') as $field => $value) {
        aur_clear(); $source->reset_case(); $coordinator = new AlbumFileCoordinator($source, 'fixture_', 0); $coordinator->begin(); $receipt = new AlbumUploadReceipt($coordinator, 'fixture_', $base); $receipt->prepare($descriptor, $items);
        aur_sql('UPDATE fixture_album_file_items SET ' . $field . '=' . (is_int($value) ? $value : aur_literal($value)) . ' WHERE item_index=0');
        aur_denied(function () use ($receipt, $descriptor) { $receipt->files($descriptor); }, 'Malformed current item cannot grant file capability: ' . $field); $coordinator->release(); $coordinator = null;
    }
    foreach (array('pic_filename','pic_thumbnail') as $field) {
        aur_clear(); $source->reset_case(); $coordinator = new AlbumFileCoordinator($source, 'fixture_', 0); $coordinator->begin(); $receipt = new AlbumUploadReceipt($coordinator, 'fixture_', $base); $receipt->prepare($descriptor, $items);
        aur_sql("INSERT INTO fixture_album(pic_id,pic_filename,pic_thumbnail,pic_title,pic_user_id,pic_time) VALUES(99,'foreign.jpg','','Foreign reference',8,1)"); aur_sql('UPDATE fixture_album SET ' . $field . '=' . aur_literal($items[0]['name']) . ' WHERE pic_id=99');
        $files = $receipt->files($descriptor); aur_denied(function () use ($files, $descriptor, $input, $metadata, $items) { $files->publish($descriptor['operation'], 'original', $items[0]['name'], $input, $metadata); }, 'Preserve surviving file reference: ' . $field); $coordinator->release(); $coordinator = null;
    }
    aur_clear(); $source->reset_case(); $receipt = aur_attempt($descriptor); aur_consistent(); $files = $receipt->files($descriptor);
    aur_denied(function () use ($files, $descriptor, $items, $input, $metadata) { $files->publish($descriptor['operation'], 'original', $items[0]['name'], $input, $metadata); }, 'Published receipt cannot republish files');
    $coordinator->release(); $coordinator = null; aur_denied(function () use ($files, $items) { $files->snapshot('original', $items[0]['name']); }, 'Old file capability expires with namespace owner');
    foreach (array('1','2') as $role) {
        aur_clear(); $source->reset_case(); $coordinator = new AlbumFileCoordinator($source, 'fixture_', 0); $coordinator->begin(); $receipt = new AlbumUploadReceipt($coordinator, 'fixture_', $base);
        if ($role === '2') { $source->lost = '2'; aur_denied(function () use ($receipt, $descriptor, $items) { $receipt->prepare($descriptor, $items); }, 'Lost durable preparation ACK never creates final files'); }
        else { $receipt->prepare($descriptor, $items); $files = $receipt->files($descriptor); foreach ($items as $item) { $files->publish($descriptor['operation'], $item['scope'], $item['name'], $input, $metadata); } aur_photo($coordinator, $descriptor); $receipt->mark_published($descriptor, 7); $source->lost = '1'; aur_denied(function () use ($coordinator) { $coordinator->commit(); }, 'Lost actual picture commit ACK never unlinks final files'); }
        $coordinator->release(); $coordinator = null; aur_consistent();
        $coordinator = new AlbumFileCoordinator($source, 'fixture_', 0); $receipt = new AlbumUploadReceipt($coordinator, 'fixture_', $base); $saved = $receipt->committed($descriptor);
        aur_check($saved['state'] === ($role === '2' ? 'prepared' : 'published') && count(aur_rows('SELECT pic_id FROM fixture_album')) === ($role === '2' ? 0 : 1), 'Independent retry resolves committed state without duplicate photo'); $cases++; $coordinator->release(); $coordinator = null;
    }
    }
    foreach (array('prepared','files','published') as $boundary) {
        aur_clear(); aur_crash_worker($boundary); aur_consistent(); $source->reset_case();
        $deadline = microtime(true) + 3;
        do { try { $coordinator = new AlbumFileCoordinator($source, 'fixture_', 0); break; } catch (AlbumFileCoordinatorFailure $error) { aur_check(microtime(true) < $deadline, 'Killed worker releases its DB namespace'); usleep(10000); } } while (true);
        $receipt = new AlbumUploadReceipt($coordinator, 'fixture_', $base); $saved = $receipt->committed($descriptor);
        aur_check($saved['state'] === ($boundary === 'published' ? 'published' : 'prepared'), 'Independent resolver sees durable state after forced process death: ' . $boundary);
        foreach ($items as $item) { aur_check(is_file($base . $scopes[$item['scope']] . $item['name']) === ($boundary !== 'prepared'), 'Forced death never loses a file already published: ' . $boundary); }
        $coordinator->release(); $coordinator = null; $cases++;
    }
    if (isset($argv[1]) && $argv[1] === 'crash-only') { echo "Native Album upload receipts: three scoped forced-process-death boundaries PASS; no live data.\n"; }
    else { echo 'Native Album upload receipts: ' . $cases . ' cases PASS; ' . count($baseline) . " real statement boundaries, actual three-scope files, guest/owner/UTF8, receipt-bound guards, lost commits and three forced process deaths; no live data.\n"; }
} finally {
    if ($coordinator) { $coordinator->release(); } if (is_resource($input)) { fclose($input); }
    if ($created_directory) { aur_clear(); foreach (array('source.bin','crash-ready') as $name) { if (is_file($base . '/' . $name)) { unlink($base . '/' . $name); } } foreach (array('/album_mod/upload/med_cache','/album_mod/upload/cache','/album_mod/upload','/album_mod') as $directory) { if (is_dir($base . $directory)) { rmdir($base . $directory); } } rmdir($base); }
    if ($source) { $source->sql_close(); } if ($created) { $control->sql_query('DROP DATABASE ' . $schema); } $control->sql_close(); restore_error_handler(); error_reporting($reporting);
}
