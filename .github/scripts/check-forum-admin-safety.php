<?php

function forum_admin_assert($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, "Forum administration safety test failed: $message\n");
		exit(1);
	}
}

$root = dirname(dirname(__DIR__));
$admin = file_get_contents($root . '/phpBB2/admin/admin_forums.php');
$subsilver = file_get_contents($root . '/phpBB2/templates/subSilver/admin/forum_admin_body.tpl');
$fisubsilver = file_get_contents($root . '/phpBB2/templates/fisubsilversh/admin/forum_admin_body.tpl');

forum_admin_assert(strpos($admin, '$forum_write_modes = array(') !== false, 'forum write modes need one explicit allowlist');
forum_admin_assert(strpos($admin, 'phpbb_admin_require_post_session();') !== false, 'forum writes must require a POST session token');
forum_admin_assert(strpos($admin, 'function admin_forum_action_button') !== false, 'ordering and synchronization need POST buttons');
forum_admin_assert(strpos($admin, "\$_POST['forum_admin_action']") !== false, 'list actions must be read from POST');
forum_admin_assert(strpos($admin, "\$_POST['move']") !== false, 'ordering direction must be read from POST');
forum_admin_assert(substr_count($admin, 'phpbb_admin_session_field()') >= 5, 'all forum edit, delete and index forms must carry a session token');
forum_admin_assert(strpos($admin, "in_array(\$move, array(-15, 15), true)") !== false, 'forum/category movement must use a strict direction allowlist');
forum_admin_assert(strpos($admin, "sync('forum', intval(\$_GET") === false, 'forum synchronization must not execute from a GET ID');

foreach (array($subsilver, $fisubsilver) as $template)
{
	forum_admin_assert(strpos($template, '{S_SESSION_FIELD}') !== false, 'forum index form must render the session token');
	forum_admin_assert(strpos($template, 'S_FORUM_RESYNC_BUTTON') !== false, 'forum resync must render as a POST button');
	forum_admin_assert(strpos($template, 'S_FORUM_MOVE_UP_BUTTON') !== false, 'forum ordering must render as POST buttons');
}

echo "Forum administration safety tests passed.\n";
