<?php
// Exercise the production identity reader, not a mock which ignores WHERE.
putenv('PHPBB_ATTACH_SETTINGS_NATIVE=0');
require __DIR__ . '/check-attachment-settings-storage.php';
define('ANONYMOUS', -1);
require __DIR__ . '/profile-request-fixture.php';
ats_load_function($ats_source . 'admin/admin_users.php', 'admin_user_post_string');
foreach (array('phpbb_rtrim', 'phpbb_clean_username', 'get_userdata') as $name) {
    ats_load_function($ats_source . 'includes/functions.php', $name);
}
if (PHP_SAPI !== 'cli' || getenv('PHPBB_USERDATA_NATIVE') !== '1') {
    echo "Identity reader checks require an explicitly enabled disposable database.\n";
    return;
}
require $ats_source . 'db/mysqli.php';
class UserdataNativeDatabase extends sql_db {
    var $identity_queries = 0;
    var $fail_identity = false;
    function sql_query($sql = '', $transaction = false) {
        if (strpos($sql, 'FROM ' . USERS_TABLE) !== false && strpos($sql, 'WHERE ') !== false) {
            $this->identity_queries++;
            if ($this->fail_identity) { return false; }
        }
        return parent::sql_query($sql, $transaction);
    }
}
$port = getenv('PHPBB_USERDATA_PORT') ?: '3306';
ats_check(preg_match('/^[0-9]{1,5}$/D', $port) && (int)$port > 0 && (int)$port <= 65535, 'Loopback fixture port');
$password = getenv('PHPBB_USERDATA_PASSWORD') ?: '';
$host = '127.0.0.1:' . $port;
$fixture = 'codex_userdata_' . bin2hex(phpbb_random_bytes(8));
$control = new sql_db($host, 'root', $password, '', false);
ats_check($control->sql_query('CREATE DATABASE ' . $fixture . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'), 'Owned schema');
$db = null;
try {
    $db = new UserdataNativeDatabase($host, 'root', $password, $fixture, false);
    ats_check($db->sql_query('CREATE TABLE fixture_users (user_id INT PRIMARY KEY, username VARCHAR(25) NOT NULL) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'), 'Canonical identity fixture');
    $cases = 0;
    foreach (array('', 'NO_BACKSLASH_ESCAPES', 'ANSI_QUOTES,NO_BACKSLASH_ESCAPES') as $mode) {
        ats_check($db->sql_query("SET SESSION sql_mode='" . $mode . "'"), 'Actual SQL mode');
        foreach (array(false, true) as $adapted) {
            ats_check($db->sql_query('DELETE FROM fixture_users'), 'Reset owned rows');
            $expected = array();
            $inputs = array('Plain', 'Grüße', 'A\\B', 'X\\nY', 'Double\\\\Path', "O'Reilly", 'A&B', '123', 'A\\%B', 'A\\_B');
            $id = 10;
            foreach ($inputs as $input) {
                $request = $input;
                $stored = phpbb_clean_username($input);
                ats_check($db->sql_query("INSERT INTO fixture_users VALUES ($id,'" . $db->sql_escape($stored) . "')"), 'Store existing identity');
                $expected[$id] = $request;
                $id++;
            }
            // The former hand-built literal A\B resolves to this different row
            // under ordinary MySQL escaping instead of the requested identity.
            ats_check($db->sql_query("INSERT INTO fixture_users VALUES (99,'AB'),(-1,'Guest')"), 'Different account and anonymous sentinel');
            foreach ($expected as $id => $request) {
                if ($adapted) { profile_fixture_request(array('username'=>$request)); $request=phpbb_request_raw_value($_POST['username']); }
                $row = get_userdata($request, true);
                ats_check(is_array($row) && (int)$row['user_id'] === $id, 'Exact current stored identity, SQL mode=' . $mode . ', adapted=' . (int)$adapted . ', id=' . $id);
                ats_check($db->query_result === false, 'Identity result released on successful lookup');
                $cases++;
            }
            // Evaluate the actual caller expressions as well: normalization
            // belongs to get_userdata, not its callers, and numeric names are
            // names in ACP account creation, not references to account IDs.
            $admin_source = file_get_contents($ats_source . 'admin/admin_users.php');
            ats_check(preg_match('/if \((get_userdata\(admin_user_post_string\(\'username\'\), true\))\)/', $admin_source, $match) === 1, 'Actual creation identity expression');
            profile_fixture_request(array('username'=>$expected[17]));
            $creation_row = eval('return ' . $match[1] . ';');
            ats_check((int)$creation_row['user_id'] === 17, 'Numeric account name is not mistaken for account ID');
            if (!$adapted) {
                $tracker_source = file_get_contents($ats_source . 'ctracker/admin/acp_module_miserableuser.php');
                ats_check(preg_match('/\$this_userdata = get_userdata\([^;]+;/', $tracker_source, $match) === 1, 'Actual tracker identity expression');
                $_POST = array('username' => 'A&B');
                eval($match[0]);
                ats_check((int)$this_userdata['user_id'] === 16, 'Tracker resolves ampersand identity without double entity encoding');
                $ajax_source = file_get_contents($ats_source . 'ajax.php');
                $start = strpos($ajax_source, "else if ((\$mode == 'checkusername_pm')");
                $a = strpos($ajax_source, '$username_input = $username;', $start);
                $b = strpos($ajax_source, '$username_row = False;', $a);
                ats_check($start !== false && $a !== false && $b > $a, 'Actual AJAX identity preprocessing');
                $mode = 'search_user'; $username = 'A&B';
                eval(substr($ajax_source, $a, $b - $a));
                $result = $db->sql_query("SELECT user_id FROM fixture_users WHERE username='$username_sql'");
                ats_check($result && (int)$db->sql_fetchrow($result)['user_id'] === 16, 'AJAX search resolves same identity after exactly one normalization');
                $db->sql_freeresult($result);
            }
            unset($expected);
            ats_check((int)get_userdata(99)['user_id'] === 99, 'Numeric ID remains an ID');
            ats_check(get_userdata(-1) === false && get_userdata('Guest', true) === false, 'Anonymous remains excluded');
            ats_check(get_userdata('missing', true) === false, 'Unknown identity');
            ats_check($db->query_result === false, 'Identity result released on missing lookup');
            $queries_before = $db->identity_queries;
            foreach (array(array(), null, false, new stdClass()) as $invalid) {
                ats_check(get_userdata($invalid, true) === false, 'Malformed identity refuses lookup');
            }
            ats_check($queries_before === $db->identity_queries, 'Malformed input never reaches the database');
            $db->fail_identity = true; $failed = false;
            try { get_userdata('Plain', true); } catch (AttachSettingsExit $e) { $failed = true; }
            $db->fail_identity = false;
            ats_check($failed, 'SQL failure does not pretend to return a valid account');
            ats_check($db->sql_query('SELECT COUNT(*) AS n FROM fixture_users'), 'Reader does not damage schema');
            $count = $db->sql_fetchrow();
            ats_check((int)$count['n'] === 12, 'All account rows unchanged');
        }
    }
    // A reader must neither commit nor roll back a caller's pending work.
    ats_check($db->sql_query('START TRANSACTION'), 'Caller transaction');
    ats_check($db->sql_query("INSERT INTO fixture_users VALUES (120,'Pending')"), 'Uncommitted caller row');
    ats_check((int)get_userdata(120)['user_id'] === 120, 'Reader sees caller snapshot');
    ats_check($control->sql_query('SELECT COUNT(*) AS n FROM ' . $fixture . '.fixture_users WHERE user_id=120'), 'Independent observer');
    ats_check((int)$control->sql_fetchrow()['n'] === 0, 'Reader never publishes caller writes');
    ats_check($db->sql_query('ROLLBACK'), 'Caller still owns transaction');
    ats_check(get_userdata(120) === false, 'Caller rollback still removes pending row');
    echo 'Native identity reader: ' . $cases . " exact-name cases across raw/adapted requests and three SQL modes passed.\n";
} finally {
    if ($db !== null) { $db->sql_close(); }
    $control->sql_query('DROP DATABASE ' . $fixture);
    $control->sql_close();
}
