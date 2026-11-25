<?php
// Create a registered account after publication to check the original recipient boundary.
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$user->session_begin();
$user->setup();
require_once './includes/functions_user.php';
$name = 'NoticeNewMember';
if ($argv[2] === 'create')
{
	// Use the synthetic member whose password has already passed the normal browser login.
	$result = $db->sql_query("SELECT u.user_password, g.group_id FROM " . USERS_TABLE . ' u, ' . GROUPS_TABLE . " g
		WHERE u.user_id = 2 AND u.username = 'ModernMember' AND g.group_name = 'REGISTERED'");
	$member = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);
	if (!$member || $member['user_password'] === '') { throw new RuntimeException('Missing authenticated notice fixture member'); }
	$id = user_add(array(
		'username' => $name,
		'user_email' => 'new-notice@example.invalid',
		'user_type' => USER_NORMAL,
		'group_id' => (int) $member['group_id'],
		'user_lang' => 'en',
		'user_password' => $member['user_password'],
	));
	if ((int) $id <= ANONYMOUS) { throw new RuntimeException('Creating the notice recipient fixture failed'); }
}
elseif ($argv[2] === 'remove')
{
	$result = $db->sql_query('SELECT user_id FROM ' . USERS_TABLE . " WHERE username='$name' AND user_email='new-notice@example.invalid' AND plus_legacy_id=0");
	$id = (int) $db->sql_fetchfield('user_id');
	$db->sql_freeresult($result);
	if ($id <= ANONYMOUS) { throw new RuntimeException('Missing newly registered fixture account'); }
	user_delete('retain', $id);
}
else { exit(2); }
