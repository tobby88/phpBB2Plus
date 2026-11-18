<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once __DIR__ . '/php_compat.php';

// Read only JPEG header segments, never entropy-coded image data. No EXIF
// extension is required, and untrusted TIFF offsets stay inside one APP1 block.
function phpbb_jpeg_exif_orientation($data)
{
    if (!is_string($data) || strlen($data) < 14 || substr($data, 0, 6) !== "Exif\0\0") { return 1; }
    $tiff = substr($data, 6); $order = substr($tiff, 0, 2); $length = strlen($tiff);
    if ($order !== 'II' && $order !== 'MM') { return 1; }
    $short = $order === 'II' ? 'vvalue' : 'nvalue'; $long = $order === 'II' ? 'Vvalue' : 'Nvalue';
    $magic = unpack($short, substr($tiff, 2, 2)); $offset = unpack($long, substr($tiff, 4, 4));
    if ($magic['value'] !== 42 || $offset['value'] < 8 || $offset['value'] > $length - 2) { return 1; }
    $at = (int) $offset['value']; $count = unpack($short, substr($tiff, $at, 2)); $at += 2;
    if ($count['value'] > 4096 || $count['value'] > (int) floor(($length - $at) / 12)) { return 1; }
    for ($i = 0; $i < $count['value']; $i++, $at += 12) {
        $tag = unpack($short, substr($tiff, $at, 2));
        if ($tag['value'] !== 0x0112) { continue; }
        $type = unpack($short, substr($tiff, $at + 2, 2)); $items = unpack($long, substr($tiff, $at + 4, 4));
        if ($type['value'] !== 3 || $items['value'] !== 1) { return 1; }
        $value = unpack($short, substr($tiff, $at + 8, 2));
        return $value['value'] >= 1 && $value['value'] <= 8 ? (int) $value['value'] : 1;
    }
    return 1;
}

function phpbb_jpeg_read_info($read, $length)
{
    $info = array('orientation' => 1, 'cache_orientation' => 0); $seen_exif = false;
    if (!is_callable($read) || !is_int($length) || $length < 2 || $length > 67108864 || call_user_func($read, 0, 2) !== "\xff\xd8") { return $info; }
    $at = 2;
    for ($segments = 0; $segments < 4096 && $at + 2 <= $length; $segments++) {
        $marker = call_user_func($read, $at, 2);
        if (!is_string($marker) || strlen($marker) !== 2 || $marker[0] !== "\xff") { break; }
        $kind = ord($marker[1]);
        if ($kind === 0xff) { $at++; continue; }
        if ($kind === 0xda || $kind === 0xd9) { break; }
        if ($kind === 0x01 || ($kind >= 0xd0 && $kind <= 0xd7)) { $at += 2; continue; }
        if ($kind === 0x00 || $kind === 0xd8 || $at + 4 > $length) { break; }
        $size = call_user_func($read, $at + 2, 2);
        if (!is_string($size) || strlen($size) !== 2) { break; }
        $size = unpack('nvalue', $size); $size = (int) $size['value'];
        if ($size < 2 || $at + 2 + $size > $length) { break; }
        if ($kind === 0xe1 || $kind === 0xef) {
            $data = call_user_func($read, $at + 4, $size - 2);
            if (!is_string($data) || strlen($data) !== $size - 2) { break; }
            if ($kind === 0xe1 && !$seen_exif && substr($data, 0, 6) === "Exif\0\0") { $info['orientation'] = phpbb_jpeg_exif_orientation($data); $seen_exif = true; }
            $magic = "phpBB2PlusThumb\0\x01";
            if ($kind === 0xef && strlen($data) === strlen($magic) + 1 && substr($data, 0, strlen($magic)) === $magic) {
                $value = ord(substr($data, -1)); if ($value >= 1 && $value <= 8) { $info['cache_orientation'] = $value; }
            }
        }
        $at += 2 + $size;
    }
    return $info;
}

function phpbb_jpeg_data_info($data)
{
    if (!is_string($data)) { return array('orientation' => 1, 'cache_orientation' => 0); }
    return phpbb_jpeg_read_info(function($at, $size) use ($data) { return substr($data, $at, $size); }, strlen($data));
}

function phpbb_jpeg_file_info($path)
{
    if (!is_string($path) || strpos($path, '://') !== false || is_link($path) || !is_file($path)) { return false; }
    $stream = @fopen($path, 'rb'); if (!$stream) { return false; }
    try {
        $before = fstat($stream);
        if (!$before || ($before['mode'] & 0170000) !== 0100000 || !is_int($before['size']) || $before['size'] > 67108864) { return false; }
        $info = phpbb_jpeg_read_info(function($at, $size) use ($stream) { return @fseek($stream, $at) === 0 ? ($size === 0 ? '' : @fread($stream, $size)) : false; }, $before['size']);
        $after = fstat($stream);
        foreach (array('dev','ino','size','mtime','ctime') as $key) { if (!isset($after[$key]) || $after[$key] !== $before[$key]) { return false; } }
        return $info;
    } finally { fclose($stream); }
}

// Transform the already bounded derivative, rather than allocating a second
// full-resolution photograph. Originals remain byte-for-byte unchanged.
function phpbb_orient_image($image, $orientation)
{
    if (!is_int($orientation) || $orientation < 1 || $orientation > 8 ||
        !((is_resource($image) && get_resource_type($image) === 'gd') || (is_object($image) && is_a($image, 'GdImage')))) { return false; }
    if ($orientation === 1) { return $image; }
    $rotate = in_array($orientation, array(3,5,6,7,8), true);
    if ($rotate) {
        $limit = trim(ini_get('memory_limit'));
        if ($limit !== '-1') {
            if (!preg_match('/^([0-9]+)\s*([KMG]?)$/iD', $limit, $m)) { return false; }
            $power = array(''=>0,'K'=>1,'M'=>2,'G'=>3);
            $cost = imageistruecolor($image) ? 5 : 9;
            if (memory_get_usage(true) + imagesx($image) * imagesy($image) * $cost + 8388608 >= (float) $m[1] * pow(1024, $power[strtoupper($m[2])])) { return false; }
        }
        if (!function_exists('imagerotate')) { return false; }
        // Some system GD builds corrupt palette images during rotation. The
        // legacy resize mode may still produce a palette; normalize it first.
        if (!imageistruecolor($image) && (!function_exists('imagepalettetotruecolor') || !@imagepalettetotruecolor($image))) { return false; }
    }
    if (in_array($orientation, array(2,4,5,7), true) && (!function_exists('imageflip') || !@imageflip($image, $orientation === 4 ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL))) { return false; }
    if (!$rotate) { return $image; }
    $angle = $orientation === 3 ? 180 : (in_array($orientation, array(6,7), true) ? -90 : 90);
    return @imagerotate($image, $angle, 0);
}

function phpbb_jpeg_cache_marker($orientation)
{
    if (!is_int($orientation) || $orientation < 1 || $orientation > 8) { return false; }
    $data = "phpBB2PlusThumb\0\x01" . chr($orientation);
    return "\xff\xef" . pack('n', strlen($data) + 2) . $data;
}

function phpbb_jpeg_mark_cache_data($data, $orientation)
{
    $marker = phpbb_jpeg_cache_marker($orientation);
    if ($marker === false || !is_string($data) || substr($data, 0, 2) !== "\xff\xd8") { return false; }
    return $orientation === 1 ? $data : substr($data, 0, 2) . $marker . substr($data, 2);
}

// Version only an owned, newly encoded cache stage. Copy in bounded chunks,
// then replace that stage; final publication remains the caller's guarded step.
function phpbb_jpeg_mark_cache_file($path, $orientation)
{
    if ($orientation === 1) { return true; }
    $marker = phpbb_jpeg_cache_marker($orientation);
    if ($marker === false || !is_string($path) || !preg_match('/^\.album-cache-[a-f0-9]{32}\.tmp$/D', basename(str_replace('\\', '/', $path))) || is_link($path) || !is_file($path)) { return false; }
    $source = $target = null; $copy = null;
    try {
        $source = @fopen($path, 'rb'); if (!$source) { return false; } $before = fstat($source);
        if (!$before || ($before['mode'] & 0170000) !== 0100000 || $before['size'] < 2 || $before['size'] > 67108864 || fread($source, 2) !== "\xff\xd8") { return false; }
        $copy = $path . '.orientation-' . bin2hex(phpbb_random_bytes(8)); $target = @fopen($copy, 'x+b');
        if (!$target || !@chmod($copy, 0600)) { return false; }
        $head = "\xff\xd8" . $marker;
        if (fwrite($target, $head) !== strlen($head) || stream_copy_to_stream($source, $target, $before['size'] - 2) !== $before['size'] - 2 || !fflush($target)) { return false; }
        $after = fstat($source); $written = fstat($target);
        foreach (array('dev','ino','size','mtime','ctime') as $key) { if (!isset($after[$key]) || $after[$key] !== $before[$key]) { return false; } }
        if ($written['size'] !== $before['size'] + strlen($marker) || is_link($path) || is_dir($path)) { return false; }
        fclose($source); $source = null; fclose($target); $target = null;
        if (!@rename($copy, $path)) { return false; } $copy = null; return true;
    } finally {
        if (is_resource($source)) { fclose($source); } if (is_resource($target)) { fclose($target); }
        if ($copy !== null && (is_file($copy) || is_link($copy))) { @unlink($copy); }
    }
}
