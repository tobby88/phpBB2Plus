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
define('IN_PHPBB', true);
require $root . '/phpBB2/includes/php_compat.php';
$mode = isset($argv[1]) ? $argv[1] : 'normal';
gzip_output_assert(in_array($mode, array('normal', 'ajax'), true), 'known compression fixture mode');
if ($mode === 'ajax') { define('AJAX_HEADERS', true); }
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
	gzip_output_assert(strpos($source, 'phpbb_accepts_gzip($accept_encoding)') !== false, $relative . ' must use the shared quality-aware negotiation');
}

foreach (array('phpBB2/includes/page_header.php', 'phpBB2/admin/page_header_admin.php') as $relative)
{
	$source = file_get_contents($root . '/' . $relative);
	gzip_output_assert(strpos($source, 'global $do_gzip_compress;') !== false, $relative . ' must share compression state with message_die() footers');
}

$page_tail = file_get_contents($root . '/phpBB2/includes/page_tail.php');
gzip_output_assert(strpos($page_tail, "!defined('AJAX_HEADERS') && !\$do_gzip_compress") !== false, 'short URL output must not consume the buffer before gzip encoding');

$sample = "phpBB2 Plus gzip regression: Grüße 😀 &amp;\n";
$encoded = gzencode($sample, 9);
gzip_output_assert(substr($encoded, 0, 2) === "\x1f\x8b", 'gzencode output must use the gzip magic bytes');
gzip_output_assert(gzdecode($encoded) === $sample, 'gzip output must round-trip through a standards-compliant decoder');

// Execute the actual four header conditions and matching footer compression
// blocks. No page bootstrap, database, login or real HTTP request is involved.
$cases = array(
	array('', false), array('gzip', true), array('br, gzip', true), array('GZIP', true), array('x-gzip', true),
	array('gzip;q=0', false), array('GZIP; Q=0.000', false), array('gzip ; q = 0', false), array('gzip;q=0.', false),
	array('gzip;q=0.001', true), array('gzip;q=0.5', true), array('gzip;q=1.000', true), array('gzip;q=1.001', false),
	array('gzip;q=2', false), array('gzip;q=-0.1', false), array('gzip;q=NaN', false), array('gzip;q=.5', false),
	array('gzip;q=0.0000', false), array('*', true), array('*;q=0', false), array('br', false),
	array('xgzip', false), array('foo-gzip', false), array('gzipfoo', false),
	array('gzip;q=0, *;q=1', false), array('*;q=0, gzip;q=1', true), array('gzip;q=0.1, *;q=0.9', true),
	array('gzip;q=0, gzip;q=1', false), array('gzip;q=1, gzip;q=0', false), array('gzip;q=1;x=y', false),
	array('gzip;q=0.5, identity;q=1', false), array('gzip;q=1, identity;q=0.5', true),
	array('*;q=0.1, identity;q=1', false), array('x-gzip;q=0, gzip;q=1', false),
	array('gzip;foo=bar', false), array('gzip;foo=bar, *;q=1', false),
	array(array('gzip'), false), array(new stdClass(), false), array(null, false), array(true, false), array(0, false),
	array("gzip\r\n", false), array(str_repeat('a', 8193), false), array("gzip\0", false),
	array(" \tbr, GZIP \t; q=0.5 \t", true), array('identity;q=0, gzip', true),
	array('gzip;q=0.5, identity;q=0.5', true), array(', , gzip, ,', true)
);
$routes = array(
	'phpBB2/includes/page_header.php'=>'phpBB2/includes/page_tail.php',
	'phpBB2/admin/page_header_admin.php'=>'phpBB2/admin/page_footer_admin.php',
	'phpBB2/printview.php'=>'phpBB2/printview.php',
	'phpBB2/links.js.php'=>'phpBB2/links.js.php'
);
$runtime_cases = 0;
foreach ($cases as $case)
{
	$before = serialize($case[0]);
	gzip_output_assert(phpbb_accepts_gzip($case[0]) === $case[1], 'quality/token/shape helper case');
	gzip_output_assert(serialize($case[0]) === $before, 'negotiation never rewrites the header');
}
foreach ($routes as $header_file=>$footer_file)
{
	$header = file_get_contents($root . '/' . $header_file);
	$footer = file_get_contents($root . '/' . $footer_file);
	gzip_output_assert(preg_match('/\$accept_encoding =[^\n]+\nif\s*\((.*?)\)\s*(\{.*?\n\})/s', $header, $head) === 1, 'actual header block exists');
	gzip_output_assert(preg_match('/if\s*\(\s*\$do_gzip_compress\s*\)\s*\{(.*?)\n\}\s*(?:exit;)?\s*(?:\?>)?\s*\z/s', $footer, $tail) === 1, 'actual compression footer exists');
	foreach (array(0, 1) as $enabled) { foreach ($cases as $case)
	{
		$_SERVER = array('HTTP_ACCEPT_ENCODING'=>$case[0]); $snapshot = serialize($_SERVER);
		$board_config = array('gzip_compress'=>$enabled); $plus_config = array('enable_shorturls'=>0);
		$do_gzip_compress = false; $level = ob_get_level(); ob_start();
		eval($head[0]);
		$expected = (bool) ($enabled && $case[1] && !($mode === 'ajax' && $header_file === 'phpBB2/includes/page_header.php'));
		gzip_output_assert($do_gzip_compress === $expected, 'actual header respects client, board switch and AJAX guard');
		echo $sample;
		eval('if ($do_gzip_compress) {' . $tail[1] . '}');
		$wire = ob_get_clean();
		gzip_output_assert(ob_get_level() === $level && serialize($_SERVER) === $snapshot, 'actual response restores buffers and leaves request unchanged');
		gzip_output_assert($expected ? (substr($wire, 0, 2) === "\x1f\x8b" && gzdecode($wire) === $sample) : $wire === $sample, 'actual wire bytes match the negotiated encoding');
		$runtime_cases++;
	}}
}
echo 'Gzip output safety ', $mode, ': ', $runtime_cases, " actual header/footer cases PASS; quality values, wildcard refusals, AJAX, board switch and round-trip bytes.\n";
