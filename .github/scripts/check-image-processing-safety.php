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

if (!function_exists('amod_realpath'))
{
	function amod_realpath($path)
	{
		return @realpath($path);
	}
}
require dirname(dirname(__DIR__)) . '/phpBB2/attach_mod/includes/functions_thumbs.php';
$attach_config = array(
	'img_min_thumb_filesize' => 0,
	'allow_ftp_upload' => 0,
	'img_imagick' => '',
	'use_gd2' => 1,
);
$corrupt_gif = tempnam(sys_get_temp_dir(), 'phpbb-gif-');
$corrupt_thumbnail = tempnam(sys_get_temp_dir(), 'phpbb-thumb-');
@unlink($corrupt_thumbnail);
file_put_contents($corrupt_gif, "GIF89a" . pack('vv', 10, 10) . "\x00\x00\x00");
image_processing_assert(create_thumbnail($corrupt_gif, $corrupt_thumbnail, 'image/gif') === false, 'a corrupt attachment image must fail without a GD type error');
@unlink($corrupt_gif);
@unlink($corrupt_thumbnail);

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
image_processing_assert(strpos($thumbnail, 'if (!$thumbnail)') !== false, 'thumbnail regeneration must handle failed GD allocations');
image_processing_assert(strpos($thumbnail, 'if (!@$resize_function(') !== false, 'thumbnail regeneration must handle failed GD copies');
image_processing_assert(strpos($thumbnail, '$thumbnail_written = false;') !== false, 'thumbnail regeneration must handle failed cache writes');
image_processing_assert(substr_count($thumbnail, '@imagedestroy($src)') >= 2, 'thumbnail regeneration must release source images on success and failure');

$attachment_thumbnail = file_get_contents($root . '/phpBB2/attach_mod/includes/functions_thumbs.php');
image_processing_assert(strpos($attachment_thumbnail, '$image_info === false') !== false, 'attachment thumbnails must handle corrupt image metadata');
image_processing_assert(strpos($attachment_thumbnail, 'if (!$image)') !== false, 'attachment thumbnails must handle failed image decoders');
image_processing_assert(strpos($attachment_thumbnail, 'if (!$copied)') !== false, 'attachment thumbnails must handle failed GD allocations and copies');
image_processing_assert(strpos($attachment_thumbnail, 'if (!$written)') !== false, 'attachment thumbnails must handle failed image writes');

$album_admin = file_get_contents($root . '/phpBB2/admin/admin_album_config_extended.php');
image_processing_assert(strpos($album_admin, "usort(\$album_config_tabs[\$outer]['sub_config'], 'sort_cmp');") !== false, 'Album subtab sorting must pass a quoted callback on PHP 8');

echo "Image processing safety checks passed.\n";
