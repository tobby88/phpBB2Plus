<?php

function ajax_endpoint_assert($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, "AJAX endpoint safety test failed: $message\n");
		exit(1);
	}
}

$root = dirname(dirname(__DIR__));
$ajax = file_get_contents($root . '/phpBB2/ajax.php');
$functions = file_get_contents($root . '/phpBB2/includes/functions.php');
$core = file_get_contents($root . '/phpBB2/includes/javascript/ajax_core.js');
$topic = file_get_contents($root . '/phpBB2/includes/javascript/ajax_topicfunctions.js');
$viewtopic = file_get_contents($root . '/phpBB2/templates/fisubsilversh/viewtopic_body.tpl');
$search_posts = file_get_contents($root . '/phpBB2/templates/fisubsilversh/search_results_posts.tpl');

ajax_endpoint_assert(strpos($ajax, 'function ajax_request_value(') !== false, 'requests need one scalar boundary');
ajax_endpoint_assert(strpos($ajax, '$allowed_modes = array_merge(') !== false, 'modes need an explicit allowlist');
ajax_endpoint_assert(strpos($ajax, '$post_modes = array_merge(') !== false, 'mutations and previews must require POST');
ajax_endpoint_assert(strpos($ajax, "!isset(\$HTTP_POST_VARS['sid']) || !is_scalar(\$HTTP_POST_VARS['sid'])") !== false, 'POST actions need a scalar session token in their body');
ajax_endpoint_assert(substr_count($ajax, "empty(\$is_auth['auth_edit'])") >= 2, 'inline subject and text edits must enforce edit permission');
ajax_endpoint_assert(substr_count($ajax, "empty(\$is_auth['auth_view']) || empty(\$is_auth['auth_read'])") >= 4, 'poll, watch and mark endpoints must not expose unreadable forums');
ajax_endpoint_assert(strpos($ajax, 'urlencode($HTTP_GET_VARS') === false, 'nested highlight input must not reach urlencode');
ajax_endpoint_assert(strpos($ajax, '$username_sql = $db->sql_escape(') !== false, 'member lookup must use the database escape routine');
ajax_endpoint_assert(strpos($ajax, 'ORDER BY username LIMIT 50') !== false, 'member suggestions must be bounded');
ajax_endpoint_assert(strpos($ajax, '$safe_username = htmlspecialchars(') !== false, 'member options must escape account names');
ajax_endpoint_assert(substr_count($ajax, 'obtain_word_list($orig_word, $replacement_word);') >= 5, 'poll and PM previews must initialize their censor lists');
ajax_endpoint_assert(strpos($ajax, "'S_HIDDEN_FIELDS' => \$s_hidden_fields") === false, 'PM preview must not read an undefined hidden-field variable');
ajax_endpoint_assert(strpos($functions, "header('X-Content-Type-Options: nosniff')") !== false, 'AJAX XML must disable MIME sniffing');
ajax_endpoint_assert(strpos($core, 'encodeURIComponent(String(text))') !== false && strpos($core, 'text = escape(text)') === false, 'AJAX values must use UTF-8 form encoding');
ajax_endpoint_assert(strpos($functions, 'return addslashes(stripslashes((string) $source));') !== false, 'PHP must not URL-decode AJAX form values twice');
ajax_endpoint_assert(strpos($functions, 'ENT_COMPAT | ENT_SUBSTITUTE') !== false, 'invalid response bytes must not blank the AJAX XML result');
ajax_endpoint_assert(strpos($ajax, "preg_match('//u', stripslashes(\$message)) !== 1") !== false, 'inline edits must reject invalid UTF-8 before storage');
ajax_endpoint_assert(strpos($topic, 'function AJAXFullPostEdit(post_id, edit_url)') !== false, 'quick edits need a draft-preserving full-editor transition');
ajax_endpoint_assert(strpos($topic, "draftflag.name = 'ajax_draft';") !== false && strpos($topic, "draft.name = 'message';") !== false, 'the full-editor transition must POST the current draft');
ajax_endpoint_assert(strpos($viewtopic, 'onclick="return AJAXFullPostEdit({postrow.U_POST_ID}, this.href);"') !== false, 'topic quick edits must use the draft-preserving transition');
ajax_endpoint_assert(strpos($search_posts, 'onclick="return AJAXFullPostEdit({searchresults.U_POST_ID}, this.href);"') !== false, 'search-result quick edits must use the draft-preserving transition');
ajax_endpoint_assert(strpos($viewtopic, 'ajax_topicfunctions.js?v=20260831-2') !== false, 'updated AJAX topic code must bypass stale browser caches');

echo "AJAX endpoint safety checks passed.\n";
