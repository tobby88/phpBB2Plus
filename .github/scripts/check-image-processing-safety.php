<?php

define('IN_PHPBB', true);
require dirname(dirname(__DIR__)) . '/phpBB2/includes/php_compat.php';
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
$image_fixture_directory = false;
for ($attempt=0; $attempt<100; $attempt++) {
	$candidate = sys_get_temp_dir() . '/phpbb-image-safety-' . getmypid() . '-' . $attempt;
	if (@mkdir($candidate, 0700)) { $image_fixture_directory=$candidate; break; }
}
image_processing_assert(is_string($image_fixture_directory), 'the image fixture owns a fresh private directory');
register_shutdown_function(function() use ($image_fixture_directory) {
	foreach (glob($image_fixture_directory . '/*') as $file) { if (is_file($file)) { @unlink($file); } }
	@rmdir($image_fixture_directory);
});
image_processing_assert(swf_bits('short', 64, 5) === false, 'truncated SWF bit fields must be rejected without out-of-range reads');
$oversized_swf = 'CWS' . chr(6) . pack('V', SWF_DIMENSION_MAX_BYTES + 1) . 'invalid';
image_processing_assert(swf_decompress($oversized_swf) === false, 'oversized compressed SWF metadata must be rejected before decompression');
$invalid_swf = tempnam($image_fixture_directory, 'phpbb-swf-');
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
$thumbnail_geometry = array(
	array(10000,1,400,1), array(1,10000,1,400),
	array(10000,10,400,1), array(10,10000,1,400),
	array(10000,12,400,1), array(12,10000,1,400),
	array(10000,13,400,1), array(13,10000,1,400),
	array(10000,37,400,1), array(37,10000,1,400),
	array(10000,38,400,2), array(38,10000,2,400),
	array(480,320,400,267), array(320,480,267,400),
	array(1,1,400,400), array(13,7,400,215), array(7,13,215,400), array(400,400,400,400),
	array(0,10,0,0), array(10,0,0,0), array(-1,10,0,0), array(10,-1,0,0),
);
foreach ($thumbnail_geometry as $geometry) {
	image_processing_assert(get_img_size_format($geometry[0], $geometry[1]) === array($geometry[2], $geometry[3]), 'canonical positive integer geometry and ordinary scaling');
}
$attach_config = array(
	'img_min_thumb_filesize' => 0,
	'allow_ftp_upload' => 0,
	'img_imagick' => '',
	'use_gd2' => 1,
);
$corrupt_gif = tempnam($image_fixture_directory, 'phpbb-gif-');
$corrupt_thumbnail = tempnam($image_fixture_directory, 'phpbb-thumb-');
@unlink($corrupt_thumbnail);
file_put_contents($corrupt_gif, "GIF89a" . pack('vv', 10, 10) . "\x00\x00\x00");
image_processing_assert(create_thumbnail($corrupt_gif, $corrupt_thumbnail, 'image/gif') === false, 'a corrupt attachment image must fail without a GD type error');
file_put_contents($corrupt_thumbnail, 'previous complete thumbnail');
image_processing_assert(create_thumbnail($corrupt_gif, $corrupt_thumbnail, 'image/gif') === false && file_get_contents($corrupt_thumbnail) === 'previous complete thumbnail', 'a failed actual decoder preserves a previous target');
@unlink($corrupt_gif);
@unlink($corrupt_thumbnail);

if (!extension_loaded('gd'))
{
	$source_file = tempnam($image_fixture_directory, 'phpbb-no-gd-source-');
	$target_file = tempnam($image_fixture_directory, 'phpbb-no-gd-target-');
	$valid_gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', true);
	file_put_contents($source_file, $valid_gif);
	image_processing_assert(getimagesize($source_file) !== false, 'synthetic GIF has actual image metadata');
	try
	{
		foreach (array('not a thumbnail', $valid_gif) as $original)
		{
			file_put_contents($target_file, $original);
			image_processing_assert(create_thumbnail($source_file, $target_file, 'image/gif') === false, 'without an encoder, existing targets never acknowledge a new thumbnail');
			image_processing_assert(file_get_contents($target_file) === $original, 'without an encoder, preserve every byte of the existing target');
		}
		unlink($target_file);
		image_processing_assert(create_thumbnail($source_file, $target_file, 'image/gif') === false && !file_exists($target_file), 'without an encoder, absent targets remain absent');
	}
	finally { unlink($source_file); if (is_file($target_file)) { unlink($target_file); } }

	echo "Attachment thumbnail no-encoder: 3 real file cases PASS; absent, invalid and valid targets cannot acknowledge a write, existing bytes preserved.\n";
	require __DIR__ . '/thumbnail-publication-fixture.php';
}

if (extension_loaded('gd'))
{
	$source_file = tempnam($image_fixture_directory, 'phpbb-real-image-');
	$target_file = tempnam($image_fixture_directory, 'phpbb-real-thumb-');
	$image = imagecreatetruecolor(480, 320);
	image_processing_assert(imagepng($image, $source_file), 'write own synthetic source');
	phpbb_gd_release($image);
	try
	{
		foreach (array(0, 1) as $gd2)
		{
			$attach_config['use_gd2'] = $gd2;
			image_processing_assert(create_thumbnail($source_file, $target_file, 'image/png'), 'actual GD thumbnail creation and cleanup');
			$shape = getimagesize($target_file);
			image_processing_assert($shape[0] === 400 && $shape[1] === 267 && $shape[2] === IMAGETYPE_PNG, 'actual bounded thumbnail pixels');
		}
	}
	finally { unlink($source_file); unlink($target_file); }
	$geometry_resizes = 0;
	foreach ($thumbnail_geometry as $geometry) {
		if ($geometry[0] < 1 || $geometry[1] < 1) { continue; }
		$source_file = tempnam($image_fixture_directory, 'phpbb-geometry-source-');
		$target_file = tempnam($image_fixture_directory, 'phpbb-geometry-target-');
		try {
			$image = imagecreatetruecolor($geometry[0], $geometry[1]);
			image_processing_assert($image && imagepng($image, $source_file), 'actual supported geometry source');phpbb_gd_release($image);
			foreach (array(0,1) as $gd2) {
				$attach_config['use_gd2'] = $gd2;
				image_processing_assert(create_thumbnail($source_file, $target_file, 'image/png'), 'actual thin/ordinary GD thumbnail publication');
				$shape = getimagesize($target_file);
				image_processing_assert($shape[0] === $geometry[2] && $shape[1] === $geometry[3] && $shape[2] === IMAGETYPE_PNG, 'actual expected nonzero pixel dimensions');$geometry_resizes++;
			}
		} finally { unlink($source_file);unlink($target_file); }
	}

	// Exercise the actual no-extension branch even on the ordinary GD CI host.
	$child_lines = array(); $child_status = 1;
	$crypto = DIRECTORY_SEPARATOR === '\\' && PHP_VERSION_ID < 70000 ? ' -d extension=' . escapeshellarg(ini_get('extension_dir') . '/php_openssl.dll') : '';
	exec(escapeshellarg(PHP_BINARY) . ' -n -d date.timezone=UTC -d disable_functions=mail ' . $crypto . ' ' . escapeshellarg(__FILE__) . ' 2>&1', $child_lines, $child_status);
	$child_output = implode("\n", $child_lines);
	image_processing_assert($child_status === 0 && substr_count($child_output, 'Attachment thumbnail no-encoder: 3 real file cases PASS;') === 1, 'actual no-extension PHP child preserves existing targets: ' . $child_output);
	image_processing_assert(substr_count($child_output, 'Attachment thumbnail publication: 9 file/converter cases, 3 bounded FTP cases PASS.') === 1, 'actual converter processes and bounded FTP outcomes: ' . $child_output);
	image_processing_assert(substr_count($child_output, 'Attachment thumbnail geometry converter: 2 cases PASS.') === 1, 'actual thin converter requests: ' . $child_output);
	echo "Attachment thumbnail no-encoder subprocess PASS.\n";
	echo "Attachment thumbnail publication subprocess: 9 file/converter cases, 3 bounded FTP cases PASS.\n";
	echo 'Attachment thumbnail thin geometry: ' . count($thumbnail_geometry) . ' dimension cases, ' . $geometry_resizes . " GD resizes, 2 converter cases PASS.\n";
}

$root = dirname(dirname(__DIR__));
$sources = array(
	'phpBB2/album_mod/album_upload_stage.php',
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

$upload = file_get_contents($root . '/phpBB2/album_upload.php');
image_processing_assert(strpos($upload, 'new AlbumUploadStage(') !== false && strpos($upload, '$upload_writer->publish(') !== false, 'actual upload must delegate bounded image decoding and publication to the owned stage/writer');

$thumbnail = file_get_contents($root . '/phpBB2/album_thumbnail.php');
image_processing_assert(strpos($thumbnail, '$pic_size === false') !== false, 'thumbnail regeneration must handle corrupt image metadata');
image_processing_assert(strpos($thumbnail, 'if (!$thumbnail)') !== false, 'thumbnail regeneration must handle failed GD allocations');
image_processing_assert(strpos($thumbnail, 'if (!@$resize_function(') !== false, 'thumbnail regeneration must handle failed GD copies');
image_processing_assert(strpos($thumbnail, '$thumbnail_written = false;') !== false, 'thumbnail regeneration must handle failed cache writes');
image_processing_assert(substr_count($thumbnail, '@phpbb_gd_release($src)') >= 2, 'thumbnail regeneration must release old GD resources without deprecated object cleanup on success and failure');

$medium_thumbnail = file_get_contents($root . '/phpBB2/album_picm.php');
image_processing_assert(strpos($medium_thumbnail, '$thumbnail_written = false;') !== false, 'medium thumbnail regeneration must handle failed cache writes');
$cache_functions = file_get_contents($root . '/phpBB2/album_mod/album_functions.php');
$cache_publication = file_get_contents($root . '/phpBB2/album_mod/album_cache_publication.php');
image_processing_assert(strpos($thumbnail, 'album_publish_picture_cache(') !== false && strpos($medium_thumbnail, 'album_publish_picture_cache(') !== false && strpos($cache_publication, 'album_store_cached_image(') !== false, 'both renderers must reach the atomic cache writer through the current picture lease');
image_processing_assert(strpos($thumbnail, '@unlink(ALBUM_CACHE_PATH . $pic_thumbnail);') === false && strpos($medium_thumbnail, '@unlink(ALBUM_MED_CACHE_PATH . $pic_thumbnail);') === false, 'failed regeneration must never delete a complete prior or peer target');
image_processing_assert(strpos($cache_functions, "@fopen(\$path, 'x+b')") !== false && strpos($cache_functions, '@rename($temporary, $target)') !== false, 'cache writes must exclusively reserve a same-directory temporary file and publish atomically');
image_processing_assert(strpos($cache_functions, '@unlink($temporary)') !== false && strpos($cache_functions, '@unlink($target)') === false, 'cache failures may clean only their owned temporary files');
$small_cache_guard = file_get_contents($root . '/phpBB2/album_mod/upload/cache/.htaccess');
$medium_cache_guard = $root . '/phpBB2/album_mod/upload/med_cache/.htaccess';
image_processing_assert(is_file($medium_cache_guard) && file_get_contents($medium_cache_guard) === $small_cache_guard && is_file($root . '/phpBB2/album_mod/upload/med_cache/index.html'), 'installation archives must contain the protected medium-cache directory');
$writable_paths = file_get_contents($root . '/folder+file-permissions.txt'); $permission_script = file_get_contents($root . '/set-permissions.sh');
image_processing_assert(strpos($writable_paths, "\n/album_mod/upload/med_cache\n") !== false && strpos($permission_script, 'album_mod/upload/med_cache ' . chr(92) . "\n") !== false, 'the medium cache must share the documented executable writable-directory setup');
$ignore = file_get_contents($root . '/.gitignore');
foreach (array('cache', 'med_cache') as $cache_directory)
{
	image_processing_assert(strpos($ignore, '/phpBB2/album_mod/upload/' . $cache_directory . '/*') !== false && strpos($ignore, '!/phpBB2/album_mod/upload/' . $cache_directory . '/.htaccess') !== false && strpos($ignore, '!/phpBB2/album_mod/upload/' . $cache_directory . '/index.html') !== false, 'generated cache images stay ignored while generic protection files remain trackable');
}

$advanced_captcha = file_get_contents($root . '/phpBB2/includes/usercp_confirm_adv.php');
image_processing_assert(strpos($advanced_captcha, 'max(120, min(1000, intval(') !== false, 'advanced CAPTCHA widths must be bounded');
image_processing_assert(strpos($advanced_captcha, 'max(40, min(400, intval(') !== false, 'advanced CAPTCHA heights must be bounded');
image_processing_assert(strpos($advanced_captcha, "include(\$phpbb_root_path . 'includes/usercp_confirm.' . \$phpEx);") !== false, 'advanced CAPTCHA must retain a GD-free fallback');
image_processing_assert(strpos($advanced_captcha, 'if (!$image)') !== false, 'advanced CAPTCHA must handle failed GD allocation');
image_processing_assert(strpos($advanced_captcha, 'captcha/pics') === false, 'unused CAPTCHA background-image discovery must not return');
image_processing_assert(strpos($advanced_captcha, 'mt_srand(') === false && strpos($advanced_captcha, 'srand(') === false, 'CAPTCHA rendering must rely on the runtime RNG instead of repeated manual seeding');
image_processing_assert(strpos($advanced_captcha, "@imagettfbbox(12, 0, \$font_path, 'A')") !== false, 'CAPTCHA font discovery must reject unreadable TTF data');
image_processing_assert(strpos($advanced_captcha, 'if (!is_array($char_pos) || count($char_pos) < 8)') !== false, 'CAPTCHA rendering must fall back when a selected TTF cannot render a character');

$legacy_captcha = file_get_contents($root . '/phpBB2/antirobot_pic.php');
image_processing_assert(strpos($legacy_captcha, "!is_string(\$_GET['id'])") !== false, 'legacy CAPTCHA identifiers must be strings');
$robot_storage=file_get_contents($root . '/phpBB2/includes/functions_antirobot.php');
image_processing_assert(strpos($legacy_captcha, 'phpbb_antirobot_code($db,$userdata,') !== false && strpos($robot_storage,"preg_match('/^[a-z]{5}\$/D'") !== false, 'legacy CAPTCHA keys must come from the current session-bound formatted challenge');
image_processing_assert(strpos($legacy_captcha, 'readfile($image_file);') !== false, 'legacy CAPTCHA images must use the resolved confined path');

$captcha_admin = file_get_contents($root . '/phpBB2/admin/admin_captcha_config.php');
$captcha_admin_template = file_get_contents($root . '/phpBB2/templates/fisubsilversh/admin/admin_captcha_config.tpl');
image_processing_assert(strpos($captcha_admin, 'S_ALLOW_IMAGE_') === false && strpos($captcha_admin_template, 'S_ALLOW_IMAGE_') === false, 'the never-implemented CAPTCHA background-image setting must not be exposed');

$attachment_thumbnail = file_get_contents($root . '/phpBB2/attach_mod/includes/functions_thumbs.php');
image_processing_assert(strpos($attachment_thumbnail, '$image_info === false') !== false, 'attachment thumbnails must handle corrupt image metadata');
image_processing_assert(strpos($attachment_thumbnail, 'if (!$image)') !== false, 'attachment thumbnails must handle failed image decoders');
image_processing_assert(strpos($attachment_thumbnail, 'if (!$copied)') !== false, 'attachment thumbnails must handle failed GD allocations and copies');
image_processing_assert(strpos($attachment_thumbnail, 'if (!$written)') !== false, 'attachment thumbnails must handle failed image writes');

$nuffload = file_get_contents($root . '/phpBB2/album_nuffload.php');
image_processing_assert(strpos($nuffload, "tempnam(dirname(\$image_file_name), 'phpbb-resize-')") !== false, 'Nuffload resizing must render beside the original upload');
image_processing_assert(strpos($nuffload, '$replaced = @rename($temporary_file, $image_file_name);') !== false, 'Nuffload resizing must replace the original only after a successful render');
image_processing_assert(strpos($nuffload, '@unlink($image_file_name);') === false, 'Nuffload resizing must not delete the original upload before replacement');
image_processing_assert(strpos($nuffload, "\$lang['Upload_resize_failed']") !== false, 'Nuffload must stop cleanly when safe resizing fails');

$album_admin = file_get_contents($root . '/phpBB2/admin/admin_album_config_extended.php');
image_processing_assert(strpos($album_admin, "usort(\$album_config_tabs[\$outer]['sub_config'], 'sort_cmp');") !== false, 'Album subtab sorting must pass a quoted callback on PHP 8');

echo "Image processing safety checks passed.\n";
