<?php
// CLI-only, synthetic identities. No common.php, authentication or admin writes.
if (PHP_SAPI !== 'cli') { exit(2); }
date_default_timezone_set('UTC');
define('IN_PHPBB', true);
error_reporting(E_ALL);
set_error_handler(function ($severity, $message) {
    if (error_reporting() & $severity) { throw new RuntimeException($message); }
});
$al_root = dirname(dirname(__DIR__)) . '/phpBB2/';
$phpbb_root_path = $al_root; $phpEx = 'php'; $table_prefix = 'fixture_';
require $al_root . 'includes/php_compat.php';
require $al_root . 'includes/constants.php';
require $al_root . 'includes/template.php';
$al_cases = $al_checks = 0;
class AdminLabelDenied extends RuntimeException {}
function message_die($level, $message) { throw new AdminLabelDenied($message); }
function al_check($ok, $message) {
    if (!$ok) { throw new RuntimeException($message); }
    $GLOBALS['al_checks']++;
}
$al_functions = file_get_contents($al_root . 'includes/functions.php');
foreach (array('phpbb_stored_text','phpbb_realpath','create_date','phpbb_profile_details_visible','phpbb_profile_email_uri','get_userdata') as $fn) {
    al_check(preg_match('/^function ' . $fn . '\(.*?^\}/ms', $al_functions, $al_match) === 1, 'Actual shared function: ' . $fn);
    eval($al_match[0]);
}
$al_sessions = file_get_contents($al_root . 'includes/sessions.php');
al_check(preg_match('/^function append_sid\(.*?^\}/ms', $al_sessions, $al_match) === 1, 'Actual URL formatter');
eval($al_match[0]); $SID = 'sid=synthetic_admin_label';
function al_source($file) { return file_get_contents($GLOBALS['al_root'] . $file); }
function al_fragment($file, $first, $last) {
    $s = al_source($file); $a = strpos($s, $first); $b = $a === false ? false : strpos($s, $last, $a + strlen($first));
    al_check($a !== false && $b !== false, 'Actual controller section: ' . $file);
    return substr($s, $a, $b - $a);
}
function al_binding($file, $key) {
    al_check(preg_match('/\'' . preg_quote($key, '/') . '\'\s*=>\s*([^\r\n]+),/', al_source($file), $m) === 1, 'Actual template binding: ' . $key);
    return $m[1];
}
function al_locale($locale) {
    global $lang, $phpEx;
    $lang = array(); require $GLOBALS['al_root'] . 'language/lang_' . $locale . '/lang_main.php';
    require $GLOBALS['al_root'] . 'language/lang_' . $locale . '/lang_admin.php';
    require $GLOBALS['al_root'] . 'language/lang_' . $locale . '/lang_color_groups.php';
    $GLOBALS['al_locale'] = $locale;
}
function al_template($file) {
    global $board_config, $theme, $phpbb_root_path;
    $phpbb_root_path = $GLOBALS['al_root'];
    $board_config = array('default_lang'=>$GLOBALS['al_locale'], 'board_timezone'=>0, 'default_dateformat'=>'Y-m-d',
        'board_email_form'=>0, 'xs_use_cache'=>0, 'xs_auto_compile'=>0, 'xs_auto_recompile'=>0, 'version'=>'.0.23');
    $theme = array('template_name'=>'fisubsilversh', 'td_class1'=>'row1', 'td_class2'=>'row2');
    $t = (new ReflectionClass('Template'))->newInstanceWithoutConstructor();
    $t->vars =& $t->_tpldata['.'][0];
    $t->load_config($phpbb_root_path . 'templates/fisubsilversh', false);
    $t->set_filenames(array('body'=>'admin/' . $file));
    return $t;
}
function al_html($template) {
    global $board_config, $theme, $phpbb_root_path, $phpEx, $lang;
    ob_start(); try { $template->pparse('body'); } finally { $html = ob_get_clean(); }
    al_check(preg_match('//u', $html) === 1, 'Rendered page is UTF-8'); return $html;
}
function al_visible($html) { return html_entity_decode($html, ENT_QUOTES, 'UTF-8'); }
function al_names() { return array('A&B', "O'Reilly", '&#39;', '&copy;', '&amp;', 'A<"B', 'Grüße 😀', 'A@B', 'Back\\slash', '0'); }
function al_labels($key) {
    $row = $owner = $main = array('user_id'=>7, 'username'=>$key);
    $out = array();
    foreach (array(array('admin/admin_account.php','USERNAME'), array('admin/admin_banner.php','BANNER_OWNER'),
        array('admin/admin_reg_ip.php','MAIN_USER'), array('admin/admin_reg_ip.php','USERNAME')) as $spec) {
        $out[] = eval('return ' . al_binding($spec[0], $spec[1]) . ';');
    }
    al_check(preg_match('/^\s*\$username = ([^\r\n]+);/m', al_source('admin/admin_color_groups.php'), $m) === 1, 'Actual ColorGroups label');
    $out[] = eval('return ' . $m[1] . ';'); return $out;
}
function al_label_suite() {
    foreach (array('english','german') as $locale) { al_locale($locale);
        foreach (al_names() as $raw) { foreach (array(ENT_QUOTES, ENT_COMPAT) as $flags) {
            $key = phpbb_username_key($raw, $flags); al_check($key !== '', 'Valid canonical identity fixture');
            $labels = al_labels($key);
            foreach ($labels as $html) {
                al_check(al_visible($html) === $raw, 'Stored username is decoded once for display');
                al_check(strpbrk($html, '<>"\'') === false, 'Name remains safe in HTML text and quoted attributes');
                $GLOBALS['al_cases']++;
            }
            $t = al_template('banner_edit_body.tpl'); $t->assign_vars(array('BANNER_OWNER'=>$labels[1])); $html = al_html($t);
            al_check(preg_match('/<input\b[^>]*name="username"[^>]*value="([^"]*)"/i', $html, $m) === 1 && al_visible($m[1]) === $raw,
                'Actual banner input round-trips the raw name, including literal entity text');
            $t = al_template('admin_account_body.tpl');
            $t->assign_block_vars('admin_account', array('USERNAME'=>$labels[0], 'U_PROFILE'=>'profile.php?u=7'));
            al_check(strpos(al_html($t), 'href="profile.php?u=7" class="gen">' . $labels[0] . '</a>') !== false,
                'Actual account template keeps name and numeric target together');
            $t = al_template('user_ip_list.tpl'); $t->assign_vars(array('MAIN_USER'=>$labels[2]));
            $t->assign_block_vars('userrow', array('USERNAME'=>$labels[3], 'U_USER'=>'admin_users.php?u=7'));
            $html = al_html($t);
            al_check(strpos($html, '<th colspan="2">' . $labels[2] . '</th>') !== false
                && strpos($html, 'href="admin_users.php?u=7">' . $labels[3] . '</a>') !== false, 'Actual IP heading and row agree');
        }}
        // These values are raw metadata, rather than stored username keys.
        $owner = array('username'=>''); $banner_info = array('banner_comment'=>'A&amp;B', 'banner_url'=>'https://example.invalid/?a=1&b=2');
        foreach (array('BANNER_COMMENT'=>'A&amp;B','BANNER_URL'=>$banner_info['banner_url']) as $key=>$raw) {
            al_check(al_visible(eval('return ' . al_binding('admin/admin_banner.php', $key) . ';')) === $raw, 'Raw banner metadata is not entity-decoded');
        }
        al_check(eval('return ' . al_binding('admin/admin_banner.php', 'BANNER_OWNER') . ';') === '', 'Missing banner owner stays empty');
    }
}
