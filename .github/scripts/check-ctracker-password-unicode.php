<?php
// Actual CT handler, ACP selector and registration/profile gate; synthetic input.
if (PHP_SAPI !== 'cli') { exit(2); }
define('IN_PHPBB', true); define('CTRACKER_ACP', true); define('GENERAL_MESSAGE', 0);
set_error_handler(function($severity, $message) {
    if (error_reporting() & $severity) { throw new RuntimeException($message); }
});
$root = dirname(dirname(__DIR__)) . '/phpBB2/'; $phpbb_root_path = $root; $phpEx = 'php';
$bootstrap = isset($argv[1]) && $argv[1] === '--bootstrap';
if ($bootstrap) {
    require $root . 'includes/php_compat.php';
    require __DIR__ . '/profile-request-fixture.php';
}
require $root . 'ctracker/classes/class_ct_userfunctions.php';
require $root . 'ctracker/classes/class_ct_adminfunctions.php';
$controller = file_get_contents($root . 'includes/usercp_register.php');
$begin = strpos($controller, '// BEGIN CrackerTracker v5.x');
$end = strpos($controller, '// END CrackerTracker v5.x', $begin);
if ($begin === false || $end === false) { throw new RuntimeException('Actual profile CT gate missing'); }
$gate = substr($controller, $begin, $end - $begin);
class CtPasswordRejected extends RuntimeException {}
class CtPasswordNoDatabase {
    function __call($method, $arguments) { throw new RuntimeException('Unexpected database operation'); }
}
function message_die($type, $message) { throw new CtPasswordRejected($message); }
function cp_check($ok, $label) {
    if (!$ok) { throw new RuntimeException($label); } $GLOBALS['checks']++;
}
function cp_run($setting, $password, $expected, $minimum = 1, $enabled = 1, $submit = true, $request_mode = 'editprofile') {
    global $HTTP_POST_VARS, $userdata, $ctracker_config, $lang, $db, $bootstrap,
        $gate, $phpbb_root_path, $phpEx, $mode;
    $mode = $request_mode; $post = array('new_password' => $password);
    if ($submit) { $post['submit'] = 'Save'; }
    if ($bootstrap) { profile_fixture_request($post); }
    else { $HTTP_POST_VARS = $_POST = $post; }
    $userdata = array('user_id'=>2, 'user_level'=>0);
    $ctracker_config = new stdClass();
    $ctracker_config->settings = array('pw_complex_min'=>$minimum, 'pw_complex'=>$enabled,
        'pw_complex_mode'=>$setting, 'reg_protection'=>0, 'autoban_mails'=>0, 'spam_keyword_det'=>0);
    $db = new CtPasswordNoDatabase();
    $before = serialize(array($HTTP_POST_VARS, $_POST, $userdata, $ctracker_config));
    for ($repeat=0; $repeat<2; $repeat++) {
        $rejected = false;
        try {
            if ($bootstrap) { eval($gate); }
            else { (new ct_userfunctions())->password_functions(); }
        } catch (CtPasswordRejected $error) {
            $rejected = true;
            $value = is_scalar($HTTP_POST_VARS['new_password']) ? (string)$HTTP_POST_VARS['new_password'] : '';
            if ($value !== '' && strlen($value) < max(1, min(20, (int)$minimum))) {
                $message = sprintf($lang['ctracker_info_password_minlng'], max(1, min(20, (int)$minimum)), strlen($value));
            } else {
                $categories = array(1=>array(1), 2=>array(2), 3=>array(3), 4=>array(1,2),
                    5=>array(1,3), 6=>array(1,2,3), 7=>array(1,4), 8=>array(1,2,4), 9=>array(1,2,3,4));
                $labels = array();
                foreach ($categories[isset($categories[(int)$setting]) ? (int)$setting : 1] as $category) {
                    $labels[] = $lang['ctracker_info_password_cmplx_'.$category];
                }
                $message = sprintf($lang['ctracker_info_password_cmplx'], implode(', ', $labels));
            }
            cp_check($error->getMessage() === $message, 'Actual localized policy message');
        }
        cp_check($rejected === !$expected, 'Actual CT password mode '.(int)$setting.' / '.bin2hex(is_string($password) ? $password : 'non-string'));
        cp_check(serialize(array($HTTP_POST_VARS, $_POST, $userdata, $ctracker_config)) === $before,
            'Password comparison never rewrites request, identity or policy');
    }
}
$checks = 0;
// Independently assigned specimen categories: digit=1, lower=2, upper=4, special=8.
$samples = array(
    array('aA1!',15), array('abc',2), array('ABC',4), array('123',1), array('_',0), array('aA1_',7),
    array('äÄ١!',15), array('éÉ９',7), array('ä1',3), array('Ä1',5), array('a١',3),
    array("e\xcc\x81E\xcc\x811",7), array('аЯ１',7), array('λΔ٢',7),
    array('漢字1',1), array('漢字',0), array('aA1漢',7), array('aA1😀',15), array('aA1€',15),
    array('aA1²',7), array('aA1Ⅳ',7), array("aA1\xcc\x81",7), array("aA1\xe2\x80\x8d",7),
    array('aA1 ',15), array("aA1\t",15), array("aA1\r",15), array("aA1\n",15),
    array("aA1\xc2\xa0",15), array("aA1\xe2\x80\xa8",15),
    array("aA1'",15), array('aA1"',15), array('aA1\\',15), array('aA1\\0',15)
);
$required = array(1=>1, 2=>2, 3=>4, 4=>3, 5=>5, 6=>7, 7=>9, 8=>11, 9=>15);
foreach (array('english', 'german') as $locale) {
    $lang = array(); require $root.'language/lang_'.$locale.'/lang_cback_ctracker.php';
    $admin = new ct_adminfunctions();
    cp_check(strpos($lang['ctracker_settings_e24'], 'Unicode') !== false &&
        strpos($lang['ctracker_settings_e25'], 'Unicode') !== false, 'Current Unicode ACP help');
    foreach ($required as $setting=>$mask) {
        $select = $admin->ct_complex_mode($setting);
        cp_check(substr_count($select, '<option ') === 9 && substr_count($select, 'selected="selected"') === 1 &&
            strpos($select, 'value="'.$setting.'" selected="selected">'.$lang['ctracker_complex_'.$setting]) !== false &&
            strpos($select, '[a-z]') === false && strpos($select, '[0-9]') === false, 'Actual nine-mode ACP selector');
        foreach ($samples as $sample) { cp_run($setting, $sample[0], ($sample[1] & $mask) === $mask); }
        foreach (array("aA1!\xff", "aA1!\xc3", "aA1!\nother", "\naA1!") as $invalid) { cp_run($setting, $invalid, false); }
        cp_run($setting, '', true); cp_run($setting, array('invalid'), true);
        cp_run($setting, 'aA1!', true, 1, 0); cp_run($setting, 'x', true, 1, 0);
    }
    foreach (array(0, 10, -1, 'invalid') as $setting) { cp_run($setting, 'ä١', true); cp_run($setting, 'abc', false); }
    cp_run(9, 'äÄ١!', true); cp_run(9, 'äÄ١', false);
    cp_run(9, 'äÄ١!', false, 20); cp_run(9, str_repeat('ä',10), true, 20, 0);
    cp_run(9, str_repeat('ä',9), false, 20, 0); cp_run(9, '', true, 20);
    cp_run(9, 'aA1!', true, 0); cp_run(9, str_repeat('ä',10), true, 100, 0);
    cp_run(1, str_repeat('x',4000).'١', true); cp_run(9, str_repeat('ä',1000).'Ä١!', true);
    $limit = ini_get('pcre.backtrack_limit');
    try { ini_set('pcre.backtrack_limit','0'); cp_run(9, 'äÄ١!', false); }
    finally { ini_set('pcre.backtrack_limit', $limit); }
    if ($bootstrap) {
        cp_run(9, 'invalid', true, 20, 1, false);
        foreach (array(array('äÄ١!',true), array('äÄ١',false), array('aA1_',false), array("aA1!\xff",false)) as $sample) {
            cp_run(9, $sample[0], $sample[1], 1, 1, true, 'register');
        }
    }
}
echo 'CrackerTracker Unicode password policy: '.$checks.' actual EN/DE nine-mode/input/byte/resource/ACP cases PASS ('.($bootstrap?'actual profile bootstrap':'standalone')."); no database or credential writes.\n";
if (!$bootstrap) {
    $pipes = array(); $command = escapeshellarg(PHP_BINARY).' -n '.escapeshellarg(__FILE__).' --bootstrap';
    $child = proc_open($command, array(0=>array('pipe','r'), 1=>array('pipe','w'), 2=>array('pipe','w')),
        $pipes, null, null, array('bypass_shell'=>true));
    if (!is_resource($child)) { throw new RuntimeException('Bootstrap policy child unavailable'); }
    fclose($pipes[0]); $stdout=stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr=stream_get_contents($pipes[2]); fclose($pipes[2]); $exit=proc_close($child);
    if ($exit !== 0 || $stderr !== '' || strpos($stdout, 'PASS (actual profile bootstrap)') === false) {
        throw new RuntimeException('Actual profile bootstrap policy failed: '.$stdout.$stderr);
    }
    echo $stdout;
}
