<?php

function pagination_boundary_assert($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, "Pagination boundary test failed: $message\n");
		exit(1);
	}
}

$root = dirname(dirname(__DIR__));
$bounded_public_pages = array(
	'activity.php' => "max(0, min(1000000, (int) \$arcade->pass_var('start', 0)))",
	'album.php' => "max(0, min(1000000, intval(phpbb_request_scalar(\$_GET, 'start', 0))))",
	'album_cat.php' => "max(0, min(1000000, intval(phpbb_request_scalar(\$_GET, 'start', 0))))",
	'album_personal.php' => "max(0, min(1000000, intval(phpbb_request_scalar(\$_GET, 'start', 0))))",
	'album_showpage.php' => "max(0, min(1000000, intval(phpbb_request_scalar(\$_GET, 'start', 0))))",
	'album_modcp.php' => "max(0, min(1000000, intval(phpbb_request_scalar(\$_GET, 'start', 0))))",
	'merge.php' => "max(0, min(1000000, intval(phpbb_request_scalar(\$_POST, 'start', 0))))",
	'admin/admin_attach_cp.php' => "max(0, min(1000000, get_var('start', 0)))",
	'uacp.php' => "max(0, min(1000000, get_var('start', 0)))"
);

foreach ($bounded_public_pages as $relative => $marker)
{
	$source = file_get_contents($root . '/phpBB2/' . $relative);
	pagination_boundary_assert(strpos($source, $marker) !== false, $relative . ' must bound pagination before composing SQL LIMIT');
}

echo "Pagination boundary tests passed.\n";
