<?php
/** 
*
* @package attachment_mod
* @version $Id: functions_thumbs.php,v 1.3 2005/11/15 21:26:47 acydburn Exp $
* @copyright (c) 2002 Meik Sievertsen
* @license http://opensource.org/licenses/gpl-license.php GNU Public License 
*
*/

/**
* All Attachment Functions needed to create Thumbnails
*/

/**
*/
if ( !defined('IN_PHPBB') )
{
	die('Hacking attempt');
	exit;
}

$imagick = '';

/**
* Calculate the needed size for Thumbnail
*/
function get_img_size_format($width, $height)
{
	// Maximum Width the Image can take
	$max_width = 400;

	if ($width > $height)
	{
		return array(
			round($width * ($max_width / $width)),
			round($height * ($max_width / $width))
		);
	} 
	else 
	{
		return array(
			round($width * ($max_width / $height)),
			round($height * ($max_width / $height))
		);
	}
}

/**
* Check if imagick is present
*/
function is_imagick() 
{
	global $imagick, $attach_config;

	if ($attach_config['img_imagick'] != '')
	{
		$imagick = $attach_config['img_imagick'];
		return true;
	}
	else
	{
		return false;
	}
}

/**
* Get supported image types
*/
function get_supported_image_types($type)
{
	if (@extension_loaded('gd'))
	{
		$format = imagetypes();
		$new_type = 0;

		switch ($type)
		{
			case 1:
				$new_type = ($format & IMG_GIF) ? IMG_GIF : 0;
				break;
			case 2:
			case 9:
			case 10:
			case 11:
			case 12:
				$new_type = ($format & IMG_JPG) ? IMG_JPG : 0;
				break;
			case 3:
				$new_type = ($format & IMG_PNG) ? IMG_PNG : 0;
				break;
			case 6:
			case 15:
				$new_type = ($format & IMG_WBMP) ? IMG_WBMP : 0;
				break;
		}
		
		return array(
			'gd'		=> ($new_type) ? true : false,
			'format'	=> $new_type,
			'version'	=> (function_exists('imagecreatetruecolor')) ? 2 : 1
		);
	}

	return array('gd' => false);
}

/**
* Create thumbnail
*/
function create_thumbnail($source, $new_file, $mimetype)
{
	global $attach_config;
	$temporary = null;
	try
	{
		if (!is_string($source) || !is_string($new_file) || $new_file === '' || strpos($source, "\0") !== false || strpos($new_file, "\0") !== false || strpos($new_file, '://') !== false) { return false; }
		$source = amod_realpath($source);
		if (!is_string($source) || !is_file($source)) { return false; }
		$info = @getimagesize($source);
		if (!$info || !phpbb_image_dimensions_safe($info[0], $info[1])) { return false; }
		$extension = image_type_to_extension($info[2], false);
		if (!is_string($extension) || !preg_match('/^[a-z0-9]{1,8}$/iD', $extension)) { return false; }
		$ftp = (int) $attach_config['allow_ftp_upload'] !== 0;
		$directory = @realpath(dirname($ftp ? $source : $new_file));
		if ($directory === false || !is_dir($directory)) { return false; }
		$target = $directory . '/' . basename($new_file);
		if (!$ftp && (is_link($target) || (file_exists($target) && !is_file($target)) || @realpath($target) === $source)) { return false; }
		// Keep the format suffix for ImageMagick, but reserve an unpredictable
		// same-directory file exclusively. Never delete/truncate the old target.
		for ($attempt = 0; $attempt < 8; $attempt++)
		{
			$candidate = $directory . '/.phpbb-thumb-' . bin2hex(phpbb_random_bytes(12)) . '.' . $extension;
			$handle = @fopen($candidate, 'x+b');
			if ($handle !== false) { fclose($handle); $temporary = $candidate; break; }
		}
		if ($temporary === null || !@chmod($temporary, 0600)) { return false; }
		if (!phpbb_attachment_thumbnail_encode($source, $temporary, $mimetype) || is_link($temporary) || !is_file($temporary)) { return false; }
		$shape = @getimagesize($temporary);
		list($width, $height) = get_img_size_format($info[0], $info[1]);
		$types = in_array($info[2], array(9, 10, 11, 12), true) ? array($info[2], 2) : array($info[2]);
		if (!$shape || !in_array($shape[2], $types, true) || !phpbb_image_dimensions_safe($shape[0], $shape[1]) || abs($shape[0] - $width) > 1 || abs($shape[1] - $height) > 1) { return false; }
		if ($ftp) { return (bool) ftp_file($temporary, $new_file, $mimetype, true); }
		if (is_link($target) || (file_exists($target) && !is_file($target)) || !@chmod($temporary, 0664)) { return false; }
		if (!@rename($temporary, $target)) { return false; }
		$temporary = null;
		return true;
	}
	catch (Exception $exception) { return false; }
	catch (Error $exception) { return false; }
	finally { if ($temporary !== null && is_file($temporary) && !is_link($temporary)) { @unlink($temporary); } }
}

// Internal encoder: its destination is always a freshly owned scratch file.
function phpbb_attachment_thumbnail_encode($source, $new_file, $mimetype)
{
	global $attach_config, $imagick;

	$source = amod_realpath($source);
	$min_filesize = (int) $attach_config['img_min_thumb_filesize'];
	$img_filesize = (@file_exists($source)) ? @filesize($source) : false;

	if (!$img_filesize || $img_filesize <= $min_filesize)
	{
		return false;
	}

	$image_info = @getimagesize($source);
	if ($image_info === false || !isset($image_info[0], $image_info[1], $image_info[2]))
	{
		return false;
	}
	$width = (int) $image_info[0];
	$height = (int) $image_info[1];
	$type = (int) $image_info[2];

	if (!$width || !$height || !phpbb_image_dimensions_safe($width, $height))
	{
		return false;
	}

	list($new_width, $new_height) = get_img_size_format($width, $height);

	$used_imagick = false;
	$thumbnail_written = false;

	if (function_exists('exec') && is_imagick())
	{
		$command = escapeshellarg($imagick)
			. ' -quality 85 -antialias -sample ' . intval($new_width) . 'x' . intval($new_height)
			. ' ' . escapeshellarg(str_replace('\\', '/', $source))
			. ' +profile "*" ' . escapeshellarg(str_replace('\\', '/', $new_file));
		$output = array();
		$status = 1;
		@exec($command, $output, $status);
		if ($status === 0 && @file_exists($new_file))
		{
			$used_imagick = true;
			$thumbnail_written = true;
		}
	} 

	if (!$used_imagick) 
	{
		$type = get_supported_image_types($type);
		
		if ($type['gd'])
		{
			$image = false;
			switch ($type['format']) 
			{
				case IMG_GIF:
					$image = @imagecreatefromgif($source);
					break;
				case IMG_JPG:
					$image = @imagecreatefromjpeg($source);
					break;
				case IMG_PNG:
					$image = @imagecreatefrompng($source);
					break;
				case IMG_WBMP:
					$image = @imagecreatefromwbmp($source);
					break;
			}
			if (!$image)
			{
				return false;
			}

			if ($type['version'] == 1 || !$attach_config['use_gd2'])
			{
				$new_image = @imagecreate($new_width, $new_height);
				$copied = $new_image ? @imagecopyresized($new_image, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height) : false;
			}
			else
			{
				$new_image = @imagecreatetruecolor($new_width, $new_height);
				$copied = $new_image ? @imagecopyresampled($new_image, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height) : false;
			}
			if (!$copied)
			{
				if ($new_image)
				{
					@phpbb_gd_release($new_image);
				}
				@phpbb_gd_release($image);
				return false;
			}
			
			$written = false;
			switch ($type['format'])
			{
				case IMG_GIF:
					$written = @imagegif($new_image, $new_file);
					break;
				case IMG_JPG:
					$written = @imagejpeg($new_image, $new_file, 90);
					break;
				case IMG_PNG:
					$written = @imagepng($new_image, $new_file);
					break;
				case IMG_WBMP:
					$written = @imagewbmp($new_image, $new_file);
					break;
			}

			@phpbb_gd_release($new_image);
			@phpbb_gd_release($image);
			if (!$written)
			{
				@unlink($new_file);
				return false;
			}
			$thumbnail_written = true;
		}
	}

	// An existing target alone cannot acknowledge this attempt. In particular,
	// no available encoder must not promote stale/invalid files as thumbnails.
	if (!$thumbnail_written || !@file_exists($new_file))
	{
		return false;
	}

	return true;
}

?>
