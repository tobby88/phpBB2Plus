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

community_listing_assert(strpos($compact, 'phpbb_request_scalar($_POST, \'start\'') !== false, 'compact Shoutbox offsets must be scalar');
community_listing_assert(strpos($compact, 'shoutbox_view.$phpEx?start=$start') !== false, 'compact Shoutbox pagination needs a named start parameter');
community_listing_assert(strpos($compact, '$display_username = phpbb_profile_text') !== false, 'compact Shoutbox usernames must be escaped');
community_listing_assert(strpos($shoutbox, 'min(1000000, max(0,') !== false && strpos($shoutbox_max, 'min(1000000, max(0,') !== false, 'Shoutbox offsets must be bounded');
community_listing_assert(strpos($kb_cat, "\$kb_news_sort_method = 't.article_date'") !== false, 'Knowledge Base category sort needs a default');
community_listing_assert(strpos($kb_cat, "\$articles_per_page = max(1") !== false, 'Knowledge Base pagination must not divide by zero');
community_listing_assert(strpos($kb_stats, "array('toprated', 'latest', 'mostpopular')") !== false, 'Knowledge Base statistic modes need an allowlist');

echo "Community listing safety tests passed.\n";
