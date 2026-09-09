<?php

$root = dirname(dirname(__DIR__));
$files = array(
	'forum permissions' => (string) file_get_contents($root . '/phpBB2/admin/admin_forumauth.php'),
	'group administration' => (string) file_get_contents($root . '/phpBB2/admin/admin_groups.php'),
	'user/group permissions' => (string) file_get_contents($root . '/phpBB2/admin/admin_ug_auth.php')
);
$errors = array();

foreach ($files as $name => $body)
{
	foreach (array('phpbb_admin_require_post_session();', 'phpbb_admin_session_field()') as $marker)
	{
		if (strpos($body, $marker) === false)
		{
			$errors[] = $name . ' is missing ' . $marker;
		}
	}
}

foreach (array(
	'$s_column_span = 0;',
	'phpbb_forum_acl_save($db, $_POST, $refresh_needed)',
	'if ($refresh_needed) { cache_tree(true); }',
	'phpbb_admin_html($forum_name)'
) as $marker)
{
	if (strpos($files['forum permissions'], $marker) === false)
	{
		$errors[] = 'Forum permissions are missing ' . $marker;
	}
}

foreach (array(
	'phpbb_group_admin_save($db, $_POST)',
	'group_single_user <>',
	'catch (PhpbbGroupException $error)',
	'cache_tree(true);',
	'phpbb_group_admin_actor(new PhpbbGroupDatabase($db))',
	'phpbb_admin_html($group_info[\'group_description\'])'
) as $marker)
{
	if (strpos($files['group administration'], $marker) === false)
	{
		$errors[] = 'Group administration is missing ' . $marker;
	}
}

foreach (array(
	'phpbb_acl_save($db, $original_mode, $original_target, $_POST)',
	'catch (PhpbbAclException $error)',
	'cache_tree(true);',
	'group_single_user <>',
	'phpbb_admin_html(get_object_lang',
	'$auth_access_count[$access_forum_id] = isset('
) as $marker)
{
	if (strpos($files['user/group permissions'], $marker) === false)
	{
		$errors[] = 'User/group permissions are missing ' . $marker;
	}
}

foreach ($files as $name => $body)
{
	foreach (array('while( list(', '@each(', '$params = array(') as $marker)
	{
		if (strpos($body, $marker) !== false)
		{
			$errors[] = $name . ' retains legacy path ' . $marker;
		}
	}
}

foreach (glob($root . '/phpBB2/templates/*', GLOB_ONLYDIR) as $style_dir)
{
	foreach (array('auth_forum_body.tpl', 'group_edit_body.tpl', 'auth_ug_body.tpl') as $template_name)
	{
		$template_path = $style_dir . '/admin/' . $template_name;
		if (is_file($template_path) && strpos((string) file_get_contents($template_path), '{S_HIDDEN_FIELDS}') === false)
		{
			$errors[] = basename($style_dir) . '/' . $template_name . ' does not render hidden session fields';
		}
	}
}

if ($errors)
{
	fwrite(STDERR, implode("\n", $errors) . "\n");
	exit(1);
}

echo "Permission administration safety checks passed.\n";
