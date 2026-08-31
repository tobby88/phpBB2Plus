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
ajax_endpoint_assert(strpos($core, "params = 'ajax_utf8=1'") !== false, 'current AJAX requests must identify their UTF-8 transport');
ajax_endpoint_assert(strpos($functions, 'function phpbb_ajax_decode_legacy_escape($source)') !== false, 'cached legacy AJAX clients need a safe migration decoder');
ajax_endpoint_assert(strpos($functions, "\$is_utf8_transport ? \$source : phpbb_ajax_decode_legacy_escape(\$source)") !== false, 'AJAX decoding must distinguish current and cached clients');
ajax_endpoint_assert(strpos($functions, 'ENT_COMPAT | ENT_SUBSTITUTE') !== false, 'invalid response bytes must not blank the AJAX XML result');
ajax_endpoint_assert(strpos($ajax, "preg_match('//u', stripslashes(\$message)) !== 1") !== false, 'inline edits must reject invalid UTF-8 before storage');
ajax_endpoint_assert(strpos($topic, 'function AJAXFullPostEdit(post_id, edit_url)') !== false, 'quick edits need a draft-preserving full-editor transition');
ajax_endpoint_assert(strpos($topic, "draftflag.name = 'ajax_draft';") !== false && strpos($topic, "draft.name = 'message';") !== false, 'the full-editor transition must POST the current draft');
ajax_endpoint_assert(strpos($viewtopic, 'onclick="return AJAXFullPostEdit({postrow.U_POST_ID}, this.href);"') !== false, 'topic quick edits must use the draft-preserving transition');
ajax_endpoint_assert(strpos($search_posts, 'onclick="return AJAXFullPostEdit({searchresults.U_POST_ID}, this.href);"') !== false, 'search-result quick edits must use the draft-preserving transition');
ajax_endpoint_assert(strpos($viewtopic, 'ajax_topicfunctions.js?v=20260831-2') !== false, 'updated AJAX topic code must bypass stale browser caches');

define('IN_PHPBB', true);
require_once $root . '/phpBB2/includes/functions.php';

$HTTP_GET_VARS = array();
$HTTP_POST_VARS = array();
$legacy_value = "Hi%0Aaufgel%F6st%20%u20AC%20%uD83D%uDE00%20100%25";
$legacy_expected = "Hi\naufgelöst € 😀 100%";
ajax_endpoint_assert(stripslashes(utf8_rawurldecode(addslashes($legacy_value))) === $legacy_expected, 'cached escape()-based clients must decode to valid UTF-8');

$HTTP_POST_VARS = array('ajax_utf8' => '1');
$utf8_value = "ÄÖÜ äöü ß € + %F6 O'Brien\\Pfad";
ajax_endpoint_assert(stripslashes(utf8_rawurldecode(addslashes($utf8_value))) === $utf8_value, 'current UTF-8 clients must preserve text and literal percent sequences exactly');

echo "AJAX endpoint safety checks passed.\n";
