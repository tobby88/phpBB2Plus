<?php

define('ANONYMOUS', -1);
define('USERS_TABLE', 'phpbb_users');

function message_die()
{
	throw new Exception('Unexpected CrackerTracker rejection during CI self-test.');
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
	'spammer_blockmode' => 0
);
$lang = array(
	'ctracker_info_password_minlng' => '%s %s',
	'ctracker_info_password_cmplx' => '%s',
	'ctracker_info_password_cmplx_1' => 'number',
	'ctracker_info_password_cmplx_2' => 'lowercase',
	'ctracker_info_password_cmplx_3' => 'uppercase',
	'ctracker_info_password_cmplx_4' => 'special'
);
$db = new ctracker_test_db();
$userdata = array('user_id' => 2, 'ct_last_ip' => '2001:db8::1', 'ct_last_used_ip' => '127.0.0.1');

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

$functions->block_handler();
if (count($db->queries) !== 0)
{
	throw new Exception('Disabled spam blocking unexpectedly changed account data.');
}

$functions->pw_create_date(2);
if (count($db->queries) !== 1 || strpos($db->queries[0], 'ct_last_pw_reset = ') === false)
{
	throw new Exception('Password expiry timestamp update failed.');
}

echo "CrackerTracker user-function checks passed.\n";
