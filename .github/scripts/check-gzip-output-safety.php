<?php

function gzip_output_assert($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, "FAIL: " . $message . "\n");
		exit(1);
	}
}

$root = dirname(dirname(__DIR__));
$files = array(
	'phpBB2/includes/page_tail.php',
	'phpBB2/admin/page_footer_admin.php',
	'phpBB2/links.js.php',
	'phpBB2/printview.php',
	'phpBB2/admin/admin_db_utilities.php',
);

foreach ($files as $relative)
{
	$source = file_get_contents($root . '/' . $relative);
	gzip_output_assert(strpos($source, 'gzencode(') !== false, $relative . ' must emit a complete gzip stream');
	gzip_output_assert(strpos($source, '"\\x1f\\x8b\\x08') === false, $relative . ' must not assemble gzip framing manually');
}

$sample = "phpBB2 Plus gzip regression\n";
$encoded = gzencode($sample, 9);
gzip_output_assert(substr($encoded, 0, 2) === "\x1f\x8b", 'gzencode output must use the gzip magic bytes');
gzip_output_assert(gzdecode($encoded) === $sample, 'gzip output must round-trip through a standards-compliant decoder');

echo "Gzip output safety checks passed.\n";

