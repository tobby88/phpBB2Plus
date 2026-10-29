<?php
if (PHP_SAPI !== 'cli') { exit(2); }
define('IN_PHPBB',true); $table_prefix='fixture_'; $root=dirname(dirname(__DIR__)).'/phpBB2/';
require $root.'includes/php_compat.php'; require $root.'album_mod/album_constants.php'; require $root.'album_mod/album_functions.php'; require $root.'album_mod/album_upload_stage.php';
$common=file_get_contents($root.'includes/functions.php'); if(!preg_match('/^function phpbb_image_dimensions_safe\(.*?^\}/ms',$common,$match)){throw new RuntimeException('Actual image dimensions policy');} eval($match[0]);
function aus_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
function aus_denied($callback,$message){$denied=false;try{$callback();}catch(AlbumUploadFailure$error){$denied=true;}aus_check($denied,$message);$GLOBALS['cases']++;}
function aus_image($type,$width,$height)
{
    $image=imagecreatetruecolor($width,$height); aus_check($image!==false,'Own GD input'); imagefilledrectangle($image,0,0,$width-1,$height-1,0x2070aa); ob_start();
    $ok=$type==='.jpg'?imagejpeg($image,null,90):imagepng($image);$bytes=ob_get_clean();if(PHP_VERSION_ID<80000){imagedestroy($image);}aus_check($ok&&is_string($bytes),'Complete own encoded input');return$bytes;
}
$base=sys_get_temp_dir().'/codex_album_stage_'.bin2hex(phpbb_random_bytes(8));$relative='album_mod/upload/nuffload/';$psid=str_repeat('a',32);$sid=str_repeat('b',32);
$snapshot=array('user_id'=>7,'session_id'=>$sid);$policy=array('gd_version'=>0,'thumbnail_cache'=>1,'thumbnail_size'=>125,'thumbnail_quality'=>80);
$directories=array('/album_mod','/album_mod/upload','/album_mod/upload/cache','/album_mod/upload/med_cache','/album_mod/upload/nuffload','/album_mod/upload/nuffload/tmp');
$tmp=$base.'/'.$relative.'tmp/';$original=$relative.'tmp/'.$psid.'_actualdata0';$supplied=$relative.'tmp/'.$psid.'_actualdata1';$owned_files=array($psid.'_owner',$psid.'_actualdata0',$psid.'_actualdata1',str_repeat('c',32).'_actualdata0');
$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jY1sAAAAASUVORK5CYII=');$gif=base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==');
$gd=function_exists('imagecreatetruecolor')&&function_exists('imagecreatefromstring')&&function_exists('imagejpeg')&&function_exists('imagepng');
if(getenv('PHPBB_ALBUM_UPLOAD_STAGE_GD_REQUIRED')==='1'){aus_check($gd,'Native GD required for supplied/generation compatibility');}
if($gd){$png=aus_image('.png',400,200);}$stage=null;$created=false;$cases=0;$finals=array();$links=0;
$reporting=error_reporting(E_ALL);set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{
    aus_check(!file_exists($base)&&mkdir($base,0700),'Own upload-stage root');$created=true;foreach($directories as$directory){aus_check(mkdir($base.$directory,0700),'Own upload-stage directory');}
    aus_check(file_put_contents($tmp.$psid.'_owner',album_nuffload_owner_token($snapshot))===64,'Own session capability');
    aus_check(file_put_contents($base.'/'.$original,$png)===strlen($png)&&file_put_contents($base.'/'.$supplied,$png)===strlen($png),'Own original and supplied thumbnail');
    aus_check(file_put_contents($tmp.str_repeat('c',32).'_actualdata0',$png)===strlen($png),'Foreign synthetic item control');
    $stage=new AlbumUploadStage($snapshot,$relative,$psid,$original,$supplied,$policy,$base);$artifacts=$stage->artifacts();$operation=$stage->operation();
    aus_check(count($artifacts)===2&&$artifacts[0]['scope']==='original'&&$artifacts[1]['scope']==='small'&&$stage->provided()['sha256']===hash('sha256',$png)&&$stage->owner_matches($snapshot),'Supplied thumbnail mode retains exact source hash');$cases++;
    foreach($artifacts as$artifact){aus_check(AlbumFileStorage::metadata($artifact['metadata'])&&is_resource($artifact['stream'])&&$artifact['metadata']['sha256']===hash('sha256',$png),'Regular source streams and complete metadata');$cases++;}
    aus_check(!is_file($base.'/album_mod/upload/'.$operation.'.png')&&!is_file($base.'/album_mod/upload/cache/'.$operation.'.png'),'Preprocessing writes no final images');$cases++;
    $again=new AlbumUploadStage($snapshot,$relative,$psid,$original,$supplied,$policy,$base);aus_check($again->operation()===$operation&&$again->capability()===$stage->capability(),'Stable operation/capability across retries');$again->release();$cases++;
    $files=new AlbumFileStorage(function(){return true;},$base);foreach($artifacts as$artifact){$final=$base.'/album_mod/upload/'.($artifact['scope']==='small'?'cache/':'').$artifact['name'];$finals[]=$final;aus_check($files->publish($operation,$artifact['scope'],$artifact['name'],$artifact['stream'],$artifact['metadata']),'Actual receipt primitive accepts source/preprocessing streams');$cases++;}
    $stage->release();aus_check(!$stage->owner_matches($snapshot)&&!is_resource($artifacts[0]['stream'])&&hash_file('sha256',$base.'/'.$original)===hash('sha256',$png),'Release closes owned streams and preserves source bytes');$stage=null;$cases++;
    foreach($finals as$final){aus_check(unlink($final),'Remove exact own published fixture image');}$finals=array();
    foreach(array(array('user_id'=>8,'session_id'=>$sid),array('user_id'=>7,'session_id'=>str_repeat('c',32)),array('user_id'=>7,'session_id'=>'bad'))as$wrong){aus_denied(function()use($wrong,$relative,$psid,$original,$supplied,$policy,$base){new AlbumUploadStage($wrong,$relative,$psid,$original,$supplied,$policy,$base);},'Wrong owner/session cannot inspect staged source');}
    foreach(array('',null,array(),'php://memory','../foreign.jpg',$relative.'tmp/'.str_repeat('c',32).'_actualdata0')as$bad){aus_denied(function()use($snapshot,$relative,$psid,$bad,$supplied,$policy,$base){new AlbumUploadStage($snapshot,$relative,$psid,$bad,$supplied,$policy,$base);},'Staged source is exact own regular item, never stream/foreign path');}
    foreach(array('../bad/','/tmp/','https://example.invalid/',array())as$bad){aus_denied(function()use($snapshot,$bad,$psid,$original,$supplied,$policy,$base){new AlbumUploadStage($snapshot,$bad,$psid,$original,$supplied,$policy,$base);},'Configured stage root canonical and relative');}
    foreach(array(array('gd_version',3),array('thumbnail_cache',2),array('thumbnail_size',0),array('thumbnail_size',10001),array('thumbnail_quality',101))as$change){$bad=$policy;$bad[$change[0]]=$change[1];aus_denied(function()use($snapshot,$relative,$psid,$original,$supplied,$bad,$base){new AlbumUploadStage($snapshot,$relative,$psid,$original,$supplied,$bad,$base);},'Typed preprocessing policy bounded');}
    file_put_contents($base.'/'.$original,'not an image');aus_denied(function()use($snapshot,$relative,$psid,$original,$supplied,$policy,$base){new AlbumUploadStage($snapshot,$relative,$psid,$original,$supplied,$policy,$base);},'Invalid actual image header rejected');file_put_contents($base.'/'.$original,$png);
    file_put_contents($base.'/'.$supplied,$gif);aus_denied(function()use($snapshot,$relative,$psid,$original,$supplied,$policy,$base){new AlbumUploadStage($snapshot,$relative,$psid,$original,$supplied,$policy,$base);},'Supplied thumbnail must match image type');file_put_contents($base.'/'.$supplied,$png);
    file_put_contents($tmp.$psid.'_owner',str_repeat('0',64));aus_denied(function()use($snapshot,$relative,$psid,$original,$supplied,$policy,$base){new AlbumUploadStage($snapshot,$relative,$psid,$original,$supplied,$policy,$base);},'Changed stored capability refused');file_put_contents($tmp.$psid.'_owner',album_nuffload_owner_token($snapshot));
    $generated=$policy;$generated['gd_version']=2;file_put_contents($base.'/'.$original,$gif);$stage=new AlbumUploadStage($snapshot,$relative,$psid,$original,null,$generated,$base);aus_check($stage->small()===null&&count($stage->artifacts())===1,'GIF stays original-only in GD modes');$stage->release();$stage=null;$cases++;
    file_put_contents($base.'/'.$original,$png);$generated['thumbnail_cache']=0;$stage=new AlbumUploadStage($snapshot,$relative,$psid,$original,null,$generated,$base);aus_check($stage->small()===null,'Disabled cache produces no thumbnail');$stage->release();$stage=null;$cases++;
    if($gd){foreach(array(1,2)as$version){foreach(array('.png','.jpg')as$type){foreach(array(array(400,200),array(150,350),array(60,40))as$dimensions){$bytes=aus_image($type,$dimensions[0],$dimensions[1]);file_put_contents($base.'/'.$original,$bytes);$generated['thumbnail_cache']=1;$generated['gd_version']=$version;$stage=new AlbumUploadStage($snapshot,$relative,$psid,$original,null,$generated,$base);$small=$stage->small();aus_check($small!==null&&$small['width']<=125&&$small['height']<=125&&$small['width']<=$dimensions[0]&&$small['height']<=$dimensions[1],'Actual GD1/2 JPEG/PNG bounded thumbnail without upscale');$encoded=stream_get_contents($small['stream']);$shape=getimagesizefromstring($encoded);aus_check($shape[0]===$small['width']&&$shape[1]===$small['height']&&hash('sha256',$encoded)===$small['metadata']['sha256'],'Preprocessed output is a complete encoded image');$stage->release();$stage=null;$cases++;}}}}
    if ($gd) {
        file_put_contents($base.'/'.$original,$png);
        $generated['thumbnail_cache']=1;$generated['gd_version']=2;
        $stage=new AlbumUploadStage($snapshot,$relative,$psid,$original,null,$generated,$base);
        $small=$stage->small();$metadata=stream_get_meta_data($small['stream']);$derivative=$metadata['uri'];
        aus_check(realpath(dirname($derivative))===realpath($tmp)&&preg_match('/^'.$psid.'_derived_[a-f0-9]{32}$/D',basename($derivative))===1,'Generated thumbnail stays in exact recognizable private Nuff namespace');$cases++;
        $stage->release();$stage=null;clearstatcache(true,$derivative);
        aus_check(!file_exists($derivative)&&is_file($base.'/'.$original),'Normal release removes only its complete derivative and retains original');$cases++;
        $stage=new AlbumUploadStage($snapshot,$relative,$psid,$original,null,$generated,$base);
        $small=$stage->small();$metadata=stream_get_meta_data($small['stream']);$derivative=$metadata['uri'];$owned_files[]=basename($derivative);
        rewind($small['stream']);aus_check(fwrite($small['stream'],'changed')===7&&ftruncate($small['stream'],7)&&fflush($small['stream']),'Alter only own fixture derivative');
        $stage->release();$stage=null;
        aus_check(file_get_contents($derivative)==='changed','Release preserves changed derivative instead of deleting unverified bytes');$cases++;
        $recent=$psid.'_derived_'.str_repeat('d',32);$unknown=$psid.'_derived_not-an-operation';$owned_files[]=$recent;$owned_files[]=$unknown;
        file_put_contents($tmp.$recent,'recent');file_put_contents($tmp.$unknown,'unknown');
        aus_check(touch($derivative,time()-7200)&&touch($tmp.$unknown,time()-7200),'Age only exact own synthetic cleanup candidates');clearstatcache();
        album_nuffload_cleanup_temp($tmp);
        aus_check(!file_exists($derivative)&&file_get_contents($tmp.$recent)==='recent'&&file_get_contents($tmp.$unknown)==='unknown'&&is_file($base.'/'.$original),'Age cleanup reclaims abandoned recognized derivative while preserving recent files, unknown names and current sources');$cases++;
    }
    file_put_contents($base.'/'.$original,$png);$guest=array('user_id'=>-1,'session_id'=>$sid);file_put_contents($tmp.$psid.'_owner',album_nuffload_owner_token($guest));$stage=new AlbumUploadStage($guest,$relative,$psid,$original,$supplied,$policy,$base);aus_check($stage->owner_matches($guest),'Guest owner capability supported');$stage->release();$stage=null;$cases++;
    if(function_exists('symlink')){$link=$tmp.$psid.'_actualdata2';if(@symlink($base.'/'.$original,$link)){$owned_files[]=$psid.'_actualdata2';aus_denied(function()use($guest,$relative,$psid,$link,$supplied,$policy,$base){new AlbumUploadStage($guest,$relative,$psid,$link,$supplied,$policy,$base);},'Actual symlink cannot become staged input');$links++;aus_check(unlink($link),'Remove exact owned input link');}elseif(DIRECTORY_SEPARATOR==='/'){throw new RuntimeException('Native POSIX input symlink fixture required');}}
    echo 'Album upload stage: '.$cases.' actual ownership/path/source/hash/GD/supplied-thumbnail/GIF/retry cases PASS; symlinks='.$links."; no database/live data.\n";
}finally{if($stage){$stage->release();}if($created){foreach($finals as$final){if(is_file($final)){unlink($final);}}foreach($owned_files as$name){$path=$tmp.$name;if(is_link($path)||is_file($path)){unlink($path);}}foreach(array_reverse($directories)as$directory){if(is_dir($base.$directory)){rmdir($base.$directory);}}rmdir($base);}restore_error_handler();error_reporting($reporting);}
