<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_file_actions.php';

function pafiledb_license_values($post)
{
    if (!is_array($post) || !isset($post['form']) || !is_array($post['form'])) { pafiledb_file_fail('PA_license_invalid'); }
    $values=array();
    foreach(array('name','text')as$key){
        $value=isset($post['form'][$key])?phpbb_request_raw_value($post['form'][$key]):'';
        // Both canonical fields are TEXT: its bound is bytes, not characters.
        if(!is_string($value)||strlen($value)>65535||preg_match('//u',$value)!==1||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value)){pafiledb_file_fail('PA_license_invalid');}
        $values[$key]=$key==='name'?trim($value):$value;
    }
    if($values['name']===''){pafiledb_file_fail('PA_license_invalid');}return$values;
}

function pafiledb_license_rows($storage,$sql)
{
    $r=$storage->sql_query($sql);try{return$storage->sql_fetchrowset($r);}finally{$storage->sql_freeresult($r);}
}

// License CRUD and clearing every affected file reference are one owned unit.
// It uses the same namespace as uploads: a stale dropdown cannot create a new
// reference to a license that was just removed by another administrator.
function pafiledb_license_save($action,$post)
{
    global$db,$table_prefix;
    $storage=null;
    try{
        if(!defined('IN_ADMIN')||!IN_ADMIN||!in_array($action,array('add','edit','delete'),true)||!is_array($post)||PA_LICENSE_TABLE!==$table_prefix.'pa_license'){pafiledb_file_fail('Not_Authorised');}
        $actor=pafiledb_mutation_actor();$ids=array();$values=array();
        if($action==='delete'){
            if(empty($post['select'])||!is_array($post['select'])||count($post['select'])>500){pafiledb_file_fail('PA_license_invalid');}
            foreach($post['select']as$key=>$value){if($value!=='yes'){pafiledb_file_fail('PA_license_invalid');}$id=PaFileDbFileAuthority::id($key);$ids[$id]=$id;}
        }else{
            $values=pafiledb_license_values($post);
            if($action==='edit'){$id=PaFileDbFileAuthority::id(isset($post['id'])?$post['id']:null);$ids[$id]=$id;}
        }
        ksort($ids,SORT_NUMERIC);$storage=new PaFileDbFileCoordinator($db,$table_prefix);$storage->begin_licenses();
        $authority=new PaFileDbFileAuthority($storage,$table_prefix,$actor,'licenses');$authority->select('licenses',array());$storage->guard_commit(function()use($authority){return$authority->check();});
        if($ids){$rows=pafiledb_license_rows($storage,'SELECT license_id FROM '.PA_LICENSE_TABLE.' WHERE license_id IN('.implode(',',$ids).') ORDER BY license_id FOR UPDATE');if(count($rows)!==count($ids)){pafiledb_file_fail('License_not_exist');}}
        if($action==='delete'){
            $list=implode(',',$ids);
            pafiledb_license_rows($storage,'SELECT file_id FROM '.PA_FILES_TABLE.' WHERE file_license IN('.$list.') ORDER BY file_id FOR UPDATE');
            $storage->sql_query('UPDATE '.PA_FILES_TABLE.' SET file_license=0 WHERE file_license IN('.$list.')');
            $storage->sql_query('DELETE FROM '.PA_LICENSE_TABLE.' WHERE license_id IN('.$list.')');
            if((int)$storage->sql_affectedrows()!==count($ids)){pafiledb_file_fail('PA_license_failed');}
            $saved=array_values($ids);
        }else{
            $name=$storage->sql_escape($values['name']);$text=$storage->sql_escape($values['text']);
            if($action==='add'){$storage->sql_query('INSERT INTO '.PA_LICENSE_TABLE." (license_name,license_text) VALUES('".$name."','".$text."')");$id=PaFileDbFileAuthority::id($storage->sql_nextid());}
            else{$storage->sql_query('UPDATE '.PA_LICENSE_TABLE." SET license_name='".$name."',license_text='".$text."' WHERE license_id=".$id);}
            $rows=pafiledb_license_rows($storage,'SELECT license_name,license_text FROM '.PA_LICENSE_TABLE.' WHERE license_id='.$id.' FOR UPDATE');
            if(count($rows)!==1||$rows[0]['license_name']!==$values['name']||$rows[0]['license_text']!==$values['text']){pafiledb_file_fail('PA_license_failed');}$saved=$id;
        }
        $authority->check();$storage->commit();return$saved;
    }finally{if($storage){$storage->release();}}
}
