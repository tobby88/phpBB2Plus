<?php
// Actual complete credential controller, session reader and owned native driver.
// No live configuration, account, cookie transport or ban list is used.
if (PHP_SAPI !== 'cli' || getenv('PHPBB_EMAIL_BAN_NATIVE') !== '1') { echo "Native email-ban checks require an explicitly enabled disposable database.\n"; return; }
putenv('PHPBB_LOGIN_PUBLICATION_NATIVE=1');
putenv('PHPBB_LOGIN_PUBLICATION_PORT=' . (getenv('PHPBB_EMAIL_BAN_PORT') ?: '3306'));
putenv('PHPBB_LOGIN_PUBLICATION_PASSWORD=' . (getenv('PHPBB_EMAIL_BAN_PASSWORD') ?: ''));
$file = __DIR__ . '/check-login-publication-native.php'; $source = file_get_contents($file);
$cut = strpos($source, "\ntry{\n set_error_handler");
if ($cut === false) { throw new RuntimeException('Login fixture setup boundary changed'); }
eval(substr(str_replace('__DIR__', var_export(__DIR__, true), substr($source, 0, $cut)), 5));
$cases = $serialized = 0;
try {
    set_error_handler(function($severity, $message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } });
    $schema = file_get_contents($ats_source . 'install/schemas/mysql_schema.sql');
    foreach (array('users','sessions','sessions_keys','banlist','config') as $table) {
        ats_check(preg_match('/CREATE TABLE `?phpbb_' . $table . '`?\s*\([\s\S]*?;/', $schema, $m) === 1, 'Canonical email-ban participant');
        lp_query(str_replace('phpbb_' . $table, 'fixture_' . $table, $m[0]));
    }
    $rules = array(
        array('member@example.invalid', '*@example.invalid', true),
        array('member@example.invalid', '@example.invalid', true),
        array('member@example.invalid', 'member@example.invalid', true),
        array('MEMBER@EXAMPLE.INVALID', '*@example.invalid', true),
        array('member@other.invalid', '*@example.invalid', false),
        array('member@sub.example.invalid', '@example.invalid', false),
        array('a_b@example.invalid', 'axb@example.invalid', false),
        array('a%b@example.invalid', 'axxb@example.invalid', false),
        array('a_b@example.invalid', 'a_b@example.invalid', true),
        array('a%b@example.invalid', 'a%b@example.invalid', true),
        array('axb@example.invalid', 'a.b@example.invalid', false),
        array('ab@example.invalid', 'a+b@example.invalid', false),
        array('a+b@example.invalid', 'a+b@example.invalid', true),
        array("o'reilly@example.invalid", "o'reilly@example.invalid", true),
        array('member@example.invalid', null, false),
        array('member@example.invalid', '', false)
    );
    foreach (array('', 'NO_BACKSLASH_ESCAPES', 'ANSI_QUOTES') as $mode) {
        $main->sql_query("SET SESSION sql_mode='$mode'"); lp_query("SET SESSION sql_mode='$mode'");
        foreach (array('manual','automatic','acp') as $kind) {
            foreach ($rules as $case) {
                $setup = function() use ($case) {
                    // An unrelated mixed rule must not become a false IP/user ban
                    // just because it participates in the email candidate scan.
                    lp_insert('fixture_banlist', array('ban_userid'=>3,'ban_ip'=>'0a000001','ban_email'=>'unrelated@example.invalid'));
                    lp_insert('fixture_banlist', array('ban_userid'=>0,'ban_ip'=>'','ban_email'=>$case[1]));
                };
                $out = lp_login(false, array('email'=>$case[0], 'setup'=>$setup, 'automatic'=>$kind==='automatic', 'admin'=>$kind==='acp', 'persistent'=>1));
                ats_check($out === ($case[2] ? 'denied' : 'published'), 'Actual '.$kind.' session email-ban outcome');
                if ($case[2]) {
                    ats_check($lp_error === 'You_been_banned' && $lp_cookies === array() && $lp_open === 0, 'Matched ban stops before cookie publication');
                    ats_check(lp_rows('SELECT session_id FROM fixture_sessions WHERE session_user_id=2') === array(), 'No banned registered/ACP session persisted');
                    ats_check(count(lp_rows('SELECT key_id FROM fixture_sessions_keys WHERE user_id=2')) === ($kind==='automatic'?1:0), 'Denied attempt cannot create or rotate persistence');
                } else {
                    ats_check(count($lp_cookies) === 2 && $lp_open === 0, 'Unrelated rule leaves normal confirmed publication usable');
                }
                $cases++;
            }
        }
    }
    foreach (array('manual','automatic') as $kind) {
        foreach (array('user','ip') as $ban) {
            $setup = function() use ($ban) { lp_insert('fixture_banlist', array('ban_userid'=>$ban==='user'?2:0, 'ban_ip'=>$ban==='ip'?'7f000001':'', 'ban_email'=>null)); };
            ats_check(lp_login(false, array('setup'=>$setup,'automatic'=>$kind==='automatic')) === 'denied' && $lp_cookies === array(), 'Existing user/IP bans remain enforced'); $cases++;
        }
        $seen = false;
        $hook = function($sql) use (&$seen) {
            if (!$seen && strpos($sql,'SELECT config_name,config_value FROM fixture_config LOCK IN SHARE MODE') === 0) {
                $seen = true; lp_insert('fixture_banlist', array('ban_userid'=>0,'ban_ip'=>'','ban_email'=>'*@example.invalid'));
            }
        };
        ats_check(lp_login(false, array('hook'=>$hook,'automatic'=>$kind==='automatic')) === 'denied' && $seen && $lp_cookies === array(), 'Already-current wildcard rule prevents owned publication'); $cases++;
        $seen = false; lp_query('SET SESSION innodb_lock_wait_timeout=1');
        $hook = function($sql) use (&$seen) {
            if ($sql === 'COMMIT') {
                $seen = true; $r = $GLOBALS['peer']->sql_query("INSERT INTO fixture_banlist(ban_userid,ban_ip,ban_email)VALUES(0,'','*@example.invalid')"); $error = $GLOBALS['peer']->sql_error();
                ats_check(!$r && (int)$error['code'] === 1205, 'Concurrent wildcard rule waits until login commits');
            }
        };
        ats_check(lp_login(false, array('hook'=>$hook,'automatic'=>$kind==='automatic')) === 'published' && $seen, 'Ban state serialized through exact session publication'); $serialized++;
    }
    // Inject failure at the real candidate reader, not merely the preliminary
    // range lock. No capability may survive either manual or automatic failure.
    foreach (array('manual','automatic') as $kind) {
        ats_check(lp_login(false,array('automatic'=>$kind==='automatic')) === 'published','Record candidate-reader boundary');
        $at = 0;
        foreach ($lp_owner_queries as $i=>$sql) { if (strpos($sql,'SELECT ban_ip, ban_userid, ban_email') === 0) { $at=$i+1; break; } }
        ats_check($at>0,'Actual candidate reader observed');
        ats_check(lp_login(false,array('automatic'=>$kind==='automatic','failure'=>$at)) === 'denied' && $lp_cookies===array() && $lp_open===0, 'Failed actual reader refuses login'); $cases++;
    }
    echo 'Native email-ban matching: '.$cases.' request/reader cases and '.$serialized." serialized publications passed.\n";
} finally {
    $main->sql_close(); $peer->sql_close(); $control->sql_query('DROP DATABASE '.$fixture); $control->sql_close(); restore_error_handler();
}
