<?php

$root = dirname(dirname(__DIR__));
$user_list = (string) file_get_contents($root . '/phpBB2/admin/admin_users_list.php');
$ban = (string) file_get_contents($root . '/phpBB2/admin/admin_user_ban.php');
$account = (string) file_get_contents($root . '/phpBB2/admin/admin_account.php');
$admin_users = (string) file_get_contents($root . '/phpBB2/admin/admin_users.php');
$profile_functions = (string) file_get_contents($root . '/phpBB2/includes/functions_profile_fields.php');
$profile = (string) file_get_contents($root . '/phpBB2/includes/usercp_register.php');
$profile_admin = (string) file_get_contents($root . '/phpBB2/admin/admin_profile_fields.php');
$profile_view = (string) file_get_contents($root . '/phpBB2/includes/usercp_viewprofile.php');
$memberlist = (string) file_get_contents($root . '/phpBB2/memberlist.php');
$templates = array(
	(string) file_get_contents($root . '/phpBB2/templates/subSilver/admin/admin_users_list_body.tpl'),
	(string) file_get_contents($root . '/phpBB2/templates/fisubsilversh/admin/admin_users_list_body.tpl')
);
$errors = array();

foreach (array(
	'phpbb_admin_require_post_session();',
	'phpbb_admin_session_field()',
	'AND user_level <> " . ADMIN',
	'$db->sql_escape($alpha)',
	"is_scalar(\$_POST['bulk_action'])"
) as $marker)
{
	if (strpos($user_list, $marker) === false)
	{
		$errors[] = 'Missing user-list safety marker: ' . $marker;
	}
}
if (strpos($user_list, '$_REQUEST') !== false)
{
	$errors[] = 'User-list administration still uses merged request data.';
}
foreach ($templates as $index => $template)
{
	if (strpos($template, '{S_HIDDEN_FIELDS}') === false)
	{
		$errors[] = 'User-list template ' . $index . ' lacks the session field.';
	}
}

foreach (array(
	'phpbb_admin_require_post_session();',
	'function admin_ban_post_string',
	'function admin_ban_add_ip',
	'($range_end - $range_start) > 4095',
	'$db->sql_escape($email_list[$i])',
	'$unban_ids = array_values(array_unique($unban_ids))',
	'phpbb_admin_html($ban_email)',
	'phpbb_admin_session_field()'
) as $marker)
{
	if (strpos($ban, $marker) === false)
	{
		$errors[] = 'Missing ban-management safety marker: ' . $marker;
	}
}
foreach (array('$where_user_sql', 'str_replace("\\\'", "\'\'", $email_list[$i])') as $marker)
{
	if (strpos($ban, $marker) !== false)
	{
		$errors[] = 'Legacy ban-management path remains: ' . $marker;
	}
}

foreach (array('phpbb_admin_require_post_session();', 'phpbb_admin_session_field()', "in_array(\$mode, array('joindate', 'username', 'email'), true)") as $marker)
{
	if (strpos($account, $marker) === false)
	{
		$errors[] = 'Missing inactive-account safety marker: ' . $marker;
	}
}

foreach (array(
	'function phpbb_profile_field_column',
	"preg_match('/^[a-z_][a-z0-9_]{0,63}$/D'",
	'function phpbb_profile_field_input',
	'array_slice($source[$column], 0, 100)',
	'in_array($item, $allowed, true)',
	'$db->sql_escape($profile_names[$column])',
	'function phpbb_profile_display_text',
	"html_entity_decode(\$value, ENT_QUOTES, 'UTF-8')"
) as $marker)
{
	if (strpos($profile_functions, $marker) === false)
	{
		$errors[] = 'Missing custom-profile input boundary: ' . $marker;
	}
}
foreach (array(
	'phpbb_profile_field_assignments($profile_data, $_POST, $profile_names)',
	"phpbb_profile_text(\$field['field_description'])",
	'implode(\', \', $profile_assignments)',
	'WHERE user_id = " . (int) $user_id'
) as $marker)
{
	if (strpos($admin_users, $marker) === false)
	{
		$errors[] = 'Missing administrator profile-field boundary: ' . $marker;
	}
}
foreach (array(
	'phpbb_profile_field_input($fields, $HTTP_POST_VARS)',
	'phpbb_profile_field_assignments($profile_data, $HTTP_POST_VARS, $profile_names)'
) as $marker)
{
	if (strpos($profile, $marker) === false)
	{
		$errors[] = 'Missing public profile-field boundary: ' . $marker;
	}
}
foreach (array('$temp = $_POST[$name]', '$sql2_tmp', 'str_replace("\\\'","\'\'",$profile_names') as $marker)
{
	if (strpos($admin_users . $profile, $marker) !== false)
	{
		$errors[] = 'Legacy custom-profile input path remains: ' . $marker;
	}
}
foreach (array($profile_admin, $profile_view, $memberlist) as $index => $output_path)
{
	if (strpos($output_path, 'phpbb_profile_display_text(') === false)
	{
		$errors[] = 'Profile output path ' . $index . ' lacks entity normalization.';
	}
}

if ($errors)
{
	fwrite(STDERR, implode("\n", $errors) . "\n");
	exit(1);
}

echo "User administration safety checks passed.\n";
