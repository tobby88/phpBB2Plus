<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }

$db->sql_transaction('begin');
if ($argv[2] === 'prepare')
{
	$db->sql_multi_insert(RANKS_TABLE, array(
		array('rank_id' => 98101, 'rank_min' => 7100, 'rank_special' => 0, 'rank_title' => 'Standard &amp; Fixture', 'rank_image' => ''),
		array('rank_id' => 98102, 'rank_min' => 7105, 'rank_special' => 0, 'rank_title' => 'Next threshold', 'rank_image' => ''),
	));
	$result = $db->sql_query('SELECT * FROM ' . USERS_TABLE . " WHERE username_clean='historicmember'");
	$sample = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);
	if (!$sample) { throw new RuntimeException('Missing rank fixture account'); }
	$users = array();
	for ($i = 0; $i <= 29; $i++)
	{
		$name = sprintf('RankFixture%02d', $i);
		$users[] = array_replace($sample, array('user_id' => 985000 + $i, 'username' => $name, 'username_clean' => strtolower($name),
			'user_type' => $i === 29 ? USER_INACTIVE : USER_NORMAL, 'user_password' => '', 'user_email' => $name . '@example.invalid',
			'user_rank' => $i === 28 ? 77 : 0, 'user_posts' => $i === 27 ? 7105 : 7100 + ($i % 5)));
	}
	$db->sql_multi_insert(USERS_TABLE, $users);
}
elseif ($argv[2] === 'cleanup')
{
	$db->sql_query('DELETE FROM ' . USERS_TABLE . ' WHERE user_id BETWEEN 985000 AND 985029');
	$db->sql_query('DELETE FROM ' . RANKS_TABLE . ' WHERE rank_id IN (98101, 98102)');
}
else { exit(2); }
$db->sql_transaction('commit');
$cache->destroy('_ranks');
