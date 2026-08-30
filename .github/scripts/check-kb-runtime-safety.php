<?php

function kb_runtime_assert($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, "Knowledge Base runtime test failed: $message\n");
		exit(1);
	}
}

$root = dirname(dirname(__DIR__));
$functions = file_get_contents($root . '/phpBB2/includes/functions_kb.php');
$admin = file_get_contents($root . '/phpBB2/admin/admin_kb_art.php');
$moderator = file_get_contents($root . '/phpBB2/includes/kb_moderator.php');
$search = file_get_contents($root . '/phpBB2/kb_search.php');

kb_runtime_assert(strpos($functions, "\$article_link = '<a") !== false, 'article link must not overwrite the database row');
kb_runtime_assert(strpos($functions, "\$postrow[\$i]['link_rating']") === false, 'KB ratings must not read an unrelated post row');
kb_runtime_assert(substr_count($functions, '$article_rating / $article_totalvotes') === 2, 'both KB list variants must use article rating fields');
kb_runtime_assert(strpos($functions, "\$author = ( \$username != '' )") === false, 'guest authors must not depend on an undefined username variable');
kb_runtime_assert(strpos($functions, "phpbb_board_url('admin/admin_kb_art.'") !== false, 'notification PM must link to a fresh AdminCP session');
kb_runtime_assert(strpos($functions, '$approve_pm_view') === false, 'notification PM must not persist privileged action links');
kb_runtime_assert(strpos($functions, 'function kb_admin_article_action_form') !== false, 'AdminCP article actions must use POST forms');
kb_runtime_assert(substr_count($admin, 'phpbb_admin_require_post_session();') >= 3, 'article mutations must require POST session tokens');
kb_runtime_assert(strpos($admin, "isset(\$_POST['c'])") !== false, 'article deletion confirmation must be submitted by POST');
kb_runtime_assert(strpos($admin, "isset(\$_POST['a']) ? (int) \$_POST['a']") !== false, 'admin article ID must be normalized');
kb_runtime_assert(strpos($admin, 'DELETE FROM " . KB_VOTES_TABLE') !== false, 'AdminCP article deletion must remove vote rows');
kb_runtime_assert(strpos($moderator, 'DELETE FROM " . KB_VOTES_TABLE') !== false, 'moderator article deletion must remove vote rows');
kb_runtime_assert(strpos($functions, '$db->sql_escape($search_matches[$i])') !== false, 'indexed Knowledge Base words must be escaped at the SQL boundary');
kb_runtime_assert(strpos($search, '$current_results[$article_id] = 1') !== false, 'AND searches must track normalized article IDs');
kb_runtime_assert(strpos($search, '$db->sql_escape(str_replace') !== false, 'Knowledge Base search terms must be escaped at the SQL boundary');
kb_runtime_assert(strpos($search, '$result_list[$post_id]') === false, 'Knowledge Base result intersection must not use an undefined post ID');
kb_runtime_assert(strpos($search, 'KB_ARTICLE_TABLE') === false, 'Knowledge Base search must use the defined plural article table constant');
kb_runtime_assert(strpos($search, "preg_match('/^[1-9][0-9]*\$/D', \$search_id_value)") !== false, 'Knowledge Base cached search IDs must be positive integers');
kb_runtime_assert(strpos($search, "preg_match('/^[1-9][0-9]*\$/D', (string) \$cached_id)") !== false, 'cached Knowledge Base result IDs must be validated');
kb_runtime_assert(strpos($search, "\$db->sql_escape(serialize(\$store_search_data))") !== false, 'serialized Knowledge Base search state must use driver escaping');
kb_runtime_assert(strpos($search, "\$db->sql_escape(\$userdata['session_id'])") !== false, 'Knowledge Base search sessions must use driver escaping');
kb_runtime_assert(strpos($search, 'AND t.approved = 1') !== false, 'Knowledge Base search results must not expose unapproved articles');
kb_runtime_assert(strpos($search, 'mode=results&amp;search_id=') !== false, 'Knowledge Base pagination must retain results mode');
kb_runtime_assert(strpos($search, "\$multibyte_charset = 'utf-8, big5, shift_jis, euc-kr, gb2312';") !== false, 'Knowledge Base search must initialize its charset strategy locally');
kb_runtime_assert(strpos($search, 'str_replace("\\\'", "\'\'", $result_array)') === false, 'legacy manual search cache escaping must be removed');

echo "Knowledge Base runtime safety tests passed.\n";
