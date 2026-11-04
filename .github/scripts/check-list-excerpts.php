<?php
// Actual display expressions and shipped templates; no forum/configuration/SQL.
require __DIR__ . '/admin-account-label-fixture.php';
foreach (array('phpbb_profile_text', 'phpbb_text_excerpt') as $function) {
    al_check(preg_match('/^function '.$function.'\(.*?^\}/ms', $al_functions, $match) === 1, 'Actual excerpt/text helper');
    eval($match[0]);
}
require $al_root . 'includes/functions_post_subject.php';
$recent_code = al_fragment('recent.php', '$word_censor = ( count(', '$topic_type =');
$staff_code = al_fragment('staff.php', '$last_post_title = ( !empty(', '$template->assign_block_vars(\'switch_view_profile.last_posts\'');
$forum_code = al_fragment('recent.php', '$forum_name = phpbb_profile_text(', '$template->assign_block_vars(\'recent\'');
$cases = $templates = 0;
function excerpt_render($kind, $label, $expected) {
    $template = al_template('logs_body.tpl');
    $template->set_filenames(array('body'=>$kind === 'recent' ? 'recent_body.tpl' : 'staff_body.tpl'));
    if ($kind === 'recent') {
        $template->assign_block_vars('recent', array('TOPIC_TITLE'=>$label, 'FORUM_NAME'=>$label,
            'U_VIEW_TOPIC'=>'viewtopic.php?t=7', 'U_VIEW_FORUM'=>'viewforum.php?f=9'));
    } else {
        $template->assign_block_vars('switch_view_profile', array());
        $template->assign_block_vars('switch_view_profile.last_posts', array('LAST_POST_TITLE'=>$label,
            'LAST_POST_URL'=>'viewtopic.php?p=7', 'FORUM_NAME'=>'Forum', 'FORUM_URL'=>'viewforum.php?f=9'));
    }
    $html = al_html($template);
    al_check(preg_match('~href="viewtopic\.php\?'.($kind === 'recent' ? 't' : 'p').'=7"[^>]*>(.*?)</a>~s', $html, $match) === 1
        && al_visible($match[1]) === $expected, 'Actual topic link retains complete escaped excerpt');
    if ($kind === 'recent') {
        al_check(preg_match('~href="viewforum\.php\?f=9"[^>]*>(.*?)</a></span>~s', $html, $match) === 1
            && al_visible($match[1]) === $expected, 'Actual forum link closes after its complete escaped label');
    }
    al_check(strpos($html, "\xEF\xBF\xBD") === false && strpos($html, '<em>') === false, 'No broken characters or active title markup');
    $GLOBALS['templates']++;
}
foreach (array('english', 'german') as $locale) {
    al_locale($locale);
    foreach (array(30, 40) as $limit) {
        $vectors = array(
            array(str_repeat('a', $limit-1).'äZ', str_repeat('a', $limit-1).'ä...'),
            array(str_repeat('a', $limit-1).'😀Z', str_repeat('a', $limit-1).'😀...'),
            array(str_repeat('ä', $limit), str_repeat('ä', $limit)),
            array(str_repeat('a', $limit), str_repeat('a', $limit)),
            array('C:\\docs\\'.str_repeat('z', 50), 'C:\\docs\\'.str_repeat('z', $limit-8).'...'),
            array('A&B <em> "quoted"', 'A&B <em> "quoted"'),
            array('Literal &#39; &amp;', 'Literal &#39; &amp;'),
            array('0', '0'),
            array('short', str_repeat('ä', $limit).'...', array('/short/u'), array(str_repeat('ä', $limit+1))),
            array(str_repeat('x', 60), 'ok', array('/x+/u'), array('ok'))
        );
        foreach ($vectors as $vector) {
            $stored = phpbb_storage_subject($vector[0]);
            al_check(is_string($stored), 'Actual canonical subject writer accepts synthetic input');
            $orig_word = isset($vector[2]) ? $vector[2] : array();
            $replacement_word = isset($vector[3]) ? $vector[3] : array();
            $line = array(array('topic_title'=>$stored)); $i = 0; $topic_length = $limit;
            eval($recent_code);
            $label = eval('return '.al_binding('recent.php', 'TOPIC_TITLE').';');
            al_check($label === htmlspecialchars($vector[1], ENT_QUOTES, 'UTF-8') && $line[0]['topic_title'] === $stored, 'Recent actual censored subject preserves raw display and storage');
            excerpt_render('recent', $label, $vector[1]); $cases++;
            foreach (array(false, true) as $fallback) {
                $last_posts = array('post_subject'=>$fallback ? '' : $stored, 'topic_title'=>$stored);
                $last_post_length = $limit; eval($staff_code);
                $label = eval('return '.al_binding('staff.php', 'LAST_POST_TITLE').';');
                al_check($label === htmlspecialchars($vector[1], ENT_QUOTES, 'UTF-8') && $last_posts['topic_title'] === $stored, 'Staff actual subject/fallback preserves complete display and storage');
                excerpt_render('staff', $label, $vector[1]); $cases++;
            }
        }
        foreach (array(array(str_repeat('a', $limit-1).'äZ', str_repeat('a', $limit-1).'ä...'),
            array(str_repeat('a', $limit-2).'&', str_repeat('a', $limit-2).'&'),
            array('Literal &amp; <em>', 'Literal &amp; <em>')) as $vector) {
            $line = array(array('forum_name'=>$vector[0])); $i=0;
            $board_config['last_topic_title_length']=$limit; eval($forum_code);
            al_check($forum_name === htmlspecialchars($vector[1], ENT_QUOTES, 'UTF-8') && $line[0]['forum_name'] === $vector[0], 'Forum raw metadata is shortened before HTML escaping');
            excerpt_render('recent', $forum_name, $vector[1]); $cases++;
        }
    }
}
foreach (array(array('', 4, ''), array('0', 1, '0'), array('abc', 0, ''), array('abc', -1, ''),
    array('ä😀', 1, 'ä...'), array('ä😀', 2, 'ä😀'), array("bad\xC3", 3, ''), array('abc', PHP_INT_MAX, 'abc')) as $index=>$case) {
    $actual=phpbb_text_excerpt($case[0], $case[1]);
    al_check($actual === $case[2], 'Helper boundary '.$index.' remains defined without optional extensions: '.bin2hex($actual)); $cases++;
}
echo 'List excerpts: '.$cases.' cases, '.$templates." actual templates PASS.\n";
restore_error_handler();
