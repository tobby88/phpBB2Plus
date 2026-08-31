<?php

define('ANONYMOUS', -1);
define('USERS_TABLE', 'phpbb_users');
define('GENERAL_MESSAGE', 0);

function message_die()
{
	$arguments = func_get_args();
	throw new Exception(isset($arguments[1]) ? (string) $arguments[1] : 'CrackerTracker rejection');
}

$rate_limit_calls = array();
$rate_limit_retry = 0;
function ctracker_rate_limit_increment($bucket, $identity, $window, $limit)
{
	global $rate_limit_calls, $rate_limit_retry;
	$rate_limit_calls[] = array($bucket, $identity, $window, $limit);
	return $rate_limit_retry;
}

class ctracker_test_db
{
	var $queries = array();

	function sql_query($sql)
	{
		$this->queries[] = $sql;
		return true;
	}
}

require dirname(dirname(__DIR__)) . '/phpBB2/ctracker/classes/class_ct_userfunctions.php';

$HTTP_POST_VARS = array('new_password' => 'A1!');
$ctracker_config = new stdClass();
$ctracker_config->settings = array(
	'pw_complex' => 1,
	'pw_complex_min' => 3,
	'pw_complex_mode' => 7,
	'pwreset_time' => 20,
	'pw_validity' => 30,
	'spammer_blockmode' => 1,
	'spammer_time' => 30,
	'spammer_postcount' => 4,
	'spam_attack_boost' => 0
);
$lang = array(
	'ctracker_info_password_minlng' => '%s %s',
	'ctracker_info_password_cmplx' => '%s',
	'ctracker_info_password_cmplx_1' => 'number',
	'ctracker_info_password_cmplx_2' => 'lowercase',
	'ctracker_info_password_cmplx_3' => 'uppercase',
	'ctracker_info_password_cmplx_4' => 'special',
	'ctracker_binf_spammer' => 'limit %s in %s; retry %s'
);
$db = new ctracker_test_db();
$userdata = array('user_id' => 2, 'user_level' => 0, 'user_posts' => 10, 'ct_last_ip' => '2001:db8::1', 'ct_last_used_ip' => '127.0.0.1');

$functions = new ct_userfunctions();
if ($functions->post_value('new_password', 2) !== 'A1')
{
	throw new Exception('Bounded scalar POST handling failed.');
}
$HTTP_POST_VARS['new_password'] = array('invalid');
if ($functions->post_value('new_password') !== '')
{
	throw new Exception('Non-scalar POST handling failed.');
}
$HTTP_POST_VARS['new_password'] = 'A1!';
$functions->password_functions();

if ($functions->check_ip_range() !== 'allclear')
{
	throw new Exception('Invalid or IPv6 history was not handled safely.');
}

$functions->handle_postings();
if ($rate_limit_calls !== array(array('posting-user', 'user:2', 30, 4)) || count($db->queries) !== 0)
{
	throw new Exception('Posting burst protection did not use the per-account rate limiter.');
}
$rate_limit_retry = 7;
try
{
	$functions->handle_postings();
	throw new Exception('Posting rate excess was not rejected.');
}
catch (Exception $exception)
{
	if ($exception->getMessage() !== 'limit 4 in 30; retry 7')
	{
		throw $exception;
	}
}
if (count($db->queries) !== 0)
{
	throw new Exception('Posting rate excess changed account or ban data.');
}

$functions->pw_create_date(2);
if (count($db->queries) !== 1 || strpos($db->queries[0], 'ct_last_pw_change = ') === false ||
	strpos($db->queries[0], 'ct_last_pw_reset') !== false)
{
	throw new Exception('Password-change and reset-cooldown timestamps were not kept separate.');
}

$userfunctions_source = file_get_contents(dirname(dirname(__DIR__)) . '/phpBB2/ctracker/classes/class_ct_userfunctions.php');
$schema_source = file_get_contents(dirname(dirname(__DIR__)) . '/phpBB2/install/schemas/mysql_schema.sql');
$updater_source = file_get_contents(dirname(dirname(__DIR__)) . '/update/update_from_153a.php');
foreach (array('block_handler', 'ban_userid', 'SET user_active = 0', 'ct_last_post', 'ct_post_counter') as $marker)
{
	if (strpos($userfunctions_source, $marker) !== false)
	{
		throw new Exception('Destructive or obsolete posting protection remains: ' . $marker);
	}
}
foreach (array('ct_last_post', 'ct_post_counter') as $marker)
{
	if (strpos($schema_source, $marker) !== false)
	{
		throw new Exception('Fresh schema retains obsolete posting state: ' . $marker);
	}
}
if (strpos($updater_source, "array('ct_last_post', 'ct_post_counter')") === false ||
	strpos($updater_source, "ct_config_name = 'logsize_spammer'") === false)
{
	throw new Exception('Updater does not remove obsolete posting-protection state.');
}

echo "CrackerTracker user-function checks passed.\n";
