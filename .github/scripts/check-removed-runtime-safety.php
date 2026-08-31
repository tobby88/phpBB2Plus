<?php

function removed_runtime_assert($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, "Removed-runtime safety test failed: $message\n");
		exit(1);
	}
}

$root = dirname(dirname(__DIR__));
$update_sources = '';
foreach (glob($root . '/update/*.php') as $file)
{
	$update_sources .= file_get_contents($file) . "\n";
}

removed_runtime_assert(!preg_match('/\beach\s*\(/i', $update_sources), 'update scripts must not call removed each()');
removed_runtime_assert(!preg_match('/\bmysql_escape_string\s*\(/i', $update_sources), 'update scripts must use the active database driver escape routine');
removed_runtime_assert(strpos($update_sources, '" WHERE topic_id = " . $row[') === false, 'topic cleanup must retain the known topic ID when no posts exist');

$captcha = file_get_contents($root . '/phpBB2/includes/usercp_confirm_adv.php');
removed_runtime_assert(strpos($captcha, '$use_ttf = count($fonts) > 0') !== false, 'advanced CAPTCHA must tolerate an empty font directory');
removed_runtime_assert(strpos($captcha, 'if (!$use_ttf)') !== false, 'advanced CAPTCHA must have a built-in-font fallback');
removed_runtime_assert(strpos($captcha, 'imagestring($image, $builtin_font') !== false, 'advanced CAPTCHA fallback must remain usable');

$functions = file_get_contents($root . '/phpBB2/includes/functions.php');
$page_header = file_get_contents($root . '/phpBB2/includes/page_header.php');
$admin_header = file_get_contents($root . '/phpBB2/admin/page_header_admin.php');
removed_runtime_assert(strpos($functions, 'function phpbb_timezone_label(') !== false, 'timezone labels need a safe fallback helper');
removed_runtime_assert(strpos($page_header, 'phpbb_timezone_label(') !== false, 'public header must use safe timezone labels');
removed_runtime_assert(strpos($admin_header, 'phpbb_timezone_label(') !== false, 'admin header must use safe timezone labels');

echo "Removed-runtime safety checks passed.\n";
