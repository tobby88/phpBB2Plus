<?php
// Switch only the isolated integration database to phpBB's alternative MySQL search backend.
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 2 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D',$argv[1])) { exit(2); }
define('IN_PHPBB',true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$user->session_begin();
$user->setup('acp/search');
$error = false;
$backend = new \phpbb\search\fulltext_mysql($error,$phpbb_root_path,$phpEx,$auth,$config,$db,$user,$phpbb_dispatcher);
if ($error || $backend->create_index(null,'')) { throw new RuntimeException('Could not create native MySQL full-text indexes'); }
$config->set('search_type','\phpbb\search\fulltext_mysql');
