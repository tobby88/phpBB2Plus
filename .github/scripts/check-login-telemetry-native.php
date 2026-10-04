<?php
// Full credential controller, actual CT loader, owned native session/history/IP
// publication. Only transport and controlled SQL failures are substituted.
if (PHP_SAPI !== 'cli' || getenv('PHPBB_LOGIN_TELEMETRY_NATIVE') !== '1') {
    echo "Login telemetry checks require an explicitly enabled disposable database.\n"; return;
}
putenv('PHPBB_LOGIN_PUBLICATION_NATIVE=1');
putenv('PHPBB_LOGIN_PUBLICATION_PORT=' . (getenv('PHPBB_LOGIN_TELEMETRY_PORT') ?: '3306'));
putenv('PHPBB_LOGIN_PUBLICATION_PASSWORD=' . (getenv('PHPBB_LOGIN_TELEMETRY_PASSWORD') ?: ''));
$library = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/check-login-publication-native.php'));
$cut = strpos($library, "\ntry{\n set_error_handler");
if ($cut === false) { throw new RuntimeException('Native login fixture boundary missing'); }
eval(substr(str_replace('__DIR__', var_export(__DIR__, true), substr($library, 0, $cut)), 5));
define('CTRACKER_CONFIG', 'fixture_ctracker_config');
define('CTRACKER_LOGINHISTORY', 'fixture_ctracker_loginhistory');
require $ats_source . 'ctracker/classes/class_ct_database.php';
require $ats_source . 'language/lang_english/lang_cback_ctracker.php';

function telemetry_login($options = array())
{
    $setup = isset($options['setup']) ? $options['setup'] : null;
    $options['setup'] = function () use ($options, $setup) {
        lp_query('DELETE FROM fixture_ctracker_config'); lp_query('DELETE FROM fixture_ctracker_loginhistory');
        $settings = isset($options['tracking']) ? $options['tracking'] : array('login_history' => 1, 'login_history_count' => 2, 'login_ip_check' => 1);
        foreach ($settings as $key => $value) { lp_insert('fixture_ctracker_config', array('ct_config_name' => $key, 'ct_config_value' => $value)); }
        lp_query("UPDATE fixture_users SET ct_last_ip='192.0.2.1',ct_last_used_ip='192.0.2.2' WHERE user_id=2");
        $GLOBALS['HTTP_SERVER_VARS'] = array('REMOTE_ADDR' => isset($options['ip']) ? $options['ip'] : '192.0.2.3');
        $GLOBALS['ctracker_config'] = new ct_database();
        if ($setup !== null) { call_user_func($setup); }
    };
    return lp_login(false, $options);
}
function telemetry_snapshot()
{
    return array('users' => lp_rows('SELECT user_id,user_password,user_badlogin,user_passwd_change,ct_last_ip,ct_last_used_ip FROM fixture_users ORDER BY user_id'),
        'sessions' => lp_rows('SELECT session_id,session_user_id,session_logged_in,session_admin FROM fixture_sessions ORDER BY session_id'),
        'keys' => lp_rows('SELECT key_id,user_id FROM fixture_sessions_keys ORDER BY user_id,key_id'),
        'history' => lp_rows('SELECT ct_login_id,ct_user_id,ct_login_ip,ct_login_time FROM fixture_ctracker_loginhistory ORDER BY ct_login_id'));
}
try {
    set_error_handler(function ($s, $m) { if (error_reporting() & $s) { throw new RuntimeException($m); } });
    $schema = file_get_contents($ats_source . 'install/schemas/mysql_schema.sql');
    foreach (array('users','sessions','sessions_keys','banlist','config','ctracker_config','ctracker_loginhistory') as $table) {
        ats_check(preg_match('/CREATE TABLE `?phpbb_' . $table . '`?\s*\([\s\S]*?;/', $schema, $m) === 1, 'Canonical telemetry participant');
        lp_query(str_replace('phpbb_' . $table, 'fixture_' . $table, $m[0]));
    }
    $cases = $serialized = 0;
    foreach (array('', 'NO_BACKSLASH_ESCAPES', 'ANSI_QUOTES') as $mode) {
        $main->sql_query("SET SESSION sql_mode='$mode'"); lp_query("SET SESSION sql_mode='$mode'");
        foreach (array(0,1) as $history) { foreach (array(0,1) as $ip_check) { foreach (array('192.0.2.3','2001:db8::3') as $ip) {
            ats_check(telemetry_login(array('ip' => $ip, 'tracking' => array('login_history' => $history, 'login_history_count' => 2, 'login_ip_check' => $ip_check))) === 'published', 'Actual telemetry login in all SQL modes');
            $rows = lp_rows('SELECT ct_login_ip FROM fixture_ctracker_loginhistory');
            ats_check(count($rows) === $history && (!$history || $rows[0]['ct_login_ip'] === $ip), 'Only enabled byte-exact history is recorded');
            $row = lp_rows('SELECT ct_last_ip,ct_last_used_ip FROM fixture_users WHERE user_id=2')[0];
            ats_check($row['ct_last_ip'] === ($ip_check ? '192.0.2.2' : '192.0.2.1') && $row['ct_last_used_ip'] === ($ip_check ? $ip : '192.0.2.2'), 'Atomic enabled IP pair');
            $cases++;
        } } }
    }
    $main->sql_query("SET SESSION sql_mode=''"); lp_query("SET SESSION sql_mode=''");
    $before = null;
    $record = function () use (&$before) { $before = telemetry_snapshot(); };
    ats_check(telemetry_login(array('persistent' => 1, 'rehash' => 1, 'changed_at' => 0, 'setup' => $record)) === 'published', 'Record complete force-change/rehash/session/telemetry path');
    $total = $lp_query_count;
    for ($at = 1; $at <= $total; $at++) {
        ats_check(telemetry_login(array('persistent' => 1, 'rehash' => 1, 'changed_at' => 0, 'failure' => $at, 'setup' => $record)) === 'denied', 'Every owned telemetry failure denies login');
        ats_check($before === telemetry_snapshot() && $lp_cookies === array() && $lp_open === 0 && $db === $main, 'Each failure rolls back the complete account/session/key/history/IP batch'); $cases++;
    }
    foreach (array('before','ack') as $failure) {
        ats_check(telemetry_login(array('persistent' => 1, 'commit' => $failure, 'setup' => $record)) === 'denied' && $lp_cookies === array(), 'Unconfirmed telemetry commit cannot deliver cookies');
        if ($failure === 'before') { ats_check($before === telemetry_snapshot(), 'Failed commit rolls back every participant'); }
        else { ats_check(count(lp_rows('SELECT session_id FROM fixture_sessions WHERE session_user_id=2')) === 1 && count(lp_rows('SELECT ct_login_id FROM fixture_ctracker_loginhistory')) === 1, 'Lost reply may leave complete committed batch, never cookies'); }
        $cases++;
    }
    foreach (array('login_history' => 0, 'login_history_count' => 60, 'login_ip_check' => 0) as $key => $value) {
        $fired = false;
        $hook = function ($sql) use (&$fired, $key, $value) { if (!$fired && strpos($sql, 'SELECT ct_config_name,ct_config_value') === 0) { $fired = true; lp_query("UPDATE fixture_ctracker_config SET ct_config_value='$value' WHERE ct_config_name='$key'"); } };
        ats_check(telemetry_login(array('hook' => $hook)) === 'denied' && $fired && $lp_cookies === array() && lp_rows('SELECT ct_login_id FROM fixture_ctracker_loginhistory') === array(), 'Stale CT policy cannot publish partial or skipped tracking'); $cases++;
    }
    $fired = false;
    $hook = function ($sql) use (&$fired) { if (!$fired && strpos($sql, 'SELECT ct_config_name,ct_config_value') === 0) { $fired = true; lp_query("UPDATE fixture_ctracker_config SET ct_config_value='1' WHERE ct_config_name='login_history'"); } };
    ats_check(telemetry_login(array('tracking' => array('login_history' => 0, 'login_history_count' => 2, 'login_ip_check' => 0), 'hook' => $hook)) === 'denied' && $fired && $lp_cookies === array(), 'Cached disabled tracking cannot bypass currently enabled policy'); $cases++;
    foreach (array(array(), array('login_history' => 'bad', 'login_history_count' => 0, 'login_ip_check' => 'bad')) as $settings) {
        ats_check(telemetry_login(array('tracking' => $settings)) === 'published' && count(lp_rows('SELECT ct_login_id FROM fixture_ctracker_loginhistory')) === 1, 'Actual loader and held policy retain missing/invalid legacy defaults'); $cases++;
    }
    lp_query('SET SESSION innodb_lock_wait_timeout=0');
    foreach (array("UPDATE fixture_ctracker_config SET ct_config_value='0' WHERE ct_config_name='login_history'", 'DELETE FROM fixture_ctracker_loginhistory WHERE ct_user_id=2', "UPDATE fixture_users SET user_password='replacement' WHERE user_id=2", 'DELETE FROM fixture_sessions WHERE session_user_id=2') as $change) {
        $fired = $blocked = false;
        $hook = function ($sql) use (&$fired, &$blocked, $change) {
            if (!$fired && $sql === 'COMMIT') { $fired = true; if (!$GLOBALS['peer']->sql_query($change)) { $error = $GLOBALS['peer']->sql_error(); ats_check((int)$error['code'] === 1205, 'Actual concurrent writer times out on held publication'); $blocked = true; } }
        };
        ats_check(telemetry_login(array('hook' => $hook)) === 'published' && $fired && $blocked, 'Current CT policy/history/reset/session writer serializes after complete login'); lp_query($change); $cases++; $serialized++;
    }
    lp_query('SET SESSION innodb_lock_wait_timeout=1');
    foreach (array(1,60) as $limit) {
        $setup = function () { for ($i = 1; $i <= 62; $i++) { lp_insert('fixture_ctracker_loginhistory', array('ct_login_id' => (string)(4294967296 + $i), 'ct_user_id' => 2, 'ct_login_ip' => '192.0.2.8', 'ct_login_time' => 1)); } lp_insert('fixture_ctracker_loginhistory', array('ct_user_id' => 3, 'ct_login_ip' => '192.0.2.9', 'ct_login_time' => 1)); };
        ats_check(telemetry_login(array('tracking' => array('login_history' => 1, 'login_history_count' => $limit, 'login_ip_check' => 1), 'setup' => $setup)) === 'published', 'Stable large-ID history retention commits');
        $rows = lp_rows('SELECT ct_login_id FROM fixture_ctracker_loginhistory WHERE ct_user_id=2 ORDER BY ct_login_id DESC');
        ats_check(count($rows) === $limit && count(lp_rows('SELECT ct_login_id FROM fixture_ctracker_loginhistory WHERE ct_user_id=3')) === 1, 'Keep configured history count and unrelated account');
        if ($limit === 60) { ats_check((string)$rows[count($rows)-1]['ct_login_id'] === '4294967300', 'Equal-time retention uses exact bigint ID without 32-bit truncation'); } $cases++;
    }
    foreach (array('ctracker_config','ctracker_loginhistory') as $suffix) {
        lp_query('ALTER TABLE fixture_' . $suffix . ' ENGINE=MyISAM');
        ats_check(telemetry_login() === 'denied' && $lp_cookies === array(), 'Refuse noncanonical CT storage before publication');
        lp_query('ALTER TABLE fixture_' . $suffix . ' ENGINE=InnoDB ROW_FORMAT=DYNAMIC'); $cases++;
    }
    lp_query('CREATE TABLE fixture_caller_marker(id INT PRIMARY KEY) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    foreach (array(false,true) as $reuse) {
        ats_check($main->sql_query('START TRANSACTION'), 'Start actual caller transaction');
        $lp_fixture_write = true; ats_check($main->sql_query('INSERT INTO fixture_caller_marker VALUES(1)'), 'Own uncommitted caller marker'); $lp_fixture_write = false;
        ats_check(telemetry_login(array('reuse' => $reuse)) === ($reuse ? 'denied' : 'published'), 'Dedicated telemetry owner or refused reused factory');
        ats_check(lp_rows('SELECT id FROM fixture_caller_marker') === array() && $main->sql_query('ROLLBACK') && lp_rows('SELECT id FROM fixture_caller_marker') === array(), 'Publication/refusal does not commit/close caller transaction'); $cases++;
    }
    foreach (array('invalid-ip','missing-tracker') as $kind) {
        $setup = function () use ($kind, &$before) { if ($kind === 'invalid-ip') { $GLOBALS['ctracker_config']->user_ip_value = 'invalid-address'; } else { $GLOBALS['ctracker_config'] = null; } $before = telemetry_snapshot(); };
        ats_check(telemetry_login(array('persistent' => 1, 'setup' => $setup)) === 'denied' && $before === telemetry_snapshot() && $lp_cookies === array(), 'Explicit new tracking context cannot silently disappear or accept invalid address'); $cases++;
    }
    $old_body = $lp_body;
    $new_call = 'phpbb_login_session($db, $row, $user_ip, PAGE_INDEX, $autologin, $admin, $upgraded_password, $password, $ctracker_config)';
    ats_check(substr_count($lp_body, $new_call) === 1, 'Actual current controller always supplies tracking context');
    try {
        $lp_body = str_replace($new_call, 'phpbb_login_session($db, $row, $user_ip, PAGE_INDEX, $autologin, $admin, $upgraded_password, $password)', $lp_body);
        ats_check(telemetry_login() === 'published' && lp_rows('SELECT ct_login_id FROM fixture_ctracker_loginhistory') === array(), 'Helper-first staged eight-argument API does not duplicate old controller telemetry'); $cases++;
    } finally { $lp_body = $old_body; }
    foreach (array('ctracker_config','ctracker_loginhistory') as $suffix) {
        $fired = $blocked = false;
        $hook = function ($sql) use (&$fired, &$blocked, $suffix) {
            if (!$fired && $sql === 'COMMIT') { $fired = true; lp_query('SET SESSION lock_wait_timeout=1');
                if (!$GLOBALS['peer']->sql_query('ALTER TABLE fixture_' . $suffix . ' ENGINE=MyISAM')) { $error = $GLOBALS['peer']->sql_error(); ats_check((int)$error['code'] === 1205, 'Held metadata blocks engine substitution'); $blocked = true; }
            }
        };
        ats_check(telemetry_login(array('hook' => $hook)) === 'published' && $fired && $blocked, 'No DDL change can escape held telemetry metadata'); $cases++; $serialized++;
    }
    foreach (array('password','inactive','session','key') as $kind) {
        $change = $kind === 'password' ? "UPDATE fixture_users SET user_password='replacement' WHERE user_id=2" : ($kind === 'inactive' ? 'UPDATE fixture_users SET user_active=0 WHERE user_id=2' : ($kind === 'session' ? "DELETE FROM fixture_sessions WHERE session_id='" . str_repeat('a',32) . "'" : 'DELETE FROM fixture_sessions_keys WHERE user_id=2'));
        $fired = false;
        $hook = function ($sql) use (&$fired, $kind, $change) { $point = $kind === 'password' || $kind === 'inactive' ? 'SELECT * FROM fixture_users WHERE user_id=2 FOR UPDATE' : 'SELECT ct_config_name,ct_config_value'; if (!$fired && strpos($sql, $point) === 0) { $fired = true; lp_query($change); } };
        // Deleting an old device key is not a revocation of a freshly supplied
        // password; it must still yield a complete manually verified login.
        $expected = $kind === 'key' ? 'published' : 'denied';
        ats_check(telemetry_login(array('existing_cookie' => 1, 'hook' => $hook)) === $expected && $fired, 'Current credential/caller revocation semantics with enabled telemetry');
        if ($expected === 'denied') { ats_check($lp_cookies === array() && lp_rows('SELECT ct_login_id FROM fixture_ctracker_loginhistory') === array(), 'Revoked login cannot add telemetry'); } $cases++;
    }
    // Execute the actual controller capability guard with only the missing
    // helper capability substituted; it must precede the publication call.
    ats_check(preg_match("/^\\s*if \\(defined\\('CTRACKER_CONFIG'\\) && !function_exists\\('phpbb_login_tracker_write'\\)\\) \\{[^\\r\\n]+\\}$/m", $lp_body, $guard) === 1 && strpos($lp_body, trim($guard[0])) < strpos($lp_body, $new_call), 'Actual controller-first guard precedes owned dispatch');
    eval('namespace LoginTelemetryInterop; function function_exists($name) { return false; }');
    $denied = false; try { eval('namespace LoginTelemetryInterop;' . $guard[0]); } catch (AttachSettingsExit $e) { $denied = true; }
    ats_check($denied, 'Controller-first update refuses missing telemetry helper before a session can be published'); $cases++;
    foreach (array('invalid', array(array()), array('fixture_users;DELETE'), array(str_repeat('a',65))) as $participants) {
        $before = telemetry_snapshot(); $denied = false;
        try { new PhpbbLoginDatabase($main, $participants); } catch (PhpbbLoginException $e) { $denied = true; }
        ats_check($denied && $lp_open === 0 && telemetry_snapshot() === $before, 'Malformed optional participants cannot skip mandatory canonical storage checks'); $cases++;
    }
    echo 'Native login telemetry: ' . $cases . ' publication/failure/policy/retention cases and ' . $serialized . " serialized peer writes passed.\n";
} finally { $main->sql_close(); $peer->sql_close(); $control->sql_query('DROP DATABASE ' . $fixture); $control->sql_close(); restore_error_handler(); }
