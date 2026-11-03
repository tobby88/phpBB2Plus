<?php

define('IN_PHPBB', 'true');
// This deliberately sessionless image endpoint validates and bounds every
// input locally. The CI bootstrap audit requires this explicit exception.
define('PHPBB_STANDALONE_VALIDATED', true);
$phpbb_root_path = './';
include_once($phpbb_root_path . 'includes/php_compat.php');
require_once($phpbb_root_path . 'includes/functions_schild.php');

$raute = '#';
$fontcolor = isset($_GET['fontcolor']) && is_string($_GET['fontcolor']) && preg_match('/^[a-f0-9]{6}$/i', $_GET['fontcolor']) ? $_GET['fontcolor'] : '000000';
$shadowcolor = isset($_GET['shadowcolor']) && is_string($_GET['shadowcolor']) && preg_match('/^[a-f0-9]{6}$/i', $_GET['shadowcolor']) ? $_GET['shadowcolor'] : '';
$schriftfarbe = $raute . $fontcolor;
$std_smilie = 1;


$smilie = isset($_GET['smilie']) && is_scalar($_GET['smilie']) ? (string) $_GET['smilie'] : 'standard';
if ( $smilie == 'random')
{
	$smilie = 'random';
}
else if ( $smilie == 'standard')
{
	$smilie = $std_smilie;
}
else
{
	$smilie = intval($smilie);
}

if ( $shadowcolor == '' )
{
	$schattenfarbe = '';
}
else
{
	$schattenfarbe = $raute . $shadowcolor;
}

$schildschatten = ( isset($_GET['shieldshadow']) && $_GET['shieldshadow'] == '1' ) ? true : false;

$smilie_dir = $phpbb_root_path . 'smilie_creator/images/smilies/schild';
$smilie_ids = array();
if ($hdl = @opendir($smilie_dir))
{
	while (($res = readdir($hdl)) !== false)
	{
		if (preg_match('/^smilie([1-9][0-9]*)\.png$/i', $res, $match))
		{
			$smilie_ids[] = intval($match[1]);
		}
	}
	closedir($hdl);
}
sort($smilie_ids, SORT_NUMERIC);

if (empty($smilie_ids) || !function_exists('gd_info') || !function_exists('imagecreatefrompng') || !function_exists('imagecreate') || !function_exists('imagepng'))
{
	header('Content-Type: text/plain; charset=UTF-8');
	header('X-Content-Type-Options: nosniff');
	header('Cache-Control: no-store, private');
	http_response_code(503);
	echo 'Smilie image generation is unavailable.';
	exit;
}

$text = phpbb_schild_text(isset($_GET['text']) ? $_GET['text'] : '');
// DejaVu Sans 2.37, distributed unmodified with assets/fonts/LICENSE-DejaVu.txt.
$font = phpbb_schild_font(__DIR__ . '/assets/fonts/DejaVuSans.ttf');
$output = phpbb_schild_lines($text, $font);
$width = 60;
foreach ($output as $line) { $metrics = phpbb_schild_metrics($line, $font); $width = max($width, $metrics[0] + 6); }
$width = min(204, $width);
$height = count($output) * $font['height'] + 34;
$center = (int) floor($width / 2);

if($smilie == 'random') $smilie = $smilie_ids[mt_rand(0, count($smilie_ids) - 1)];
if(!$smilie){
	if(in_array($std_smilie, $smilie_ids, true)) $smilie = $std_smilie;
	else $smilie = $smilie_ids[0];
}
if (!in_array(intval($smilie), $smilie_ids, true))
{
	$smilie = in_array($std_smilie, $smilie_ids, true) ? $std_smilie : $smilie_ids[0];
}


$smilie = @imagecreatefrompng($smilie_dir . '/smilie' . intval($smilie) . '.png');
$schild = @imagecreatefrompng($smilie_dir . '/schild.png');
$img = imagecreate($width,$height);

if (!$smilie || !$schild || !$img || imagesx($smilie) < 6 || imagesy($smilie) < 15 || imagesx($schild) < 7 || imagesy($schild) < 17)
{
	if ($smilie) phpbb_gd_release($smilie);
	if ($schild) phpbb_gd_release($schild);
	if ($img) phpbb_gd_release($img);
	header('Content-Type: text/plain; charset=UTF-8');
	header('X-Content-Type-Options: nosniff');
	header('Cache-Control: no-store, private');
	http_response_code(500);
	echo 'Smilie image assets could not be loaded.';
	exit;
}

$bgcolor = imagecolorallocate ($img, 111, 252, 134);
$txtcolor = imagecolorallocate ($img, hexdec(substr(str_replace('#',"",$schriftfarbe),0,2)), hexdec(substr(str_replace('#',"",$schriftfarbe),2,2)), hexdec(substr(str_replace('#',"",$schriftfarbe),4,2)));
$txt2color = imagecolorallocate ($img, hexdec(substr(str_replace('#',"",$schattenfarbe),0,2)), hexdec(substr(str_replace('#',"",$schattenfarbe),2,2)), hexdec(substr(str_replace('#',"",$schattenfarbe),4,2)));
$bocolor = imagecolorallocate ($img, 0, 0, 0);
$schcolor = imagecolorallocate ($img, 255, 255, 255);
$schatten1color = imagecolorallocate ($img, 235, 235, 235);
$schatten2color = imagecolorallocate ($img, 219, 219, 219);

$smiliefarbe = imagecolorsforindex($smilie, imagecolorat($smilie, 5, 14));

imagesetpixel($schild, 1, 14, imagecolorallocate($schild, min(255, $smiliefarbe['red'] + 52), min(255, $smiliefarbe['green'] + 59), min(255, $smiliefarbe['blue'] + 11)));
imagesetpixel($schild, 2, 14, imagecolorallocate($schild, min(255, $smiliefarbe['red'] + 50), min(255, $smiliefarbe['green'] + 52), min(255, $smiliefarbe['blue'] + 50)));
imagesetpixel($schild, 1, 15, imagecolorallocate($schild, min(255, $smiliefarbe['red'] + 50), min(255, $smiliefarbe['green'] + 52), min(255, $smiliefarbe['blue'] + 50)));
imagesetpixel($schild, 2, 15, imagecolorallocate($schild, min(255, $smiliefarbe['red'] + 22), min(255, $smiliefarbe['green'] + 21), min(255, $smiliefarbe['blue'] + 35)));
imagesetpixel($schild, 1, 16, imagecolorat($smilie, 5, 14));
imagesetpixel($schild, 2, 16, imagecolorat($smilie, 5, 14));
imagesetpixel($schild, 5, 16, imagecolorallocate($schild, min(255, $smiliefarbe['red'] + 22), min(255, $smiliefarbe['green'] + 21), min(255, $smiliefarbe['blue'] + 35)));
imagesetpixel($schild, 6, 16, imagecolorat($smilie, 5, 14));
imagesetpixel($schild, 5, 15, imagecolorallocate($schild, min(255, $smiliefarbe['red'] + 52), min(255, $smiliefarbe['green'] + 59), min(255, $smiliefarbe['blue'] + 11)));
imagesetpixel($schild, 6, 15, imagecolorallocate($schild, min(255, $smiliefarbe['red'] + 50), min(255, $smiliefarbe['green'] + 52), min(255, $smiliefarbe['blue'] + 50)));


imagecopy ($img, $schild, $center - 3, 0, 0, 0, 6, 4); // Bildteil kopieren
imagecopy ($img, $schild, $center - 3, $height - 24, 0, 5, 9, 17); // Bildteil kopieren
imagecopy ($img, $smilie, $center + 6, $height - 24, 0, 0, 23, 23); // Bildteil kopieren

imagefilledrectangle($img, 0, 4, $width, ($height - 25), $bocolor);
imagefilledrectangle($img, 1, 5, ($width - 2), ($height - 26), $schcolor);

if($schildschatten){
	$shadow_points_one = array((($width - 2) / 2 + ((($width - 2) / 4) - 3)), 5, (($width - 2) / 2 + ((($width - 2) / 4) + 3)), 5, (($width - 2) / 2 - ((($width - 2) / 4) - 3)), ($height - 26), (($width - 2) / 2 - ((($width - 2) / 4) + 3)), ($height - 26));
	$shadow_points_two = array((($width - 2) / 2 + ((($width - 2) / 4) + 4)), 5, ($width - 2), 5, ($width - 2), ($height - 26), (($width - 2) / 2 - ((($width - 2) / 4) - 4)), ($height - 26));
	$shadow_points_one = array_map('intval', $shadow_points_one);
	$shadow_points_two = array_map('intval', $shadow_points_two);
	if (PHP_VERSION_ID >= 80100)
	{
		imagefilledpolygon($img, $shadow_points_one, $schatten1color);
		imagefilledpolygon($img, $shadow_points_two, $schatten2color);
	}
	else
	{
		imagefilledpolygon($img, $shadow_points_one, 4, $schatten1color);
		imagefilledpolygon($img, $shadow_points_two, 4, $schatten2color);
	}
}

if (!phpbb_schild_draw_text($img, $output, $font, $width, $txtcolor, $schattenfarbe !== '' ? $txt2color : null))
{
	phpbb_gd_release($img); phpbb_gd_release($schild); phpbb_gd_release($smilie);
	header('Content-Type: text/plain; charset=UTF-8'); header('X-Content-Type-Options: nosniff'); header('Cache-Control: no-store, private');
	http_response_code(500); echo 'Smilie text could not be rendered.'; exit;
}


imagecolortransparent($img, $bgcolor);  // Dummybg als transparenz setzen
// These small PNGs need no progressive passes; older GD readers also decode
// non-interlaced output without libpng's interlace-handling warning.
imageinterlace($img, false);

header('Content-Type: image/png');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, private');
//imagepng($img,'',100);   // 100 = komprimierung
imagepng($img); 
phpbb_gd_release($img);
phpbb_gd_release($schild);
phpbb_gd_release($smilie);

exit;
?>
