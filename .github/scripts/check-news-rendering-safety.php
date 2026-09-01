<?php

define('IN_PHPBB', true);
$root = dirname(dirname(__DIR__));
$phpbb_root_path = $root . '/phpBB2/';
$phpEx = 'php';
$lang = array();

require $phpbb_root_path . 'includes/functions.php';
require $phpbb_root_path . 'includes/news.php';

function news_render_test_same($expected, $actual, $message)
{
	if ($expected !== $actual)
	{
		fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
		exit(1);
	}
}

function news_render_test_true($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, $message . "\n");
		exit(1);
	}
}

$reflection = new ReflectionClass('NewsModule');
$news = $reflection->newInstanceWithoutConstructor();
$news->root_path = 'https://forum.example.test/';
$news->config = array('news_path' => 'images/news');
$theme = array('template_name' => 'fisubsilversh');

news_render_test_same('Normal &amp; sicher', $news->htmlText('Normal &amp; sicher'), 'Historic stored entities must remain readable.');
news_render_test_same('&lt;script&gt;alert(1)&lt;/script&gt;', $news->htmlText('<script>alert(1)</script>'), 'Stored news labels must not become markup.');
news_render_test_same('https://forum.example.test/templates/fisubsilversh/images/news/general.png', $news->imageUrl('general.png'), 'Valid news images must retain their public path.');
news_render_test_same('https://forum.example.test/images/spacer.gif', $news->imageUrl('../general.png'), 'News image traversal must fail closed.');
news_render_test_same('https://forum.example.test/images/spacer.gif', $news->imageUrl('payload.svg'), 'Active news image formats must not be embedded.');

$entry = file_get_contents($root . '/phpBB2/news_index.php');
$module = file_get_contents($root . '/phpBB2/includes/news.php');
$portal = file_get_contents($root . '/phpBB2/portal.php');
news_render_test_true(strpos($entry, '$content->render( );') !== false, 'The standalone News page must use the normalized shared dispatcher.');
news_render_test_true(strpos($module, "if (\$news_mode == 'categories')") !== false, 'The dispatcher must recognize the category URL emitted by the template.');
news_render_test_true(substr_count($module, '$this->htmlText(') >= 5, 'Stored News titles, categories and comment labels must use shared output escaping.');
news_render_test_true(strpos($portal, '$content->imageUrl(') !== false && strpos($portal, '$content->htmlText(') !== false, 'Portal news categories must use the safe News renderers.');

echo "News rendering safety checks passed.\n";
