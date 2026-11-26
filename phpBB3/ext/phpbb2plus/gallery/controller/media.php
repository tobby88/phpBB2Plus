<?php
namespace phpbb2plus\gallery\controller;

use phpbb2plus\gallery\service\raster;
use phpbb2plus\gallery\service\pictures;
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
		$size = raster::dimensions($path, $mime);
		if (!$size)
		{
			throw new ServiceUnavailableHttpException();
		}
		return $size;
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
		$directory = realpath($this->root . $this->config['upload_path']);
		$source = pictures::original_path($directory, $pic['storage_name']);
		if (!$source || !in_array($size, array('small','medium','full'), true)) { throw new NotFoundHttpException(); }
		$mime = $pic['media_type'];
		$decoders = array('image/jpeg' => 'imagecreatefromjpeg', 'image/png' => 'imagecreatefrompng', 'image/gif' => 'imagecreatefromgif');
		if (!isset($decoders[$mime])) { throw new NotFoundHttpException(); }
		$strength = $mime !== 'image/gif' ? $this->access->watermark_strength() : 0;
		if ($size === 'medium' && !(int) $this->access->setting('midthumb_use', 1, 'sp')) { $size = 'full'; }
		if ($size === 'full' && !$strength) { return $this->response($source, $mime); }
		$preview = '';
		if ($size === 'small' && preg_match('/^[a-f0-9]{64}$/D', $pic['preview_hash']))
		{
			$path = $directory . '/gallery_small_' . substr($pic['storage_name'], 8);
			if (!is_link($path) && is_file($path) && hash_file('sha256', $path) === $pic['preview_hash'])
			{
				$preview = $pic['preview_hash'];
				$source = $path;
			}
		}
		$dimensions = $this->image_info($source, $mime);
		$width = $dimensions[0];
		$height = $dimensions[1];
		if ($size === 'small')
		{
			$width = $height = max(1, min(10000, (int) $this->access->setting('thumbnail_size', 125)));
			if ($preview !== '' && !$strength && max($dimensions[0], $dimensions[1]) <= $width) { return $this->response($source, $mime); }
		}
		if ($size === 'medium')
		{
			$width = max(1, min(10000, (int) $this->access->setting('midthumb_width', 800, 'sp')));
			$height = max(1, min(10000, (int) $this->access->setting('midthumb_height', 600, 'sp')));
		}
		$quality = max(0, min(100, (int) $this->access->setting('thumbnail_quality', 75)));
		$position = max(0, min(8, (int) $this->access->setting('disp_watermark_at', 3, 'sp')));
		$mark = raster::watermark_path($directory, $this->config['plus_gallery_media_key']);
		$mark_size = $strength ? $this->image_info($mark, 'image/png') : array(0,0);
		$mark_hash = $strength ? hash_file('sha256', $mark) : '';
		if ($strength && !$mark_hash) { throw new ServiceUnavailableHttpException(); }
		$key = implode(':', array('v2', $pic['storage_name'], $preview, $width, $height, $size, $quality, $strength, $position, $mark_hash));
		$variant = hash_hmac('sha256', $key, $this->config['plus_gallery_media_key']);
		$cached = $directory . '/' . \phpbb2plus\gallery\service\preview_cache::filename($pic['storage_name'], $variant, $this->config['plus_gallery_cache_generation'] ?? 0);
		$use_cache = $size === 'small' ? (int) $this->access->setting('thumbnail_cache', 1) : (int) $this->access->setting('midthumb_cache', 1, 'sp');
		if ($use_cache && is_file($cached) && !is_link($cached)) { return $this->response($cached, $mime); }
		$budget = $this->ini->getBytes('memory_limit');
		$required = 12.0 * ($dimensions[0] * $dimensions[1] + $mark_size[0] * $mark_size[1]) + 4194304;
		if (!function_exists($decoders[$mime]) || (ini_get('memory_limit') !== '-1' && (!$budget || $required > $budget - memory_get_usage(true))))
		{
			throw new ServiceUnavailableHttpException();
		}
		$image = @$decoders[$mime]($source);
		if ($image && $mime === 'image/jpeg') { $image = raster::orient($image, raster::orientation($source)); }
		if (!$image) { throw new ServiceUnavailableHttpException(); }
		if ($strength) { $this->apply_mark($image, $mark, $position, $strength); }
		if ($size !== 'full') { $image = raster::fit($image, $width, $height, $mime); }
		if (!$image) { throw new ServiceUnavailableHttpException(); }
		$temp = tempnam($directory, 'album-');
		try
		{
			if (!$temp || dirname($temp) !== $directory) { throw new ServiceUnavailableHttpException(); }
			$written = raster::encode($image, $temp, $mime, $quality);
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
