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
$removed_calls = array();
foreach (glob($root . '/update/*.php') as $file)
{
	$source = file_get_contents($file);
	$update_sources .= $source . "\n";
	$tokens = token_get_all($source);
	for ($i = 0; $i < count($tokens); $i++)
	{
		if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_STRING)
		{
			continue;
		}
		$name = strtolower($tokens[$i][1]);
		if (!in_array($name, array('each', 'mysql_escape_string'), true))
		{
			continue;
		}
		for ($j = $i + 1; $j < count($tokens); $j++)
		{
			if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) { continue; }
			if ($tokens[$j] === '(') { $removed_calls[] = $name; }
			break;
		}
	}
}

removed_runtime_assert(!in_array('each', $removed_calls, true), 'update scripts must not call removed each()');
removed_runtime_assert(!in_array('mysql_escape_string', $removed_calls, true), 'update scripts must use the active database driver escape routine');
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

define('IN_PHPBB', true);
require_once $root . '/phpBB2/attach_mod/posting_attachments.php';
foreach (array('attachment_id_list', 'attachment_comment_list', 'attachment_filesize_list', 'attachment_filetime_list', 'attachment_filename_list', 'attachment_extension_list', 'attachment_mimetype_list', 'attachment_list', 'attachment_thumbnail_list') as $property)
{
	removed_runtime_assert(property_exists('attach_parent', $property), 'attachment request state must use declared properties: ' . $property);
}

define('IN_MINI_CAL', true);
require_once $root . '/phpBB2/includes/mini_cal/calendarSuite.php';
foreach (array('dateYYYY', 'monthStart', 'formatted', 'language') as $property)
{
	removed_runtime_assert(property_exists('calendarSuite', $property), 'Mini Calendar runtime state must use declared properties: ' . $property);
}

$pafiledb_template = file_get_contents($root . '/phpBB2/pafiledb/includes/template.php');
removed_runtime_assert(strpos($pafiledb_template, "'for (\$this->_'") === false, 'paFileDB compiled loops must not create PHP 8 dynamic counter properties');
removed_runtime_assert(strpos($pafiledb_template, "'for (\$_' . \$tag_args") !== false, 'paFileDB compiled loops must use local counters');

echo "Removed-runtime safety checks passed.\n";
