<?php

function community_listing_assert($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, "Community listing safety test failed: $message\n");
		exit(1);
	}
}

$root = dirname(dirname(__DIR__));
$compact = file_get_contents($root . '/phpBB2/shoutbox_view.php');
$shoutbox = file_get_contents($root . '/phpBB2/shoutbox.php');
$shoutbox_max = file_get_contents($root . '/phpBB2/shoutbox_max.php');
$kb_cat = file_get_contents($root . '/phpBB2/includes/kb_cat.php');
$kb_stats = file_get_contents($root . '/phpBB2/includes/kb_stats.php');
$kb_functions = file_get_contents($root . '/phpBB2/includes/functions_kb.php');
$memberlist = file_get_contents($root . '/phpBB2/memberlist.php');
$groupcp = file_get_contents($root . '/phpBB2/groupcp.php');
$profile = file_get_contents($root . '/phpBB2/includes/usercp_viewprofile.php');
$profile_fields = file_get_contents($root . '/phpBB2/includes/functions_profile_fields.php');
$viewtopic = file_get_contents($root . '/phpBB2/viewtopic.php');
$functions = file_get_contents($root . '/phpBB2/includes/functions.php');
$profile_template = file_get_contents($root . '/phpBB2/templates/fisubsilversh/profile_view_body.tpl');

community_listing_assert(strpos($compact, 'phpbb_request_scalar($_POST, \'start\'') !== false, 'compact Shoutbox offsets must be scalar');
community_listing_assert(strpos($compact, 'shoutbox_view.$phpEx?start=$start') !== false, 'compact Shoutbox pagination needs a named start parameter');
foreach (array($compact, $shoutbox_max) as $author_source)
{
	community_listing_assert(strpos($author_source, "phpbb_stored_text(\$shout_row['username'])") !== false && strpos($author_source, "phpbb_post_author_name(\$shout_row,'shout_username','shout_user_id','shout_author_removed')") !== false, 'Shoutbox account keys and guest labels must use their escaped display contracts');
}
community_listing_assert(strpos($shoutbox, 'min(1000000, max(0,') !== false && strpos($shoutbox_max, 'min(1000000, max(0,') !== false, 'Shoutbox offsets must be bounded');
community_listing_assert(strpos($kb_cat, "\$kb_news_sort_method = 't.article_date'") !== false, 'Knowledge Base category sort needs a default');
community_listing_assert(strpos($kb_cat, '$articles_per_page = kb_stats_page_size(') !== false && preg_match('/^function kb_stats_page_size\(.*?^\}/ms',$kb_functions,$kb_size_helper)===1 && preg_match('/return\s+max\(1,\s*min\(1000/',$kb_size_helper[0])===1, 'Knowledge Base pagination must use its bounded nonzero page-size helper');
community_listing_assert(strpos($kb_stats, "array('toprated', 'latest', 'mostpopular')") !== false, 'Knowledge Base statistic modes need an allowlist');
community_listing_assert(strpos($memberlist, 'redirect=memberlist.$phpEx') !== false && strpos($memberlist, "!\$userdata['session_logged_in']") !== false, 'the member list must require login');
community_listing_assert(strpos($groupcp, 'redirect=groupcp.$phpEx') !== false && strpos($groupcp, "!\$userdata['session_logged_in']") !== false, 'user-group listings must require login');
community_listing_assert(strpos($profile, 'if ( !phpbb_profile_details_visible() )') !== false && strpos($functions, 'function phpbb_profile_details_visible()') !== false, 'custom profile fields must use the shared authenticated-viewer gate');
community_listing_assert(strpos($profile_fields, "empty(\$userdata['session_logged_in'])") !== false, 'custom topic profile fields must be hidden from guests');
community_listing_assert(strpos($viewtopic, "\$userdata['session_logged_in'] && \$postrow[\$i]['user_birthday'] != 999999") !== false, 'age and zodiac details beside posts must require login');
community_listing_assert(strpos($functions, "if (\$userdata['session_logged_in'])\n\t{\n\t\t\$nav_links['author']") !== false, 'guest metadata must not advertise the protected member list');
community_listing_assert(strpos($profile_template, 'BEGIN switch_registered_profile_details') !== false, 'birthday and zodiac profile rows must require login');

define('IN_PHPBB', true);
$phpbb_root_path = $root . '/phpBB2/';
$phpEx = 'php';
require $phpbb_root_path . 'includes/functions.php';
require $phpbb_root_path . 'includes/sessions.php';
require $phpbb_root_path . 'includes/template.php';
$theme = array('template_name'=>'fisubsilversh');
$board_config = array('default_lang'=>'english','xs_use_cache'=>0,'xs_auto_compile'=>0,'xs_auto_recompile'=>0);
foreach (array(false,true) as $populated) {
    $template = (new ReflectionClass('Template'))->newInstanceWithoutConstructor();
    $template->vars = &$template->_tpldata['.'][0];
    $template->load_config($phpbb_root_path.'templates/fisubsilversh', false);
    $template->set_filenames(array('body'=>'modcp_body.tpl'));
    $template->assign_vars(array('HAS_TOPICS'=>$populated,'L_NO_TOPICS'=>'No topics here','L_SAVE_CHANGES'=>'Save','L_CANCEL'=>'Cancel'));
    if ($populated) {
        foreach (array(100=>true,200=>false) as $id=>$editable) {
            $template->assign_block_vars('topicrow',array('TOPIC_FIRST_POST_ID'=>$id,'TOPIC_ID'=>$id,
                'EDITABLE'=>$editable,'TOPIC_TITLE'=>phpbb_stored_text('Grüße &amp; "Hallo"'),'U_VIEW_TOPIC'=>'topic.php?t='.$id));
        }
    }
    ob_start();
    try { $template->pparse('body'); $html=ob_get_contents(); } finally { ob_end_clean(); }
    community_listing_assert((strpos($html,'No topics here') === false) === $populated
        && (strpos($html,'name="move"') !== false) === $populated,'Empty moderator lists explain the empty result');
    if ($populated) {
        community_listing_assert(strpos($html,'id="topictitle_100" value="Grüße &amp; &quot;Hallo&quot;"') !== false
            && strpos($html,'id="topictitle_200"') === false && strpos($html,'href="topic.php?t=200"') !== false
            && strpos($html,'value="Save"') !== false && strpos($html,'value="Cancel"') !== false,
            'Ordinary titles retain their text in the editor and moved links open their destination');
    }
}
echo "Community listing safety tests passed.\n";
