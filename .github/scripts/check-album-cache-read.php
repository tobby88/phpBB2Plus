<?php
if (PHP_SAPI !== 'cli') { exit(2); }
$source = dirname(dirname(__DIR__)) . '/phpBB2/';
define('IN_PHPBB', true); define('ADMIN', 1); define('MOD', 2); define('GENERAL_ERROR', 202); define('GENERAL_MESSAGE', 200); $table_prefix = 'fixture_';
foreach (array('includes/php_compat.php', 'album_mod/album_constants.php', 'album_mod/album_functions.php', 'album_mod/album_hierarchy_auth.php') as $file) { require $source . $file; }
function acr_check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
function message_die($type, $message) { echo 'ERROR:' . $message; exit; }
function checkFlag($value, $flag) { return ($value & $flag) === $flag; }
function album_is_debug_enabled() { return false; }
function album_sql_id_list($value) { throw new RuntimeException('No private group lookup in public cache fixture'); }
$common = file_get_contents($source . 'includes/functions.php'); acr_check(preg_match('/^function phpbb_image_dimensions_safe\(.*?^\}/ms', $common, $match) === 1, 'Actual shared image budget'); eval($match[0]);
class AlbumCacheReadCounter {
    var $queries = 0;
    function sql_query($sql) { acr_check(preg_match("/^UPDATE fixture_album\s+SET pic_view_count = pic_view_count \+ 1\s+WHERE pic_id = '1'$/D", $sql) === 1, 'Only actual independent view counter SQL'); $this->queries++; return true; }
}
if (isset($argv[1]) && $argv[1] === 'child') {
    $case = isset($argv[2]) ? json_decode(base64_decode($argv[2], true), true) : null;
    acr_check(is_array($case) && isset($case['directory'], $case['script']) && in_array($case['script'], array('album_thumbnail.php', 'album_picm.php'), true), 'Exact owned cache child inputs');
    $directory = $case['directory']; acr_check(is_string($directory) && preg_match('/^codex_album_cache_[a-f0-9]{16}$/D', basename($directory)) && !is_link($directory) && realpath(dirname($directory)) === realpath(sys_get_temp_dir()) && chdir($directory), 'Only own cache fixture directory');
    $db = new AlbumCacheReadCounter(); register_shutdown_function(function () use ($directory, $db) { file_put_contents($directory . '/child.json', json_encode(array('queries' => $db->queries))); });
    $album_config = array('rate' => 1, 'comment' => 1, 'thumbnail_cache' => $case['enabled'], 'hotlink_prevent' => 0);
    $album_sp_config = array('midthumb_cache' => $case['enabled']); $album_data = array(); $pic_id = 1;
    $userdata = array('user_id' => $case['guest'] ? -1 : 7, 'user_level' => $case['admin'] ? ADMIN : 0, 'session_logged_in' => !$case['guest']); $lang = array('Pic_not_exist' => 'no-picture', 'Not_Authorised' => 'DENIED');
    $thispic = array('pic_filename' => $case['original'], 'pic_thumbnail' => $case['stored'], 'pic_cat_id' => 10, 'cat_id' => 10, 'cat_user_id' => 0, 'cat_title' => 'Synthetic', 'cat_view_level' => $case['view'], 'cat_moderator_groups' => '', 'cat_approval' => $case['approval'], 'pic_approval' => $case['approved']);
    $images = array('no_thumbnail' => $directory . '/no-thumbnail.jpg');
    $body = file_get_contents($source . $case['script']); $start = strpos($body, '$cat_id = $thispic[\'pic_cat_id\'];'); $end = strpos($body, '// Hmm, cache is empty. Try to re-generate!', $start);
    acr_check($start !== false && $end > $start, 'Actual complete path/permission/approval/counter/cache response boundary'); $handler = substr($body, $start, $end - $start); $braces = 0;
    foreach (token_get_all('<?php ' . $handler) as $token) { if ($token === '{') { $braces++; } if ($token === '}') { $braces--; } }
    acr_check($braces === 1, 'Only the actual outer image-type else remains open'); eval($handler . "\n}\necho 'MISS';"); exit;
}
$cache_bytes = array('CUSTOM' => base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBxdWFsaXR5ID0gODUK/9sAQwAFAwQEBAMFBAQEBQUFBgcMCAcHBwcPCwsJDBEPEhIRDxERExYcFxMUGhURERghGBodHR8fHxMXIiQiHiQcHh8e/9sAQwEFBQUHBgcOCAgOHhQRFB4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4e/8AAEQgAAgACAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A8sooor86P7LP/9k='), 'FALLBACK' => base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBxdWFsaXR5ID0gODUK/9sAQwAFAwQEBAMFBAQEBQUFBgcMCAcHBwcPCwsJDBEPEhIRDxERExYcFxMUGhURERghGBodHR8fHxMXIiQiHiQcHh8e/9sAQwEFBQUHBgcOCAgOHhQRFB4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4e/8AAEQgAAgACAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A+YaKKK/ss+dP/9k='), 'PNG-FALLBACK' => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAFklEQVQImWNkYPjPwMDAxMDAwMDAAAALHwED/oDrHgAAAABJRU5ErkJggg=='));
$vectors = array(
    array('named', 'custom.jpg', array('custom.jpg' => 'CUSTOM'), 'CUSTOM'),
    array('empty-name', '', array('source.jpg' => 'FALLBACK'), 'FALLBACK'),
    array('null-name', null, array('source.jpg' => 'FALLBACK'), 'FALLBACK'),
    array('missing-old-name', 'missing.jpg', array('source.jpg' => 'FALLBACK'), 'FALLBACK'),
    array('prefer-existing-name', 'custom.jpg', array('custom.jpg' => 'CUSTOM', 'source.jpg' => 'FALLBACK'), 'CUSTOM'),
    array('named-is-directory', 'custom.jpg', array('source.jpg' => 'FALLBACK'), 'FALLBACK'),
    array('no-cache-file', '', array(), 'MISS'),
    array('broken-named-fallback', 'custom.jpg', array('custom.jpg' => 'BROKEN', 'source.jpg' => 'FALLBACK'), 'FALLBACK'),
    array('broken-fallback', '', array('source.jpg' => 'BROKEN'), 'MISS'),
    array('empty-fallback', '', array('source.jpg' => ''), 'MISS'),
    array('wrong-format-fallback', '', array('source.jpg' => 'PNG-FALLBACK'), 'MISS'),
    array('missing-no-fallback', 'missing.jpg', array(), 'MISS'),
    array('disabled', '', array('source.jpg' => 'FALLBACK'), 'MISS'),
    array('disabled-named', 'custom.jpg', array('custom.jpg' => 'CUSTOM', 'source.jpg' => 'FALLBACK'), 'MISS'),
    array('guest-public', '', array('source.jpg' => 'FALLBACK'), 'FALLBACK'),
    array('view-denied', '', array('source.jpg' => 'FALLBACK'), 'DENIED'),
    array('approval-denied', '', array('source.jpg' => 'FALLBACK'), 'DENIED'),
    array('admin-approval-override', '', array('source.jpg' => 'FALLBACK'), 'FALLBACK'),
    array('corrupt-name', '../custom.jpg', array('source.jpg' => 'FALLBACK'), 'ERROR:The filename data in the DB was corrupted'),
    array('corrupt-original', '', array('source.jpg' => 'FALLBACK'), 'ERROR:The filename data in the DB was corrupted'),
    array('missing-original', '', array('source.jpg' => 'FALLBACK'), 'ERROR:no-picture'),
    array('png-fallback', '', array('source.png' => 'PNG-FALLBACK'), 'PNG-FALLBACK'),
    array('unsupported-gif', '', array('source.gif' => 'GIF-CACHE'), 'NO-THUMB')
);
$base = sys_get_temp_dir() . '/codex_album_cache_' . bin2hex(phpbb_random_bytes(8)); $created = false; $cases = 0;
try {
    acr_check(!file_exists($base) && mkdir($base, 0700), 'Reserve owned cache fixture'); $created = true;
    acr_check(mkdir($base . '/album_mod') && mkdir($base . '/album_mod/upload') && mkdir($base . '/album_mod/upload/cache') && mkdir($base . '/album_mod/upload/med_cache'), 'Owned original/small/medium directories');
    foreach (array('album_thumbnail.php', 'album_picm.php') as $script) { foreach ($vectors as $vector) {
        foreach (array('source.jpg', 'source.png', 'source.gif', 'custom.jpg', 'missing.jpg') as $file) { foreach (array('/album_mod/upload/', '/album_mod/upload/cache/', '/album_mod/upload/med_cache/') as $directory) { if (is_file($base . $directory . $file)) { unlink($base . $directory . $file); } else if (is_dir($base . $directory . $file)) { rmdir($base . $directory . $file); } } }
        $original = $vector[0] === 'png-fallback' ? 'source.png' : ($vector[0] === 'unsupported-gif' ? 'source.gif' : 'source.jpg');
        if ($vector[0] === 'corrupt-original') { $original = '../source.jpg'; }
        if (!in_array($vector[0], array('missing-original', 'corrupt-original'), true)) { acr_check(file_put_contents($base . '/album_mod/upload/' . $original, 'OWNED SYNTHETIC ORIGINAL') !== false, 'Own original marker'); }
        acr_check(file_put_contents($base . '/no-thumbnail.jpg', 'NO-THUMB') !== false, 'Own no-thumbnail marker');
        $cache = $script === 'album_thumbnail.php' ? 'cache' : 'med_cache'; foreach ($vector[2] as $file => $bytes) { $bytes = isset($cache_bytes[$bytes]) ? $cache_bytes[$bytes] : $bytes; acr_check(file_put_contents($base . '/album_mod/upload/' . $cache . '/' . $file, $bytes) !== false, 'Own synthetic cached bytes'); }
        if ($vector[0] === 'named-is-directory') { acr_check(mkdir($base . '/album_mod/upload/' . $cache . '/custom.jpg'), 'Own non-file thumbnail candidate'); }
        $pending = in_array($vector[0], array('approval-denied', 'admin-approval-override'), true);
        $case = array('directory' => $base, 'script' => $script, 'original' => $original, 'stored' => $vector[1], 'enabled' => strpos($vector[0], 'disabled') === 0 ? 0 : 1, 'view' => $vector[0] === 'view-denied' ? ALBUM_ADMIN : ($vector[0] === 'guest-public' ? ALBUM_GUEST : ALBUM_USER), 'approval' => $pending ? ADMIN : 0, 'approved' => $pending ? 0 : 1, 'guest' => $vector[0] === 'guest-public', 'admin' => $vector[0] === 'admin-approval-override');
        // Windows bundles tokenizer; Linux provides it through the configured
        // extension INI. Keep the same Linux module/temp-dir setup as the parent.
        $command = escapeshellarg(PHP_BINARY) . (DIRECTORY_SEPARATOR === chr(92) ? ' -n' : '') . ' -d date.timezone=UTC ' . escapeshellarg(__FILE__) . ' child ' . escapeshellarg(base64_encode(json_encode($case)));
        $process = proc_open($command, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, null, null, array('bypass_shell' => true)); acr_check(is_resource($process), 'Own cache child'); fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
        $expected = isset($cache_bytes[$vector[3]]) ? $cache_bytes[$vector[3]] : $vector[3];
        $diagnostic = $exit !== 0 && preg_match('//u', $output) === 1 ? substr($output, 0, 1600) : '';
        acr_check($exit === 0 && $errors === '', 'Actual ' . $script . ' child failed: exit=' . $exit . ' ' . $errors . $diagnostic);
        acr_check($output === $expected, 'Actual ' . $script . ' ' . $vector[0] . ' expected ' . $vector[3] . ', got SHA256 ' . hash('sha256', $output));
        $report = json_decode(file_get_contents($base . '/child.json'), true); $early = in_array($vector[0], array('view-denied', 'approval-denied', 'corrupt-name', 'corrupt-original', 'missing-original'), true);
        acr_check(is_array($report) && $report['queries'] === ($script === 'album_picm.php' && !$early ? 1 : 0), 'Actual medium view counter only after path/access/approval guards'); $cases++;
    } }
    echo 'Album cache reads: ', $cases, " complete actual permission/approval/path/cache-response/fallback/disabled/type/counter cases PASS; own synthetic cache images, no database or network.\n";
} finally {
    if ($created) {
        acr_check(!is_link($base) && realpath(dirname($base)) === realpath(sys_get_temp_dir()) && preg_match('/^codex_album_cache_[a-f0-9]{16}$/D', basename($base)), 'Exact owned cache cleanup root');
        foreach (array('source.jpg', 'source.png', 'source.gif', 'custom.jpg', 'missing.jpg') as $file) { foreach (array('/album_mod/upload/', '/album_mod/upload/cache/', '/album_mod/upload/med_cache/') as $directory) { if (is_file($base . $directory . $file)) { unlink($base . $directory . $file); } else if (is_dir($base . $directory . $file)) { rmdir($base . $directory . $file); } } }
        foreach (array('/child.json', '/no-thumbnail.jpg') as $file) { if (is_file($base . $file)) { unlink($base . $file); } }
        foreach (array('/album_mod/upload/cache', '/album_mod/upload/med_cache', '/album_mod/upload', '/album_mod', '') as $directory) { rmdir($base . $directory); }
    }
}
