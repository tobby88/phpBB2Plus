<?php
if(PHP_SAPI!=='cli'){exit(2);}
define('IN_PHPBB',true);$table_prefix='fixture_';$app=dirname(dirname(__DIR__)).'/phpBB2/';
require$app.'includes/php_compat.php';require$app.'includes/functions.php';require$app.'album_mod/album_constants.php';require$app.'album_mod/album_functions.php';require$app.'album_mod/album_upload_stage.php';
if(!function_exists('imagecreatetruecolor')){if(getenv('PHPBB_ALBUM_ORIENTATION_GD_REQUIRED')==='1'){throw new RuntimeException('Actual GD orientation checks required');}return;}
$ao_cases=$ao_checks=0;
function ao_check($ok,$message){if(!$ok){throw new RuntimeException($message);}$GLOBALS['ao_checks']++;}
function ao_exif($jpeg,$orientation,$order='II'){
 $short=$order==='II'?'v':'n';$long=$order==='II'?'V':'N';$tiff=$order.pack($short,42).pack($long,8).pack($short,1).pack($short,0x0112).pack($short,3).pack($long,1).pack($short,$orientation)."\0\0".pack($long,0);$data="Exif\0\0".$tiff;
 return substr($jpeg,0,2)."\xff\xe1".pack('n',strlen($data)+2).$data.substr($jpeg,2);
}
function ao_pixels($bytes,$orientation,$width,$height){
 $shape=getimagesizefromstring($bytes);ao_check($shape&&$shape[0]===$width&&$shape[1]===$height&&$shape[2]===IMAGETYPE_JPEG,'Actual output has oriented bounded JPEG dimensions');
 $image=imagecreatefromstring($bytes);ao_check($image!==false,'Actual JPEG output decodes');
 $colors=array(array(230,20,20),array(20,210,20),array(20,20,230),array(230,210,20));
 $maps=array(1=>array(0,1,2,3),2=>array(1,0,3,2),3=>array(3,2,1,0),4=>array(2,3,0,1),5=>array(0,2,1,3),6=>array(2,0,3,1),7=>array(3,1,2,0),8=>array(1,3,0,2));
 foreach(array(array(1,1),array(3,1),array(1,3),array(3,3))as$i=>$point){$pixel=imagecolorsforindex($image,imagecolorat($image,(int)floor($width*$point[0]/4),(int)floor($height*$point[1]/4)));foreach(array('red','green','blue')as$c=>$key){ao_check(abs($pixel[$key]-$colors[$maps[$orientation][$i]][$c])<=32,'Actual corner pixels preserve EXIF rotation/mirroring');}}
 phpbb_gd_release($image);
}
class AlbumOrientationExit extends RuntimeException {}
eval('namespace AlbumOrientationSink; function header($value){} function album_publish_picture_cache($snapshot,$owner,$category,$picture,$names,$image,$type,$medium=false){$GLOBALS["ao_publications"]++;$info=\\phpbb_jpeg_file_info(ALBUM_UPLOAD_PATH.$names["pic_filename"]);return \\album_store_cached_image($image,$medium?ALBUM_MED_CACHE_PATH:ALBUM_CACHE_PATH,$names["pic_filename"],$type,90,function(){return true;},$info["orientation"]);}');
function ao_render($script,$cache,$gd){
 global$album_config,$album_sp_config,$userdata,$images;
 $album_config=array('thumbnail_cache'=>$cache,'thumbnail_size'=>80,'thumbnail_quality'=>90,'gd_version'=>$gd);$album_sp_config=array('midthumb_cache'=>$cache,'midthumb_width'=>70,'midthumb_height'=>45);
 $userdata=array('user_id'=>7);$album_user_id=0;$cat_id=$pic_id=1;$pic_filetype='.jpg';$pic_filename='source.jpg';$pic_thumbnail='legacy.jpg';$thispic=array('pic_filename'=>$pic_filename,'pic_thumbnail'=>$pic_thumbnail);
 $source=file_get_contents($GLOBALS['app'].$script);$at=strpos($source,"if( (\$pic_filetype != '.jpg')");ao_check($at!==false,'Actual complete renderer body');$code=preg_replace('/\?>\s*$/D','',substr($source,$at));$code=str_replace('exit;','throw new \\AlbumOrientationExit();',$code);
 ob_start();try{eval('namespace AlbumOrientationSink; use \\Exception; use \\Error;'.$code);throw new RuntimeException('Actual renderer did not finish');}catch(AlbumOrientationExit$done){}finally{$bytes=ob_get_contents();ob_end_clean();}return$bytes;
}
$base=sys_get_temp_dir().'/codex_album_orientation_'.bin2hex(phpbb_random_bytes(8));$previous=getcwd();$directories=array('/album_mod','/album_mod/upload','/album_mod/upload/cache','/album_mod/upload/med_cache','/album_mod/upload/nuffload','/album_mod/upload/nuffload/tmp');
$relative='album_mod/upload/nuffload/';$psid=str_repeat('a',32);$snapshot=array('user_id'=>7,'session_id'=>str_repeat('b',32));$stage=null;$created=false;$owned=array();
$reporting=error_reporting(E_ALL);set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{
 ao_check(!file_exists($base)&&mkdir($base,0700),'Own fresh image root');$created=true;foreach($directories as$directory){ao_check(mkdir($base.$directory,0700),'Own image directory');}chdir($base);
 $owner=$relative.'tmp/'.$psid.'_owner';$original=$relative.'tmp/'.$psid.'_actualdata0';$owned=array($owner,$original,'album_mod/upload/source.jpg','album_mod/upload/cache/source.jpg','album_mod/upload/cache/legacy.jpg','album_mod/upload/med_cache/source.jpg','album_mod/upload/med_cache/legacy.jpg');file_put_contents($owner,album_nuffload_owner_token($snapshot));
 $image=imagecreatetruecolor(96,48);foreach(array(array(0,0,47,23,0xe61414),array(48,0,95,23,0x14d214),array(0,24,47,47,0x1414e6),array(48,24,95,47,0xe6d214))as$r){imagefilledrectangle($image,$r[0],$r[1],$r[2],$r[3],$r[4]);}ob_start();ao_check(imagejpeg($image,null,98),'Own quadrant JPEG');$plain=ob_get_clean();phpbb_gd_release($image);
 foreach(array('II','MM')as$order){foreach(range(1,8)as$orientation){
  $jpeg=ao_exif($plain,$orientation,$order);$info=phpbb_jpeg_data_info($jpeg);ao_check($info['orientation']===$orientation&&$info['cache_orientation']===0,'Both endian EXIF headers parsed');file_put_contents($original,$jpeg);file_put_contents('album_mod/upload/source.jpg',$jpeg);$original_hash=hash('sha256',$jpeg);
  ao_check(phpbb_jpeg_file_info($original)===$info,'File and in-memory metadata agree');
  foreach(array(1,2)as$gd){
   $stage=new AlbumUploadStage($snapshot,$relative,$psid,$original,null,array('gd_version'=>$gd,'thumbnail_cache'=>1,'thumbnail_size'=>80,'thumbnail_quality'=>90),$base);$small=$stage->small();ao_check($small!==null,'Actual upload stage produces oriented derivative');$bytes=stream_get_contents($small['stream']);$size=$orientation>=5?array(40,80):array(80,40);ao_pixels($bytes,$orientation,$size[0],$size[1]);
   $derived=phpbb_jpeg_data_info($bytes);ao_check($derived['orientation']===1&&$derived['cache_orientation']===($orientation===1?0:$orientation),'Physical pixels normalized and only private cache version retained');ao_check(hash_file('sha256',$original)===$original_hash&&$stage->original()['metadata']['sha256']===$original_hash,'Actual original stream and bytes remain exact');$stage->release();$stage=null;$ao_cases++;
   foreach(array('album_thumbnail.php','album_picm.php')as$script){foreach(array(0,1)as$cache){
    $directory=$script==='album_thumbnail.php'?ALBUM_CACHE_PATH:ALBUM_MED_CACHE_PATH;foreach(array('source.jpg','legacy.jpg')as$name){if(is_file($directory.$name)){unlink($directory.$name);}}
    $GLOBALS['ao_publications']=0;$bytes=ao_render($script,$cache,$gd);$size=$script==='album_thumbnail.php'?array(80,40):array(70,35);if($orientation>=5){$size=$script==='album_thumbnail.php'?array(40,80):array(23,45);}ao_pixels($bytes,$orientation,$size[0],$size[1]);ao_check(hash_file('sha256','album_mod/upload/source.jpg')===$original_hash,'Actual renderer preserves original');
    if($cache){ao_check($GLOBALS['ao_publications']===1&&album_cached_image_valid($directory,'source.jpg','.jpg',$script==='album_thumbnail.php'?80:70,$script==='album_thumbnail.php'?80:45,$orientation),'Actual renderer publishes reusable oriented cache');$cached=file_get_contents($directory.'source.jpg');$again=ao_render($script,$cache,$gd);ao_check($again===$cached&&$GLOBALS['ao_publications']===1,'Warm actual renderer serves the cache without rebuilding');}
    $ao_cases++;
   }}
  }
 }}
 // Existing unversioned caches for an oriented original must regenerate.
 $jpeg=ao_exif($plain,6);file_put_contents('album_mod/upload/source.jpg',$jpeg);foreach(array(ALBUM_CACHE_PATH,ALBUM_MED_CACHE_PATH)as$directory){foreach(array('source.jpg','legacy.jpg')as$name){if(is_file($directory.$name)){unlink($directory.$name);}}file_put_contents($directory.'legacy.jpg',$plain);ao_check(!album_cached_image_valid($directory,'legacy.jpg','.jpg',125,125,6),'Old unoriented cache rejected');}
 foreach(array('album_thumbnail.php','album_picm.php')as$script){$GLOBALS['ao_publications']=0;$bytes=ao_render($script,1,2);$size=$script==='album_thumbnail.php'?array(40,80):array(23,45);ao_pixels($bytes,6,$size[0],$size[1]);ao_check($GLOBALS['ao_publications']===1,'Actual old-cache renderer regenerates');$ao_cases++;}
 // Manual GD-disabled uploads continue to supply their own unchanged bytes.
 $image=imagecreatefromstring($plain);$raster=imagecreatetruecolor(80,40);imagecopyresampled($raster,$image,0,0,0,0,80,40,96,48);$manual=phpbb_orient_image($raster,6);ob_start();imagejpeg($manual,null,90);$manual_bytes=ob_get_clean();phpbb_gd_release($manual);phpbb_gd_release($raster);phpbb_gd_release($image);file_put_contents(ALBUM_CACHE_PATH.'legacy.jpg',$manual_bytes);$GLOBALS['ao_publications']=0;$bytes=ao_render('album_thumbnail.php',1,0);ao_check($bytes===$manual_bytes&&$GLOBALS['ao_publications']===0,'Manual supplied thumbnail stays byte-exact');$ao_cases++;
 // Execute the complete legacy resizer function, not a reimplementation.
 $source=file_get_contents($app.'album_nuffload.php');ao_check(preg_match('/^function resize_image\(.*?^\}/ms',$source,$matches)===1,'Actual complete Nuffload resizer');eval($matches[0]);ao_check(preg_match('/^function gdVersion\(.*?^\}/ms',$source,$matches)===1,'Actual GD mode selector');eval($matches[0]);$resize_path='album_mod/upload/resize.jpg';$owned[]=$resize_path;
 foreach(array(1,2)as$gd){gdVersion($gd);foreach(range(1,8)as$orientation){file_put_contents($resize_path,ao_exif($plain,$orientation));ao_check(resize_image($resize_path,70,45,90)==='image/jpeg','Actual legacy resizer succeeds');$bytes=file_get_contents($resize_path);$size=$orientation>=5?array(23,45):array(70,35);ao_pixels($bytes,$orientation,$size[0],$size[1]);ao_check(phpbb_jpeg_data_info($bytes)===array('orientation'=>1,'cache_orientation'=>0),'Explicit legacy resize normalizes pixels without retaining EXIF');$ao_cases++;}}
 // Reject malformed offsets/types/counts without scanning image payload.
 foreach(array('II','MM')as$order){$valid=ao_exif($plain,6,$order);$segment=substr($valid,6,32);$bad=array('',"Exif\0\0",substr($segment,0,13),substr_replace($segment,'ZZ',6,2),substr_replace($segment,"\0\0",8,2),substr_replace($segment,"\xff\xff\xff\xff",10,4),substr_replace($segment,"\xff\xff",14,2),substr_replace($segment,"\0\0",18,2),substr_replace($segment,"\xff\xff\xff\xff",20,4),substr_replace($segment,"\0\0",24,2));foreach($bad as$data){ao_check(phpbb_jpeg_exif_orientation($data)===1,'Malformed TIFF metadata defaults safely');$ao_cases++;}}
 foreach(array('',"\xff\xd8",substr($jpeg,0,5),"\xff\xd8\xff\xe1\0\1","\xff\xd8\xff\xe1\xff\xff","\xff\xd8\xff\xda\0\0".substr($jpeg,2))as$data){ao_check(phpbb_jpeg_data_info($data)===array('orientation'=>1,'cache_orientation'=>0),'Truncated/invalid header or entropy payload is not parsed as EXIF');$ao_cases++;}
 $reads=array();$header="\xff\xd8\xff\xe0".pack('n',65535).str_repeat('x',65533).substr($jpeg,2);$info=phpbb_jpeg_read_info(function($at,$size)use($header,&$reads){$reads[]=$size;return substr($header,$at,$size);},strlen($header));ao_check($info['orientation']===6&&array_sum($reads)<1000,'Large unrelated APP segment is skipped, not loaded');$ao_cases++;
 $calls=0;ao_check(phpbb_jpeg_read_info(function()use(&$calls){$calls++;return '';},67108865)===array('orientation'=>1,'cache_orientation'=>0)&&$calls===0,'Oversized source rejected before reading');$ao_cases++;
 foreach(array(0,9,'6',null)as$invalid){ao_check(phpbb_orient_image(null,$invalid)===false&&phpbb_jpeg_cache_marker($invalid)===false&&phpbb_jpeg_mark_cache_data($plain,$invalid)===false,'Invalid transform/marker orientation is refused');$ao_cases++;}
 $image=imagecreatefromstring($plain);$limit=ini_get('memory_limit');try{ao_check(ini_set('memory_limit',(string)(memory_get_usage(true)+1048576))!==false,'Own bounded memory limit');ao_check(phpbb_orient_image($image,5)===false,'Rotation budget refuses before mutating the raster');ob_start();imagejpeg($image,null,98);$unchanged=ob_get_clean();ao_pixels($unchanged,1,96,48);$ao_cases++;}finally{ini_set('memory_limit',$limit);phpbb_gd_release($image);}
 $outside='album_mod/upload/unowned.jpg';$owned[]=$outside;file_put_contents($outside,$plain);ao_check(phpbb_jpeg_mark_cache_file($outside,6)===false&&file_get_contents($outside)===$plain,'Cache marker cannot overwrite a non-stage file');$ao_cases++;
 ao_check(glob(ALBUM_CACHE_PATH.'.album-cache-*')===array()&&glob(ALBUM_MED_CACHE_PATH.'.album-cache-*')===array(),'No owned cache stages remain');
 echo 'Album JPEG orientation: ',$ao_cases,' cases, ',$ao_checks,' assertions PASS; all eight orientations, both TIFF byte orders/GD modes, actual upload, resizer and small/medium render bodies, cold/warm/legacy/manual caches, malformed headers, bounded reads/memory, source bytes and owned cleanup; no database or production access.',"\n";
}finally{
 if($stage){$stage->release();}if($created){foreach($owned as$file){if(is_file($file)){unlink($file);}}chdir($previous);foreach(array_reverse($directories)as$directory){rmdir($base.$directory);}rmdir($base);}restore_error_handler();error_reporting($reporting);
}
