<?php
// Native GD regression cases. All image/config data is synthetic; no forum DB.
if (PHP_SAPI !== 'cli') { exit(2); }
define('IN_PHPBB', true);
define('CAPTCHA_CONFIG_TABLE', 'synthetic_captcha_config');
$app = dirname(dirname(__DIR__)) . '/phpBB2/';
require $app . 'includes/php_compat.php';
require $app . 'includes/functions.php';
if (!extension_loaded('gd')) { throw new RuntimeException('Native GD required'); }
error_reporting(E_ALL);
set_error_handler(function ($severity, $message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } });
$checks = $cases = 0;
function wc_check($ok, $message)
{
	if (!$ok) { throw new RuntimeException($message); }
	$GLOBALS['checks']++;
}
function wc_function($source, $name)
{
	wc_check(preg_match('/^function ' . preg_quote($name, '/') . '\(.*?^\}/ms', $source, $match) === 1, 'Actual function boundary: ' . $name);
	return $match[0];
}
$album = file_get_contents($app . 'album_pic.php');
eval(wc_function($album, 'mergePics'));
$captcha = file_get_contents($app . 'includes/usercp_confirm_adv.php');
$helpers = substr($captcha, strpos($captcha, 'function get_rgb('));
$helpers = substr($helpers, 0, strrpos($helpers, '?>'));
$helpers = str_replace('imagestring($image,', 'wc_bitmap($image,', $helpers);
eval($helpers);
$start = strpos($captcha, '$captcha_config = array();');
$end = strpos($captcha, 'phpbb_gd_release($image);', $start);
wc_check($start !== false && $end !== false, 'Actual complete CAPTCHA drawing block');
$body = substr($captcha, $start, $end + strlen('phpbb_gd_release($image);') - $start);
// Only final glyphs are bounded. Background noise may intentionally be clipped.
$body = str_replace('imagettftext($image, $size, $angle,', 'wc_ttf($image, $size, $angle,', $body);
class WcConfigRows
{
	var $rows; var $queries = 0; var $frees = 0;
	function __construct($config) { $this->rows = array(); foreach ($config as $key => $value) { $this->rows[] = array('config_name' => $key, 'config_value' => $value); } }
	function sql_query($sql) { wc_check(preg_match('/^SELECT\s+\*\s+FROM\s+synthetic_captcha_config\s*$/D', $sql) === 1, 'Only synthetic configuration SELECT'); $this->queries++; return (object) array('rows' => $this->rows); }
	function sql_fetchrow($result) { return $result->rows ? array_shift($result->rows) : false; }
	function sql_freeresult($result) { $this->frees++; }
}
function wc_bounds($image, $char, $x, $y, $left, $right, $top, $bottom)
{
	wc_check(is_int($x) && is_int($y), 'Integral native glyph coordinates');
	wc_check($x + $left >= 0 && $x + $right < imagesx($image) && $y + $top >= 0 && $y + $bottom < imagesy($image), 'Complete final glyph stays inside CAPTCHA');
	$GLOBALS['wc_glyphs'][] = $char;
}
function wc_ttf($image, $size, $angle, $x, $y, $color, $font, $char)
{
	$box = imagettfbbox($size, $angle, $font, $char);
	wc_bounds($image, $char, $x, $y, min($box[0], $box[2], $box[4], $box[6]), max($box[0], $box[2], $box[4], $box[6]), min($box[1], $box[3], $box[5], $box[7]), max($box[1], $box[3], $box[5], $box[7]));
	$result = imagettftext($image, $size, $angle, $x, $y, $color, $font, $char);
	wc_check(is_array($result), 'Actual FreeType draw succeeds');
	return $result;
}
function wc_bitmap($image, $font, $x, $y, $char, $color)
{
	wc_bounds($image, $char, $x, $y, 0, imagefontwidth($font) - 1, 0, imagefontheight($font) - 1);
	$result = imagestring($image, $font, $x, $y, $char, $color);
	wc_check($result, 'Actual built-in glyph draw succeeds');
	return $result;
}
function wc_image($path, $width, $height, $jpeg, $mark)
{
	$image = imagecreatetruecolor($width, $height);
	imagefill($image, 0, 0, imagecolorallocate($image, $mark ? 220 : 20, 50, $mark ? 30 : 210));
	imageline($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, 0, 200, 70));
	wc_check($jpeg ? imagejpeg($image, $path, 90) : imagepng($image, $path), 'Own native source image');
	phpbb_gd_release($image);
}
$base = sys_get_temp_dir() . '/phpbb_wc_' . bin2hex(phpbb_random_bytes(8));
$created = false;
$source = $base . '/source'; $mark = $base . '/mark'; $bad = $base . '/bad';
$child = <<<'PHP'
<?php
if (PHP_SAPI !== 'cli') { exit(2); }
$input = explode("\n", stream_get_contents(STDIN));
if (count($input) !== 3) { exit(3); }
$app = base64_decode($input[0], true);
define('IN_PHPBB', true);
require $app . 'includes/php_compat.php';
require $app . 'includes/functions.php';
error_reporting(E_ALL);
set_error_handler(function ($severity, $message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } });
if (preg_match('/^function mergePics\(.*?^\}/ms', file_get_contents($app . 'album_pic.php'), $function) !== 1) { exit(4); }
eval($function[0]);
ob_start();
$ok = mergePics(base64_decode($input[1], true), base64_decode($input[2], true), 0, 50, '.png');
$output = ob_get_clean();
if ($ok !== false || $output !== '') { exit(5); }
echo "PASS";
PHP;
try
{
	wc_check(!file_exists($base) && mkdir($base, 0700), 'Exclusive own fixture directory'); $created = true;
	mkdir($base . '/captcha', 0700); mkdir($base . '/captcha/fonts', 0700);
	file_put_contents($base . '/index.php', '<?php // Synthetic font-free root.');
	foreach (array(false, true) as $jpeg)
	{
		foreach (array(array(16, 12, 5, 3), array(17, 13, 6, 4), array(16, 12, 21, 15)) as $shape)
		{
			wc_image($source, $shape[0], $shape[1], $jpeg, false); wc_image($mark, $shape[2], $shape[3], false, true);
			$source_hash = hash_file('sha256', $source); $mark_hash = hash_file('sha256', $mark);
			$dx = $shape[0] - $shape[2]; $dy = $shape[1] - $shape[3];
			$positions = array(array((int) ($dx / 2), (int) ($dy / 2)), array(0, 0), array($dx, 0), array($dx, $dy), array(0, $dy), array((int) ($dx / 2), 0), array($dx, (int) ($dy / 2)), array((int) ($dx / 2), $dy), array(0, (int) ($dy / 2)));
			foreach ($positions as $position => $offset)
			{
				foreach (array(0, 50, 100) as $opacity)
				{
					$expected = $jpeg ? imagecreatefromjpeg($source) : imagecreatefrompng($source); $watermark = imagecreatefrompng($mark);
					wc_check(imagecopymerge($expected, $watermark, $offset[0], $offset[1], 0, 0, $shape[2], $shape[3], $opacity), 'Independent native reference merge');
					ob_start(); if ($jpeg) { imagejpeg($expected, null, 75); } else { imagepng($expected); } $reference = ob_get_clean();
					phpbb_gd_release($expected); phpbb_gd_release($watermark);
					ob_start(); $ok = mergePics($source, $mark, (string) $position, (string) $opacity, $jpeg ? '.jpg' : '.png'); $actual = ob_get_clean();
					wc_check($ok === true && $actual === $reference, 'All nine watermark placements/opacity/negative truncation match actual native pixels');
					$info = getimagesizefromstring($actual);
					wc_check($info[0] === $shape[0] && $info[1] === $shape[1] && $info[2] === ($jpeg ? IMAGETYPE_JPEG : IMAGETYPE_PNG), 'Complete original dimensions and format');
					wc_check(hash_file('sha256', $source) === $source_hash && hash_file('sha256', $mark) === $mark_hash, 'Rendering never modifies either original'); $cases++;
				}
			}
		}
	}
	wc_image($source, 16, 12, false, false); wc_image($mark, 5, 3, false, true);
	file_put_contents($base . '/child.php', $child);
	foreach (array('no-gd', 'imagepng', 'imagecreatefrompng', 'imagecopymerge') as $disabled)
	{
		$command = escapeshellarg(PHP_BINARY) . ' ';
		if (DIRECTORY_SEPARATOR === chr(92) || $disabled === 'no-gd')
		{
			$command .= '-n ';
			if ($disabled !== 'no-gd') { $command .= '-d ' . escapeshellarg('extension_dir=' . ini_get('extension_dir')) . ' -d extension=' . (PHP_VERSION_ID < 80000 ? 'php_gd2.dll' : 'php_gd.dll') . ' '; }
		}
		if ($disabled !== 'no-gd') { $command .= '-d disable_functions=' . $disabled . ' '; }
		$command .= '-d display_errors=stderr -d log_errors=0 ' . escapeshellarg($base . '/child.php');
		$process = proc_open($command, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, null, null, array('bypass_shell' => true));
		wc_check(is_resource($process), 'Own isolated unavailable-GD/encoder process');
		$input = base64_encode($app) . "\n" . base64_encode($source) . "\n" . base64_encode($mark);
		wc_check(fwrite($pipes[0], $input) === strlen($input), 'Exact synthetic source paths'); fclose($pipes[0]);
		$output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
		wc_check($exit === 0 && $error === '' && $output === 'PASS', 'Unavailable image support fails without warnings or unmarked output: ' . $disabled . ' ' . $error); $cases++;
	}
	ob_start(); $ok = mergePics($source, $mark, 'invalid', 50, '.png'); $center = ob_get_clean();
	ob_start(); $center_ok = mergePics($source, $mark, 0, 50, '.png'); $expected = ob_get_clean();
	wc_check($ok && $center_ok && $center === $expected, 'Unknown stored positions use the center'); $cases++;
	file_put_contents($bad, 'not an image');
	$invalid = array(array($source, $bad, 0, 50, '.png'), array($bad, $mark, 0, 50, '.png'), array($source, $mark, 0, 50, '.jpg'), array($source, $mark, 0, 50, '.gif'), array($base . '/missing', $mark, 0, 50, '.png'), array(array(), $mark, 0, 50, '.png'), array($source . "\0", $mark, 0, 50, '.png'), array('file://' . $source, $mark, 0, 50, '.png'), array($source, $mark, 0, array(), '.png'));
	foreach ($invalid as $vector) { ob_start(); $ok = call_user_func_array('mergePics', $vector); $output = ob_get_clean(); wc_check($ok === false && $output === '', 'Invalid sources/options fail without original or partial output'); $cases++; }
	// Header-readable PNGs which cannot be decoded, and dimensions over budget.
	foreach (array(array(16, 12), array(5000, 5000), array(10001, 1)) as $shape)
	{
		file_put_contents($bad, "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NN', $shape[0], $shape[1]) . "\x08\x02\0\0\0" . pack('N', 0));
		wc_check(is_array(getimagesize($bad)), 'Actual metadata parser sees corrupt/oversized PNG header');
		ob_start(); $ok = mergePics($bad, $mark, 0, 50, '.png'); $output = ob_get_clean(); wc_check(!$ok && $output === '', 'Decode and dimension failures return no unmarked/partial image'); $cases++;
	}
	$old_limit = ini_get('memory_limit');
	try
	{
		wc_check(ini_set('memory_limit', (string) (memory_get_usage(true) + 8 * 1048576)) !== false, 'Set own temporary low memory budget');
		ob_start(); $ok = mergePics($source, $mark, 0, 50, '.png'); $output = ob_get_clean(); wc_check(!$ok && $output === '', 'Reject decoding without safe memory headroom'); $cases++;
	}
	finally { ini_set('memory_limit', $old_limit); }
	foreach (array(array(119, 39), array(120, 400), array(320, 80), array(1001, 401), array(1000, 40), array(121, 41)) as $shape)
	{
		foreach (array('W', 'SAMPLE', 'ABMW1290IJKL') as $challenge)
		{
			foreach (array(false, true) as $bitmap)
			{
				foreach (array(false, true) as $jpeg)
				{
					$config = array('width' => $shape[0], 'height' => $shape[1], 'font' => 1, 'jpeg' => $jpeg ? 1 : 0);
					if ($jpeg) { $config += array('pre_letters' => 5, 'pre_letters_great' => $bitmap ? 0 : 1, 'chess' => 1, 'ellipses' => 1, 'arcs' => 1, 'lines' => 1, 'foreground_lattice_x' => 11, 'foreground_lattice_y' => 13, 'gammacorrect' => 1.1); }
					$db = new WcConfigRows($config); $code = $challenge; $font_debug = false; $phpEx = 'php'; $phpbb_root_path = $bitmap ? $base . '/' : $app; $wc_glyphs = array();
					mt_srand(2 + $cases); ob_start(); eval($body); $output = ob_get_clean();
					wc_check($db->queries === 1 && $db->frees === 1 && $db->rows === (new WcConfigRows($config))->rows && $code === $challenge, 'Read-only config/challenge remain unchanged');
					wc_check($use_ttf === !$bitmap, 'Actual installed fonts or empty-font-directory fallback');
					$info = getimagesizefromstring($output);
					wc_check($info[0] === max(120, min(1000, $shape[0])) && $info[1] === max(40, min(400, $shape[1])) && $info[2] === ($jpeg ? IMAGETYPE_JPEG : IMAGETYPE_PNG), 'Complete configured CAPTCHA image');
					$expected_glyphs = array(); foreach (str_split($challenge) as $letter) { $expected_glyphs[] = $letter; $expected_glyphs[] = $letter; $expected_glyphs[] = $letter; }
					wc_check($wc_glyphs === $expected_glyphs, 'Every challenge character has three complete final layers in order'); $cases++;
				}
			}
		}
	}
	echo 'Watermark/CAPTCHA rendering: ', $cases, ' cases, ', $checks, " assertions PASS; native PNG/JPEG pixels, bounds, warning-free glyphs, memory/corruption failures and unchanged originals/config.\n";
}
finally
{
	if ($created)
	{
		wc_check(!is_link($base) && realpath(dirname($base)) === realpath(sys_get_temp_dir()) && preg_match('/^phpbb_wc_[a-f0-9]{16}$/D', basename($base)), 'Only own temporary fixture cleanup');
		foreach (array($source, $mark, $bad, $base . '/index.php', $base . '/child.php') as $file) { if (is_file($file)) { wc_check(!is_link($file), 'Only enumerated own files'); unlink($file); } }
		rmdir($base . '/captcha/fonts'); rmdir($base . '/captcha'); rmdir($base);
	}
	restore_error_handler();
}
