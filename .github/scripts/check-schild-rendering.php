<?php
// Actual GD text/layout plus isolated whole-endpoint PNG/status/failure cases.
if(PHP_SAPI!=='cli'){exit(2);}
define('IN_PHPBB',true);$app=dirname(dirname(__DIR__)).'/phpBB2/';
function sh_source($path){return$GLOBALS['app'].$path;}
require sh_source('includes/functions_schild.php');
if(!extension_loaded('gd')){throw new RuntimeException('Real GD required for the shield rendering fixture');}
error_reporting(E_ALL);set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
$checks=0;$cases=0;
function sh_check($ok,$message){if(!$ok){throw new RuntimeException($message);}$GLOBALS['checks']++;}
function sh_rgb($image,$x,$y){$color=imagecolorsforindex($image,imagecolorat($image,$x,$y));return array($color['red'],$color['green'],$color['blue']);}
function sh_reference($png,$bitmap){
 $image=imagecreatefromstring($png);$width=imagesx($image);$expected=imagecreate($width,40);imagecolorallocate($expected,255,255,255);$ink=imagecolorallocate($expected,0,0,0);
 if($bitmap){$bytes=$bitmap==='ascii'?'Gruesse aus Koeln':"Gr\xfc\xdfe aus K\xf6ln";$left=(int)floor(($width-strlen($bytes)*imagefontwidth(2))/2);imagestring($expected,2,$left,5,$bytes,$ink);$bottom=5+imagefontheight(2);}
 else{$font=sh_source('assets/fonts/DejaVuSans.ttf');$box=imagettfbbox(8,0,$font,'Grüße aus Köln');$left=min($box[0],$box[2],$box[4],$box[6]);$right=max($box[0],$box[2],$box[4],$box[6]);$metrics=imagettfbbox(8,0,$font,'AgÄgj');$ascent=-min($metrics[1],$metrics[3],$metrics[5],$metrics[7]);imagettftext($expected,8,0,(int)floor(($width-($right-$left))/2)-$left,5+$ascent,$ink,$font,'Grüße aus Köln');$bottom=5+$ascent+max($metrics[1],$metrics[3],$metrics[5],$metrics[7])+1;}
 for($y=5;$y<$bottom;$y++){for($x=1;$x<$width-1;$x++){sh_check(sh_rgb($image,$x,$y)===sh_rgb($expected,$x,$y),'Actual endpoint glyphs must equal the intended UTF-8/latin2 reference');}}
 phpbb_gd_release($image);phpbb_gd_release($expected);
}
$samples=array(array('Grüße aus Köln','Grüße aus Köln'),array('Gr&uuml;&szlig;e','Grüße'),array('0','0'),array('C:\new\test','C:\new\test'),array(" one\n\t two\xc2\xa0three ",'one two three'),array('<b>plain</b>','plain'),array("a\0b\x01c",'abc'),array('', 'error'),array(array(),'error'),array(null,'error'),array(false,'error'),array("broken\xff byte", "broken\xef\xbf\xbd byte"));
foreach($samples as$sample){sh_check(phpbb_schild_text($sample[0])===$sample[1],'Bounded raw GET text/entities/UTF-8/slashes/zero/malformed values');$cases++;}
$bounded=phpbb_schild_text(str_repeat('ü',100000));sh_check(preg_match('//u',$bounded)===1&&preg_match_all('/./us',$bounded,$unused)===512,'Oversized input must stop at a complete UTF-8 boundary');$cases++;
sh_check(hash_file('sha256',sh_source('assets/fonts/DejaVuSans.ttf'))==='7da195a74c55bef988d0d48f9508bd5d849425c1770dba5d7bfc6ce9ed848954','Unmodified official DejaVu Sans 2.37 release font');
sh_check(hash_file('sha256',sh_source('assets/fonts/LICENSE-DejaVu.txt'))==='7a083b136e64d064794c3419751e5c7dd10d2f64c108fe5ba161eae5e5958a93','Original upstream font license retained byte-for-byte');
$fonts=array(phpbb_schild_font(sh_source('assets/fonts/DejaVuSans.ttf')),phpbb_schild_font(''));
sh_check($fonts[0]['ttf']&&!$fonts[1]['ttf'],'Native FreeType font and explicit bitmap fallback');
sh_check(phpbb_schild_bitmap_text('Grüße aus Köln')==="Gr\xfc\xdfe aus K\xf6ln",'Bitmap fallback uses latin2 bytes, not raw UTF-8');
foreach($fonts as$font){foreach(array('Grüße aus Köln','0','C:\new\test','Привет κόσμε','Grüße 😀',str_repeat('W',512),str_repeat('ü',512),str_repeat('word ',120),"bad\xff text")as$raw){
 $text=phpbb_schild_text($raw);$lines=phpbb_schild_lines($text,$font);sh_check(count($lines)>=1&&count($lines)<=12,'Bounded line count');
 foreach($lines as$line){$metrics=phpbb_schild_metrics($line,$font);sh_check(preg_match('//u',$line)===1&&preg_match_all('/./us',$line,$unused)<=33&&$metrics[0]<=198,'Complete Unicode characters and pixel bounds in both fonts');}
 $image=imagecreate(204,322);imagecolorallocate($image,255,255,255);$ink=imagecolorallocate($image,0,0,0);$shadow=imagecolorallocate($image,150,150,150);
 sh_check(phpbb_schild_draw_text($image,$lines,$font,204,$ink,$shadow),'Actual GD multiline renderer with shadow');ob_start();imagepng($image);$png=ob_get_clean();sh_check(substr($png,0,8)==="\x89PNG\r\n\x1a\n",'Native rendered PNG');phpbb_gd_release($image);$cases++;
}}
$image=imagecreate(2,2);sh_check(phpbb_gd_release($image),'Release native GD image');if(PHP_VERSION_ID<80000){sh_check(!is_resource($image)&&!phpbb_gd_release($image),'Legacy resources are closed once');}else{sh_check(imagesx($image)===2&&phpbb_gd_release($image),'Object cleanup is an intentional no-op without deprecation');}$cases++;
$stream=fopen('php://temp','w+b');sh_check(!phpbb_gd_release($stream)&&is_resource($stream)&&!phpbb_gd_release(false)&&!phpbb_gd_release(new stdClass()),'Cleanup must not close unrelated handles or reject false allocations');fclose($stream);$cases++;
$base=sys_get_temp_dir().'/codex_shield_'.bin2hex(phpbb_random_bytes(8));$created=false;$owned=array();
$copyPaths=array('text2schild.php','includes/php_compat.php','includes/functions_username.php','includes/functions_post_author.php','includes/functions_author_visibility.php','includes/functions_schild.php','assets/fonts/DejaVuSans.ttf','smilie_creator/images/smilies/schild/schild.png','smilie_creator/images/smilies/schild/smilie1.png','smilie_creator/images/smilies/schild/smilie2.png');
$child= <<<'PHP'
<?php
if(PHP_SAPI!=='cli'){exit(2);}$case=json_decode(stream_get_contents(STDIN),true);$base=$case['base'];if(!is_string($base)||is_link($base)||realpath(dirname($base))!==realpath(sys_get_temp_dir())||!preg_match('/^codex_shield_[a-f0-9]{16}$/D',basename($base))||!chdir($base.'/app')){exit(3);}
register_shutdown_function(function()use($base){file_put_contents($base.'/report.json',json_encode(array('status'=>http_response_code()?:200)));});
error_reporting(E_ALL);set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});$_GET=$case['query'];require 'text2schild.php';
PHP;
try{
 sh_check(!file_exists($base)&&mkdir($base,0700),'Reserve own isolated image fixture');$created=true;mkdir($base.'/app',0700);$directories=array();
 foreach($copyPaths as$relative){$parent=dirname($relative);$parts=explode('/',$parent);$directory=$base.'/app';foreach($parts as$part){if($part==='.')continue;$directory.='/'.$part;if(!is_dir($directory)){sh_check(mkdir($directory,0700),'Own fixture directory');$directories[]=$directory;}}sh_check(copy(sh_source($relative),$base.'/app/'.$relative),'Copy exact current source/static asset');$owned[]=$base.'/app/'.$relative;}
 sh_check(file_put_contents($base.'/child.php',$child)===strlen($child),'Own fixed child');$owned[]=$base.'/child.php';$owned[]=$base.'/report.json';
 $vectors=array(array('normal',array('text'=>'Grüße aus Köln')),array('bitmap',array('text'=>'Grüße aus Köln')),array('ascii-fallback',array('text'=>'Grüße aus Köln')),array('zero',array('text'=>'0')),array('slashes',array('text'=>'C:\new\test')),array('entities',array('text'=>'Gr&uuml;&szlig;e')),array('multiline',array('text'=>str_repeat('Grüße ',100),'shieldshadow'=>'1','shadowcolor'=>'ff0000')),array('longword',array('text'=>str_repeat('W',8192))),array('malformed',array('text'=>array('bad'),'smilie'=>array(),'fontcolor'=>array(),'shieldshadow'=>array())),array('random',array('text'=>'Привет κόσμε','smilie'=>'random')),array('unknown',array('text'=>'fallback','smilie'=>'999999')),array('no-gd',array('text'=>'safe')),array('no-encoder',array('text'=>'safe')),array('broken-asset',array('text'=>'safe')),array('tiny-asset',array('text'=>'safe')));
 foreach($vectors as$vector){$name=$vector[0];$fontPath=$base.'/app/assets/fonts/DejaVuSans.ttf';$asset=$base.'/app/smilie_creator/images/smilies/schild/schild.png';
  if($name==='bitmap'||$name==='ascii-fallback'){unlink($fontPath);}if($name==='broken-asset'){file_put_contents($asset,'not a PNG');}if($name==='tiny-asset'){$tiny=imagecreate(1,1);imagecolorallocate($tiny,255,255,255);imagepng($tiny,$asset);phpbb_gd_release($tiny);}
  $command=escapeshellarg(PHP_BINARY).' ';
  if(DIRECTORY_SEPARATOR===chr(92)||$name==='no-gd'){$command.='-n ';if($name!=='no-gd'){$command.='-d '.escapeshellarg('extension_dir='.ini_get('extension_dir')).' -d extension='.(PHP_VERSION_ID<80000?'php_gd2.dll':'php_gd.dll').' ';}}
  if($name==='no-encoder'){$command.='-d disable_functions=imagepng ';}
  if($name==='ascii-fallback'){$command.='-d disable_functions=iconv ';}
  $command.='-d display_errors=stderr -d log_errors=0 '.escapeshellarg($base.'/child.php');
  $pipes=array();$process=proc_open($command,array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes,null,null,array('bypass_shell'=>true));sh_check(is_resource($process),'Own endpoint process');$input=json_encode(array('base'=>$base,'query'=>$vector[1]));sh_check(fwrite($pipes[0],$input)===strlen($input),'Exact synthetic request via stdin');fclose($pipes[0]);$png=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
  sh_check($exit===0&&$error==='','Actual endpoint must not emit notices, warnings or deprecations: '.$name.' '.$error);$report=json_decode(file_get_contents($base.'/report.json'),true);
  $status=in_array($name,array('no-gd','no-encoder'),true)?503:(in_array($name,array('broken-asset','tiny-asset'),true)?500:200);sh_check($report['status']===$status,'Actual controlled endpoint status: '.$name);
  if($status===200){$info=getimagesizefromstring($png);sh_check(is_array($info)&&$info[2]===IMAGETYPE_PNG&&$info[0]>=60&&$info[0]<=204&&$info[1]>=46&&$info[1]<=322,'Whole endpoint emits a bounded valid PNG: '.$name);if($name==='normal'||$name==='bitmap'||$name==='ascii-fallback'){sh_reference($png,$name==='ascii-fallback'?'ascii':$name==='bitmap');}}
  else{sh_check(strpos($png,'Smilie ')===0&&strpos($png,'Fatal error')===false,'Failure response is controlled text, not a damaged PNG');}
  if($name==='bitmap'||$name==='ascii-fallback'){copy(sh_source('assets/fonts/DejaVuSans.ttf'),$fontPath);}if($name==='broken-asset'||$name==='tiny-asset'){copy(sh_source('smilie_creator/images/smilies/schild/schild.png'),$asset);}$cases++;
 }
 echo 'Schild rendering: ',$cases,' cases, ',$checks," assertions PASS; actual UTF-8 and bitmap glyphs, bounded native GD PNGs, shadows, malformed input, controlled failures and PHP-compatible cleanup.\n";
}finally{
 if($created){sh_check(!is_link($base)&&realpath(dirname($base))===realpath(sys_get_temp_dir())&&preg_match('/^codex_shield_[a-f0-9]{16}$/D',basename($base)),'Exact owned fixture cleanup');foreach($owned as$file){if(file_exists($file)){sh_check(!is_link($file)&&is_file($file),'Only enumerated own regular files');unlink($file);}}foreach(array_reverse($directories)as$directory){rmdir($directory);}rmdir($base.'/app');rmdir($base);}
 restore_error_handler();
}
