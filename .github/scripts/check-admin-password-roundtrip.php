<?php
// Execute the actual ACP input/hash and login input fragments, without loading
// pagestart, configuration, database connections or creating real accounts.
namespace AdminPasswordFixture;
$root = dirname(dirname(__DIR__)) . '/phpBB2/';
require_once $root . 'includes/php_compat.php';
if (!defined('GENERAL_MESSAGE')) { define('GENERAL_MESSAGE', 200); }
function message_die($type, $message) { throw new \RuntimeException($message); }
function check($ok, $message) { if (!$ok) { throw new \RuntimeException($message); } }
function fragment($source, $start, $end)
{
    $source = str_replace("\r\n", "\n", $source);
    $from = strpos($source, $start);
    $to = $from === false ? false : strpos($source, $end, $from);
    check($from !== false && $to !== false && $to > $from, 'Actual controller fragment located');
    return 'namespace AdminPasswordFixture;' . substr($source, $from, $to - $from);
}
$admin = file_get_contents($root . 'admin/admin_users.php');
$quick = file_get_contents($root . 'admin/admin_user_register.php');
$login = file_get_contents($root . 'login.php');
$admin_input = fragment($admin, "\t\t\$password = (isset(\$_POST['password'])", "\t\t\$icq =");
$admin_hash = fragment($admin, "\t\t\$passwd_sql = '';", '// End add - Admin add user MOD');
$quick_input = fragment($quick, "\t\$new_password = (isset(\$_POST['new_password'])", "\t\$user_style =");
$quick_validate = fragment($quick, "\t\$passwd_sql = '';", "\t//\n\t// Do a ban check");
// Source files may have checkout CRLF; fragment anchors otherwise stay exact.
$login_input = fragment($login, "\t\t\$password_value =", "\t\t\$sql =");
check(preg_match('/^function admin_user_require_creation_password\(\).*?^\}/ms', $admin, $gate) === 1, 'Actual early creation gate located');
eval('namespace AdminPasswordFixture;' . $gate[0]);
check(preg_match('/\$new_password = phpbb_password_hash\(\$new_password\);/', $quick, $hash) === 1, 'Actual quick-add hash located');
$quick_hash = 'namespace AdminPasswordFixture;' . $hash[0];
check(strpos($login, 'phpbb_password_verify($password, $row[\'user_password\'])') !== false, 'Login uses the tested raw input with the actual verifier');
$lang = array('New_user_password_required' => 'required', 'Password_mismatch' => 'mismatch', 'Fields_empty' => 'required');
set_error_handler(function($severity, $message) { if (error_reporting() & $severity) { throw new \RuntimeException($message); } });
try
{
    $values = array('Normal-secret!Q9', 'Grüße & <wörtlich> "Q9"', "Apostrophe' and \\slash!9", ' leading and trailing !9 ', '&amp; literal &#39;!9', '0');
    foreach (array(0, 1) as $hashing)
    {
        $board_config = array('password_hashing' => $hashing);
        foreach ($values as $original)
        {
            foreach (array(false, true) as $quick_add)
            {
                $_POST = array('password' => $original, 'new_password' => $original, 'password_confirm' => $original);
                $error = false; $error_msg = ''; $new_user = true; $force_new_passwd = false;
                $username = 'Fixture Member'; $email = 'fixture@example.invalid';
                if ($quick_add)
                {
                    eval($quick_input); eval($quick_validate);
                    check(!$error && $new_password === $original, 'Quick-add validation preserves exact submitted bytes');
                    eval($quick_hash); $stored = $new_password;
                }
                else
                {
                    admin_user_require_creation_password(); eval($admin_input);
                    check($password === $original, 'User-manager input preserves exact submitted bytes');
                    eval($admin_hash); $stored = $password;
                    check(!$error && $passwd_sql !== '', 'User-manager creates password assignment');
                }
                eval($login_input);
                check($password === $original && \phpbb_password_verify($password, $stored), 'Actual login input verifies the actual ACP hash in both migration states');
                $changed = trim(htmlspecialchars($original, ENT_QUOTES, 'UTF-8'));
                if ($changed !== $original) { check(!\phpbb_password_verify($changed, $stored), 'Encoded or trimmed variants are not credential aliases'); }
            }
        }
    }
    foreach (array(array('left ', 'left'), array('&', '&amp;'), array('0e123', '0e456'), array('0', ''), array('', '0')) as $pair)
    {
        $_POST = array('password' => $pair[0], 'new_password' => $pair[0], 'password_confirm' => $pair[1]);
        $caught = false;
        try { admin_user_require_creation_password(); } catch (\RuntimeException $exception) { $caught = true; }
        check($caught, 'Creation gate rejects different raw confirmation before writes');
        $error = false; $error_msg = ''; $new_user = false; eval($admin_input); eval($admin_hash);
        check($error && $passwd_sql === '', 'Existing-account mismatches do not produce a password update');
        $error = false; $error_msg = ''; eval($quick_input); eval($quick_validate);
        check($error, 'Quick-add rejects different raw confirmations, including numeric-looking strings');
    }
    $_POST = array('password' => '', 'password_confirm' => '');
    $error = false; $error_msg = ''; $new_user = false; eval($admin_input); eval($admin_hash);
    check(!$error && $passwd_sql === '', 'Blank edit leaves the existing password unchanged');
    foreach (array('CUR_PASSWORD', 'NEW_PASSWORD', 'PASSWORD_CONFIRM') as $key)
    {
        check(preg_match('/\x27' . $key . '\x27\s*=>\s*\x27\x27/', $quick) === 1, 'Quick-add template never receives a submitted password or hash');
    }
    $template = file_get_contents($root . 'templates/fisubsilversh/admin/user_edit_body.tpl');
    check(preg_match_all('/<input\b[^>]*type="password"[^>]*>/i', $template, $fields) >= 2, 'Actual edit password fields located');
    foreach ($fields[0] as $field) { check(!preg_match('/value="[^"]+"/', $field), 'Edit password fields do not reflect credentials'); }
    echo "ACP raw-password creation/edit, strict confirmation, login round-trip and blank output checks passed.\n";
}
finally { restore_error_handler(); }
