<?php
if (PHP_SAPI !== 'cli' || getenv('PHPBB_ALBUM_PHOTO_EDIT_NATIVE') !== '1') { echo "Native Album picture edit fixture skipped.\n"; return; }
define('IN_PHPBB', true); define('ADMIN', 1); define('GENERAL_ERROR', 202);
define('USERS_TABLE', 'fixture_users'); define('USER_GROUP_TABLE', 'fixture_members'); $table_prefix = 'fixture_';
$source = dirname(dirname(__DIR__)) . '/phpBB2/';
foreach (array('includes/php_compat.php', 'db/mysqli.php', 'album_mod/album_constants.php', 'album_mod/album_functions.php', 'album_mod/album_hierarchy_auth.php', 'album_mod/album_hierarchy_sql.php', 'album_mod/album_photo_storage.php') as $file) { require $source . $file; }
require __DIR__ . '/album-native-session.php'; require __DIR__ . '/album-native-observer.php';
function ape_check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
function message_die($type, $message) { throw new RuntimeException('refused:' . $message); }
function checkFlag($value, $flag) { return ($value & $flag) === $flag; }
function album_is_debug_enabled() { return false; }
$functions = file_get_contents($source . 'includes/functions.php'); ape_check(preg_match('/^function phpbb_sql_id_list\(.*?^\}/ms', $functions, $m) === 1, 'Actual group parser'); eval($m[0]);
function album_sql_id_list($ids) { return phpbb_sql_id_list($ids); }
class AlbumPhotoEditObservedSource extends sql_db
{
    var $queries = array(); var $fail_at = 0; var $hook = null; var $lost_commit = false; var $worker_role = ''; var $worker_directory = ''; var $worker_gate_done = false;
    function reset_observer($failure = 0) { $this->queries = array(); $this->fail_at = $failure; $this->hook = null; $this->lost_commit = false; }
    function sql_dedicated_connection() { return new AlbumNativeObservedConnection(parent::sql_dedicated_connection(), $this); }
    function observed_query($database, $sql, $transaction = false)
    {
        $this->queries[] = $sql;
        if ($this->fail_at > 0 && count($this->queries) === $this->fail_at) { return false; }
        if (strpos($sql, 'UPDATE fixture_album ') === 0 && is_callable($this->hook)) { $hook = $this->hook; $this->hook = null; call_user_func($hook); }
        $owner_lock = strpos($sql, 'SELECT user_id FROM fixture_users WHERE ') === 0 && strpos($sql, ' FOR UPDATE') !== false;
        if ($owner_lock && $this->worker_role === 'peer' && !$this->worker_gate_done) {
            ape_check(file_put_contents($this->worker_directory . '/peer-attempt', '1') !== false, 'Peer reached actual owner lock'); $this->worker_gate_done = true;
        }
        $result = $database->sql_query($sql, $transaction);
        if ($owner_lock && $result && $this->worker_role === 'holder' && !$this->worker_gate_done) {
            $this->worker_gate_done = true; ape_check(file_put_contents($this->worker_directory . '/holder-locked', '1') !== false, 'Holder acquired actual owner lock'); $deadline = microtime(true) + 8;
            while (!is_file($this->worker_directory . '/peer-attempt')) { if (microtime(true) > $deadline) { throw new RuntimeException('Picture edit peer barrier deadline'); } usleep(10000); clearstatcache(); }
        }
        return $sql === 'COMMIT' && $this->lost_commit ? false : $result;
    }
}
$body = file_get_contents($source . 'album_edit.php');
$start = strpos($body, '$album_user_access = album_permissions('); $end = strpos($body, '/*', $start); ape_check($start !== false && $end > $start, 'Actual initial permission boundary'); $grant = substr($body, $start, $end - $start);
$start = strpos($body, "\tif (\$_SERVER['REQUEST_METHOD']"); $end = strpos($body, '// Complete... now send a message to user', $start); ape_check($start !== false && $end > $start, 'Actual complete POST/token/input/save/failure handler'); $handler = substr($body, $start, $end - $start);
$loader = "require_once dirname(__FILE__) . '/album_mod/album_photo_storage.php';"; ape_check(substr_count($handler, $loader) === 1, 'One actual provider include'); $handler = str_replace($loader, '// Exact provider is already included.', $handler);
$port = getenv('PHPBB_ALBUM_PHOTO_EDIT_PORT') ?: '3306'; $password = getenv('PHPBB_ALBUM_PHOTO_EDIT_PASSWORD') ?: '';
ape_check(preg_match('/^[0-9]{1,5}$/D', $port) && (int) $port > 0 && (int) $port <= 65535, 'Owned loopback port'); $host = '127.0.0.1:' . $port;
if (isset($argv[1]) && $argv[1] === 'worker') {
    ape_check(isset($argv[2], $argv[3], $argv[4]) && preg_match('/^codex_album_photo_edit_[a-f0-9]{16}$/D', $argv[2]) && in_array($argv[3], array('holder', 'peer'), true) && in_array($argv[4], array('normal', 'slashes', 'quotes'), true), 'Only actual owned photo edit worker inputs');
    $db = new AlbumPhotoEditObservedSource($host, 'root', $password, $argv[2], false); ape_check($db->db_connect_id, 'Independent photo edit worker'); $db->worker_role = $argv[3]; $db->worker_directory = sys_get_temp_dir() . '/' . $argv[2] . '-workers'; ape_check(is_dir($db->worker_directory) && !is_link($db->worker_directory), 'Own picture edit barrier directory');
    $userdata = array('user_id' => 7, 'user_level' => 0, 'user_active' => 1, 'session_logged_in' => true, 'session_id' => str_repeat('a', 32)); $album_config = array(); $album_data = array();
    $mode = $argv[4] === 'normal' ? '' : ($argv[4] === 'slashes' ? 'STRICT_ALL_TABLES,NO_BACKSLASH_ESCAPES' : 'STRICT_ALL_TABLES,ANSI_QUOTES,NO_BACKSLASH_ESCAPES'); ape_check($db->sql_query("SET SESSION sql_mode='" . $mode . "'"), 'Worker SQL mode');
    try { $ok = album_picture_text_edit(1, 10, 0, 7, 'Concurrent ' . $argv[3], htmlspecialchars("Mode 'slash' \\ 😀 " . $argv[3], ENT_QUOTES, 'UTF-8')); echo json_encode(array('success' => $ok)), "\n"; } finally { $db->sql_close(); } exit(0);
}
$control = new sql_db($host, 'root', $password, '', false); ape_check($control->db_connect_id, 'Own loopback control');
$schema = 'codex_album_photo_edit_' . bin2hex(phpbb_random_bytes(8)); $created = false; $db = null; $cases = 0; $sid = str_repeat('a', 32); $children = array(); $workers = sys_get_temp_dir() . '/' . $schema . '-workers';
$settings = array('rate' => 1, 'comment' => 1, 'personal_gallery' => ALBUM_USER, 'personal_gallery_view' => ALBUM_GUEST, 'personal_gallery_private' => '10', 'personal_allow_gallery_mod' => 1, 'personal_allow_sub_categories' => 1, 'personal_sub_category_limit' => 20, 'desc_length' => 65535);
function ape_sql($sql) { $r = $GLOBALS['control']->sql_query($sql); if ($r === false) { $error = $GLOBALS['control']->sql_error(); throw new RuntimeException('Owned picture SQL: ' . $error['message']); } return $r; }
function ape_rows($sql) { $r = ape_sql($sql); $rows = $GLOBALS['control']->sql_fetchrowset($r); $GLOBALS['control']->sql_freeresult($r); return $rows; }
function ape_snapshot() { $rows = array(); foreach (array('users', 'sessions', 'members', 'album_cat', 'album', 'album_config') as $table) { $rows[$table] = ape_rows('SELECT * FROM fixture_' . $table . ' ORDER BY 1'); } return $rows; }
function ape_child($role, $mode)
{
    $command = escapeshellarg(PHP_BINARY) . ' ';
    if (DIRECTORY_SEPARATOR === chr(92)) { $command .= '-n -d ' . escapeshellarg('extension_dir=' . ini_get('extension_dir')) . ' -d extension=php_mysqli.dll -d extension=php_openssl.dll '; if (extension_loaded('mbstring')) { $command .= '-d extension=php_mbstring.dll '; } }
    $command .= escapeshellarg(__FILE__) . ' worker ' . escapeshellarg($GLOBALS['schema']) . ' ' . escapeshellarg($role) . ' ' . escapeshellarg($mode);
    $process = proc_open($command, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, null, null, array('bypass_shell' => true)); ape_check(is_resource($process), 'Own photo edit process'); fclose($pipes[0]); $GLOBALS['children'][] = array($process, $pipes); return count($GLOBALS['children']) - 1;
}
function ape_child_finish($index)
{
    $entry = $GLOBALS['children'][$index]; $deadline = microtime(true) + 12;
    do { $status = proc_get_status($entry[0]); if (!$status['running']) { break; } if (microtime(true) > $deadline) { throw new RuntimeException('Own photo edit child deadline'); } usleep(10000); } while (true);
    $out = stream_get_contents($entry[1][1]); $errors = stream_get_contents($entry[1][2]); fclose($entry[1][1]); fclose($entry[1][2]); $closed = proc_close($entry[0]); $GLOBALS['children'][$index] = null;
    $code = $status['exitcode'] >= 0 ? $status['exitcode'] : $closed; $result = json_decode(trim($out), true); ape_check($code === 0 && $errors === '' && is_array($result) && $result['success'] === true, 'Actual concurrent picture edit result: ' . $errors . $out);
}
function ape_seed($owner = 0, $author = 7, $admin = false)
{
    foreach (array('album', 'album_cat', 'members', 'album_config') as $table) { ape_sql('DELETE FROM fixture_' . $table); }
    ape_sql('UPDATE fixture_users SET user_active=1,user_level=0'); if ($admin) { ape_sql('UPDATE fixture_users SET user_level=1 WHERE user_id=7'); }
    album_native_session_seed($GLOBALS['control'], 7, $GLOBALS['sid']);
    ape_sql("INSERT INTO fixture_album_cat(cat_id,cat_title,cat_desc,cat_order,cat_parent,cat_user_id,cat_edit_level,cat_upload_level) VALUES(10,'Synthetic','Keep',0,0," . $owner . ',' . ALBUM_USER . ',' . ALBUM_USER . "),(11,'Other category','Keep',1,0,8," . ALBUM_ADMIN . ',' . ALBUM_ADMIN . ')');
    ape_sql("INSERT INTO fixture_album(pic_id,pic_filename,pic_title,pic_desc,pic_user_id,pic_username,pic_time,pic_cat_id) VALUES(1,'synthetic-never-created.jpg','Before','Keep 😀'," . $author . ",'Author',1,10),(2,'unrelated-never-created.jpg','Unrelated',NULL,8,NULL,2,11)");
    foreach ($GLOBALS['settings'] as $key => $value) { ape_sql("INSERT INTO fixture_album_config VALUES('" . $key . "','" . $value . "')"); }
    $GLOBALS['userdata'] = array('user_id' => 7, 'username' => 'Synthetic', 'user_level' => $admin ? 1 : 0, 'user_active' => 1, 'session_logged_in' => true, 'session_id' => $GLOBALS['sid']);
    $GLOBALS['album_config'] = $GLOBALS['settings']; $GLOBALS['album_data'] = array('preserve' => 'cached tree');
}
function ape_save($owner = 0, $author = 7, $title = 'Changed', $description = 'Grüße 😀') { return album_picture_text_edit(1, 10, $owner, $author, $title, $description); }
function ape_post($owner, $author)
{
    $pic_id = 1; $cat_id = 10; $album_user_id = $owner; $thispic = array('pic_user_id' => $author);
    $lang = array('Not_Authorised' => 'denied', 'Missed_pic_title' => 'empty-title', 'Pic_text_update_failed' => 'save-failed');
    global $userdata, $album_config; eval($GLOBALS['handler']); return true;
}
$reporting = error_reporting(E_ALL); set_error_handler(function ($s, $m) { if (error_reporting() & $s) { throw new RuntimeException($m); } });
try {
    ape_sql('CREATE DATABASE ' . $schema . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); $created = true; ape_sql('USE ' . $schema);
    ape_sql('CREATE TABLE fixture_users(user_id MEDIUMINT PRIMARY KEY,username VARCHAR(255),user_level TINYINT,user_active TINYINT) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'); ape_sql("INSERT INTO fixture_users VALUES(7,'Actor',0,1),(8,'Other owner',0,1)");
    ape_sql('CREATE TABLE fixture_members(user_id MEDIUMINT,group_id MEDIUMINT,user_pending TINYINT) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $ddl = file_get_contents($source . 'install/schemas/mysql_schema.sql');
    foreach (array('album', 'album_cat', 'album_config') as $suffix) { ape_check(preg_match('/CREATE TABLE phpbb_' . $suffix . '\s*\([\s\S]*?;/', $ddl, $m) === 1, 'Canonical Album schema'); ape_sql(str_replace('phpbb_' . $suffix, 'fixture_' . $suffix, $m[0])); }
    album_native_session_schema($control, $source); $db = new AlbumPhotoEditObservedSource($host, 'root', $password, $schema, false); ape_check($db->db_connect_id, 'Own independent source'); $original = $db;
    foreach (array('unchanged-admin', 'revoked-admin', 'inactive-actor', 'logged-out-session', 'expired-session', 'missing-session', 'foreign-session', 'unchanged-author', 'author-transferred', 'category-edit-revoked', 'picture-moved', 'owner-transferred', 'category-deleted', 'picture-deleted') as $case) {
        foreach (array(false, true) as $post) {
            $admin = !in_array($case, array('unchanged-author', 'author-transferred', 'category-edit-revoked', 'picture-moved'), true); $owner = $admin ? 8 : 0; $author = $admin ? 8 : 7; ape_seed($owner, $author, $admin);
            $thispic = ape_rows('SELECT p.*,c.* FROM fixture_album p JOIN fixture_album_cat c ON c.cat_id=p.pic_cat_id WHERE pic_id=1'); $thispic = $thispic[0]; $album_user_id = $owner; $cat_id = 10; $pic_id = 1; $lang = array('Not_Authorised' => 'denied'); eval($grant);
            if ($case === 'revoked-admin') { ape_sql('UPDATE fixture_users SET user_level=0 WHERE user_id=7'); }
            if ($case === 'inactive-actor') { ape_sql('UPDATE fixture_users SET user_active=0 WHERE user_id=7'); }
            if ($case === 'logged-out-session') { ape_sql('UPDATE fixture_sessions SET session_logged_in=0'); }
            if ($case === 'expired-session') { ape_sql('UPDATE fixture_sessions SET session_time=' . (time() - 3601)); }
            if ($case === 'missing-session') { ape_sql('DELETE FROM fixture_sessions'); }
            if ($case === 'foreign-session') { ape_sql('UPDATE fixture_sessions SET session_user_id=8'); }
            if ($case === 'author-transferred') { ape_sql('UPDATE fixture_album SET pic_user_id=8 WHERE pic_id=1'); }
            if ($case === 'category-edit-revoked') { ape_sql('UPDATE fixture_album_cat SET cat_edit_level=' . ALBUM_ADMIN . ' WHERE cat_id=10'); }
            if ($case === 'picture-moved') { ape_sql('UPDATE fixture_album SET pic_cat_id=11,pic_user_id=8 WHERE pic_id=1'); }
            if ($case === 'owner-transferred') { ape_sql('UPDATE fixture_album_cat SET cat_user_id=7 WHERE cat_id=10'); }
            if ($case === 'category-deleted') { ape_sql('DELETE FROM fixture_album_cat WHERE cat_id=10'); }
            if ($case === 'picture-deleted') { ape_sql('DELETE FROM fixture_album WHERE pic_id=1'); }
            $before = ape_snapshot(); $user = $userdata; $config = $album_config; $cache = $album_data; $db->reset_observer();
            $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = array('pic_title' => addslashes("Changed Grüße 😀 & <'title>"), 'pic_desc' => addslashes("Line \\ note 😀"), 'sid' => $sid); $ok = true;
            try { $ok = $post ? ape_post($owner, $author) : ape_save($owner, $author); } catch (RuntimeException $error) { ape_check($post && $error->getMessage() === 'refused:save-failed', 'Only actual expected save denial: ' . $error->getMessage()); $ok = false; }
            $allowed = in_array($case, array('unchanged-admin', 'unchanged-author'), true); ape_check($ok === $allowed, 'Fresh actor/session/target/category authority: ' . $case);
            $after = ape_snapshot(); if ($allowed) {
                $expected = $before; $expected['album'][0]['pic_title'] = $post ? 'Changed Grüße 😀 &amp; &lt;&#039;title&gt;' : 'Changed'; $expected['album'][0]['pic_desc'] = $post ? "Line \\ note 😀" : 'Grüße 😀';
                // Native fetch mode may also supply numeric keys.
                foreach ($expected['album'][0] as $key => $value) { if (is_int($key)) { unset($expected['album'][0][$key]); } }
                foreach ($after['album'][0] as $key => $value) { if (is_int($key)) { unset($after['album'][0][$key]); } }
                ape_check($after === $expected, 'Exactly target title/description changed');
            } else { ape_check($after === $before, 'Denied save leaves all fixture rows exact'); }
            ape_check($db === $original && $userdata === $user && $album_config === $config && $album_data === $cache, 'Caller globals restored on success/refusal'); $cases++;
        }
    }
    foreach (array('private-member', 'private-revoked', 'private-pending', 'moderator-member', 'moderator-revoked', 'owner-moderation', 'owner-moderation-disabled', 'description-shrunk', 'missing-setting') as $case) {
        $personal = strpos($case, 'owner-') === 0; $moderator = strpos($case, 'moderator-') === 0; $owner = $personal ? 7 : 0; $author = ($personal || $moderator) ? 8 : 7; ape_seed($owner, $author);
        if ($personal) { ape_sql('UPDATE fixture_album_cat SET cat_upload_level=' . ALBUM_PRIVATE . ' WHERE cat_id=10'); }
        if (!$personal) {
            ape_sql('UPDATE fixture_album_cat SET cat_edit_level=' . ALBUM_PRIVATE . ",cat_edit_groups='10',cat_moderator_groups='" . ($moderator ? '10' : '') . "' WHERE cat_id=10");
            ape_sql('INSERT INTO fixture_members VALUES(7,10,0)');
            if (strpos($case, 'revoked') !== false) { ape_sql('DELETE FROM fixture_members'); }
            if ($case === 'private-pending') { ape_sql('UPDATE fixture_members SET user_pending=1'); }
        }
        if ($case === 'owner-moderation-disabled') { ape_sql("UPDATE fixture_album_config SET config_value='0' WHERE config_name='personal_allow_gallery_mod'"); }
        if ($case === 'description-shrunk') { ape_sql("UPDATE fixture_album_config SET config_value='1' WHERE config_name='desc_length'"); }
        if ($case === 'missing-setting') { ape_sql("DELETE FROM fixture_album_config WHERE config_name='personal_gallery'"); }
        $before = ape_snapshot(); $ok = ape_save($owner, $author); $allowed = in_array($case, array('private-member', 'moderator-member', 'owner-moderation'), true);
        ape_check($ok === $allowed && ($ok || ape_snapshot() === $before), 'Fresh locked group/config grant: ' . $case); $cases++;
    }
    foreach (array('GET', 'missing-sid', 'wrong-sid', 'array-sid', 'array-title', 'array-description', 'empty-title') as $case) {
        ape_seed(); $before = ape_snapshot(); $db->reset_observer(); $_SERVER['REQUEST_METHOD'] = $case === 'GET' ? 'GET' : 'POST'; $_POST = array('pic_title' => 'Changed', 'pic_desc' => 'Text', 'sid' => $sid);
        if ($case === 'missing-sid') { unset($_POST['sid']); } if ($case === 'wrong-sid') { $_POST['sid'] = str_repeat('b', 32); } if ($case === 'array-sid') { $_POST['sid'] = array($sid); }
        if ($case === 'array-title') { $_POST['pic_title'] = array('Changed'); } if ($case === 'array-description') { $_POST['pic_desc'] = array('Text'); } if ($case === 'empty-title') { $_POST['pic_title'] = ' '; }
        $refused = false; try { ape_post(0, 7); } catch (RuntimeException $error) { ape_check(in_array($error->getMessage(), array('refused:denied', 'refused:empty-title'), true), 'Actual input refusal'); $refused = true; }
        ape_check($refused && $db->queries === array() && ape_snapshot() === $before, 'Actual token/input refusal precedes owned SQL: ' . $case); $cases++;
    }
    $arguments = array(1, 10, 0, 7, 'Title', 'Description');
    foreach (array(array(0, 0), array(0, '1'), array(0, 4294967296), array(1, 0), array(1, 16777216), array(2, -1), array(2, '0'), array(2, 8388608), array(3, '7'), array(3, -8388609), array(4, ''), array(4, ' '), array(4, '<raw>'), array(4, '&'), array(4, str_repeat('x', 256)), array(4, "\xc3"), array(4, array()), array(5, str_repeat('x', 65536)), array(5, "\xc3"), array(5, '<raw>'), array(5, null)) as $vector) {
        ape_seed(); $before = ape_snapshot(); $input = $arguments; $input[$vector[0]] = $vector[1]; $db->reset_observer();
        ape_check(!call_user_func_array('album_picture_text_edit', $input) && $db->queries === array() && ape_snapshot() === $before, 'Typed bounded canonical input refuses before owned SQL'); $cases++;
    }
    foreach (array('cached-logout', 'bad-sid', 'case-changed-sid', 'invalid-lifetime', 'missing-actor') as $case) {
        ape_seed(); if ($case === 'cached-logout') { $userdata['session_logged_in'] = false; } if ($case === 'bad-sid') { $userdata['session_id'] = array($sid); } if ($case === 'case-changed-sid') { $userdata['session_id'] = strtoupper($sid); }
        if ($case === 'invalid-lifetime') { $board_config['session_length'] = '0'; } if ($case === 'missing-actor') { ape_sql('DELETE FROM fixture_users WHERE user_id=7'); }
        $before = ape_snapshot(); ape_check(!ape_save() && ape_snapshot() === $before, 'Exact current login/account prerequisite: ' . $case); $cases++;
        $board_config['session_length'] = 3600; if ($case === 'missing-actor') { ape_sql("INSERT INTO fixture_users VALUES(7,'Actor',0,1)"); }
    }
    ape_seed(); $db->reset_observer(); ape_check(ape_save(), 'Native statement baseline'); $statements = count($db->queries); ape_check($statements > 20 && $statements < 100, 'Bounded owned query inventory'); $cases++;
    for ($failure = 1; $failure <= $statements; $failure++) {
        ape_seed(); $before = ape_snapshot(); $user = $userdata; $config = $album_config; $db->reset_observer($failure);
        ape_check(!ape_save() && ape_snapshot() === $before && $db === $original && $userdata === $user && $album_config === $config, 'Every owned SQL fault rolls back without changing caller: ' . $failure); $cases++;
    }
    ape_seed(); $db->reset_observer(); ape_sql('SET SESSION innodb_lock_wait_timeout=0'); $blocked = 0;
    $db->hook = function () use (&$blocked) {
        foreach (array('UPDATE fixture_users SET user_level=1 WHERE user_id=7', 'UPDATE fixture_sessions SET session_logged_in=0', "UPDATE fixture_album_config SET config_value='0' WHERE config_name='desc_length'", 'UPDATE fixture_album_cat SET cat_edit_level=3 WHERE cat_id=10', "UPDATE fixture_album SET pic_title='Peer' WHERE pic_id=1") as $sql) {
            $r = $GLOBALS['control']->sql_query($sql); $error = $GLOBALS['control']->sql_error(); ape_check($r === false && (int) $error['code'] === 1205, 'Actual independent actor/session/config/category/picture writer blocked'); $blocked++;
        }
    };
    ape_check(ape_save() && $blocked === 5, 'All current grants and target remain locked through commit'); $cases++; $db->reset_observer();
    ape_seed(); ape_sql('UPDATE fixture_album_cat SET cat_edit_level=' . ALBUM_PRIVATE . ",cat_edit_groups='10' WHERE cat_id=10"); ape_sql('INSERT INTO fixture_members VALUES(7,10,0)'); $blocked = 0;
    $db->hook = function () use (&$blocked) { $r = $GLOBALS['control']->sql_query('UPDATE fixture_members SET user_pending=1 WHERE user_id=7 AND group_id=10'); $error = $GLOBALS['control']->sql_error(); ape_check($r === false && (int) $error['code'] === 1205, 'Actual private membership lock retained'); $blocked++; };
    ape_check(ape_save() && $blocked === 1, 'Private membership cannot be revoked between authorization and commit'); $cases++; $db->reset_observer();
    foreach (array('suppress', 'unrelated') as $trigger) {
        ape_seed(); $before = ape_snapshot(); ape_sql('CREATE TRIGGER fixture_edit_trigger BEFORE UPDATE ON fixture_album FOR EACH ROW SET ' . ($trigger === 'suppress' ? 'NEW.pic_title=OLD.pic_title,NEW.pic_desc=OLD.pic_desc' : 'NEW.pic_time=OLD.pic_time+1'));
        ape_check(!ape_save() && ape_snapshot() === $before, 'Suppressed or unrelated trigger mutation refuses and rolls back'); ape_sql('DROP TRIGGER fixture_edit_trigger'); $cases++;
    }
    ape_seed(); $db->reset_observer(); $db->lost_commit = true; ape_check(!ape_save(), 'Uncertain commit is not falsely reported as acknowledged'); $db->reset_observer(); $after = ape_snapshot();
    ape_check($after['album'][0]['pic_title'] === 'Changed' && $after['album'][0]['pic_desc'] === 'Grüße 😀' && ape_save() && ape_snapshot() === $after, 'Lost commit acknowledgement stores one complete pair and safe retry is idempotent'); $cases++;
    ape_seed(); ape_sql('UPDATE fixture_album SET pic_desc=NULL WHERE pic_id=1'); $before = ape_snapshot(); $db->reset_observer(); ape_check(ape_save(0, 7, 'Before', '') && ape_snapshot() === $before, 'Legitimate unchanged nullable description retains NULL');
    foreach ($db->queries as $query) { ape_check(strpos($query, 'UPDATE fixture_album ') !== 0, 'No-op needs no picture UPDATE'); } $cases++;
    ape_seed(); ape_check(ape_save(0, 7, '0', '') && ape_rows('SELECT pic_title FROM fixture_album WHERE pic_id=1')[0]['pic_title'] === '0', 'Title zero remains a valid changed save'); $cases++;
    ape_sql('CREATE TABLE fixture_caller(id INT PRIMARY KEY,body VARCHAR(40)) ENGINE=InnoDB'); ape_sql("INSERT INTO fixture_caller VALUES(1,'original')");
    foreach (array(true, false) as $allowed) {
        ape_seed(); if (!$allowed) { ape_sql('DELETE FROM fixture_sessions'); } $db->reset_observer();
        ape_check($db->sql_query('START TRANSACTION') && $db->sql_query("UPDATE fixture_caller SET body='pending' WHERE id=1"), 'Caller starts unrelated pending work'); ape_check(ape_save() === $allowed, 'Independent save grant');
        $r = $db->sql_query('SELECT body FROM fixture_caller WHERE id=1'); $pending = $db->sql_fetchrow($r); $db->sql_freeresult($r); $outside = ape_rows('SELECT body FROM fixture_caller WHERE id=1');
        ape_check($pending['body'] === 'pending' && $outside[0]['body'] === 'original' && $db->sql_query('ROLLBACK'), 'Save never commits or rolls back caller transaction'); $rows = ape_rows('SELECT pic_title FROM fixture_album WHERE pic_id=1'); ape_check($rows[0]['pic_title'] === ($allowed ? 'Changed' : 'Before'), 'Own save survives caller rollback'); $cases++;
    }
    ape_check(mkdir($workers, 0700), 'Own picture edit process barriers');
    foreach (array('normal', 'slashes', 'quotes') as $mode) {
        ape_seed(); $first = ape_child('holder', $mode); $deadline = microtime(true) + 8;
        while (!is_file($workers . '/holder-locked')) { if (microtime(true) > $deadline) { throw new RuntimeException('Actual picture holder deadline'); } usleep(10000); clearstatcache(); }
        $second = ape_child('peer', $mode); ape_child_finish($first); ape_child_finish($second);
        $rows = ape_rows('SELECT pic_title,pic_desc FROM fixture_album WHERE pic_id=1'); ape_check($rows[0]['pic_title'] === 'Concurrent peer' && $rows[0]['pic_desc'] === "Mode &#039;slash&#039; \\ 😀 peer", 'Two actual concurrent processes retain one complete last edit pair');
        unlink($workers . '/holder-locked'); unlink($workers . '/peer-attempt'); $cases++;
    }
    foreach (array('users', 'sessions', 'album_cat', 'album', 'album_config', 'members') as $suffix) {
        foreach (array('ENGINE=MyISAM', 'ROW_FORMAT=COMPACT', 'CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_bin') as $mutation) {
            ape_seed(); if ($suffix === 'members') { ape_sql("UPDATE fixture_album_cat SET cat_edit_level=" . ALBUM_PRIVATE . ",cat_edit_groups='10' WHERE cat_id=10"); ape_sql('INSERT INTO fixture_members VALUES(7,10,0)'); }
            ape_sql('ALTER TABLE fixture_' . $suffix . ' ' . $mutation); $before = ape_snapshot();
            ape_check(!ape_save() && ape_snapshot() === $before, 'Noncanonical storage refuses without touching data: ' . $suffix . ' ' . $mutation); $cases++;
            ape_sql('ALTER TABLE fixture_' . $suffix . ' ENGINE=InnoDB,ROW_FORMAT=DYNAMIC,CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        }
    }
    echo 'Native Album picture edit: ', $cases, " actual POST/authority/session/target/group/config/locking/SQL-fault/trigger/caller/uncertain-commit/storage cases PASS; owned loopback data, no image files or live requests.\n";
} finally {
    foreach ($children as $entry) { if ($entry) { proc_terminate($entry[0]); foreach (array(1, 2) as $pipe) { if (is_resource($entry[1][$pipe])) { fclose($entry[1][$pipe]); } } proc_close($entry[0]); } }
    if (is_dir($workers)) { foreach (array('holder-locked', 'peer-attempt') as $file) { if (is_file($workers . '/' . $file)) { unlink($workers . '/' . $file); } } rmdir($workers); }
    if ($db) { $db->sql_close(); } if ($created) { ape_check(preg_match('/^codex_album_photo_edit_[a-f0-9]{16}$/D', $schema) === 1, 'Exact owned fixture cleanup'); $control->sql_query('DROP DATABASE ' . $schema); } $control->sql_close(); restore_error_handler(); error_reporting($reporting);
}
