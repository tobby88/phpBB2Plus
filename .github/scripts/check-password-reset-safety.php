<?php

function password_reset_assert($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, "Password reset safety test failed: $message\n");
		exit(1);
	}
}

$root = dirname(dirname(__DIR__));
$send = file_get_contents($root . '/phpBB2/includes/usercp_sendpasswd.php');
$activate = file_get_contents($root . '/phpBB2/includes/usercp_activate.php');
$constants = file_get_contents($root . '/phpBB2/includes/constants.php');
$validate = file_get_contents($root . '/phpBB2/includes/functions_validate.php');
$english_mail = file_get_contents($root . '/phpBB2/language/lang_english/email/user_activate_passwd.tpl');
$german_mail = file_get_contents($root . '/phpBB2/language/lang_german/email/user_activate_passwd.tpl');

password_reset_assert(strpos($constants, "define('PHPBB_PASSWORD_RESET_PENDING'") !== false, 'pending resets need an unambiguous compatibility marker');
password_reset_assert(strpos($send, "user_newpasswd = '\$reset_marker_sql'") !== false, 'reset requests must store only the pending marker');
password_reset_assert(strpos($send, 'phpbb_password_hash($user_password)') === false, 'the request handler must not create a password for the user');
password_reset_assert(strpos($send, "'PASSWORD' =>") === false, 'the mailer must never receive a plaintext password');
password_reset_assert(strpos($english_mail, '{PASSWORD}') === false && strpos($german_mail, '{PASSWORD}') === false, 'reset e-mails must never contain a password placeholder');
password_reset_assert(strpos($activate, "ct_last_pw_reset >= \$now") !== false, 'the atomic reset must enforce token expiry');
password_reset_assert(strpos($activate, "user_actkey = '\$activation_key_sql'") !== false, 'the atomic reset must consume only the presented token');
password_reset_assert(strpos($activate, '$db->sql_affectedrows() < 1') !== false, 'concurrent or reused reset links must fail closed');
password_reset_assert(strpos($activate, 'session_reset_keys((int) $row[\'user_id\']') !== false, 'a completed reset must revoke existing sessions and auto-login keys');
password_reset_assert(strpos($validate, 'strpos($password, "\\0")') !== false, 'password validation must reject NUL before password_hash');

echo "Password reset safety checks passed.\n";
