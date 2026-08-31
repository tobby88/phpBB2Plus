<?php

$root = dirname(dirname(__DIR__));
$admin = $root . '/phpBB2/admin/admin_color_groups.php';
$functions = $root . '/phpBB2/includes/functions_color_groups.php';
$errors = array();

function require_marker($path, $marker, &$errors)
{
	$body = (string) file_get_contents($path);
	if (strpos($body, $marker) === false)
	{
		$errors[] = basename($path) . ' is missing: ' . $marker;
	}
}

require_marker($admin, 'phpbb_admin_require_post_session();', $errors);
require_marker($admin, "'S_FORM_TOKEN' => phpbb_admin_session_field()", $errors);
require_marker($admin, 'if ($color_groups_changed)', $errors);
require_marker($admin, "@unlink(\$phpbb_root_path . 'cache/cg_users.cache');", $errors);
require_marker($functions, "SELECT group_id FROM ' . COLOR_GROUPS_TABLE", $errors);
require_marker($functions, 'return false;', $errors);
require_marker($functions, '$cache_update = 300;', $errors);
require_marker($functions, 'AND ug.user_pending = 0', $errors);
require_marker($functions, 'SELECT MIN(c2.order_num)', $errors);
require_marker($functions, '($row && isset($row[\'username\']))', $errors);

$profile = $root . '/phpBB2/includes/usercp_register.php';
$admin_users = $root . '/phpBB2/admin/admin_users.php';
$shared_functions = $root . '/phpBB2/includes/functions.php';
require_marker($profile, "if (\$username_sql !== '')", $errors);
require_marker($profile, 'phpbb_sync_username_references(', $errors);
require_marker($admin_users, 'phpbb_sync_username_references(', $errors);
require_marker($shared_functions, "@unlink(\$phpbb_root_path . 'cache/' . \$cache_file);", $errors);
require_marker($shared_functions, "'cg_users.cache'", $errors);

$combined = (string) file_get_contents($admin) . (string) file_get_contents($functions);
if (strpos($combined, 'print_r($_POST)') !== false || strpos($combined, 'print_r($_GET)') !== false)
{
	$errors[] = 'ColorGroups still contains raw request debugging output.';
}

foreach (array('subSilver', 'fisubsilversh') as $style)
{
	foreach (array('color_groups_manager.tpl', 'color_groups_user_list.tpl') as $template)
	{
		require_marker($root . '/phpBB2/templates/' . $style . '/admin/' . $template, '{S_FORM_TOKEN}', $errors);
	}
}

if ($errors)
{
	fwrite(STDERR, implode("\n", $errors) . "\n");
	exit(1);
}

echo "ColorGroups administration safety checks passed.\n";
