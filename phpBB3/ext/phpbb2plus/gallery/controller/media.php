<?php
namespace phpbb2plus\gallery\controller;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class media
{
	private $access;
	private $config;
	private $root;
	private $ini;

	public function __construct($access, $config, $root, $ini)
	{
		$this->access = $access;
		$this->config = $config;
		$this->root = $root;
		$this->ini = $ini;
	}

	private function response($path, $mime)
	{
		$response = new BinaryFileResponse($path, 200, array('Content-Type' => $mime, 'X-Content-Type-Options' => 'nosniff'), false);
		$response->headers->set('Cache-Control', 'private, no-store');
		return $response;
	}

	private function image_info($path, $mime)
	{
		$size = is_file($path) && !is_link($path) ? @getimagesize($path) : false;
		if (!$size || $size['mime'] !== $mime || $size[0] < 1 || $size[1] < 1 ||
			$size[0] > 10000 || $size[1] > 10000 || $size[0] * $size[1] > 20000000)
		{
			throw new ServiceUnavailableHttpException();
		}
		return $size;
	}

	private function rotate_jpeg($image, $path)
	{
		if (!function_exists('exif_read_data')) { return $image; }
		$exif = @exif_read_data($path, 'IFD0', true);
		$o = (int) ($exif['IFD0']['Orientation'] ?? 1);
		if (in_array($o, array(2,5,7), true)) { imageflip($image, IMG_FLIP_HORIZONTAL); }
		if ($o === 4) { imageflip($image, IMG_FLIP_VERTICAL); }
		$angles = array(3 => 180, 5 => 90, 6 => -90, 7 => -90, 8 => 90);
		if (!isset($angles[$o])) { return $image; }
		$rotated = imagerotate($image, $angles[$o], 0);
		if (!$rotated) { throw new ServiceUnavailableHttpException(); }
		return $rotated;
	}

	private function apply_mark($image, $path, $position, $strength)
	{
		$mark = @imagecreatefrompng($path);
		if (!$mark) { throw new ServiceUnavailableHttpException(); }
		$dx = imagesx($image) - imagesx($mark);
		$dy = imagesy($image) - imagesy($mark);
		$x = in_array($position, array(2,3,6), true) ? $dx : (in_array($position, array(0,5,7), true) ? (int) ($dx/2) : 0);
		$y = in_array($position, array(3,4,7), true) ? $dy : (in_array($position, array(0,6,8), true) ? (int) ($dy/2) : 0);
		if (!imagecopymerge($image, $mark, $x, $y, 0, 0, imagesx($mark), imagesy($mark), $strength)) { throw new ServiceUnavailableHttpException(); }
	}

	public function show($picture, $size)
	{
		list($pic) = $this->access->picture($picture);
		if (!in_array($size, array('small','medium','full'), true) || !preg_match('/^gallery_[a-f0-9]{64}$/D', $pic['storage_name']))
		{
			throw new NotFoundHttpException();
		}
		$directory = realpath($this->root . $this->config['upload_path']);
		$source = $directory . DIRECTORY_SEPARATOR . $pic['storage_name'];
		if (!$directory || !is_file($source) || is_link($source)) { throw new NotFoundHttpException(); }
		$mime = $pic['media_type'];
		$decoders = array('image/jpeg' => 'imagecreatefromjpeg', 'image/png' => 'imagecreatefrompng', 'image/gif' => 'imagecreatefromgif');
		if (!isset($decoders[$mime])) { throw new NotFoundHttpException(); }
		$strength = $mime !== 'image/gif' ? $this->access->watermark_strength() : 0;
		if ($size === 'medium' && !(int) $this->access->setting('midthumb_use', 1, 'sp')) { $size = 'full'; }
		if ($size === 'full' && !$strength) { return $this->response($source, $mime); }
		$dimensions = $this->image_info($source, $mime);
		$width = $dimensions[0];
		$height = $dimensions[1];
		if ($size === 'small') { $width = $height = max(1, min(2048, (int) $this->access->setting('thumbnail_size', 125))); }
		if ($size === 'medium')
		{
			$width = max(1, min(10000, (int) $this->access->setting('midthumb_width', 800, 'sp')));
			$height = max(1, min(10000, (int) $this->access->setting('midthumb_height', 600, 'sp')));
		}
		$quality = max(0, min(100, (int) $this->access->setting('thumbnail_quality', 75)));
		$position = max(0, min(8, (int) $this->access->setting('disp_watermark_at', 3, 'sp')));
		$mark = $directory . '/gallery_mark_' . hash_hmac('sha256', 'watermark', $this->config['plus_gallery_media_key']);
		$mark_size = $strength ? $this->image_info($mark, 'image/png') : array(0,0);
		$mark_hash = $strength ? hash_file('sha256', $mark) : '';
		if ($strength && !$mark_hash) { throw new ServiceUnavailableHttpException(); }
		$key = implode(':', array('v1', $pic['storage_name'], $width, $height, $size, $quality, $strength, $position, $mark_hash));
		$cached = $directory . '/gallery_cache_' . hash_hmac('sha256', $key, $this->config['plus_gallery_media_key']);
		$use_cache = $size === 'small' ? (int) $this->access->setting('thumbnail_cache', 1) : (int) $this->access->setting('midthumb_cache', 1, 'sp');
		if ($use_cache && is_file($cached) && !is_link($cached)) { return $this->response($cached, $mime); }
		$budget = $this->ini->getBytes('memory_limit');
		$required = 12.0 * ($dimensions[0] * $dimensions[1] + $mark_size[0] * $mark_size[1]) + 4194304;
		if (!function_exists($decoders[$mime]) || (ini_get('memory_limit') !== '-1' && (!$budget || $required > $budget - memory_get_usage(true))))
		{
			throw new ServiceUnavailableHttpException();
		}
		$image = @$decoders[$mime]($source);
		if (!$image) { throw new ServiceUnavailableHttpException(); }
		if ($mime === 'image/jpeg') { $image = $this->rotate_jpeg($image, $source); }
		if ($strength) { $this->apply_mark($image, $mark, $position, $strength); }
		$ratio = $size === 'full' ? 1 : min(1, $width/imagesx($image), $height/imagesy($image));
		if ($ratio < 1)
		{
			$w = max(1, (int) round(imagesx($image) * $ratio));
			$h = max(1, (int) round(imagesy($image) * $ratio));
			$scaled = imagecreatetruecolor($w, $h);
			if (!$scaled) { throw new ServiceUnavailableHttpException(); }
			imagealphablending($scaled, false);
			imagesavealpha($scaled, true);
			if (!imagecopyresampled($scaled, $image, 0, 0, 0, 0, $w, $h, imagesx($image), imagesy($image))) { throw new ServiceUnavailableHttpException(); }
			$image = $scaled;
		}
		$temp = tempnam($directory, 'album-');
		try
		{
			if (!$temp || dirname($temp) !== $directory) { throw new ServiceUnavailableHttpException(); }
			if ($mime === 'image/jpeg') { $written = imagejpeg($image, $temp, $quality); }
			elseif ($mime === 'image/png') { $written = imagepng($image, $temp); }
			else { $written = imagegif($image, $temp); }
			if (!$written || !filesize($temp)) { throw new ServiceUnavailableHttpException(); }
			if ($use_cache)
			{
				if (!rename($temp, $cached) && (!is_file($cached) || is_link($cached))) { throw new ServiceUnavailableHttpException(); }
				return $this->response($cached, $mime);
			}
			$response = $this->response($temp, $mime);
			$response->deleteFileAfterSend(true);
			$temp = null;
			return $response;
		}
		finally
		{
			if ($temp && is_file($temp)) { unlink($temp); }
		}
	}
}
