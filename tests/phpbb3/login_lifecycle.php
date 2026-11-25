<?php
// Exercise native account deletion and reuse of its ID while Community is disabled.
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
$table = $table_prefix . 'plus_account_logins';
$count = static function ($where) use ($db,$table) {
	$result = $db->sql_query("SELECT COUNT(*) AS total FROM $table WHERE $where");
	$total = (int) $db->sql_fetchfield('total');
	$db->sql_freeresult($result);
	return $total;
};
if ($argv[2] === 'older')
{
	if ($phpbb_container->get('dbal.tools')->sql_table_exists($table)) { throw new RuntimeException('Missing earlier migration baseline'); }
}
elseif ($argv[2] === 'upgraded')
{
	if (!$phpbb_container->get('dbal.tools')->sql_table_exists($table) || !$config->offsetExists('plus_login_history') ||
		!$config->offsetExists('plus_login_network_check') || (int) $config['plus_login_history'] !== 0 ||
		(int) $config['plus_login_network_check'] !== 0 || (int) $config['plus_login_limit'] !== 10 || $count('1=1') !== 0)
	{
		throw new RuntimeException('An earlier conversion started collecting login data without its original board policy');
	}
}
elseif ($argv[2] === 'disable')
{
	$result = $db->sql_query("SELECT group_id FROM " . GROUPS_TABLE . " WHERE group_name='REGISTERED'");
	$group = (int) $db->sql_fetchfield('group_id');
	$db->sql_freeresult($result);
	$account = array('username'=>'OldLoginFixture', 'user_email'=>'old-logins@example.invalid', 'user_type'=>USER_INACTIVE, 'group_id'=>$group);
	$binding = str_repeat('a',32);
	$id = (int) user_add($account + array('plus_login_binding'=>$binding));
	if ($id <= ANONYMOUS) { throw new RuntimeException('Could not create the disabled-extension account fixture'); }
	$db->sql_query("INSERT INTO $table " . $db->sql_build_array('INSERT', array(
		'user_id'=>$id, 'owner_binding'=>$binding, 'login_time'=>100, 'login_ip'=>'203.0.113.246')));
	user_delete('retain', $id);
	$account['username'] = 'ReusedLoginFixture';
	$account['user_email'] = 'reused-logins@example.invalid';
	if ((int) user_add($account + array('user_id'=>$id)) !== $id || $count("user_id=$id") !== 1)
	{
		throw new RuntimeException('Did not exercise native ID reuse with the extension disabled');
	}
}
elseif ($argv[2] === 'enable')
{
	$result = $db->sql_query("SELECT user_id,plus_login_binding FROM " . USERS_TABLE . " WHERE username='ReusedLoginFixture'");
	$row = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);
	if (!$row || $row['plus_login_binding'] !== '' || $count('user_id=' . (int) $row['user_id']) || $count('user_id=89') !== 201)
	{
		throw new RuntimeException('Resuming Community did not remove the retired identity and preserve the other private histories');
	}
	user_delete('retain', (int) $row['user_id']);
}
else { exit(2); }
