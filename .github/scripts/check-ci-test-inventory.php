<?php

$root = dirname(dirname(__DIR__));
$workflow = file_get_contents($root . '/.github/workflows/php-lint.yml');
$missing = array();

foreach (glob(__DIR__ . '/check-*.php') as $test_file)
{
	$name = basename($test_file);
	if (strpos($workflow, '.github/scripts/' . $name) === false)
	{
		$missing[] = $name;
	}
}

if ($missing)
{
	fwrite(STDERR, "CI test inventory failed; the workflow does not execute:\n" . implode("\n", $missing) . "\n");
	exit(1);
}

echo "CI test inventory checks passed.\n";
