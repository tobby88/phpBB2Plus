<?php

require dirname(__DIR__) . '/includes/php_compat.php';

$values = array('first' => false, 4 => 'second');
$first = each($values);
$second = each($values);
$end = each($values);

$passed =
	$first['key'] === 'first' &&
	$first['value'] === false &&
	$first[0] === 'first' &&
	$first[1] === false &&
	$second['key'] === 4 &&
	$second['value'] === 'second' &&
	$end === false;

if (!$passed)
{
	fwrite(STDERR, "Legacy each() compatibility check failed.\n");
	exit(1);
}

$matches = array();
$passed =
	eregi('^insert ', 'INSERT INTO phpbb_test', $matches) !== false &&
	$matches[0] === 'INSERT ' &&
	eregi_replace('(-)+', '-', 'one---two') === 'one-two' &&
	split('[[:space:]]+', 'one two') === array('one', 'two');

if (!$passed)
{
	fwrite(STDERR, "Legacy POSIX regex compatibility check failed.\n");
	exit(1);
}

echo "Legacy PHP compatibility helpers passed.\n";

