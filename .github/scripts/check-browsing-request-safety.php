<?php

function browsing_request_assert($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, "Browsing request safety test failed: $message\n");
		exit(1);
	}
}

$root = dirname(dirname(__DIR__));
$topic = file_get_contents($root . '/phpBB2/viewtopic.php');
$forum = file_get_contents($root . '/phpBB2/viewforum.php');
$header = file_get_contents($root . '/phpBB2/includes/page_header.php');
$portal = file_get_contents($root . '/phpBB2/portal.php');
$news = file_get_contents($root . '/phpBB2/includes/news.php');

browsing_request_assert(strpos($topic, "\$view_mode = phpbb_request_scalar(\$_GET, 'view')") !== false, 'topic navigation modes must be scalar');
browsing_request_assert(strpos($topic, 'in_array($post_days, $previous_days, true)') !== false, 'topic day filters must use the supported allowlist');
browsing_request_assert(strpos($forum, 'in_array($topic_days, $previous_days, true)') !== false, 'forum day filters must use the supported allowlist');
browsing_request_assert(strpos($forum, "phpbb_request_scalar(\$_POST, 'selected_id'") !== false, 'hierarchy selection must be scalar');
browsing_request_assert(strpos($header, "preg_match('/^(Root|[") !== false, 'navigation keys must follow the hierarchy grammar');
browsing_request_assert(strpos($portal, "substr(phpbb_request_scalar(\$_GET, 'key'), 0, 100)") !== false, 'archive keys must be scalar and bounded');
browsing_request_assert(substr_count($news, "min(1000000, intval(phpbb_request_scalar(\$_GET, 'start'") >= 2, 'news offsets must be bounded at every render path');
browsing_request_assert(strpos($news, "\$news_mode = phpbb_request_scalar(\$_GET, 'news')") !== false, 'news modes must be scalar');

echo "Browsing request safety tests passed.\n";
