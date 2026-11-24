<?php
// Ask the same native manager that supplies missing profile fields on new accounts.
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 2 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D',$argv[1])) { exit(2); }
define('IN_PHPBB',true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$user->session_begin();
$user->setup();
$defaults = $phpbb_container->get('profilefields.manager')->build_insert_sql_array(array());
if ($defaults['pf_plus_f54'] !== '2' || (int) $defaults['pf_plus_f53'] !== 2 || $defaults['pf_plus_f52'] !== str_repeat('Frühling ',45))
{
	throw new RuntimeException('A new account would receive different custom profile defaults.');
}
