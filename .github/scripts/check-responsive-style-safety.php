<?php

function responsive_style_assert($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, "Responsive style safety test failed: $message\n");
		exit(1);
	}
}

$root = dirname(dirname(__DIR__));
$functions = file_get_contents($root . '/phpBB2/includes/functions.php');
$switcher = file_get_contents($root . '/phpBB2/style_switch.php');
$tail = file_get_contents($root . '/phpBB2/includes/page_tail.php');
$schema = file_get_contents($root . '/phpBB2/install/schemas/mysql_schema.sql');
$basic = file_get_contents($root . '/phpBB2/install/schemas/mysql_basic.sql');
$updater = file_get_contents($root . '/update/update_from_153a.php');

responsive_style_assert(strpos($functions, "array('BS_subSilver', 'BS_subIce', 'BS')") !== false, 'responsive styles must be discovered by template name, not a database ID');
responsive_style_assert(strpos($functions, "array('auto', 'mobile', 'desktop')") !== false, 'style preference modes are incomplete');
responsive_style_assert(strpos($functions, "isset(\$row['theme_public']) && !\$row['theme_public']") !== false, 'non-public responsive styles must not be auto-selected');
responsive_style_assert(strpos($switcher, "\$_SERVER['REQUEST_METHOD']") !== false && strpos($switcher, "hash_equals((string) \$userdata['session_id'], \$sid)") !== false, 'style preference mutation must require POST and the active session');
responsive_style_assert(strpos($switcher, "phpbb_setcookie(\$board_config['cookie_name'] . '_style_mode'") !== false, 'style mode cookie is missing');
responsive_style_assert(strpos($tail, "'STYLE_SWITCHER' => \$style_switcher") !== false, 'footer switcher is not assigned');

responsive_style_assert((bool) preg_match('/theme_public\s+tinyint\(1\).*default\s+\'1\'/i', $schema), 'fresh schema does not expose public styles');
responsive_style_assert((bool) preg_match('/^INSERT INTO\s+phpbb_themes\s*\(([^;]+)\)\s*VALUES\s*\(([^;]+)\);/im', $basic, $theme_insert), 'fresh theme seed must use an explicit column list');
$theme_columns = array_map('trim', explode(',', $theme_insert[1]));
$theme_values = str_getcsv($theme_insert[2], ',', "'", '\\');
responsive_style_assert(count($theme_columns) === count($theme_values), 'fresh theme seed column/value counts differ');
responsive_style_assert(in_array('theme_public', $theme_columns, true), 'fresh theme seed omits theme_public');
responsive_style_assert(strpos($updater, "'theme_public', \"TINYINT(1) UNSIGNED NOT NULL DEFAULT '1'\"") !== false, 'post-1.53a migration omits public-style schema');

foreach (array('BS', 'BS_subIce', 'BS_subSilver', 'fisubsilversh', 'prosilver', 'prosilver_se', 'subSilver') as $style)
{
	$footer = file_get_contents($root . '/phpBB2/templates/' . $style . '/overall_footer.tpl');
	responsive_style_assert(strpos($footer, '{STYLE_SWITCHER}') !== false, 'style switcher missing from ' . $style);
}

echo "Responsive style safety checks passed.\n";
