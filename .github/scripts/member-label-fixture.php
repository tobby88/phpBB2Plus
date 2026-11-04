<?php
// Execute display code only: no common.php, login, editor or score submissions.
require __DIR__ . '/admin-account-label-fixture.php';
foreach (array('phpbb_profile_text','phpbb_serialized_data_read','phpbb_serialized_data_write','phpbb_data_cache_read','phpbb_data_cache_write') as $fn) {
    al_check(preg_match('/^function ' . $fn . '\(.*?^\}/ms', $al_functions, $m) === 1, 'Actual display/cache helper: ' . $fn); eval($m[0]);
}
require $al_root . 'includes/functions_post_edit.php';
require $al_root . 'includes/classes_arcade.php';
require $al_root . 'includes/functions_color_groups.php';
$ml_cases = $ml_templates = 0;
$ml_arcade = (new ReflectionClass('arcade'))->newInstanceWithoutConstructor();
$ml_confirmations = array();
al_check(preg_match('/^function phpbb_arcade_score_number\(.*?^\}/ms', al_source('includes/functions_arcade_score_storage.php'), $m) === 1, 'Actual score confirmation number'); eval($m[0]);
foreach (array('admin/admin_arcade_scores.php','arcade_modcp.php') as $file) {
    foreach (preg_split('/\r?\n/', al_source($file)) as $line) {
        if (strpos($line, "\$lang['arcade_score_sure']") === false || strpos($line, 'get_username') === false) { continue; }
        $pattern = '/^\'MESSAGE_TEXT\'\s*=>\s*(.+),$/';
        al_check(preg_match($pattern, trim($line), $m) === 1, 'Actual score confirmation'); $ml_confirmations[$file] = $m[1];
    }
}
al_check(count($ml_confirmations) === 2, 'Both confirmation routes');
al_check(preg_match('/^\s*\$user_name_display = (.+);/m', al_source('newscore.php'), $m) === 1, 'Actual score result label'); $ml_score = $m[1];
$ml_kb = al_fragment('includes/functions_kb.php', '$registered=isset($postrow[$i][\'user_id\'])', '$post_date = create_date');
al_check(preg_match_all('/if \(\$row\[\'post_edit_count\'\]\).*?else\s*\{\s*\$editmessage = \'\';\s*\}/s', al_source('ajax.php'), $m) === 2, 'Both actual caption branches'); $ml_captions = $m[0];
function ml_context($locale, $logged = true) {
    global $board_config, $userdata, $phpbb_root_path, $lang, $phpEx;
    al_locale($locale); $board_config = array('sitename'=>'Synthetic board','default_lang'=>$locale,'default_dateformat'=>'Y-m-d','board_timezone'=>0,'hide_removed_author_names'=>0);
    $userdata = array('user_id'=>2,'session_logged_in'=>$logged);
    $phpbb_root_path = isset($GLOBALS['ml_cache_root']) ? $GLOBALS['ml_cache_root'] . '/' : $GLOBALS['al_root'];
    require $GLOBALS['al_root'] . 'language/lang_' . $locale . '/lang_extend_arcade.php';
}
function ml_expect($label, $expected, $message) {
    al_check(al_visible(strip_tags($label)) === al_visible(strip_tags($expected)), $message);
    al_check(preg_match('//u', $label) === 1, 'Valid UTF-8 display'); $GLOBALS['ml_cases']++;
}
function ml_registered($record, $raw) {
    global $lang, $arcade;
    $arcade = $GLOBALS['ml_arcade']; $player_id = (int)$record['user_id']; $score = 123.5; $target = array('score'=>$score);
    $key = $record['username']; $expected = htmlspecialchars($raw, ENT_QUOTES, 'UTF-8'); $out = array();
    foreach ($GLOBALS['ml_confirmations'] as $route=>$expression) {
        $out[$route] = eval('return ' . $expression . ';');
        $shown_score = $route === 'admin/admin_arcade_scores.php' ? phpbb_arcade_score_number($target['score']) : $score;
        ml_expect($out[$route], sprintf($lang['arcade_score_sure'], $shown_score, $player_id . ' (' . $expected . ')'), 'Current account in actual score confirmation');
    }
    $user_name = $key; $out['score'] = eval('return ' . $GLOBALS['ml_score'] . ';'); ml_expect($out['score'], $expected, 'Score result name');
    $view_profile = $view_userdata = $record;
    $out['staff'] = eval('return ' . al_binding('staff.php','L_ABOUT_USER') . ';'); ml_expect($out['staff'], sprintf($lang['Staff_about'],$expected), 'Staff profile heading');
    $out['options'] = eval('return ' . al_binding('profile_options.php','L_USER') . ';'); ml_expect($out['options'],$expected,'Profile option breadcrumb');
    $username = $key; $is_personal_root_cat = true; $catrow = array('cat_title'=>'');
    $out['album'] = eval('return ' . al_binding('album_personal_cat_admin.php','S_CAT_TITLE') . ';'); ml_expect($out['album'],sprintf($lang['Personal_Gallery_Of_User'],$expected),'Personal gallery root title');
    $i = 0; $postrow = array(array('user_id'=>$player_id,'username'=>$key,'poster_id'=>$player_id,'post_username'=>'','post_author_removed'=>0));
    eval($GLOBALS['ml_kb']); ml_expect($poster,$expected,'Registered knowledge-base commenter');
    foreach ($GLOBALS['ml_captions'] as $code) {
        $row = array('username'=>$key,'poster_id'=>$player_id,'post_username'=>'','post_edit_count'=>1,'post_edit_time'=>1700000000);
        eval($code); ml_expect($editmessage,sprintf($lang['Edited_time_total'],$expected,create_date('Y-m-d',1700000000,0),1),'Legacy caption fallback preserves the stored name');
    }
    $out['color'] = color_group_colorize_name($player_id);
    ml_expect($out['color'],$expected,'ColorGroups preserves entity text and literal backslashes');
    al_check(preg_match('/(?:[?&]|&amp;)u=' . $player_id . '(?:&amp;[^\"]*)?"/', $out['color']) === 1, 'Colored name keeps its numeric profile target');
    return $out;
}
function ml_render($labels, $id) {
    $specs = array(
        array('staff_body.tpl','L_ABOUT_USER',$labels['staff']), array('profile_options_body.tpl','L_USER',$labels['options']),
        array('confirm_body.tpl','MESSAGE_TEXT',$labels['arcade_modcp.php']), array('arcade_saved_body.tpl','HIGHSCORESAVED',$labels['score']),
        array('admin/album_personal_cat_new_body.tpl','S_CAT_TITLE',$labels['album'])
    );
    foreach ($specs as $spec) {
        $t = al_template('logs_body.tpl'); $t->set_filenames(array('body'=>$spec[0]));
        $t->assign_vars(array($spec[1]=>$spec[2], 'U_USER'=>'profile.php?u=' . $id));
        if ($spec[0] === 'staff_body.tpl') { $t->assign_block_vars('switch_view_profile', array()); }
        $html = al_html($t);
        if ($spec[1] === 'S_CAT_TITLE') {
            al_check(preg_match('/name="cat_title"[^>]*value="([^"]*)"/', $html, $m) === 1 && al_visible($m[1]) === al_visible($spec[2]), 'Real album input preserves the title without breaking attributes');
        } else { al_check(strpos($html,$spec[2]) !== false, 'Actual shipped template renders its name binding'); }
        if ($spec[1] === 'L_USER') { al_check(strpos($html,'href="profile.php?u=' . $id . '"') !== false, 'Real breadcrumb retains numeric account link'); }
        $GLOBALS['ml_templates']++;
    }
}
function ml_privacy_suite() {
    global $board_config, $userdata, $lang;
    foreach (array('english','german') as $locale) { foreach (array(false,true) as $logged) {
        ml_context($locale,$logged); $board_config['hide_removed_author_names'] = 1;
        foreach (array(false,true) as $removed) {
            $stored = phpbb_post_author_key('Old&Name');
            $post = array('user_id'=>null,'username'=>null,'poster_id'=>-1,'post_username'=>$stored,'post_author_removed'=>(int)$removed,
                'post_edit_count'=>1,'post_edit_time'=>1700000000);
            $expected = htmlspecialchars(!$logged && $removed ? $lang['Former_member'] : 'Old&Name', ENT_QUOTES, 'UTF-8');
            $i = 0; $postrow = array($post); eval($GLOBALS['ml_kb']); ml_expect($poster,$expected,'Genuine guest/former commenter visibility');
            foreach ($GLOBALS['ml_captions'] as $code) { $row=$post; eval($code);
                ml_expect($editmessage,sprintf($lang['Edited_time_total'],$expected,create_date('Y-m-d',1700000000,0),1),'Caption keeps former-member and genuine-guest policy');
            }
        }
        $row = array('username'=>'Original','poster_id'=>7,'post_username'=>'','post_edit_count'=>2,'post_edit_time'=>1700000000,
            'post_edit_user_id'=>8,'post_edit_username'=>'Editor&amp;Name');
        $board_config['hide_removed_author_names']=0;
        foreach ($GLOBALS['ml_captions'] as $code) { eval($code);
            ml_expect($editmessage,sprintf($lang['Edited_times_total'],'Editor&amp;Name',create_date('Y-m-d',1700000000,0),2),'Recorded editor still overrides fallback author');
        }
        $row['post_edit_count']=0; foreach ($GLOBALS['ml_captions'] as $code) { eval($code); al_check($editmessage==='','Never-edited post remains caption-free'); }
    }}
}
function ml_metadata_suite() {
    global $lang;
    $username='Unused'; $is_personal_root_cat=false; $catrow=array('cat_title'=>'Raw&amp;title');
    ml_expect(eval('return ' . al_binding('album_personal_cat_admin.php','S_CAT_TITLE') . ';'), 'Raw&amp;title', 'Non-root category uses its existing stored-title contract');
    $lang['Guest']='Guest&amp;literal'; $postrow=array(array('user_id'=>null,'username'=>null,'poster_id'=>-1,'post_username'=>'','post_author_removed'=>0)); $i=0;
    eval($GLOBALS['ml_kb']); ml_expect($poster,'Guest&amp;amp;literal','Raw translated guest label is not treated as a stored username');
}
function ml_suite($prepare, $after = null) {
    $id=100;
    foreach (array('english','german') as $locale) { ml_context($locale);
        foreach (al_names() as $raw) { foreach (array(ENT_QUOTES,ENT_COMPAT) as $flags) {
            ml_context($locale); $key=phpbb_username_key($raw,$flags); al_check($key!=='','Canonical member fixture');
            $record=call_user_func($prepare,++$id,$key); $out=ml_registered($record,$raw);
            if ($after !== null) { call_user_func($after,$record); } ml_render($out,$id);
        }}
        ml_context($locale); ml_metadata_suite();
    }
    ml_privacy_suite();
}
