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

forum_admin_assert(strpos($admin, '$forum_write_modes = array(') !== false, 'forum write modes need one explicit allowlist');
forum_admin_assert(strpos($admin, 'phpbb_admin_require_post_session();') !== false, 'forum writes must require a POST session token');
forum_admin_assert(strpos($admin, 'function admin_forum_action_button') !== false, 'ordering and synchronization need POST buttons');
forum_admin_assert(strpos($admin, "\$_POST['forum_admin_action']") !== false, 'list actions must be read from POST');
forum_admin_assert(strpos($admin, "\$_POST['move']") !== false, 'ordering direction must be read from POST');
forum_admin_assert(substr_count($admin, 'phpbb_admin_session_field()') >= 5, 'all forum edit, delete and index forms must carry a session token');
forum_admin_assert(strpos($admin, "in_array(\$move, array(-15, 15), true)") !== false, 'forum/category movement must use a strict direction allowlist');
forum_admin_assert(strpos($admin, "sync('forum', intval(\$_GET") === false, 'forum synchronization must not execute from a GET ID');
forum_admin_assert(strpos($admin, 'function admin_forum_post_scalar(') !== false, 'forum mutations need typed scalar request helpers');
forum_admin_assert(substr_count($admin, '$db->sql_escape(') >= 10, 'forum and category text must use the active database escape routine');
forum_admin_assert(strpos($admin, 'function admin_forum_resource_value(') !== false, 'forum links and icons need a safe scheme boundary');
forum_admin_assert(strpos($admin, 'str_replace("\\\'", "\'\'", $_POST') === false, 'legacy quote replacement must not protect forum SQL');

foreach (glob($root . '/phpBB2/templates/*/admin/forum_admin_body.tpl') as $forum_index_file)
{
	$template = file_get_contents($forum_index_file);
	forum_admin_assert(strpos($template, '{S_SESSION_FIELD}') !== false, basename(dirname(dirname($forum_index_file))) . ' forum index must render the session token');
	forum_admin_assert(strpos($template, 'S_FORUM_RESYNC_BUTTON') !== false, basename(dirname(dirname($forum_index_file))) . ' forum resync must render as a POST button');
	forum_admin_assert(strpos($template, 'S_FORUM_MOVE_UP_BUTTON') !== false, basename(dirname(dirname($forum_index_file))) . ' forum ordering must render as POST buttons');
}

foreach (glob($root . '/phpBB2/templates/*/admin/forum_edit_body.tpl') as $forum_edit_file)
{
	$template = file_get_contents($forum_edit_file);
	foreach (array('{ICON}', '{FORUM_LINK}', '{COUNT_POSTS_YES}', '{S_HIDDEN_FIELDS}') as $marker)
	{
		forum_admin_assert(strpos($template, $marker) !== false, basename(dirname(dirname($forum_edit_file))) . ' forum editor is missing ' . $marker);
	}
}

foreach (glob($root . '/phpBB2/templates/*/admin/category_edit_body.tpl') as $category_edit_file)
{
	$template = file_get_contents($category_edit_file);
	foreach (array('{CAT_DESCRIPTION}', '{ICON}', '{S_CAT_LIST}', '{S_HIDDEN_FIELDS}') as $marker)
	{
		forum_admin_assert(strpos($template, $marker) !== false, basename(dirname(dirname($category_edit_file))) . ' category editor is missing ' . $marker);
	}
}

echo "Forum administration safety tests passed.\n";
