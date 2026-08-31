<?php

function web_source_assert($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, "Web source exposure test failed: $message\n");
		exit(1);
	}
}

$root = dirname(dirname(__DIR__));
$rules = file_get_contents($root . '/phpBB2/.htaccess');

web_source_assert(strpos($rules, 'Options -Indexes') !== false, 'directory listings must be disabled');
web_source_assert(strpos($rules, '^config\\.php$') !== false, 'the live database configuration must be denied explicitly');
foreach (array('inc', 'tpl', 'cfg', 'sql', 'log', 'cache', 'bak', 'old', 'orig', 'save', 'swp', 'dist') as $extension)
{
	web_source_assert(strpos($rules, $extension) !== false, '.' . $extension . ' sources must be denied');
}
web_source_assert(strpos($rules, 'Require all denied') !== false, 'Apache 2.4 authorization must be present');
web_source_assert(strpos($rules, 'Deny from all') !== false, 'Apache 2.2 authorization fallback must be present');

echo "Web source exposure checks passed.\n";

