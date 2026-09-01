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

require dirname(dirname(__DIR__)) . '/phpBB2/attach_mod/includes/functions_filetypes.php';
image_processing_assert(swf_bits('short', 64, 5) === false, 'truncated SWF bit fields must be rejected without out-of-range reads');
$oversized_swf = 'CWS' . chr(6) . pack('V', SWF_DIMENSION_MAX_BYTES + 1) . 'invalid';
image_processing_assert(swf_decompress($oversized_swf) === false, 'oversized compressed SWF metadata must be rejected before decompression');
$invalid_swf = tempnam(sys_get_temp_dir(), 'phpbb-swf-');
file_put_contents($invalid_swf, 'not a flash file');
image_processing_assert(swf_getdimension($invalid_swf) === array(0, 0), 'invalid SWF files must produce a safe empty dimension result');
image_processing_assert(image_getdimension($invalid_swf) === array(0, 0), 'invalid attachment images must produce a safe empty dimension result');
file_put_contents($invalid_swf, 'BM');
image_processing_assert(image_getdimension($invalid_swf) === array(0, 0), 'truncated attachment headers must not cause out-of-range reads');
@unlink($invalid_swf);

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

$album_admin = file_get_contents($root . '/phpBB2/admin/admin_album_config_extended.php');
image_processing_assert(strpos($album_admin, "usort(\$album_config_tabs[\$outer]['sub_config'], 'sort_cmp');") !== false, 'Album subtab sorting must pass a quoted callback on PHP 8');

echo "Image processing safety checks passed.\n";
