<?php

$root = dirname(dirname(__DIR__));
$workflow = file_get_contents($root . '/.github/workflows/php-lint.yml');
$workflow .= "\n" . file_get_contents($root . '/.github/workflows/innodb-migration.yml');
$workflow = str_replace("\r\n", "\n", $workflow);
$missing = array();
$maintenance_suites = array(
	'check-maintenance-pm-counters.php'=>'check-pm-counter-session.php',
	'check-maintenance-pm-repair.php'=>'check-pm-repair-session.php',
	'check-maintenance-pm-recovery.php'=>'check-pm-recovery-session.php'
);

foreach (array_merge(glob(__DIR__ . '/check-*.php'), glob(__DIR__ . '/check-*.js')) as $test_file)
{
	$name = basename($test_file);
	if (strpos($workflow, '.github/scripts/' . $name) === false)
	{
		// Each session entry executes the full base suite before its own cases.
		if (isset($maintenance_suites[$name]))
		{
			$entry = $maintenance_suites[$name];
			$required = "~\\A<\\?php\\s+require\\s+__DIR__\\s*\\.\\s*'/" . preg_quote($name, '~') . "'\\s*;~";
			if (strpos($workflow, 'run: php .github/scripts/' . $entry . "\n") !== false
				&& preg_match($required, file_get_contents(__DIR__ . '/' . $entry))) { continue; }
		}
		$missing[] = $name;
	}
}

if ($missing)
{
	fwrite(STDERR, "CI test inventory failed; the workflows do not execute:\n" . implode("\n", $missing) . "\n");
	exit(1);
}

echo "CI test inventory checks passed.\n";
