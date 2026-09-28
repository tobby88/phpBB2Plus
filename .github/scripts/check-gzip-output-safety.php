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
);

foreach ($files as $relative)
{
	$source = file_get_contents($root . '/' . $relative);
	gzip_output_assert(strpos($source, 'gzencode(') !== false, $relative . ' must emit a complete gzip stream');
	gzip_output_assert(strpos($source, '"\\x1f\\x8b\\x08') === false, $relative . ' must not assemble gzip framing manually');
}

// Backup output uses a disk spool with zlib's complete gzip wrapper rather
// than buffering the full dump for gzencode. Native tests decode the actual
// controller response; this check also exercises the configured filter.
$backup = file_get_contents($root . '/phpBB2/includes/functions_database_backup.php');
gzip_output_assert(strpos($backup, "'zlib.deflate'") !== false && strpos($backup, "'window'=>31") !== false, 'backup must select a complete gzip wrapper');
gzip_output_assert(strpos($backup, 'stream_filter_remove($filter)') !== false, 'backup must finalize the gzip trailer before publication');
$backup_controller = file_get_contents($root . '/phpBB2/admin/admin_db_utilities.php');
gzip_output_assert(strpos($backup_controller, 'phpbb_database_backup_capture(') !== false && strpos($backup_controller, 'fpassthru($backup_stream)') !== false, 'backup must publish its completed spool');
$spool = tmpfile();
gzip_output_assert(is_resource($spool), 'disposable gzip spool');
$filter = stream_filter_append($spool, 'zlib.deflate', STREAM_FILTER_WRITE, array('level'=>9,'window'=>31));
gzip_output_assert(is_resource($filter), 'configured gzip filter');
$payload = "Backup gzip filter regression\n";
fwrite($spool, $payload);
gzip_output_assert(stream_filter_remove($filter), 'gzip filter finalization');
rewind($spool); $spooled = stream_get_contents($spool); fclose($spool);
gzip_output_assert(substr($spooled, 0, 2) === "\x1f\x8b" && gzdecode($spooled) === $payload, 'spooled gzip framing and checksum decode correctly');

$header_files = array(
	'phpBB2/includes/page_header.php',
	'phpBB2/admin/page_header_admin.php',
	'phpBB2/links.js.php',
	'phpBB2/printview.php',
);
foreach ($header_files as $relative)
{
	$source = file_get_contents($root . '/' . $relative);
	gzip_output_assert(strpos($source, 'ob_gzhandler') === false, $relative . ' must use the deterministic gzip footer path');
	gzip_output_assert(strpos($source, "preg_match('/(?:^|,)\\s*gzip") !== false, $relative . ' must negotiate the gzip encoding as a token');
}

foreach (array('phpBB2/includes/page_header.php', 'phpBB2/admin/page_header_admin.php') as $relative)
{
	$source = file_get_contents($root . '/' . $relative);
	gzip_output_assert(strpos($source, 'global $do_gzip_compress;') !== false, $relative . ' must share compression state with message_die() footers');
}

$page_tail = file_get_contents($root . '/phpBB2/includes/page_tail.php');
gzip_output_assert(strpos($page_tail, "!defined('AJAX_HEADERS') && !\$do_gzip_compress") !== false, 'short URL output must not consume the buffer before gzip encoding');

$sample = "phpBB2 Plus gzip regression\n";
$encoded = gzencode($sample, 9);
gzip_output_assert(substr($encoded, 0, 2) === "\x1f\x8b", 'gzencode output must use the gzip magic bytes');
gzip_output_assert(gzdecode($encoded) === $sample, 'gzip output must round-trip through a standards-compliant decoder');

echo "Gzip output safety checks passed.\n";
