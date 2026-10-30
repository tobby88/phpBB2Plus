<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_file_actions.php';

function pafiledb_mirror_text($value)
{
    if (!is_string($value) || strlen($value)>1020 || preg_match('//u',$value)!==1 || preg_match('/[\x00-\x1f\x7f]/',$value) || pafiledb_file_prefix($value,255)!==$value) { pafiledb_file_fail('PA_file_invalid'); }
    return $value;
}

function pafiledb_mirror_upload($upload)
{
    if ($upload===null) { return null; }
    if (!is_array($upload) || !isset($upload['error']) || !(is_int($upload['error'])||is_string($upload['error'])) || !in_array((string)$upload['error'],array((string)UPLOAD_ERR_OK,(string)UPLOAD_ERR_NO_FILE),true)) { pafiledb_file_fail('PA_file_invalid'); }
    if ((string)$upload['error']===(string)UPLOAD_ERR_NO_FILE) { return null; }
    if (!isset($upload['name'],$upload['tmp_name']) || !is_string($upload['name']) || !is_string($upload['tmp_name']) || strlen($upload['name'])>255 || preg_match('//u',$upload['name'])!==1 || !is_uploaded_file($upload['tmp_name'])) { pafiledb_file_fail('PA_file_invalid'); }
    $name=basename(str_replace('\\','/',$upload['name']));$extension=strtolower(pathinfo($name,PATHINFO_EXTENSION));
    if (phpbb_forbidden_upload_extension($extension)) { pafiledb_file_fail('PA_file_invalid'); }
    $size=@filesize($upload['tmp_name']);if(!is_int($size)||$size<1||$size>PaFileDbFileStorage::MAX_BYTES){pafiledb_file_fail('PA_file_invalid');}
    return array('name'=>$name,'tmp_name'=>$upload['tmp_name'],'extension'=>$extension,'size'=>$size);
}

// Decode the legacy request boundary exactly once, retaining each upload's
// actual PHP error. One request is one complete create, edit or delete batch.
function pafiledb_mirror_request_data()
{
    $actions=array();foreach(array('add_new'=>'create','modify'=>'modify','delete_mirrors'=>'delete')as$button=>$action){if(isset($_POST[$button])){$actions[]=$action;}}
    if(count($actions)!==1){pafiledb_file_fail('PA_file_invalid');}$action=$actions[0];
    if($action==='delete'){if(!isset($_POST['mirror_ids'])||!is_array($_POST['mirror_ids'])){pafiledb_file_fail('PA_file_invalid');}return array($action,$_POST['mirror_ids']);}
    if($action==='create'){
        return array($action,array(0=>array('url'=>isset($_POST['new_download_url'])?phpbb_request_raw_value($_POST['new_download_url']):'','location'=>isset($_POST['new_location'])?phpbb_request_raw_value($_POST['new_location']):'','upload'=>isset($_FILES['new_userfile'])?$_FILES['new_userfile']:null)));
    }
    $items=array();foreach(array('download_url'=>'url','location'=>'location')as$field=>$key){
        if(!isset($_POST[$field])){continue;}if(!is_array($_POST[$field])){pafiledb_file_fail('PA_file_invalid');}
        foreach($_POST[$field]as$id=>$value){$id=PaFileDbFileAuthority::id($id);$items[$id][$key]=phpbb_request_raw_value($value);}
    }
    if(isset($_FILES['userfile'])){
        $uploads=$_FILES['userfile'];if(!is_array($uploads)){pafiledb_file_fail('PA_file_invalid');}
        foreach(array('name','tmp_name','size','error','type')as$field){if(!isset($uploads[$field])){continue;}if(!is_array($uploads[$field])){pafiledb_file_fail('PA_file_invalid');}foreach($uploads[$field]as$id=>$value){$id=PaFileDbFileAuthority::id($id);$items[$id]['upload'][$field]=$value;}}
    }
    return array($action,$items);
}

function pafiledb_mirror_legacy_upload($temporary)
{
    if($temporary===''||$temporary==='none'){return null;}
    foreach($_FILES as$upload){
        if(!is_array($upload)||!isset($upload['tmp_name'],$upload['error'])){continue;}
        if(is_string($upload['tmp_name'])&&$upload['tmp_name']===$temporary){return$upload;}
        if(is_array($upload['tmp_name'])&&is_array($upload['error'])){foreach($upload['tmp_name']as$id=>$path){if(is_string($path)&&$path===$temporary){$item=array();foreach(array('name','tmp_name','size','error','type')as$field){if(isset($upload[$field][$id])){$item[$field]=$upload[$field][$id];}}return$item;}}}
    }
    // A compatibility call cannot manufacture UPLOAD_ERR_OK or convert a
    // partial/failed SAPI upload into an accepted publication.
    return array('error'=>UPLOAD_ERR_PARTIAL);
}

function pafiledb_mirror_queue_old($journal,$row,$root)
{
    if(PaFileDbFileStorage::filename($row['unique_name'])){$journal->queue_path($root.'/'.rtrim($row['file_dir'],'/').'/'.$row['unique_name']);}
    $journal->queue_url($row['file_dlurl']);
}

function pafiledb_mirror_change($model,$file_id,$action,$payload)
{
    global $db,$table_prefix,$phpbb_root_path,$pafiledb_functions,$lang;
    $storage=$journal=null;$operation=null;$result=null;$root=realpath($phpbb_root_path);$url='';
    try{
        $id=PaFileDbFileAuthority::id($file_id);$actor=pafiledb_mutation_actor();
        if(!defined('IN_ADMIN')||!IN_ADMIN||!in_array($action,array('create','modify','delete'),true)||!is_array($payload)||!$payload||count($payload)>500||$root===false){pafiledb_file_fail('PA_file_invalid');}
        $storage=new PaFileDbFileCoordinator($db,$table_prefix);$storage->begin();$authority=new PaFileDbFileAuthority($storage,$table_prefix,$actor,'files');
        $authority->select($action==='delete'?'mirror-delete':'mirror',array($id));$storage->guard_commit(function()use($authority){return$authority->check();});
        $settings=$authority->settings();$directories=pafiledb_file_directories($settings);$user=$authority->user();$url=phpbb_board_url();
        $journal=new PaFileDbFileJournal($storage,$table_prefix,$actor['id'],$directories,$url,$root);$operation=$journal->operation_id();
        $r=$storage->sql_query('SELECT * FROM '.PA_MIRRORS_TABLE.' WHERE file_id='.$id.' ORDER BY mirror_id FOR UPDATE');try{$rows=$storage->sql_fetchrowset($r);}finally{$storage->sql_freeresult($r);}$existing=array();foreach($rows as$row){$existing[(int)$row['mirror_id']]=$row;}
        if($action==='delete'){
            $selected=array();foreach($payload as$mirror){$mirror=PaFileDbFileAuthority::id($mirror);if(isset($selected[$mirror])||!isset($existing[$mirror])){pafiledb_file_fail('PA_file_invalid');}$selected[$mirror]=$existing[$mirror];}
            foreach($selected as$row){pafiledb_mirror_queue_old($journal,$row,$root);}
            $storage->sql_query('DELETE FROM '.PA_MIRRORS_TABLE.' WHERE file_id='.$id.' AND mirror_id IN('.implode(',',array_keys($selected)).')');
            if((int)$storage->sql_affectedrows()!==count($selected)){pafiledb_file_fail();}$result=true;
        }else{
            if($action==='create'&&(count($payload)!==1||!array_key_exists(0,$payload))){pafiledb_file_fail('PA_file_invalid');}
            $prepared=array();
            // Validate ALL rows before the first publication or metadata write.
            foreach($payload as$mirror=>$item){
                if($action==='modify'){$mirror=PaFileDbFileAuthority::id($mirror);if(!isset($existing[$mirror])){pafiledb_file_fail('File_not_exist');}}
                if(!is_array($item)||array_diff(array_keys($item),array('url','location','upload'))){pafiledb_file_fail('PA_file_invalid');}$old=$action==='modify'?$existing[$mirror]:null;
                $location=pafiledb_mirror_text(array_key_exists('location',$item)?$item['location']:($old===null?'':$old['mirror_location']));if($old===null&&trim($location)===''){pafiledb_file_fail('Missing_field');}
                $remote=pafiledb_mirror_text(array_key_exists('url',$item)?$item['url']:($old===null?'':$old['file_dlurl']));$upload=pafiledb_mirror_upload(isset($item['upload'])?$item['upload']:null);
                if($upload!==null){$forbidden=array_map('strtolower',array_map('trim',explode(',',$settings['forbidden_extensions'])));if($remote!==''||in_array($upload['extension'],$forbidden,true)||((int)$user['user_level']!==ADMIN&&$upload['size']>(int)$settings['max_file_size'])){pafiledb_file_fail('PA_file_invalid');}}
                if($remote!==''){$normalized=pafiledb_normalize_remote_url($remote);if($normalized===false){pafiledb_file_fail('PA_file_invalid');}$remote=pafiledb_mirror_text($normalized);}
                $replace=$upload!==null||($remote!==''&&($old===null||$remote!==$old['file_dlurl']));
                if(!$replace&&$old===null){pafiledb_file_fail('Missing_field');}
                $prepared[$mirror]=array('old'=>$old,'location'=>$location,'url'=>$replace?$remote:$old['file_dlurl'],'upload'=>$upload,'unique_name'=>$replace?'':$old['unique_name'],'file_dir'=>$replace?$settings['upload_dir']:$old['file_dir'],'replace'=>$replace);
            }
            $io=new PaFileDbJournalFunctions($pafiledb_functions,$journal,$settings,$user,$root,$url);
            // Independent upload receipts must all COMMIT before this writer
            // inserts deletion receipts into the same journal operation.
            foreach($prepared as$mirror=>$item){if($item['upload']!==null){$name=bin2hex(phpbb_random_bytes(16)).'.'.$item['upload']['extension'];$io->upload_file($item['upload']['tmp_name'],$name,0,$settings['upload_dir']);$prepared[$mirror]['unique_name']=$name;}}
            $saved=array();foreach($prepared as$mirror=>$item){
                $values=array();foreach(array('unique_name','file_dir')as$field){$values[$field]=$storage->sql_escape($item[$field]);}$values['file_dlurl']=$storage->sql_escape($item['url']);$values['mirror_location']=$storage->sql_escape($item['location']);
                if($action==='create'){$storage->sql_query('INSERT INTO '.PA_MIRRORS_TABLE.' (file_id,unique_name,file_dir,file_dlurl,mirror_location)VALUES('.$id.", '".$values['unique_name']."','".$values['file_dir']."','".$values['file_dlurl']."','".$values['mirror_location']."')");if((int)$storage->sql_affectedrows()!==1){pafiledb_file_fail();}$saved[]=PaFileDbFileAuthority::id($storage->sql_nextid());}
                else{$assign=array();foreach($values as$field=>$value){$assign[]=$field."='".$value."'";}$storage->sql_query('UPDATE '.PA_MIRRORS_TABLE.' SET '.implode(',',$assign).' WHERE file_id='.$id.' AND mirror_id='.$mirror);$saved[]=$mirror;}
            }
            foreach($prepared as$item){if($item['old']!==null&&$item['replace']){pafiledb_mirror_queue_old($journal,$item['old'],$root);}}$result=$saved;
        }
        $authority->check();$storage->commit();return$result;
    }catch(PaFileDbFileActionFailure$error){$key=$error->getMessage();$model->error[]=isset($lang[$key])?$lang[$key]:$lang['PA_file_failed'];}
    catch(PaFileDbMutationFailure$error){$key=$error->getMessage();$model->error[]=isset($lang[$key])?$lang[$key]:$lang['PA_file_failed'];}
    catch(Exception$error){$model->error[]=$lang['PA_file_failed'];}catch(Error$error){$model->error[]=$lang['PA_file_failed'];}
    finally{$model->modified(false);if($storage){$storage->release();}if($operation!==null&&$root!==false&&$url!==''){pafiledb_file_recover($db,$table_prefix,$operation,$root,$url);}}
    return null;
}
