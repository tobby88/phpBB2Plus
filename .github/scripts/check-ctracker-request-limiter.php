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
limiter_assert_profile('account', 'dload.php', array('action' => 'email', 'submit' => '1'), array(), $errors);
limiter_assert_profile('write', 'profile.php', array('submit' => '1'), array('mode' => 'editprofile'), $errors);
limiter_assert_profile('upload', 'album_upload.php', array(), array(), $errors);
limiter_assert_profile('upload', 'album_nuffload.php', array(), array(), $errors);
limiter_assert_profile('content', 'posting.php', array(), array(), $errors);
limiter_assert_profile('content', 'privmsg.php', array(), array(), $errors);
limiter_assert_profile('content', 'ibproarcade.php', array(), array(), $errors);
limiter_assert_profile('content', 'ajax.php', array('mode' => 'edit_post_text'), array(), $errors);
limiter_assert_profile('write', 'ajax.php', array('mode' => 'post_preview'), array(), $errors);
limiter_assert_profile('upload', 'dload.php', array('action' => 'user_upload'), array(), $errors);
limiter_assert_profile('content', 'dload.php', array('action' => 'post_comment'), array(), $errors);
limiter_assert_profile('write', 'future_plugin.php', array('submit' => '1'), array(), $errors);
limiter_assert_profile('write', 'search.php', array('search_keywords' => 'example'), array(), $errors);
$content_profile = ctracker_request_limit_profile('posting.php', array('message' => 'example'), array());
if ($content_profile !== array('content', 300, 'request_limit_content', 60))
{
	$errors[] = 'Content actions must use the dedicated five-minute configurable profile.';
}

class limiter_test_db
{
	var $count = 0;
	var $last_updated_at = 0;

	function sql_escape($value) { return addslashes((string) $value); }
	function sql_query($sql)
	{
		if (strpos($sql, 'INSERT INTO') === 0)
		{
			$this->count++;
			$this->last_updated_at = time();
		}
		return true;
	}
	function sql_fetchrow($result) { return array('request_count' => $this->count, 'updated_at' => $this->last_updated_at); }
	function sql_freeresult($result) { return true; }
}

$db = new limiter_test_db();
if (ctracker_rate_limit_increment('test', 'identity', 60, 1) !== 0 ||
	ctracker_rate_limit_increment('test', 'identity', 60, 1) <= 0)
{
	$errors[] = 'Atomic rate-limit counter did not allow then throttle as expected.';
}

$ctracker_config = new stdClass();
$ctracker_config->settings = array(
	'loginfeature' => '1', 'logincount' => '20',
	'request_limit_enabled' => '1', 'request_limit_upload' => '30'
);
$ctracker_config->user_ip_value = '192.0.2.10';
$before_identity_count = $db->count;
ctracker_enforce_login_identity_limit('Example User');
if ($db->count !== $before_identity_count + 1)
{
	$errors[] = 'Per-IP/account login limiter did not use the atomic store.';
}
$before_upload_count = $db->count;
ctracker_enforce_request_limit_profile(array('upload', 3600, 'request_limit_upload', 30));
if ($db->count !== $before_upload_count + 1)
{
	$errors[] = 'Explicit upload hand-off limiter did not use the atomic store.';
}

$db->last_updated_at = 0;
if (ctracker_rate_limit_cooldown_remaining('registration-success', '192.0.2.10', 30) !== 0 ||
	!ctracker_rate_limit_mark_success('registration-success', '192.0.2.10') ||
	ctracker_rate_limit_cooldown_remaining('registration-success', '192.0.2.10', 30) <= 0)
{
	$errors[] = 'Successful-registration cooldown did not remain per identity.';
}

$userfunctions_source = file_get_contents(dirname(dirname(__DIR__)) . '/phpBB2/ctracker/classes/class_ct_userfunctions.php');
$settings_source = file_get_contents(dirname(dirname(__DIR__)) . '/phpBB2/ctracker/admin/acp_module_settings.php');
$settings_template = file_get_contents(dirname(dirname(__DIR__)) . '/phpBB2/templates/fisubsilversh/ctracker/acp/acp_settings.tpl');
$basic_source = file_get_contents(dirname(dirname(__DIR__)) . '/phpBB2/install/schemas/mysql_basic.sql');
$updater_source = file_get_contents(dirname(dirname(__DIR__)) . '/update/update_from_153a.php');
$nuffload_source = file_get_contents(dirname(dirname(__DIR__)) . '/phpBB2/album_nuffload.php');
if (strpos($basic_source, "('request_limit_content', '60')") === false ||
	strpos($updater_source, 'foreach ($seed_statements as $seed_sql)') === false)
{
	$errors[] = 'The dedicated content limit is missing from the fresh-install or 1.53a migration path.';
}
if (strpos($nuffload_source, "ctracker_enforce_request_limit_profile(array('upload', 3600, 'request_limit_upload', 30))") === false)
{
	$errors[] = 'The session-bound Nuffload GET hand-off is not covered by the upload limiter.';
}
if (strpos($userfunctions_source, "change_configuration('reg_last_reg'") !== false ||
	strpos($userfunctions_source, "change_configuration('reg_lastip'") !== false ||
	strpos($settings_source, "'reg_ip_scan' =>") !== false ||
	strpos($settings_template, 'name="reg_ip_scan"') !== false ||
	preg_match("/\\('(?:reg_last_reg|reg_lastip|reg_ip_scan)',/", $basic_source) ||
	strpos($updater_source, "'reg_last_reg', 'reg_lastip', 'reg_ip_scan'") === false)
{
	$errors[] = 'The global legacy registration lock was not fully retired.';
}

if ($errors)
{
	fwrite(STDERR, implode("\n", $errors) . "\n");
	exit(1);
}

echo "CrackerTracker request limiter classification passed.\n";

?>
