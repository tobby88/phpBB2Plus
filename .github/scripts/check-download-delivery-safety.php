<?php

$root = dirname(dirname(__DIR__));
$attachment = (string) file_get_contents($root . '/phpBB2/download.php');
$router = (string) file_get_contents($root . '/phpBB2/dload.php');
$pafile = (string) file_get_contents($root . '/phpBB2/pafiledb/modules/pa_download.php');
$search = (string) file_get_contents($root . '/phpBB2/pafiledb/modules/pa_search.php');
$comments = (string) file_get_contents($root . '/phpBB2/pafiledb/includes/functions_comment.php');
$pafiledbFunctions = (string) file_get_contents($root . '/phpBB2/pafiledb/includes/functions.php');
$rules = (string) file_get_contents($root . '/phpBB2/attach_rules.php');
$errors = array();

foreach (array(
	'$thumbnail_prefix',
	"\$is_auth['auth_view'] && \$is_auth['auth_read'] && \$is_auth['auth_download']",
	"header('X-Content-Type-Options: nosniff')",
	"header('Cache-Control: private, no-store, max-age=0')",
	'if (!$row)'
) as $marker)
{
	if (strpos($attachment, $marker) === false)
	{
		$errors[] = 'Missing attachment delivery marker: ' . $marker;
	}
}

foreach (array("isset(\$_POST['action']) && is_scalar", "isset(\$_GET['action']) && is_scalar", 'substr($action, 0, 120)') as $marker)
{
	if (strpos($router, $marker) === false)
	{
		$errors[] = 'Missing paFileDB router marker: ' . $marker;
	}
}
if (strpos($router, "\$_REQUEST['action']") !== false)
{
	$errors[] = 'paFileDB routing still accepts cookie-merged action data.';
}

if (strpos($search, '${$store_vars[$i]}') !== false || strpos($search, "'search_results' => \$search_results") === false)
{
	$errors[] = 'paFileDB search state must use an explicit PHP 8 compatible field map.';
}
if (strpos($comments, "isset(\$lang['Comment_do']) ? \$lang['Comment_do'] : \$lang['Comment_add']") === false)
{
	$errors[] = 'paFileDB comment action label lacks a language fallback.';
}

foreach (array(
	"include_once(\$phpbb_root_path . 'includes/page_tail.'.\$phpEx);\n\t\t\t\treturn;",
	"header('X-Content-Type-Options: nosniff')",
	"header('Cache-Control: private, no-store, max-age=0')",
	'pafiledb_resolve_local_download($physical_filename, $upload_dir, $phpbb_root_path)',
	'$file_url = pafiledb_normalize_remote_url($file_url);'
) as $marker)
{
	if (strpos(str_replace("\r\n", "\n", $pafile), $marker) === false)
	{
		$errors[] = 'Missing paFileDB delivery marker: ' . $marker;
	}
}

foreach (array(
	'function pafiledb_resolve_local_download(',
	"strpos(\$upload_normalized, \$root_normalized) !== 0",
	"strpos(\$file_normalized, \$upload_normalized) !== 0",
	'!@is_readable($file_real)'
) as $marker)
{
	if (strpos($pafiledbFunctions, $marker) === false)
	{
		$errors[] = 'Missing paFileDB local-path confinement marker: ' . $marker;
	}
}

if (substr_count($rules, 'htmlspecialchars(') < 2)
{
	$errors[] = 'Attachment rule labels are not consistently escaped.';
}

if (!defined('IN_PHPBB'))
{
	define('IN_PHPBB', true);
}
require_once $root . '/phpBB2/pafiledb/includes/functions.php';

$testBase = rtrim(sys_get_temp_dir(), '/\\') . '/phpbb-pafiledb-download-' . uniqid('', true);
$testRoot = $testBase . '/board';
$testUpload = $testRoot . '/files';
$testOutside = $testBase . '/outside';
@mkdir($testUpload, 0700, true);
@mkdir($testOutside, 0700, true);
file_put_contents($testUpload . '/allowed.zip', 'allowed');
file_put_contents($testOutside . '/secret.txt', 'secret');

$resolvedAllowed = pafiledb_resolve_local_download('allowed.zip', $testUpload, $testRoot);
if ($resolvedAllowed === false || @realpath($resolvedAllowed) !== @realpath($testUpload . '/allowed.zip'))
{
	$errors[] = 'paFileDB rejected a valid local download inside its board directory.';
}
if (pafiledb_resolve_local_download('secret.txt', $testOutside, $testRoot) !== false)
{
	$errors[] = 'paFileDB accepted a download directory outside its board directory.';
}
if (pafiledb_resolve_local_download("missing\r\nname.zip", $testUpload, $testRoot) !== false)
{
	$errors[] = 'paFileDB accepted a nonexistent hostile local filename.';
}
if (pafiledb_normalize_remote_url('https://downloads.example/path\\@attacker.example/file.zip') !== false)
{
	$errors[] = 'paFileDB accepted an ambiguous backslash in a remote URL.';
}

@unlink($testUpload . '/allowed.zip');
@unlink($testOutside . '/secret.txt');
@rmdir($testUpload);
@rmdir($testRoot);
@rmdir($testOutside);
@rmdir($testBase);

if ($errors)
{
	fwrite(STDERR, implode("\n", $errors) . "\n");
	exit(1);
}

echo "Download delivery safety checks passed.\n";

?>
