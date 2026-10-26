<?php
if (PHP_SAPI !== 'cli') { exit(2); }
define('IN_PHPBB', true);
$source = dirname(dirname(__DIR__)) . '/phpBB2/';
require $source . 'includes/php_compat.php'; require $source . 'album_mod/album_file_storage.php';
function afs_check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
function afs_refused($callback, $message) { $refused = false; try { $callback(); } catch (AlbumFileStorageFailure $error) { $refused = true; } afs_check($refused, $message); }
if (isset($argv[1]) && $argv[1] === 'crash-child') {
    $directory = isset($argv[2]) ? $argv[2] : null; $boundary = isset($argv[3]) ? (int) $argv[3] : 0;
    afs_check(is_string($directory) && preg_match('/^codex_album_file_[a-f0-9]{16}$/D', basename($directory)) && !is_link($directory) && realpath(dirname($directory)) === realpath(sys_get_temp_dir()) && in_array($boundary, array(3, 4), true), 'Exact owned crash child');
    $calls = 0; $guard = function () use (&$calls, $directory, $boundary) { $calls++; if ($calls === $boundary) { afs_check(file_put_contents($directory . '/crash-ready', (string) $boundary) !== false, 'Own crash barrier'); exit(77); } return true; };
    $files = new AlbumFileStorage($guard, $directory); $handle = fopen($directory . '/source.bin', 'rb');
    $expected = array('present' => true, 'bytes' => filesize($directory . '/source.bin'), 'sha256' => hash_file('sha256', $directory . '/source.bin'));
    $files->publish(str_repeat('a', 32), 'original', 'crash.jpg', $handle, $expected); throw new RuntimeException('Owned child must exit at the actual boundary');
}
$cases = 0;
foreach (array('a.jpg', '0.png', 'Größe 😀.JPEG', 'one two.gif', 'con photo.jpg', 'com10.png') as $name) { afs_check(AlbumFileStorage::filename($name), 'Legitimate byte-preserving image basename'); $cases++; }
foreach (array('', null, array(), 0, false, '.hidden.jpg', ' name.jpg', 'name.jpg ', 'name.jpg.', '../a.jpg', 'sub/a.jpg', 'sub\\a.jpg', '/a.jpg', '\\a.jpg', ':a.jpg', "\x00a.jpg", "a\nb.jpg", 'C:a.jpg', 'a.php', 'index.html', '.htaccess', 'nul.jpg', 'CON .jpg', 'LPT1.png', 'com9.jpeg', str_repeat('a', 252) . '.jpg', "invalid\xff.jpg") as $name) { afs_check(!AlbumFileStorage::filename($name), 'Invalid basename/device/type/UTF8 refused'); $cases++; }
$absent = array('present' => false, 'bytes' => 0, 'sha256' => null);
afs_check(AlbumFileStorage::metadata($absent) && AlbumFileStorage::metadata(array('sha256' => hash('sha256', ''), 'bytes' => 0, 'present' => true)), 'Typed absence and empty-file snapshots'); $cases++;
foreach (array(null, array(), array('present' => 1, 'bytes' => 1, 'sha256' => str_repeat('a', 64)), array('present' => true, 'bytes' => '1', 'sha256' => str_repeat('a', 64)), array('present' => true, 'bytes' => -1, 'sha256' => str_repeat('a', 64)), array('present' => true, 'bytes' => AlbumFileStorage::MAX_BYTES + 1, 'sha256' => str_repeat('a', 64)), array('present' => true, 'bytes' => 1, 'sha256' => str_repeat('A', 64)), array('present' => false, 'bytes' => 1, 'sha256' => null), array('present' => false, 'bytes' => 0, 'sha256' => null, 'extra' => true)) as $meta) { afs_check(!AlbumFileStorage::metadata($meta), 'Malformed cleanup metadata refused'); $cases++; }
$base = sys_get_temp_dir() . '/codex_album_file_' . bin2hex(phpbb_random_bytes(8)); $created = false; $input = null; $links = 0;
$scopes = array('original' => '/album_mod/upload/', 'small' => '/album_mod/upload/cache/', 'medium' => '/album_mod/upload/med_cache/');
$owned_names = array('owned.jpg', 'Größe 😀.png', 'sample.gif', 'wrong.jpg', 'revoked.jpg', 'uncertain.jpg', 'blocked.jpg', 'oversized.jpg', 'linked.jpg', 'crash.jpg'); $stages = array();
$capability = (object) array('allowed' => true, 'calls' => 0, 'fail_at' => null);
$guard = function () use ($capability) { $capability->calls++; return $capability->allowed && $capability->calls !== $capability->fail_at; };
$operation = str_repeat('a', 32); $bytes = str_repeat("Owned synthetic opaque bytes Größe 😀\n", 4000);
$expected = array('present' => true, 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes));
$reordered = array('sha256' => $expected['sha256'], 'bytes' => $expected['bytes'], 'present' => true);
try {
    afs_check(!file_exists($base) && mkdir($base, 0700), 'Reserve own file fixture'); $created = true;
    foreach (array('/album_mod', '/album_mod/upload', '/album_mod/upload/cache', '/album_mod/upload/med_cache') as $directory) { afs_check(mkdir($base . $directory), 'Own configured directories'); }
    afs_check(file_put_contents($base . '/source.bin', $bytes) === strlen($bytes) && file_put_contents($base . '/foreign.bin', 'UNCHANGED FOREIGN FIXTURE') === 25, 'Own borrowed source and external control');
    $input = fopen($base . '/source.bin', 'rb'); afs_check(is_resource($input), 'Borrowed source handle'); $files = new AlbumFileStorage($guard, $base);
    foreach ($scopes as $scope => $relative) { foreach ($owned_names as $name) { $stages[$files->stage_name($operation, $scope, $name)] = true; } }
    foreach (array(null, array(), '', str_repeat('A', 32), '../bad', str_repeat('a', 31)) as $bad) { afs_refused(function () use ($files, $bad) { $files->stage_name($bad, 'small', 'owned.jpg'); }, 'Exact operation identity required'); $cases++; }
    foreach ($scopes as $scope => $relative) {
        afs_check($files->snapshot($scope, 'owned.jpg') === $absent, 'Missing file snapshot'); $cases++;
        foreach (array('owned.jpg', 'Größe 😀.png', 'sample.gif') as $name) {
            afs_check(fseek($input, 17) === 0 && $files->publish($operation, $scope, $name, $input, $reordered), 'Actual exclusive staged publication');
            afs_check(is_resource($input) && ftell($input) === 17 && file_get_contents($base . '/source.bin') === $bytes, 'Borrowed source bytes/position/handle retained'); $cases++;
            afs_check($files->snapshot($scope, $name) === $expected && file_get_contents($base . $relative . $name) === $bytes, 'Exact complete final bytes'); $cases++;
            afs_refused(function () use ($files, $operation, $scope, $name, $input, $expected) { $files->publish($operation, $scope, $name, $input, $expected); }, 'Never overwrite an existing destination');
            afs_check(file_get_contents($base . $relative . $name) === $bytes, 'Existing final remains unchanged'); $cases++;
            $wrong = $expected; $wrong['sha256'] = str_repeat('0', 64); afs_check($files->remove_exact($scope, $name, $wrong) === 'changed' && is_file($base . $relative . $name), 'Changed content is preserved'); $cases++;
            afs_check($files->remove_exact($scope, $name, $absent) === 'changed', 'An absent inventory never adopts later bytes'); $cases++;
            afs_check($files->remove_exact($scope, $name, $reordered) === 'removed' && $files->remove_exact($scope, $name, $expected) === 'missing', 'Exact cleanup and idempotent retry, key order independent'); $cases++;
            afs_check($files->remove_stage($operation, $scope, $name) === 'missing', 'Successful publication has no abandoned stage'); $cases++;
        }
    }
    $wrong = $expected; $wrong['sha256'] = str_repeat('0', 64);
    afs_refused(function () use ($files, $operation, $input, $wrong) { $files->publish($operation, 'small', 'wrong.jpg', $input, $wrong); }, 'Hash mismatch refuses publication');
    $stage = $base . $scopes['small'] . $files->stage_name($operation, 'small', 'wrong.jpg');
    afs_check(!file_exists($base . $scopes['small'] . 'wrong.jpg') && is_file($stage) && ftell($input) === 17, 'Prepared stage retained on failure for durable recovery');
    afs_check($files->remove_stage($operation, 'small', 'wrong.jpg') === 'removed' && $files->remove_stage($operation, 'small', 'wrong.jpg') === 'missing', 'Only exact receipt-owned stage cleanup'); $cases += 3;
    $capability->allowed = false;
    afs_refused(function () use ($files) { $files->snapshot('original', 'owned.jpg'); }, 'Revoked capability refuses even snapshot');
    afs_refused(function () use ($files, $operation, $input, $expected) { $files->publish($operation, 'original', 'revoked.jpg', $input, $expected); }, 'Revoked capability refuses mutation');
    $capability->allowed = true; afs_check(!file_exists($base . $scopes['original'] . 'revoked.jpg'), 'No target created without capability'); $cases += 3;
    foreach (array(2 => 'revoked.jpg', 3 => 'uncertain.jpg') as $boundary => $name) {
        $capability->fail_at = $capability->calls + $boundary;
        afs_refused(function () use ($files, $operation, $input, $expected, $name) { $files->publish($operation, 'original', $name, $input, $expected); }, 'Actual publication capability fault boundary');
        $capability->fail_at = null;
        afs_check(is_resource($input) && ftell($input) === 17 && file_get_contents($base . '/source.bin') === $bytes, 'Failure preserves caller state/source');
        if ($boundary === 2) { afs_check(!is_file($base . $scopes['original'] . $name) && $files->remove_stage($operation, 'original', $name) === 'removed', 'Pre-rename failure retains recoverable stage only'); }
        else { afs_check(file_get_contents($base . $scopes['original'] . $name) === $bytes && $files->remove_stage($operation, 'original', $name) === 'missing' && $files->remove_exact('original', $name, $expected) === 'removed', 'Post-rename failure keeps final bytes until explicit recovery'); }
        $cases += 3;
    }
    afs_check(file_put_contents($base . $scopes['small'] . 'owned.jpg', $bytes) === strlen($bytes), 'Own exact cleanup fault target');
    $capability->fail_at = $capability->calls + 3;
    afs_refused(function () use ($files, $expected) { $files->remove_exact('small', 'owned.jpg', $expected); }, 'Revoked capability after snapshot still prevents unlink');
    $capability->fail_at = null; afs_check(file_get_contents($base . $scopes['small'] . 'owned.jpg') === $bytes && $files->remove_exact('small', 'owned.jpg', $expected) === 'removed', 'Late cleanup refusal preserved complete target'); $cases += 2;
    $stage = $base . $scopes['small'] . $files->stage_name($operation, 'small', 'owned.jpg'); afs_check(file_put_contents($stage, 'PARTIAL OWNED STAGE') === 19, 'Owned partial stage fixture');
    $capability->allowed = false; afs_refused(function () use ($files, $operation) { $files->remove_stage($operation, 'small', 'owned.jpg'); }, 'Stage recovery also requires current capability');
    $capability->allowed = true; afs_check(is_file($stage) && $files->remove_stage($operation, 'small', 'owned.jpg') === 'removed', 'Only explicit recovery removes a preserved partial stage'); $cases += 2;
    afs_check(file_put_contents($base . '/write-only.bin', $bytes) === strlen($bytes), 'Own unreadable stream fixture'); $write_only = fopen($base . '/write-only.bin', 'c');
    try { afs_refused(function () use ($files, $operation, $write_only, $expected) { $files->publish($operation, 'original', 'owned.jpg', $write_only, $expected); }, 'Write-only source rejected before publication'); afs_check(is_resource($write_only) && file_get_contents($base . '/write-only.bin') === $bytes, 'Write-only borrowed source not closed or modified'); $cases++; }
    finally { fclose($write_only); }
    afs_check(file_put_contents($base . $scopes['small'] . 'owned.jpg', '') === 0, 'Own empty stale file'); $empty = array('present' => true, 'bytes' => 0, 'sha256' => hash('sha256', ''));
    afs_check($files->snapshot('small', 'owned.jpg') === $empty && $files->remove_exact('small', 'owned.jpg', $empty) === 'removed', 'Empty owned files remain inventoried and recoverable'); $cases++;
    foreach (array(3, 4) as $boundary) {
        $command = (DIRECTORY_SEPARATOR === chr(92) ? '' : 'exec ') . escapeshellarg(PHP_BINARY) . ' ';
        if (DIRECTORY_SEPARATOR === chr(92)) { $command .= '-n -d ' . escapeshellarg('extension_dir=' . ini_get('extension_dir')) . ' -d extension=php_openssl.dll '; if (extension_loaded('mbstring')) { $command .= '-d extension=php_mbstring.dll '; } }
        $command .= escapeshellarg(__FILE__) . ' crash-child ' . escapeshellarg($base) . ' ' . $boundary;
        $process = proc_open($command, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, null, null, array('bypass_shell' => true)); afs_check(is_resource($process), 'Own actual publication crash process'); fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $code = proc_close($process);
        afs_check($code === 77 && $out === '' && $err === '' && file_get_contents($base . '/crash-ready') === (string) $boundary, 'Actual child exited without returning through its publication finally');
        if ($boundary === 3) { afs_check(!is_file($base . $scopes['original'] . 'crash.jpg') && $files->remove_stage($operation, 'original', 'crash.jpg') === 'removed', 'Independent recovery owns the stage left by an exited publisher'); }
        else { afs_check($files->snapshot('original', 'crash.jpg') === $expected && $files->remove_stage($operation, 'original', 'crash.jpg') === 'missing' && $files->remove_exact('original', 'crash.jpg', $expected) === 'removed', 'Exited post-rename process did not roll back final bytes'); }
        unlink($base . '/crash-ready'); $cases += 2;
    }
    afs_check(mkdir($base . $scopes['small'] . 'blocked.jpg'), 'Own non-file destination');
    afs_refused(function () use ($files) { $files->snapshot('small', 'blocked.jpg'); }, 'Non-files never inventoried as files');
    afs_refused(function () use ($files, $operation, $input, $expected) { $files->publish($operation, 'small', 'blocked.jpg', $input, $expected); }, 'Non-file destination not replaced');
    afs_check(is_dir($base . $scopes['small'] . 'blocked.jpg') && rmdir($base . $scopes['small'] . 'blocked.jpg'), 'Non-file retained'); $cases += 3;
    $large = fopen($base . $scopes['small'] . 'oversized.jpg', 'x+b'); afs_check($large && ftruncate($large, AlbumFileStorage::MAX_BYTES + 1), 'Own bounded oversized fixture'); fclose($large);
    afs_refused(function () use ($files) { $files->snapshot('small', 'oversized.jpg'); }, 'Oversized files refused before hashing'); afs_check(unlink($base . $scopes['small'] . 'oversized.jpg'), 'Remove only own oversized test file'); $cases++;
    if (function_exists('symlink') && @symlink(realpath($base . '/foreign.bin'), $base . $scopes['small'] . 'linked.jpg')) {
        afs_refused(function () use ($files) { $files->snapshot('small', 'linked.jpg'); }, 'Linked targets never followed');
        afs_refused(function () use ($files, $expected) { $files->remove_exact('small', 'linked.jpg', $expected); }, 'Linked targets never unlinked as owned images');
        afs_check(is_link($base . $scopes['small'] . 'linked.jpg') && file_get_contents($base . '/foreign.bin') === 'UNCHANGED FOREIGN FIXTURE', 'Link target unchanged'); unlink($base . $scopes['small'] . 'linked.jpg'); $links++; $cases += 3;
    } else { afs_check(DIRECTORY_SEPARATOR === chr(92), 'POSIX target symlink coverage required'); }
    $small = realpath($base . '/album_mod/upload/cache'); $held = realpath($base) . DIRECTORY_SEPARATOR . 'cache-held';
    afs_check(dirname($held) === realpath($base) && !file_exists($held) && rename($small, $held), 'Temporarily move only own configured cache');
    try {
        if (function_exists('symlink') && @symlink(realpath($base . '/album_mod/upload/med_cache'), $small)) {
            afs_refused(function () use ($files) { $files->snapshot('original', 'owned.jpg'); }, 'Any changed ancestor invalidates the whole file capability');
            afs_refused(function () use ($files, $operation, $input, $expected) { $files->publish($operation, 'small', 'owned.jpg', $input, $expected); }, 'Linked configured directory never receives staged bytes'); $links++; $cases += 2;
        } else { afs_check(DIRECTORY_SEPARATOR === chr(92), 'POSIX configured-directory symlink coverage required'); afs_refused(function () use ($files) { $files->snapshot('original', 'owned.jpg'); }, 'Missing configured root also refuses operations'); $cases++; }
    } finally { if (is_link($small)) { unlink($small); } afs_check(!file_exists($small) && rename($held, $small), 'Restore own exact cache directory'); }
    foreach (array('outside', '../small', array(), null) as $bad) { afs_refused(function () use ($files, $bad) { $files->snapshot($bad, 'owned.jpg'); }, 'Only three exact declared scopes'); $cases++; }
    afs_check(file_get_contents($base . '/foreign.bin') === 'UNCHANGED FOREIGN FIXTURE' && file_get_contents($base . '/source.bin') === $bytes && is_resource($input), 'All outside/source bytes and caller handle unchanged'); $cases++;
    $restricted = new AlbumFileStorage(function ($context) use ($operation, $expected) {
        if ($context['action'] === 'roots') { return true; }
        if ($context['scope'] !== 'original' || $context['name'] !== 'owned.jpg') { return false; }
        if ($context['action'] === 'publish') { return $context['operation'] === $operation && $context['metadata'] === $expected; }
        return $context['action'] === 'snapshot' || ($context['action'] === 'remove' && $context['metadata'] === $expected);
    }, $base);
    afs_refused(function () use ($restricted, $operation, $input, $expected) { $restricted->publish($operation, 'original', 'revoked.jpg', $input, $expected); }, 'Guard receives and rejects the exact wrong destination');
    afs_refused(function () use ($restricted, $input, $expected) { $restricted->publish(str_repeat('b', 32), 'original', 'owned.jpg', $input, $expected); }, 'Guard receives and rejects a foreign operation');
    afs_check($restricted->publish($operation, 'original', 'owned.jpg', $input, $expected) && $restricted->remove_exact('original', 'owned.jpg', $expected) === 'removed', 'Guard binds actual action, item, operation and immutable metadata'); $cases += 3;
    echo 'Album file storage primitive: ', $cases, ' actual path/receipt/hash/staging/publication/retry/revocation/fault/source/cleanup cases PASS; symlink checks=', $links, ". Filesystem only, owned temporary synthetic bytes; DB journal/protocol integration NOT claimed.\n";
} finally {
    if (is_resource($input)) { fclose($input); }
    if ($created) {
        afs_check(!is_link($base) && realpath(dirname($base)) === realpath(sys_get_temp_dir()) && preg_match('/^codex_album_file_[a-f0-9]{16}$/D', basename($base)), 'Exact owned fixture cleanup');
        foreach ($scopes as $relative) { foreach (scandir($base . $relative) as $name) {
            if ($name === '.' || $name === '..' || ($relative === '/album_mod/upload/' && in_array($name, array('cache', 'med_cache'), true))) { continue; }
            afs_check(in_array($name, $owned_names, true) || isset($stages[$name]), 'Only enumerated owned synthetic artifacts'); $path = $base . $relative . $name;
            if (is_link($path) || is_file($path)) { unlink($path); } elseif (is_dir($path)) { afs_check($name === 'blocked.jpg' && rmdir($path), 'Only exact owned empty directory'); }
        } }
        foreach (array('/source.bin', '/foreign.bin', '/write-only.bin', '/crash-ready') as $name) { if (is_file($base . $name)) { unlink($base . $name); } }
        foreach (array('/album_mod/upload/cache', '/album_mod/upload/med_cache', '/album_mod/upload', '/album_mod', '') as $directory) { rmdir($base . $directory); }
    }
}
