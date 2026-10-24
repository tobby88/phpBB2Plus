<?php
if (PHP_SAPI !== 'cli' || getenv('PHPBB_ALBUM_CATEGORY_EDIT_NATIVE') !== '1') { echo "Native Album category edit fixture skipped.\n"; return; }
define('IN_PHPBB', true); define('ADMIN', 1); define('GENERAL_ERROR', 202); define('GENERAL_MESSAGE', 200);
define('USERS_TABLE', 'fixture_users'); define('USER_GROUP_TABLE', 'fixture_members'); $table_prefix = 'fixture_';
$source = dirname(dirname(__DIR__)) . '/phpBB2/';
foreach (array('includes/php_compat.php', 'db/mysqli.php', 'album_mod/album_constants.php', 'album_mod/album_functions.php', 'album_mod/album_hierarchy_auth.php', 'album_mod/album_hierarchy_sql.php', 'album_mod/album_category_storage.php') as $file) { require $source . $file; }
require __DIR__ . '/album-native-session.php'; require __DIR__ . '/album-native-observer.php';
function ace_check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
function message_die($type, $message) { throw new RuntimeException($message); }
function checkFlag($value, $flag) { return ($value & $flag) === $flag; }
function album_is_debug_enabled() { return false; }
class AlbumCategoryEditObservedSource extends sql_db
{
    var $queries = array(); var $fail_at = 0; var $hook = null; var $worker_role = ''; var $worker_directory = ''; var $worker_gate_done = false;
    function reset_observer($failure = 0) { $this->queries = array(); $this->fail_at = $failure; $this->hook = null; }
    function sql_dedicated_connection() { return new AlbumNativeObservedConnection(parent::sql_dedicated_connection(), $this); }
    function observed_query($database, $sql, $transaction = false)
    {
        $this->queries[] = $sql;
        if ($this->fail_at > 0 && count($this->queries) === $this->fail_at) { return false; }
        if (strpos($sql, 'UPDATE fixture_album_cat ') === 0 && is_callable($this->hook)) { $hook = $this->hook; $this->hook = null; call_user_func($hook); }
        $owner_lock = strpos($sql, 'SELECT user_id FROM fixture_users WHERE ') === 0 && strpos($sql, ' FOR UPDATE') !== false;
        if ($owner_lock && $this->worker_role === 'peer' && !$this->worker_gate_done) {
            ace_check(file_put_contents($this->worker_directory . '/peer-attempt', '1') !== false, 'Peer reached actual owner lock'); $this->worker_gate_done = true;
        }
        $result = $database->sql_query($sql, $transaction);
        if ($owner_lock && $result && $this->worker_role === 'holder' && !$this->worker_gate_done) {
            $this->worker_gate_done = true; ace_check(file_put_contents($this->worker_directory . '/holder-locked', '1') !== false, 'Holder acquired actual owner lock'); $deadline = microtime(true) + 8;
            while (!is_file($this->worker_directory . '/peer-attempt')) { if (microtime(true) > $deadline) { throw new RuntimeException('Actual edit peer barrier deadline'); } usleep(10000); clearstatcache(); }
        }
        return $result;
    }
}
$body = file_get_contents($source . 'includes/functions.php'); ace_check(preg_match('/^function phpbb_sql_id_list\(.*?^\}/ms', $body, $m) === 1, 'Actual group parser'); eval($m[0]);
function album_sql_id_list($ids) { return phpbb_sql_id_list($ids); }
$body = file_get_contents($source . 'album_personal_cat_admin.php');
foreach (array('album_personal_permission_value', 'album_personal_category_text') as $function) { ace_check(preg_match('/^function ' . $function . '\(.*?^\}/ms', $body, $m) === 1, 'Actual input helper'); eval($m[0]); }
$start = strpos($body, "\telse if( \$mode == 'edit' )\n"); ace_check($start !== false, 'Actual edit branch');
$start = strpos($body, "\t\t// Get posting variables.", $start); $end = strpos($body, "\t\t// Return a message...", $start);
ace_check($start !== false && $end > $start, 'Actual complete edit POST handler'); $handler = substr($body, $start, $end - $start);
$loader = "require_once dirname(__FILE__) . '/album_mod/album_category_storage.php';"; ace_check(substr_count($handler, $loader) === 1, 'Exactly one actual edit provider loader');
$handler = str_replace($loader, '// Exact provider is already included by this fixture.', $handler);
$order_start = strpos($body, "\tif (\$mode === 'order')\n"); $order_end = strpos($body, "\t\tshowResultMessage(\$lang['Category_changed_order']);", $order_start);
ace_check($order_start !== false && $order_end > $order_start, 'Actual complete order POST handler'); $order_handler = substr($body, $order_start + strlen("\tif (\$mode === 'order')\n\t{\n"), $order_end - $order_start - strlen("\tif (\$mode === 'order')\n\t{\n"));
ace_check(substr_count($order_handler, $loader) === 1, 'Exactly one actual order provider loader'); $order_handler = str_replace($loader, '// Exact provider already included.', $order_handler);
$intent_start = strpos($body, '$mode = phpbb_request_scalar($_POST,'); ace_check($intent_start !== false, 'Actual request intent and action allowlists');
// Stop before session/gallery bootstrapping between intent and owner checks.
$intent_end = strpos($body, "\n}\n", strpos($body, "if (!in_array(\$mode", $intent_start)); ace_check($intent_end > $intent_start, 'Exact intent check end'); $intent = substr($body, $intent_start, $intent_end + 3 - $intent_start);
$token_start = strpos($body, '$submitted_sid ='); $token_end = strpos($body, '// ------------------------------------------------------------------------', $token_start); ace_check($token_start !== false && $token_end > $token_start, 'Actual common POST SID boundary'); $token = substr($body, $token_start, $token_end - $token_start);
$outer_form = file_get_contents($source . 'templates/fisubsilversh/admin/album_personal_cat_body.tpl'); ace_check(preg_match('/<input type="hidden" value="([^"]*)" name="mode" \/>/', $outer_form, $m) === 1, 'Actual shared form hidden mode'); $outer_mode = $m[1];
ace_check(preg_match('/\x27S_CAT_TITLE\x27 => (\(\$is_personal_root_cat\).*),\n/', $body, $m) === 1, 'Actual edit title template value'); $title_expression = $m[1];
$port = getenv('PHPBB_ALBUM_CATEGORY_EDIT_PORT') ?: '3306'; $password = getenv('PHPBB_ALBUM_CATEGORY_EDIT_PASSWORD') ?: '';
ace_check(preg_match('/^[0-9]{1,5}$/D', $port) && (int) $port > 0 && (int) $port <= 65535, 'Owned loopback port'); $host = '127.0.0.1:' . $port;
if (isset($argv[1]) && $argv[1] === 'worker') {
    ace_check(isset($argv[2], $argv[3], $argv[4]) && preg_match('/^codex_album_cat_edit_[a-f0-9]{16}$/D', $argv[2]) && in_array($argv[3], array('holder', 'peer'), true) && in_array($argv[4], array('normal', 'slashes', 'quotes'), true), 'Only actual owned edit worker inputs');
    $db = new AlbumCategoryEditObservedSource($host, 'root', $password, $argv[2], false); ace_check($db->db_connect_id, 'Own independent worker connection'); $db->worker_role = $argv[3]; $db->worker_directory = sys_get_temp_dir() . '/' . $argv[2] . '-workers'; ace_check(is_dir($db->worker_directory) && !is_link($db->worker_directory), 'Own edit barrier directory');
    $userdata = array('user_id' => 7, 'username' => 'Synthetic', 'user_level' => 0, 'user_active' => 1, 'session_logged_in' => true, 'session_id' => str_repeat('a', 32)); $album_data = array();
    $album_config = array('rate' => 1, 'comment' => 1, 'personal_gallery' => ALBUM_USER, 'personal_gallery_view' => ALBUM_GUEST, 'personal_gallery_private' => '10', 'personal_allow_gallery_mod' => 1, 'personal_allow_sub_categories' => 1, 'personal_sub_category_limit' => 20, 'album_category_sorting' => 'cat_order');
    $mode = $argv[4] === 'normal' ? '' : ($argv[4] === 'slashes' ? 'STRICT_ALL_TABLES,NO_BACKSLASH_ESCAPES' : 'STRICT_ALL_TABLES,ANSI_QUOTES,NO_BACKSLASH_ESCAPES'); ace_check($db->sql_query("SET SESSION sql_mode='" . $mode . "'"), 'Worker SQL mode');
    $holder = $argv[3] === 'holder'; $levels = array('view' => ALBUM_GUEST, 'upload' => ALBUM_PRIVATE, 'rate' => ALBUM_USER, 'comment' => ALBUM_USER);
    try { $ok = isset($argv[5]) && $argv[5] === 'order' ? album_personal_category_order(7, 12, $holder ? -15 : 15) : album_personal_category_edit(7, $holder ? 11 : 12, $holder ? 12 : 11, 'Concurrent ' . $argv[3], "Mode 'slash' \\ 😀", $levels); echo json_encode(array('success' => $ok)), "\n"; } finally { $db->sql_close(); } exit(0);
}
$control = new sql_db($host, 'root', $password, '', false); ace_check($control->db_connect_id, 'Own loopback control');
$schema = 'codex_album_cat_edit_' . bin2hex(phpbb_random_bytes(8)); $created = false; $db = null; $cases = 0; $children = array(); $workers = sys_get_temp_dir() . '/' . $schema . '-workers';
function ace_sql($sql) { $r = $GLOBALS['control']->sql_query($sql); ace_check($r !== false, 'Own synthetic SQL'); return $r; }
function ace_rows($sql) { $r = ace_sql($sql); $rows = $GLOBALS['control']->sql_fetchrowset($r); $GLOBALS['control']->sql_freeresult($r); return $rows; }
function ace_child($role, $mode, $operation = '')
{
    $command = escapeshellarg(PHP_BINARY) . ' ';
    if (DIRECTORY_SEPARATOR === chr(92)) { $command .= '-n -d ' . escapeshellarg('extension_dir=' . ini_get('extension_dir')) . ' -d extension=php_mysqli.dll -d extension=php_openssl.dll '; if (extension_loaded('mbstring')) { $command .= '-d extension=php_mbstring.dll '; } }
    $command .= escapeshellarg(__FILE__) . ' worker ' . escapeshellarg($GLOBALS['schema']) . ' ' . escapeshellarg($role) . ' ' . escapeshellarg($mode) . ' ' . escapeshellarg($operation);
    $process = proc_open($command, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, null, null, array('bypass_shell' => true)); ace_check(is_resource($process), 'Own edit process'); fclose($pipes[0]); $GLOBALS['children'][] = array($process, $pipes); return count($GLOBALS['children']) - 1;
}
function ace_child_finish($index, $expected)
{
    $entry = $GLOBALS['children'][$index]; $deadline = microtime(true) + 12;
    do { $status = proc_get_status($entry[0]); if (!$status['running']) { break; } if (microtime(true) > $deadline) { throw new RuntimeException('Own edit child deadline'); } usleep(10000); } while (true);
    $out = stream_get_contents($entry[1][1]); $errors = stream_get_contents($entry[1][2]); fclose($entry[1][1]); fclose($entry[1][2]); $closed = proc_close($entry[0]); $GLOBALS['children'][$index] = null;
    $code = $status['exitcode'] >= 0 ? $status['exitcode'] : $closed; $result = json_decode(trim($out), true); ace_check($code === 0 && $errors === '' && is_array($result) && $result['success'] === $expected, 'Actual concurrent edit result: ' . $errors . $out);
}
function ace_seed($owner = 7)
{
    ace_sql('DELETE FROM fixture_album_cat');
    ace_sql("INSERT INTO fixture_album_cat(cat_id,cat_title,cat_desc,cat_order,cat_user_id,cat_parent,cat_view_level,cat_upload_level) VALUES(10,'Root',NULL,0," . $owner . ',0,' . ALBUM_GUEST . ',' . ALBUM_PRIVATE . "),(11,'Original child','Original description',10," . $owner . ',10,' . ALBUM_GUEST . ',' . ALBUM_PRIVATE . "),(12,'Sibling','Original sibling',20," . $owner . ',10,' . ALBUM_GUEST . ',' . ALBUM_PRIVATE . ')');
}
function ace_order_seed($owner = 7)
{
    ace_seed($owner); $other = $owner === 7 ? 8 : 7;
    ace_sql("INSERT INTO fixture_album_cat(cat_id,cat_title,cat_desc,cat_order,cat_user_id,cat_parent,cat_view_level,cat_upload_level) VALUES(13,'C','Third sibling',30," . $owner . ',10,' . ALBUM_GUEST . ',' . ALBUM_PRIVATE . "),(14,'Nested','Keep nested order',50," . $owner . ',11,' . ALBUM_GUEST . ',' . ALBUM_PRIVATE . "),(20,'Foreign root','Keep other owner',400," . $other . ',0,' . ALBUM_GUEST . ',' . ALBUM_PRIVATE . "),(21,'Foreign child','Keep other order',500," . $other . ',20,' . ALBUM_GUEST . ',' . ALBUM_PRIVATE . "),(30,'Public','Keep public order',600,0,0," . ALBUM_GUEST . ',' . ALBUM_PRIVATE . ')');
}
function ace_order_shape($rows) { foreach ($rows as &$row) { foreach (array_keys($row) as $key) { if (is_int($key) || $key === 'cat_order') { unset($row[$key]); } } } unset($row); return $rows; }
class AlbumOrderRendererCapture {
    var $rows = array();
    function assign_vars($vars) {}
    function assign_block_vars($name, $vars) { if ($name === 'catrow.cathead') { $this->rows[] = $vars; } }
}
$reporting = error_reporting(E_ALL); set_error_handler(function ($s, $m) { if (error_reporting() & $s) { throw new RuntimeException($m); } });
try
{
    ace_sql('CREATE DATABASE ' . $schema . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); $created = true; ace_sql('USE ' . $schema);
    ace_sql('CREATE TABLE fixture_users(user_id MEDIUMINT PRIMARY KEY,username VARCHAR(255),user_level TINYINT,user_active TINYINT) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'); ace_sql("INSERT INTO fixture_users VALUES(7,'Actor',1,1),(8,'Other owner',0,1)");
    ace_sql('CREATE TABLE fixture_members(user_id MEDIUMINT,group_id MEDIUMINT,user_pending TINYINT) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $ddl = file_get_contents($source . 'install/schemas/mysql_schema.sql'); ace_check(preg_match('/CREATE TABLE phpbb_album_cat\s*\([\s\S]*?;/', $ddl, $m) === 1, 'Canonical category schema'); ace_sql(str_replace('phpbb_album_cat', 'fixture_album_cat', $m[0])); album_native_session_schema($control, $source);
    $db = new AlbumCategoryEditObservedSource($host, 'root', $password, $schema, false); $original = $db; $sid = str_repeat('a', 32);
    $album_config = array('rate' => 1, 'comment' => 1, 'personal_gallery' => ALBUM_USER, 'personal_gallery_view' => ALBUM_GUEST, 'personal_gallery_private' => '10', 'personal_allow_gallery_mod' => 1, 'personal_allow_sub_categories' => 1, 'personal_sub_category_limit' => 20);
    $levels = array('view' => ALBUM_GUEST, 'upload' => ALBUM_PRIVATE, 'rate' => ALBUM_USER, 'comment' => ALBUM_USER);
    foreach (array('unchanged-admin', 'revoked-admin', 'inactive-actor', 'logged-out-session', 'expired-session', 'missing-session', 'foreign-session', 'unchanged-owner') as $case)
    {
        ace_sql('UPDATE fixture_users SET user_active=1,user_level=0'); ace_sql('UPDATE fixture_users SET user_level=' . ($case === 'unchanged-owner' ? '0' : '1') . ' WHERE user_id=7'); album_native_session_seed($control, 7, $sid);
        $owner = $case === 'unchanged-owner' ? 7 : 8; ace_seed($owner);
        $r = $db->sql_query('SELECT user_id,username,user_level,user_active FROM fixture_users WHERE user_id=7'); $userdata = $db->sql_fetchrow($r); $db->sql_freeresult($r); $userdata['session_logged_in'] = true; $userdata['session_id'] = $sid; $snapshot = $userdata;
        if ($case === 'revoked-admin') { ace_sql('UPDATE fixture_users SET user_level=0 WHERE user_id=7'); }
        if ($case === 'inactive-actor') { ace_sql('UPDATE fixture_users SET user_active=0 WHERE user_id=7'); }
        if ($case === 'logged-out-session') { ace_sql('UPDATE fixture_sessions SET session_logged_in=0'); }
        if ($case === 'expired-session') { ace_sql('UPDATE fixture_sessions SET session_time=' . (time() - 3601)); }
        if ($case === 'missing-session') { ace_sql('DELETE FROM fixture_sessions'); }
        if ($case === 'foreign-session') { ace_sql('UPDATE fixture_sessions SET session_user_id=8'); }
        $allowed = in_array($case, array('unchanged-admin', 'unchanged-owner'), true);
        $users = ace_rows('SELECT * FROM fixture_users ORDER BY user_id'); $sessions = ace_rows('SELECT * FROM fixture_sessions ORDER BY session_id');
        foreach (array(false, true) as $post)
        {
            ace_seed($owner); $before = ace_rows('SELECT * FROM fixture_album_cat ORDER BY cat_id'); $album_data = array('fixture' => 'preserve');
            if ($post) {
                $album_user_id = $owner; $cat_id = 11; $lang = array('No_valid_category_selected' => 'invalid-title', 'Personal_category_update_failed' => 'edit-refused');
                $_POST = array('cat_title' => addslashes("Grüße 😀 & <'title>"), 'cat_desc' => addslashes("Line \\ note 😀"), 'cat_parent_id' => '12', 'cat_view_level' => (string) ALBUM_ADMIN); $ok = true;
                try { eval($handler); } catch (RuntimeException $error) { ace_check($error->getMessage() === 'edit-refused', 'Actual POST expected failure only'); $ok = false; }
            } else { $ok = album_personal_category_edit($owner, 11, 12, 'Changed child', 'Grüße 😀', $levels); }
            ace_check($ok === $allowed, 'Fresh actual edit grant: ' . $case); $rows = ace_rows('SELECT * FROM fixture_album_cat ORDER BY cat_id');
            ace_check($ok ? count($rows) === 3 && (int) $rows[1]['cat_parent'] === 12 : $rows === $before, 'Only authorized target changes');
            if ($ok && $post) { ace_check($rows[1]['cat_title'] === 'Grüße 😀 &amp; &lt;&#039;title&gt;' && $rows[1]['cat_desc'] === "Line \\ note 😀", 'Actual POST Unicode/entity/slash preservation'); }
            ace_check($db === $original && $userdata === $snapshot && ($ok ? $album_data === array() : $album_data === array('fixture' => 'preserve')) && ace_rows('SELECT * FROM fixture_users ORDER BY user_id') === $users && ace_rows('SELECT * FROM fixture_sessions ORDER BY session_id') === $sessions, 'Caller/account/session/cache context preserved'); $cases++;
        }
    }
    ace_seed(); $db->reset_observer(); ace_check(album_personal_category_edit(7, 11, 12, 'Baseline', 'Grüße 😀', $levels), 'Native SQL fault baseline'); $statements = count($db->queries); ace_check($statements > 10 && $statements < 100, 'Bounded actual edit statement inventory'); $cases++;
    for ($failure = 1; $failure <= $statements; $failure++) {
        ace_seed(); $before = ace_rows('SELECT * FROM fixture_album_cat ORDER BY cat_id'); $album_data = array('fixture' => 'fault'); $snapshot = $userdata; $db->reset_observer($failure);
        ace_check(!album_personal_category_edit(7, 11, 12, 'Fault', 'Preserve', $levels), 'Every owned edit SQL statement fails closed: ' . $failure);
        ace_check(ace_rows('SELECT * FROM fixture_album_cat ORDER BY cat_id') === $before && $db === $original && $userdata === $snapshot && $album_data === array('fixture' => 'fault'), 'Owned rollback preserves unrelated data/context'); $cases++;
    }
    $db->reset_observer(); ace_sql('SET SESSION innodb_lock_wait_timeout=0'); $blocked = 0;
    $db->hook = function () use (&$blocked) {
        foreach (array('UPDATE fixture_users SET user_level=1 WHERE user_id=7', 'UPDATE fixture_sessions SET session_logged_in=0', 'UPDATE fixture_album_cat SET cat_parent=11 WHERE cat_id=12') as $sql) {
            $r = $GLOBALS['control']->sql_query($sql); $error = $GLOBALS['control']->sql_error(); ace_check($r === false && (int) $error['code'] === 1205, 'Actual independent account/session/parent writer blocked through edit commit'); $blocked++;
        }
    };
    ace_check(album_personal_category_edit(7, 11, 12, 'Locked grant', 'Preserve', $levels) && $blocked === 3, 'Account, login and actual parent locks retained'); $cases++; $db->reset_observer();
    ace_sql('CREATE TABLE fixture_caller(id INT PRIMARY KEY,body VARCHAR(40)) ENGINE=InnoDB'); ace_sql("INSERT INTO fixture_caller VALUES(1,'original')");
    foreach (array(true, false) as $allowed) {
        ace_seed(); album_native_session_seed($control, 7, $sid); if (!$allowed) { ace_sql('DELETE FROM fixture_sessions'); }
        ace_check($db->sql_query('START TRANSACTION') && $db->sql_query("UPDATE fixture_caller SET body='pending caller' WHERE id=1"), 'Begin unrelated caller transaction');
        ace_check(album_personal_category_edit(7, 11, 12, 'Independent', 'Preserve', $levels) === $allowed, 'Independent edit grant');
        $r = $db->sql_query('SELECT body FROM fixture_caller WHERE id=1'); $pending = $db->sql_fetchrow($r); $db->sql_freeresult($r);
        $outside = ace_rows('SELECT body FROM fixture_caller WHERE id=1'); ace_check($pending['body'] === 'pending caller' && $outside[0]['body'] === 'original', 'Neither successful nor denied edit commits/rolls back caller work'); ace_check($db->sql_query('ROLLBACK'), 'Caller owns rollback');
        $rows = ace_rows('SELECT cat_parent FROM fixture_album_cat WHERE cat_id=11'); ace_check((int) $rows[0]['cat_parent'] === ($allowed ? 12 : 10), 'Independent edit survives caller rollback'); $cases++;
    }
    album_native_session_seed($control, 7, $sid);
    ace_check(mkdir($workers, 0700), 'Own edit worker barriers');
    foreach (array('normal', 'slashes', 'quotes') as $mode) {
        ace_seed(); $first = ace_child('holder', $mode); $deadline = microtime(true) + 8;
        while (!is_file($workers . '/holder-locked')) { if (microtime(true) > $deadline) { throw new RuntimeException('Actual edit holder deadline'); } usleep(10000); clearstatcache(); }
        $second = ace_child('peer', $mode); ace_child_finish($first, true); ace_child_finish($second, false);
        $rows = ace_rows('SELECT cat_id,cat_title,cat_desc,cat_parent FROM fixture_album_cat ORDER BY cat_id');
        ace_check(count($rows) === 3 && $rows[1]['cat_title'] === 'Concurrent holder' && $rows[1]['cat_desc'] === "Mode 'slash' \\ 😀" && (int) $rows[1]['cat_parent'] === 12 && $rows[2]['cat_title'] === 'Sibling' && (int) $rows[2]['cat_parent'] === 10, 'Two independent mutual-reparent requests cannot create a cycle or partially edit denied target');
        unlink($workers . '/holder-locked'); unlink($workers . '/peer-attempt'); $cases++;
    }
    $matrix = array(
        array(7, 11, -1, str_repeat('&#039;', 255), str_repeat('😀', 65535), true),
        array(7, 11, 12, str_repeat('😀', 255), "SQL 'literal' \\ 😀", true),
        array(7, 11, 10, '&amp;amp;', 'Literal entity', true),
        array(7, 11, 10, 'FROM fixture_members', 'Ordinary text', true),
        array(7, 11, 10, str_repeat('x', 256), 'Too long', false),
        array(7, 11, 10, 'Title', str_repeat('x', 65536), false),
        array(7, 11, 10, '<raw>', 'Noncanonical', false), array(7, 11, 10, '&', 'Noncanonical', false),
        array(7, 11, 10, "\xc3", 'Invalid title', false), array(7, 11, 10, 'Title', "\xc3", false),
        array(7, 11, 11, 'Self', 'Cycle', false), array(7, 11, 0, 'Invalid', 'Parent zero', false),
        array(7, 11, -2, 'Invalid', 'Parent alias', false), array(7, 11, 999, 'Invalid', 'Missing parent', false),
        array(7, 11, 16777216, 'Invalid', 'Overwide parent', false), array(7, 999, 10, 'Invalid', 'Missing target', false),
        array(7, 16777216, 10, 'Invalid', 'Overwide target', false), array(7, '11', 10, 'Invalid', 'Typed target', false),
        array(8, 11, 10, 'Invalid', 'Foreign owner', false), array('7', 11, 10, 'Invalid', 'Typed owner', false),
        array(0, 11, 10, 'Invalid', 'Guest owner', false), array(8388608, 11, 10, 'Invalid', 'Overwide owner', false)
    );
    foreach (array('', 'STRICT_ALL_TABLES,NO_BACKSLASH_ESCAPES', 'STRICT_ALL_TABLES,ANSI_QUOTES,NO_BACKSLASH_ESCAPES') as $mode) {
        ace_check($db->sql_query("SET SESSION sql_mode='" . $mode . "'"), 'Caller SQL mode');
        $r = $db->sql_query('SELECT @@SESSION.sql_mode AS mode,@@SESSION.innodb_lock_wait_timeout AS wait_time'); $settings = $db->sql_fetchrow($r); $db->sql_freeresult($r);
        foreach ($matrix as $case) {
            ace_seed(); $before = ace_rows('SELECT * FROM fixture_album_cat ORDER BY cat_id'); $album_data = array('fixture' => 'input'); $snapshot = $userdata;
            $ok = album_personal_category_edit($case[0], $case[1], $case[2], $case[3], $case[4], $levels); ace_check($ok === $case[5], 'Typed/logical/canonical/parent edit boundary');
            $rows = ace_rows('SELECT * FROM fixture_album_cat ORDER BY cat_id'); ace_check($ok ? $rows[1]['cat_title'] === $case[3] && $rows[1]['cat_desc'] === $case[4] : $rows === $before, 'Complete exact stored bytes or no mutation');
            $r = $db->sql_query('SELECT @@SESSION.sql_mode AS mode,@@SESSION.innodb_lock_wait_timeout AS wait_time'); $after = $db->sql_fetchrow($r); $db->sql_freeresult($r);
            ace_check($settings === $after && $db === $original && $userdata === $snapshot && ($ok ? $album_data === array() : $album_data === array('fixture' => 'input')), 'All outcomes preserve caller SQL settings/context'); $cases++;
        }
        ace_seed(); $root = ace_rows('SELECT cat_title,cat_desc FROM fixture_album_cat WHERE cat_id=10');
        ace_check(album_personal_category_edit(7, 10, -1, 'Must not replace root', 'Must not replace NULL', $levels) && ace_rows('SELECT cat_title,cat_desc FROM fixture_album_cat WHERE cat_id=10') === $root, 'Read-only root title and SQL NULL description retained'); $cases++;
        ace_check(album_personal_category_edit(7, 11, 10, 'No-op', 'Same', $levels) && album_personal_category_edit(7, 11, 10, 'No-op', 'Same', $levels), 'An exact zero-affected-row edit succeeds'); $cases++;
        $logical = "Grüße 😀 & <'title> &amp;"; $stored = album_personal_category_text(addslashes($logical), 255, true);
        ace_check(album_personal_category_edit(7, 11, 10, $stored, '&amp; is literal description text', $levels), 'Seed actual title roundtrip');
        $is_personal_root_cat = false;
        for ($round = 0; $round < 5; $round++) {
            $rows = ace_rows('SELECT * FROM fixture_album_cat WHERE cat_id=11'); $catrow = $rows[0]; eval('$presented = ' . $title_expression . ';');
            $browser_value = html_entity_decode($presented, ENT_QUOTES, 'UTF-8'); ace_check($browser_value === $logical, 'Actual edit form shows original logical title');
            ace_check(album_personal_category_edit(7, 11, 10, album_personal_category_text(addslashes($browser_value), 255, true), $catrow['cat_desc'], $levels), 'Unchanged actual form input saves');
            $rows = ace_rows('SELECT cat_title,cat_desc FROM fixture_album_cat WHERE cat_id=11'); ace_check($rows[0]['cat_title'] === $stored && $rows[0]['cat_desc'] === '&amp; is literal description text', 'No repeated encoding or description entity decoding'); $cases++;
        }
    }
    foreach (array('descendant', 'foreign-parent', 'orphan-parent', 'cyclic-parent', 'ambiguous-root', 'missing-root', 'invalid-current-path') as $case) {
        ace_seed(); $parent = 12;
        if ($case === 'descendant') { ace_sql('UPDATE fixture_album_cat SET cat_parent=11 WHERE cat_id=12'); }
        if ($case === 'foreign-parent') { ace_sql('UPDATE fixture_album_cat SET cat_user_id=8 WHERE cat_id=12'); }
        if ($case === 'orphan-parent') { ace_sql('UPDATE fixture_album_cat SET cat_parent=999 WHERE cat_id=12'); }
        if ($case === 'cyclic-parent') { ace_sql('UPDATE fixture_album_cat SET cat_parent=12 WHERE cat_id=12'); }
        if ($case === 'ambiguous-root') { ace_sql('UPDATE fixture_album_cat SET cat_parent=0 WHERE cat_id=12'); }
        if ($case === 'missing-root') { ace_sql('DELETE FROM fixture_album_cat WHERE cat_id=10'); }
        if ($case === 'invalid-current-path') { ace_sql('UPDATE fixture_album_cat SET cat_parent=999 WHERE cat_id=11'); }
        $before = ace_rows('SELECT * FROM fixture_album_cat ORDER BY cat_id'); ace_check(!album_personal_category_edit(7, 11, $parent, 'Invalid graph', 'Preserve', $levels) && ace_rows('SELECT * FROM fixture_album_cat ORDER BY cat_id') === $before, 'Actual locked owner graph rejected without changes: ' . $case); $cases++;
    }
    foreach (array(null, 'invalid', new stdClass()) as $invalid) { $snapshot = $userdata; $userdata = $invalid; ace_seed(); $before = ace_rows('SELECT * FROM fixture_album_cat ORDER BY cat_id'); ace_check(!album_personal_category_edit(7, 11, 10, 'Invalid actor', 'Preserve', $levels) && $userdata === $invalid && ace_rows('SELECT * FROM fixture_album_cat ORDER BY cat_id') === $before, 'Malformed identity rejected without warnings/writes'); $userdata = $snapshot; $cases++; }
    echo 'Native Album category edit: ', $cases, " actual edit/current-grant/session/SQL-fault/locked-parent/caller-TX/process-concurrency/input/SQL-mode/title-roundtrip cases PASS; synthetic loopback only.\n";

    // The same actual owning primitive now also covers personal ordering.
    $order_cases = 0; $album_config['album_category_sorting'] = 'cat_order';
    foreach (array('unchanged-admin', 'revoked-admin', 'inactive-actor', 'logged-out-session', 'expired-session', 'missing-session', 'foreign-session', 'unchanged-owner') as $case) {
        ace_sql('UPDATE fixture_users SET user_active=1,user_level=0'); ace_sql('UPDATE fixture_users SET user_level=' . ($case === 'unchanged-owner' ? '0' : '1') . ' WHERE user_id=7'); album_native_session_seed($control, 7, $sid); $owner = $case === 'unchanged-owner' ? 7 : 8;
        $r = $db->sql_query('SELECT user_id,username,user_level,user_active FROM fixture_users WHERE user_id=7'); $userdata = $db->sql_fetchrow($r); $db->sql_freeresult($r); $userdata['session_logged_in'] = true; $userdata['session_id'] = $sid; $snapshot = $userdata;
        if ($case === 'revoked-admin') { ace_sql('UPDATE fixture_users SET user_level=0 WHERE user_id=7'); }
        if ($case === 'inactive-actor') { ace_sql('UPDATE fixture_users SET user_active=0 WHERE user_id=7'); }
        if ($case === 'logged-out-session') { ace_sql('UPDATE fixture_sessions SET session_logged_in=0'); }
        if ($case === 'expired-session') { ace_sql('UPDATE fixture_sessions SET session_time=' . (time() - 3601)); }
        if ($case === 'missing-session') { ace_sql('DELETE FROM fixture_sessions'); }
        if ($case === 'foreign-session') { ace_sql('UPDATE fixture_sessions SET session_user_id=8'); }
        $allowed = in_array($case, array('unchanged-admin', 'unchanged-owner'), true); $users = ace_rows('SELECT * FROM fixture_users ORDER BY user_id'); $sessions = ace_rows('SELECT * FROM fixture_sessions ORDER BY session_id');
        foreach (array(false, true) as $post) {
            ace_order_seed($owner); $before = ace_rows('SELECT * FROM fixture_album_cat ORDER BY cat_id'); $album_data = array('fixture' => 'order context'); $db->reset_observer();
            if ($post) {
                $album_user_id = $owner; $_POST = array('sid' => $sid, 'move_category' => '12:-15', 'mode' => $outer_mode, 'name' => array(10 => 'Unsubmitted draft')); $_GET = array(); $_SERVER['REQUEST_METHOD'] = 'POST'; $lang = array('Not_Authorised' => 'bad-request', 'Personal_category_order_failed' => 'order-refused'); $ok = true;
                try { eval($intent); eval($token); ace_check($mode === 'order', 'Actual submit button classifies ordering intent'); eval($order_handler); } catch (RuntimeException $error) { ace_check($error->getMessage() === 'order-refused', 'Actual order POST failure only'); $ok = false; }
            } else { $ok = album_personal_category_order($owner, 12, -15); }
            ace_check($ok === $allowed, 'Actual current order grant: ' . $case); $rows = ace_rows('SELECT * FROM fixture_album_cat ORDER BY cat_id');
            ace_check($ok ? ace_order_shape($rows) === ace_order_shape($before) && (int) $rows[1]['cat_order'] === 20 && (int) $rows[2]['cat_order'] === 10 : $rows === $before, 'Only authorized sibling order changes');
            foreach (array(0, 4, 5, 6, 7) as $index) { ace_check($rows[$index] === $before[$index], 'Root, descendant, other gallery and public rows untouched'); }
            ace_check($db === $original && $userdata === $snapshot && ($ok ? $album_data === array() : $album_data === array('fixture' => 'order context')) && ace_rows('SELECT * FROM fixture_users ORDER BY user_id') === $users && ace_rows('SELECT * FROM fixture_sessions ORDER BY session_id') === $sessions, 'Order caller/account/session/cache restoration'); $order_cases++;
        }
    }
    ace_order_seed(); $db->reset_observer(); ace_check(album_personal_category_order(7, 12, -15), 'Actual order fault baseline'); $statements = count($db->queries); ace_check($statements > 10 && $statements < 100, 'Bounded actual order SQL inventory'); $order_cases++;
    for ($failure = 1; $failure <= $statements; $failure++) {
        ace_order_seed(); $before = ace_rows('SELECT * FROM fixture_album_cat ORDER BY cat_id'); $album_data = array('fixture' => 'order fault'); $snapshot = $userdata; $db->reset_observer($failure);
        ace_check(!album_personal_category_order(7, 12, -15) && ace_rows('SELECT * FROM fixture_album_cat ORDER BY cat_id') === $before && $db === $original && $userdata === $snapshot && $album_data === array('fixture' => 'order fault'), 'Every actual order SQL failure rolls back all changed siblings and restores context: ' . $failure); $order_cases++;
    }
    ace_order_seed(); $db->reset_observer(); $blocked = 0;
    $db->hook = function () use (&$blocked) { foreach (array('UPDATE fixture_users SET user_level=1 WHERE user_id=7', 'UPDATE fixture_sessions SET session_logged_in=0', 'UPDATE fixture_album_cat SET cat_parent=20 WHERE cat_id=12', 'UPDATE fixture_album_cat SET cat_order=999 WHERE cat_id=11') as $sql) { $r = $GLOBALS['control']->sql_query($sql); $error = $GLOBALS['control']->sql_error(); ace_check($r === false && (int) $error['code'] === 1205, 'Actual current account, session and siblings retained through order commit'); $blocked++; } };
    ace_check(album_personal_category_order(7, 12, -15) && $blocked === 4, 'Independent account/logout/parent/order writers blocked through commit'); $order_cases++; $db->reset_observer();
    foreach (array(true, false) as $allowed) {
        ace_order_seed(); album_native_session_seed($control, 7, $sid); if (!$allowed) { ace_sql('DELETE FROM fixture_sessions'); }
        ace_check($db->sql_query('START TRANSACTION') && $db->sql_query("UPDATE fixture_caller SET body='pending caller' WHERE id=1"), 'Begin unrelated order caller TX');
        ace_check(album_personal_category_order(7, 12, -15) === $allowed, 'Order owns independent transaction'); $r = $db->sql_query('SELECT body FROM fixture_caller WHERE id=1'); $pending = $db->sql_fetchrow($r); $db->sql_freeresult($r); $outside = ace_rows('SELECT body FROM fixture_caller WHERE id=1');
        ace_check($pending['body'] === 'pending caller' && $outside[0]['body'] === 'original' && $db->sql_query('ROLLBACK'), 'Neither successful nor denied ordering changes caller TX'); $rows = ace_rows('SELECT cat_order FROM fixture_album_cat WHERE cat_id=12'); ace_check((int) $rows[0]['cat_order'] === ($allowed ? 10 : 20), 'Caller rollback cannot undo own category order commit'); $order_cases++;
    }
    album_native_session_seed($control, 7, $sid);
    foreach (array('normal', 'slashes', 'quotes') as $mode) {
        ace_order_seed(); $before = ace_rows('SELECT * FROM fixture_album_cat ORDER BY cat_id'); $first = ace_child('holder', $mode, 'order'); $deadline = microtime(true) + 8;
        while (!is_file($workers . '/holder-locked')) { if (microtime(true) > $deadline) { throw new RuntimeException('Order holder deadline'); } usleep(10000); clearstatcache(); }
        $second = ace_child('peer', $mode, 'order'); ace_child_finish($first, true); ace_child_finish($second, true);
        ace_check(ace_rows('SELECT * FROM fixture_album_cat ORDER BY cat_id') === $before, 'Two independent opposing moves serialize without a lost update, cycle or unrelated write'); unlink($workers . '/holder-locked'); unlink($workers . '/peer-attempt'); $order_cases++;
    }
    $order_matrix = array(array(7, 12, -15, true), array(7, 12, 15, true), array(7, 11, -15, true), array(7, 13, 15, true), array(7, 14, -15, true), array(7, 10, -15, false), array(7, 21, 15, false), array(7, 999, 15, false), array(7, 16777216, 15, false), array(7, '12', 15, false), array('7', 12, 15, false), array(0, 12, 15, false), array(8388608, 12, 15, false), array(7, 12, 0, false), array(7, 12, '15', false), array(7, 12, array(), false));
    foreach (array('', 'STRICT_ALL_TABLES,NO_BACKSLASH_ESCAPES', 'STRICT_ALL_TABLES,ANSI_QUOTES,NO_BACKSLASH_ESCAPES') as $sql_mode) {
        ace_check($db->sql_query("SET SESSION sql_mode='" . $sql_mode . "'"), 'Order SQL mode'); $r = $db->sql_query('SELECT @@SESSION.sql_mode AS mode,@@SESSION.innodb_lock_wait_timeout AS wait_time'); $settings = $db->sql_fetchrow($r); $db->sql_freeresult($r);
        foreach ($order_matrix as $case) {
            ace_order_seed(); $before = ace_rows('SELECT * FROM fixture_album_cat ORDER BY cat_id'); $album_data = array('fixture' => 'order input'); $snapshot = $userdata; $ok = album_personal_category_order($case[0], $case[1], $case[2]); ace_check($ok === $case[3], 'Typed order owner/category/direction/boundary'); $after = ace_rows('SELECT * FROM fixture_album_cat ORDER BY cat_id'); ace_check($ok ? ace_order_shape($after) === ace_order_shape($before) : $after === $before, 'Only order columns can change');
            if ($ok && in_array($case[1], array(11, 13, 14), true)) { ace_check($after === $before, 'First/last/only sibling boundary is exact data no-op'); }
            $r = $db->sql_query('SELECT @@SESSION.sql_mode AS mode,@@SESSION.innodb_lock_wait_timeout AS wait_time'); $after_settings = $db->sql_fetchrow($r); $db->sql_freeresult($r); ace_check($settings === $after_settings && $db === $original && $userdata === $snapshot && ($ok ? $album_data === array() : $album_data === array('fixture' => 'order input')), 'Order preserves caller settings/context'); $order_cases++;
        }
        foreach (array('ties', 'signed-extremes') as $kind) {
            ace_order_seed(); ace_sql('UPDATE fixture_album_cat SET cat_order=' . ($kind === 'ties' ? '0' : '-8388608') . ' WHERE cat_id=11'); ace_sql('UPDATE fixture_album_cat SET cat_order=0 WHERE cat_id=12'); ace_sql('UPDATE fixture_album_cat SET cat_order=' . ($kind === 'ties' ? '0' : '8388607') . ' WHERE cat_id=13');
            ace_check(album_personal_category_order(7, 12, -15), 'Tie/extreme-order normalization succeeds atomically'); $rows = ace_rows('SELECT cat_id,cat_order FROM fixture_album_cat WHERE cat_parent=10 AND cat_user_id=7 ORDER BY cat_order,cat_id'); ace_check(array_map('intval', array_column($rows, 'cat_id')) === array(12, 11, 13) && array_map('intval', array_column($rows, 'cat_order')) === array(10, 20, 30), 'Stable tie break and bounded normalized order values'); $order_cases++;
        }
    }
    foreach (array('foreign-parent', 'orphan-parent', 'cyclic-parent', 'ambiguous-root', 'missing-root') as $case) {
        ace_order_seed(); if ($case === 'foreign-parent') { ace_sql('UPDATE fixture_album_cat SET cat_parent=20 WHERE cat_id=12'); } if ($case === 'orphan-parent') { ace_sql('UPDATE fixture_album_cat SET cat_parent=999 WHERE cat_id=12'); } if ($case === 'cyclic-parent') { ace_sql('UPDATE fixture_album_cat SET cat_parent=12 WHERE cat_id=12'); } if ($case === 'ambiguous-root') { ace_sql('UPDATE fixture_album_cat SET cat_parent=0 WHERE cat_id=13'); } if ($case === 'missing-root') { ace_sql('DELETE FROM fixture_album_cat WHERE cat_id=10'); }
        $before = ace_rows('SELECT * FROM fixture_album_cat ORDER BY cat_id'); ace_check(!album_personal_category_order(7, 12, -15) && ace_rows('SELECT * FROM fixture_album_cat ORDER BY cat_id') === $before, 'Invalid actual owner graph cannot reorder: ' . $case); $order_cases++;
    }
    ace_order_seed(); $album_config['album_category_sorting'] = 'cat_title'; $before = ace_rows('SELECT * FROM fixture_album_cat ORDER BY cat_id'); ace_check(!album_personal_category_order(7, 12, -15) && ace_rows('SELECT * FROM fixture_album_cat ORDER BY cat_id') === $before, 'Automatic title sorting cannot accept misleading manual order mutation'); $album_config['album_category_sorting'] = 'cat_order'; $order_cases++;
    foreach (array(array(), '12:0', '0:-15', '16777216:15', '12:15suffix', '12:-15\n') as $invalid) {
        ace_order_seed(); $before = ace_rows('SELECT * FROM fixture_album_cat ORDER BY cat_id'); $_POST = array('sid' => $sid, 'move_category' => $invalid); $_GET = array(); $_SERVER['REQUEST_METHOD'] = 'POST'; $denied = false;
        try { eval($intent); eval($token); eval($order_handler); } catch (RuntimeException $error) { $denied = true; } ace_check($denied && ace_rows('SELECT * FROM fixture_album_cat ORDER BY cat_id') === $before, 'Malformed actual order POST rejected unchanged'); $order_cases++;
    }
    foreach (array('old-get', 'missing-sid', 'wrong-sid', 'mixed-intent', 'two-buttons') as $case) {
        ace_order_seed(); $before = ace_rows('SELECT * FROM fixture_album_cat ORDER BY cat_id'); $_POST = array('sid' => $sid, 'move_category' => '12:-15'); $_GET = array(); $_SERVER['REQUEST_METHOD'] = 'POST';
        if ($case === 'old-get') { $_POST = array(); $_GET = array('action' => 'move', 'cat_id' => '12', 'move' => '-15', 'album_token' => hash_hmac('sha256', 'album-category-order:12:-15', $sid)); $_SERVER['REQUEST_METHOD'] = 'GET'; }
        if ($case === 'missing-sid') { unset($_POST['sid']); } if ($case === 'wrong-sid') { $_POST['sid'] = str_repeat('b', 32); } if ($case === 'mixed-intent') { $_POST['mode'] = 'delete'; }
        if ($case === 'two-buttons') { $_POST['mode'] = $outer_mode; $_POST['addcategory'] = array(10 => 'Create category'); }
        $denied = false; try { eval($intent); eval($token); eval($order_handler); } catch (RuntimeException $error) { $denied = true; } ace_check($denied && ace_rows('SELECT * FROM fixture_album_cat ORDER BY cat_id') === $before, 'GET/replayed invalid token/mixed action does not mutate: ' . $case); $order_cases++;
    }
    foreach (array(array(array('mode' => $outer_mode, 'addcategory' => array(10 => 'Create category')), 'new'), array(array('mode' => 'edit'), 'edit'), array(array('mode' => 'delete'), 'delete'), array(array(), '')) as $case) { $_POST = $case[0]; $_GET = array(); eval($intent); ace_check($mode === $case[1], 'Shared form intent preserves normal creation/edit/delete/view actions'); $order_cases++; }
    $renderer = file_get_contents($source . 'album_mod/album_hierarchy_functions.php'); $controls_start = strpos($renderer, "\t\t\$order_controls = '';"); $controls_end = strpos($renderer, '// send to template', $controls_start); ace_check($controls_start !== false && $controls_end > $controls_start, 'Actual personal order button presentation'); $controls = substr($renderer, $controls_start, $controls_end - $controls_start);
    $cat_id = 12; $lang = array('Move_up' => 'Hoch & <😀>', 'Move_down' => 'Runter "😀"');
    foreach (array(array(false, 'cat_order', true), array(true, 'cat_order', false), array(false, 'cat_title', false)) as $case) {
        $is_root = $case[0]; $album_config['album_category_sorting'] = $case[1]; eval($controls);
        ace_check($case[2] ? substr_count($order_controls, 'type="submit"') === 2 && strpos($order_controls, 'name="move_category"') !== false && strpos($order_controls, 'value="12:-15"') !== false && strpos($order_controls, 'value="12:15"') !== false && strpos($order_controls, 'Hoch &amp; &lt;😀&gt;') !== false && strpos($order_controls, 'Runter &quot;😀&quot;') !== false && strpos($order_controls, '<a ') === false : $order_controls === '', 'Actual escaped POST controls only for manual, nonroot personal categories'); $order_cases++;
    }
    ace_check(preg_match('/^function album_display_admin_index\(.*?^\}/ms', $renderer, $m) === 1, 'Actual whole category-index renderer');
    eval('namespace AlbumOrderRenderer;
        function album_get_max_depth(&$keys, $cur, $mask, $all) { return 2; }
        function album_get_user_name($id) { return "Synthetic"; }
        function album_html_text($text) { return htmlspecialchars((string) $text, ENT_QUOTES, "UTF-8"); }
        function append_sid($url) { return $url; }
        function album_append_uid($url) { return $url; }
        ' . $m[0]);
    $lang += array('Category_Title' => 'Categories', 'Album_Categories_Title' => 'Categories', 'Album_Categories_Explain' => 'Synthetic', 'Personal_Gallery_Of_User' => 'Gallery of %s', 'Edit' => 'Edit', 'Delete' => 'Delete'); $phpEx = 'php';
    foreach (array('cat_order', 'cat_title') as $sorting) {
        ace_seed(); $rows = ace_rows('SELECT * FROM fixture_album_cat ORDER BY cat_id');
        $album_data = array('data' => $rows, 'keys' => array(10 => 0, 11 => 1, 12 => 2), 'id' => array(10, 11, 12), 'parent' => array(-1, 10, 10), 'sub' => array(-1 => array(10), 10 => array(11, 12)));
        $album_config['album_category_sorting'] = $sorting; $template = new AlbumOrderRendererCapture(); AlbumOrderRenderer\album_display_admin_index();
        ace_check(count($template->rows) === 3 && $template->rows[0]['CAT_ORDER_CONTROLS'] === '', 'Whole recursive renderer retains root without order controls');
        foreach (array(1, 2) as $index) { ace_check($sorting === 'cat_order' ? substr_count($template->rows[$index]['CAT_ORDER_CONTROLS'], 'name="move_category"') === 2 && strpos($template->rows[$index]['CAT_ORDER_CONTROLS'], 'value="' . (10 + $index) . ':-15"') !== false : $template->rows[$index]['CAT_ORDER_CONTROLS'] === '', 'Whole recursive renderer binds POST controls to actual child and global sorting mode'); } $order_cases++;
    }
    $album_config['album_category_sorting'] = 'cat_order'; ace_order_seed(); $before = ace_rows('SELECT * FROM fixture_album_cat ORDER BY cat_id'); $snapshot = $userdata; $album_data = array('fixture' => 'suppressed update');
    ace_sql('CREATE TRIGGER fixture_order_guard BEFORE UPDATE ON fixture_album_cat FOR EACH ROW SET NEW.cat_order=OLD.cat_order');
    try { ace_check(!album_personal_category_order(7, 12, -15) && ace_rows('SELECT * FROM fixture_album_cat ORDER BY cat_id') === $before && $db === $original && $userdata === $snapshot && $album_data === array('fixture' => 'suppressed update'), 'A planned changed-row order UPDATE cannot silently affect zero rows'); }
    finally { ace_sql('DROP TRIGGER fixture_order_guard'); } $order_cases++;
    ace_seed(); ace_sql('DELETE FROM fixture_album_cat WHERE cat_parent<>0'); $values = array();
    for ($i = 0; $i < 10001; $i++) { $values[] = '(' . (100 + $i) . ",'Bulk',''," . ($i + 1) . ',7,10,' . ALBUM_GUEST . ',' . ALBUM_PRIVATE . ')'; }
    ace_sql('INSERT INTO fixture_album_cat(cat_id,cat_title,cat_desc,cat_order,cat_user_id,cat_parent,cat_view_level,cat_upload_level) VALUES' . implode(',', $values)); unset($values);
    $before = ace_rows('SELECT cat_id,cat_order FROM fixture_album_cat ORDER BY cat_id'); ace_check(!album_personal_category_order(7, 101, -15) && ace_rows('SELECT cat_id,cat_order FROM fixture_album_cat ORDER BY cat_id') === $before, 'Oversized sibling scope refuses before any writes rather than exhausting or partially changing an unbounded request'); $order_cases++;
    echo 'Native Album category order: ', $order_cases, " actual POST/current-grant/SQL-fault/locked-sibling/caller-TX/process-concurrency/scope/SQL-mode/no-op/GET-refusal cases PASS; synthetic loopback only.\n";
}
finally
{
    foreach ($children as $entry) { if (!$entry) { continue; } $status = proc_get_status($entry[0]); if ($status['running']) { proc_terminate($entry[0]); } foreach (array(1, 2) as $pipe) { if (is_resource($entry[1][$pipe])) { fclose($entry[1][$pipe]); } } proc_close($entry[0]); }
    if ($db) { $db->sql_close(); }
    if ($created) { ace_check(preg_match('/^codex_album_cat_edit_[a-f0-9]{16}$/D', $schema) === 1, 'Exact owned schema cleanup'); $control->sql_query('DROP DATABASE ' . $schema); }
    $control->sql_close();
    if (is_dir($workers)) { ace_check(!is_link($workers), 'Only own worker directory cleanup'); foreach (array('holder-locked', 'peer-attempt') as $name) { if (is_file($workers . '/' . $name)) { unlink($workers . '/' . $name); } } rmdir($workers); }
    restore_error_handler(); error_reporting($reporting);
}
