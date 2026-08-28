<?php

define('IN_PHPBB', 'true');
$phpbb_root_path = './';
include_once($phpbb_root_path . 'includes/php_compat.php');

$raute = '#';
$fontcolor = isset($_GET['fontcolor']) && is_string($_GET['fontcolor']) && preg_match('/^[a-f0-9]{6}$/i', $_GET['fontcolor']) ? $_GET['fontcolor'] : '000000';
$shadowcolor = isset($_GET['shadowcolor']) && is_string($_GET['shadowcolor']) && preg_match('/^[a-f0-9]{6}$/i', $_GET['shadowcolor']) ? $_GET['shadowcolor'] : '';
$schriftfarbe = $raute . $fontcolor;
$schriftdatei = 'arial';
$std_smilie = 1;
$phpversion_nr = (float) PHP_VERSION;
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

$anz_smilie = -1;
$hdl = opendir($phpbb_root_path. 'smilie_creator/images/smilies/schild/');
while($res = readdir($hdl)){
	if(strtolower(substr($res, (strlen($res) - 3), 3)) == 'png') $anz_smilie++;
}
closedir($hdl);


if($phpversion_nr >= 4.30) $gd_info = gd_info();
else{
	$gd_info['FreeType Support'] = 1;
}

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

mt_srand((float)microtime()*3216549);
if($smilie == 'random') $smilie = mt_rand(1,$anz_smilie);
if(!$smilie){
	if($std_smilie) $smilie = $std_smilie;
	else $smilie = mt_rand(1,$anz_smilie);
}
if ($smilie < 1 || $smilie > $anz_smilie || !is_file($phpbb_root_path . 'smilie_creator/images/smilies/schild/smilie' . $smilie . '.png'))
{
	$smilie = $std_smilie;
}


$smilie = imagecreatefrompng($phpbb_root_path . 'smilie_creator/images/smilies/schild/smilie'.$smilie.'.png');
$schild = imagecreatefrompng($phpbb_root_path . 'smilie_creator/images/smilies/schild/schild.png');
$img = imagecreate($width,$height);

$bgcolor = imagecolorallocate ($img, 111, 252, 134);
$txtcolor = imagecolorallocate ($img, hexdec(substr(str_replace('#',"",$schriftfarbe),0,2)), hexdec(substr(str_replace('#',"",$schriftfarbe),2,2)), hexdec(substr(str_replace('#',"",$schriftfarbe),4,2)));
$txt2color = imagecolorallocate ($img, hexdec(substr(str_replace('#',"",$schattenfarbe),0,2)), hexdec(substr(str_replace('#',"",$schattenfarbe),2,2)), hexdec(substr(str_replace('#',"",$schattenfarbe),4,2)));
$bocolor = imagecolorallocate ($img, 0, 0, 0);
$schcolor = imagecolorallocate ($img, 255, 255, 255);
$schatten1color = imagecolorallocate ($img, 235, 235, 235);
$schatten2color = imagecolorallocate ($img, 219, 219, 219);

$smiliefarbe = imagecolorsforindex($smilie, imagecolorat($smilie, 5, 14));

imagesetpixel($schild, 1, 14, imagecolorallocate($schild, ($smiliefarbe['red'] + 52), ($smiliefarbe['green'] + 59), ($smiliefarbe['blue'] + 11)));
imagesetpixel($schild, 2, 14, imagecolorallocate($schild, ($smiliefarbe['red'] + 50), ($smiliefarbe['green'] + 52), ($smiliefarbe['blue'] + 50)));
imagesetpixel($schild, 1, 15, imagecolorallocate($schild, ($smiliefarbe['red'] + 50), ($smiliefarbe['green'] + 52), ($smiliefarbe['blue'] + 50)));
imagesetpixel($schild, 2, 15, imagecolorallocate($schild, ($smiliefarbe['red'] + 22), ($smiliefarbe['green'] + 21), ($smiliefarbe['blue'] + 35)));
imagesetpixel($schild, 1, 16, imagecolorat($smilie, 5, 14));
imagesetpixel($schild, 2, 16, imagecolorat($smilie, 5, 14));
imagesetpixel($schild, 5, 16, imagecolorallocate($schild, ($smiliefarbe['red'] + 22), ($smiliefarbe['green'] + 21), ($smiliefarbe['blue'] + 35)));
imagesetpixel($schild, 6, 16, imagecolorat($smilie, 5, 14));
imagesetpixel($schild, 5, 15, imagecolorallocate($schild, ($smiliefarbe['red'] + 52), ($smiliefarbe['green'] + 59), ($smiliefarbe['blue'] + 11)));
imagesetpixel($schild, 6, 15, imagecolorallocate($schild, ($smiliefarbe['red'] + 50), ($smiliefarbe['green'] + 52), ($smiliefarbe['blue'] + 50)));


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

header("Content-Type: image/png");
//imagepng($img,'',100);   // 100 = komprimierung
imagepng($img); 
imagedestroy($img);
imagedestroy($schild);
imagedestroy($smilie);

exit;
?>
