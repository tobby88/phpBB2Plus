<?php
/***************************************************************************
 *                            usercp_confirm.php
 *                            -------------------
 *   begin                : Feb 23, 2006
 *   copyright            : (C) 2006 AmigaLink
 *   website              : www.AmigaLink.de
 *
 *   $Id: usercp_confirm.php,v 2.0.9.0 2006/03/29 14:03:00 AmigaLink Exp $
 *
 ***************************************************************************/

/***************************************************************************
 *
 *   This program is free software; you can redistribute it and/or modify
 *   it under the terms of the GNU General Public License as published by
 *   the Free Software Foundation; either version 2 of the License, or
 *   (at your option) any later version.
 *
 ***************************************************************************/

if ( !defined('IN_PHPBB') )
{
	die('Hacking attempt');
	exit;
}

$font_debug = false;

// Do we have an id? No, then just exit
if (!isset($HTTP_GET_VARS['id']) || !is_scalar($HTTP_GET_VARS['id']) || $HTTP_GET_VARS['id'] === '')
{
	exit;
}

$confirm_id = htmlspecialchars((string) $HTTP_GET_VARS['id']);

// Define available charset
$chars = array('A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J',  'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T',  'U', 'V', 'W', 'X', 'Y', 'Z', '1', '2', '3', '4', '5', '6', '7', '8', '9');

#if (!preg_match('/^[A-Za-z0-9]+$/', $confirm_id))
if (!preg_match('/^[[:alnum:]]+$/', $confirm_id))
{
	$confirm_id = '';
}

if ($confirm_id === 'Admin')
{
	if ( !$userdata['session_logged_in'] )
	{
		die('Hacking attempt');
		exit;
	}
	$code = 'SAMPLE';
	$font_debug = true;
}
else
{
	// Try and grab code for this id and session
	$sql = 'SELECT code  
		FROM ' . CONFIRM_TABLE . " 
		WHERE session_id = '" . $userdata['session_id'] . "' 
			AND confirm_id = '$confirm_id'";
	$result = $db->sql_query($sql);

	// If we have a row then grab data else create a new id
	if ($row = $db->sql_fetchrow($result))
	{
		$db->sql_freeresult($result);
		$code = $row['code'];
	}
	else
	{
		exit;
	}
}

if (!is_string($code) || !preg_match('/^[A-Z0-9]{1,12}$/iD', $code))
{
	exit;
}

// The bundled filtered-PNG implementation does not depend on GD and keeps
// registration usable on minimal PHP installations.
if (!function_exists('imagecreate') || !function_exists('imagecolorallocate'))
{
	include($phpbb_root_path . 'includes/usercp_confirm.' . $phpEx);
	exit;
}

#include($phpbb_root_path.'includes/functions_captcha.'.$phpEx);

// Read the config table
$captcha_config = array();
$sql = "SELECT *
	FROM " . CAPTCHA_CONFIG_TABLE;
if( !($result = $db->sql_query($sql)) )
{
	message_die(CRITICAL_ERROR, "Could not query captcha config information", "", __LINE__, __FILE__, $sql);
}
while ( $row = $db->sql_fetchrow($result) )
{
	$captcha_config[$row['config_name']] = $row['config_value'];
}
$db->sql_freeresult($result);

// For better compatibility with some servers which need absolut path to load TTFonts
$phpbb_root_path = str_replace('index.'.$phpEx, '', realpath($phpbb_root_path.'index.'.$phpEx));

// Prefs
$total_width = max(120, min(1000, intval(isset($captcha_config['width']) ? $captcha_config['width'] : 320)));
$total_height = max(40, min(400, intval(isset($captcha_config['height']) ? $captcha_config['height'] : 80)));

$hex_bg_color = get_rgb(isset($captcha_config['background_color']) ? $captcha_config['background_color'] : 'FFFFFF');
$hex_bg_color = ($hex_bg_color === null) ? '255,255,255' : $hex_bg_color;
$bg_color = array();
$bg_color = explode(",", $hex_bg_color);

$jpeg = !empty($captcha_config['jpeg']);
$img_quality = max(0, min(95, intval(isset($captcha_config['jpeg_quality']) ? $captcha_config['jpeg_quality'] : 85)));
// Max quality is 95

$pre_letters = max(0, min(5, intval(isset($captcha_config['pre_letters']) ? $captcha_config['pre_letters'] : 0)));
$pre_letter_great = !empty($captcha_config['pre_letters_great']);
$rnd_font = !empty($captcha_config['font']);
$chess = isset($captcha_config['chess']) && in_array((string) $captcha_config['chess'], array('0', '1', '2'), true) ? (string) $captcha_config['chess'] : '0';
$ellipses = isset($captcha_config['ellipses']) && in_array((string) $captcha_config['ellipses'], array('0', '1', '2'), true) ? (string) $captcha_config['ellipses'] : '0';
$arcs = isset($captcha_config['arcs']) && in_array((string) $captcha_config['arcs'], array('0', '1', '2'), true) ? (string) $captcha_config['arcs'] : '0';
$lines = isset($captcha_config['lines']) && in_array((string) $captcha_config['lines'], array('0', '1', '2'), true) ? (string) $captcha_config['lines'] : '0';
$gammacorrect = isset($captcha_config['gammacorrect']) ? (float) $captcha_config['gammacorrect'] : 0.0;
$gammacorrect = ($gammacorrect > 0.0 && $gammacorrect <= 10.0) ? $gammacorrect : 0.0;

$foreground_lattice_y = max(0, min(100, intval(isset($captcha_config['foreground_lattice_y']) ? $captcha_config['foreground_lattice_y'] : 0)));
$foreground_lattice_x = max(0, min(100, intval(isset($captcha_config['foreground_lattice_x']) ? $captcha_config['foreground_lattice_x'] : 0)));
$hex_lattice_color = get_rgb(isset($captcha_config['lattice_color']) ? $captcha_config['lattice_color'] : 'FFFFFF');
$hex_lattice_color = ($hex_lattice_color === null) ? '255,255,255' : $hex_lattice_color;
$rgb_lattice_color = array();
$rgb_lattice_color = explode(",", $hex_lattice_color);

// Fonts init
$fonts = array();
if ($fonts_dir = @opendir($phpbb_root_path.'captcha/fonts/'))
{
	while (($file = @readdir($fonts_dir)) !== false)
	{ 
		$font_path = $phpbb_root_path . 'captcha/fonts/' . $file;
		if (substr(strtolower($file), -4) === '.ttf' && @is_file($font_path) && @is_readable($font_path) &&
			(!function_exists('imagettfbbox') || @imagettfbbox(12, 0, $font_path, 'A') !== false))
		{         
			$fonts[] = $file; 
		}     
	}
	closedir($fonts_dir);
}
$use_ttf = count($fonts) > 0 && function_exists('imagettfbbox') && function_exists('imagettftext');
$font = $use_ttf ? rand(0, (count($fonts)-1)) : 5;

// Generate
$image = (gdVersion() >= 2 && function_exists('imagecreatetruecolor')) ? @imagecreatetruecolor($total_width, $total_height) : @imagecreate($total_width, $total_height);
if (!$image)
{
	header('Content-Type: text/plain; charset=UTF-8');
	header('Cache-Control: no-store, no-cache, max-age=0');
	http_response_code(503);
	exit('Visual confirmation is temporarily unavailable.');
}
$background_color = imagecolorallocate($image, $bg_color[0], $bg_color[1], $bg_color[2]);
imagefill($image, 0, 0, $background_color);
#imagecolortransparent($image, $background_color);

// Generate backgrund
if ($chess == '1' || $chess == '2' && rand(0,1))
{
	// Draw rectangles
	for($i = 0; $i <= 8; $i++)
	{
		$rectanglecolor = imagecolorallocate($image, rand(100,200),rand(100,200),rand(100,200));
		imagefilledrectangle($image, 0, 0, round($total_width-($total_width/8*$i)), round($total_height), $rectanglecolor);
		$rectanglecolor = imagecolorallocate($image, rand(100,200),rand(100,200),rand(100,200));
		imagefilledrectangle($image, 0, 0, round($total_width-($total_width/8*$i)), round($total_height/2), $rectanglecolor);
	}
}
if ($ellipses == '1' || $ellipses == '2' && rand(0,1))
{
	// Draw random ellipses
	for ($i = 1; $i <= 60; $i++)
	{
		$ellipsecolor = imagecolorallocate($image, rand(100,250),rand(100,250),rand(100,250));
		imagefilledellipse($image, rand(0, $total_width), rand(0, $total_height), rand(1, max(1, (int) floor($total_width / 8))), rand(1, max(1, (int) floor($total_height / 4))), $ellipsecolor);
	}
}
if ($arcs == '1' || $arcs == '2' && rand(0,1))
{
	// Draw random partial ellipses
	for ($i = 0; $i <= 30; $i++)
	{
		$linecolor = imagecolorallocate($image, rand(120,255),rand(120,255),rand(120,255));
		$cx = round(rand(1, $total_width));
		$cy = round(rand(1, $total_height));
		$int_w = rand(1, max(1, (int) floor($total_width / 2)));
		$int_h = round(rand(1, $total_height));
		imagearc($image, $cx, $cy, $int_w, $int_h, round(rand(0, 190)), round(rand(191, 360)), $linecolor);
		imagearc($image, $cx-1, $cy-1, $int_w, $int_h, round(rand(0, 190)), round(rand(191, 360)), $linecolor);
	}
}
if ($lines == '1' || $lines == '2' && rand(0,1))
{
	// Draw random lines
	for ($i = 0; $i <= 50; $i++)
	{
		$linecolor = imagecolorallocate($image, rand(120,255),rand(120,255),rand(120,255));
		imageline($image, rand(1, $total_width * 3), rand(1, $total_height * 5), rand(1, max(1, (int) floor($total_width / 2))), rand(1, $total_height * 2), $linecolor);
	}
}
//


$text_color_array = array('255,51,0', '51,77,255', '204,51,102', '0,153,0', '255,166,2', '255,0,255', '255,0,0', '0,255,0', '0,0,255', '0,255,255');
shuffle($text_color_array);
$pre_text_color_array = array('255,71,20', '71,20,224', '224,71,122', '20,173,20', '255,186,22', '25,25,25');
shuffle($pre_text_color_array);
$white = imagecolorallocate($image, 255, 255, 255);
$gray = imagecolorallocate($image, 100, 100, 100);
$black = imagecolorallocate($image, 0, 0, 0);
$lattice_color = imagecolorallocate($image, $rgb_lattice_color[0], $rgb_lattice_color[1], $rgb_lattice_color[2]);

#$x_char_position = (round($total_width/6));
$x_char_position = (round(($total_width - 12) / strlen($code)) + mt_rand(-3, 5));

for ($i = 0; $i < strlen($code); $i++)
{
	$char = $code[$i];
#	$size = mt_rand(18, ceil($total_height / 2.8));
	$size = mt_rand(max(1, (int) floor($total_height / 3.5)), max(1, (int) ceil($total_height / 2.8)));
	$font = ($use_ttf && $rnd_font) ? rand(0, (count($fonts)-1)) : $font;
	$angle = mt_rand(-30, 30);
	$text_color = $text_color_array[mt_rand(0,count($text_color_array)-1)];
	$text_color = explode(",", $text_color);
	$textcolor = imagecolorallocate($image, $text_color[0], $text_color[1], $text_color[2]);

	if (!$use_ttf)
	{
		$builtin_font = 5;
		$letter_width = imagefontwidth($builtin_font);
		$letter_height = imagefontheight($builtin_font);
		$x_pos = max(2, (int) (($i + 0.5) * ($total_width / strlen($code)) - ($letter_width / 2)));
		$y_pos = max(2, (int) (($total_height - $letter_height) / 2) + mt_rand(-3, 3));
		imagestring($image, $builtin_font, $x_pos + 2, $y_pos + 2, $char, $white);
		imagestring($image, $builtin_font, $x_pos + 1, $y_pos + 1, $char, $black);
		imagestring($image, $builtin_font, $x_pos, $y_pos, $char, $textcolor);
		continue;
	}

	$char_pos = array();
	$font_path = $phpbb_root_path . 'captcha/fonts/' . $fonts[$font];
	$char_pos = @imagettfbbox($size, $angle, $font_path, $char);
	if (!is_array($char_pos) || count($char_pos) < 8)
	{
		$builtin_font = 5;
		$letter_width = imagefontwidth($builtin_font);
		$letter_height = imagefontheight($builtin_font);
		$x_pos = max(2, (int) (($i + 0.5) * ($total_width / strlen($code)) - ($letter_width / 2)));
		$y_pos = max(2, (int) (($total_height - $letter_height) / 2) + mt_rand(-3, 3));
		imagestring($image, $builtin_font, $x_pos + 2, $y_pos + 2, $char, $white);
		imagestring($image, $builtin_font, $x_pos + 1, $y_pos + 1, $char, $black);
		imagestring($image, $builtin_font, $x_pos, $y_pos, $char, $textcolor);
		continue;
	}
	$letter_width = abs($char_pos[0]) + abs($char_pos[4]);
	$letter_height = abs($char_pos[1]) + abs($char_pos[5]);

	$x_pos = ($x_char_position / 4) + ($i * $x_char_position);
	($i == strlen($code)-1 && $x_pos >= ($total_width - ($letter_width + 5))) ? $x_pos = ($total_width - ($letter_width + 5)) : '';
	$y_min = max(1, (int) ceil($size * 1.4));
	$y_max = max($y_min, (int) floor($total_height - ($size * 0.4)));
	$y_pos = mt_rand($y_min, $y_max);

//	Pre letters
	$size = ($pre_letter_great) ? $size + (2 * $pre_letters) : $size - (2 * $pre_letters);
	for ($count = 1; $count <= $pre_letters; $count++)
	{
		$pre_angle = $angle + mt_rand(-20, 20);

		$text_color = $pre_text_color_array[mt_rand(0,count($pre_text_color_array)-1)];
		$text_color = explode(",", $text_color);
		$textcolor = imagecolorallocate($image, $text_color[0], $text_color[1], $text_color[2]);

		imagettftext($image, $size, $pre_angle, $x_pos, $y_pos-2, $white, $phpbb_root_path.'captcha/fonts/'.$fonts[$font], $char);
		imagettftext($image, $size, $pre_angle, $x_pos+2, $y_pos, $black, $phpbb_root_path.'captcha/fonts/'.$fonts[$font], $char);
		imagettftext($image, $size, $pre_angle, $x_pos+1, $y_pos-1, $textcolor, $phpbb_root_path.'captcha/fonts/'.$fonts[$font], $char);

		$size = ($pre_letter_great) ? $size - 2 : $size + 2;
	}

//	Final letters
	imagettftext($image, $size, $angle, $x_pos, $y_pos-2, $white, $phpbb_root_path.'captcha/fonts/'.$fonts[$font], $char);
	imagettftext($image, $size, $angle, $x_pos+2, $y_pos, $black, $phpbb_root_path.'captcha/fonts/'.$fonts[$font], $char);
	imagettftext($image, $size, $angle, $x_pos+1, $y_pos-1, $textcolor, $phpbb_root_path.'captcha/fonts/'.$fonts[$font], $char);
}


($gammacorrect) ? imagegammacorrect($image, 1.0, $gammacorrect) : '';

// Generate a white lattice in foreground
if ($foreground_lattice_y)
{
	// x lines
	$ih = round($total_height / $foreground_lattice_y);
	for ($i = 0; $i <= $ih; $i++)
	{
		imageline($image, 0, $i*$foreground_lattice_y, $total_width, $i*$foreground_lattice_y, $lattice_color);
	}
}
if ($foreground_lattice_x)
{
	// y lines
	$iw = round($total_width / $foreground_lattice_x);
	for ($i = 0; $i <= $iw; $i++)
	{
		imageline($image, $i*$foreground_lattice_x, 0, $i*$foreground_lattice_x, $total_height, $lattice_color);
	}
}

// Font debug
if ($font_debug && !$rnd_font && $use_ttf)
{
	imagestring($image, 5, 2, 0, $fonts[$font], $white);
	imagestring($image, 5, 5, 0, $fonts[$font], $white);
	imagestring($image, 5, 4, 2, $fonts[$font], $gray);
	imagestring($image, 5, 3, 1, $fonts[$font], $black);
}

// Display
header("Last-Modified: " . gmdate("D, d M Y H:i:s") ." GMT"); 
header("Pragma: no-cache"); 
header("Cache-Control: no-store, no-cache, max-age=0, must-revalidate");
(!$jpeg) ? header("Content-Type: image/png") : header("Content-Type: image/jpeg");

(!$jpeg) ? imagepng($image) : imagejpeg($image, null, $img_quality);
imagedestroy($image);
exit;

// Function get_rgb by Frank Burian
// http://www.phpfuncs.org/?content=show&id=46
function get_rgb($hex){ 
    $hex_array = array(0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 
        'A' => 10, 'B' => 11, 'C' => 12, 'D' => 13, 'E' => 14, 
        'F' => 15); 
    $hex = str_replace('#', '', strtoupper($hex)); 
    if (($length = strlen($hex)) == 3) { 
        $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2]; 
        $length = 6; 
    } 
    if ($length != 6 or strlen(str_replace(array_keys($hex_array), '', $hex))) 
        return NULL; 
    $rgb['r'] = $hex_array[$hex[0]] * 16 + $hex_array[$hex[1]]; 
    $rgb['g'] = $hex_array[$hex[2]] * 16 + $hex_array[$hex[3]]; 
    $rgb['b']= $hex_array[$hex[4]] * 16 + $hex_array[$hex[5]]; 
    return $rgb['r'].','.$rgb['g'].','.$rgb['b']; 
}

// Function  gdVersion by Hagan Fox
// http://de3.php.net/manual/en/function.gd-info.php#52481
function gdVersion($user_ver = 0)
{
   if (! extension_loaded('gd')) { return; }
   static $gd_ver = 0;
   // Just accept the specified setting if it's 1.
   if ($user_ver == 1) { $gd_ver = 1; return 1; }
   // Use the static variable if function was called previously.
   if ($user_ver !=2 && $gd_ver > 0 ) { return $gd_ver; }
   // Use the gd_info() function if possible.
   if (function_exists('gd_info')) {
       $ver_info = gd_info();
       preg_match('/\d/', $ver_info['GD Version'], $match);
       $gd_ver = $match[0];
       return $match[0];
   }
   // gd_info() exists on every supported PHP version. An unusual build which
   // omits it can safely use the GD2 rendering branch; never scrape full PHP diagnostics,
   // because its output contains configuration and request metadata.
   $gd_ver = 2;
   return $gd_ver;
} // End gdVersion()
?>
