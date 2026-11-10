<?php

$root = dirname(dirname(__DIR__));
$workflow = file_get_contents($root . '/.github/workflows/php-lint.yml');
$workflow .= "\n" . file_get_contents($root . '/.github/workflows/innodb-migration.yml');
$missing = array();
$native = file_get_contents($root . '/.github/workflows/innodb-migration.yml');
if (!preg_match('/part:\s*\[([0-9,\s]+)\]/', $native, $matrix)) { throw new RuntimeException('Native matrix parts unavailable'); }
$declared = array_map('intval', explode(',', $matrix[1])); sort($declared);
preg_match_all('/matrix\.part\s*==\s*([0-9]+)/', $native, $conditions);
$used = array_values(array_unique(array_map('intval', $conditions[1]))); sort($used);
if ($declared !== $used || count(array_unique($declared)) !== count($declared)) { throw new RuntimeException('Every native part must be declared and used exactly once in the matrix'); }

foreach (array_merge(glob(__DIR__ . '/check-*.php'), glob(__DIR__ . '/check-*.js')) as $test_file)
{
	$name = basename($test_file);
	if (strpos($workflow, '.github/scripts/' . $name) === false)
	{
		$missing[] = $name;
	}
}

if ($missing)
{
	fwrite(STDERR, "CI test inventory failed; the workflows do not execute:\n" . implode("\n", $missing) . "\n");
	exit(1);
}

echo "CI test inventory checks passed.\n";
