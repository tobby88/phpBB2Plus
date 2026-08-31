<?php

$root = dirname(dirname(__DIR__));
$login = (string) file_get_contents($root . '/phpBB2/login.php');
$errors = array();

$required = array(
	'hash_equals((string) $userdata[\'session_id\']',
	'ctracker_enforce_login_identity_limit($submitted_username)',
	'user_badlogin = user_badlogin + 1'
);
foreach ($required as $marker)
{
	if (strpos($login, $marker) === false)
	{
		$errors[] = 'Missing login protection marker: ' . $marker;
	}
}

$database_class = (string) file_get_contents($root . '/phpBB2/ctracker/classes/class_ct_database.php');
$schema = (string) file_get_contents($root . '/phpBB2/install/schemas/mysql_schema.sql');
$updater = (string) file_get_contents($root . '/update/update_from_153a.php');
if (is_file($root . '/phpBB2/ctracker_login.php') ||
	is_file($root . '/phpBB2/templates/fisubsilversh/ctracker/ctracker_login.tpl'))
{
	$errors[] = 'Obsolete account-wide CAPTCHA endpoint or template still exists.';
}
foreach (array('reset_login_system', 'handle_wrong_login', 'check_login_status', 'ct_login_count', 'ct_login_vconfirm') as $marker)
{
	if (strpos($database_class, $marker) !== false || strpos($schema, $marker) !== false)
	{
		$errors[] = 'Obsolete account-wide CAPTCHA state remains: ' . $marker;
	}
}
foreach (array('ct_login_count', 'ct_login_vconfirm') as $column)
{
	if (strpos($updater, "array('ct_login_count', 'ct_login_vconfirm')") === false)
	{
		$errors[] = 'Updater does not remove obsolete login column: ' . $column;
	}
}

$forbidden = array(
	'$blocktime = ", user_block_by',
	"use_template('bad_login'",
	'$ctracker_config->handle_wrong_login',
	'$ctracker_config->check_login_status'
);
foreach ($forbidden as $marker)
{
	if (strpos($login, $marker) !== false)
	{
		$errors[] = 'Legacy attacker-triggered account lock remains: ' . $marker;
	}
}

if ($errors)
{
	fwrite(STDERR, implode("\n", $errors) . "\n");
	exit(1);
}

echo "Login abuse protections passed.\n";

?>
