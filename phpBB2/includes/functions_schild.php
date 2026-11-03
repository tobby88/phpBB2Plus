<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/php_compat.php';

function phpbb_schild_text($value)
{
	if (!is_string($value)) { return 'error'; }
	// This sessionless endpoint receives raw GET, without common.php's slashes.
	$value = html_entity_decode(substr($value, 0, 8192), ENT_QUOTES, 'UTF-8');
	$value = html_entity_decode(htmlspecialchars($value, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8'), ENT_NOQUOTES, 'UTF-8');
	$value = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $value);
	$value = preg_replace('/[\s\p{Z}]+/u', ' ', strip_tags($value));
	if (!is_string($value) || preg_match('/\A.{0,512}/us', trim($value), $match) !== 1 || $match[0] === '') { return 'error'; }
	return $match[0];
}

function phpbb_schild_font($file)
{
	$font = array('ttf'=>false, 'file'=>'', 'size'=>8, 'ascent'=>0, 'height'=>imagefontheight(2) + 2);
	if (function_exists('imagettfbbox') && function_exists('imagettftext') && is_string($file) && is_file($file) && is_readable($file))
	{
		$box = @imagettfbbox(8, 0, $file, 'AgÄgj');
		if (is_array($box) && count($box) === 8)
		{
			$top = min($box[1], $box[3], $box[5], $box[7]);
			$bottom = max($box[1], $box[3], $box[5], $box[7]);
			$font = array('ttf'=>true, 'file'=>$file, 'size'=>8, 'ascent'=>max(1, -$top), 'height'=>min(24, max(12, $bottom - $top + 3)));
		}
	}
	return $font;
}

function phpbb_schild_bitmap_text($text)
{
	// GD's built-in fonts use latin2. Convert only at the drawing boundary.
	if (function_exists('iconv'))
	{
		$bytes = @iconv('UTF-8', 'ISO-8859-2//TRANSLIT', $text);
		if (is_string($bytes)) { return $bytes; }
	}
	// An installation without iconv still gets readable ASCII, never UTF-8 bytes
	// interpreted as unrelated bitmap glyphs.
	$text = strtr($text, array('ä'=>'ae','ö'=>'oe','ü'=>'ue','Ä'=>'Ae','Ö'=>'Oe','Ü'=>'Ue','ß'=>'ss','é'=>'e','è'=>'e','ê'=>'e','á'=>'a','à'=>'a','ó'=>'o','ñ'=>'n'));
	return preg_replace('/[^\x00-\x7f]/u', '?', $text);
}

function phpbb_schild_metrics($text, $font)
{
	if ($font['ttf'])
	{
		$box = @imagettfbbox($font['size'], 0, $font['file'], $text);
		if (!is_array($box) || count($box) !== 8) { throw new RuntimeException('Smilie text measurement failed'); }
		$left = min($box[0], $box[2], $box[4], $box[6]);
		return array(max($box[0], $box[2], $box[4], $box[6]) - $left, $left);
	}
	return array(strlen(phpbb_schild_bitmap_text($text)) * imagefontwidth(2), 0);
}

function phpbb_schild_lines($text, $font)
{
	$lines = array(); $line = ''; $widths = array();
	$fits = function($value) use ($font, &$widths)
	{
		if (preg_match_all('/./us', $value, $characters) > 33) { return false; }
		if (!isset($widths[$value])) { $metrics = phpbb_schild_metrics($value, $font); $widths[$value] = $metrics[0]; }
		return $widths[$value] <= 198;
	};
	foreach (explode(' ', $text) as $word)
	{
		$candidate = $line === '' ? $word : $line . ' ' . $word;
		if ($fits($candidate)) { $line = $candidate; continue; }
		if ($line !== '') { $lines[] = $line; $line = ''; }
		foreach (preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) as $character)
		{
			if ($line !== '' && !$fits($line . $character)) { $lines[] = $line; $line = ''; }
			$line .= $character;
		}
	}
	if ($line !== '') { $lines[] = $line; }
	if (count($lines) > 12)
	{
		$lines = array_slice($lines, 0, 12);
		$characters = preg_split('//u', $lines[11], -1, PREG_SPLIT_NO_EMPTY);
		while ($characters && !$fits(implode('', $characters) . '...')) { array_pop($characters); }
		$lines[11] = implode('', $characters) . '...';
	}
	return $lines;
}

function phpbb_schild_draw_text($image, $lines, $font, $width, $color, $shadow)
{
	foreach ($lines as $index => $line)
	{
		$metrics = phpbb_schild_metrics($line, $font);
		$x = (int) floor(($width - $metrics[0]) / 2) - $metrics[1];
		$y = 5 + $index * $font['height'];
		if ($font['ttf'])
		{
			$y += $font['ascent'];
			if ($shadow !== null && @imagettftext($image, $font['size'], 0, $x + 1, $y + 1, $shadow, $font['file'], $line) === false) { return false; }
			if (@imagettftext($image, $font['size'], 0, $x, $y, $color, $font['file'], $line) === false) { return false; }
		}
		else
		{
			$bytes = phpbb_schild_bitmap_text($line);
			if ($shadow !== null) { imagestring($image, 2, $x + 1, $y + 1, $bytes, $shadow); }
			imagestring($image, 2, $x, $y, $bytes, $color);
		}
	}
	return true;
}
