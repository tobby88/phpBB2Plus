<?php

define('IN_PHPBB', true);
require dirname(dirname(__DIR__)) . '/phpBB2/includes/functions.php';

function image_processing_assert($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, "Image processing safety test failed: $message\n");
		exit(1);
	}
}

image_processing_assert(phpbb_image_dimensions_safe(4000, 4000), 'ordinary high-resolution pictures must remain supported');
image_processing_assert(!phpbb_image_dimensions_safe(5000, 5000), 'decoded images above the pixel budget must be rejected');
image_processing_assert(!phpbb_image_dimensions_safe(1, 20001), 'extreme single dimensions must be rejected');
image_processing_assert(!phpbb_image_dimensions_safe(0, 100), 'zero dimensions must be rejected');

$root = dirname(dirname(__DIR__));
$sources = array(
	'phpBB2/album_upload.php',
	'phpBB2/album_nuffload.php',
	'phpBB2/album_thumbnail.php',
	'phpBB2/album_picm.php',
	'phpBB2/attach_mod/includes/functions_thumbs.php',
	'phpBB2/includes/usercp_avatar.php',
);

foreach ($sources as $relative)
{
	$source = file_get_contents($root . '/' . $relative);
	image_processing_assert(strpos($source, 'phpbb_image_dimensions_safe(') !== false, $relative . ' must check decoded dimensions before GD allocation');
}

$thumbnail = file_get_contents($root . '/phpBB2/album_thumbnail.php');
image_processing_assert(strpos($thumbnail, '$pic_size === false') !== false, 'thumbnail regeneration must handle corrupt image metadata');

echo "Image processing safety checks passed.\n";

