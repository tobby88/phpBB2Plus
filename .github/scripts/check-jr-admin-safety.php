<?php

$root = dirname(dirname(__DIR__));
$admin = (string) file_get_contents($root . '/phpBB2/admin/admin_jr_admin.php');
$errors = array();

foreach (array(
	'phpbb_admin_require_post_session();',
	'phpbb_admin_session_field()',
	'$allowed_module_hashes',
	"preg_match('/^[0-9]+_' . UPDATE_MODULE_PREFIX . '([a-f0-9]{32})$/D'",
	'$db->sql_escape($user_update_list)',
	'$db->sql_escape($admin_notes)',
	'$db->sql_escape($user_search)',
	'$allowed_sort_items',
	'jr_admin_safe_color',
	"'user_jr_admin' => ''",
	'$letter_list = array();'
) as $marker)
{
	if (strpos($admin, $marker) === false)
	{
		$errors[] = 'Missing Junior Admin safety marker: ' . $marker;
	}
}

foreach (array(
	'$params = array(',
	'$update_find_pattern',
	'print_r($_POST)',
	'print_r($_GET)',
	'" AND username LIKE (\'".$_POST[\'user_search\']',
	'admin_notes = \'$admin_notes\'',
	'<input type="hidden" name="sid"'
) as $marker)
{
	if (strpos($admin, $marker) !== false)
	{
		$errors[] = 'Legacy Junior Admin path remains: ' . $marker;
	}
}

foreach (array('fisubsilversh') as $style)
{
	$template = (string) file_get_contents($root . '/phpBB2/templates/' . $style . '/admin/jr_admin_user_permissions.tpl');
	if (strpos($template, '{S_HIDDEN_FIELDS}') === false)
	{
		$errors[] = $style . ' Junior Admin permissions form does not render the POST token';
	}
}

if ($errors)
{
	fwrite(STDERR, implode("\n", $errors) . "\n");
	exit(1);
}

echo "Junior Admin safety checks passed.\n";
