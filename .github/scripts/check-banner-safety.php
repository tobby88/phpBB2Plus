<?php

$root = dirname(dirname(__DIR__));
$admin = (string) file_get_contents($root . '/phpBB2/admin/admin_banner.php');
$header = (string) file_get_contents($root . '/phpBB2/includes/page_header.php');
$redirect = (string) file_get_contents($root . '/phpBB2/redirect.php');
$errors = array();

$admin_required = array(
	"in_array(\$mode, array('', 'edit', 'add', 'save', 'delete'), true)",
	'function admin_banner_post_scalar',
	'phpbb_admin_require_post_session();',
	'phpbb_admin_session_field()',
	'in_array($banner_type, array(0, 2, 6), true)',
	"in_array(\$time_type, array(0, 2, 4, 6), true)",
	'$db->sql_escape($banner_name)',
	"foreach (\$options as \$offset => \$type)"
);
foreach ($admin_required as $marker)
{
	if (strpos($admin, $marker) === false)
	{
		$errors[] = 'Missing banner administration safety marker: ' . $marker;
	}
}

foreach (array('@each(', 'ereg(', "\$_GET['date_begin_week']", "\$_GET['time_begin_hour']", '<input type="hidden" name="sid"', 'str_replace("\\\'", "\'\'")') as $marker)
{
	if (strpos($admin, $marker) !== false)
	{
		$errors[] = 'Legacy banner administration path remains: ' . $marker;
	}
}
if (strpos($admin, "\$banner_example = \$banner_info['banner_name']") !== false ||
	strpos($header, "\$banners[\$i]['banner_name'] );") !== false)
{
	$errors[] = 'The raw custom-HTML banner renderer must remain retired';
}
$english_banner_language = (string) file_get_contents($root . '/phpBB2/language/lang_english/lang_banner.php');
$german_banner_language = (string) file_get_contents($root . '/phpBB2/language/lang_german/lang_banner.php');
if (strpos($english_banner_language, "Banner_type'][4]") !== false || strpos($german_banner_language, "Banner_type'][4]") !== false)
{
	$errors[] = 'The AdminCP must not offer arbitrary stored HTML banners';
}
$updater = (string) file_get_contents($root . '/update/update_from_153a.php');
if (strpos($updater, 'SET banner_type = 2 WHERE banner_type = 4') === false)
{
	$errors[] = 'The upgrade path must convert legacy custom-HTML banners to escaped text banners';
}

foreach (array('$safe_banner_name = htmlspecialchars', '$safe_banner_description = htmlspecialchars', '(int) $banner_spot') as $marker)
{
	if (strpos($header, $marker) === false)
	{
		$errors[] = 'Public banner rendering is missing: ' . $marker;
	}
}

foreach (array('is_scalar($banner_id_value)', 'phpbb_normalize_external_url($banner_data[\'banner_url\'])') as $marker)
{
	if (strpos($redirect, $marker) === false)
	{
		$errors[] = 'Banner redirect is missing: ' . $marker;
	}
}

foreach (array('$db->sql_escape($userdata[\'session_ip\'])', '$user_duration = max(0,', 'intval($banner_id)') as $marker)
{
	if (strpos($redirect, $marker) === false)
	{
		$errors[] = 'Banner redirect hardening is missing: ' . $marker;
	}
}
if (strpos($redirect, "ctracker_enforce_request_limit_profile(array('tracked-redirect', 300, 'request_limit_content', 60))") === false)
{
	$errors[] = 'Tracked banner redirects must have a dedicated bounded request bucket';
}
$cookie_guard = strpos($redirect, 'if (!isset($HTTP_COOKIE_VARS[$cookie_name]))');
$stats_insert = strpos($redirect, 'INSERT INTO " . BANNER_STATS_TABLE');
if ($cookie_guard === false || $stats_insert === false || $stats_insert < $cookie_guard)
{
	$errors[] = 'Detailed banner statistics must share the public click-cookie filter';
}
if ($errors)
{
	fwrite(STDERR, implode("\n", $errors) . "\n");
	exit(1);
}

echo "Banner safety checks passed.\n";
