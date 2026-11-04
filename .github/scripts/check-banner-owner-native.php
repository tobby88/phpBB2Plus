<?php
// Exercise the complete production save branch against an owned MariaDB schema.
if (PHP_SAPI !== 'cli' || getenv('PHPBB_BANNER_OWNER_NATIVE') !== '1') {
    echo "Banner owner checks require an explicitly enabled disposable database.\n"; return;
}
putenv('PHPBB_ATTACH_SETTINGS_NATIVE=0');
require __DIR__ . '/check-attachment-settings-storage.php';
require __DIR__ . '/profile-request-fixture.php';
define('ANONYMOUS', -1); define('POST_FORUM_URL', 'f'); define('BANNERS_TABLE', 'fixture_banner');
ats_load_function($ats_source . 'includes/functions.php', 'get_userdata');
ats_load_function($ats_source . 'admin/admin_banner.php', 'admin_banner_post_scalar');
require $ats_source . 'language/lang_english/lang_banner.php';
require $ats_source . 'db/mysqli.php';
$source = file_get_contents($ats_source . 'admin/admin_banner.php');
$begin = strpos($source, 'phpbb_admin_require_post_session();', strpos($source, 'else if( $mode == "save" )'));
$end = strpos($source, "\n\t}\n\telse if( \$mode == \"delete\" )", $begin);
ats_check($begin !== false && $end > $begin, 'Complete actual banner save branch');
$banner_save_body = substr($source, $begin, $end - $begin);
$schema = file_get_contents($ats_source . 'install/schemas/mysql_schema.sql');
ats_check(preg_match('/CREATE TABLE phpbb_banner \([\s\S]+?;/', $schema, $match) === 1, 'Actual banner installer schema');
$banner_schema = str_replace('phpbb_banner', BANNERS_TABLE, $match[0]);
class BannerOwnerDatabase extends sql_db {
    var $fail_users = false;
    function sql_query($sql = '', $transaction = false) {
        if ($this->fail_users && preg_match('/^SELECT .* FROM fixture_users\b/', $sql)) { return false; }
        return parent::sql_query($sql, $transaction);
    }
}
function bo_sql($sql) {
    $r = $GLOBALS['db']->sql_query($sql); ats_check($r !== false, 'Owned banner fixture SQL'); return $r;
}
function bo_rows($sql) {
    $r = bo_sql($sql); try { return $GLOBALS['db']->sql_fetchrowset($r); } finally { $GLOBALS['db']->sql_freeresult($r); }
}
function bo_snapshot() { return serialize(bo_rows('SELECT * FROM fixture_banner ORDER BY banner_id')); }
function bo_save($raw, $id = 0, $sid = 'banner-fixture', $fields = array()) {
    global $db, $lang, $userdata, $phpEx, $banner_save_body;
    $userdata = array('session_id'=>'banner-fixture'); $_SERVER['REQUEST_METHOD'] = 'POST';
    profile_fixture_request(array_merge(array('sid'=>$sid, 'username'=>$raw, 'id'=>(string)$id, 'banner_name'=>'Synthetic banner', 'banner_type'=>'2'), $fields));
    try { eval($banner_save_body); } catch (AttachSettingsExit $e) { return $e->getMessage(); }
    throw new RuntimeException('Actual save branch must finish explicitly');
}
$port = getenv('PHPBB_BANNER_OWNER_PORT') ?: '3306';
ats_check(preg_match('/^[0-9]{1,5}$/D', $port) && (int)$port > 0 && (int)$port <= 65535, 'Loopback port');
$host = '127.0.0.1:' . $port; $password = getenv('PHPBB_BANNER_OWNER_PASSWORD') ?: '';
$fixture = 'codex_banner_owner_' . bin2hex(phpbb_random_bytes(8));
$control = new sql_db($host, 'root', $password, '', false);
ats_check($control->sql_query('CREATE DATABASE ' . $fixture . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'), 'Owned schema');
$db = null; $cases = 0;
try {
    $db = new BannerOwnerDatabase($host, 'root', $password, $fixture, false);
    set_error_handler(function($severity, $message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } });
    bo_sql('CREATE TABLE fixture_users(user_id INT PRIMARY KEY, username VARCHAR(25) NOT NULL) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    bo_sql($banner_schema);
    foreach (array('', 'NO_BACKSLASH_ESCAPES', 'STRICT_TRANS_TABLES', 'STRICT_TRANS_TABLES,NO_BACKSLASH_ESCAPES') as $mode) {
        bo_sql("SET SESSION sql_mode='" . $mode . "'");
        foreach (array('123', '0', '000123', '1e2', '+123', '-1', '.5', '1.2', 'Plain', 'Müller', 'A&B', "O'Reilly", 'C:\\notes', "A\\'B", 'Trailing\\', '&#39;', '😀', ' 123 ') as $raw) {
            foreach (array(ENT_QUOTES, ENT_COMPAT) as $flags) {
                bo_sql('DELETE FROM fixture_users'); bo_sql('DELETE FROM fixture_banner');
                $key = phpbb_username_key($raw, $flags); ats_check($key !== '', 'Valid canonical fixture identity');
                bo_sql("INSERT INTO fixture_users VALUES(7,'" . $db->sql_escape($key) . "'),(123,'Other numeric ID'),(100,'Other exponent ID'),(1,'Other decimal ID'),(-1,'Anonymous')");
                $users = serialize(bo_rows('SELECT * FROM fixture_users ORDER BY user_id'));
                foreach (array(0, 1) as $id) {
                    $message = bo_save($raw, $id);
                    $rows = bo_rows('SELECT banner_id,banner_owner FROM fixture_banner');
                    ats_check(strpos($message, $lang[$id ? 'Banner_updated' : 'Banner_added']) === 0, 'Actual save acknowledges success: ' . $raw);
                    ats_check(count($rows) === 1 && (int)$rows[0]['banner_id'] === 1 && (int)$rows[0]['banner_owner'] === 7,
                        'Actual banner INSERT/UPDATE must select the named account, never a numeric user ID: ' . $raw);
                    ats_check(serialize(bo_rows('SELECT * FROM fixture_users ORDER BY user_id')) === $users, 'Owner lookup never changes accounts');
                    $cases++;
                }
                // Loading an existing banner still resolves its stored numeric owner ID.
                ats_check((int)get_userdata(7)['user_id'] === 7 && get_userdata(7)['username'] === $key, 'Existing numeric ID reader unchanged');
                $before = bo_snapshot();
                foreach (array('Missing', str_repeat('x', 26), '', array('123'), '123 AND 1=1') as $invalid) {
                    ats_check(bo_save($invalid, 1) === $lang['Missing_banner_owner'] && bo_snapshot() === $before, 'Missing/malformed owner cannot rewrite banner'); $cases++;
                }
                ats_check(bo_save($raw, 1, 'wrong-session') === 'Invalid administration request.' && bo_snapshot() === $before, 'Actual session guard remains before owner lookup/write'); $cases++;
                $db->fail_users = true;
                try { $message = bo_save($raw, 1); } finally { $db->fail_users = false; }
                ats_check($message === 'Failed obtaining user data' && bo_snapshot() === $before, 'Failed owner read cannot rewrite banner'); $cases++;
            }
        }
        bo_sql('DELETE FROM fixture_users');
        foreach (array(7=>ENT_QUOTES, 8=>ENT_COMPAT) as $id=>$flags) {
            bo_sql("INSERT INTO fixture_users VALUES(" . $id . ",'" . $db->sql_escape(phpbb_username_key("O'Reilly", $flags)) . "')");
        }
        $before = bo_snapshot();
        ats_check(bo_save("O'Reilly", 1) === $lang['Missing_banner_owner'] && bo_snapshot() === $before, 'Ambiguous historical owner cannot select either account'); $cases++;
        bo_sql('DELETE FROM fixture_users'); bo_sql("INSERT INTO fixture_users VALUES(7,'Owner')");
        foreach (array("O'Reilly", 'A"B', 'C:\\notes', "A\\'B", 'Trailing\\', '&amp;', '&#39;', '<literal>', 'Grüße 😀', '0', '  Trimmed  ') as $text) {
            bo_sql('DELETE FROM fixture_banner');
            $fields = array('banner_name'=>$text, 'banner_description'=>$text, 'banner_comment'=>$text,
                'banner_url'=>"https://example.invalid/?a='" . rawurlencode($text) . "'&b=2");
            foreach (array(0, 1, 1) as $id) {
                bo_save('Owner', $id, 'banner-fixture', array_merge($fields, array('f'=>'9')));
                $row = bo_rows('SELECT banner_name,banner_description,banner_comment,banner_url,banner_owner,banner_forum FROM fixture_banner');
                ats_check(count($row) === 1 && (int)$row[0]['banner_owner'] === 7, 'Metadata save retains numeric owner');
                ats_check((int)$row[0]['banner_forum'] === 9, 'Actual INSERT and repeated UPDATE preserve the selected forum');
                foreach ($fields as $field=>$value) {
                    ats_check($row[0][$field] === trim($value), 'Actual banner text round-trip is byte exact: ' . $field);
                    $fields[$field] = $row[0][$field];
                }
                $cases++;
            }
        }
    }
    echo 'Native banner owners: ' . $cases . " complete save/identity/session/failure cases PASS.\n";
} finally {
    if ($db !== null) { $db->sql_close(); }
    $control->sql_query('DROP DATABASE ' . $fixture); $control->sql_close(); restore_error_handler();
}
