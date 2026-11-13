<?php
// Included by the actual no-GD child of check-image-processing-safety.php.
// Converter processes and the bounded FTP sink touch only this owned directory.
$thumbnail_fixture_dir = $image_fixture_directory;
$thumb_source = $thumbnail_fixture_dir . '/converter-source.gif';
$thumb_target = $thumbnail_fixture_dir . '/converter-target.gif';
$thumb_mode = $thumbnail_fixture_dir . '/converter-mode';
$thumb_worker = $thumbnail_fixture_dir . '/converter.php';
$thumb_launcher = $thumbnail_fixture_dir . (DIRECTORY_SEPARATOR === '\\' ? '/converter.cmd' : '/converter.sh');
$thumb_small = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', true);
$thumb_large = substr_replace($thumb_small, pack('vv', 400, 400), 6, 4);
file_put_contents($thumb_source, $thumb_small);
file_put_contents($thumbnail_fixture_dir . '/unrelated.gif', 'preserve unrelated file');
$worker = '<?php $path=end($argv);$root=' . var_export($thumbnail_fixture_dir, true) . ';'
	. 'if(realpath(dirname($path))!==realpath($root)||strpos(basename($path),".phpbb-thumb-")!==0){exit(2);}'
	. '$mode=file_get_contents($root."/converter-mode");'
	. 'if($mode==="partial"){file_put_contents($path,"partial output");exit(1);}'
	. 'if($mode==="empty"){exit(0);}if($mode==="missing"){unlink($path);exit(0);}'
	. 'file_put_contents($path,base64_decode($mode==="small"?' . var_export(base64_encode($thumb_small), true) . ':' . var_export(base64_encode($thumb_large), true) . '));exit(0);';
file_put_contents($thumb_worker, $worker);
if (DIRECTORY_SEPARATOR === '\\') {
	file_put_contents($thumb_launcher, '@echo off' . "\r\n" . '"' . PHP_BINARY . '" -n "' . $thumb_worker . '" %*' . "\r\nexit /b %errorlevel%\r\n");
} else {
	file_put_contents($thumb_launcher, "#!/bin/sh\nexec " . escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg($thumb_worker) . ' "$@"' . "\n");
	chmod($thumb_launcher, 0700);
}
$attach_config['img_imagick'] = $thumb_launcher;
$thumbnail_file_cases = 0;
foreach (array('partial', 'empty', 'small', 'missing', 'valid') as $mode) {
	file_put_contents($thumb_mode, $mode);file_put_contents($thumb_target, $thumb_small);
	$result = create_thumbnail($thumb_source, $thumb_target, 'image/gif');
	image_processing_assert($result === ($mode === 'valid'), 'actual configured converter completion: ' . $mode);
	image_processing_assert(file_get_contents($thumb_target) === ($mode === 'valid' ? $thumb_large : $thumb_small), 'failed conversion preserves old bytes: ' . $mode);
	image_processing_assert(glob($thumbnail_fixture_dir . '/.phpbb-thumb-*') === array(), 'only owned stage cleaned: ' . $mode);
	$thumbnail_file_cases++;
}
unlink($thumb_target);file_put_contents($thumb_mode, 'valid');
image_processing_assert(create_thumbnail($thumb_source, $thumb_target, 'image/gif') && file_get_contents($thumb_target) === $thumb_large, 'publish a new complete target');$thumbnail_file_cases++;
image_processing_assert(!create_thumbnail($thumb_source, $thumb_source, 'image/gif') && file_get_contents($thumb_source) === $thumb_small, 'source and output cannot be the same file');$thumbnail_file_cases++;
unlink($thumb_target);mkdir($thumb_target);
image_processing_assert(!create_thumbnail($thumb_source, $thumb_target, 'image/gif') && is_dir($thumb_target), 'never replace a directory');rmdir($thumb_target);$thumbnail_file_cases++;
image_processing_assert(!create_thumbnail($thumb_source, $thumbnail_fixture_dir . '/absent/output.gif', 'image/gif'), 'no implicit destination directory or system temporary fallback');$thumbnail_file_cases++;

function ftp_file($source, $destination, $mimetype, $quiet = false)
{
	global $thumbnail_fixture_dir, $thumbnail_ftp_calls, $thumbnail_ftp_result;
	$thumbnail_ftp_calls++;
	$shape = getimagesize($source);
	image_processing_assert(realpath(dirname($source)) === realpath($thumbnail_fixture_dir) && strpos(basename($source), '.phpbb-thumb-') === 0 && $shape[0] === 400 && $shape[1] === 400 && $shape[2] === IMAGETYPE_GIF, 'FTP receives the verified owned stage');
	image_processing_assert($destination === 'thumbs/t_synthetic.gif' && $mimetype === 'image/gif' && $quiet === true, 'FTP destination and optional-error semantics retained');
	return $thumbnail_ftp_result;
}
$attach_config['allow_ftp_upload'] = 1;$thumbnail_ftp_calls = 0;$thumbnail_ftp_cases = 0;
foreach (array(true, false) as $thumbnail_ftp_result) {
	image_processing_assert(create_thumbnail($thumb_source, 'thumbs/t_synthetic.gif', 'image/gif') === $thumbnail_ftp_result, 'FTP outcome retained');
	image_processing_assert(glob($thumbnail_fixture_dir . '/.phpbb-thumb-*') === array(), 'FTP local stage always cleaned');$thumbnail_ftp_cases++;
}
file_put_contents($thumb_mode, 'partial');
image_processing_assert(!create_thumbnail($thumb_source, 'thumbs/t_synthetic.gif', 'image/gif') && $thumbnail_ftp_calls === 2, 'failed converter never reaches FTP');$thumbnail_ftp_cases++;
image_processing_assert(file_get_contents($thumbnail_fixture_dir . '/unrelated.gif') === 'preserve unrelated file' && file_get_contents($thumb_source) === $thumb_small && glob($thumbnail_fixture_dir . '/.phpbb-thumb-*') === array(), 'source, unrelated files and clean stages');
$attach_config['img_imagick'] = '';$attach_config['allow_ftp_upload'] = 0;
echo 'Attachment thumbnail publication: ' . $thumbnail_file_cases . ' file/converter cases, ' . $thumbnail_ftp_cases . " bounded FTP cases PASS.\n";
