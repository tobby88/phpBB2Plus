<?php

$root = dirname(dirname(__DIR__));
$admin = $root . '/phpBB2/admin/admin_news_cats.php';
$body = (string) file_get_contents($admin);
$errors = array();

$required = array(
	"in_array(\$mode, array('', 'delete', 'edit', 'save', 'savenew'), true)",
	'phpbb_admin_require_post_session();',
	'phpbb_admin_session_field()',
	"if (!isset(\$_POST['id']) || !is_scalar(\$_POST['id']))",
	'in_array($news_image, $category_images, true)',
	'$db->sql_escape($news_category)',
	"substr(trim((string) \$_POST['category']), 0, 70)"
);

foreach ($required as $marker)
{
	if (strpos($body, $marker) === false)
	{
		$errors[] = 'Missing news administration safety marker: ' . $marker;
	}
}

if (strpos($body, '<input type="hidden" name="sid"') !== false)
{
	$errors[] = 'News administration still hand-builds session token fields.';
}

if (substr_count($body, 'phpbb_admin_require_post_session();') < 3)
{
	$errors[] = 'Delete, edit-save and create must each enforce a POST token.';
}

if ($errors)
{
	fwrite(STDERR, implode("\n", $errors) . "\n");
	exit(1);
}

echo "News category administration safety checks passed.\n";
