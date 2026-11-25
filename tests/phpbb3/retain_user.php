<?php
// Exercise phpBB's native account removal only for the designated synthetic fixture.
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 2 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$user->session_begin();
$user->setup();
require_once './includes/functions_user.php';
$result = $db->sql_query('SELECT username FROM ' . USERS_TABLE . ' WHERE user_id=87');
$name = $db->sql_fetchfield('username');
$db->sql_freeresult($result);
if ($name !== 'DepartingFixture') { throw new RuntimeException('Unexpected fixture identity'); }
user_delete('retain', 87, true);
$private = $db->sql_query('SELECT COUNT(*) AS total FROM ' . $table_prefix . 'plus_account_logins WHERE user_id=87');
$remaining = (int) $db->sql_fetchfield('total');
$db->sql_freeresult($private);
if ($remaining) { throw new RuntimeException('Native removal left private login history behind'); }
$result = $db->sql_query('SELECT poster_id,post_username FROM ' . POSTS_TABLE . ' WHERE post_id=42');
$post = $db->sql_fetchrow($result);
$db->sql_freeresult($result);
if (!$post || (int) $post['poster_id'] !== ANONYMOUS || $post['post_username'] === $name)
{
	throw new RuntimeException('Native removal must retain the post with a public-safe stored byline');
}
