<?php

$root = dirname(dirname(__DIR__));
$login = (string) file_get_contents($root . '/phpBB2/login.php');
$page_header = (string) file_get_contents($root . '/phpBB2/includes/page_header.php');
$errors = array();

$required = array(
	'hash_equals((string) $userdata[\'session_id\']',
	'ctracker_enforce_login_identity_limit($submitted_username)',
	'user_badlogin = user_badlogin + 1',
	"phpbb_password_verify(\$password, '')"
);
foreach ($required as $marker)
{
	if (strpos($login, $marker) === false)
	{
		$errors[] = 'Missing login protection marker: ' . $marker;
	}
}

if (strpos($page_header, "'S_LOGIN_FIELDS' => '<input type=\"hidden\" name=\"sid\"") === false)
{
	$errors[] = 'Inline login forms do not receive the current session token.';
}
$template_files = glob($root . '/phpBB2/templates/fisubsilversh/*.tpl');
foreach ($template_files as $template_file)
{
	$template = (string) file_get_contents($template_file);
	$inline_forms = substr_count($template, '<form method="post" action="{S_LOGIN_ACTION}">');
	if ($inline_forms > 0 && substr_count($template, '{S_LOGIN_FIELDS}') < $inline_forms)
	{
		$errors[] = 'Inline login form lacks a session token: ' . basename($template_file);
	}
}

$compat = (string) file_get_contents($root . '/phpBB2/includes/php_compat.php');
if (substr_count($compat, 'password_verify((string) $password, $dummy_hash);') < 2 ||
	strpos($compat, 'Legacy MD5 comparison is otherwise observably faster') === false)
{
	$errors[] = 'Unknown, malformed and legacy password records do not share an adaptive timing path.';
}

$database_class = (string) file_get_contents($root . '/phpBB2/ctracker/classes/class_ct_database.php');
$schema = (string) file_get_contents($root . '/phpBB2/install/schemas/mysql_schema.sql');
$updater = (string) file_get_contents($root . '/update/update_from_153a.php');
if (!preg_match('/CREATE TABLE\s+`phpbb_ctracker_loginhistory`.*?`ct_login_id`\s+bigint\(20\)\s+unsigned\s+NOT NULL\s+AUTO_INCREMENT.*?KEY\s+`ct_user_time`/is', $schema) ||
	strpos($database_class, 'ORDER BY ct_login_time DESC, ct_login_id DESC') === false ||
	strpos($database_class, 'ct_login_id < ') === false ||
	strpos($updater, "ADD `ct_login_id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST") === false ||
	strpos($updater, 'ADD INDEX `ct_user_time` (`ct_user_id`, `ct_login_time`, `ct_login_id`)') === false)
{
	$errors[] = 'Login-history retention lacks a stable event ID or migration.';
}
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
