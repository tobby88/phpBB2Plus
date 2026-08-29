<?php

$root = dirname(dirname(__DIR__));
$login = (string) file_get_contents($root . '/phpBB2/login.php');
$errors = array();

$required = array(
	'hash_equals((string) $userdata[\'session_id\']',
	'$ctracker_config->handle_wrong_login',
	'user_badlogin = user_badlogin + 1'
);
foreach ($required as $marker)
{
	if (strpos($login, $marker) === false)
	{
		$errors[] = 'Missing login protection marker: ' . $marker;
	}
}

$forbidden = array(
	'$blocktime = ", user_block_by',
	"use_template('bad_login'"
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
