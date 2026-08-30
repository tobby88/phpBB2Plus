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
	'isset($simple_auth_ary[intval($_POST[\'simpleauth\'])])',
	'in_array($post_auth_value, $forum_auth_const, true)',
	'phpbb_admin_html($forum_name)'
) as $marker)
{
	if (strpos($files['forum permissions'], $marker) === false)
	{
		$errors[] = 'Forum permissions are missing ' . $marker;
	}
}

foreach (array(
	'$validated_group_info',
	'group_single_user <>',
	'$db->sql_escape($group_name)',
	'$db->sql_escape($group_description)',
	'admin_group_text_length',
	'phpbb_admin_html($group_info[\'group_description\'])'
) as $marker)
{
	if (strpos($files['group administration'], $marker) === false)
	{
		$errors[] = 'Group administration is missing ' . $marker;
	}
}

foreach (array(
	'admin_ug_boolean_map',
	'foreach ($forum_auth_action as $forum_id => $action)',
	'if ($group_user)',
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
