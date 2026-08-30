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

foreach (array('$safe_banner_name = htmlspecialchars', '$safe_banner_description = htmlspecialchars', '(int) $banner_spot') as $marker)
{
	if (strpos($header, $marker) === false)
	{
		$errors[] = 'Public banner rendering is missing: ' . $marker;
	}
}

foreach (array('is_scalar($banner_id_value)', "isset(\$redirect_parts['user'])", "isset(\$redirect_parts['pass'])") as $marker)
{
	if (strpos($redirect, $marker) === false)
	{
		$errors[] = 'Banner redirect is missing: ' . $marker;
	}
}

if ($errors)
{
	fwrite(STDERR, implode("\n", $errors) . "\n");
	exit(1);
}

echo "Banner safety checks passed.\n";
