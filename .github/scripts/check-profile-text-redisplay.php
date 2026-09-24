<?php
// Exercise the actual parser, optional validator, rejected-form preparation
// and Extreme Styles template. No database or live account is involved.
define('IN_PHPBB', true);
$phpbb_root_path = dirname(dirname(__DIR__)) . '/phpBB2/';
$phpEx = 'php';
require $phpbb_root_path . 'includes/php_compat.php';
require $phpbb_root_path . 'includes/functions.php';
require $phpbb_root_path . 'includes/functions_validate.php';
require $phpbb_root_path . 'includes/functions_profile_fields.php';
require $phpbb_root_path . 'includes/template.php';
$sessions = file_get_contents($phpbb_root_path . 'includes/sessions.php');
$sid_function = substr($sessions, strpos($sessions, 'function append_sid('));
eval(substr($sid_function, 0, strrpos($sid_function, '?>')));
$SID = 'sid=fixture-session';
function profile_text_check($ok, $message)
{
    if (!$ok) { throw new RuntimeException($message); }
}
function profile_text_section($source, $begin, $end)
{
    $a = strpos($source, $begin);
    $b = $a === false ? false : strpos($source, $end, $a);
    profile_text_check($a !== false && $b > $a, 'Actual controller boundary: ' . $begin);
    return substr($source, $a, $b - $a);
}
$controller = file_get_contents($phpbb_root_path . 'includes/usercp_register.php');
eval(profile_text_section($controller, 'function usercp_post_scalar(', 'function usercp_sql_value('));
$parse = profile_text_section($controller, "\t\$strip_var_list = array('email'", "\tforeach (array('fb'");
$input_assignments = '';
foreach (array('username', 'signature', 'user_absence_text') as $key) {
    profile_text_check(preg_match('/^\t\$' . $key . ' = [^\r\n]+;/m', $controller, $m) === 1, 'Actual input assignment ' . $key);
    $input_assignments .= $m[0] . "\n";
}
// There are earlier error tests: locate this one after the final writer exit.
$tail = substr($controller, strpos($controller, '// Rejected forms have never attempted'));
$reject = profile_text_section($tail, 'if ( $error )', "else if ( \$mode == 'editprofile' && !isset");
$theme = array('template_name' => 'fisubsilversh');
$userdata = array('user_lang' => 'english', 'template_name' => 'fisubsilversh');
$board_config = array('xs_use_cache' => 0, 'xs_auto_compile' => 0, 'xs_auto_recompile' => 0, 'default_lang' => 'english');
$map = array('username' => 'USERNAME', 'email' => 'EMAIL', 'location' => 'LOCATION', 'occupation' => 'OCCUPATION', 'interests' => 'INTERESTS', 'fb' => 'FB', 'ig' => 'IG', 'twr' => 'TWR', 'tg' => 'TG', 'li' => 'LI', 'tt' => 'TT', 'dc' => 'DC', 'signal' => 'SIGNAL', 'threema' => 'THREEMA', 'user_absence_text' => 'S_USER_ABSENCE_TEXT');
$cases = 0;
set_error_handler(function ($severity, $message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } });
try {
    foreach (array('english', 'german') as $language) {
        $lang = array(); require $phpbb_root_path . 'language/lang_' . $language . '/lang_main.php';
        foreach (array('', '0', 'x', 'ü', 'C:\\notes\\draft', "Grüße 😀 \\ & \" ' </textarea><script>test</script>", '&amp; &#039; literal') as $raw) {
            $_POST = array_fill_keys(array_keys($map), $raw);
            $_POST['signature'] = $raw;
            for ($hop = 0; $hop < 3; $hop++) {
                eval($parse);
                eval($input_assignments);
                $icq = '123'; $aim = $msn = $yim = 'legacy';
                validate_optional_fields($icq, $aim, $msn, $yim, $website, $location, $occupation, $interests, $signature);
                foreach (array('location', 'occupation', 'interests') as $key) {
                    profile_text_check(html_entity_decode($$key, ENT_QUOTES, 'UTF-8') === $raw, 'Parser/validator retains ' . $key);
                }
                profile_text_check($signature === $raw, 'Single-character signature retained');
                $cur_password = $new_password = $password_confirm = 'PRIVATE-PASSWORD';
                $user_dateformat = 'D \\ Y'; $user_lang = $language;
                $error = true; eval($reject);
                profile_text_check($cur_password === '' && $new_password === '' && $password_confirm === '', 'Rejected passwords cleared');
                profile_text_check($user_dateformat === 'D \\ Y' && $user_lang === $language, 'Preferences retained');
                profile_text_check(html_entity_decode(phpbb_profile_display_text($signature), ENT_QUOTES, 'UTF-8') === $raw, 'Raw rejected signature display');
                $template = (new ReflectionClass('Template'))->newInstanceWithoutConstructor();
                $template->vars =& $template->_tpldata['.'][0];
                $template->load_config($phpbb_root_path . 'templates/fisubsilversh', false);
                $template->set_filenames(array('body' => 'profile_add_body.tpl'));
                $template->assign_block_vars('switch_namechange_allowed', array());
                $template->assign_block_vars('allow_absence', array());
                foreach ($map as $key => $placeholder) {
                    profile_text_check(preg_match("/'" . $placeholder . "' => ([^\r\n]+),/", $tail, $m) === 1, 'Actual template assignment ' . $placeholder);
                    $template->assign_vars(array($placeholder => eval('return ' . $m[1] . ';')));
                }
                ob_start(); try { $template->pparse('body'); $html = ob_get_contents(); } finally { ob_end_clean(); }
                profile_text_check(strpos($html, '<script>test</script>') === false && strpos($html, 'PRIVATE-PASSWORD') === false, 'Safe HTML and no reflected password');
                foreach ($map as $key => $placeholder) {
                    $pattern = $key === 'user_absence_text' ? '/<textarea\b[^>]*name="' . $key . '"[^>]*>(.*?)<\/textarea>/is' : '/<input\b[^>]*name="' . $key . '"[^>]*value="([^"]*)"/i';
                    profile_text_check(preg_match($pattern, $html, $m) === 1, 'Rendered field ' . $key);
                    $value = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
                    profile_text_check($value === $raw, 'Exact rendered field ' . $key . '/' . $hop);
                    $_POST[$key] = $value;
                }
            }
            $cases++;
        }
    }
    foreach (array(array('nested'), null) as $invalid) {
        $_POST = array_fill_keys(array_keys($map), $invalid); eval($parse);
        foreach ($strip_var_list as $key => $unused) { profile_text_check($$key === '', 'Reject nonscalar input'); }
        $cases++;
    }
    $icq = 'invalid'; $aim = $msn = $yim = 'x'; $website = 'javascript:alert(1)';
    $location = $occupation = $interests = $signature = '0';
    validate_optional_fields($icq, $aim, $msn, $yim, $website, $location, $occupation, $interests, $signature);
    profile_text_check($icq === '' && $aim === '' && $msn === '' && $yim === '' && $website === '', 'Legacy identifier and website checks preserved');
    echo 'Profile text: ' . $cases . " parser/validator/real-template cases, three rejected-form round trips passed.\n";
} finally { restore_error_handler(); }
