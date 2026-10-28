<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_ALBUM_PICTURE_UPLOAD_NATIVE')!=='1'){echo "Native Album picture upload fixture skipped.\n";return;}
define('IN_PHPBB',true);define('ADMIN',1);define('GENERAL_ERROR',202);define('GENERAL_MESSAGE',200);$table_prefix='fixture_';
foreach(array('USERS_TABLE'=>'users','SESSIONS_TABLE'=>'sessions','USER_GROUP_TABLE'=>'user_group','CONFIG_TABLE'=>'config')as$name=>$suffix){define($name,$table_prefix.$suffix);}
$root=dirname(dirname(__DIR__)).'/phpBB2/';require$root.'includes/php_compat.php';require$root.'db/mysqli.php';require$root.'album_mod/album_constants.php';require$root.'album_mod/album_functions.php';require$root.'album_mod/album_hierarchy_auth.php';require$root.'album_mod/album_picture_upload.php';require __DIR__.'/album-native-observer.php';
require $root.'album_mod/album_photo_text.php';
function apu_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
function apu_trace($message){if(getenv('PHPBB_ALBUM_UPLOAD_TRACE')==='1'){fwrite(STDOUT,'UPLOAD_TRACE '.$message."\n");}}
function message_die($type,$message){throw new RuntimeException($message);}
function checkFlag($value,$flag){return($value&$flag)===$flag;}
function album_is_debug_enabled(){return false;}
$common=file_get_contents($root.'includes/functions.php');foreach(array('phpbb_image_dimensions_safe','phpbb_sql_id_list')as$name){apu_check(preg_match('/^function '.$name.'\(.*?^\}/ms',$common,$match)===1,'Actual production utility');eval($match[0]);}
function album_sql_id_list($ids){return phpbb_sql_id_list($ids);}
class AlbumPictureUploadSource extends sql_db
{
    var$queries=array();var$count=0;var$failure=0;var$kind='';var$factory_count=0;var$role=0;var$armed=false;var$crash='';var$crash_armed=false;var$unwind_pending=false;
    function sql_dedicated_connection(){$connection=new AlbumPictureUploadObserved(parent::sql_dedicated_connection(),$this);$connection->role=++$this->factory_count;return$connection;}
    function observed_query($database,$sql,$transaction=false){$this->queries[]=$sql;if(++$this->count===$this->failure){if(getenv('PHPBB_ALBUM_UPLOAD_TRACE')==='1'){apu_trace('failure stack '.json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)));}return false;}
        if(strpos($sql,'INSERT INTO fixture_album_file_items')===0&&$this->kind==='prepare-ack'){$this->armed=true;}
        if(strpos($sql,"UPDATE fixture_album_file_jobs SET operation_state='published'")===0&&in_array($this->kind,array('picture-ack','items-read-unwind'),true)){$this->armed=true;}
        if($this->kind==='items-read-unwind'&&$this->armed&&strpos($sql,'SELECT * FROM fixture_album_file_items')===0){$this->unwind_pending=true;}
        if($this->unwind_pending&&strpos($sql,'SELECT CONNECTION_ID() AS id,IS_USED_LOCK(')===0){$this->unwind_pending=false;return false;}
        if($sql==='COMMIT'&&$this->armed&&(($this->kind==='prepare-ack'&&$this->role===2)||($this->kind==='picture-ack'&&$this->role===1))){$this->armed=false;apu_check($database->sql_query($sql)!==false,'Actual unacknowledged upload commit');return false;}
        if(strpos($sql,'INSERT INTO fixture_album(')===0&&$this->kind==='before-picture'){return false;}
        if(($this->crash==='prepared'&&strpos($sql,'INSERT INTO fixture_album_file_items')===0)||($this->crash==='published'&&strpos($sql,"UPDATE fixture_album_file_jobs SET operation_state='published'")===0)){$this->crash_armed=true;}
        if($this->crash==='files'&&strpos($sql,'INSERT INTO fixture_album(')===0){apu_crash_barrier('files');}
        $result=$database->sql_query($sql,$transaction);
        if($result!==false&&$sql==='COMMIT'&&$this->crash_armed&&(($this->crash==='prepared'&&$this->role===2)||($this->crash==='published'&&$this->role===1))){apu_crash_barrier($this->crash);}
        return$result;
    }
    function reset_case($failure=0,$kind=''){$this->queries=array();$this->count=0;$this->failure=$failure;$this->kind=$kind;$this->factory_count=0;$this->role=0;$this->armed=false;$this->unwind_pending=false;}
}
class AlbumPictureUploadObserved extends AlbumNativeObservedConnection
{
    var$role;
    function sql_query($sql='',$transaction=false){$this->observer->role=$this->role;return parent::sql_query($sql,$transaction);}
}
$port=getenv('PHPBB_ALBUM_PICTURE_UPLOAD_PORT')?:'3306';$password=getenv('PHPBB_ALBUM_PICTURE_UPLOAD_PASSWORD')?:'';apu_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Own loopback upload port');
$schema='codex_album_publish_'.bin2hex(phpbb_random_bytes(8));$base=sys_get_temp_dir().'/'.$schema;$relative='album_mod/upload/nuffload/';$psid=str_repeat('a',32);$sid=str_repeat('b',32);$source_file=$relative.'tmp/'.$psid.'_actualdata0';$thumb_file=$relative.'tmp/'.$psid.'_actualdata1';
$host='127.0.0.1:'.$port;$control=new sql_db($host,'root',$password,'',false);$db=null;$stage=null;$created=false;$created_directory=false;$known=array();$cases=0;
$userdata=array();$album_config=array();$album_data=array();$board_config=array('session_length'=>999999,'marker'=>'unchanged');$lang=array('Category_not_exist'=>'missing');
$title='0';$description=htmlspecialchars("O'Connor \ Größe 😀",ENT_QUOTES,'UTF-8');
function apu_sql($sql){$result=$GLOBALS['control']->sql_query($sql);apu_check($result!==false,'Own native upload fixture SQL');return$result;}
function apu_rows($sql){$result=apu_sql($sql);try{return$GLOBALS['control']->sql_fetchrowset($result);}finally{$GLOBALS['control']->sql_freeresult($result);}}
function apu_literal($value){return$value===''?"''":'CONVERT(0x'.bin2hex($value).' USING utf8mb4)';}
function apu_image($type,$width=400,$height=200){$image=imagecreatetruecolor($width,$height);apu_check($image!==false,'Owned actual source image');imagefilledrectangle($image,0,0,$width-1,$height-1,0x275faa);ob_start();$ok=$type==='.jpg'?imagejpeg($image,null,90):imagepng($image);$bytes=ob_get_clean();if(PHP_VERSION_ID<80000){imagedestroy($image);}apu_check($ok&&is_string($bytes),'Complete native encoded source');return$bytes;}
function apu_clear_files(){foreach($GLOBALS['known']as$path){if(is_file($path)&&!is_link($path)){apu_check(unlink($path),'Remove only exact owned upload image/stage');}}$GLOBALS['known']=array();}
function apu_reset($guest=false,$gd=2,$type='.png')
{
    if($GLOBALS['stage']){$GLOBALS['stage']->release();$GLOBALS['stage']=null;}apu_clear_files();
    foreach(array('album_file_items','album_file_jobs','album','album_cat','album_config','config','sessions','user_group','users')as$suffix){apu_sql('DELETE FROM fixture_'.$suffix);}
    apu_sql("INSERT INTO fixture_users(user_id,username,user_password,user_active,user_level) VALUES(-1,'Anonymous','',0,0),(7,".apu_literal("O'Connor \ 😀").",'',1,0),(8,'Other owner','',1,0)");
    apu_sql("INSERT INTO fixture_config VALUES('session_length','3600'),('unrelated','preserve')");foreach($GLOBALS['seeds']as$sql){apu_sql($sql);}
    apu_sql("UPDATE fixture_album_config SET config_value='".$gd."' WHERE config_name='gd_version'");apu_sql("UPDATE fixture_album_config SET config_value='1' WHERE config_name='gif_allowed'");
    apu_sql("INSERT INTO fixture_album_cat(cat_id,cat_title,cat_order,cat_user_id,cat_upload_level,cat_upload_groups,cat_moderator_groups) VALUES(1,'Public',1,0,".($guest?-1:0).",'',''),(2,'Own gallery',2,7,3,'',''),(3,'Other gallery',3,8,3,'10',''),(4,'Moderated',4,0,2,'','10')");apu_sql('INSERT INTO fixture_user_group(group_id,user_id,user_pending) VALUES(10,7,0)');
    $GLOBALS['userdata']=array('user_id'=>$guest?-1:7,'username'=>'Old name','user_level'=>ADMIN,'user_active'=>1,'session_id'=>$GLOBALS['sid'],'session_logged_in'=>!$guest,'session_ip'=>'ffffffff','marker'=>'preserve');
    apu_sql("INSERT INTO fixture_sessions(session_id,session_user_id,session_logged_in,session_time,session_ip) VALUES('".$GLOBALS['sid']."',".($guest?-1:7).','.($guest?0:1).','.time().",'7f000001')");
    $GLOBALS['album_config']=array('marker'=>'preserve');$GLOBALS['album_data']=array('auth'=>array(1=>array('upload'=>1)));
    $settings=array();foreach(apu_rows('SELECT config_name,config_value FROM fixture_album_config')as$row){$settings[$row['config_name']]=$row['config_value'];}$GLOBALS['processing']=$settings;
    $original=$type==='.gif'?base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw=='):apu_image($type);$thumb=$type==='.gif'?$original:apu_image($type,100,50);
    apu_check(file_put_contents($GLOBALS['base'].'/'.$GLOBALS['source_file'],$original)===strlen($original)&&file_put_contents($GLOBALS['base'].'/'.$GLOBALS['thumb_file'],$thumb)===strlen($thumb),'Own staged source and thumbnail');
    apu_check(file_put_contents($GLOBALS['base'].'/'.$GLOBALS['relative'].'tmp/'.$GLOBALS['psid'].'_owner',album_nuffload_owner_token($GLOBALS['userdata']))===64,'Own Nuffload owner capability');
    $GLOBALS['input_hash']=hash('sha256',$original);$GLOBALS['db']->reset_case();
}
function apu_stage($source=null)
{
    $source=$source===null?$GLOBALS['source_file']:$source;
    $GLOBALS['stage']=new AlbumUploadStage($GLOBALS['userdata'],$GLOBALS['relative'],$GLOBALS['psid'],$source,$GLOBALS['thumb_file'],$GLOBALS['processing'],$GLOBALS['base']);
    foreach($GLOBALS['stage']->artifacts()as$item){$directory=$GLOBALS['base'].'/album_mod/upload/'.($item['scope']==='small'?'cache/':'');$GLOBALS['known'][$directory.$item['name']]=$directory.$item['name'];$temporary=$directory.'.album-stage-'.$GLOBALS['stage']->operation().'-'.substr(hash('sha256',$item['scope']."\0".$item['name']),0,16).'.tmp';$GLOBALS['known'][$temporary]=$temporary;}
    return$GLOBALS['stage'];
}
function apu_publish($owner=0,$category=1)
{
    if(!$GLOBALS['stage']){apu_stage();}$before=array($GLOBALS['db'],$GLOBALS['userdata'],$GLOBALS['album_config'],$GLOBALS['album_data'],$GLOBALS['board_config']);
    $uploader=new AlbumPictureUpload($GLOBALS['db'],'fixture_',$GLOBALS['base']);
    try{return$uploader->publish($GLOBALS['userdata'],$owner,$category,$GLOBALS['title'],$GLOBALS['description'],$GLOBALS['userdata']['session_logged_in']?'':htmlspecialchars("Guest's 😀",ENT_QUOTES,'UTF-8'),$GLOBALS['stage']);}
    finally{apu_check(array($GLOBALS['db'],$GLOBALS['userdata'],$GLOBALS['album_config'],$GLOBALS['album_data'],$GLOBALS['board_config'])===$before,'Actual publisher preserves exact caller globals');}
}
function apu_controller($owner=0,$category=1)
{
    global$db,$userdata,$album_config,$album_data,$board_config,$lang;
    $source=file_get_contents($GLOBALS['root'].'album_upload.php');$start=strpos($source,"\t// Preprocess from the session-owned Nuff stage");$end=strpos($source,"\t// Complete... now send a message to user",$start);apu_check($start!==false&&$end>$start,'Actual upload handler publication block');$branch=substr($source,$start,$end-$start);
    $input_start=strpos($source,'$pic_title_input =');$input_guard=strpos($source,"if (\$pic_title === '')",$input_start);$input_end=strpos($source,"\n\t}",$input_guard);apu_check($input_start!==false&&$input_guard>$input_start&&$input_end>$input_guard,'Actual controller complete caption normalization');$input=substr($source,$input_start,$input_end+3-$input_start);
    $saved_config=$album_config;$album_config=$GLOBALS['processing'];$table_prefix='fixture_';$phpbb_root_path=$GLOBALS['base'].'/';$path_to_bin=$GLOBALS['relative'];$psid=$GLOBALS['psid'];$filetmp=$GLOBALS['source_file'];$thumbtmp=$GLOBALS['thumb_file'];$pic_title=$GLOBALS['title'];$pic_desc=$GLOBALS['description'];$pic_username=$userdata['session_logged_in']?'':htmlspecialchars("Guest's 😀",ENT_QUOTES,'UTF-8');$album_user_id=$owner;$cat_id=$category;
    $tracking=new AlbumUploadStage($userdata,$path_to_bin,$psid,$filetmp,$thumbtmp,$album_config,$GLOBALS['base']);foreach($tracking->artifacts()as$item){$directory=$GLOBALS['base'].'/album_mod/upload/'.($item['scope']==='small'?'cache/':'');$GLOBALS['known'][$directory.$item['name']]=$directory.$item['name'];$temporary=$directory.'.album-stage-'.$tracking->operation().'-'.substr(hash('sha256',$item['scope']."\0".$item['name']),0,16).'.tmp';$GLOBALS['known'][$temporary]=$temporary;}$tracking->release();
    $saved_post=$_POST;$_POST=array('pic_title'=>addslashes(html_entity_decode($GLOBALS['title'],ENT_QUOTES,'UTF-8')),'pic_desc'=>addslashes(html_entity_decode($GLOBALS['description'],ENT_QUOTES,'UTF-8')),'pic_username'=>addslashes("Guest's 😀"));
    try{eval($input);eval($branch);return$upload_result;}catch(RuntimeException$error){if($error->getMessage()===(isset($lang['Upload_processing_failed'])?$lang['Upload_processing_failed']:'The picture could not be stored. Please try again.')||$error->getMessage()===$lang['Not_Authorised']||$error->getMessage()===$lang['Upload_description_too_long']){return array('error'=>$error->getMessage());}throw$error;}
    finally{$album_config=$saved_config;$_POST=$saved_post;}
}
function apu_consistent()
{
    $jobs=apu_rows('SELECT operation_state,item_count FROM fixture_album_file_jobs');$items=apu_rows('SELECT * FROM fixture_album_file_items');$photos=apu_rows('SELECT * FROM fixture_album');
    apu_check(count($jobs)<=1,'Single owned logical upload');$published=$jobs&&$jobs[0]['operation_state']==='published';apu_check(count($photos)===($published?1:0),'Photo and publication receipt commit together');
    apu_check(count($items)===($jobs?(int)$jobs[0]['item_count']:0),'Prepared header/items commit together');
    foreach($items as$item){$path=$GLOBALS['base'].'/album_mod/upload/'.($item['file_scope']==='small'?'cache/':'').$item['filename'];if(is_file($path)){apu_check(hash_file('sha256',$path)===$item['file_sha256'],'Every completed file matches persisted full hash');}if($published){apu_check(is_file($path),'Committed picture keeps original and thumbnail');}}
    if($published){apu_check($photos[0]['pic_title']===$GLOBALS['title']&&$photos[0]['pic_desc']===$GLOBALS['description']&&$photos[0]['pic_user_ip']==='7f000001','Canonical title zero, UTF8 and current IP retained');}
    apu_check(is_file($GLOBALS['base'].'/'.$GLOBALS['source_file'])&&hash_file('sha256',$GLOBALS['base'].'/'.$GLOBALS['source_file'])===$GLOBALS['input_hash'],'Original Nuff source never moved/deleted/changed');
}
function apu_denied($owner=0,$category=1){$failed=false;try{apu_publish($owner,$category);}catch(AlbumUploadFailure$error){$failed=true;}catch(AlbumFileCoordinatorFailure$error){$failed=true;}catch(AlbumFileStorageFailure$error){$failed=true;}apu_check($failed,'Current invalid input/authority fails actual publication');$GLOBALS['cases']++;}
function apu_crash_barrier($boundary)
{
    apu_check(file_put_contents($GLOBALS['base'].'/crash-ready',$boundary)===strlen($boundary),'Own child reached exact publication boundary');
    $deadline=microtime(true)+20;while(microtime(true)<$deadline){usleep(10000);}
    throw new RuntimeException('Parent must terminate actual upload worker without shutdown handlers');
}
class AlbumUploadSlowFixtureFilter extends php_user_filter
{
    #[\ReturnTypeWillChange]
    function filter($input,$output,&$consumed,$closing){while($bucket=stream_bucket_make_writeable($input)){usleep(10000);$consumed+=$bucket->datalen;stream_bucket_append($output,$bucket);}return PSFS_PASS_ON;}
}
if(isset($argv[1])&&$argv[1]==='crash-worker'){
    apu_check(count($argv)===4&&preg_match('/^codex_album_publish_[a-f0-9]{16}$/D',$argv[2])&&in_array($argv[3],array('prepared','copy','files','published'),true),'Exact own upload worker context');
    $schema=$argv[2];$base=sys_get_temp_dir().'/'.$schema;apu_check(is_dir($base)&&!is_link($base)&&realpath(dirname($base))===realpath(sys_get_temp_dir()),'Existing own parent fixture root');
    apu_sql('USE '.$schema);$db=new AlbumPictureUploadSource($host,'root',$password,$schema,false);$db->crash=$argv[3];
    $userdata=array('user_id'=>7,'session_id'=>$sid,'session_logged_in'=>true);$processing=array();foreach(apu_rows('SELECT config_name,config_value FROM fixture_album_config')as$row){$processing[$row['config_name']]=$row['config_value'];}
    apu_stage();if($argv[3]==='copy'){apu_check(stream_filter_register('album.upload.slow.fixture','AlbumUploadSlowFixtureFilter'),'Register own slow read filter');$artifact=$stage->original();apu_check(stream_filter_append($artifact['stream'],'album.upload.slow.fixture',STREAM_FILTER_READ)!==false,'Delay actual regular-file copy without production hooks');}
    apu_publish();throw new RuntimeException('Parent must terminate child at requested publication boundary');
}
function apu_crash_worker($boundary)
{
    $command=(DIRECTORY_SEPARATOR===chr(92)?'':'exec ').escapeshellarg(PHP_BINARY).' -d date.timezone=UTC ';
    if(DIRECTORY_SEPARATOR===chr(92)){$command.='-n -d '.escapeshellarg('extension_dir='.ini_get('extension_dir')).' -d extension=php_mysqli.dll -d extension=php_openssl.dll -d extension='.(PHP_VERSION_ID<80000?'php_gd2.dll':'php_gd.dll').' ';if(extension_loaded('mbstring')){$command.='-d extension=php_mbstring.dll ';}}
    $command.=escapeshellarg(__FILE__).' crash-worker '.escapeshellarg($GLOBALS['schema']).' '.escapeshellarg($boundary);
    $process=proc_open($command,array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes,null,null,array('bypass_shell'=>true));apu_check(is_resource($process),'Start actual owned upload process');fclose($pipes[0]);
    $ready=$GLOBALS['base'].'/crash-ready';$partial=$GLOBALS['base'].'/album_mod/upload/.album-stage-'.$GLOBALS['operation'].'-'.substr(hash('sha256',"original\0".$GLOBALS['operation'].'.png'),0,16).'.tmp';
    try{
        $deadline=microtime(true)+15;$reached=false;$output='';
        do{
            $status=proc_get_status($process);clearstatcache();
            if($boundary==='copy'){$size=is_file($partial)?filesize($partial):0;$reached=$size>0&&$size<$GLOBALS['copy_bytes'];}
            else{$reached=is_file($ready)&&file_get_contents($ready)===$boundary;}
            if($reached||!$status['running']||microtime(true)>=$deadline){break;}usleep(10000);
        }while(true);
        // Anonymous Windows pipes can block while a silent worker is alive.
        if(!$status['running']){$output=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);}
        apu_check($reached&&$status['running'],'Actual live upload process reached '.$boundary.': '.$output);
        apu_check(proc_terminate($process,9),'Force process death before any PHP release/finally handler');$deadline=microtime(true)+5;
        do{$status=proc_get_status($process);if(!$status['running']){break;}apu_check(microtime(true)<$deadline,'Bounded worker termination');usleep(10000);}while(true);
        apu_check(stream_get_contents($pipes[1])===''&&stream_get_contents($pipes[2])==='','Killed actual upload worker emitted no PHP error');fclose($pipes[1]);fclose($pipes[2]);proc_close($process);$process=null;
        if(is_file($ready)){apu_check(unlink($ready),'Remove exact own crash barrier');}
    }finally{if(is_resource($process)){proc_terminate($process,9);foreach(array(1,2)as$index){if(is_resource($pipes[$index])){fclose($pipes[$index]);}}proc_close($process);}}
}
$reporting=error_reporting(E_ALL);set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{
    apu_check(function_exists('imagecreatetruecolor')&&function_exists('imagejpeg')&&function_exists('imagepng'),'Actual GD required for upload integration');apu_check($control->db_connect_id,'Owned loopback control');apu_sql('CREATE DATABASE '.$schema.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$created=true;apu_sql('USE '.$schema);
    $ddl=file_get_contents($root.'install/schemas/mysql_schema.sql');foreach(array('users','sessions','user_group','config','album','album_cat','album_comment','album_rate','album_config','album_file_jobs','album_file_items')as$suffix){apu_check(preg_match('/^CREATE TABLE `?phpbb_'.$suffix.'`?\s*\([\s\S]*?;/m',$ddl,$match)===1,'Canonical upload fixture table');apu_sql(str_replace('phpbb_'.$suffix,'fixture_'.$suffix,$match[0]));}
    preg_match_all('/^INSERT INTO phpbb_album_config\b[^\r\n]*;/m',file_get_contents($root.'install/schemas/mysql_basic.sql'),$matches);$seeds=array();foreach($matches[0]as$sql){$seeds[]=str_replace('phpbb_album_config','fixture_album_config',$sql);}apu_check(count($seeds)>40,'Actual default Album configuration');
    apu_check(!file_exists($base)&&mkdir($base,0700),'Own upload integration root');$created_directory=true;$directories=array('/album_mod','/album_mod/upload','/album_mod/upload/cache','/album_mod/upload/med_cache','/album_mod/upload/nuffload','/album_mod/upload/nuffload/tmp');foreach($directories as$directory){apu_check(mkdir($base.$directory,0700),'Own upload integration directory');}
    $db=new AlbumPictureUploadSource($host,'root',$password,$schema,false);apu_check($db->db_connect_id,'Owned request DB');
    $phpbb_root_path=$root;$phpEx='php';require$GLOBALS['root'].'language/lang_english/lang_main_album.php';$lang['Not_Authorised']='Not authorised';
    apu_trace('regular cases');$baseline=array();$fault_cases=0;if(getenv('PHPBB_ALBUM_UPLOAD_CRASH_ONLY')!=='1'){
    foreach(array(array(false,0,'.png',0,1),array(false,1,'.png',0,1),array(false,2,'.jpg',0,1),array(false,2,'.gif',0,1),array(true,2,'.png',0,1),array(false,2,'.png',7,2),array(false,2,'.png',8,3),array(false,2,'.png',0,4))as$case){apu_reset($case[0],$case[1],$case[2]);$result=apu_publish($case[3],$case[4]);apu_check($result['state']==='published'&&!$result['reused'],'Legitimate guest/registered/personal/private/mod and GD0/1/2/GIF publication');apu_consistent();$cases++;}
    apu_reset();apu_sql("UPDATE fixture_album_config SET config_value='0' WHERE config_name='thumbnail_cache'");$processing['thumbnail_cache']='0';$result=apu_publish();apu_check($result['thumbnail']===''&&count(apu_rows('SELECT item_index FROM fixture_album_file_items'))===1,'Cache-disabled upload stores only original');apu_consistent();$cases++;
    foreach(array("UPDATE fixture_users SET user_active=0 WHERE user_id=7","DELETE FROM fixture_sessions","UPDATE fixture_sessions SET session_time=1","UPDATE fixture_sessions SET session_user_id=8","UPDATE fixture_album_cat SET cat_upload_level=1 WHERE cat_id=1","UPDATE fixture_album_cat SET cat_user_id=8 WHERE cat_id=1","UPDATE fixture_config SET config_value='0' WHERE config_name='session_length'","UPDATE fixture_album_config SET config_value='0' WHERE config_name='png_allowed'","UPDATE fixture_album_config SET config_value='1' WHERE config_name='max_file_size'","UPDATE fixture_album_config SET config_value='1' WHERE config_name='max_width'","UPDATE fixture_album_config SET config_value='1' WHERE config_name='max_height'","UPDATE fixture_album_config SET config_value='0' WHERE config_name='desc_length'","UPDATE fixture_album_config SET config_value='64' WHERE config_name='thumbnail_size'")as$change){apu_reset();apu_stage();apu_sql($change);apu_denied();apu_check(!apu_rows('SELECT operation_id FROM fixture_album_file_jobs'),'Invalid current publication creates no prepared files');apu_consistent();}
    apu_reset();apu_sql('UPDATE fixture_album_cat SET cat_approval=1 WHERE cat_id=1');$result=apu_publish();apu_check($result['approval']===0&&apu_rows('SELECT pic_approval FROM fixture_album')[0]['pic_approval']==='0','Current approval requirement wins over frontend snapshot');apu_consistent();$cases++;
    apu_reset();apu_sql("UPDATE fixture_album_config SET config_value='1' WHERE config_name='max_pics'");$first=apu_publish();$db->reset_case();$again=apu_publish();apu_check($again['reused']&&$again['picture']===$first['picture']&&count(apu_rows('SELECT pic_id FROM fixture_album'))===1,'Already published retry succeeds at filled quota without duplicate');$cases++;
    apu_sql("UPDATE fixture_album_config SET config_value='0' WHERE config_name='desc_length'");$db->reset_case();$again=apu_publish();apu_check($again['reused'],'Already committed retry unaffected by later lower description limit');$cases++;
    apu_sql('DELETE FROM fixture_album');$db->reset_case();$again=apu_publish();apu_check($again['reused']&&$again['state']==='gone'&&$again['picture']===null&&!apu_rows('SELECT pic_id FROM fixture_album'),'Successful retry never resurrects subsequently deleted picture');$cases++;
    apu_reset();$db->reset_case();apu_publish();$baseline=$db->queries;
    $selected_fault=getenv('PHPBB_ALBUM_UPLOAD_FAULT_ONLY');apu_check($selected_fault===false||(preg_match('/^[1-9][0-9]*$/D',$selected_fault)&&(int)$selected_fault<=count($baseline)),'Explicit fault selector is an actual observed boundary');
    foreach($baseline as$index=>$sql){if($selected_fault!==false&&$selected_fault!==(string)($index+1)){continue;}apu_trace('SQL fault '.($index+1).'/'.count($baseline).' '.$sql);apu_reset();$db->reset_case($index+1);apu_denied();$fault_cases++;apu_trace('expected failure returned');apu_consistent();apu_trace('fault state consistent');}
    apu_reset();$db->reset_case(0,'items-read-unwind');$caught=false;try{apu_publish();}catch(AlbumFileCoordinatorFailure$error){$caught=$error->getMessage()==='Album coordinated statement failed';}apu_check($caught,'Actual receipt readback preserves first ownership exception while safely freeing result in finally');apu_consistent();$db->reset_case();apu_check(apu_publish()['state']==='published','Result-cleanup failure path safely resumes its prepared upload');apu_consistent();$cases++;
    foreach(array('prepare-ack','picture-ack','before-picture')as$kind){apu_trace($kind);apu_reset();$db->reset_case(0,$kind);apu_denied();apu_consistent();$db->reset_case();$retry=apu_publish();apu_check($retry['state']==='published'&&count(apu_rows('SELECT pic_id FROM fixture_album'))===1,'Retry resolves real uncertain/failed commit without duplicating photo');apu_consistent();$cases++;}
    apu_reset();apu_sql("CREATE TRIGGER own_upload_change BEFORE INSERT ON fixture_album FOR EACH ROW SET NEW.pic_title='changed'");apu_denied();apu_consistent();apu_check(apu_rows('SELECT operation_state FROM fixture_album_file_jobs')[0]['operation_state']==='prepared','Trigger/readback failure retains durable preparation and files');apu_sql('DROP TRIGGER own_upload_change');$db->reset_case();$retry=apu_publish();apu_check(!$retry['reused'],'Prepared complete files resume after metadata failure');apu_consistent();$cases++;
    apu_reset();$result=apu_controller();apu_check($result['state']==='published'&&!$result['reused'],'Actual controller wires staged input, current publisher and fresh category result');apu_consistent();$cases++;
    foreach(array('before-picture','picture-ack')as$kind){apu_reset();$db->reset_case(0,$kind);$result=apu_controller();apu_check(isset($result['error'])&&$result['error']===$lang['Upload_processing_failed'],'Actual handler renders controlled localized failure instead of uncaught 500');apu_consistent();$db->reset_case();$result=apu_controller();apu_check($result['state']==='published'&&count(apu_rows('SELECT pic_id FROM fixture_album'))===1,'Actual controller retries pending/committed handoff exactly once');apu_consistent();$cases++;}
    apu_reset();$first=apu_controller();apu_sql("UPDATE fixture_album_config SET config_value='0' WHERE config_name='desc_length'");$processing['desc_length']='0';$db->reset_case();$again=apu_controller();apu_check($again['reused']&&$again['picture']===$first['picture'],'Actual controller keeps complete caption identity when live limit changes after success');$cases++;
    apu_reset();apu_sql("UPDATE fixture_album_config SET config_value='0' WHERE config_name='desc_length'");$processing['desc_length']='0';$result=apu_controller();apu_check(isset($result['error'])&&$result['error']===$lang['Upload_description_too_long']&&!apu_rows('SELECT pic_id FROM fixture_album'),'Actual controller rejects oversized description without silently storing a clipped caption');$cases++;
    apu_reset();$saved_error=$lang['Upload_processing_failed'];unset($lang['Upload_processing_failed']);$db->reset_case(0,'before-picture');$result=apu_controller();apu_check(isset($result['error'])&&$result['error']==='The picture could not be stored. Please try again.','Legacy language pack still gets controlled upload error');$lang['Upload_processing_failed']=$saved_error;$cases++;
    apu_reset();$db->reset_case(0,'before-picture');apu_denied();$artifacts=$stage->artifacts();foreach($artifacts as$item){$path=$base.'/album_mod/upload/'.($item['scope']==='small'?'cache/':'').$item['name'];apu_check(unlink($path),'Simulate only own interrupted publication final');}$partial=$base.'/album_mod/upload/.album-stage-'.$stage->operation().'-'.substr(hash('sha256',"original\0".$artifacts[0]['name']),0,16).'.tmp';apu_check(file_put_contents($partial,'partial')===7,'Exact operation-owned interrupted stage');$db->reset_case();$result=apu_publish();apu_check(!is_file($partial)&&$result['state']==='published','Actual retry removes only its recorded partial stage and republishes complete source');apu_consistent();$cases++;
    apu_reset();$db->reset_case(0,'before-picture');apu_denied();$artifacts=$stage->artifacts();$path=$base.'/album_mod/upload/'.$artifacts[0]['name'];file_put_contents($path,'foreign replacement');$partial=$base.'/album_mod/upload/.album-stage-'.$stage->operation().'-'.substr(hash('sha256',"original\0".$artifacts[0]['name']),0,16).'.tmp';file_put_contents($partial,'partial');$db->reset_case();apu_denied();apu_check(file_get_contents($path)==='foreign replacement'&&file_get_contents($partial)==='partial'&&!apu_rows('SELECT pic_id FROM fixture_album'),'Changed final bytes and existing stage both preserved');$cases++;
    apu_reset();$db->reset_case(0,'before-picture');apu_denied();$artifacts=$stage->artifacts();apu_sql("INSERT INTO fixture_album(pic_id,pic_filename,pic_thumbnail,pic_title,pic_user_id,pic_time) VALUES(99,".apu_literal($artifacts[0]['name']).",'','Foreign reference',8,1)");$db->reset_case();apu_denied();$photos=apu_rows('SELECT pic_id FROM fixture_album');apu_check(count($photos)===1&&(int)$photos[0]['pic_id']===99&&hash_file('sha256',$base.'/album_mod/upload/'.$artifacts[0]['name'])===$input_hash,'Prepared retry never adopts a surviving foreign picture reference');$cases++;
    }
    foreach(array('prepared','copy','files','published')as$boundary){
        apu_trace('crash '.$boundary);
        apu_reset();
        if($boundary==='copy'){$large=apu_image('.png').str_repeat('p',8*1024*1024);apu_check(file_put_contents($base.'/'.$source_file,$large)===strlen($large),'Own valid PNG with bounded trailing padding for mid-copy crash');$copy_bytes=strlen($large);$input_hash=hash('sha256',$large);apu_sql("UPDATE fixture_album_config SET config_value='16777216' WHERE config_name='max_file_size'");$processing['max_file_size']='16777216';}
        apu_stage();$operation=$stage->operation();$stage->release();$stage=null;apu_crash_worker($boundary);apu_consistent();
        $jobs=apu_rows('SELECT operation_state FROM fixture_album_file_jobs');apu_check(count($jobs)===1&&$jobs[0]['operation_state']===($boundary==='published'?'published':'prepared'),'Actual process kill preserves exact durable publication phase at '.$boundary);
        // A hard kill intentionally leaves its derived preview behind. The
        // existing age cleaner must recognize it without touching raw inputs.
        $abandoned=0;foreach(scandir($base.'/'.$relative.'tmp')as$name){if(preg_match('/^'.$psid.'_derived_[a-f0-9]{32}$/D',$name)){apu_check(touch($base.'/'.$relative.'tmp/'.$name,time()-7200),'Age exact own abandoned generated preview');$abandoned++;}}
        apu_check($abandoned===1,'Exactly one generated private preview survives hard process death');clearstatcache();album_nuffload_cleanup_temp($base.'/'.$relative.'tmp');
        foreach(scandir($base.'/'.$relative.'tmp')as$name){apu_check(preg_match('/^'.$psid.'_derived_[a-f0-9]{32}$/D',$name)!==1,'Abandoned preview is reclaimable by bounded existing age cleanup');}
        $db->reset_case();$retry=apu_publish();apu_check($retry['state']==='published'&&$retry['reused']===($boundary==='published')&&count(apu_rows('SELECT pic_id FROM fixture_album'))===1,'Actual killed upload resumes without duplicate, missing final or partial-stage obstruction');apu_consistent();$cases++;
    }
    echo 'Native Album picture upload: '.$cases.' cases PASS; '.count($baseline).' actual SQL boundaries, '.$fault_cases." injected statement faults, four forced process kills, real staged/GD/final files, current authority/approval/quotas, exact metadata and uncertain-commit retries; no live data.\n";
}finally{if($stage){$stage->release();}apu_clear_files();if($db){$db->sql_close();}if($created){$control->sql_query('DROP DATABASE '.$schema);}$control->sql_close();if($created_directory){foreach(scandir($base.'/'.$relative.'tmp')as$name){if(preg_match('/^'.$psid.'_derived_[a-f0-9]{32}$/D',$name)){unlink($base.'/'.$relative.'tmp/'.$name);}}if(is_file($base.'/crash-ready')){unlink($base.'/crash-ready');}foreach(array($psid.'_owner',$psid.'_actualdata0',$psid.'_actualdata1')as$name){$path=$base.'/'.$relative.'tmp/'.$name;if(is_file($path)){unlink($path);}}foreach(array_reverse($directories)as$directory){if(is_dir($base.$directory)){rmdir($base.$directory);}}rmdir($base);}restore_error_handler();error_reporting($reporting);}
