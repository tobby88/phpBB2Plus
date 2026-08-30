<?php

$root = dirname(dirname(__DIR__));
$errors = array();
$markers = array(
	'phpBB2/includes/functions.php' => array('$dir !== false && ($file = @readdir($dir)) !== false'),
	'phpBB2/includes/functions_selects.php' => array('$dir !== false && ($file = readdir($dir)) !== false'),
	'phpBB2/includes/functions_dbmtnc.php' => array('$dir !== false && ($file = readdir($dir)) !== false'),
	'phpBB2/includes/lang_extend_mac.php' => array('$dir !== false && ($file = @readdir($dir)) !== false'),
	'phpBB2/admin/admin_album_config_extended.php' => array('$dir !== false && ($config_file = @readdir($dir)) !== false'),
	'phpBB2/includes/usercp_avatar.php' => array('array_keys($avatar_images)', '@closedir($sub_dir);', 'is_array($field_value)'),
	'phpBB2/admin/admin_users.php' => array('user_avatar_gallery_directory()', 'array_keys($avatar_images)', '@closedir($sub_dir);')
);

foreach ($markers as $relative => $needles)
{
	$body = (string) @file_get_contents($root . '/' . $relative);
	foreach ($needles as $needle)
	{
		if (strpos($body, $needle) === false)
		{
			$errors[] = 'Missing directory-iteration safety marker in ' . $relative . ': ' . $needle;
		}
	}
}

foreach (array('phpBB2/includes/usercp_avatar.php', 'phpBB2/admin/admin_users.php') as $relative)
{
	$body = (string) @file_get_contents($root . '/' . $relative);
	if (strpos($body, 'each($avatar_images)') !== false)
	{
		$errors[] = 'Avatar gallery still relies on the removed each() iterator: ' . $relative;
	}
}

if ($errors)
{
	fwrite(STDERR, implode("\n", $errors) . "\n");
	exit(1);
}

echo "Directory iteration safety checks passed.\n";

