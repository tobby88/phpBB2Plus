<?php

$root = dirname(dirname(__DIR__));
$admin = $root . '/phpBB2/admin/admin_links.php';
$body = (string) file_get_contents($admin);
$errors = array();

$required = array(
	"in_array(\$mode, array('add', 'view', 'edit', 'delete', 'update'), true)",
	"in_array(\$action, array('add', 'modify', 'delete'), true)",
	'phpbb_admin_require_post_session();',
	'phpbb_admin_session_field()',
	'function admin_links_post_scalar',
	'isset($link_categories[$link_category])',
	'$db->sql_escape($link_title)',
	"'U_LINK_EDIT' => append_sid",
	"'U_LINK_DELETE' => append_sid"
);

foreach ($required as $marker)
{
	if (strpos($body, $marker) === false)
	{
		$errors[] = 'Missing link administration safety marker: ' . $marker;
	}
}

$forbidden = array(
	"admin_links.\$phpEx?mode=update",
	"'&sid=' . \$userdata['session_id']",
	"str_replace(\"'\", \"''\", \$link_title)",
	"WHERE link_id = '\$link_id'"
);

foreach ($forbidden as $marker)
{
	if (strpos($body, $marker) !== false)
	{
		$errors[] = 'Legacy link administration path remains: ' . $marker;
	}
}

foreach (array('subSilver', 'fisubsilversh') as $style)
{
	$edit = (string) file_get_contents($root . '/phpBB2/templates/' . $style . '/admin/admin_links_edit_body.tpl');
	$list = (string) file_get_contents($root . '/phpBB2/templates/' . $style . '/admin/admin_links_body.tpl');
	if (strpos($edit, '{S_HIDDEN_FIELDS}') === false)
	{
		$errors[] = $style . ' link edit form is missing hidden POST fields.';
	}
	if (strpos($list, '{linkrow.U_LINK_EDIT}') === false || strpos($list, '{linkrow.U_LINK_DELETE}') === false)
	{
		$errors[] = $style . ' link list still assembles administrative URLs in the template.';
	}
}

if ($errors)
{
	fwrite(STDERR, implode("\n", $errors) . "\n");
	exit(1);
}

echo "Link administration safety checks passed.\n";
