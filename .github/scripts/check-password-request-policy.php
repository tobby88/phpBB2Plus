<?php
// Execute real bootstrap normalization and every controller's policy call.
// This is a request/policy test, not a database or browser integration test.
$root = dirname(dirname(__DIR__)) . '/phpBB2/';
require_once $root . 'includes/php_compat.php';
require_once $root . 'includes/functions_validate.php';
require_once __DIR__ . '/profile-request-fixture.php';
$lang = array('Fields_empty'=>'empty', 'Password_invalid'=>'invalid', 'Password_long'=>'long',
    'Password_not_complex'=>'policy:', 'Password_to_short'=>'minimum %d',
    'Password_not_same'=>'same-name', 'Password_mixed'=>'mixed');
$board_config = array('password_hashing'=>1, 'min_password_len'=>6,
    'force_complex_password'=>1, 'password_not_login'=>1);
$failures = array(); $checks = 0;
define('GENERAL_MESSAGE', 200);
function message_die($type, $message) { throw new RuntimeException($message); }
function request_policy_check($ok, $label)
{
    global $failures, $checks;
    $checks++;
    if (!$ok) { $failures[] = $label; }
}
function request_policy_call($statement, $variable, $value)
{
    $username = 'Fixture'; $admin_name = 'Fixture';
    $row = $userdata = array('username'=>'Fixture');
    $password = $new_password = $admin_password_value = $value;
    eval($statement);
    return $$variable;
}
set_error_handler(function($severity, $message) {
    if (error_reporting() & $severity) { throw new RuntimeException($message); }
});
try
{
    $calls = array();
    foreach (array('install/install.php', 'login.php', 'change_password.php',
        'admin/admin_users.php', 'admin/admin_user_register.php',
        'includes/usercp_register.php', 'includes/usercp_activate.php') as $path)
    {
        $source = file_get_contents($root . $path);
        preg_match_all('/\$(\w+)\s*=\s*validate_complex_password\s*\([^;]+;/', $source, $matches, PREG_SET_ORDER);
        request_policy_check(count($matches) === ($path === 'admin/admin_users.php' ? 2 : 1), 'All policy calls found: ' . $path);
        foreach ($matches as $match) { $calls[] = array($path, $match[0], $match[1]); }
    }

    // The installer is standalone: test its explicit encoded-input call before
    // the common.php flag exists. Literal backslash-zero must remain usable.
    request_policy_check(!defined('PHPBB_LEGACY_REQUEST_ESCAPED'), 'Standalone installer context');
    foreach (array(array("Good9\0tail", 'invalid'), array('Good9\\0tail', ''), array('Good9\\tail', '')) as $case)
    {
        $result = request_policy_call($calls[0][1], $calls[0][2], addslashes($case[0]));
        request_policy_check($result['error_msg'] === $case[1], 'Standalone installer raw-NUL distinction');
    }

    $cases = array(
        'dots-only'=>array('......', 'policy:mixed'),
        'letters-dot'=>array('Letters.', 'policy:mixed'),
        'numbers-dot'=>array('123456.', 'policy:mixed'),
        'punctuation'=>array('!@#$%^', 'policy:mixed'),
        'letters-only'=>array('Letters', 'policy:mixed'),
        'numbers-only'=>array('123456', 'policy:mixed'),
        'nul'=>array("Good9\0tail", 'invalid'),
        'escaped-nul'=>array("Good9\\\0tail", 'invalid'),
        'literal-zero'=>array('Good9\\0tail', ''),
        'literal-double-slash'=>array('Good9\\\\0tail', ''),
        'ascii'=>array('Letters9', ''),
        'unicode-letter'=>array('ÄÖÜß99', ''),
        'unicode-digit'=>array('Letters９', ''),
        'unicode-both'=>array('Буквы١', ''),
        'combining-letter'=>array("e\xCC\x81abcd9", ''),
        'emoji-only'=>array('😀😀', 'policy:mixed'),
        'quote'=>array("Quote'9", ''),
        'double-quote'=>array('Quote"9', ''),
        'spaces'=>array(' space9 ', ''),
        'html-literal'=>array('&amp;9', ''),
        'too-long-canonical'=>array(str_repeat("'", 35) . 'Ab9', 'long'),
        'max-canonical'=>array(str_repeat("'", 34) . 'Ab99', ''),
        'malformed'=>array(array('invalid'), 'invalid'),
        'invalid-utf8'=>array("Good9\xFF", 'policy:mixed')
    );
    foreach ($cases as $label => $case)
    {
        profile_fixture_request(array('username'=>'Fixture', 'password'=>$case[0],
            'password_confirm'=>$case[0], 'new_password'=>$case[0]));
        $before = $_POST;
        foreach ($calls as $call)
        {
            $result = request_policy_call($call[1], $call[2], $_POST['password']);
            request_policy_check($result['error_msg'] === $case[1] && $result['error'] === ($case[1] !== ''), $call[0] . ': ' . $label);
        }
        request_policy_check($_POST === $before && $HTTP_POST_VARS === $before, 'Policy never rewrites request/hash bytes: ' . $label);
        if ($case[1] !== '') { continue; }
        foreach (array(0, 1) as $hashing)
        {
            $board_config['password_hashing'] = $hashing;
            $encoded = $_POST['password']; $hash = phpbb_password_hash($encoded);
            request_policy_check(is_string($hash) && phpbb_password_verify($encoded, $hash), 'Unchanged MD5/bcrypt representation: ' . $label);
            request_policy_check($encoded === $case[0] || !phpbb_password_verify($case[0], $hash), 'No raw/escaped password aliases: ' . $label);
        }
    }
    // Execute the actual early ACP creation gate, not just its policy call.
    // It must stop before returning a hash to the ID-reservation/write path.
    $admin = file_get_contents($root . 'admin/admin_users.php');
    request_policy_check(preg_match('/^function admin_user_require_creation_password\(.*?^}/ms', $admin, $match) === 1, 'Actual ACP early gate found');
    eval($match[0]);
    $lang += array('New_user_password_required'=>'empty', 'Password_mismatch'=>'mismatch', 'Password_hash_failed'=>'hash-failed');
    foreach (array(0, 1) as $hashing)
    {
        $board_config['password_hashing'] = $hashing;
        foreach (array('dots-only', 'letters-dot', 'nul', 'escaped-nul', 'literal-zero', 'unicode-letter', 'quote') as $label)
        {
            $case = $cases[$label];
            profile_fixture_request(array('username'=>'Fixture', 'password'=>$case[0], 'password_confirm'=>$case[0]));
            $hash = null; $error = '';
            try { $hash = admin_user_require_creation_password(); }
            catch (RuntimeException $exception) { $error = $exception->getMessage(); }
            request_policy_check($error === $case[1] && ($error === ''
                ? is_string($hash) && phpbb_password_verify($_POST['password'], $hash) : $hash === null), 'Actual ACP pre-write gate: ' . $label);
        }
    }
    $board_config['force_complex_password'] = 0;
    foreach (array('......', 'Letters.', '123456.') as $value)
    {
        profile_fixture_request(array('password'=>$value));
        request_policy_check(!validate_complex_password('Fixture', $_POST['password'])['error'], 'Complexity remains optional');
    }
    profile_fixture_request(array('password'=>"Good9\0tail"));
    request_policy_check(validate_complex_password('Fixture', $_POST['password'])['error_msg'] === 'invalid', 'NUL rejected with complexity disabled');
    request_policy_check(!validate_complex_password('Fixture', 'Good9\\0tail', false)['error'], 'Explicit raw caller does not decode literal zero');
    request_policy_check(validate_complex_password('Fixture', "Good9\0tail", false)['error_msg'] === 'invalid', 'Explicit raw caller rejects actual NUL');
    echo 'Request password policy: ' . $checks . ' checks; failures=' . count($failures) . ".\n";
    if ($failures) { throw new RuntimeException(implode("\n", $failures)); }
}
finally { restore_error_handler(); }
