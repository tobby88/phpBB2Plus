<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__).'/functions_file_actions.php';

function pafiledb_file_delete_rows($storage,$journal,$files,$settings,$root)
{
    if (!$storage->can_publish()||!is_array($files)) { pafiledb_file_fail(); }
    $ids=array();
    foreach($files as$file){
        $id=PaFileDbFileAuthority::id($file['file_id']);if(isset($ids[$id])){pafiledb_file_fail();}$ids[$id]=$id;
        if(PaFileDbFileStorage::filename($file['unique_name'])){
            $directory=$file['file_dir']!==''?$file['file_dir']:$settings['upload_dir'];
            $journal->queue_path($root.'/'.rtrim($directory,'/').'/'.$file['unique_name']);
        }
        $journal->queue_url($file['file_dlurl']);$journal->queue_url($file['file_ssurl']);
    }
    foreach(array_chunk(array_values($ids),500)as$chunk){
        $list=implode(',',$chunk);$r=$storage->sql_query('SELECT * FROM '.PA_MIRRORS_TABLE.' WHERE file_id IN('.$list.') ORDER BY mirror_id FOR UPDATE');
        try{$mirrors=$storage->sql_fetchrowset($r);}finally{$storage->sql_freeresult($r);}
        foreach($mirrors as$mirror){
            if(PaFileDbFileStorage::filename($mirror['unique_name'])){$journal->queue_path($root.'/'.rtrim($mirror['file_dir'],'/').'/'.$mirror['unique_name']);}
            $journal->queue_url($mirror['file_dlurl']);
        }
        foreach(array(PA_COMMENTS_TABLE=>'file_id',PA_VOTES_TABLE=>'votes_file',PA_DOWNLOAD_INFO_TABLE=>'file_id',PA_CUSTOM_DATA_TABLE=>'customdata_file',PA_MIRRORS_TABLE=>'file_id')as$table=>$column){
            $storage->sql_query('DELETE FROM '.$table.' WHERE '.$column.' IN('.$list.')');
        }
        $storage->sql_query('DELETE FROM '.PA_FILES_TABLE.' WHERE file_id IN('.$list.')');
        if((int)$storage->sql_affectedrows()!==count($chunk)){pafiledb_file_fail();}
    }
}

// One request owns the complete selection. Physical cleanup can only begin
// after this metadata transaction and its durable deletion intents commit.
function pafiledb_file_delete($model,$ids)
{
    global $db,$table_prefix,$phpbb_root_path,$lang;
    $storage=$journal=null;$operation=null;$result=null;$root=realpath($phpbb_root_path);$url='';
    try{
        if(!is_array($ids)){$ids=array($ids);}$actor=pafiledb_mutation_actor();
        if(!$ids||count($ids)>500||$root===false||!function_exists('phpbb_board_url')){pafiledb_file_fail('PA_file_invalid');}
        $storage=new PaFileDbFileCoordinator($db,$table_prefix);$storage->begin();
        $authority=new PaFileDbFileAuthority($storage,$table_prefix,$actor,defined('IN_ADMIN')&&IN_ADMIN?'files':'public');
        $files=$authority->select('delete',$ids);$storage->guard_commit(function()use($authority){return$authority->check();});$settings=$authority->settings();$url=phpbb_board_url();
        $journal=new PaFileDbFileJournal($storage,$table_prefix,$actor['id'],pafiledb_file_directories($settings),$url,$root);$operation=$journal->operation_id();
        pafiledb_file_delete_rows($storage,$journal,$files,$settings,$root);
        $storage->sql_query('UPDATE '.PA_CATEGORY_TABLE." SET cat_files=-1,cat_last_file_id=0,cat_last_file_name='',cat_last_file_time=0");
        $authority->check();$storage->commit();$result=true;
    }catch(PaFileDbFileActionFailure$error){$key=$error->getMessage();$model->error[]=isset($lang[$key])?$lang[$key]:$lang['PA_file_failed'];}
    catch(PaFileDbMutationFailure$error){$key=$error->getMessage();$model->error[]=isset($lang[$key])?$lang[$key]:$lang['PA_file_failed'];}
    catch(Exception$error){$model->error[]=$lang['PA_file_failed'];}catch(Error$error){$model->error[]=$lang['PA_file_failed'];}
    finally{
        $model->modified(true);$model->modified(false);if($storage){$storage->release();}
        if($operation!==null&&$root!==false&&$url!==''){pafiledb_file_recover($db,$table_prefix,$operation,$root,$url);}
    }
    return$result;
}
