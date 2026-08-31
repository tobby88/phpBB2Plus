<?php

function moderator_test_assert($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, "Moderator action test failed: $message\n");
		exit(1);
	}
}

$root = dirname(dirname(__DIR__));
$compat = file_get_contents($root . '/phpBB2/includes/php_compat.php');
$modcp = file_get_contents($root . '/phpBB2/modcp.php');
$viewtopic = file_get_contents($root . '/phpBB2/viewtopic.php');
$ajax = file_get_contents($root . '/phpBB2/ajax.php');

moderator_test_assert(strpos($compat, 'function phpbb_session_action_token') !== false, 'the shared session action-token helper must exist');
moderator_test_assert(strpos($compat, "hash_hmac('sha256', \$scope . ':' . \$action . ':' . (int) \$target_id") !== false, 'tokens must bind scope, action and target');
moderator_test_assert(strpos($modcp, "array('lock', 'unlock', 'sticky', 'announce', 'normalise')") !== false, 'every direct topic mutation must be covered');
moderator_test_assert(strpos($modcp, "\$_SERVER['REQUEST_METHOD'] !== 'POST'") !== false, 'legacy GET mutations must enter the capability check');
moderator_test_assert(strpos($modcp, "phpbb_session_action_token('moderate-topic', \$mode, \$topic_id") !== false, 'the submitted capability must match the requested action and topic');
moderator_test_assert(substr_count($viewtopic, "phpbb_session_action_token('moderate-topic'") >= 4, 'all topic-toolbar mutation links must carry capabilities');
moderator_test_assert(substr_count($ajax, "phpbb_session_action_token('moderate-topic'") >= 2, 'AJAX lock responses must preserve protected fallback links');
moderator_test_assert(strpos($ajax, "in_array(\$mode, \$post_modes, true) && (\$request_method !== 'POST'") !== false, 'AJAX topic mutations must remain POST-only');

echo "Moderator action safety tests passed.\n";
