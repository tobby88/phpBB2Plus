<?php
/** 
*
* @package attachment_mod
* @version $Id: functions_filetypes.php,v 1.1 2005/11/05 12:30:57 acydburn Exp $
* @copyright (c) 2002 Meik Sievertsen
* @license http://opensource.org/licenses/gpl-license.php GNU Public License 
*
*/

/**
* All Attachment Functions needed to determine Special Files/Dimensions
*/

/**
* Read Long Int (4 Bytes) from File
*/
function read_longint($fp)
{
	$data = fread($fp, 4);
	if (!is_string($data) || strlen($data) !== 4)
	{
		return 0;
	}

	$value = ord($data[0]) + (ord($data[1])<<8)+(ord($data[2])<<16)+(ord($data[3])<<24);
	if ($value >= 4294967294)
	{
		$value -= 4294967296;
	}

	return $value;
}

/**
* Read Word (2 Bytes) from File - Note: It's an Intel Word
*/
function read_word($fp)
{
	$data = fread($fp, 2);
	if (!is_string($data) || strlen($data) !== 2)
	{
		return 0;
	}

	$value = ord($data[1]) * 256 + ord($data[0]);
	
	return $value;
}

/**
* Read Byte
*/
function read_byte($fp)
{
	$data = fread($fp, 1);
	if (!is_string($data) || strlen($data) !== 1)
	{
		return 0;
	}

	$value = ord($data);
	
	return $value;
}

/**
* Get Image Dimensions
*/
function image_getdimension($file)
{
	$size = @getimagesize($file);

	if (is_array($size) && isset($size[0], $size[1]) && ($size[0] != 0 || $size[1] != 0))
	{
		return $size;
	}
	$size = array(0, 0);

	// Try to get the Dimension manually, depending on the mimetype
	$fp = @fopen($file, 'rb');
	if (!$fp)
	{
		return $size;
	}
	
	$error = false;

	// BMP - IMAGE

	$tmp_str = fread($fp, 2);
	if ($tmp_str == 'BM')
	{
		$length = read_longint($fp);

		if ($length <= 6)
		{
			$error = true;
		}

		if (!$error)
		{
			$i = read_longint($fp); 
			if ( $i != 0)
			{
				$error = true;
			}
		}

		if (!$error)
		{
			$i = read_longint($fp);

			if ($i != 0x3E && $i != 0x76 && $i != 0x436 && $i != 0x36)
			{
				$error = true;
			}
		}

		if (!$error)
		{
			$tmp_str = fread($fp, 4); 
			$width = read_longint($fp); 
			$height = read_longint($fp);

			if ($width < 1 || $height < 1 || $width > 3000 || $height > 3000)
			{
				$error = true;
			}
		}
	}
	else
	{
		$error = true;
	}

	if (!$error)
	{
		fclose($fp);
		return array(
			$width,
			$height,
			6
		);
	}
	
	$error = false;
	fclose($fp);

	// GIF - IMAGE

	$fp = @fopen($file, 'rb');
	if (!$fp)
	{
		return $size;
	}

	$tmp_str = fread($fp, 3);
	
	if ($tmp_str == 'GIF')
	{
		$tmp_str = fread($fp, 3);
		$width = read_word($fp);
		$height = read_word($fp);

		$info_byte = read_byte($fp);
		if ($width < 1 || $height < 1)
		{
			$error = true;
		}
		if (($info_byte & 0x80) != 0x80 && ($info_byte & 0x80) != 0)
		{
			$error = true;
		}
		
		if (!$error)
		{
			if (($info_byte & 8) != 0)
			{
				$error = true;
			}

		}
	}
	else
	{
		$error = true;
	}

	if (!$error)
	{
		fclose($fp);
		return array(
			$width,
			$height,
			1
		);
	}
	
	$error = false;
	fclose($fp);

	// JPG - IMAGE
	$fp = @fopen($file, 'rb');
	if (!$fp)
	{
		return $size;
	}

	$tmp_str = fread($fp, 4);
	$w1 = read_word($fp);

	if (!is_string($tmp_str) || strlen($tmp_str) !== 4 || substr($tmp_str, 0, 2) !== "\xFF\xD8" || intval($w1) < 16)
	{
		$error = true;
	}
	
	if (!$error)
	{
		$tmp_str = fread($fp, 4);
		if ($tmp_str == 'JFIF')
		{
			$o_byte = read_byte($fp);
			if ($o_byte != 0)
			{
				$error = true;
			}

			if (!$error)
			{
				$str = fread($fp, 2);
				$b = read_byte($fp);

				if ($b != 0 && $b != 1 && $b != 2)
				{
					$error = true;
				}
			}

			if (!$error)
			{
				$width = read_word($fp);
				$height = read_word($fp);

				if ($width <= 0 || $height <= 0)
				{
					$error = true;
				}
			}
		}
		else
		{
			$error = true;
		}
	}
	else
	{
		$error = true;
	}

	if (!$error)
	{
		fclose($fp);
		return array(
			$width,
			$height,
			2
		);
	}
	
	$error = false;
	fclose($fp);

	// PCX - IMAGE

	$fp = @fopen($file, 'rb');
	if (!$fp)
	{
		return $size;
	}

	$tmp_str = fread($fp, 3);
	
	if (is_string($tmp_str) && strlen($tmp_str) === 3 && (ord($tmp_str[0]) == 10) && (ord($tmp_str[1]) == 0 || ord($tmp_str[1]) == 2 || ord($tmp_str[1]) == 3 || ord($tmp_str[1]) == 4 || ord($tmp_str[1]) == 5) && (ord($tmp_str[2]) == 1))
	{
		$b = read_byte($fp);

		if ($b != 1 && $b != 2 && $b != 4 && $b != 8 && $b != 24)
		{
			$error = true;
		}

		if (!$error)
		{
			$xmin = read_word($fp);
			$ymin = read_word($fp);
			$xmax = read_word($fp);
			$ymax = read_word($fp);
			$tmp_str = fread($fp, 52);
	  
			$b = read_byte($fp);
			if ($b != 0)
			{
				$error = true;
			}
		}

		if (!$error)
		{
			$width = $xmax - $xmin + 1;
			$height = $ymax - $ymin + 1;
			if ($width < 1 || $height < 1 || $width > 3000 || $height > 3000)
			{
				$error = true;
			}
		}
	}
	else
	{
		$error = true;
	}

	if (!$error)
	{
		fclose($fp);
		return array(
			$width,
			$height,
			7
		);
	}
	
	fclose($fp);

	return $size;
}

/**
* Flash MX Support
* Routines and Methods are from PhpAdsNew (www.sourceforge.net/projects/phpadsnew)
*/

/**
*/
define('SWF_TAG_COMPRESSED', chr(0x43).chr(0x57).chr(0x53));
define('SWF_TAG_IDENTIFY', chr(0x46).chr(0x57).chr(0x53));
define('SWF_DIMENSION_MAX_BYTES', 16777216);

/**
* Get flash bits
*/
function swf_bits($buffer, $pos, $count)
{
	$result = 0;
	$required_bytes = (int) ceil(($pos + $count) / 8);
	if (!is_string($buffer) || $pos < 0 || $count < 1 || $count > 31 || strlen($buffer) < $required_bytes)
	{
		return false;
	}
	
	for ($loop = $pos; $loop < $pos + $count; $loop++)
	{
		$result = $result + ((((ord($buffer[(int)($loop / 8)])) >> (7 - ($loop % 8))) & 0x01) << ($count - ($loop - $pos) - 1));
	}

	return $result;
}

/**
* decompress flash contents
*/
function swf_decompress($buffer)
{
	if (!is_string($buffer) || strlen($buffer) < 8 || substr($buffer, 0, 3) !== SWF_TAG_COMPRESSED || ord($buffer[3]) < 6 || !function_exists('gzuncompress'))
	{
		return $buffer;
	}

	$length_data = unpack('Vlength', substr($buffer, 4, 4));
	$expected_length = isset($length_data['length']) ? (int) $length_data['length'] : 0;
	if ($expected_length < 8 || $expected_length > SWF_DIMENSION_MAX_BYTES)
	{
		return false;
	}

	$decompressed = @gzuncompress(substr($buffer, 8), $expected_length - 8);
	if (!is_string($decompressed) || strlen($decompressed) + 8 !== $expected_length)
	{
		return false;
	}

	return 'F' . substr($buffer, 1, 7) . $decompressed;
}

/**
* Get flash dimension
*/
function swf_getdimension($file)
{
	$size = @getimagesize($file);

	if (is_array($size) && isset($size[0], $size[1]) && ($size[0] != 0 || $size[1] != 0))
	{
		return $size;
	}
	$size = array(0, 0);

	// Try to get the Dimension manually
	$fp = @fopen($file, 'rb');
	if (!$fp)
	{
		return $size;
	}
	
	// Decompress if file is a Flash MX compressed file
	$buffer = fread($fp, 1024);
	if (!is_string($buffer) || strlen($buffer) < 9)
	{
		fclose($fp);
		return $size;
	}
	
	if (substr($buffer, 0, 3) === SWF_TAG_IDENTIFY || substr($buffer, 0, 3) === SWF_TAG_COMPRESSED)
	{
		if (substr($buffer, 0, 3) === SWF_TAG_COMPRESSED)
		{
			$file_size = @filesize($file);
			if (!is_int($file_size) || $file_size < 9 || $file_size > SWF_DIMENSION_MAX_BYTES)
			{
				fclose($fp);
				return $size;
			}
			fclose($fp);
			$fp = @fopen($file, 'rb');
			if (!$fp)
			{
				return $size;
			}
			$buffer = fread($fp, $file_size);
			$buffer = swf_decompress($buffer);
			if (!is_string($buffer) || strlen($buffer) < 9)
			{
				fclose($fp);
				return $size;
			}
		}
	
		// Get size of rect structure
		$bits = swf_bits ($buffer, 64, 5);
		if ($bits === false || $bits < 1 || $bits > 31)
		{
			fclose($fp);
			return $size;
		}

		// Get rect
		$x_min = swf_bits($buffer, 69, $bits);
		$x_max = swf_bits($buffer, 69 + $bits, $bits);
		$y_min = swf_bits($buffer, 69 + (2 * $bits), $bits);
		$y_max = swf_bits($buffer, 69 + (3 * $bits), $bits);
		if ($x_min === false || $x_max === false || $y_min === false || $y_max === false)
		{
			fclose($fp);
			return $size;
		}
		$width = (int) (($x_max - $x_min) / 20);
		$height = (int) (($y_max - $y_min) / 20);
		if ($width < 1 || $height < 1)
		{
			fclose($fp);
			return $size;
		}
	}
	else
	{
		fclose($fp);
		return $size;
	}

	fclose($fp);
	return array($width, $height, 2);
}

?>
