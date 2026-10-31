<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_file_actions.php';

function pafiledb_custom_rows($storage,$sql)
{
    $r=$storage->sql_query($sql);try{return$storage->sql_fetchrowset($r);}finally{$storage->sql_freeresult($r);}
}
function pafiledb_custom_text($post,$key)
{
    $value=isset($post[$key])?phpbb_request_raw_value($post[$key]):'';
    if(!is_string($value)||strlen($value)>65535||preg_match('//u',$value)!==1||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value)){pafiledb_file_fail('PA_custom_invalid');}
    return str_replace(array("\r\n","\r"),"\n",$value);
}
function pafiledb_custom_values($post,$current)
{
    $type=PaFileDbFileAuthority::id(isset($post['field_type'])?$post['field_type']:($current?$current['field_type']:null),true);
    if(!in_array($type,array(PA_FIELD_INPUT,PA_FIELD_TEXTAREA,PA_FIELD_RADIO,PA_FIELD_SELECT,PA_FIELD_SELECT_MULTIPLE,PA_FIELD_CHECKBOX),true)||($current&&(string)$type!==(string)$current['field_type'])){pafiledb_file_fail('PA_custom_invalid');}
    $values=array('custom_name'=>htmlspecialchars(trim(pafiledb_custom_text($post,'field_name')),ENT_QUOTES,'UTF-8'),'custom_description'=>htmlspecialchars(pafiledb_custom_text($post,'field_desc'),ENT_QUOTES,'UTF-8'),'data'=>'','regex'=>'','field_type'=>(string)$type);
    if($values['custom_name']===''){pafiledb_file_fail('PA_custom_invalid');}
    if($type===PA_FIELD_INPUT||$type===PA_FIELD_TEXTAREA){
        $regex=pafiledb_custom_text($post,'regex');
        if(strlen($regex)>1020||preg_match_all('/./us',$regex)>255||($regex!==''&&@preg_match('#'.$regex.'#siU','')===false)){pafiledb_file_fail('PA_custom_regex');}$values['regex']=$regex;
    }else{
        $text=trim(pafiledb_custom_text($post,'data'));if($text===''){pafiledb_file_fail('PA_custom_invalid');}$choices=array();
        foreach(explode("\n",$text)as$choice){$choices[]=htmlspecialchars(trim($choice),ENT_QUOTES,'UTF-8');}
        $values['data']=serialize($choices);
    }
    foreach(array('custom_name','custom_description','data')as$key){if(strlen($values[$key])>65535){pafiledb_file_fail('PA_custom_invalid');}}
    $values['field_order']=$current?(string)PaFileDbFileAuthority::id(isset($post['field_order'])?$post['field_order']:$current['field_order'],true):'0';
    return$values;
}

// Definition/order changes and deletion of their stored per-file values use
// the same owned namespace as file saves. Never borrow the caller transaction.
function pafiledb_custom_save($action,$post)
{
    global$db,$table_prefix;
    $storage=null;
    try{
        if(!defined('IN_ADMIN')||!IN_ADMIN||!in_array($action,array('save','delete'),true)||!is_array($post)||PA_CUSTOM_TABLE!==$table_prefix.'pa_custom'||PA_CUSTOM_DATA_TABLE!==$table_prefix.'pa_customdata'){pafiledb_file_fail('Not_Authorised');}
        $actor=pafiledb_mutation_actor();$ids=array();$id=0;
        if($action==='delete'){
            if(empty($post['field_ids'])||!is_array($post['field_ids'])||count($post['field_ids'])>500){pafiledb_file_fail('PA_custom_invalid');}
            foreach($post['field_ids']as$key=>$value){if($value!=='yes'){pafiledb_file_fail('PA_custom_invalid');}$selected=PaFileDbFileAuthority::id($key);$ids[$selected]=$selected;}
        }else{$id=PaFileDbFileAuthority::id(isset($post['field_id'])?$post['field_id']:null,true);if($id){$ids[$id]=$id;}}
        ksort($ids,SORT_NUMERIC);$storage=new PaFileDbFileCoordinator($db,$table_prefix);$storage->begin_custom_fields();
        $authority=new PaFileDbFileAuthority($storage,$table_prefix,$actor,'custom');$authority->select('custom',array());$storage->guard_commit(function()use($authority){return$authority->check();});
        $current=null;
        if($ids){$rows=pafiledb_custom_rows($storage,'SELECT * FROM '.PA_CUSTOM_TABLE.' WHERE custom_id IN('.implode(',',$ids).') ORDER BY custom_id FOR UPDATE');if(count($rows)!==count($ids)){pafiledb_file_fail('PA_custom_missing');}$current=$rows[0];}
        if($action==='delete'){
            $list=implode(',',$ids);pafiledb_custom_rows($storage,'SELECT customdata_file,customdata_custom FROM '.PA_CUSTOM_DATA_TABLE.' WHERE customdata_custom IN('.$list.') ORDER BY customdata_file,customdata_custom FOR UPDATE');
            $storage->sql_query('DELETE FROM '.PA_CUSTOM_DATA_TABLE.' WHERE customdata_custom IN('.$list.')');$storage->sql_query('DELETE FROM '.PA_CUSTOM_TABLE.' WHERE custom_id IN('.$list.')');
            if((int)$storage->sql_affectedrows()!==count($ids)){pafiledb_file_fail('PA_custom_failed');}$saved=array_values($ids);
        }else{
            $values=pafiledb_custom_values($post,$current);$assignments=array();foreach($values as$key=>$value){$assignments[]=$key."='".$storage->sql_escape($value)."'";}
            if(!$id){$storage->sql_query('INSERT INTO '.PA_CUSTOM_TABLE.' SET '.implode(',',$assignments));$id=PaFileDbFileAuthority::id($storage->sql_nextid());$values['field_order']=(string)$id;$storage->sql_query('UPDATE '.PA_CUSTOM_TABLE.' SET field_order='.$id.' WHERE custom_id='.$id);}
            else{$storage->sql_query('UPDATE '.PA_CUSTOM_TABLE.' SET '.implode(',',$assignments).' WHERE custom_id='.$id);}
            $rows=pafiledb_custom_rows($storage,'SELECT * FROM '.PA_CUSTOM_TABLE.' WHERE custom_id='.$id.' FOR UPDATE');if(count($rows)!==1){pafiledb_file_fail('PA_custom_failed');}foreach($values as$key=>$value){if($rows[0][$key]!==$value){pafiledb_file_fail('PA_custom_failed');}}$saved=$id;
        }
        $authority->check();$storage->commit();return$saved;
    }finally{if($storage){$storage->release();}}
}
