<?php
// Native phpBB group membership changes in the disposable conversion fixture.
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 2 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D',$argv[1])) { exit(2); }
define('IN_PHPBB',true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
require_once './includes/functions_user.php';
$user->session_begin();
$user->setup();
$auth->acl($user->data);
$read = static function () use ($db) {
	$result = $db->sql_query('SELECT username,u.group_id,u.user_rank,u.user_colour,u.plus_colour_applied,g.group_colour FROM ' .
		USERS_TABLE . ' u JOIN ' . GROUPS_TABLE . ' g ON g.group_id=u.group_id WHERE u.user_id=87');
	$row = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);
	return $row;
};
$before = $read();
if (!$before || $before['username'] !== 'DepartingFixture') { throw new RuntimeException('Missing fixture member'); }
if (group_user_add(73,array(87),false,'Archive Club',false,0,1) || $read() !== $before)
{
	throw new RuntimeException('A pending membership changed the account colour or default group');
}
group_user_attributes('approve',73,array(87),false,'Archive Club');
$during = $read();
if ($during['user_colour'] !== '5A769B' || (int) $during['plus_colour_applied'] !== 1 ||
	$during['group_id'] !== $before['group_id'] || $during['user_rank'] !== $before['user_rank'])
{
	throw new RuntimeException('Approving the cosmetic membership lost its colour, existing rank or actual default group');
}
group_user_del(73,array(87),false,'Archive Club');
if ($read() !== $before) { throw new RuntimeException('Removing the membership failed to restore the native colour'); }
