<?php

$root = dirname(dirname(__DIR__));
$attachment = (string) file_get_contents($root . '/phpBB2/download.php');
$router = (string) file_get_contents($root . '/phpBB2/dload.php');
$pafile = (string) file_get_contents($root . '/phpBB2/pafiledb/modules/pa_download.php');
$search = (string) file_get_contents($root . '/phpBB2/pafiledb/modules/pa_search.php');
$comments = (string) file_get_contents($root . '/phpBB2/pafiledb/includes/functions_comment.php');
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
	"header('Cache-Control: private, no-store, max-age=0')"
) as $marker)
{
	if (strpos(str_replace("\r\n", "\n", $pafile), $marker) === false)
	{
		$errors[] = 'Missing paFileDB delivery marker: ' . $marker;
	}
}

if (substr_count($rules, 'htmlspecialchars(') < 2)
{
	$errors[] = 'Attachment rule labels are not consistently escaped.';
}

if ($errors)
{
	fwrite(STDERR, implode("\n", $errors) . "\n");
	exit(1);
}

echo "Download delivery safety checks passed.\n";

?>
