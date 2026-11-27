<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 || !preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
if ($argv[2] === 'source')
{
	$db = new mysqli('127.0.0.1', getenv('PLUS_TEST_DB_USER') ?: 'root', getenv('PLUS_TEST_DB_PASSWORD'), $argv[1], (int) (getenv('PLUS_TEST_DB_PORT') ?: 3306));
	$db->query("UPDATE phpbb_config SET config_value='0' WHERE config_name='hidde_last_logon'");
	return;
}
define('IN_PHPBB', true);
$phpbb_root_path = './'; $phpEx = 'php'; require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$key = 'test_plus_last_sign_in_display';
$store = $phpbb_container->get('config_text');
$columns = 'plus_last_login,plus_login_hidden,user_allow_viewonline';
$table = USERS_TABLE;
if ($argv[2] === 'unconverted')
{
	if ((string) $config['plus_hide_last_login'] !== '1') { throw new RuntimeException('Missing source unexpectedly disclosed sign-in times'); }
}
elseif ($argv[2] === 'setup')
{
	if ((string) $config['plus_hide_last_login'] !== '0') { throw new RuntimeException('Original public sign-in choice missing'); }
	$result = $db->sql_query("SELECT user_id,username,$columns FROM $table WHERE username IN ('ModernMember','HistoricMember')");
	$rows = $db->sql_fetchrowset($result); $db->sql_freeresult($result);
	if (count($rows) !== 2 || $store->get($key) !== null) { throw new RuntimeException('Unexpected member fixture'); }
	$store->set($key, json_encode($rows));
	$db->sql_query("UPDATE $table SET plus_last_login=1000000000,plus_login_hidden=0,user_allow_viewonline=1 WHERE username='ModernMember'");
	$db->sql_query("UPDATE $table SET plus_last_login=1100000000,plus_login_hidden=1,user_allow_viewonline=1 WHERE username='HistoricMember'");
	echo json_encode(array_column($rows, 'user_id', 'username'));
}
elseif ($argv[2] === 'account-hidden')
{
	$db->sql_query("UPDATE $table SET plus_login_hidden=0,user_allow_viewonline=0 WHERE username='HistoricMember'");
}
elseif ($argv[2] === 'never')
{
	$db->sql_query("UPDATE $table SET plus_last_login=0,plus_login_hidden=0,user_allow_viewonline=1 WHERE username='HistoricMember'");
}
elseif ($argv[2] === 'restore')
{
	$config->set('plus_hide_last_login', 0);
	foreach (json_decode($store->get($key), true) as $r)
	{
		$id = (int) $r['user_id']; unset($r['user_id'],$r['username']);
		$db->sql_query("UPDATE $table SET " . $db->sql_build_array('UPDATE', $r) . " WHERE user_id=$id");
	}
	$store->delete($key);
}
else { exit(2); }
