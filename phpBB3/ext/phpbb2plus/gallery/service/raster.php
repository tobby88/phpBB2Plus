<?php
namespace phpbb2plus\gallery\service;

class raster
{
	public static function watermark_path($directory, $key)
	{
		return $directory && is_string($key) && $key !== '' ? $directory . '/gallery_mark_' . hash_hmac('sha256', 'watermark', $key) : false;
	}

	public static function dimensions($path, $mime)
	{
		$size = $path && is_file($path) && !is_link($path) ? @getimagesize($path) : false;
		return $size && $size['mime'] === $mime && $size[0] >= 1 && $size[1] >= 1 &&
			$size[0] <= 10000 && $size[1] <= 10000 && $size[0] * $size[1] <= 20000000 ? $size : false;
	}

	/** JPEG APP1 stores a TIFF header. Read bounded segments even when ext-exif is unavailable. */
	public static function orientation($file)
	{
		$handle = @fopen($file, 'rb');
		if (!$handle) { return 1; }
		try
		{
			if (fread($handle, 2) !== "\xff\xd8") { return 1; }
			for ($i = 0; $i < 4096 && fread($handle, 1) === "\xff"; ++$i)
			{
				do { $kind = fread($handle, 1); } while ($kind === "\xff");
				if ($kind === '' || $kind === false || $kind === "\xda" || $kind === "\xd9") { break; }
				if ($kind === "\x01" || (ord($kind) >= 0xd0 && ord($kind) <= 0xd7)) { continue; }
				$field = fread($handle, 2);
				if (strlen($field) !== 2) { break; }
				$length = unpack('n', $field)[1] - 2;
				if ($length < 0) { break; }
				if ($kind === "\xe1" && $length >= 14)
				{
					$segment = fread($handle, $length);
					if (strlen($segment) !== $length) { break; }
					if (substr($segment, 0, 6) === "Exif\0\0") { return self::tiff_orientation(substr($segment, 6)); }
				}
				elseif (fseek($handle, $length, SEEK_CUR) !== 0) { break; }
			}
			return 1;
		}
		finally { fclose($handle); }
	}

	private static function tiff_orientation($data)
	{
		$order = substr($data, 0, 2);
		if (strlen($data) < 8 || ($order !== 'II' && $order !== 'MM')) { return 1; }
		$short = $order === 'II' ? 'v' : 'n';
		$long = $order === 'II' ? 'V' : 'N';
		$pos = unpack($long, $data, 4)[1];
		if (unpack($short, $data, 2)[1] !== 42 || $pos < 8 || $pos > strlen($data) - 2) { return 1; }
		$count = unpack($short, $data, $pos)[1];
		$pos += 2;
		if ($count > intdiv(strlen($data) - $pos, 12)) { return 1; }
		for ($i = 0; $i < $count; ++$i, $pos += 12)
		{
			if (unpack($short, $data, $pos)[1] !== 0x112) { continue; }
			if (unpack($short, $data, $pos + 2)[1] !== 3 || unpack($long, $data, $pos + 4)[1] !== 1) { return 1; }
			$value = unpack($short, $data, $pos + 8)[1];
			return $value >= 1 && $value <= 8 ? $value : 1;
		}
		return 1;
	}

	public static function orient($image, $orientation)
	{
		if (in_array($orientation, array(2,5,7), true) && !imageflip($image, IMG_FLIP_HORIZONTAL)) { return false; }
		if ($orientation === 4 && !imageflip($image, IMG_FLIP_VERTICAL)) { return false; }
		$angles = array(3 => 180, 5 => 90, 6 => -90, 7 => -90, 8 => 90);
		return isset($angles[$orientation]) ? imagerotate($image, $angles[$orientation], 0) : $image;
	}

	public static function fit($image, $width, $height, $mime)
	{
		$ratio = min(1, $width / imagesx($image), $height / imagesy($image));
		if ($ratio >= 1) { return $image; }
		$w = max(1, (int) round(imagesx($image) * $ratio));
		$h = max(1, (int) round(imagesy($image) * $ratio));
		$scaled = imagecreatetruecolor($w, $h);
		if (!$scaled) { return false; }
		imagealphablending($scaled, false);
		imagesavealpha($scaled, true);
		if ($mime === 'image/gif' && imagecolortransparent($image) >= 0)
		{
			$colour = imagecolorsforindex($image, imagecolortransparent($image));
			$transparent = imagecolorallocatealpha($scaled, $colour['red'], $colour['green'], $colour['blue'], 127);
			imagefill($scaled, 0, 0, $transparent);
			imagecolortransparent($scaled, $transparent);
		}
		return imagecopyresampled($scaled, $image, 0, 0, 0, 0, $w, $h, imagesx($image), imagesy($image)) ? $scaled : false;
	}

	public static function encode($image, $target, $mime, $quality)
	{
		if ($mime === 'image/jpeg') { return imagejpeg($image, $target, $quality); }
		if ($mime === 'image/png') { return imagepng($image, $target); }
		return $mime === 'image/gif' && function_exists('imagegif') && imagegif($image, $target);
	}
}
