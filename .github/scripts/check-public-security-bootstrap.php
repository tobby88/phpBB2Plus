<?php

$root = dirname(dirname(__DIR__));
$public_root = $root . '/phpBB2';
$errors = array();

foreach (glob($public_root . '/*.php') as $file)
{
	$name = basename($file);
	if ($name === 'config.php')
	{
		continue;
	}

	$source = file_get_contents($file);
	$loads_common = preg_match('/(?:include|include_once|require|require_once)\s*\(?[^;\r\n]*[\'\"]common\.[^;\r\n]*\$phpEx/', $source) === 1;
	$validated_standalone = preg_match('/define\s*\(\s*[\'\"]PHPBB_STANDALONE_VALIDATED[\'\"]\s*,\s*true\s*\)/i', $source) === 1;
	$include_only = preg_match('/if\s*\(\s*!\s*defined\s*\(\s*[\'\"]IN_PHPBB[\'\"]\s*\)/i', $source) === 1;

	if (!$loads_common && !$validated_standalone && !$include_only)
	{
		$errors[] = $name . ' neither loads common.php nor declares a validated standalone/include-only boundary';
	}
}

$common = file_get_contents($public_root . '/common.php');
foreach (array('ct_security.', 'ct_varsetter.', 'ct_request_limiter.', 'ct_ipblocker.') as $engine)
{
	if (strpos($common, "ctracker/engines/$engine") === false)
	{
		$errors[] = 'common.php no longer loads ' . $engine;
	}
}

$standalone = file_get_contents($public_root . '/text2schild.php');
if (strpos($standalone, "define('PHPBB_STANDALONE_VALIDATED', true)") === false ||
	strpos($standalone, "includes/php_compat.php") === false)
{
	$errors[] = 'The sessionless image endpoint lacks its explicit validated boundary.';
}

if ($errors)
{
	fwrite(STDERR, "Public security bootstrap audit failed:\n- " . implode("\n- ", $errors) . "\n");
	exit(1);
}

echo "Public security bootstrap audit passed.\n";

?>
