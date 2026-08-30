<?php

$root = dirname(dirname(__DIR__));
$user_list = (string) file_get_contents($root . '/phpBB2/admin/admin_users_list.php');
$ban = (string) file_get_contents($root . '/phpBB2/admin/admin_user_ban.php');
$account = (string) file_get_contents($root . '/phpBB2/admin/admin_account.php');
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

if ($errors)
{
	fwrite(STDERR, implode("\n", $errors) . "\n");
	exit(1);
}

echo "User administration safety checks passed.\n";
