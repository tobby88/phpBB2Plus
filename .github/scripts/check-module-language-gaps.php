<?php
// Real module expressions and EN/DE packs; no forum bootstrap or database.
define('IN_PHPBB', true);
define('XS_MODS_CATEGORY_HIERARCHY210', true);
$app = dirname(dirname(__DIR__)) . '/phpBB2/';
$checks = 0;
function append_sid($url) { return $url; }
function ml_check($ok, $message)
{
    if (!$ok) { throw new RuntimeException($message); }
    $GLOBALS['checks']++;
}
function ml_line($app, $file, $marker)
{
    $found = array();
    foreach (explode("\n", file_get_contents($app . $file)) as $line)
    {
        if (strpos($line, $marker) !== false) { $found[] = $line; }
    }
    if (!$found) { throw new RuntimeException('Module expression required: ' . $file . ' ' . $marker); }
    return $found[0];
}
function ml_pack($app, $locale, $packs)
{
    $lang = array(); $lang_extend_admin = true;
    $phpbb_root_path = $app; $phpEx = 'php'; $board_config = array('sitename' => 'Fixture');
    foreach ($packs as $pack) { require $app . 'language/lang_' . $locale . '/' . $pack . '.php'; }
    return $lang;
}
// Extract only the actual metadata function, without loading storage classes.
$source = file_get_contents($app . 'includes/functions_style_data.php');
$offset = strpos($source, 'function xs_get_vars(');
if ($offset === false) { throw new RuntimeException('Style metadata function missing'); }
$body = ''; $depth = 0; $started = false;
foreach (token_get_all('<?php ' . substr($source, $offset)) as $token)
{
    if (is_array($token) && $token[0] === T_OPEN_TAG) { continue; }
    if ($token === '{') { $depth++; $started = true; }
    if ($token === '}') { $depth--; }
    $body .= is_array($token) ? $token[1] : $token;
    if ($started && $depth === 0) { break; }
}
eval($body);
$sites = array(
    array('activity.php', "GENERAL_ERROR, \$lang['no_rate_data']", array('lang_extend_arcade')),
    array('admin/admin_arcade_cats.php', "GENERAL_ERROR, \$lang['no_cat_delete']", array('lang_extend_arcade')),
    array('includes/classes_arcade.php', "\$this->message .= \$lang['no_score_update']", array('lang_extend_arcade')),
    array('arcade_tournament.php', "message_die(GENERAL_ERROR, \$lang['no_tour_player_data']", array('lang_extend_arcade')),
    array('admin/admin_banner.php', "GENERAL_MESSAGE, \$lang['Missing_banner_name']", array('lang_banner')),
    array('admin/admin_captcha_config.php', "\$message = \$lang['captcha_config_updated']", array('lang_main', 'lang_admin', 'lang_admin_captcha')),
    array('admin/admin_jr_admin.php', "GENERAL_ERROR, \$lang['Error_User_Table']", array('lang_jr_admin')),
    array('admin/admin_album_config_clown.php', "'detail'", array('lang_main_album', 'lang_clown_album')),
    array('album_delete.php', "\$message = \$lang['Pics_deleted_successfully']", array('lang_main_album')),
    array('album_nuffload.php', "multi_loop(\$lang['thumb_res_too_high'])", array('lang_main_album')),
    array('admin/admin_kb_cat.php', "'L_CAT_NAME'", array('lang_kb')),
    array('admin/admin_kb_cat.php', "'L_CATEGORY'", array('lang_kb')),
    array('includes/functions_topics_list.php', "\$list_title = \$lang[", array('lang_main')),
    array('pafiledb/modules/pa_mcp.php', "\$this->display(\$lang[", array('lang_main', 'lang_pafiledb', 'lang_admin_pafiledb')),
    array('recent.php', "\$topic_type .= ( \$line[\$i]['topic_type'] == POST_GLOBAL_ANNOUNCE )", array('lang_extend_announces'))
);
set_error_handler(function ($severity, $message) {
    if (error_reporting() & $severity) { throw new RuntimeException($message); }
});
foreach (array('english', 'german') as $locale)
{
    foreach ($sites as $site)
    {
        $lang = ml_pack($app, $locale, $site[2]);
        $line = ml_line($app, $site[0], $site[1]);
        preg_match_all('/\$lang\[\x27([^\x27]+)\x27\]/', $line, $keys);
        ml_check(count($keys[1]) > 0, 'Runtime language lookup missing');
        foreach (array_unique($keys[1]) as $key)
        {
            $value = $lang[$key];
            ml_check(is_string($value) && $value !== '' && preg_match('//u', $value) === 1,
                'Missing/invalid ' . $locale . ' module message: ' . $site[0] . ' ' . $key);
        }
    }
    $lang = ml_pack($app, $locale, array('lang_main', 'lang_extend_categories_hierarchy'));
    $url = 'https://example.invalid/path?q=Grüße&label="quoted"';
    $safe_url = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    ob_start(); eval(ml_line($app, 'viewforum.php', 'echo \'<!DOCTYPE HTML')); $html = ob_get_clean();
    ml_check(substr_count($html, '<a href="' . $safe_url . '">') === 1 && substr_count($html, '</a>') === 1,
        'Forum redirect must render both localized link placeholders');
    ml_check(strpos($html, '%s') === false && strpos($html, '&amp;label=&quot;quoted&quot;') !== false,
        'Forum redirect URL escaping or placeholder substitution failed');
    $lang = ml_pack($app, $locale, array('lang_main'));
    eval(ml_line($app, 'pafiledb/includes/functions.php', '$page_title = '));
    ml_check($page_title === $lang['Emoticons'], 'Smilie picker requires a localized title without a topic');
    $lang = ml_pack($app, $locale, array('lang_xs'));
    $fields = xs_get_vars(array('style_name' => 'Fixture', 'head_stylesheet' => 'fixture.css'));
    foreach (array('images_pack', 'custom_tpls') as $field)
    {
        ml_check(isset($fields[$field]) && $fields[$field]['len'] === 100, 'CH style metadata missing');
        foreach (array('', '_explain') as $suffix)
        {
            $value = $lang['xs_data_' . $field . $suffix];
            ml_check(is_string($value) && $value !== '' && preg_match('//u', $value) === 1, 'Style metadata label missing');
        }
    }
    $lang = ml_pack($app, $locale, array('lang_main_album', 'lang_admin_album'));
    $original = $lang['Album_config_explain'];
    require $app . 'language/lang_' . $locale . '/lang_clown_album.php';
    ml_check($lang['Album_config_explain'] === $original, 'Service pack must not replace the main album description');
}
$edit = file_get_contents($app . 'includes/kb_edit.php');
foreach (array('L_HOWTO', 'L_TUTORIAL', 'L_FAQ', 'L_INFO') as $unused)
{
    ml_check(strpos($edit, "'" . $unused . "'") === false, 'Unused fixed article-type binding remains');
}
restore_error_handler();
echo 'Module language integration: ', $checks, " assertions PASS; real EN/DE module lookups, forum redirect, smilie title, CH style metadata and album descriptions.\n";
