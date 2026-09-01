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
$memberlist = file_get_contents($root . '/phpBB2/memberlist.php');
$groupcp = file_get_contents($root . '/phpBB2/groupcp.php');
$profile = file_get_contents($root . '/phpBB2/includes/usercp_viewprofile.php');
$profile_fields = file_get_contents($root . '/phpBB2/includes/functions_profile_fields.php');
$viewtopic = file_get_contents($root . '/phpBB2/viewtopic.php');
$functions = file_get_contents($root . '/phpBB2/includes/functions.php');
$profile_template = file_get_contents($root . '/phpBB2/templates/fisubsilversh/profile_view_body.tpl');

community_listing_assert(strpos($compact, 'phpbb_request_scalar($_POST, \'start\'') !== false, 'compact Shoutbox offsets must be scalar');
community_listing_assert(strpos($compact, 'shoutbox_view.$phpEx?start=$start') !== false, 'compact Shoutbox pagination needs a named start parameter');
community_listing_assert(strpos($compact, '$display_username = phpbb_profile_text') !== false, 'compact Shoutbox usernames must be escaped');
community_listing_assert(strpos($shoutbox, 'min(1000000, max(0,') !== false && strpos($shoutbox_max, 'min(1000000, max(0,') !== false, 'Shoutbox offsets must be bounded');
community_listing_assert(strpos($kb_cat, "\$kb_news_sort_method = 't.article_date'") !== false, 'Knowledge Base category sort needs a default');
community_listing_assert(strpos($kb_cat, "\$articles_per_page = max(1") !== false, 'Knowledge Base pagination must not divide by zero');
community_listing_assert(strpos($kb_stats, "array('toprated', 'latest', 'mostpopular')") !== false, 'Knowledge Base statistic modes need an allowlist');
community_listing_assert(strpos($memberlist, 'redirect=memberlist.$phpEx') !== false && strpos($memberlist, "!\$userdata['session_logged_in']") !== false, 'the member list must require login');
community_listing_assert(strpos($groupcp, 'redirect=groupcp.$phpEx') !== false && strpos($groupcp, "!\$userdata['session_logged_in']") !== false, 'user-group listings must require login');
community_listing_assert(strpos($profile, "if ( !\$userdata['session_logged_in'] )") !== false, 'custom profile fields must be hidden from guests');
community_listing_assert(strpos($profile_fields, "empty(\$userdata['session_logged_in'])") !== false, 'custom topic profile fields must be hidden from guests');
community_listing_assert(strpos($viewtopic, "\$userdata['session_logged_in'] && \$postrow[\$i]['user_birthday'] != 999999") !== false, 'age and zodiac details beside posts must require login');
community_listing_assert(strpos($functions, "if (\$userdata['session_logged_in'])\n\t{\n\t\t\$nav_links['author']") !== false, 'guest metadata must not advertise the protected member list');
community_listing_assert(strpos($profile_template, 'BEGIN switch_registered_profile_details') !== false, 'birthday and zodiac profile rows must require login');

echo "Community listing safety tests passed.\n";
