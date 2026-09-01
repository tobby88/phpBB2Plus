<?php

function search_safety_assert($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, "Search safety test failed: $message\n");
		exit(1);
	}
}

$root = dirname(dirname(__DIR__));
$search = file_get_contents($root . '/phpBB2/search.php');

search_safety_assert(strpos($search, "in_array(\$mode, array('', 'results', 'searchuser', 'removebm'), true)") !== false, 'search modes must use an allowlist');
search_safety_assert(strpos($search, 'phpbb_request_id_array($_POST, \'topic_id_list\')') !== false, 'bookmark deletion must accept only positive scalar IDs');
search_safety_assert(strpos($search, "hash_equals((string) \$userdata['session_id'], \$submitted_sid)") !== false, 'bookmark deletion must verify the session token');
search_safety_assert(strpos($search, '$search_author_sql = $db->sql_escape($search_author)') !== false, 'author searches must use database-driver escaping');
search_safety_assert(strpos($search, '$match_word = $db->sql_escape(stripslashes(trim($split_search[$i])))') !== false, 'full-text terms must use database-driver escaping');
search_safety_assert(strpos($search, "addslashes('%' . str_replace('*', '', \$split_search[\$i])") === false, 'multibyte terms must not use generic addslashes');
search_safety_assert(strpos($search, '$result_array_sql = $db->sql_escape($result_array)') !== false, 'cached search data must use database-driver escaping');
search_safety_assert(strpos($search, '$search_session_id_sql = $db->sql_escape($userdata[\'session_id\'])') !== false, 'search cache ownership must use database-driver escaping');
search_safety_assert(strpos($search, "preg_match('/^[1-9][0-9]*\$/D', (string) \$cached_id)") !== false, 'cached result IDs must be validated before returning to SQL');
search_safety_assert(strpos($search, "preg_quote((string) \$split_word, '#')") !== false, 'highlight terms must be quoted before becoming regular expressions');
search_safety_assert(strpos($search, "is_dir(\$phpbb_root_path . 'language/lang_' . \$board_config['default_lang'])") !== false, 'search dictionaries must stay inside an installed language directory');
search_safety_assert((bool) preg_match('/\$search_results\s*=\s*\'\';.{0,400}\$split_search\s*=\s*array\(\);/s', $search), 'all search modes must initialize keyword highlight state');
search_safety_assert(strpos($search, "!empty(\$searchset) && isset(\$searchset[0]['topic_id'])") !== false, 'AJAX single-result redirects must tolerate stale result IDs');
search_safety_assert(strpos($search, 'auth(AUTH_ALL, $search_where, $userdata)') === false, 'hierarchy selectors such as Root must never be passed to auth() as numeric forum IDs');

echo "Search safety tests passed.\n";
