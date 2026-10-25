<?php
if (PHP_SAPI !== 'cli') { exit(2); }
define('IN_PHPBB', true); define('GENERAL_ERROR', 202);
$root = dirname(dirname(__DIR__)); $source = $root . '/phpBB2/';
require $source . 'includes/php_compat.php'; require $source . 'album_mod/album_photo_text.php';
function pht_check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
function message_die($type, $message) { throw new RuntimeException($message); }
$upload = file_get_contents($source . 'album_upload.php'); $edit = file_get_contents($source . 'album_edit.php');
function pht_input_body($body, $needle) {
    $start = strpos($body, $needle); $guard = strpos($body, "if (\$pic_title === '')", $start); $end = strpos($body, "\n\t}", $guard);
    pht_check($start !== false && $guard > $start && $end > $guard, 'Actual complete picture text input and title guard'); return substr($body, $start, $end + 3 - $start);
}
$upload_input = pht_input_body($upload, '$pic_title_input ='); $edit_input = pht_input_body($edit, '$pic_title = album_photo_text(');
$upload_start = strpos($upload, '$pic_filename_sql ='); $upload_end = strpos($upload, 'if( !$result =', $upload_start); pht_check($upload_start !== false && $upload_end > $upload_start, 'Actual upload SQL builder'); $upload_builder = substr($upload, $upload_start, $upload_end - $upload_start);
$edit_start = strpos($edit, '// Update the DB'); $edit_start = strpos($edit, '$sql =', $edit_start); $edit_end = strpos($edit, 'if( !$result =', $edit_start); pht_check($edit_start !== false && $edit_end > $edit_start, 'Actual edit SQL builder'); $edit_builder = substr($edit, $edit_start, $edit_end - $edit_start);
$lang = array('Missed_pic_title' => 'empty-title'); $cases = 0;
$vectors = array(
    array('0', 'a' . str_repeat('😀', 16), 32, 'Guest 0', '0', 'a' . str_repeat('😀', 16), 'Guest 0', true),
    array(str_repeat("'", 255), str_repeat("'", 65535), 65535, str_repeat("'", 32), str_repeat("'", 255), str_repeat("'", 65535), str_repeat("'", 32), true),
    array(str_repeat('😀', 255), str_repeat('😀', 65535), 65535, str_repeat('😀', 32), str_repeat('😀', 255), str_repeat('😀', 65535), str_repeat('😀', 32), true),
    array(str_repeat('a', 256), str_repeat('x', 33), 32, str_repeat('n', 33), str_repeat('a', 255), str_repeat('x', 32), str_repeat('n', 32), true),
    array('&amp; <Title>', "Line \\ note '😀'\nnext", 65535, ' Guest &amp; 0 ', '&amp; <Title>', "Line \\ note '😀'\nnext", 'Guest &amp; 0', true),
    array("\xc3", "\xc3", 32, "\xc3", "\xef\xbf\xbd", "\xef\xbf\xbd", "\xef\xbf\xbd", true),
    array('A', 'ignored', 0, '0', 'A', '', '0', true),
    array('', '', 32, 'Guest', '', '', 'Guest', false),
    array(" \t\r\n", '', 32, 'Guest', '', '', 'Guest', false)
);
$reporting = error_reporting(E_ALL); set_error_handler(function ($s, $m) { if (error_reporting() & $s) { throw new RuntimeException($m); } });
try {
    foreach (array($upload_input, $edit_input) as $form_index => $input) { foreach (array(false, true) as $logged_in) { foreach ($vectors as $vector) {
        $userdata = array('session_logged_in' => $logged_in, 'username' => " Registered &' name\\ 😀 "); $album_config = array('desc_length' => $vector[2]);
        $_POST = array('pic_title' => addslashes($vector[0]), 'pic_desc' => addslashes($vector[1]), 'pic_username' => addslashes($vector[3])); $pic_username = 'untouched author'; $ok = true;
        try { eval($input); } catch (RuntimeException $error) { pht_check($error->getMessage() === 'empty-title', 'Only actual expected empty-title refusal'); $ok = false; }
        pht_check($ok === $vector[7], 'Actual upload/edit title-zero and empty boundary'); $cases++;
        if (!$ok) { continue; }
        $expected_title = htmlspecialchars($vector[4], ENT_QUOTES, 'UTF-8'); $expected_desc = htmlspecialchars($vector[5], ENT_QUOTES, 'UTF-8');
        pht_check($pic_title === $expected_title && $pic_desc === $expected_desc, 'Actual complete logical UTF8/title/description/HTML/slash inputs');
        $expected_author = $form_index === 1 ? 'untouched author' : ($logged_in ? $userdata['username'] : htmlspecialchars($vector[6], ENT_QUOTES, 'UTF-8'));
        pht_check($pic_username === $expected_author, 'Guest names are encoded after logical clipping; registered names and edit author are exact');
        if ($form_index === 0 && !$logged_in) { pht_check($pic_username_raw === $vector[6], 'Raw guest validation input retained without double slash stripping'); }
        for ($round = 0; $round < 3; $round++) {
            $_POST = array('pic_title' => addslashes(html_entity_decode($pic_title, ENT_QUOTES, 'UTF-8')), 'pic_desc' => addslashes(html_entity_decode($pic_desc, ENT_QUOTES, 'UTF-8')), 'pic_username' => addslashes($vector[6])); eval($input);
            pht_check($pic_title === $expected_title && $pic_desc === $expected_desc && $pic_username === $expected_author, 'Repeated unchanged input roundtrip has no entity/Unicode/slash loss'); $cases++;
        }
    } } }
    foreach (array(array(), new stdClass(), null) as $invalid) { pht_check(album_photo_text($invalid, 255) === '' && album_photo_description_limit($invalid) === 0, 'Nonscalar inputs fail without warnings'); $cases++; }
    foreach (array(-1, 0, 65536, '255', array()) as $invalid) { pht_check(album_photo_text('Title', $invalid) === '', 'Text API uses bounded typed logical limits'); $cases++; }
    pht_check(album_photo_description_limit('-5') === 0 && album_photo_description_limit('100000') === 65535 && album_photo_description_limit('32') === 32, 'Existing description settings are bounded before server/client use'); $cases++;
    echo 'Album picture text input: ', $cases, " actual upload/edit/logical-UTF8/HTML/zero/guest/registered/roundtrip cases PASS; no files or network.\n";
} finally { restore_error_handler(); error_reporting($reporting); }

if (getenv('PHPBB_ALBUM_PHOTO_INPUT_NATIVE') !== '1') { echo "Native picture text storage requires an explicitly enabled disposable fixture.\n"; return; }
$table_prefix = 'fixture_'; require $source . 'album_mod/album_constants.php'; require $source . 'db/mysqli.php';
$port = getenv('PHPBB_ALBUM_PHOTO_INPUT_PORT') ?: '3306'; $password = getenv('PHPBB_ALBUM_PHOTO_INPUT_PASSWORD') ?: '';
pht_check(preg_match('/^[0-9]{1,5}$/D', $port) && (int) $port > 0 && (int) $port <= 65535, 'Owned loopback port'); $host = '127.0.0.1:' . $port;
$control = new sql_db($host, 'root', $password, '', false); pht_check($control->db_connect_id, 'Own picture input control'); $schema = 'codex_album_photo_input_' . bin2hex(phpbb_random_bytes(8)); $created = false; $db = null; $native_cases = 0;
function pht_sql($sql) { $r = $GLOBALS['control']->sql_query($sql); pht_check($r !== false, 'Owned picture input SQL'); return $r; }
function pht_rows($sql) { $r = pht_sql($sql); $rows = $GLOBALS['control']->sql_fetchrowset($r); $GLOBALS['control']->sql_freeresult($r); return $rows; }
$reporting = error_reporting(E_ALL); set_error_handler(function ($s, $m) { if (error_reporting() & $s) { throw new RuntimeException($m); } });
try {
    pht_sql('CREATE DATABASE ' . $schema . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); $created = true; pht_sql('USE ' . $schema);
    $ddl = file_get_contents($source . 'install/schemas/mysql_schema.sql'); pht_check(preg_match('/CREATE TABLE phpbb_album\s*\([\s\S]*?;/', $ddl, $m) === 1, 'Actual canonical widened picture schema'); pht_sql(str_replace('phpbb_album', 'fixture_album', $m[0]));
    pht_sql("INSERT INTO fixture_album(pic_id,pic_filename,pic_title,pic_desc,pic_user_id,pic_username,pic_time,pic_cat_id) VALUES(1,'baseline.jpg','Before &amp; Grüße 😀',NULL,7,NULL,1,10)"); $baseline = pht_rows('SELECT * FROM fixture_album WHERE pic_id=1');
    $db = new sql_db($host, 'root', $password, $schema, false); pht_check($db->db_connect_id, 'Own actual mysqli writer');
    foreach (array('', 'STRICT_ALL_TABLES,NO_BACKSLASH_ESCAPES', 'STRICT_ALL_TABLES,ANSI_QUOTES,NO_BACKSLASH_ESCAPES') as $mode) {
        pht_check($db->sql_query("SET SESSION sql_mode='" . $mode . "'"), 'Picture text mode matrix');
        foreach (array(false, true) as $logged_in) { foreach ($vectors as $vector) {
            if (!$vector[7]) { continue; } pht_sql('DELETE FROM fixture_album WHERE pic_id<>1');
            $userdata = array('session_logged_in' => $logged_in, 'username' => str_repeat('😀', 255)); $album_config = array('desc_length' => $vector[2]);
            $_POST = array('pic_title' => addslashes($vector[0]), 'pic_desc' => addslashes($vector[1]), 'pic_username' => addslashes($vector[3])); eval($upload_input);
            $expected_title = $pic_title; $expected_desc = $pic_desc; $expected_author = $pic_username;
            $pic_filename = 'synthetic-no-file-' . bin2hex(phpbb_random_bytes(16)) . '.jpg'; $pic_thumbnail = ''; $pic_user_id = $logged_in ? 7 : -1; $pic_user_ip = '7f000001'; $pic_time = 12345; $cat_id = 10; $pic_approval = 1;
            eval($upload_builder); pht_check($db->sql_query($sql), 'Actual controller/driver INSERT stores complete logical text'); $pic_id = (int) $db->sql_nextid();
            $rows = pht_rows('SELECT * FROM fixture_album WHERE pic_id=' . $pic_id); pht_check(count($rows) === 1 && $rows[0]['pic_title'] === $expected_title && $rows[0]['pic_desc'] === $expected_desc && $rows[0]['pic_username'] === $expected_author && (int) $rows[0]['pic_user_id'] === $pic_user_id, 'Canonical schema stores all exact title/description/guest/registered bytes'); $native_cases++;
            for ($round = 0; $round < 3; $round++) {
                $_POST = array('pic_title' => addslashes(html_entity_decode($rows[0]['pic_title'], ENT_QUOTES, 'UTF-8')), 'pic_desc' => addslashes(html_entity_decode($rows[0]['pic_desc'], ENT_QUOTES, 'UTF-8'))); eval($edit_input); eval($edit_builder); pht_check($db->sql_query($sql), 'Actual edit SQL preserves unchanged complete text');
                $rows = pht_rows('SELECT * FROM fixture_album WHERE pic_id=' . $pic_id); pht_check($rows[0]['pic_title'] === $expected_title && $rows[0]['pic_desc'] === $expected_desc && $rows[0]['pic_username'] === $expected_author && pht_rows('SELECT * FROM fixture_album WHERE pic_id=1') === $baseline, 'Repeated actual edit never double-encodes, clips or changes unrelated author/row'); $native_cases++;
            }
        } }
    }
    echo 'Native Album picture text: ', $native_cases, " actual upload/edit/schema/UTF8/HTML/author/SQL-mode/roundtrip cases PASS; owned loopback rows only, no image files.\n";
} finally {
    if ($db) { $db->sql_close(); } if ($created) { pht_check(preg_match('/^codex_album_photo_input_[a-f0-9]{16}$/D', $schema) === 1, 'Exact owned picture fixture cleanup'); $control->sql_query('DROP DATABASE ' . $schema); } $control->sql_close(); restore_error_handler(); error_reporting($reporting);
}
