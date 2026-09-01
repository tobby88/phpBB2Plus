<?php

define('IN_PHPBB', true);
require dirname(dirname(__DIR__)) . '/phpBB2/includes/functions.php';

function standard_style_assert($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, "Standard style safety test failed: $message\n");
		exit(1);
	}
}

$root = dirname(dirname(__DIR__));
$templates_root = $root . '/phpBB2/templates';
$functions = file_get_contents($root . '/phpBB2/includes/functions.php');
$tail = file_get_contents($root . '/phpBB2/includes/page_tail.php');
$template_engine = file_get_contents($root . '/phpBB2/includes/template.php');
$basic = file_get_contents($root . '/phpBB2/install/schemas/mysql_basic.sql');
$updater = file_get_contents($root . '/update/update_from_153a.php');
$footer = file_get_contents($templates_root . '/fisubsilversh/overall_footer.tpl');
$header = file_get_contents($templates_root . '/fisubsilversh/overall_header.tpl');
$simple_header = file_get_contents($templates_root . '/fisubsilversh/simple_header.tpl');
$page_header = file_get_contents($root . '/phpBB2/includes/page_header.php');

$style_directories = array();
foreach (glob($templates_root . '/*', GLOB_ONLYDIR) as $directory)
{
	$style_directories[] = basename($directory);
}
sort($style_directories);
standard_style_assert($style_directories === array('fisubsilversh'), 'only FI Subsilver Shadow may be bundled');
standard_style_assert(!is_file($root . '/phpBB2/style_switch.php'), 'the retired display-mode endpoint must not return');
standard_style_assert(strpos($functions, 'phpbb_style_mode') === false && strpos($functions, 'phpbb_mobile_style_id') === false, 'automatic mobile selection must remain removed');
standard_style_assert(strpos($tail, 'STYLE_SWITCHER') === false && strpos($footer, 'STYLE_SWITCHER') === false, 'the retired footer switcher must not return');
standard_style_assert(preg_match_all('/^INSERT INTO phpbb_themes\s*\(/m', $basic, $theme_matches) === 1, 'fresh installs must seed exactly one style');
standard_style_assert(strpos($basic, "'fisubsilversh', 'FI Subsilver Shadow'") !== false, 'fresh installs must seed FI Subsilver Shadow');
standard_style_assert(strpos($updater, 'function update_queue_standard_style') !== false, 'the updater must normalize existing style records');
standard_style_assert(strpos($updater, "WHERE template_name <> 'fisubsilversh'") !== false, 'the updater must remove obsolete style records');
standard_style_assert(strpos($updater, "config_name = 'xs_def_template'") !== false, 'the updater must normalize the eXtreme Styles fallback');
standard_style_assert(strpos($updater, "'xs_def_template' => 'fisubsilversh'") !== false, 'the updater must create a missing fallback setting');
standard_style_assert(strpos($template_engine, "var \$tpldef = 'fisubsilversh';") !== false, 'the template fallback must use FI Subsilver Shadow');
standard_style_assert(strpos($page_header, "strtolower((string) \$board_config['default_lang']) === 'german'") !== false, 'the page header must expose the language pack selected by user preference initialization');
standard_style_assert(strpos($header, 'lang="{S_CONTENT_LANGUAGE}"') !== false, 'the public document must declare its language');
standard_style_assert(strpos($simple_header, 'lang="{S_CONTENT_LANGUAGE}"') !== false, 'simple public documents must declare their language');

$safe_theme = phpbb_standard_theme_row(array(
	'themes_id' => '2',
	'template_name' => '../outside',
	'head_stylesheet' => '\"><script>alert(1)</script>',
	'body_background' => '../outside.php',
	'td_color1' => "fff';alert(1);//",
	'td_class1' => 'row1 onclick=alert(1)',
	'fontface1' => 'Arial;</style><script>alert(1)</script>',
	'fontsize1' => 999,
	'theme_public' => 1,
));
standard_style_assert($safe_theme['template_name'] === 'fisubsilversh' && $safe_theme['head_stylesheet'] === 'fisubsilversh.css', 'database theme paths must resolve to the preserved style');
standard_style_assert($safe_theme['body_background'] === '' && $safe_theme['td_color1'] === '' && $safe_theme['td_class1'] === '' && $safe_theme['fontface1'] === '', 'unsafe theme presentation values must fail closed');
standard_style_assert($safe_theme['fontsize1'] === 72, 'theme numeric presentation values must be bounded');
standard_style_assert(strpos($functions, '$row = phpbb_standard_theme_row($row);') !== false, 'style setup must sanitize database rows before path and template use');

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($templates_root . '/fisubsilversh', FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file)
{
	if (!$file->isFile() || strtolower($file->getExtension()) !== 'tpl')
	{
		continue;
	}
	$source = file_get_contents($file->getPathname());
	standard_style_assert(strpos($source, 'templates/subSilver/') === false, $file->getPathname() . ' references removed subSilver assets');
}

echo "Standard style safety checks passed.\n";
