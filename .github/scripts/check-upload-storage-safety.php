<?php

function upload_storage_assert($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, "Upload storage safety test failed: $message\n");
		exit(1);
	}
}

$root = dirname(dirname(__DIR__));
$rule_files = array(
	'files/.htaccess',
	'album_mod/upload/.htaccess',
	'album_mod/upload/cache/.htaccess',
	'images/avatars/.htaccess',
	'pafiledb/uploads/.htaccess',
	'pafiledb/images/screenshots/.htaccess'
);

foreach ($rule_files as $relative)
{
	$rules = file_get_contents($root . '/phpBB2/' . $relative);
	upload_storage_assert(strpos($rules, 'html?') !== false, $relative . ' must deny raw HTML uploads');
	upload_storage_assert(strpos($rules, 'svg') !== false, $relative . ' must deny raw SVG uploads');
	upload_storage_assert(strpos($rules, 'm?js') !== false, $relative . ' must deny raw JavaScript uploads');
	upload_storage_assert(strpos($rules, 'pdf') !== false, $relative . ' must deny raw PDF uploads');
	upload_storage_assert(strpos($rules, 'X-Content-Type-Options "nosniff"') !== false, $relative . ' must disable MIME sniffing');
	upload_storage_assert(strpos($rules, 'Require all denied') !== false, $relative . ' must support Apache 2.4');
	upload_storage_assert(strpos($rules, 'Deny from all') !== false, $relative . ' must support Apache 2.2');
}

echo "Upload storage safety checks passed.\n";

