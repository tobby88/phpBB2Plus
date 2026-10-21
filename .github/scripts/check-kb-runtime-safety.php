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
$publication = file_get_contents($root . '/phpBB2/includes/functions_kb_publication.php');
$deletion = file_get_contents($root . '/phpBB2/includes/functions_kb_delete_storage.php');
$writing = file_get_contents($root . '/phpBB2/includes/functions_kb_write_storage.php');
$topic_removal = file_get_contents($root . '/phpBB2/includes/functions_moderation.php');
$admin = file_get_contents($root . '/phpBB2/admin/admin_kb_art.php');
$moderator = file_get_contents($root . '/phpBB2/includes/kb_moderator.php');
$search = file_get_contents($root . '/phpBB2/kb_search.php');
$category = file_get_contents($root . '/phpBB2/includes/kb_cat.php');
$rate = file_get_contents($root . '/phpBB2/includes/kb_rate.php');
$rating_storage = file_get_contents($root . '/phpBB2/includes/functions_kb_rating_storage.php');
$add = file_get_contents($root . '/phpBB2/includes/kb_add.php');
$edit = file_get_contents($root . '/phpBB2/includes/kb_edit.php');
kb_runtime_assert(strpos($edit, "empty(\$kb_config['allow_edit'])") !== false, 'disabled editing must gate the actual editor, not only its link');
kb_runtime_assert(strpos($writing, "\$this->settings['allow_edit'] !== '1'") !== false, 'ordinary authors must honor the current locked edit policy at save');
kb_runtime_assert(strpos($writing, 'validate_username(phpbb_post_author_key($this->guest_name),false,0,true,true)') !== false, 'KB guest names must reuse actual local forum validation without remote spam requests');
kb_runtime_assert(strpos($writing, "\$this->guest_policy?'REPEATABLE READ':'READ COMMITTED'") !== false && strpos($writing, '$this->locking_validation') !== false, 'guest name checks must pin current rules and empty name/rule ranges without changing registered writer isolation');

kb_runtime_assert(strpos($functions, "\$article_link = '<a") !== false, 'article link must not overwrite the database row');
kb_runtime_assert(strpos($functions, "\$postrow[\$i]['link_rating']") === false, 'KB ratings must not read an unrelated post row');
kb_runtime_assert(substr_count($functions, '$article_rating / $article_totalvotes') === 2, 'both KB list variants must use article rating fields');
kb_runtime_assert(strpos($functions, "\$author = ( \$username != '' )") === false, 'guest authors must not depend on an undefined username variable');
kb_runtime_assert(strpos($functions, "\$post_time_order = 'ASC';") !== false, 'embedded comments must use a defined SQL sort direction');
kb_runtime_assert(strpos($functions, "\$topic_id = (int) \$topic_id;") !== false, 'embedded comment topic IDs must be normalized');
kb_runtime_assert(strpos($functions, "phpbb_profile_text(\$postrow[\$i]['post_subject'])") !== false, 'embedded comment subjects must be escaped');
kb_runtime_assert(strpos($writing, "phpbb_board_url('admin/admin_kb_art.'") !== false, 'notification PM must link to a fresh AdminCP session');
kb_runtime_assert(strpos($functions, '$approve_pm_view') === false, 'notification PM must not persist privileged action links');
kb_runtime_assert(strpos($functions, 'function kb_admin_article_action_form') !== false, 'AdminCP article actions must use POST forms');
kb_runtime_assert(substr_count($admin, 'phpbb_admin_require_post_session();') >= 3, 'article mutations must require POST session tokens');
kb_runtime_assert(strpos($admin, "isset(\$_POST['c'])") !== false, 'article deletion confirmation must be submitted by POST');
kb_runtime_assert(strpos($admin, 'kb_counter_category_id($article_id_value)') !== false && strpos($moderator, 'kb_counter_category_id($article_id_value)') !== false, 'article actions must validate complete bounded IDs instead of partially casting request values');
kb_runtime_assert(strpos($deletion, "'DELETE FROM ' . KB_VOTES_TABLE") !== false, 'owned article deletion must remove vote rows');
kb_runtime_assert(strpos($admin, "kb_delete_article(\$db, \$article_id, 'acp')") !== false && strpos($moderator, "kb_delete_article(\$db, \$article_id, 'moderator')") !== false, 'both delete controllers must use their exact current-authority context');
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
kb_runtime_assert(strpos($functions, 'CAST(COALESCE(number_articles, 0) AS DECIMAL(65,0))') !== false, 'category counter deltas must use the current stored value without unsigned underflow');
kb_runtime_assert(strpos($functions, 'function kb_record_exists($table, $column, $id)') !== false, 'article references must be checked against existing records');
kb_runtime_assert(strpos($functions, 'function kb_limit_text($value, $length)') !== false, 'Knowledge Base text fields must respect their schema bounds');
kb_runtime_assert(strpos($functions, 'LEFT JOIN " . USERS_TABLE . " u ON u.user_id = t.article_author_id') !== false, 'article lists must retain guest-authored articles');
kb_runtime_assert(strpos($functions, "\$mode = is_null(\$topic_id) ? 'newtopic' : 'reply';") !== false, 'discussion mirroring must initialize its posting mode');
kb_runtime_assert(strpos($functions, '$message_sql = $db->sql_escape($message);') !== false, 'mirrored discussion messages must use driver SQL escaping');
kb_runtime_assert(strpos($functions, '$subject_sql = $db->sql_escape($subject);') !== false, 'mirrored discussion subjects must use driver SQL escaping');
kb_runtime_assert(strpos($functions, '$message_die(GENERAL_ERROR') === false, 'discussion failures must call the configured error handler');
kb_runtime_assert(strpos($functions, 'function kb_delete_discussion_topic(') === false, 'unsafe autocommit discussion cleanup must be retired');
kb_runtime_assert(strpos($deletion, 'phpbb_delete_moderated_topics_owned(') !== false, 'KB cleanup must reuse the transactional topic worker');
kb_runtime_assert(strpos($topic_removal, 'CASE WHEN user_posts > 0 THEN user_posts - 1 ELSE 0 END') !== false, 'discussion cleanup must not create negative user post counts');
kb_runtime_assert(strpos($topic_removal, 'array(BOOKMARK_TABLE, TOPICS_WATCH_TABLE, TOPIC_VIEW_TABLE)') !== false, 'discussion cleanup must remove topic references');
kb_runtime_assert(strpos($topic_removal, 'array(VOTE_RESULTS_TABLE, VOTE_USERS_TABLE)') !== false && strpos($topic_removal, "'DELETE FROM ' . VOTE_DESC_TABLE") !== false, 'discussion cleanup must remove scoped poll data');
kb_runtime_assert(strpos($topic_removal, '$storage->detach_post_files($post_id);') !== false && strpos($deletion, '!$storage->cleanup()') !== false && strpos($deletion, 'delete_attachment(') === false, 'detach attachment references in owned SQL and clean bytes only after confirmed commit');
kb_runtime_assert(substr_count($admin, 'kb_delete_article(') === 1 && substr_count($moderator, 'kb_delete_article(') === 1, 'both deletion controllers use centralized article cleanup');
kb_runtime_assert(strpos($admin, 'SET user_posts = user_posts -') === false && strpos($moderator, 'SET user_posts = user_posts -') === false, 'article controllers must not retain incomplete topic deletion copies');
kb_runtime_assert(strpos($functions, 'function kb_remove_article_words($article_id)') !== false, 'article search index removal must be centralized');
kb_runtime_assert(strpos($functions, 'function kb_clear_search_cache()') !== false, 'article changes must invalidate cached searches');
kb_runtime_assert(strpos($functions, 'kb_remove_article_words($post_id);') !== false, 'article reindexing must replace stale word matches');
kb_runtime_assert(strpos($writing, 'else { kb_remove_article_words($article_id); }') !== false, 'unapproved edits must leave no searchable word matches');
kb_runtime_assert(strpos($edit, "\$_POST['topic']") === false, 'article editors must not be able to relink cleanup to an arbitrary forum topic');
kb_runtime_assert(strpos($writing, "kb_counter_category_id(\$article['topic_id'], true)") !== false && strpos($writing, "'UPDATE ' . KB_ARTICLES_TABLE . ' SET topic_id='") !== false, 'article edits use the locked trusted discussion and persist new discussions');
kb_runtime_assert(strpos($writing, "(int)\$old['approved'] === 1 && (\$approved !== 1 || (int)\$old['article_category_id'] !== \$category)") !== false, 'article edits decrement the old category only when an approved article leaves it');
kb_runtime_assert(strpos($writing, "\$approved === 1 && (!\$old || (int)\$old['approved'] !== 1 || (int)\$old['article_category_id'] !== \$category)") !== false, 'article transitions increment the new category once');
kb_runtime_assert(strpos($writing, 'add_kb_words($article_id, $body, $title);') !== false, 'article titles must be included in the owned search index');
kb_runtime_assert(strpos($publication, "((int)\$article['approved'] === 1) !== (\$approved === 1)") !== false, 'Locked current approval state controls counter transitions');
kb_runtime_assert(substr_count($admin, 'kb_publish_approval(') === 2 && substr_count($moderator, 'kb_publish_approval(') === 2, 'Both controllers share the owned approval/unapproval path');
kb_runtime_assert(substr_count($functions, "phpbb_stored_text(\$article['article_description'])") >= 2, 'all article list descriptions must escape stored text without removing literal backslashes');
kb_runtime_assert(substr_count($functions, "phpbb_stored_text(\$article['article_title'])") >= 2, 'all article list titles must escape stored text without removing literal backslashes');
kb_runtime_assert(strpos($functions, "phpbb_stored_text(\$category['category_details'])") !== false, 'category descriptions must escape stored text without request unquoting');
kb_runtime_assert(strpos($search, '$article_title = phpbb_stored_text($article_title);') !== false, 'search result titles must escape stored text without request unquoting');
kb_runtime_assert(strpos($search, "'ARTICLE_DESCRIPTION' => phpbb_stored_text(\$article['article_description'])") !== false, 'search result descriptions must escape stored text without request unquoting');
kb_runtime_assert(strpos($search, "phpbb_profile_text(\$article['registered_username'])") !== false, 'registered search result authors must retain literal text and be escaped');
kb_runtime_assert(strpos($search, 'phpbb_profile_text($guest_username)') !== false, 'guest search result authors must retain literal text and be escaped');
foreach(array($add,$edit)as$controller){kb_runtime_assert(strpos($controller,"phpbb_request_raw_value(\$_POST['message'])")!==false&&strpos($controller,"phpbb_request_raw_value(\$_POST['article_name'])")!==false,'actual writer controllers must remove request quoting exactly once');}
kb_runtime_assert(strpos($edit,"'ARTICLE_TITLE' => phpbb_profile_text(\$article_name)")!==false&&strpos($edit,"stripslashes(\$row['article_body'])")===false,'editor forms retain literal metadata and canonical body bytes');
kb_runtime_assert(strpos($category, '$category_name_plain =') !== false, 'category existence checks must use an unformatted value');
kb_runtime_assert(strpos($category, "strtoupper((string) \$kb_config['news_sort_par']) === 'ASC'") !== false, 'category sort direction must be normalized');
kb_runtime_assert(substr_count($category, "min(1000, (int) \$kb_config['art_pagination'])") === 2, 'category page sizes must be bounded');

$article = file_get_contents($root . '/phpBB2/includes/kb_article.php');
kb_runtime_assert(strpos($article, '$author_name_plain =') !== false && strpos($article, '$username !=') === false, 'guest articles must not read an undefined username');
kb_runtime_assert(strpos($article, '(int) $approved !== 1 && !$can_view_unapproved') !== false, 'direct article views must hide pending edits from unrelated visitors');
kb_runtime_assert(strpos($article, "\$kb_config['comments'] && \$topic_id > 0") !== false, 'comment rendering must require an existing discussion relation');
kb_runtime_assert(strpos($article, 'insert_post(') === false, 'read-only article views must not create forum discussions');
kb_runtime_assert(strpos($article, '$article_title = phpbb_stored_text($article_title);') !== false, 'article titles must normalize and escape legacy stored text');
kb_runtime_assert(strpos($article, '$kb_art_description  = phpbb_stored_text') !== false, 'article descriptions must normalize and escape legacy stored text');
kb_runtime_assert(strpos($article, "\$topic = array('topic_id' => 0, 'topic_replies' => 0);") !== false, 'articles without comment topics need defined pagination state');
kb_runtime_assert(strpos($article, "\$pagination = '';") !== false, 'articles without displayed comments need an initialized pagination value');
kb_runtime_assert(strpos($rate, "empty(\$kb_config['allow_rating'])") !== false, 'direct rating requests must respect the global rating switch');
kb_runtime_assert(strpos($rate, "empty(\$kb_config['allow_anonymos_rating'])") !== false, 'direct guest ratings must respect the anonymous rating switch');
kb_runtime_assert(strpos($rate,'kb_rate_article($db,') !== false && strpos($rate,'INSERT INTO') === false && strpos($rate,'SET article_rating') === false,'Rating controller must use the owning worker, not autocommit vote/statistic copies');
kb_runtime_assert(strpos($rating_storage,"'UPDATE '.KB_ARTICLES_TABLE.' SET article_rating=article_rating+'") !== false,'Owned rating statistics update is present');
kb_runtime_assert(strpos($rating_storage,"$"."owner->commit()") !== false && strpos($rating_storage,"$"."owner->settings['votes_check_userid']==='1'&&(int)$"."actor['user_id']>0") !== false,'Owned rating commit and registered-only user identity duplicate policy are present');
kb_runtime_assert(strpos($rate, "(int) \$article['approved'] !== 1 && !\$can_rate_unapproved") !== false, 'rating pages must hide pending edits from unrelated visitors');
kb_runtime_assert(substr_count($rate, "phpbb_stored_text(\$article['article_title'])") === 2, 'rating messages must normalize and escape stored article titles');
kb_runtime_assert(strpos($writing, "((int)\$actor['user_id'] > 0 ? (int)\$actor['user_id'] : 0)") !== false, 'anonymous articles must use the supported guest author ID');
kb_runtime_assert(strpos($writing, "isset(\$owner->hierarchy['expected'][\$category])") !== false && strpos($writing, "'SELECT id FROM ' . KB_TYPES_TABLE") !== false, 'all owned writes must reference locked valid categories and types');
kb_runtime_assert(strpos($add, "kb_write_article(\$db, 'add'") !== false && strpos($edit, "kb_write_article(\$db, 'edit'") !== false, 'both real controllers must invoke the owned writer');
kb_runtime_assert(strpos($writing, "\$title = kb_limit_text(\$input['title'],255)") !== false, 'new article titles must be stored as plain UTF-8 text');
kb_runtime_assert(strpos($add, '$title = htmlspecialchars($posted_name);') === false && strpos($edit, '$title = htmlspecialchars($posted_name);') === false, 'article writes must not add a second HTML encoding layer');

echo "Knowledge Base runtime safety tests passed.\n";
