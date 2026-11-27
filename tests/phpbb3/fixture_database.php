<?php

/** Open only a disposable schema from the integration runner. */
function plus_fixture_database($name)
{
	if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || !preg_match('/^plus3_test_[a-f0-9]{24}$/D', $name))
	{
		throw new RuntimeException('Expected a disposable integration database');
	}
	mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
	return new mysqli('127.0.0.1', getenv('PLUS_TEST_DB_USER') ?: 'root', getenv('PLUS_TEST_DB_PASSWORD'),
		$name, (int) (getenv('PLUS_TEST_DB_PORT') ?: 3306));
}
