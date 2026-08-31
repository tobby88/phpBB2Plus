<?php

function style_admin_assert($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, "Style administration safety test failed: $message\n");
		exit(1);
	}
}

$root = dirname(dirname(__DIR__));
$legacy = file_get_contents($root . '/phpBB2/admin/admin_styles.php');
$xs_include = file_get_contents($root . '/phpBB2/admin/xs_include.php');
$xs_styles = file_get_contents($root . '/phpBB2/admin/xs_styles.php');
$xs_install = file_get_contents($root . '/phpBB2/admin/xs_install.php');
$xs_uninstall = file_get_contents($root . '/phpBB2/admin/xs_uninstall.php');

style_admin_assert(strpos($legacy, 'redirect(append_sid("xs_frameset.$phpEx?action=menu"') !== false, 'legacy style bookmarks must redirect to eXtreme Styles');
style_admin_assert(substr_count($legacy, "\$module['Styles']") === 1, 'legacy endpoint may publish only its replaceable compatibility module');
style_admin_assert(strpos($legacy, "['Add_new']") === false && strpos($legacy, "['Create_new']") === false && strpos($legacy, "['Export']") === false, 'legacy write-capable ACP modules must stay retired');
style_admin_assert(strpos($legacy, 'theme_info.cfg') !== false, 'compatibility endpoint should document why the legacy importer is retired');
style_admin_assert(strpos($legacy, 'include($phpbb_root_path. "templates/"') === false, 'legacy executable style imports must remain unreachable');
style_admin_assert(strpos($legacy, 'sql_query') === false, 'compatibility endpoint must not retain database writes');
style_admin_assert(strpos($xs_include, "\$module['Styles']['Menu'] = 'xs_frameset.'") !== false, 'eXtreme Styles must always publish its replacement menu');

foreach (array($xs_styles, $xs_install, $xs_uninstall) as $manager)
{
	style_admin_assert(strpos($manager, 'phpbb_admin_require_post_session();') !== false, 'active style mutations must require POST/session validation');
}

$retired_templates = array(
	'styles_addnew_body.tpl',
	'styles_edit_body.tpl',
	'styles_exporter.tpl',
	'styles_list_body.tpl'
);
foreach (array('fisubsilversh') as $style)
{
	foreach ($retired_templates as $template)
	{
		style_admin_assert(!file_exists($root . '/phpBB2/templates/' . $style . '/admin/' . $template), 'retired legacy style template remains in ' . $style);
	}
}

echo "Style administration safety tests passed.\n";
