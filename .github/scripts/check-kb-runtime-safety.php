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
$category = file_get_contents($root . '/phpBB2/includes/kb_cat.php');
$rate = file_get_contents($root . '/phpBB2/includes/kb_rate.php');
$add = file_get_contents($root . '/phpBB2/includes/kb_add.php');
$edit = file_get_contents($root . '/phpBB2/includes/kb_edit.php');

kb_runtime_assert(strpos($functions, "\$article_link = '<a") !== false, 'article link must not overwrite the database row');
kb_runtime_assert(strpos($functions, "\$postrow[\$i]['link_rating']") === false, 'KB ratings must not read an unrelated post row');
kb_runtime_assert(substr_count($functions, '$article_rating / $article_totalvotes') === 2, 'both KB list variants must use article rating fields');
kb_runtime_assert(strpos($functions, "\$author = ( \$username != '' )") === false, 'guest authors must not depend on an undefined username variable');
kb_runtime_assert(strpos($functions, "\$post_time_order = 'ASC';") !== false, 'embedded comments must use a defined SQL sort direction');
kb_runtime_assert(strpos($functions, "\$topic_id = (int) \$topic_id;") !== false, 'embedded comment topic IDs must be normalized');
kb_runtime_assert(strpos($functions, "phpbb_profile_text(\$postrow[\$i]['post_subject'])") !== false, 'embedded comment subjects must be escaped');
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
kb_runtime_assert(strpos($functions, '$articles_in_cat = max(0, min(1000, (int) $articles_in_cat));') !== false, 'article list sizes must be bounded');
kb_runtime_assert(strpos($functions, 'function get_kb_nav($parent, $visited = array())') !== false, 'category navigation must detect cyclic parent relationships');
kb_runtime_assert(strpos($functions, 'foreach (array_reverse($path_kb_array) as $path_part)') !== false, 'category navigation must not read beyond its path');
kb_runtime_assert(strpos($functions, '$allowed_sort_methods = array(') !== false, 'article list SQL sorting must be allowlisted');
kb_runtime_assert(strpos($functions, 'function update_kb_number($id, $change, $visited = array())') !== false, 'category counters must detect cyclic parent relationships');
kb_runtime_assert(strpos($functions, "\$new_number = max(0, (int) \$kb_cat['number_articles'] + \$change);") !== false, 'category article counters must use numeric addition');
kb_runtime_assert(strpos($functions, 'function kb_record_exists($table, $column, $id)') !== false, 'article references must be checked against existing records');
kb_runtime_assert(strpos($functions, 'function kb_limit_text($value, $length)') !== false, 'Knowledge Base text fields must respect their schema bounds');
kb_runtime_assert(strpos($functions, 'LEFT JOIN " . USERS_TABLE . " u ON u.user_id = t.article_author_id') !== false, 'article lists must retain guest-authored articles');
kb_runtime_assert(strpos($functions, "\$mode = is_null(\$topic_id) ? 'newtopic' : 'reply';") !== false, 'discussion mirroring must initialize its posting mode');
kb_runtime_assert(strpos($functions, '$message_sql = $db->sql_escape($message);') !== false, 'mirrored discussion messages must use driver SQL escaping');
kb_runtime_assert(strpos($functions, '$subject_sql = $db->sql_escape($subject);') !== false, 'mirrored discussion subjects must use driver SQL escaping');
kb_runtime_assert(strpos($functions, '$message_die(GENERAL_ERROR') === false, 'discussion failures must call the configured error handler');
kb_runtime_assert(strpos($functions, 'function kb_delete_discussion_topic($topic_id)') !== false, 'Knowledge Base discussion cleanup must be centralized');
kb_runtime_assert(strpos($functions, 'GREATEST(user_posts - $post_count, 0)') !== false, 'discussion cleanup must not create negative user post counts');
kb_runtime_assert(strpos($functions, 'array(BOOKMARK_TABLE, TOPICS_WATCH_TABLE, TOPIC_VIEW_TABLE)') !== false, 'discussion cleanup must remove topic references');
kb_runtime_assert(strpos($functions, 'array(VOTE_DESC_TABLE, VOTE_RESULTS_TABLE, VOTE_USERS_TABLE)') !== false, 'discussion cleanup must remove poll data');
kb_runtime_assert(strpos($functions, 'delete_attachment(array_values($post_ids))') !== false, 'discussion cleanup must remove post attachments');
kb_runtime_assert(substr_count($admin, 'kb_delete_discussion_topic(') === 1, 'AdminCP deletion must use centralized discussion cleanup');
kb_runtime_assert(substr_count($moderator, 'kb_delete_discussion_topic(') === 1, 'moderator deletion must use centralized discussion cleanup');
kb_runtime_assert(strpos($admin, 'SET user_posts = user_posts -') === false && strpos($moderator, 'SET user_posts = user_posts -') === false, 'article controllers must not retain incomplete topic deletion copies');
kb_runtime_assert(strpos($functions, 'function kb_remove_article_words($article_id)') !== false, 'article search index removal must be centralized');
kb_runtime_assert(strpos($functions, 'function kb_clear_search_cache()') !== false, 'article changes must invalidate cached searches');
kb_runtime_assert(strpos($functions, 'kb_remove_article_words($post_id);') !== false, 'article reindexing must replace stale word matches');
kb_runtime_assert(strpos($edit, 'kb_remove_article_words($article_id);') !== false, 'unapproved edits must leave no searchable word matches');
kb_runtime_assert(strpos($admin, "(int) \$managed_article['approved'] !== 1") !== false, 'AdminCP approval must only increment counters on a state transition');
kb_runtime_assert(strpos($moderator, "(int) \$moderated_article['approved'] === 1") !== false, 'moderator unapproval must only decrement counters for approved articles');
kb_runtime_assert(substr_count($functions, "phpbb_stored_text(stripslashes(\$article['article_description']))") >= 2, 'all article list descriptions must normalize and escape legacy stored text');
kb_runtime_assert(substr_count($functions, "phpbb_stored_text(stripslashes(\$article['article_title']))") >= 2, 'all article list titles must normalize and escape legacy stored text');
kb_runtime_assert(strpos($functions, "phpbb_stored_text(stripslashes(\$category['category_details']))") !== false, 'category descriptions must normalize and escape legacy stored text');
kb_runtime_assert(strpos($search, '$article_title = phpbb_stored_text(stripslashes($article_title));') !== false, 'search result titles must normalize and escape legacy stored text');
kb_runtime_assert(strpos($search, "'ARTICLE_DESCRIPTION' => phpbb_stored_text(stripslashes(\$article['article_description']))") !== false, 'search result descriptions must normalize and escape legacy stored text');
kb_runtime_assert(strpos($search, "phpbb_profile_text(stripslashes(\$article['registered_username']))") !== false, 'registered search result authors must be escaped');
kb_runtime_assert(strpos($search, 'phpbb_profile_text(stripslashes($guest_username))') !== false, 'guest search result authors must be escaped');
kb_runtime_assert(strpos($category, '$category_name_plain =') !== false, 'category existence checks must use an unformatted value');
kb_runtime_assert(strpos($category, "strtoupper((string) \$kb_config['news_sort_par']) === 'ASC'") !== false, 'category sort direction must be normalized');
kb_runtime_assert(substr_count($category, "min(1000, (int) \$kb_config['art_pagination'])") === 2, 'category page sizes must be bounded');

$article = file_get_contents($root . '/phpBB2/includes/kb_article.php');
kb_runtime_assert(strpos($article, '$author_name_plain =') !== false && strpos($article, '$username !=') === false, 'guest articles must not read an undefined username');
kb_runtime_assert(strpos($article, '$article_title = phpbb_stored_text($article_title);') !== false, 'article titles must normalize and escape legacy stored text');
kb_runtime_assert(strpos($article, '$kb_art_description  = phpbb_stored_text') !== false, 'article descriptions must normalize and escape legacy stored text');
kb_runtime_assert(strpos($article, "\$topic = array('topic_id' => 0, 'topic_replies' => 0);") !== false, 'articles without comment topics need defined pagination state');
kb_runtime_assert(strpos($article, "\$pagination = '';") !== false, 'articles without displayed comments need an initialized pagination value');
kb_runtime_assert(strpos($rate, "empty(\$kb_config['allow_rating'])") !== false, 'direct rating requests must respect the global rating switch');
kb_runtime_assert(strpos($rate, "empty(\$kb_config['allow_anonymos_rating'])") !== false, 'direct guest ratings must respect the anonymous rating switch');
kb_runtime_assert(substr_count($rate, "phpbb_stored_text(\$article['article_title'])") === 2, 'rating messages must normalize and escape stored article titles');
kb_runtime_assert(strpos($add, "\$author_id = \$userdata['session_logged_in'] ? (int) \$userdata['user_id'] : 0;") !== false, 'anonymous articles must use the supported guest author ID');
kb_runtime_assert(substr_count($add, 'kb_record_exists(') >= 2, 'new articles must reference existing categories and types');
kb_runtime_assert(substr_count($edit, 'kb_record_exists(') >= 2, 'edited articles must reference existing categories and types');
kb_runtime_assert(strpos($add, '$title = $posted_name;') !== false && strpos($edit, '$title = $posted_name;') !== false, 'new article titles must be stored as plain UTF-8 text');
kb_runtime_assert(strpos($add, '$title = htmlspecialchars($posted_name);') === false && strpos($edit, '$title = htmlspecialchars($posted_name);') === false, 'article writes must not add a second HTML encoding layer');

echo "Knowledge Base runtime safety tests passed.\n";
