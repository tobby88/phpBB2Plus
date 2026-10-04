<?php
// Complete production public registration projection and owned native scope.
if (PHP_SAPI !== 'cli' || getenv('PHPBB_EMAIL_BAN_NATIVE') !== '1') { echo "Native registration email-ban checks require an explicitly enabled disposable database.\n"; return; }
putenv('PHPBB_REGISTRATION_NATIVE=1');
putenv('PHPBB_REGISTRATION_PORT=' . (getenv('PHPBB_EMAIL_BAN_PORT') ?: '3306'));
putenv('PHPBB_REGISTRATION_PASSWORD=' . (getenv('PHPBB_EMAIL_BAN_PASSWORD') ?: ''));
$file = __DIR__ . '/check-registration-native.php'; $source = file_get_contents($file);
$cut = strpos($source, " foreach(array(0,1,2,3) as \$mode)");
if ($cut === false) { throw new RuntimeException('Registration fixture setup boundary changed'); }
$head = str_replace('__DIR__', var_export(__DIR__, true), substr($source, 5, $cut - 5));
$tail = <<<'PHP'
 email_ban_registration_suite();
} finally {
 $rgn_hook=$rgn_after=null;$rgn_fail=0;$rgn_commit='';$rgn_main->sql_close();$peer->sql_close();$control->sql_query('DROP DATABASE '.$fixture);$control->sql_close();restore_error_handler();
}
PHP;
function email_ban_registration_suite() {
    global $rgn_main, $rgn_mails, $rgn_avatar, $rgn_open, $lang;
    $cases = 0;
    $rules = array(
        array('new@example.invalid', '*@example.invalid', true),
        array('new@example.invalid', '@example.invalid', true),
        array('new@example.invalid', 'NEW@EXAMPLE.INVALID', true),
        array('new@other.invalid', '*@example.invalid', false),
        array('n_w@example.invalid', 'nxw@example.invalid', false),
        array('nxw@example.invalid', 'n.w@example.invalid', false),
        array('n.w@example.invalid', 'n.w@example.invalid', true),
        array('n+w@example.invalid', 'n+w@example.invalid', true),
        array('nw@example.invalid', 'n+w@example.invalid', false),
        array("o'reilly@example.invalid", "o'reilly@example.invalid", true),
        array('new@example.invalid', null, false)
    );
    foreach (array('', 'NO_BACKSLASH_ESCAPES', 'ANSI_QUOTES') as $mode) {
        $rgn_main->sql_query("SET SESSION sql_mode='$mode'"); rgn_sql("SET SESSION sql_mode='$mode'");
        foreach ($rules as $case) {
            rgn_reset(0, 'confirm');
            rgn_sql(rgn_insert_sql('fixture_banlist', array('ban_email'=>null)));
            rgn_sql(rgn_insert_sql('fixture_banlist', array('ban_email'=>'unrelated@example.invalid')));
            rgn_sql(rgn_insert_sql('fixture_banlist', array('ban_email'=>$case[1])));
            $before = rgn_snap(); $out = rgn_run('New fixture', $case[0]);
            ats_check(rgn_success($out) === !$case[2] && $rgn_open === 0, 'Actual registration email rule matches literal address');
            if ($case[2]) {
                ats_check($out === $lang['Email_banned'] && rgn_snap() === $before && !$rgn_mails && !$rgn_avatar->confirmed, 'Denied registration retains users/challenge/cooldown and sends no mail');
            } else {
                ats_check(rgn_rows('SELECT user_email FROM fixture_users WHERE user_id=8')[0]['user_email'] === $case[0] && count($rgn_mails) === 1, 'Accepted address preserved byte-exactly in confirmed account');
            }
            $cases++;
        }
    }
    echo 'Native registration email-ban matching: '.$cases." complete publication cases passed.\n";
}
eval($head . $tail);
