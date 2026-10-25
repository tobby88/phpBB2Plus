<?php
if (PHP_SAPI !== 'cli') { exit(2); }
if (!extension_loaded('gd')) { if (getenv('PHPBB_ALBUM_CACHE_GD_REQUIRED') === '1') { throw new RuntimeException('Required native GD extension is missing'); } echo "Native GD Album cache fixture skipped.\n"; return; }
define('IN_PHPBB', true); $table_prefix = 'fixture_'; $source = dirname(dirname(__DIR__)) . '/phpBB2/';
foreach (array('includes/php_compat.php', 'album_mod/album_constants.php', 'album_mod/album_functions.php') as $file) { require $source . $file; }
function acs_check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
$common = file_get_contents($source . 'includes/functions.php'); acs_check(preg_match('/^function phpbb_image_dimensions_safe\(.*?^\}/ms', $common, $match) === 1, 'Actual image allocation policy'); eval($match[0]);
if (isset($argv[1]) && $argv[1] === 'worker') {
    acs_check(isset($argv[2], $argv[3]) && preg_match('/^codex_album_cache_store_[a-f0-9]{16}$/D', basename($argv[2])) && !is_link($argv[2]) && realpath(dirname($argv[2])) === realpath(sys_get_temp_dir()) && in_array($argv[3], array('first', 'second'), true) && chdir($argv[2]), 'Exact independent owned cache worker');
    $role = $argv[3]; $image = imagecreatetruecolor(1600, 1600); acs_check($image !== false, 'Actual independent GD image'); mt_srand($role === 'first' ? 123 : 456);
    for ($y = 0; $y < 1600; $y += 2) { for ($x = 0; $x < 1600; $x += 2) { imagefilledrectangle($image, $x, $y, $x + 1, $y + 1, mt_rand(0, 16777215)); } }
    ob_start(); $encoded = imagejpeg($image, null, 95); $bytes = ob_get_clean(); acs_check($encoded && is_string($bytes), 'Complete independently expected GD bytes'); $expected = hash('sha256', $bytes); unset($bytes);
    acs_check(file_put_contents('ready-' . $role, '1') !== false, 'Actual writer ready barrier'); $deadline = microtime(true) + 10;
    while (!is_file('release')) { if (microtime(true) > $deadline) { throw new RuntimeException('Actual cache worker release deadline'); } usleep(1000); clearstatcache(); }
    $ok = album_store_cached_image($image, ALBUM_CACHE_PATH, 'concurrent.jpg', '.jpg', 95); $last = error_get_last(); echo json_encode(array('success' => $ok, 'expected' => $expected, 'last_error' => $last ? $last['message'] : null)), "\n"; exit;
}
$base = sys_get_temp_dir() . '/codex_album_cache_store_' . bin2hex(phpbb_random_bytes(8)); $created = false; $cwd = getcwd(); $cases = 0; $children = array(); $links = 0;
function acs_no_temps() { foreach (scandir(ALBUM_CACHE_PATH) as $name) { acs_check(strpos($name, '.album-cache-') !== 0, 'No abandoned cache staging file'); } foreach (scandir(ALBUM_MED_CACHE_PATH) as $name) { acs_check(strpos($name, '.album-cache-') !== 0, 'No abandoned medium staging file'); } }
function acs_child($role)
{
    $command = escapeshellarg(PHP_BINARY) . ' ';
    if (DIRECTORY_SEPARATOR === chr(92)) { $command .= '-n -d ' . escapeshellarg('extension_dir=' . ini_get('extension_dir')) . ' -d extension=' . (PHP_VERSION_ID < 80000 ? 'php_gd2.dll' : 'php_gd.dll') . ' -d extension=php_openssl.dll '; if (extension_loaded('mbstring')) { $command .= '-d extension=php_mbstring.dll '; } }
    $command .= escapeshellarg(__FILE__) . ' worker ' . escapeshellarg($GLOBALS['base']) . ' ' . escapeshellarg($role);
    $process = proc_open($command, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, null, null, array('bypass_shell' => true)); acs_check(is_resource($process), 'Own actual encoder child'); fclose($pipes[0]); $GLOBALS['children'][] = array($process, $pipes); return count($GLOBALS['children']) - 1;
}
function acs_finish($index, $observed_exit)
{
    $entry = $GLOBALS['children'][$index]; $out = stream_get_contents($entry[1][1]); $err = stream_get_contents($entry[1][2]); fclose($entry[1][1]); fclose($entry[1][2]); $code = proc_close($entry[0]); $GLOBALS['children'][$index] = null;
    $code = $observed_exit >= 0 ? $observed_exit : $code;
    $result = json_decode(trim($out), true);
    acs_check($code === 0 && $err === '' && is_array($result) && is_bool($result['success']) && preg_match('/^[a-f0-9]{64}$/D', $result['expected']), 'Actual native concurrent encoding result: ' . $err . $out);
    if (!$result['success']) {
        // Older Windows PHP readers can deny replacement of their open target.
        // That is an actual safe publication refusal, not a successful overwrite.
        acs_check(DIRECTORY_SEPARATOR === chr(92) && is_string($result['last_error']) && strpos($result['last_error'], 'rename(') === 0 && preg_match('/\(code: (?:5|32)\)$/D', $result['last_error']), 'Only observed native Windows sharing refusal allowed: ' . $out);
    }
    return $result;
}
$reporting = error_reporting(E_ALL); set_error_handler(function ($s, $m) { if (error_reporting() & $s) { throw new RuntimeException($m); } });
try {
    acs_check(!file_exists($base) && mkdir($base, 0700), 'Reserve owned GD fixture'); $created = true;
    acs_check(mkdir($base . '/album_mod') && mkdir($base . '/album_mod/upload') && mkdir($base . '/album_mod/upload/cache') && mkdir($base . '/album_mod/upload/med_cache') && chdir($base) && getcwd() === realpath($base), 'Only owned cache directories');
    $image = imagecreatetruecolor(2, 3); acs_check($image !== false && imagefill($image, 0, 0, 16711680), 'Native synthetic GD content'); $context = array('db' => new stdClass(), 'userdata' => array('user_id' => 7), 'album_config' => array('preserve' => 'settings')); foreach ($context as $key => $value) { $GLOBALS[$key] = $value; }
    foreach (array(ALBUM_CACHE_PATH, ALBUM_MED_CACHE_PATH) as $directory) { foreach (array('.jpg', '.png') as $type) { foreach (array(0, 80, 100) as $quality) {
        acs_check(album_store_cached_image($image, $directory, 'owned' . $type, $type, $quality) && album_cached_image_valid($directory, 'owned' . $type, $type), 'Actual new/replaced complete GD cache');
        $info = getimagesize($directory . 'owned' . $type); acs_check($info[0] === 2 && $info[1] === 3 && $info[2] === ($type === '.jpg' ? IMAGETYPE_JPEG : IMAGETYPE_PNG), 'Exact GD dimensions/type'); acs_no_temps(); $cases++;
    } } }
    $old = file_get_contents(ALBUM_CACHE_PATH . 'owned.jpg'); $png = file_get_contents(ALBUM_CACHE_PATH . 'owned.png');
    foreach (array(array('../owned.jpg', '.jpg'), array('sub/owned.jpg', '.jpg'), array('sub\\owned.jpg', '.jpg'), array('C:owned.jpg', '.jpg'), array("nul\x00.jpg", '.jpg'), array('con.jpg', '.jpg'), array(' owned.jpg', '.jpg'), array('owned.jpg ', '.jpg'), array(str_repeat('a', 252) . '.jpg', '.jpg'), array('owned.php', '.jpg'), array('owned.jpg', '.png'), array('owned.jpg', '.gif'), array(array(), '.jpg')) as $case) {
        acs_check(!album_store_cached_image($image, ALBUM_CACHE_PATH, $case[0], $case[1]) && file_get_contents(ALBUM_CACHE_PATH . 'owned.jpg') === $old, 'Invalid cache path/type retains old target'); acs_no_temps(); $cases++;
    }
    foreach (array(array(), null, 0, new stdClass()) as $bad_image) { acs_check(!album_store_cached_image($bad_image, ALBUM_CACHE_PATH, 'owned.jpg', '.jpg') && file_get_contents(ALBUM_CACHE_PATH . 'owned.jpg') === $old, 'Invalid GD input does not touch cache'); acs_no_temps(); $cases++; }
    foreach (array(-1, 101, '80', array()) as $quality) { acs_check(!album_store_cached_image($image, ALBUM_CACHE_PATH, 'owned.jpg', '.jpg', $quality) && file_get_contents(ALBUM_CACHE_PATH . 'owned.jpg') === $old, 'Typed bounded quality preserves cache'); acs_no_temps(); $cases++; }
    acs_check(!album_store_cached_image($image, $base, 'outside.jpg', '.jpg') && !file_exists($base . '/outside.jpg'), 'Only declared cache roots writable'); $cases++;
    acs_check(mkdir(ALBUM_CACHE_PATH . 'directory.jpg') && !album_store_cached_image($image, ALBUM_CACHE_PATH, 'directory.jpg', '.jpg') && is_dir(ALBUM_CACHE_PATH . 'directory.jpg') && !album_cached_image_valid(ALBUM_CACHE_PATH, 'directory.jpg', '.jpg'), 'Directories neither replaced nor treated as images'); $cases++;
    $wide = imagecreatetruecolor(10001, 1); acs_check($wide !== false && !album_store_cached_image($wide, ALBUM_CACHE_PATH, 'owned.jpg', '.jpg') && file_get_contents(ALBUM_CACHE_PATH . 'owned.jpg') === $old, 'Post-encode dimension refusal preserves previous complete target'); acs_no_temps(); $cases++;
    foreach (array('', substr($old, 0, 200), $old . 'trailing', $png) as $bad) { acs_check(file_put_contents(ALBUM_CACHE_PATH . 'broken.jpg', $bad) !== false && !album_cached_image_valid(ALBUM_CACHE_PATH, 'broken.jpg', '.jpg'), 'Empty/truncated/trailing/wrong-format cache refused'); $cases++; }
    acs_check(file_put_contents(ALBUM_CACHE_PATH . 'broken.png', substr($png, 0, -12)) !== false && !album_cached_image_valid(ALBUM_CACHE_PATH, 'broken.png', '.png'), 'Truncated PNG cache refused'); $cases++;
    if (function_exists('symlink') && @symlink(realpath(ALBUM_CACHE_PATH . 'owned.jpg'), ALBUM_CACHE_PATH . 'linked.jpg')) {
        acs_check(!album_store_cached_image($image, ALBUM_CACHE_PATH, 'linked.jpg', '.jpg') && !album_cached_image_valid(ALBUM_CACHE_PATH, 'linked.jpg', '.jpg') && is_link(ALBUM_CACHE_PATH . 'linked.jpg') && file_get_contents(ALBUM_CACHE_PATH . 'owned.jpg') === $old, 'Symbolic cache target is not read, followed or replaced'); $links++; unlink(ALBUM_CACHE_PATH . 'linked.jpg'); $cases++;
    } else { acs_check(DIRECTORY_SEPARATOR === chr(92), 'POSIX symlink fixture must be supported'); }
    $medium = realpath(ALBUM_MED_CACHE_PATH); $held = realpath($base) . DIRECTORY_SEPARATOR . 'medium-held';
    acs_check(strpos($medium, realpath($base) . DIRECTORY_SEPARATOR) === 0 && dirname($held) === realpath($base) && !file_exists($held) && rename($medium, $held), 'Temporarily move only exact owned medium directory');
    try {
        if (@symlink(realpath(ALBUM_CACHE_PATH), $medium)) {
            acs_check(album_cache_directory(ALBUM_MED_CACHE_PATH) === false && !album_store_cached_image($image, ALBUM_MED_CACHE_PATH, 'owned.jpg', '.jpg') && !album_cached_image_valid(ALBUM_MED_CACHE_PATH, 'owned.jpg', '.jpg') && file_get_contents(ALBUM_CACHE_PATH . 'owned.jpg') === $old, 'Symbolic configured cache directory is never followed'); $links++; $cases++;
        } else { acs_check(DIRECTORY_SEPARATOR === chr(92), 'POSIX directory symlink fixture must be supported'); }
    } finally { if (is_link($medium)) { unlink($medium); } acs_check(!file_exists($medium) && rename($held, $medium), 'Restore exact owned medium directory'); }
    $first = acs_child('first'); $second = acs_child('second'); $deadline = microtime(true) + 15;
    while (!is_file('ready-first') || !is_file('ready-second')) { if (microtime(true) > $deadline) { throw new RuntimeException('Actual GD ready deadline'); } usleep(1000); clearstatcache(); }
    acs_check(file_put_contents('release', '1') !== false, 'Release real concurrent GD producers'); $observed = array(); $a = null; $b = null;
    do {
        clearstatcache(); if (is_file(ALBUM_CACHE_PATH . 'concurrent.jpg')) { $bytes = file_get_contents(ALBUM_CACHE_PATH . 'concurrent.jpg'); acs_check(strlen($bytes) > 16 && substr($bytes, 0, 2) === "\xff\xd8" && substr($bytes, -2) === "\xff\xd9", 'Named cache never exposes a partial actual GD image'); $observed[hash('sha256', $bytes)] = true; }
        if ($a === null || $a['running']) { $a = proc_get_status($children[$first][0]); } if ($b === null || $b['running']) { $b = proc_get_status($children[$second][0]); }
        if (!$a['running'] && !$b['running']) { break; } if (microtime(true) > $deadline) { throw new RuntimeException('Actual GD concurrency deadline'); } usleep(1000);
    } while (true);
    $results = array(acs_finish($first, $a['exitcode']), acs_finish($second, $b['exitcode'])); $expected = array($results[0]['expected'], $results[1]['expected']);
    acs_check($results[0]['success'] || $results[1]['success'], 'At least one complete actual image must be published'); $refused = (int) !$results[0]['success'] + (int) !$results[1]['success'];
    $observed[hash_file('sha256', ALBUM_CACHE_PATH . 'concurrent.jpg')] = true;
    foreach ($observed as $hash => $unused) { acs_check(in_array($hash, $expected, true), 'Every concurrent cache snapshot is exactly one complete producer image'); } acs_no_temps(); $cases++;
    foreach (array('album_thumbnail.php' => ALBUM_CACHE_PATH, 'album_picm.php' => ALBUM_MED_CACHE_PATH) as $controller => $directory) {
        $body = file_get_contents($source . $controller); $start = strpos($body, 'if ($thumbnail_written)'); $end = strpos($body, "\$pic_thumbnail = '';", $start); $end = strpos($body, '}', $end);
        acs_check($start !== false && $end > $start, 'Actual complete renderer publication outcome branch'); $outcome = substr($body, $start, $end + 1 - $start);
        $peer = 'owned.jpg'; $peer_bytes = file_get_contents($directory . $peer); $thumbnail_written = false; $pic_thumbnail = $peer; eval($outcome);
        acs_check($pic_thumbnail === '' && file_get_contents($directory . $peer) === $peer_bytes, 'Actual failed renderer publication never unlinks an existing peer cache'); $cases++;
    }
    foreach ($context as $key => $value) { acs_check($GLOBALS[$key] === $value, 'No caller DB/user/config mutation'); }
    echo 'Native GD Album cache: ', $cases, ' actual encoding/validation/replacement/path/failure/old-target/temp/context/two-process cases PASS; symlink checks=', $links, ', safe Windows sharing refusals=', $refused, "; own synthetic images only, no DB/network.\n";
} finally {
    foreach ($children as $entry) { if ($entry) { proc_terminate($entry[0]); foreach (array(1, 2) as $index) { if (is_resource($entry[1][$index])) { fclose($entry[1][$index]); } } proc_close($entry[0]); } }
    chdir($cwd);
    if ($created) {
        acs_check(!is_link($base) && realpath(dirname($base)) === realpath(sys_get_temp_dir()) && preg_match('/^codex_album_cache_store_[a-f0-9]{16}$/D', basename($base)), 'Exact owned GD cleanup root');
        foreach (array('/album_mod/upload/cache', '/album_mod/upload/med_cache') as $directory) { foreach (scandir($base . $directory) as $name) { if ($name === '.' || $name === '..') { continue; } acs_check(in_array($name, array('owned.jpg', 'owned.png', 'broken.jpg', 'broken.png', 'linked.jpg', 'directory.jpg', 'concurrent.jpg'), true) || preg_match('/^\.album-cache-[a-f0-9]{32}\.tmp$/D', $name), 'Only enumerated synthetic GD files'); $path = $base . $directory . '/' . $name; if (is_link($path) || is_file($path)) { unlink($path); } else { rmdir($path); } } rmdir($base . $directory); }
        foreach (array('/ready-first', '/ready-second', '/release') as $file) { if (is_file($base . $file)) { unlink($base . $file); } } rmdir($base . '/album_mod/upload'); rmdir($base . '/album_mod'); rmdir($base);
    }
    restore_error_handler(); error_reporting($reporting);
}
