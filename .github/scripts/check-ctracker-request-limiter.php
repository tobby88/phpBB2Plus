<?php

define('IN_PHPBB', true);
define('CTRACKER_REQUEST_LIMITER_NO_AUTO_RUN', true);
define('CTRACKER_RATE_LIMITS', 'phpbb_ctracker_rate_limits');
require dirname(dirname(__DIR__)) . '/phpBB2/ctracker/engines/ct_request_limiter.php';

function limiter_assert_profile($expected, $script, $post, $get, &$errors)
{
	$profile = ctracker_request_limit_profile($script, $post, $get);
	$actual = $profile === false ? false : $profile[0];
	if ($actual !== $expected)
	{
		$errors[] = $script . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true);
	}
}

$errors = array();
limiter_assert_profile('login', 'login.php', array('login' => 'Login'), array(), $errors);
limiter_assert_profile('login', 'login.php', array(), array(), $errors);
limiter_assert_profile('register', 'profile.php', array('submit' => '1'), array('mode' => 'register'), $errors);
limiter_assert_profile('register', 'profile.php', array('submit' => '1', 'mode' => 'REGISTER'), array(), $errors);
limiter_assert_profile('account', 'profile.php', array(), array('mode' => 'sendpassword'), $errors);
limiter_assert_profile('account', 'tellafriend.php', array(), array(), $errors);
limiter_assert_profile('write', 'profile.php', array('submit' => '1'), array('mode' => 'editprofile'), $errors);
limiter_assert_profile('upload', 'album_upload.php', array(), array(), $errors);
limiter_assert_profile('upload', 'album_nuffload.php', array(), array(), $errors);
limiter_assert_profile('write', 'posting.php', array(), array(), $errors);
limiter_assert_profile('write', 'ibproarcade.php', array(), array(), $errors);
limiter_assert_profile('write', 'future_plugin.php', array('submit' => '1'), array(), $errors);
limiter_assert_profile('write', 'search.php', array('search_keywords' => 'example'), array(), $errors);

class limiter_test_db
{
	var $count = 0;

	function sql_escape($value) { return addslashes((string) $value); }
	function sql_query($sql)
	{
		if (strpos($sql, 'INSERT INTO') === 0) { $this->count++; }
		return true;
	}
	function sql_fetchrow($result) { return array('request_count' => $this->count); }
	function sql_freeresult($result) { return true; }
}

$db = new limiter_test_db();
if (ctracker_rate_limit_increment('test', 'identity', 60, 1) !== 0 ||
	ctracker_rate_limit_increment('test', 'identity', 60, 1) <= 0)
{
	$errors[] = 'Atomic rate-limit counter did not allow then throttle as expected.';
}

$ctracker_config = new stdClass();
$ctracker_config->settings = array('loginfeature' => '1', 'logincount' => '20');
$ctracker_config->user_ip_value = '192.0.2.10';
$before_identity_count = $db->count;
ctracker_enforce_login_identity_limit('Example User');
if ($db->count !== $before_identity_count + 1)
{
	$errors[] = 'Per-IP/account login limiter did not use the atomic store.';
}

if ($errors)
{
	fwrite(STDERR, implode("\n", $errors) . "\n");
	exit(1);
}

echo "CrackerTracker request limiter classification passed.\n";

?>
