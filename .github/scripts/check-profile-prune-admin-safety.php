<?php

$root = dirname(dirname(__DIR__));
$profile = (string) file_get_contents($root . '/phpBB2/admin/admin_profile_fields.php');
$link_categories = (string) file_get_contents($root . '/phpBB2/admin/admin_links_cat.php');
$prune = (string) file_get_contents($root . '/phpBB2/admin/admin_prune_users.php');
$delete_users = (string) file_get_contents($root . '/phpBB2/delete_users.php');
$errors = array();

foreach (array(
	'$mode_value = (isset($_POST[\'mode\'])',
	'$pfid_value = (isset($_POST[\'pfid\'])',
	'phpbb_admin_require_post_session();',
	'phpbb_admin_session_field()',
	'profile_field_column_identifier($name_input)',
	"in_array(\$signature_wrap_value, array(AUTHOR, ABOVE_SIGNATURE, BELOW_SIGNATURE), true)",
	"'MESSAGE_TEXT' => \$lang['field_success']"
) as $marker)
{
	if (strpos($profile, $marker) === false)
	{
		$errors[] = 'Missing profile-field safety marker: ' . $marker;
	}
}
foreach (array('$HTTP_POST_VARS', '$HTTP_GET_VARS', '<input type="hidden" name="sid"', '$create_second_field_link') as $marker)
{
	if (strpos($profile, $marker) !== false)
	{
		$errors[] = 'Legacy profile-field path remains: ' . $marker;
	}
}

foreach (array("in_array(\$post_mode, array('', 'new', 'edit', 'delete'), true)", 'phpbb_admin_session_field()') as $marker)
{
	if (strpos($link_categories, $marker) === false)
	{
		$errors[] = 'Missing link-category safety marker: ' . $marker;
	}
}
if (strpos($link_categories, '<input type="hidden" name="sid"') !== false)
{
	$errors[] = 'Link-category administration still hand-builds session fields.';
}

foreach (array('is_scalar($_GET[$vars])', 'phpbb_admin_html($user_list[$i][\'username\'])', 'max(1, min(36500, intval($day_value)))') as $marker)
{
	if (strpos($prune, $marker) === false)
	{
		$errors[] = 'Missing prune-list safety marker: ' . $marker;
	}
}
foreach (array("is_scalar(\$_POST['mode'])", '$db->sql_escape($username)', 'intval($row[\'group_id\'])') as $marker)
{
	if (strpos($delete_users, $marker) === false)
	{
		$errors[] = 'Missing user-deletion safety marker: ' . $marker;
	}
}

if ($errors)
{
	fwrite(STDERR, implode("\n", $errors) . "\n");
	exit(1);
}

echo "Profile-field and user-pruning safety checks passed.\n";
