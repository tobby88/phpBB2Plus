<?php
// Fresh CLI children exercise the actual handlers and distributed dictionary.
// No forum bootstrap, credentials, database, HTTP or persisted test content.
if (PHP_SAPI !== 'cli') { exit(2); }
set_error_handler(function ($severity, $message) {
    if (error_reporting() & $severity) { throw new RuntimeException($message); }
});
define('IN_PHPBB', true);
define('GENERAL_MESSAGE', 0);
$phpbb_root_path = dirname(dirname(__DIR__)) . '/phpBB2/';
$phpEx = 'php';
require $phpbb_root_path . 'ctracker/classes/class_ct_userfunctions.php';
class Utf8SpamRejected extends RuntimeException {}
function message_die($type, $message) { throw new Utf8SpamRejected($message); }
class Utf8SpamNoDatabase {
    function __call($name, $arguments) { throw new RuntimeException('Unexpected database operation: ' . $name); }
}
function utf8_spam_assert($condition, $message) {
    if (!$condition) { throw new RuntimeException($message); }
}
$cases = array();
foreach (array('english', 'german') as $locale) {
    foreach (array('message', 'subject') as $field) {
        foreach (array(
            array('viagra', true),
            array("vi\xC2\xADagra", true),
            array("\xC2\xADV\xC2\xADI\xC2\xADAGRA\xC2\xAD", true),
            array("first\nvi\xC2\xADagra\nlast", true),
            array("online\xC2\xAD something roulette", true),
            array("Grüße, café, Кириллица, 漢字, 😀", false),
            array("Silben\xC2\xADtrennung", false),
            array("vi\xC3\xADagra", false),
            array('normal message', false),
            array(array('viagra'), false)
        ) as $sample) {
            $cases[] = array($locale, 'post', $field, $sample[0], $sample[1], array());
        }
    }
    foreach (array('register', 'editprofile') as $mode_case) {
        foreach (array('aim','msn','yim','website','location','occupation','interests','signature') as $field) {
			$capacity = in_array($field, array('website','location','occupation'), true) ? 100 : 255;
            foreach (array(
                array('viagra', true),
                array("vi\xC2\xADagra", true),
                array("\xC2\xADVi\xC2\xADa\xC2\xADgra\xC2\xAD", true),
                array("Grüße und Silben\xC2\xADtrennung 😀", false),
                array("vi\xC3\xADagra", false),
                array(str_repeat("\xF0\x9F\x98\x80", 64) . 'viagra', true),
                array(str_repeat("\xF0\x9F\x98\x80", $capacity - 6) . 'viagra', true),
                array(str_repeat("\xF0\x9F\x98\x80", 63) . "xxvi\xC2\xADagra", true),
                array(str_repeat("\xF0\x9F\x98\x80", 64) . 'ordinary', false),
                array(array('viagra'), false)
            ) as $sample) {
                $cases[] = array($locale, $mode_case, $field, $sample[0], $sample[1], array());
            }
        }
    }
    // Keep the administrator's existing switches and historical role policy.
    foreach (array(
        array('post', 'message', array('spam_keyword_det'=>1)),
        array('post', 'subject', array('spam_keyword_det'=>0)),
        array('post', 'message', array('spam_attack_boost'=>0)),
        array('post', 'message', array('user_posts'=>2)),
        array('post', 'message', array('user_level'=>1)),
        array('post', 'message', array('user_level'=>2)),
        array('register', 'interests', array('spam_keyword_det'=>0)),
        array('editprofile', 'signature', array('spam_keyword_det'=>0)),
        array('register', 'signature', array('submit'=>false))
    ) as $policy) {
        $cases[] = array($locale, $policy[0], $policy[1], "vi\xC2\xADagra", false, $policy[2]);
    }
    $cases[] = array($locale, 'register', 'signature', "vi\xC2\xADagra", true, array('spam_keyword_det'=>1));
}

if (isset($argv[1])) {
    utf8_spam_assert(count($argv) === 3 && $argv[1] === '--case' && ctype_digit($argv[2]) && isset($cases[(int)$argv[2]]), 'Invalid child case');
    $case = $cases[(int)$argv[2]];
    $lang = array();
    require $phpbb_root_path . 'language/lang_' . $case[0] . '/lang_cback_ctracker.php';
    $ctracker_config = new stdClass();
    $ctracker_config->settings = array('spammer_blockmode'=>0, 'spam_attack_boost'=>1,
        'spam_keyword_det'=>2, 'reg_protection'=>0, 'autoban_mails'=>0);
    $userdata = array('user_id'=>2, 'user_level'=>0, 'user_posts'=>0);
    $mode = $case[1];
    $HTTP_POST_VARS = array('submit'=>1, 'message'=>'ordinary', 'subject'=>'ordinary');
    $HTTP_POST_VARS[$case[2]] = $case[3];
    $_POST = $HTTP_POST_VARS;
    foreach ($case[5] as $key=>$value) {
        if ($key === 'user_posts' || $key === 'user_level') { $userdata[$key] = $value; }
        elseif ($key === 'submit') { unset($HTTP_POST_VARS['submit'], $_POST['submit']); }
        else { $ctracker_config->settings[$key] = $value; }
    }
    $db = new Utf8SpamNoDatabase();
    $snapshot = serialize(array($HTTP_POST_VARS, $_POST, $userdata, $ctracker_config));
    $rejected = false;
    try {
        $functions = new ct_userfunctions();
        if ($mode === 'post') { $functions->handle_postings(); }
        else { $functions->handle_profile(); }
    } catch (Utf8SpamRejected $e) {
        $expected_key = $mode === 'post' ? 'ctracker_info_post_spammer' :
            ($mode === 'register' ? 'ctracker_info_profile_spammer' : 'ctracker_info_profile_content');
        utf8_spam_assert($e->getMessage() === $lang[$expected_key], 'Wrong localized rejection');
        $rejected = true;
    }
    utf8_spam_assert($rejected === $case[4], 'Actual handler policy differs: case ' . $argv[2]);
    utf8_spam_assert(serialize(array($HTTP_POST_VARS, $_POST, $userdata, $ctracker_config)) === $snapshot, 'Handler changed input, policy or account');
    echo "UTF8_SPAM_CASE_OK\n";
    exit(0);
}

$functions = new ct_userfunctions();
$normalization_cases = array(
    array('', ''), array('ordinary text', 'ordinary text'),
    array("vi\xC2\xADagra", 'viagra'),
    array("\xC2\xAD\xC2\xADx\xC2\xAD", 'x'),
    array("Grüße café í 漢 😀", "Grüße café í 漢 😀"),
    array("\xC3\xAD\xE1\xAD\x80\xF0\x90\xAD\x80", "\xC3\xAD\xE1\xAD\x80\xF0\x90\xAD\x80"),
    array("a\xADb", "a\xADb"), array("a\xC2", "a\xC2"),
    array("a\0\n\t\\'\"&amp;b", "a\0\n\t\\'\"&amp;b"),
    array("a&#173;b&#xAD;c&shy;d", "a&#173;b&#xAD;c&shy;d")
);
foreach ($normalization_cases as $case) {
    $original = $case[0];
    $clean = $functions->spam_comparison_text($original);
    utf8_spam_assert($clean === $case[1] && $original === $case[0], 'Comparison bytes or input changed');
    utf8_spam_assert($functions->spam_comparison_text($clean) === $clean, 'Normalization not idempotent');
    if (preg_match('//u', $original) === 1) {
        utf8_spam_assert(preg_match('//u', $clean) === 1, 'Valid Unicode damaged');
    }
}
foreach ($cases as $index=>$case) {
    // Windows fixtures intentionally have no php.ini. Linux retains its CLI
    // module stack (ctype can be a separately packaged extension on old PHP).
    $command = escapeshellarg(PHP_BINARY);
    if (PHP_OS === 'WINNT') { $command .= ' -n -d date.timezone=UTC'; }
    else { $ini = php_ini_loaded_file(); if ($ini !== false) { $command .= ' -c ' . escapeshellarg($ini); } }
    $command .= ' ' . escapeshellarg(__FILE__) . ' --case ' . (int)$index;
    $pipes = array();
    $process = proc_open($command, array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')), $pipes, null, null, array('bypass_shell'=>true));
    utf8_spam_assert(is_resource($process), 'Fresh request process unavailable');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $status = proc_close($process);
    utf8_spam_assert($status === 0 && $stdout === "UTF8_SPAM_CASE_OK\n" && $stderr === '', 'Actual fresh handler failed case ' . $index . ': ' . $stdout . $stderr);
}
echo 'CrackerTracker UTF-8 spam checks passed: ' . count($normalization_cases) . ' byte-preservation and ' . count($cases) . " fresh localized handler cases.\n";
