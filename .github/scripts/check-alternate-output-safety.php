<?php

function alternate_output_assert($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, "Alternate output safety test failed: $message\n");
		exit(1);
	}
}

$root = dirname(dirname(__DIR__));
$export = file_get_contents($root . '/phpBB2/export.php');
$print = file_get_contents($root . '/phpBB2/printview.php');
$rss = file_get_contents($root . '/phpBB2/news_rss.php');
$news = file_get_contents($root . '/phpBB2/includes/news.php');
$header = file_get_contents($root . '/phpBB2/includes/page_header.php');
$admin_header = file_get_contents($root . '/phpBB2/admin/page_header_admin.php');
$functions = file_get_contents($root . '/phpBB2/includes/functions.php');

alternate_output_assert(strpos($export, "phpbb_request_scalar(\$_GET, POST_TOPIC_URL)") !== false, 'export topic IDs must be scalar');
alternate_output_assert(strpos($export, "header('X-Content-Type-Options: nosniff')") !== false, 'downloads must opt out of MIME sniffing');
alternate_output_assert(strpos($print, 'auth(AUTH_ALL, $forum_id') !== false, 'print view must evaluate all source-forum access');
alternate_output_assert(strpos($print, "empty(\$is_auth['auth_view']) || empty(\$is_auth['auth_read'])") !== false, 'print view must require view and read access');
alternate_output_assert(strpos($rss, 'application/rss+xml; charset=UTF-8') !== false, 'RSS must advertise an RSS/XML media type and charset');
alternate_output_assert(strpos($news, "str_replace(']]>', ']]]]><![CDATA[>'") !== false, 'RSS bodies must not terminate their CDATA section');
alternate_output_assert(strpos($news, 'ENT_QUOTES | ENT_XML1') !== false, 'RSS metadata must use XML escaping');
alternate_output_assert(strpos($news, "&amp;cat_id=' . \$_GET") === false, 'news pagination must not concatenate raw category parameters');
alternate_output_assert(substr_count($header, '$nav_title = phpbb_stored_text(') === 2, 'navigation metadata titles must escape stored labels');
alternate_output_assert(strpos($header, '$nav_url = htmlspecialchars(append_sid(') !== false && strpos($header, '$nav_url = htmlspecialchars((string) $nested_array[\'url\']') !== false, 'navigation metadata URLs must be attribute escaped');
alternate_output_assert(strpos($functions, "phpbb_stored_text(\$forum_rows[\$j]['forum_name'])") !== false, 'forum jump-box labels must escape stored names');
alternate_output_assert(strpos($functions, "phpbb_stored_text(\$category_rows[\$i]['cat_title'])") !== false, 'category jump-box labels must escape stored names');
alternate_output_assert(strpos($header, "'SITENAME' => \$sitename_html") !== false, 'public headers must escape the stored site name');
alternate_output_assert(strpos($header, "'SITE_DESCRIPTION' => \$site_description_html") !== false, 'public headers must escape the stored site description');
alternate_output_assert(strpos($header, "'PAGE_TITLE' => \$page_title_html") !== false, 'public headers must normalize and escape page titles');
alternate_output_assert(substr_count($admin_header, 'phpbb_stored_text(') >= 3, 'administration headers must escape stored site and page labels');

echo "Alternate output safety tests passed.\n";
