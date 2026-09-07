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
require $root . '/phpBB2/includes/functions_search.php';

search_safety_assert(phpbb_search_return_chars(-1) === -1, 'all available characters must remain a distinct option');
search_safety_assert(phpbb_search_return_chars('-1') === -1, 'request and cached string values must retain full-text mode');
search_safety_assert(phpbb_search_return_chars(-2) === 0 && phpbb_search_return_chars(99999) === 1000, 'other excerpt lengths must remain bounded');
search_safety_assert(phpbb_search_return_chars(array()) === 200, 'malformed cached lengths must use the default');
search_safety_assert(phpbb_search_excerpt('Grüße 😀 weiter', '', 7) === 'Grüße 😀 ...', 'UTF-8 excerpts must retain whole umlauts and emoji');
search_safety_assert(phpbb_search_excerpt('äöü', '', 3) === 'äöü', 'exact-length excerpts must not gain an ellipsis');
search_safety_assert(phpbb_search_excerpt('A &amp; B', '', 3) === 'A &amp; ...', 'entities must count as one visible character');
search_safety_assert(phpbb_search_excerpt('&lt;img src=x onerror=alert(1)&gt;', '', 100) === '&lt;img src=x onerror=alert(1)&gt;', 'encoded HTML must never become active markup');
search_safety_assert(phpbb_search_excerpt('<b>Text</b> [b:abc]fett[/b:abc] [url=https://example.org]Link[/url]', 'abc', 100) === 'Text fett Link', 'excerpts must remove HTML and stored BBCode');
search_safety_assert(phpbb_search_excerpt('Text', '', 0) === '', 'zero characters must not render text or an ellipsis');
search_safety_assert(preg_match('//u', phpbb_search_excerpt("alt\xC3 kaputt", '', 30)) === 1, 'malformed legacy bytes must not invalidate the UTF-8 response');
$permissions = array(
	1 => array('auth_view' => true, 'auth_read' => true),
	2 => array('auth_view' => true, 'auth_read' => false),
	3 => array('auth_view' => false, 'auth_read' => true),
	4 => array(),
);
search_safety_assert(phpbb_search_readable_forums($permissions) === array(1), 'hidden, unreadable and missing permissions must deny search access');
$permissions[1]['auth_read'] = false;
search_safety_assert(phpbb_search_readable_forums($permissions) === array(), 'revoked read access must remove previously readable forums');
search_safety_assert(substr_count($search, 'phpbb_search_return_chars(') === 2, 'requests and restored searches must share length normalization');
search_safety_assert(strpos($search, '$readable_forums = phpbb_search_readable_forums($this_auth)') !== false, 'result display must apply current forum permissions');
search_safety_assert(strpos($search, '$result_from_sql .= " AND f.forum_id IN ($readable_forum_sql)"') !== false, 'all result modes must constrain their SQL to authorized forums');
search_safety_assert(strpos($search, "'SELECT COUNT(*) AS total' . \$result_from_sql") !== false && strpos($search, '$sql = $result_select_sql . $result_from_sql') !== false, 'counts and displayed rows must use identical joins and permissions');
search_safety_assert(strpos($search, 'AND t.forum_id = f.forum_id') !== false, 'inconsistent post and topic forum assignments must not expose private topics');
$ajax = file_get_contents($root . '/phpBB2/ajax.php');
search_safety_assert(strpos($ajax, 'phpbb_search_excerpt($message, $bbcode_uid, $return_chars)') !== false, 'AJAX edits must use the same UTF-8-safe excerpt renderer');
search_safety_assert(strpos($search, '$postrow[$i]') === false, 'full-text rendering must read flags from the actual result set');

search_safety_assert(strpos($search, "in_array(\$mode, array('', 'results', 'searchuser', 'removebm'), true)") !== false, 'search modes must use an allowlist');
search_safety_assert(strpos($search, 'phpbb_request_id_array($_POST, \'topic_id_list\')') !== false, 'bookmark deletion must accept only positive scalar IDs');
search_safety_assert(strpos($search, "hash_equals((string) \$userdata['session_id'], \$submitted_sid)") !== false, 'bookmark deletion must verify the session token');
search_safety_assert(strpos($search, '$search_author_sql = $db->sql_escape($search_author)') !== false, 'author searches must use database-driver escaping');
search_safety_assert(strpos($search, "poster_id = \" . ANONYMOUS . \" AND post_username LIKE '\$search_author_sql'") !== false, 'author-only searches must include guest display names');
search_safety_assert(substr_count($search, "p.poster_id = \" . ANONYMOUS . \" AND p.post_username LIKE '\$search_author_sql'") === 2, 'filtered topic and post searches must include guest display names');
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
search_safety_assert(strpos($search, '$ct_rules') === false, 'search rendering must not depend on an undefined legacy CrackerTracker rule array');
search_safety_assert(strpos($search, "\$raw_message = '';" ) !== false, 'non-editable search results must initialize the AJAX editor payload');

echo "Search safety tests passed.\n";
