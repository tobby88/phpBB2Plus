<?php
$pu_before=$pc_checks;$pu_created=false;$pu_process=null;$pu_base=sys_get_temp_dir().'/codex_pa_upload_'.bin2hex(phpbb_random_bytes(8));
$pu_token=bin2hex(phpbb_random_bytes(32));$pu_url='';$pu_zip="PK\x03\x04Synthetic archive Grüße 😀";$pu_png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
function pu_request($data=null,$files=array()){
 $headers='X-Fixture-Token: '.$GLOBALS['pu_token']."\r\nConnection: close\r\n";$options=array('method'=>$data===null?'GET':'POST','timeout'=>25,'ignore_errors'=>true,'header'=>$headers);
 if($data!==null){$boundary='CodexBoundary'.bin2hex(phpbb_random_bytes(12));$body='';foreach($data as$key=>$value){$body.='--'.$boundary."\r\nContent-Disposition: form-data; name=\"".$key."\"\r\n\r\n".$value."\r\n";}foreach($files as$key=>$file){$body.='--'.$boundary."\r\nContent-Disposition: form-data; name=\"".$key.'"; filename="'.$file[0]."\"\r\nContent-Type: application/octet-stream\r\n\r\n".$file[1]."\r\n";}$body.='--'.$boundary."--\r\n";$options['header'].='Content-Type: multipart/form-data; boundary='.$boundary."\r\n";$options['content']=$body;}
 $response=@file_get_contents($GLOBALS['pu_url'],false,stream_context_create(array('http'=>$options)));if($response===false){return false;}$decoded=json_decode($response,true);if(!is_array($decoded)){throw new RuntimeException('Upload fixture returned non-JSON response: '.substr($response,0,500));}if(isset($decoded['fixture_error'])){throw new RuntimeException('Actual upload worker error: '.$decoded['fixture_error']);}return$decoded;
}
function pu_post($legacy,$extra=array()){
 return array_merge(array('sid'=>'files-8','cat_id'=>'9','name'=>"Upload O'Reilly \\ &amp; Grüße 😀",'long_desc'=>'Upload description 😀','author'=>'Synthetic','posticon'=>'none','field[1]'=>"O'Reilly \\ <tag> Grüße 😀",'fixture_legacy'=>$legacy?'1':'0'),$extra);
}
function pu_files(){return array('userfile'=>array('synthetic.zip',$GLOBALS['pu_zip']),'screen_shot'=>array('synthetic.png',$GLOBALS['pu_png']));}
function pu_assets(){
 $paths=array();foreach(array('downloads','screenshots')as$dir){foreach(scandir($GLOBALS['pu_base'].'/'.$dir)as$name){if($name==='.'||$name==='..'){continue;}$path=$GLOBALS['pu_base'].'/'.$dir.'/'.$name;if(!is_file($path)||is_link($path)){throw new RuntimeException('Unexpected object in own upload root');}$paths[$dir.'/'.$name]=hash_file('sha256',$path);}}ksort($paths);return$paths;
}
function pu_clear(){foreach(pu_assets()as$path=>$hash){if(!unlink($GLOBALS['pu_base'].'/'.$path)){throw new RuntimeException('Own upload cleanup failed');}}}
function pu_pending(){return pc_rows('SELECT operation_id,item_state,operation_kind FROM fixture_pa_file_journal WHERE item_state=0');}
try{
 pc_check(mkdir($pu_base,0700),'Own HTTP upload root');$pu_created=true;$pu_base=realpath($pu_base);mkdir($pu_base.'/downloads',0700);mkdir($pu_base.'/screenshots',0700);
 $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);pc_check(is_resource($socket),'Reserve own loopback HTTP port');$address=stream_socket_get_name($socket,false);fclose($socket);$pu_url='http://'.$address.'/';
 putenv('PHPBB_PA_UPLOAD_TOKEN='.$pu_token);putenv('PHPBB_PA_UPLOAD_SCHEMA='.$schema);putenv('PHPBB_PA_UPLOAD_ROOT='.$pu_base);
 $command=escapeshellarg(PHP_BINARY).' -n -d date.timezone=UTC -d '.escapeshellarg('extension_dir='.ini_get('extension_dir')).' -d extension=php_mysqli.dll -d extension=php_openssl.dll -S '.escapeshellarg($address).' '.escapeshellarg(__DIR__.'/pafiledb-file-upload-http-worker.php');
 if(DIRECTORY_SEPARATOR!=='\\'){$command='exec '.escapeshellarg(PHP_BINARY).' -d date.timezone=UTC -S '.escapeshellarg($address).' '.escapeshellarg(__DIR__.'/pafiledb-file-upload-http-worker.php');}
 $pu_process=proc_open($command,array(0=>array('pipe','r'),1=>array('file',$pu_base.'/stdout.log','a'),2=>array('file',$pu_base.'/stderr.log','a')),$pipes,null,null,array('bypass_shell'=>true));pc_check(is_resource($pu_process),'Start actual multipart PHP server');fclose($pipes[0]);$ready=false;
 for($attempt=0;$attempt<100;$attempt++){$status=proc_get_status($pu_process);if(!$status['running']){throw new RuntimeException('Upload server stopped: '.file_get_contents($pu_base.'/stderr.log'));}$probe=pu_request();if(is_array($probe)&&!empty($probe['ready'])){$ready=true;break;}usleep(20000);}pc_check($ready,'Actual multipart server is ready');
 foreach(array(false,true)as$legacy){
  pa_reset(8,'public');pc_sql('DELETE FROM fixture_pa_customdata');pc_sql('DELETE FROM fixture_pa_custom');pa_insert('fixture_pa_custom',array('custom_id'=>1,'custom_name'=>'Synthetic','custom_description'=>'Description','data'=>'','regex'=>'','field_type'=>INPUT,'field_order'=>1));pu_clear();
  $created=pu_request(pu_post($legacy),pu_files());pc_check(is_int($created['saved'])&&empty($created['errors'])&&$created['actual_upload']&&$created['actual_screenshot'],'Actual multipart download and image are accepted: '.json_encode($created));$id=$created['saved'];$row=pc_rows('SELECT * FROM fixture_pa_files WHERE file_id='.$id)[0];$download=$pu_base.'/'.$row['file_dir'].$row['unique_name'];$url_path=parse_url($row['file_ssurl'],PHP_URL_PATH);$screenshot=$pu_base.'/'.substr($url_path,strlen('/forum/'));
  pc_check(file_get_contents($download)===$pu_zip&&file_get_contents($screenshot)===$pu_png&&$row['file_size']===(string)strlen($pu_zip),'Actual file and screenshot bytes match committed metadata');pc_check(count(pu_assets())===2&&!pu_pending(),'Committed actual upload retains both referenced assets and completes its intents');
  $before=pu_assets();$snapshot=pc_rows('SELECT * FROM fixture_pa_files WHERE file_id='.$id);$failed=pu_request(pu_post($legacy,array('file_id'=>(string)$id,'fixture_failure'=>'custom')),pu_files());
  pc_check($failed['saved']===null&&!empty($failed['errors'])&&pu_assets()===$before&&pc_rows('SELECT * FROM fixture_pa_files WHERE file_id='.$id)===$snapshot&&!pu_pending(),'Failed real replacement rolls back metadata, preserves old assets and cleans only new unreferenced files');
  $replaced=pu_request(pu_post($legacy,array('file_id'=>(string)$id)),pu_files());$row=pc_rows('SELECT * FROM fixture_pa_files WHERE file_id='.$id)[0];
  pc_check($replaced['saved']===$id&&empty($replaced['errors'])&&count(pu_assets())===2&&!file_exists($download)&&!file_exists($screenshot)&&!pu_pending(),'Successful replacement cleans old download and screenshot only after commit');
  $before=pu_assets();$failed=pu_request(pu_post($legacy,array('fixture_failure'=>'intent-ack')),pu_files());pc_check($failed['saved']===null&&!empty($failed['errors'])&&pu_assets()===$before&&!pu_pending(),'Lost actual preparation COMMIT acknowledgement leaves no published orphan or partial metadata');
  $accepted=pu_request(pu_post($legacy,array('fixture_failure'=>'metadata-ack')),pu_files());pc_check($accepted['saved']===null&&!empty($accepted['errors'])&&count(pu_assets())===4&&!pu_pending(),'Lost actual metadata COMMIT acknowledgement retains all committed referenced bytes');
  $before=pu_assets();$bad=pu_files();$bad['screen_shot'][1]='not an image';$failed=pu_request(pu_post($legacy),$bad);pc_check($failed['saved']===null&&!empty($failed['errors'])&&pu_assets()===$before,'Malformed actual multipart image is rejected before publication');
  pa_reset(7,'files');pu_clear();$created=pu_request(pu_post($legacy,array('sid'=>'files-7','fixture_acp'=>'1','approved'=>'1','pin'=>'1','file_download'=>'5')),pu_files());pc_check(is_int($created['saved'])&&empty($created['errors']),'Current ACP actor can save a real multipart upload');$admin_id=$created['saved'];$row=pc_rows('SELECT * FROM fixture_pa_files WHERE file_id='.$admin_id)[0];
  pc_check($row['file_approved']==='1'&&$row['file_pin']==='1'&&$row['file_dls']==='5'&&$row['user_id']==='7','Actual ACP approval, pin, counter and actor selections are applied');
  $updated=pu_request(pu_post($legacy,array('sid'=>'files-7','fixture_acp'=>'1','file_id'=>(string)$admin_id,'approved'=>'0','download_url'=>'')));$row=pc_rows('SELECT * FROM fixture_pa_files WHERE file_id='.$admin_id)[0];pc_check($updated['saved']===$admin_id&&empty($updated['errors'])&&$row['file_approved']==='0'&&count(pu_assets())===2,'Actual ACP edit can withdraw approval without replacing bytes');
 }
 if(getenv('PHPBB_PA_MIRROR_HTTP_NATIVE')==='1'){require __DIR__.'/pafiledb-mirror-upload-fixture.php';}
 echo 'Native download multipart upload: ',$pc_checks-$pu_before," actual is_uploaded_file/source bytes/screenshot/replace/rollback/lost-ack/raw/legacy checks PASS.\n";
}finally{
 if(is_resource($pu_process)){$status=proc_get_status($pu_process);if($status['running']){proc_terminate($pu_process);}proc_close($pu_process);}
 foreach(array('PHPBB_PA_UPLOAD_TOKEN','PHPBB_PA_UPLOAD_SCHEMA','PHPBB_PA_UPLOAD_ROOT')as$key){putenv($key);}
 if($pu_created){$resolved=realpath($pu_base);if($resolved===false||dirname($resolved)!==realpath(sys_get_temp_dir())||!preg_match('/^codex_pa_upload_[a-f0-9]{16}$/D',basename($resolved))||is_link($pu_base)){throw new RuntimeException('Own multipart root required');}pu_clear();rmdir($pu_base.'/downloads');rmdir($pu_base.'/screenshots');foreach(array('stdout.log','stderr.log')as$name){if(is_file($pu_base.'/'.$name)){unlink($pu_base.'/'.$name);}}rmdir($pu_base);}
}
