<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_file_actions.php';

// Download requests are reads from the visitor's perspective. Their counter and
// unique-visitor record nevertheless commit together under the file namespace.
function pafiledb_download_actor()
{
    global $userdata;
    if (!is_array($userdata) || !isset($userdata['user_id'],$userdata['session_id']) || !is_string($userdata['session_id']) || !(is_int($userdata['user_id'])||is_string($userdata['user_id']))) { pafiledb_file_fail('Session_invalid'); }
    $logged=isset($userdata['session_logged_in'])&&in_array($userdata['session_logged_in'],array(true,1,'1'),true);
    $id=$logged?PaFileDbFileAuthority::id($userdata['user_id']):ANONYMOUS;
    if ((!$logged && (string)$userdata['user_id']!==(string)ANONYMOUS) || ($logged && $id>8388607)) { pafiledb_file_fail('Not_Authorised'); }
    return array('id'=>$id,'sid'=>$userdata['session_id'],'logged'=>$logged);
}

function pafiledb_record_download($database, $file_id, $mirror_id, $ip, $metadata)
{
    global $table_prefix;
    $storage=null;
    try {
        $id=PaFileDbFileAuthority::id($file_id);$mirror=($mirror_id===false||$mirror_id===-1||$mirror_id==='-1')?0:PaFileDbFileAuthority::id($mirror_id,true);
        if (!is_string($ip)||!preg_match('/^[a-f0-9]{8}$/iD',$ip)||!is_array($metadata)||count($metadata)!==3) { pafiledb_file_fail('PA_file_invalid'); }
        $bounded=array();foreach(array_values($metadata)as$value){if(!is_string($value)||preg_match('//u',$value)!==1||preg_match('/[\x00-\x1f\x7f]/',$value)){pafiledb_file_fail('PA_file_invalid');}$bounded[]=pafiledb_file_prefix($value,255);}
        $actor=pafiledb_download_actor();$storage=new PaFileDbFileCoordinator($database,$table_prefix);$storage->begin();
        $authority=new PaFileDbFileAuthority($storage,$table_prefix,$actor,'public');$files=$authority->select('download',array($id));$storage->guard_commit(function()use($authority){return$authority->check();});$file=$files[$id];$selected=null;
        if($mirror){$r=$storage->sql_query('SELECT * FROM '.PA_MIRRORS_TABLE.' WHERE mirror_id='.$mirror.' AND file_id='.$id.' FOR UPDATE');try{$rows=$storage->sql_fetchrowset($r);}finally{$storage->sql_freeresult($r);}if(count($rows)!==1){pafiledb_file_fail('File_not_exist');}$selected=$rows[0];}
        $target=$selected===null?$file:$selected;
        $settings=$authority->settings();$directory=$target['file_dir']!==''?$target['file_dir']:(isset($settings['upload_dir'])?$settings['upload_dir']:'');
        if(!is_string($directory)||((string)$target['file_dlurl']===''&&$directory==='')){pafiledb_file_fail('PA_file_upgrade');}
        if((string)$target['file_dlurl']!==''&&pafiledb_normalize_remote_url($target['file_dlurl'])===false){pafiledb_file_fail('PA_file_invalid');}
        if($file['file_dls']!==null&&(!(is_int($file['file_dls'])||is_string($file['file_dls']))||!preg_match('/^(?:0|[1-9][0-9]*)$/D',(string)$file['file_dls'])||(float)$file['file_dls']>=2147483647)){pafiledb_file_fail('PA_file_invalid');}
        $address=$storage->sql_escape(strtolower($ip));$duplicate=$actor['logged']?'user_id='.$actor['id']:"downloader_ip='".$address."'";
        $r=$storage->sql_query('SELECT user_id FROM '.PA_DOWNLOAD_INFO_TABLE.' WHERE file_id='.$id.' AND '.$duplicate.' FOR UPDATE');try{$existing=$storage->sql_fetchrowset($r);}finally{$storage->sql_freeresult($r);}
        $storage->sql_query('UPDATE '.PA_FILES_TABLE.' SET file_dls=COALESCE(file_dls,0)+1,file_last='.time().' WHERE file_id='.$id);if((int)$storage->sql_affectedrows()!==1){pafiledb_file_fail();}
        if(!$existing){$values=array();foreach($bounded as$value){$values[]="'".$storage->sql_escape($value)."'";}$storage->sql_query('INSERT INTO '.PA_DOWNLOAD_INFO_TABLE.' (file_id,user_id,downloader_ip,downloader_os,downloader_browser,browser_version)VALUES('.$id.','.$actor['id'].",'".$address."',".implode(',',$values).')');if((int)$storage->sql_affectedrows()!==1){pafiledb_file_fail();}}
        $authority->check();$storage->commit();return array('file'=>$file,'mirror'=>$selected,'directory'=>$directory);
    }catch(PaFileDbFileActionFailure$error){throw$error;}
    catch(Exception$error){pafiledb_file_fail();}catch(Error$error){pafiledb_file_fail();}
    finally{if($storage){$storage->release();}}
}
