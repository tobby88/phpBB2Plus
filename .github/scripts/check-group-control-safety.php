<?php

function group_control_test_assert($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, "Group control test failed: $message\n");
		exit(1);
	}
}

$root = dirname(dirname(__DIR__));
$groupcp = file_get_contents($root . '/phpBB2/groupcp.php');
$subsilver = file_get_contents($root . '/phpBB2/templates/subSilver/groupcp_info_body.tpl');
$fisubsilver = file_get_contents($root . '/phpBB2/templates/fisubsilversh/groupcp_info_body.tpl');

group_control_test_assert(strpos($groupcp, 'function groupcp_require_post_session') !== false, 'group writes must share one session guard');
group_control_test_assert(strpos($groupcp, "\$request_method !== 'POST'") !== false, 'the session guard must reject non-POST writes');
group_control_test_assert(strpos($groupcp, "hash_equals((string) \$userdata['session_id']") !== false, 'the session guard must compare the current session safely');
group_control_test_assert(substr_count($groupcp, 'groupcp_require_post_session(') >= 5, 'every group write family must use the shared guard');
group_control_test_assert(strpos($groupcp, "AND g.group_type = \" . GROUP_OPEN") !== false, 'subscriptions must target an existing open group');
group_control_test_assert(strpos($groupcp, 'WHERE NOT EXISTS (') !== false, 'subscriptions must avoid duplicate membership rows');
group_control_test_assert(strpos($groupcp, '!$db->sql_affectedrows()') !== false, 'duplicate subscription races must not send a second request');
group_control_test_assert(strpos($groupcp, '$db->sql_escape($username)') !== false, 'moderator-entered usernames must use database-driver escaping');
group_control_test_assert(strpos($groupcp, '$sid !== $userdata') === false, 'ad-hoc session comparisons must not return');
group_control_test_assert(strpos($subsilver, '{S_HIDDEN_FIELDS}') !== false && strpos($fisubsilver, '{S_HIDDEN_FIELDS}') !== false, 'both shipped group forms must carry their hidden session field');

echo "Group control safety tests passed.\n";
