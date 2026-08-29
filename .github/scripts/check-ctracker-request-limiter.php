<?php

define('IN_PHPBB', true);
define('CTRACKER_REQUEST_LIMITER_NO_AUTO_RUN', true);
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
limiter_assert_profile(false, 'login.php', array(), array(), $errors);
limiter_assert_profile('register', 'profile.php', array('submit' => '1'), array('mode' => 'register'), $errors);
limiter_assert_profile('register', 'profile.php', array('submit' => '1', 'mode' => 'REGISTER'), array(), $errors);
limiter_assert_profile(false, 'profile.php', array('submit' => '1'), array('mode' => 'editprofile'), $errors);
limiter_assert_profile('upload', 'album_upload.php', array(), array(), $errors);
limiter_assert_profile('write', 'posting.php', array(), array(), $errors);
limiter_assert_profile('write', 'ibproarcade.php', array(), array(), $errors);
limiter_assert_profile(false, 'search.php', array('search_keywords' => 'example'), array(), $errors);

if ($errors)
{
	fwrite(STDERR, implode("\n", $errors) . "\n");
	exit(1);
}

echo "CrackerTracker request limiter classification passed.\n";

?>
