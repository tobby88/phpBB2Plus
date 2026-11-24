<?php
// Exclusively owned loopback schemas for the integration test.
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1') { exit(2); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$connection = new mysqli('127.0.0.1', getenv('PLUS_TEST_DB_USER') ?: 'root',
	getenv('PLUS_TEST_DB_PASSWORD'), '', (int)(getenv('PLUS_TEST_DB_PORT') ?: 3306));
if ($argc === 2 && $argv[1] === 'create')
{
	$name = 'plus3_test_' . bin2hex(random_bytes(12));
	$connection->query("CREATE DATABASE $name CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
	echo $name;
}
elseif ($argc === 3 && $argv[1] === 'drop' && preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[2]))
{
	$connection->query('DROP DATABASE ' . $argv[2]);
}
else { exit(2); }
