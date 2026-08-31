<?php

define('IN_PHPBB', 'true');
// This deliberately sessionless image endpoint validates and bounds every
// input locally. The CI bootstrap audit requires this explicit exception.
define('PHPBB_STANDALONE_VALIDATED', true);
$phpbb_root_path = './';
include_once($phpbb_root_path . 'includes/php_compat.php');

$raute = '#';
$fontcolor = isset($_GET['fontcolor']) && is_string($_GET['fontcolor']) && preg_match('/^[a-f0-9]{6}$/i', $_GET['fontcolor']) ? $_GET['fontcolor'] : '000000';
$shadowcolor = isset($_GET['shadowcolor']) && is_string($_GET['shadowcolor']) && preg_match('/^[a-f0-9]{6}$/i', $_GET['shadowcolor']) ? $_GET['shadowcolor'] : '';
$schriftfarbe = $raute . $fontcolor;
$schriftdatei = 'arial';
$std_smilie = 1;
$schriftwidth = 0;
$schriftheight = 0;
$zeichenzahl = 0;
$output = array();
$text = '';


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

if (empty($smilie_ids) || !function_exists('gd_info') || !function_exists('imagecreatefrompng') || !function_exists('imagecreate'))
{
	header('Content-Type: text/plain; charset=UTF-8');
	header('X-Content-Type-Options: nosniff');
	header('Cache-Control: no-store, private');
	http_response_code(503);
	echo 'Smilie image generation is unavailable.';
	exit;
}

$gd_info = gd_info();

if((!$gd_info['FreeType Support']) || (!file_exists($schriftdatei))){
	$schriftwidth = 6;
	$schriftheight = 8;
}else{
	if((!$schriftheight) || (!$schriftwidth)){
		$schriftwidth = imagefontwidth($schriftdatei);
		$schriftheight = imagefontheight($schriftdatei);
	}
}
$schriftheight += 2;


if(!$text) $text = isset($_GET['text']) && !is_array($_GET['text']) ? trim($_GET['text']) : '';
$text = html_entity_decode(stripslashes($text), ENT_QUOTES, 'UTF-8');
$text = preg_replace('/\s+/', ' ', strip_tags(substr($text, 0, 512)));

if(!$text) $text = 'error';//$lang['SC_error']; 

if(strlen($text) > 33){
	$worte = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);

	if(is_array($worte)){
		$i = 0;
		$output[0] = '';
		foreach($worte as $wort){
			if((strlen($output[$i].' '.$wort) < 33) && (!substr_count($wort, '[SM'))){
				$output[$i] .= ' '.$wort;
			}else{
				if($i < 11){
					if($zeichenzahl < strlen($output[$i])) $zeichenzahl = strlen($output[$i]);
					$i++;
					$output[$i] = substr($wort, 0, 33);
				}else{
					$output[$i] = substr($output[$i], 0, 30) . '...';
					break;
				}
			}
		}
		if($zeichenzahl < strlen($output[$i])) $zeichenzahl = strlen($output[$i]);
	}else{
		$zeichenzahl = 33;
		$output[0] = substr($text, 0, 30)."...";
	}
}else{
	$zeichenzahl = strlen($text);
	$output[0] = $text;
}

if(count($output) > 12) $output[12] = substr($output[12], 0, 30)."...";

$width = ($zeichenzahl * $schriftwidth) + 6;
$height = (count($output) * $schriftheight) + 34;
if($width < 60) $width = 60;

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

if (!$smilie || !$schild || !$img)
{
	if ($smilie) imagedestroy($smilie);
	if ($schild) imagedestroy($schild);
	if ($img) imagedestroy($img);
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


imagecopy ($img, $schild, ($width / 2 - 3), 0, 0, 0, 6, 4); // Bildteil kopieren
imagecopy ($img, $schild, ($width / 2 - 3), ($height - 24), 0, 5, 9, 17); // Bildteil kopieren
imagecopy ($img, $smilie, ($width / 2 + 6), ($height - 24), 0, 0, 23, 23); // Bildteil kopieren

imagefilledrectangle($img, 0, 4, $width, ($height - 25), $bocolor);
imagefilledrectangle($img, 1, 5, ($width - 2), ($height - 26), $schcolor);

if($schildschatten){
	$shadow_points_one = array((($width - 2) / 2 + ((($width - 2) / 4) - 3)), 5, (($width - 2) / 2 + ((($width - 2) / 4) + 3)), 5, (($width - 2) / 2 - ((($width - 2) / 4) - 3)), ($height - 26), (($width - 2) / 2 - ((($width - 2) / 4) + 3)), ($height - 26));
	$shadow_points_two = array((($width - 2) / 2 + ((($width - 2) / 4) + 4)), 5, ($width - 2), 5, ($width - 2), ($height - 26), (($width - 2) / 2 - ((($width - 2) / 4) - 4)), ($height - 26));
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

$i = 0;
while($i < count($output)){
	if(((!$gd_info['FreeType Support']) || (!file_exists($schriftdatei)))){
		if($schattenfarbe) imagestring($img, 2, (($width - (strlen(trim($output[$i])) * $schriftwidth) - 2) / 2 + 1), ($i * $schriftheight + 6), trim($output[$i]), $txt2color);
		imagestring($img, 2, (($width - (strlen(trim($output[$i])) * $schriftwidth) - 2) / 2), ($i * $schriftheight + 5), trim($output[$i]), $txtcolor);
	}else{
		if($schattenfarbe) imagettftext($img, $schriftheight, 0, (($width - (strlen(trim($output[$i])) * $schriftwidth) - 2) / 2 + 1), ($i * $schriftheight + $schriftheight + 4), $txt2color, $schriftdatei, trim($output[$i]));
		imagettftext($img, $schriftheight, 0, (($width - (strlen(trim($output[$i])) * $schriftwidth) - 2) / 2), ($i * $schriftheight + $schriftheight + 3), $txtcolor, $schriftdatei, trim($output[$i]));
	}
	$i++;
}


imagecolortransparent($img, $bgcolor);  // Dummybg als transparenz setzen
imageinterlace($img, 1);

header('Content-Type: image/png');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, private');
//imagepng($img,'',100);   // 100 = komprimierung
imagepng($img); 
imagedestroy($img);
imagedestroy($schild);
imagedestroy($smilie);

exit;
?>
