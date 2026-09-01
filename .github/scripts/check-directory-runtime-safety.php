<?php

function directory_runtime_assert($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, "Directory runtime safety test failed: $message\n");
		exit(1);
	}
}

$root = dirname(dirname(__DIR__));
$memberlist = file_get_contents($root . '/phpBB2/memberlist.php');
$topic_view = file_get_contents($root . '/phpBB2/topic_view_users.php');
$staff = file_get_contents($root . '/phpBB2/staff.php');
$viewonline = file_get_contents($root . '/phpBB2/viewonline.php');

directory_runtime_assert(strpos($memberlist, "phpbb_request_scalar(\$_GET, 'start'") !== false, 'member-list offsets must be scalar');
directory_runtime_assert(strpos($topic_view, 'auth(AUTH_ALL, $forum_id') !== false, 'topic viewers must inherit forum permissions');
directory_runtime_assert(strpos($topic_view, "empty(\$is_auth['auth_view']) || empty(\$is_auth['auth_read'])") !== false, 'topic viewers must require view and read access');
directory_runtime_assert(strpos($topic_view, "\$_GET['start'] + 1") === false, 'topic viewer rows must use the normalized offset');
directory_runtime_assert(strpos($staff, '$last_post_length = max(1, min(200, (int) $last_post_length));') !== false, 'staff post-title lengths must be bounded');
directory_runtime_assert(strpos($staff, '$last_post_limit = max(1, min(100, (int) $last_post_limit));') !== false, 'staff post batches must be bounded');
directory_runtime_assert(strpos($staff, '$excluded_user_ids = phpbb_sql_id_list($exclude_users);') !== false, 'staff exclusion lists must be normalized');
directory_runtime_assert(strpos($staff, '$except_forums = phpbb_sql_id_list($except_forum_ids);') !== false, 'staff forum exclusions must contain integer IDs only');
directory_runtime_assert(strpos($staff, "\$except_forums = '\\'start\\''") === false, 'staff queries must not use a string sentinel in numeric ID lists');
directory_runtime_assert(strpos($staff, "empty(\$view_profile['user_allowhtml'])") !== false, 'staff signatures must honor the profile owner HTML preference');
directory_runtime_assert(strpos($staff, "\$userdata['user_allowhtml']") === false, 'staff signatures must not inherit the viewing user HTML preference');
directory_runtime_assert(strpos($staff, '$special_users') === false, 'staff configuration must not advertise an unused special-user list');
directory_runtime_assert(strpos($staff, ': $user_sig;') !== false, 'staff signatures must survive an empty censor list');
directory_runtime_assert(strpos($staff, '($total_posts > 0)') !== false, 'staff post percentages must guard empty boards');
directory_runtime_assert(strpos($staff, "include(\$phpbb_root_path .'includes/page_tail.'.\$phpEx);\n\texit;") !== false, 'staff popup must stop after its own page tail');
directory_runtime_assert(strpos($viewonline, "empty(\$is_auth_ary[\$session_page]['auth_view'])") !== false, 'online locations must tolerate stale or inaccessible forums');
directory_runtime_assert(strpos($viewonline, "intval(\$row['session_topic'])") !== false, 'online topic IDs must be integer-bound before queries');

echo "Directory runtime safety tests passed.\n";
