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

echo "AJAX endpoint safety checks passed.\n";
