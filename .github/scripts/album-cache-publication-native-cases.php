<?php
require_once $root.'album_mod/album_cache_publication.php';
if(!function_exists('phpbb_image_dimensions_safe')){$common=file_get_contents($root.'includes/functions.php');ad_check(preg_match('/^function phpbb_image_dimensions_safe\(.*?^\}/ms',$common,$function)===1,'Actual image allocation policy');eval($function[0]);}

function ad_cache_frame($medium=false,$type='.png')
{
	ad_reset();
	ad_sql("UPDATE fixture_album SET pic_filename='one".$type."',pic_thumbnail='thumb".$type."' WHERE pic_id=1");
	ad_sql("UPDATE fixture_album_config SET config_value='10' WHERE config_name='thumbnail_size'");
	ad_sql("UPDATE fixture_album_config SET config_value='1' WHERE config_name='thumbnail_cache'");
	ad_sql("UPDATE fixture_album_config SET config_value='80' WHERE config_name='thumbnail_quality'");
	ad_sql("INSERT INTO fixture_album_sp_config VALUES('midthumb_cache','1'),('midthumb_width','8'),('midthumb_height','8')");
	foreach(array('cache','med_cache')as$dir){foreach(array('one.png','one.jpg','thumb.png','thumb.jpg')as$file){$path=$GLOBALS['cache_base'].'/album_mod/upload/'.$dir.'/'.$file;if(is_file($path)){unlink($path);}}}
	$source=imagecreatetruecolor(20,10);$color=imagecolorallocate($source,60,110,180);imagefill($source,0,0,$color);
	$path=$GLOBALS['cache_base'].'/album_mod/upload/one'.$type;
	ad_check($type==='.jpg'?imagejpeg($source,$path,90):imagepng($source,$path),'Own complete cache source');
	if(is_resource($source)){imagedestroy($source);}
	$size=$medium?array(8,4):array(10,5);$image=imagecreatetruecolor($size[0],$size[1]);$color=imagecolorallocate($image,60,110,180);imagefill($image,0,0,$color);
	$GLOBALS['cache_type']=$type;$GLOBALS['cache_medium']=$medium;$GLOBALS['cache_image']=$image;$GLOBALS['cache_names']=array('pic_filename'=>'one'.$type,'pic_thumbnail'=>'thumb'.$type);
	$GLOBALS['db']->reset_case();
}
function ad_cache_publish($writer=null)
{
	if($writer===null){$writer=new AlbumCachePublication($GLOBALS['db'],'fixture_',$GLOBALS['cache_base']);}
	return $writer->publish($GLOBALS['userdata'],0,1,1,$GLOBALS['cache_names'],$GLOBALS['cache_image'],$GLOBALS['cache_type'],$GLOBALS['cache_medium']);
}
function ad_cache_target(){return $GLOBALS['cache_base'].'/album_mod/upload/'.($GLOBALS['cache_medium']?'med_cache':'cache').'/one'.$GLOBALS['cache_type'];}
function ad_cache_controller()
{
	global $db,$userdata,$phpbb_root_path;$phpbb_root_path=$GLOBALS['cache_base'].'/';$album_user_id=0;$cat_id=1;$pic_id=1;$thispic=$GLOBALS['cache_names'];$thumbnail=$GLOBALS['cache_image'];
	$source=file_get_contents($GLOBALS['root'].($GLOBALS['cache_medium']?'album_picm.php':'album_thumbnail.php'));
	ad_check(preg_match_all('/^\s*\$thumbnail_written = album_publish_picture_cache\([^;]+;/m',$source,$matches)>0,'Actual renderer publication handoff');
	foreach($matches[0]as$statement){if(strpos($statement,"'".$GLOBALS['cache_type']."'")!==false){eval($statement);return$thumbnail_written;}}
	throw new RuntimeException('Actual typed renderer publication statement missing');
}
function ad_cache_expected()
{
	ob_start();if($GLOBALS['cache_type']==='.jpg'){imagejpeg($GLOBALS['cache_image'],null,80);}else{imagepng($GLOBALS['cache_image']);}return ob_get_clean();
}
function ad_cache_no_temps()
{
	foreach(array('cache','med_cache')as$directory){ad_check(glob($GLOBALS['cache_base'].'/album_mod/upload/'.$directory.'/.album-cache-*.tmp')===array(),'Only this cache writer temporary file is removed on refusal');}
}
function ad_cache_refused($label)
{
	$before=ad_snapshot();$old='Existing cache bytes must be retained';ad_check(file_put_contents(ad_cache_target(),$old)===strlen($old),'Own previous cache');
	try{$result=ad_cache_publish();}catch(AlbumFileCoordinatorFailure$error){$result=false;}catch(AlbumFileStorageFailure$error){$result=false;}
	ad_check($result===false&&file_get_contents(ad_cache_target())===$old&&ad_snapshot()===$before,$label);ad_cache_no_temps();$GLOBALS['cases']++;
}
class AlbumCacheExpiryFixture extends AlbumCachePublication
{
	var $image_directory;
	function current($context=null)
	{
		if($this->image_directory!==null&&glob($this->image_directory.'/.album-cache-*.tmp')){$this->image_directory=null;return false;}
		return parent::current($context);
	}
}
class AlbumCachePeerFixture extends AlbumCachePublication
{
	var $image_directory;
	function current($context=null)
	{
		if(!parent::current($context)){return false;}
		if($this->image_directory!==null&&glob($this->image_directory.'/.album-cache-*.tmp')){
			$this->image_directory=null;$base=$GLOBALS['cache_base'];ad_check(file_put_contents($base.'/cache-peer-start','start')===5,'Start real deletion peer immediately before final rename');$deadline=microtime(true)+10;
			while(!is_file($base.'/cache-peer-attempt')){clearstatcache();if(microtime(true)>$deadline){throw new RuntimeException('Owned deletion peer barrier deadline');}usleep(10000);}
			usleep(250000);clearstatcache();ad_check(!is_file($base.'/cache-peer-done')&&count(ad_rows('SELECT pic_id FROM fixture_album WHERE pic_id=1'))===1,'Actual deletion peer waits while cache publication owns namespace');
		}
		return true;
	}
}
class AlbumCacheSourceChangeFixture extends AlbumCachePublication
{
	var $image_directory;
	function current($context=null)
	{
		if($this->image_directory!==null&&glob($this->image_directory.'/.album-cache-*.tmp')){$this->image_directory=null;ad_check(file_put_contents($GLOBALS['cache_base'].'/album_mod/upload/one.png','Externally changed original')!==false,'Own simulated external source replacement');}
		return parent::current($context);
	}
}
function ad_cache_peer_case($medium)
{
	global $cache_base,$schema,$db,$cases;
	ad_cache_frame($medium);ad_sql('UPDATE fixture_album SET pic_user_id=7 WHERE pic_id=1');$nonce=bin2hex(phpbb_random_bytes(16));ad_check(file_put_contents($cache_base.'/cache-owner',$nonce)===32,'Owned cache peer marker');
	$command=escapeshellarg(PHP_BINARY);if(DIRECTORY_SEPARATOR==='\\'){$command.=' -n -d '.escapeshellarg('extension_dir='.ini_get('extension_dir')).' -d extension=php_mysqli.dll -d extension=php_openssl.dll';}
	$command.=' '.escapeshellarg(__DIR__.'/check-album-discussion-native.php').' --cache-delete-worker '.escapeshellarg($schema).' '.escapeshellarg($nonce);
	$p=proc_open($command,array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes,null,null,array('bypass_shell'=>true));ad_check(is_resource($p),'Actual independent cache deletion worker');fclose($pipes[0]);
	try{
		$deadline=microtime(true)+15;while(!is_file($cache_base.'/cache-peer-ready')){clearstatcache();if(microtime(true)>$deadline){throw new RuntimeException('Owned cache peer ready deadline');}usleep(10000);}
		$writer=new AlbumCachePeerFixture($db,'fixture_',$cache_base);$writer->image_directory=dirname(ad_cache_target());ad_check(ad_cache_publish($writer)===true,'Actual cache publication completes before queued deletion');
		$deadline=microtime(true)+20;do{$status=proc_get_status($p);if(!$status['running']){break;}if(microtime(true)>$deadline){throw new RuntimeException('Owned cache peer finish deadline');}usleep(10000);}while(true);
		$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$closed=proc_close($p);$p=null;$code=$status['exitcode']>=0?$status['exitcode']:$closed;ad_check($code===0,'Cache deletion peer completed: '.$err.$out);$result=json_decode(trim($out),true);
		ad_check(is_array($result)&&$result['deleted']===1&&$result['pending']===0&&!ad_rows('SELECT pic_id FROM fixture_album WHERE pic_id=1')&&!file_exists(ad_cache_target())&&!file_exists($cache_base.'/album_mod/upload/one.png'),'Queued real deletion removes original and the newly published cache; no resurrection');$cases++;
	}finally{if(is_resource($p)){proc_terminate($p,9);foreach(array(1,2)as$i){if(is_resource($pipes[$i])){fclose($pipes[$i]);}}proc_close($p);}foreach(array('cache-owner','cache-peer-ready','cache-peer-start','cache-peer-attempt','cache-peer-done')as$file){if(is_file($cache_base.'/'.$file)){unlink($cache_base.'/'.$file);}}}
}
function ad_cache_cases()
{
	global $cache_base,$schema,$cases,$db,$root;
	ad_check(function_exists('imagecreatetruecolor'),'Native GD required for cache publication');$cache_base=sys_get_temp_dir().'/'.$schema;$cwd=getcwd();$directories=array('album_mod','album_mod/upload','album_mod/upload/cache','album_mod/upload/med_cache');
	ad_check(!file_exists($cache_base)&&mkdir($cache_base,0700),'Own cache fixture root');foreach($directories as$directory){ad_check(mkdir($cache_base.'/'.$directory,0700),'Own cache directory');}ad_check(chdir($cache_base),'Own cache working directory for actual constants');
	try
	{
		foreach(array(false,true)as$medium){foreach(array('.png','.jpg')as$type){
			ad_cache_frame($medium,$type);$before=ad_snapshot();$source=file_get_contents($cache_base.'/album_mod/upload/one'.$type);$expected=ad_cache_expected();ad_check(ad_cache_publish()===true&&file_get_contents(ad_cache_target())===$expected&&ad_snapshot()===$before&&file_get_contents($cache_base.'/album_mod/upload/one'.$type)===$source,'Current exact parent publishes a complete cache under original filename with no metadata/source changes');ad_cache_no_temps();$cases++;
			ad_check(!file_exists(dirname(ad_cache_target()).'/thumb'.$type),'Regeneration does not invent a second stale metadata thumbnail destination');$cases++;
			ad_cache_frame($medium,$type);ad_check(ad_cache_controller()===true&&file_get_contents(ad_cache_target())===ad_cache_expected(),'Actual small/medium JPEG/PNG renderer statement passes current picture identities to owned publication');$cases++;
		}}
		foreach(array(false,true)as$medium){foreach(array("DELETE FROM fixture_album WHERE pic_id=1","UPDATE fixture_album SET pic_cat_id=2 WHERE pic_id=1","UPDATE fixture_album SET pic_filename='different.png' WHERE pic_id=1","UPDATE fixture_album SET pic_thumbnail='different.png' WHERE pic_id=1","DELETE FROM fixture_sessions","UPDATE fixture_sessions SET session_time=1","UPDATE fixture_users SET user_active=0 WHERE user_id=7","UPDATE fixture_album_cat SET cat_view_level=1 WHERE cat_id=1","UPDATE fixture_album_cat SET cat_approval=1 WHERE cat_id=1","UPDATE fixture_album_config SET config_value='bad' WHERE config_name='thumbnail_quality'")as$change){
			ad_cache_frame($medium);if(strpos($change,'cat_approval=1')!==false){ad_sql('UPDATE fixture_album SET pic_approval=0 WHERE pic_id=1');}ad_sql($change);ad_cache_refused('Stale/unauthorized parent or current configuration cannot replace any old cache');
		}}
		foreach(array(false,true)as$medium){foreach(array('disabled','smaller','larger','invalid')as$change){ad_cache_frame($medium);
			if($medium){ad_sql("UPDATE fixture_album_sp_config SET config_value='".($change==='disabled'?'0':($change==='smaller'?'4':($change==='larger'?'9':'bad')))."' WHERE config_name='".($change==='disabled'?'midthumb_cache':'midthumb_width')."'");}
			else{ad_sql("UPDATE fixture_album_config SET config_value='".($change==='disabled'?'0':($change==='smaller'?'4':($change==='larger'?'12':'bad')))."' WHERE config_name='".($change==='disabled'?'thumbnail_cache':'thumbnail_size')."'");}
			ad_cache_refused('A changed current cache policy does not accept an image generated under obsolete bounds');
		}}
		ad_cache_frame();unlink($cache_base.'/album_mod/upload/one.png');ad_cache_refused('Missing current source cannot resurrect cache');
		ad_cache_frame();ad_actor(-1);ad_check(ad_cache_publish()===true,'Current guest view session may populate public cache');$cases++;
		foreach(array('engine','format','charset','column')as$change){ad_cache_frame(true);ad_sql($change==='engine'?'ALTER TABLE fixture_album_sp_config ENGINE=MyISAM':($change==='format'?'ALTER TABLE fixture_album_sp_config ROW_FORMAT=COMPACT':($change==='charset'?'ALTER TABLE fixture_album_sp_config DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci':'ALTER TABLE fixture_album_sp_config MODIFY config_value VARCHAR(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL DEFAULT \'\'')));ad_cache_refused('Medium policy table must retain canonical transactional storage');ad_sql('ALTER TABLE fixture_album_sp_config ENGINE=InnoDB ROW_FORMAT=DYNAMIC, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');}
		ad_cache_frame();$old='Earlier complete cache';file_put_contents(ad_cache_target(),$old);$writer=new AlbumCacheExpiryFixture($db,'fixture_',$cache_base);$writer->image_directory=dirname(ad_cache_target());ad_check(ad_cache_publish($writer)===false&&file_get_contents(ad_cache_target())===$old,'Capability lost after encoding refuses final rename and preserves previous cache');ad_cache_no_temps();$cases++;
		ad_cache_frame();file_put_contents(ad_cache_target(),$old);$writer=new AlbumCacheSourceChangeFixture($db,'fixture_',$cache_base);$writer->image_directory=dirname(ad_cache_target());ad_check(ad_cache_publish($writer)===false&&file_get_contents(ad_cache_target())===$old&&file_get_contents($cache_base.'/album_mod/upload/one.png')==='Externally changed original','Source replaced during encoding is preserved and cannot receive an obsolete cache');ad_cache_no_temps();$cases++;
		ad_cache_frame();$db->sql_query('START TRANSACTION');$db->sql_query("INSERT INTO fixture_config VALUES('caller_unrelated','pending')");ad_check(ad_cache_publish()===true&&!ad_rows("SELECT * FROM fixture_config WHERE config_name='caller_unrelated'"),'Independent cache lease never commits the caller transaction');$db->sql_query('ROLLBACK');$cases++;
		ad_cache_frame();$before=ad_snapshot();$db->cache_operation=true;$db->lost=true;$refused=false;try{ad_cache_publish();}catch(AlbumFileCoordinatorFailure$error){$refused=true;}ad_check($refused&&ad_snapshot()===$before&&file_get_contents(ad_cache_target())===ad_cache_expected(),'Lost read-only commit acknowledgement retains complete authorized cache without metadata changes');$cases++;
		ad_cache_peer_case(false);ad_cache_peer_case(true);
		if(getenv('PHPBB_ALBUM_CACHE_QUICK')!=='1'){
			foreach(array(false,true)as$medium){ad_cache_frame($medium);ad_check(ad_cache_publish()===true,'Cache fault baseline');$baseline=$db->queries;
				foreach($baseline as$index=>$sql){ad_cache_frame($medium);$before=ad_snapshot();$expected=ad_cache_expected();$old='Previous cache content';file_put_contents(ad_cache_target(),$old);$db->reset_case($index+1);try{ad_cache_publish();}catch(AlbumFileCoordinatorFailure$error){}catch(AlbumFileStorageFailure$error){}
					ad_check(ad_snapshot()===$before&&in_array(file_get_contents(ad_cache_target()),array($old,$expected),true),'Every real SQL failure preserves all picture metadata and either old or complete new cache');ad_cache_no_temps();$cases++;
				}
			}
		}
		echo 'Native Album cache publication: '.$cases.' cases PASS; '.(getenv('PHPBB_ALBUM_CACHE_QUICK')==='1'?'quick current/stale parent, policy, final-rename guard and real peer deletion':'current/stale parent, policy, final-rename guard, real peer deletion and actual SQL failure boundaries')."; own files/schema only.\n";
	}
	finally
	{
		if(isset($GLOBALS['cache_image'])&&is_resource($GLOBALS['cache_image'])){imagedestroy($GLOBALS['cache_image']);}chdir($cwd);
		ad_check(preg_match('/^codex_album_discussion_[a-f0-9]{16}$/D',basename($cache_base))===1&&realpath(dirname($cache_base))===realpath(sys_get_temp_dir()),'Exact owned cache fixture cleanup');
		foreach(array_reverse($directories)as$directory){foreach(glob($cache_base.'/'.$directory.'/*')as$file){if(is_file($file)&&!is_link($file)){unlink($file);}}if(basename($directory)==='cache'||basename($directory)==='med_cache'){foreach(glob($cache_base.'/'.$directory.'/.album-cache-*.tmp')as$file){if(is_file($file)&&!is_link($file)){unlink($file);}}}rmdir($cache_base.'/'.$directory);}rmdir($cache_base);
	}
}
