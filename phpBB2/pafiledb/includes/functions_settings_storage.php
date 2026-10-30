<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_file_actions.php';

function pafiledb_settings_fields()
{
    return array('settings_dbname','settings_topnumber','settings_newdays','settings_file_page',
        'settings_viewall','settings_disable','hotlink_prevent','hotlink_allowed','sort_method','sort_order',
        'max_file_size','upload_dir','screenshots_dir','forbidden_extensions','auth_search','auth_stats',
        'auth_toplist','auth_viewall','allow_html','allow_bbcode','allow_smilies','allow_comment_links',
        'no_comment_link_message','allow_comment_images','no_comment_image_message','max_comment_chars',
        'need_validation','validator','pm_notify','tpl_php');
}
function pafiledb_settings_error($key = 'PA_settings_invalid') { pafiledb_file_fail($key); }
function pafiledb_settings_text($value)
{
    if (!is_string($value) || strlen($value)>1020 || preg_match('//u',$value)!==1 ||
        preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value) || preg_match_all('/./us',$value)>255) { pafiledb_settings_error(); }
    return $value;
}
function pafiledb_settings_size($value,$unit)
{
    $units=array(''=>1,'b'=>1,'kb'=>1024,'mb'=>1048576);
    if (!is_string($value)||!is_string($unit)||!isset($units[$unit])||!preg_match('/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,7})?$/D',$value)) { pafiledb_settings_error(); }
    $bytes=round((float)$value*$units[$unit]);
    if ($bytes<0 || $bytes>PaFileDbFileStorage::MAX_BYTES) { pafiledb_settings_error('PA_settings_size'); }
    return (string)(int)$bytes;
}
function pafiledb_settings_display_size($bytes)
{
    if (!(is_string($bytes)||is_int($bytes))||!preg_match('/^(?:0|[1-9][0-9]*)$/D',(string)$bytes)) { pafiledb_settings_error(); }
    $unit=(float)$bytes>=1048576?'mb':((float)$bytes>=1024?'kb':'b');$factor=$unit==='mb'?1048576:($unit==='kb'?1024:1);
    $value=rtrim(rtrim(number_format((float)$bytes/$factor,7,'.',''),'0'),'.');
    return array('value'=>$value===''?'0':$value,'unit'=>$unit);
}
// Build the whole proposed state before the first UPDATE. Missing POST values
// retain the current locked value, never an earlier request's cached default.
function pafiledb_settings_values($post,$current)
{
    if (!is_array($post)||!is_array($current)) { pafiledb_settings_error(); }
    $booleans=array('settings_viewall','settings_disable','hotlink_prevent','allow_html','allow_bbcode','allow_smilies','allow_comment_links','allow_comment_images','need_validation','pm_notify');
    $integers=array('settings_topnumber','settings_newdays','settings_file_page','max_comment_chars');
    $auth=array('auth_search','auth_stats','auth_toplist','auth_viewall');$values=$current;
    foreach(pafiledb_settings_fields()as$name){
        if(!array_key_exists($name,$current)){pafiledb_settings_error('PA_file_upgrade');}
        if(!array_key_exists($name,$post)){continue;}$value=phpbb_request_raw_value($post[$name]);$value=pafiledb_settings_text($value);
        if(in_array($name,$booleans,true)){if(!in_array($value,array('0','1'),true)){pafiledb_settings_error();}}
        elseif(in_array($name,$integers,true)){if(!preg_match('/^(?:0|[1-9][0-9]{0,6})$/D',$value)||(float)$value>1000000||($name==='settings_file_page'&&$value==='0')){pafiledb_settings_error();}}
        elseif(in_array($name,$auth,true)){if(!in_array($value,array((string)AUTH_ALL,(string)AUTH_REG,(string)AUTH_ACL,(string)AUTH_MOD,(string)AUTH_ADMIN),true)){pafiledb_settings_error();}}
        elseif($name==='sort_method'){if(!in_array($value,array('file_name','file_time','file_rating','file_dls','file_update_time'),true)){pafiledb_settings_error();}}
        elseif($name==='sort_order'){$value=strtoupper($value);if(!in_array($value,array('ASC','DESC'),true)){pafiledb_settings_error();}}
        elseif($name==='validator'){if(!in_array($value,array('validator_admin','validator_mod'),true)){pafiledb_settings_error();}}
        elseif($name==='max_file_size'){$unit=isset($post['max_size'])?phpbb_request_raw_value($post['max_size']):'b';$value=pafiledb_settings_size($value,is_string($unit)?strtolower($unit):$unit);}
        elseif($name==='upload_dir'||$name==='screenshots_dir'){
            $value=str_replace('\\','/',trim($value));
            if($value===''||preg_match('/[\x00-\x1f\x7f?#:]/',$value)||preg_match('#(^|/)\.\.(/|$)|^/#',$value)){pafiledb_settings_error();}
            $value=rtrim($value,'/').'/';
        }
        $values[$name]=$value;
    }
    $values['tpl_php']='0';return $values;
}
function pafiledb_settings_save($post)
{
    global$db,$table_prefix,$phpbb_root_path;
    $storage=null;
    try{
        if(!defined('IN_ADMIN')||!IN_ADMIN){pafiledb_settings_error('Not_Authorised');}
        $actor=pafiledb_mutation_actor();$storage=new PaFileDbFileCoordinator($db,$table_prefix);$storage->begin_settings();
        $authority=new PaFileDbFileAuthority($storage,$table_prefix,$actor,'settings');$authority->select('settings',array());$storage->guard_commit(function()use($authority){return$authority->check();});
        $current=$authority->settings();$values=pafiledb_settings_values($post,$current);$directories=pafiledb_file_directories($values);
        $root=realpath($phpbb_root_path);if($root===false){pafiledb_settings_error();}
        // Validate both actual configured roots with a read-only capability.
        $files=new PaFileDbFileStorage(function($context){return$context['action']==='roots';},$directories,$root);
        foreach(array('upload_dir'=>'download','screenshots_dir'=>'screenshot')as$key=>$scope){
            if(rtrim($values[$key],'/')!==rtrim($current[$key],'/')){
                $r=$storage->sql_query("SELECT operation_id FROM ".$table_prefix."pa_file_journal WHERE item_state=0 AND file_scope='".$scope."' LIMIT 1 FOR UPDATE");
                try{$pending=$storage->sql_fetchrow($r);}finally{$storage->sql_freeresult($r);}if($pending){pafiledb_settings_error('PA_settings_pending');}
            }
        }
        foreach(pafiledb_settings_fields()as$name){
            if($values[$name]===$current[$name]){continue;}
            $storage->sql_query('UPDATE '.PA_CONFIG_TABLE." SET config_value='".$storage->sql_escape($values[$name])."' WHERE config_name='".$storage->sql_escape($name)."'");
            if((int)$storage->sql_affectedrows()!==1){pafiledb_settings_error('PA_settings_failed');}
        }
        $authority->check();$storage->commit();return $values;
    }finally{if($storage){$storage->release();}}
}
