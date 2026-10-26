<?php
if (PHP_SAPI !== 'cli') { exit(2); }
$source = dirname(dirname(__DIR__)) . '/phpBB2/';
define('IN_PHPBB', true); define('ADMIN', 1); define('MOD', 2); define('GENERAL_ERROR', 202); define('GENERAL_MESSAGE', 200); $table_prefix = 'fixture_';
foreach (array('includes/php_compat.php', 'album_mod/album_constants.php', 'album_mod/album_functions.php', 'album_mod/album_hierarchy_auth.php') as $relative) { require $source . $relative; }
function agr_check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
function message_die($type, $message) { echo 'ERROR:' . $message; exit; }
function checkFlag($value, $flag) { return ($value & $flag) === $flag; }
function album_is_debug_enabled() { return false; }
function album_sql_id_list($value) { throw new RuntimeException('No group lookup in public rendering fixture'); }
$common = file_get_contents($source . 'includes/functions.php'); agr_check(preg_match('/^function phpbb_image_dimensions_safe\(.*?^\}/ms', $common, $match) === 1, 'Actual image allocation policy'); eval($match[0]);
class AlbumRegenerationCounter {
    var $queries = 0;
    function sql_query($sql) { agr_check(preg_match("/^UPDATE fixture_album\s+SET pic_view_count = pic_view_count \+ 1\s+WHERE pic_id = '1'$/D", $sql) === 1, 'Only actual view-counter SQL'); $this->queries++; return true; }
}
if (isset($argv[1]) && $argv[1] === 'child') {
    $case = json_decode(base64_decode($argv[2], true), true); agr_check(is_array($case) && isset($case['directory'], $case['script']), 'Typed owned child'); $directory = $case['directory'];
    agr_check(is_string($directory) && preg_match('/^codex_album_regen_[a-f0-9]{16}$/D', basename($directory)) && !is_link($directory) && realpath(dirname($directory)) === realpath(sys_get_temp_dir()) && chdir($directory), 'Exact owned regeneration root');
    agr_check(in_array($case['script'], array('album_picm.php', 'album_thumbnail.php'), true), 'Only actual renderers');
    $db = new AlbumRegenerationCounter(); register_shutdown_function(function () use ($directory, $db) { file_put_contents($directory . '/child.json', json_encode(array('queries' => $db->queries))); });
    $album_config = array('rate' => 1, 'comment' => 1, 'thumbnail_cache' => $case['cache'], 'thumbnail_size' => $case['width'], 'thumbnail_quality' => 80, 'gd_version' => $case['gd1'] ? 1 : 2, 'hotlink_prevent' => 0);
    $album_sp_config = array('midthumb_cache' => $case['cache'], 'midthumb_width' => $case['width'], 'midthumb_height' => $case['height']); $album_data = array(); $pic_id = 1;
    $userdata = array('user_id' => 7, 'user_level' => 0, 'session_logged_in' => true); $lang = array('Pic_not_exist' => 'NO-PICTURE', 'Not_Authorised' => 'DENIED');
    $thispic = array('pic_filename' => 'source.' . $case['type'], 'pic_thumbnail' => '', 'pic_cat_id' => 10, 'cat_id' => 10, 'cat_user_id' => 0, 'cat_title' => 'Synthetic', 'cat_view_level' => ALBUM_USER, 'cat_moderator_groups' => '', 'cat_approval' => $case['denied'] ? ADMIN : 0, 'pic_approval' => $case['denied'] ? 0 : 1);
    $images = array('no_thumbnail' => $directory . '/fallback.jpg');
    $body = file_get_contents($source . $case['script']); $start = strpos($body, '$cat_id = $thispic[\'pic_cat_id\'];'); $end = strpos($body, '// +---', $start);
    agr_check($start !== false && $end > $start && substr_count(substr($body, $start, $end - $start), 'exit;') >= 5, 'Actual entire permission/cache/decoder/resize/output/cleanup handler');
    eval(substr($body, $start, $end - $start)); throw new RuntimeException('Actual image handler must exit');
}
$cases = 0;
foreach (array(
    array(500, 300, 400, 200, array(333, 200)), array(300, 500, 400, 200, array(120, 200)),
    array(500, 500, 400, 200, array(200, 200)), array(10, 20, 400, 200, array(10, 20)),
    array(500, 1, 100, 100, array(100, 1)), array(1, 500, 100, 100, array(1, 100)),
    array('500', '300', '400', '200', array(333, 200))
) as $vector) { agr_check(album_thumbnail_dimensions($vector[0], $vector[1], $vector[2], $vector[3]) === $vector[4], 'Both edges/aspect/no enlargement/numeric database settings'); $cases++; }
foreach (array(0, -1, 10001, '1000000000000000000', '1e3', '1.5', 1.5, true, null, array(), new stdClass()) as $bad) {
    foreach (array(0, 1, 2, 3) as $index) { $values = array(500, 300, 400, 200); $values[$index] = $bad; agr_check(call_user_func_array('album_thumbnail_dimensions', $values) === false, 'Typed invalid input/configuration refused before allocation'); $cases++; }
}
agr_check(album_thumbnail_dimensions(5000, 5000, 100, 100) === false, 'Existing decoded-pixel budget retained'); $cases++;
if (!extension_loaded('gd')) {
    if (getenv('PHPBB_ALBUM_CACHE_GD_REQUIRED') === '1') { throw new RuntimeException('Required real GD regeneration test missing'); }
    echo 'Album regeneration dimensions: ', $cases, " cases PASS; native GD rendering fixture skipped.\n"; return;
}
foreach (array('album_thumbnail.php', 'album_picm.php') as $script) {
    $body = file_get_contents($source . $script); $start = strpos($body, 'if (isset($thumbnail)'); $end = strpos($body, "header('Content-type:", $start);
    agr_check($start !== false && $end > $start, 'Actual complete failed-GD cleanup boundary');
    $cleanup = substr($body, $start, $end - $start);
    foreach (array('failed-allocation', 'failed-copy', 'shared-source', 'failed-decoder') as $fault) {
        $src = $fault === 'failed-decoder' ? false : imagecreatetruecolor(2, 2);
        $thumbnail = $fault === 'failed-copy' ? imagecreatetruecolor(1, 1) : ($fault === 'shared-source' ? $src : false);
        // Exercise the actual failure cleanup with native GD values, including
        // false returned by an allocator/decoder; never pass false to destroy.
        eval($cleanup);
        if (PHP_VERSION_ID < 80000) { agr_check(!is_resource($src) && !is_resource($thumbnail), 'Every successfully allocated GD resource is released on failure'); }
        $cases++;
    }
}
$base = sys_get_temp_dir() . '/codex_album_regen_' . bin2hex(phpbb_random_bytes(8)); $created = false;
$vectors = array(
    array('landscape', 500, 300, 400, 200), array('portrait', 300, 500, 400, 200), array('square', 500, 500, 400, 200),
    array('small', 10, 20, 100, 100), array('wide', 500, 1, 100, 100), array('tall', 1, 500, 100, 100),
    array('width-limit', 500, 100, 100, 200), array('height-limit', 100, 500, 400, 50),
    array('gd1', 500, 300, 100, 100), array('no-cache', 500, 300, 100, 100),
    array('no-decoder', 500, 300, 100, 100), array('no-encoder', 500, 300, 100, 100),
    array('no-allocator', 500, 300, 100, 100), array('no-copier', 500, 300, 100, 100),
    array('gd1-fallback', 500, 300, 100, 100), array('cache-no-gd', 500, 300, 100, 100),
    array('oversized-cache', 500, 300, 400, 200), array('oversized-cache-no-gd', 500, 300, 400, 200),
    array('small-no-allocator', 10, 20, 100, 100), array('small-no-copier', 10, 20, 100, 100),
    array('denied-no-gd', 500, 300, 100, 100), array('zero-config', 500, 300, 0, 100),
    array('huge-config', 500, 300, 10001, 100), array('array-config', 500, 300, array(), 100), array('null-config', 500, 300, null, 100)
);
try {
    agr_check(!file_exists($base) && mkdir($base, 0700), 'Reserve own regeneration fixture'); $created = true;
    foreach (array('/album_mod', '/album_mod/upload', '/album_mod/upload/cache', '/album_mod/upload/med_cache') as $directory) { agr_check(mkdir($base . $directory), 'Own fixture directories'); }
    $fallback = imagecreatetruecolor(2, 2); agr_check($fallback !== false && imagefill($fallback, 0, 0, 65280) && imagejpeg($fallback, $base . '/fallback.jpg'), 'Own fallback image');
    $fallback_bytes = file_get_contents($base . '/fallback.jpg');
    foreach (array('album_thumbnail.php', 'album_picm.php') as $script) { foreach (array('jpg', 'png') as $type) { foreach ($vectors as $vector) {
        foreach (array('/album_mod/upload/', '/album_mod/upload/cache/', '/album_mod/upload/med_cache/') as $directory) { foreach (array('jpg', 'png') as $old_type) { $path = $base . $directory . 'source.' . $old_type; if (is_file($path)) { unlink($path); } } }
        $image = imagecreatetruecolor($vector[1], $vector[2]); agr_check($image !== false && imagefill($image, 0, 0, 16711680), 'Real original pixels');
        $encoder = $type === 'jpg' ? 'imagejpeg' : 'imagepng'; agr_check($encoder($image, $base . '/album_mod/upload/source.' . $type), 'Real original encoded bytes'); if (PHP_VERSION_ID < 80000) { imagedestroy($image); }
        $disabled = array();
        switch ($vector[0]) {
            case 'no-decoder': $disabled = array($type === 'jpg' ? 'imagecreatefromjpeg' : 'imagecreatefrompng'); break;
            case 'no-encoder': $disabled = array($encoder); break;
            case 'no-allocator': case 'small-no-allocator': $disabled = array('imagecreate', 'imagecreatetruecolor'); break;
            case 'no-copier': case 'small-no-copier': $disabled = array('imagecopyresized', 'imagecopyresampled'); break;
            case 'gd1-fallback': $disabled = array('imagecreatetruecolor'); break;
            case 'cache-no-gd': case 'denied-no-gd': case 'oversized-cache-no-gd': $disabled = array('imagecreatefromjpeg', 'imagecreatefrompng', 'imagejpeg', 'imagepng', 'imagecreate', 'imagecreatetruecolor', 'imagecopyresized', 'imagecopyresampled'); break;
        }
        if ($vector[0] === 'cache-no-gd') {
            $cache = $script === 'album_picm.php' ? 'med_cache' : 'cache'; $path = $base . '/album_mod/upload/' . $cache . '/source.' . $type;
            agr_check($type === 'png' ? imagepng($fallback, $path) : copy($base . '/fallback.jpg', $path), 'Own pre-existing complete matching cache');
        }
        if (strpos($vector[0], 'oversized-cache') === 0) {
            $cache = $script === 'album_picm.php' ? 'med_cache' : 'cache'; $path = $base . '/album_mod/upload/' . $cache . '/source.' . $type;
            agr_check(copy($base . '/album_mod/upload/source.' . $type, $path), 'Owned oversized old cache'); $previous_cache = file_get_contents($path);
        }
        $case = array('directory' => $base, 'script' => $script, 'type' => $type, 'width' => $vector[3], 'height' => $vector[4], 'cache' => $vector[0] === 'no-cache' ? 0 : 1, 'gd1' => $vector[0] === 'gd1', 'denied' => $vector[0] === 'denied-no-gd');
        $command = escapeshellarg(PHP_BINARY) . ' ';
        if (DIRECTORY_SEPARATOR === chr(92)) { $command .= '-n -d ' . escapeshellarg('extension_dir=' . ini_get('extension_dir')) . ' -d extension=' . (PHP_VERSION_ID < 80000 ? 'php_gd2.dll' : 'php_gd.dll') . ' -d extension=php_openssl.dll '; if (extension_loaded('mbstring')) { $command .= '-d extension=php_mbstring.dll '; } }
        $command .= '-d display_errors=stderr -d error_reporting=-1 ';
        if ($disabled) { $command .= '-d ' . escapeshellarg('disable_functions=' . implode(',', $disabled)) . ' '; }
        $command .= escapeshellarg(__FILE__) . ' child ' . escapeshellarg(base64_encode(json_encode($case)));
        $process = proc_open($command, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, null, null, array('bypass_shell' => true)); agr_check(is_resource($process), 'Own actual GD handler child'); fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
        agr_check($exit === 0 && $errors === '', 'Actual ' . $script . ' ' . $type . '/' . $vector[0] . ' exit=' . $exit . ' stderr=' . $errors);
        $refused = in_array($vector[0], array('no-decoder', 'no-encoder', 'no-allocator', 'no-copier', 'zero-config', 'huge-config', 'array-config', 'null-config', 'oversized-cache-no-gd'), true);
        if ($case['denied']) { agr_check($output === 'DENIED', 'Approval guard precedes GD and caching'); }
        elseif ($refused) { agr_check($output === $fallback_bytes, 'Unavailable GD/configuration uses the configured fallback'); }
        else {
            // Golden dimensions are independent of the helper under test.
            $golden = array('landscape' => array(array(400, 240), array(333, 200)), 'portrait' => array(array(240, 400), array(120, 200)), 'square' => array(array(400, 400), array(200, 200)), 'small' => array(array(10, 20), array(10, 20)), 'wide' => array(array(100, 1), array(100, 1)), 'tall' => array(array(1, 100), array(1, 100)), 'width-limit' => array(array(100, 20), array(100, 20)), 'height-limit' => array(array(80, 400), array(10, 50)), 'small-no-allocator' => array(array(10, 20), array(10, 20)), 'small-no-copier' => array(array(10, 20), array(10, 20)), 'cache-no-gd' => array(array(2, 2), array(2, 2)));
            $key = $vector[0] === 'oversized-cache' ? 'landscape' : $vector[0];
            $dimensions = isset($golden[$key]) ? $golden[$key][$script === 'album_picm.php' ? 1 : 0] : array(100, 60);
            $size = @getimagesizefromstring($output);
            agr_check($size && $size[0] === $dimensions[0] && $size[1] === $dimensions[1] && $size[2] === ($type === 'jpg' ? IMAGETYPE_JPEG : IMAGETYPE_PNG), 'Actual complete image has the bounded dimensions and correct type');
        }
        $report = json_decode(file_get_contents($base . '/child.json'), true); agr_check($report['queries'] === ($script === 'album_picm.php' && !$case['denied'] ? 1 : 0), 'Existing view-counter/approval semantics retained');
        $cache = $base . '/album_mod/upload/' . ($script === 'album_picm.php' ? 'med_cache' : 'cache') . '/source.' . $type;
        if ($vector[0] === 'oversized-cache-no-gd') { agr_check(file_get_contents($cache) === $previous_cache, 'Refused regeneration preserves old cache bytes without serving oversized content'); }
        elseif ($refused || $case['denied'] || !$case['cache']) { agr_check(!is_file($cache), 'Refused/disabled render never creates cache'); }
        else { agr_check(is_file($cache) && file_get_contents($cache) === $output, 'Published cache matches the complete returned image'); }
        $cases++;
    } } }
    echo 'Album regeneration: ', $cases, " actual whole-handler/native GD/JPEG/PNG/two-edge/no-upscale/config/disabled-function/cache/approval/counter cases PASS; own synthetic images only, no database or network.\n";
} finally {
    if (isset($fallback) && is_resource($fallback)) { imagedestroy($fallback); }
    if ($created) {
        agr_check(!is_link($base) && realpath(dirname($base)) === realpath(sys_get_temp_dir()) && preg_match('/^codex_album_regen_[a-f0-9]{16}$/D', basename($base)), 'Exact owned regeneration cleanup');
        foreach (array('/album_mod/upload/', '/album_mod/upload/cache/', '/album_mod/upload/med_cache/') as $directory) {
            foreach (scandir($base . $directory) as $name) { if ($name === '.' || $name === '..' || ($directory === '/album_mod/upload/' && in_array($name, array('cache', 'med_cache'), true))) { continue; } agr_check(in_array($name, array('source.jpg', 'source.png'), true) || preg_match('/^\.album-cache-[a-f0-9]{32}\.tmp$/D', $name), 'Only enumerated own image files'); agr_check(!is_link($base . $directory . $name) && is_file($base . $directory . $name), 'Only regular own images'); unlink($base . $directory . $name); }
        }
        foreach (array('/fallback.jpg', '/child.json') as $file) { if (is_file($base . $file)) { unlink($base . $file); } }
        foreach (array('/album_mod/upload/cache', '/album_mod/upload/med_cache', '/album_mod/upload', '/album_mod', '') as $directory) { rmdir($base . $directory); }
    }
}
