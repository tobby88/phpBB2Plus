<?php

define('IN_PHPBB', true);
require dirname(dirname(__DIR__)) . '/phpBB2/includes/functions.php';

function album_output_test_same($expected, $actual, $message)
{
	if ($expected !== $actual)
	{
		fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
		exit(1);
	}
}

function album_output_test_true($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, $message . "\n");
		exit(1);
	}
}

album_output_test_same('Bild &amp; Titel', phpbb_stored_text('Bild &amp; Titel'), 'Historic entity-encoded text must stay readable.');
album_output_test_same('&lt;img src=x onerror=alert(1)&gt;', phpbb_stored_text('<img src=x onerror=alert(1)>'), 'Malformed stored text must not become markup.');
album_output_test_same('&quot; onclick=&quot;alert(1)', phpbb_stored_text('&quot; onclick=&quot;alert(1)'), 'Stored entities must be safely re-encoded for attributes.');
album_output_test_same('1,2,42', phpbb_sql_id_list('1, 2,2,0,-4,42'), 'Stored ID lists must retain unique positive integers only.');
album_output_test_same('2', phpbb_sql_id_list('1); DROP TABLE users,2'), 'Stored ID lists must discard malformed SQL fragments.');
album_output_test_same('7,8', phpbb_sql_id_list(array('7', array('8'), '8', '9'), 2), 'Stored ID lists must reject non-scalars and enforce their item limit.');
album_output_test_same('0', phpbb_sql_id_list('invalid,-1,0'), 'Empty ID lists must produce a safe never-match expression.');

$root = dirname(dirname(__DIR__)) . '/phpBB2/';
$common = file_get_contents($root . 'album_mod/album_common.php');
$hierarchy = file_get_contents($root . 'album_mod/album_hierarchy_sql.php');
$album_functions = file_get_contents($root . 'album_mod/album_functions.php');
$album_cat = file_get_contents($root . 'album_cat.php');
$memberlist = file_get_contents($root . 'album_mod/album_memberlist.php');
$showpage = file_get_contents($root . 'album_showpage.php');
$comment_edit = file_get_contents($root . 'album_comment_edit.php');
$personal_index = file_get_contents($root . 'album_personal_index.php');
$news = file_get_contents($root . 'includes/news.php');
$showpage_template = file_get_contents($root . 'templates/fisubsilversh/album_showpage_body.tpl');

album_output_test_true(strpos($common, 'return phpbb_stored_text($value);') !== false, 'Album output must use the shared stored-text normalizer.');
album_output_test_true(strpos($common, 'return phpbb_sql_id_list($value, $maximum_ids);') !== false, 'Album stored ID lists must use the shared integer-list normalizer.');
album_output_test_true(substr_count($album_functions, 'album_sql_id_list(') >= 3, 'Album access checks must normalize stored group lists.');
album_output_test_true(strpos($hierarchy, "album_sql_id_list(\$cat['cat_moderator_groups'])") !== false, 'Hierarchy moderator lookups must normalize stored group lists.');
album_output_test_true(strpos($album_cat, "album_sql_id_list(\$thiscat['cat_moderator_groups'])") !== false, 'Category moderator lookups must normalize stored group lists.');
album_output_test_true(strpos($hierarchy, "album_html_text(\$grouprows[\$j]['group_name'])") !== false && strpos($album_cat, "album_html_text(\$grouprows[\$j]['group_name'])") !== false, 'Album moderator group names must be escaped.');
album_output_test_true(substr_count($hierarchy, 'album_html_text(') >= 18, 'Picture grids and recent/highest/random listings must normalize stored labels.');
album_output_test_true(strpos($memberlist, '$album_view_type = ALBUM_LISTTYPE_PICTURES;') !== false, 'Member picture lists must initialize their view mode.');
album_output_test_true(strpos($memberlist, '$total_pics = 0;') !== false, 'Empty category sets must leave a defined picture count.');
album_output_test_true(strpos($memberlist, '$pics_per_page = min(100,') !== false, 'Member picture page sizes must be bounded.');
album_output_test_true(strpos($showpage, "smilies_pass(album_html_text(\$commentrow[\$i]['comment_text']))") !== false, 'Album comments must be normalized before markup expansion.');
album_output_test_true(strpos($showpage, "'PIC_DESC' => nl2br(album_html_text(\$thispic['pic_desc']))") !== false, 'Picture descriptions must be normalized before line-break markup.');
album_output_test_true(strpos($showpage, 'phpbb_profile_image_name($smilies_data') !== false && strpos($comment_edit, 'phpbb_profile_image_name($smilies_data') !== false, 'Album smiley paths must use validated filenames.');
album_output_test_true(strpos($showpage, '($i % 5) === 0') !== false && strpos($comment_edit, '($i % 5) === 0') !== false, 'Album smiley rows must use a working modulo boundary.');
album_output_test_true(strpos($showpage_template, "emotions(this.getAttribute('data-code'))") !== false, 'Album smiley codes must not be interpolated into JavaScript source.');
album_output_test_true(strpos($personal_index, '$items_per_page = max(1, min(100,') !== false, 'Personal-gallery index limits must be bounded.');
album_output_test_true(strpos($personal_index, "'USERNAME' => album_html_text(") !== false, 'Personal-gallery usernames must be escaped.');
album_output_test_true(strpos($news, 'return phpbb_stored_text($value);') !== false, 'News and Album output must share the same entity normalization.');

echo "Album output safety checks passed.\n";
