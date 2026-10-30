<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_file_coordinator.php';
require_once dirname(__FILE__) . '/functions_file_authority.php';
require_once dirname(__FILE__) . '/functions_file_journal.php';
require_once dirname(__FILE__) . '/functions_mutation_storage.php';
require_once dirname(__FILE__) . '/functions_field.php';

function pafiledb_file_prefix($text, $length)
{
    if (!is_string($text) || preg_match('//u', $text) !== 1 || !preg_match('/^.{0,' . (int)$length . '}/us', $text, $match)) { pafiledb_file_fail('PA_file_invalid'); }
    return $match[0];
}

function pafiledb_file_form_values()
{
    $values = array();
    foreach (array('name','long_desc','short_desc','author','version','website','posticon','screen_shot_url','download_url') as $field) {
        $value = isset($_POST[$field]) ? phpbb_request_raw_value($_POST[$field]) : '';
        if (!is_string($value) || strlen($value) > 65535 || preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) { pafiledb_file_fail('PA_file_invalid'); }
        $values[$field] = $value;
    }
    if (trim($values['name']) === '' || trim($values['long_desc']) === '') { pafiledb_file_fail('Missing_field'); }
    foreach (array('license','file_download') as $field) { if (isset($_POST[$field])) { PaFileDbFileAuthority::id($_POST[$field],true); } }
    foreach (array('pin','approved','sshot_link') as $field) { if (isset($_POST[$field]) && (!(is_int($_POST[$field]) || is_string($_POST[$field])) || !in_array((string)$_POST[$field],array('0','1'),true))) { pafiledb_file_fail('PA_file_invalid'); } }
    foreach (array('userfile','screen_shot') as $field) {
        if (!isset($_FILES[$field])) { continue; } $upload = $_FILES[$field];
        if (!is_array($upload) || !isset($upload['error']) || !(is_int($upload['error']) || is_string($upload['error'])) || !in_array((string)$upload['error'], array((string)UPLOAD_ERR_OK,(string)UPLOAD_ERR_NO_FILE), true)) { pafiledb_file_fail('PA_file_invalid'); }
        if ((string)$upload['error'] === (string)UPLOAD_ERR_NO_FILE) { continue; }
        if (!isset($upload['name'],$upload['tmp_name']) || !is_string($upload['name']) || !is_string($upload['tmp_name']) || strlen($upload['name']) > 255 || preg_match('//u', $upload['name']) !== 1 || !is_uploaded_file($upload['tmp_name'])) { pafiledb_file_fail('PA_file_invalid'); }
        if ($field === 'screen_shot' && (!preg_match('/\.(?:gif|jpe?g|png)$/iD', $upload['name']) || @getimagesize($upload['tmp_name']) === false)) { pafiledb_file_fail('PA_file_invalid'); }
    }
    return $values;
}

function pafiledb_file_form_draft($model, $functions)
{
    $draft=array();
    foreach (array('name'=>'file_name','short_desc'=>'file_desc','long_desc'=>'file_long_desc','author'=>'file_author','version'=>'file_version','website'=>'file_website','screen_shot_url'=>'file_ssurl','download_url'=>'file_url') as $posted=>$binding) {
        $value=isset($_POST[$posted]) && is_string($_POST[$posted]) ? phpbb_request_raw_value($_POST[$posted]) : '';
        // pafiledb_html preserves existing entities; encode raw drafts first so
        // literal entity text survives a rejected form unchanged.
        $draft[$binding]=htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    }
    $number=function($key){return isset($_POST[$key]) && (is_string($_POST[$key])||is_int($_POST[$key])) && preg_match('/^[0-9]{1,10}$/D',(string)$_POST[$key]) && (float)$_POST[$key]<=2147483647 ? (int)$_POST[$key] : 0;};
    $cat=$number('cat_id'); $draft['file_cat_list']=$model->jumpmenu_option(0,0,array($cat=>1),true,true);
    $draft['file_license']=$functions->license_list($number('license'));
    $draft['file_posticons']=$functions->post_icons(isset($_POST['posticon']) && is_string($_POST['posticon']) ? phpbb_request_raw_value($_POST['posticon']) : 'none');
    $draft['file_download']=$number('file_download');
    foreach(array('pin'=>'pin','approved'=>'approved','sshot_link'=>'ss')as$key=>$binding){$yes=$number($key)===1;$draft[$binding.'_checked_yes']=$yes?' checked':'';$draft[$binding.'_checked_no']=$yes?'':' checked';}
    return $draft;
}

function pafiledb_file_directories($settings)
{
    foreach (array('upload_dir','screenshots_dir','max_file_size','need_validation','forbidden_extensions') as $key) { if (!isset($settings[$key]) || !is_string($settings[$key])) { pafiledb_file_fail('PA_file_upgrade'); } }
    PaFileDbFileAuthority::id($settings['max_file_size'], true);
    if (!in_array($settings['need_validation'], array('0','1'), true)) { pafiledb_file_fail('PA_file_upgrade'); }
    return array('download'=>$settings['upload_dir'], 'screenshot'=>$settings['screenshots_dir']);
}

// Preserve the existing presentation helpers while replacing all destructive
// I/O reached by the legacy metadata parser with journaled operations.
class PaFileDbJournalFunctions
{
    private $original;
    private $journal;
    private $settings;
    private $user;
    private $root;
    private $url;
    function __construct($original, $journal, $settings, $user, $root, $url)
    {
        if (!is_object($original)) { pafiledb_file_fail(); }
        $this->original=$original; $this->journal=$journal; $this->settings=$settings; $this->user=$user; $this->root=$root; $this->url=rtrim($url,'/');
    }
    function __call($method, $arguments) { return call_user_func_array(array($this->original,$method),$arguments); }
    function pafiledb_unlink($path) { return $this->journal->queue_path($path); }
    function upload_file($input, $name, $declared_size, $directory = '', $local = false)
    {
        if (!is_string($input) || !is_string($name) || !PaFileDbFileStorage::filename($name) || !is_bool($local) || (!$local && !is_uploaded_file($input))) { pafiledb_file_fail('PA_file_invalid'); }
        $scope=null; foreach (array('download'=>'upload_dir','screenshot'=>'screenshots_dir') as $candidate=>$key) { if (rtrim($directory,'/')===rtrim($this->settings[$key],'/')) { $scope=$candidate; break; } }
        if ($scope===null) { pafiledb_file_fail('PA_file_invalid'); }
        $size=@filesize($input); if (!is_int($size) || $size<1 || $size>PaFileDbFileStorage::MAX_BYTES) { pafiledb_file_fail('PA_file_invalid'); }
        if ((int)$this->user['user_level']!==ADMIN && $size>(int)$this->settings['max_file_size']) { pafiledb_file_fail('Filetoobig'); }
        $stream=@fopen($input,'rb'); if (!$stream) { pafiledb_file_fail('PA_file_invalid'); }
        try { $metadata=$this->journal->publish($scope,$name,$stream); } finally { fclose($stream); }
        return array('error'=>false,'size'=>$metadata['bytes'],'url'=>$this->url.'/'.trim($directory,'/').'/'.rawurlencode($name));
    }
}

// This is intentionally independent of the caller's connection/transaction.
// A completed metadata action remains accepted even if cleanup must be retried.
function pafiledb_file_recover($database, $prefix, $operation, $root, $url)
{
    $storage=null;
    try {
        $storage=new PaFileDbFileCoordinator($database,$prefix); $storage->begin();
        $r=$storage->sql_query('SELECT config_name,config_value FROM '.$prefix.'pa_config ORDER BY config_name FOR UPDATE');
        try { $settings=array(); foreach ($storage->sql_fetchrowset($r) as $row) { $settings[$row['config_name']]=$row['config_value']; } } finally { $storage->sql_freeresult($r); }
        $journal=new PaFileDbFileJournal($storage,$prefix,-1,pafiledb_file_directories($settings),$url,$root);
        $result=$journal->resolve($operation,50); if ($result['selected']) { $storage->commit(); } return $result;
    } catch (Exception $error) { return false; }
    catch (Error $error) { return false; }
    finally { if ($storage) { $storage->release(); } }
}

// Metadata-only actions still own their connection and the same namespace as
// upload/category changes. The complete batch is authorized before any write.
function pafiledb_file_metadata_action($model, $action, $ids, $body)
{
    global $db,$userdata,$pafiledb_config,$table_prefix,$lang;
    $original_db=$db; $original_user=$userdata; $original_settings=$pafiledb_config; $storage=null; $result=null;
    try {
        if (!is_callable($body) || !is_array($ids) || !$ids || count($ids)>500 || !in_array($action,array('approve'),true)) { pafiledb_file_fail('PA_file_invalid'); }
        $actor=pafiledb_mutation_actor(); $storage=new PaFileDbFileCoordinator($original_db,$table_prefix); $storage->begin();
        $authority=new PaFileDbFileAuthority($storage,$table_prefix,$actor,defined('IN_ADMIN')&&IN_ADMIN?'files':'public');
        $selected=$authority->select($action,$ids); $storage->guard_commit(function()use($authority){return$authority->check();});
        $db=$storage;foreach($authority->user() as$key=>$value){if(!is_int($key)){$userdata[$key]=$value;}}$pafiledb_config=array_merge($original_settings,$authority->settings());
        $model->init(); $saved=call_user_func($body,$storage,$selected); if(!empty($model->error)||$saved!==true){pafiledb_file_fail();}
        $storage->sql_query('UPDATE '.PA_CATEGORY_TABLE." SET cat_files=-1,cat_last_file_id=0,cat_last_file_name='',cat_last_file_time=0");
        $model->sync_all();$model->modified(false);$authority->check();$storage->commit();$result=true;
    } catch(PaFileDbFileActionFailure$error){$key=$error->getMessage();$model->error[]=isset($lang[$key])?$lang[$key]:(isset($lang['PA_file_failed'])?$lang['PA_file_failed']:'The file operation could not be confirmed.');}
    catch(PaFileDbMutationFailure$error){$key=$error->getMessage();$model->error[]=isset($lang[$key])?$lang[$key]:(isset($lang['PA_file_failed'])?$lang['PA_file_failed']:'The file operation could not be confirmed.');}
    catch(Exception$error){$model->error[]=isset($lang['PA_file_failed'])?$lang['PA_file_failed']:'The file operation could not be confirmed.';}
    catch(Error$error){$model->error[]=isset($lang['PA_file_failed'])?$lang['PA_file_failed']:'The file operation could not be confirmed.';}
    finally{$db=$original_db;$userdata=$original_user;$pafiledb_config=$original_settings;$model->modified(false);if($storage){$storage->release();}}
    return$result;
}

function pafiledb_file_set_approval($model, $mode, $ids)
{
    if(!is_array($ids)){$ids=array($ids);}
    $body=function($storage,$selected)use($mode){
        if(!in_array($mode,array('do_approve','do_unapprove'),true)){pafiledb_file_fail('PA_file_invalid');}
        $storage->sql_query('UPDATE '.PA_FILES_TABLE.' SET file_approved='.($mode==='do_approve'?1:0).' WHERE file_id IN('.implode(',',array_keys($selected)).')');return true;
    };
    return pafiledb_file_metadata_action($model,'approve',$ids,$body);
}

function pafiledb_file_save($model, $file_id, $fields, $body)
{
    global $db,$userdata,$pafiledb_config,$pafiledb_functions,$phpbb_root_path,$table_prefix,$lang;
    $original_db=$db; $original_user=$userdata; $original_settings=$pafiledb_config; $original_functions=$pafiledb_functions; $original_post=$_POST;
    $storage=$journal=null; $operation=null; $result=null; $root=realpath($phpbb_root_path); $url='';
    try {
        $id=$file_id===false?0:PaFileDbFileAuthority::id($file_id,true); $actor=pafiledb_mutation_actor(); pafiledb_file_form_values();
        $destination=PaFileDbFileAuthority::id(isset($_POST['cat_id'])?$_POST['cat_id']:null);
        if (!is_callable($body) || $root===false || !function_exists('phpbb_board_url')) { pafiledb_file_fail(); } $url=phpbb_board_url();
        if ($fields===null) { $fields=new custom_field(); } if (!($fields instanceof custom_field)) { pafiledb_file_fail('PA_file_invalid'); }
        $storage=new PaFileDbFileCoordinator($original_db,$table_prefix); $storage->begin();
        $mode=defined('IN_ADMIN')&&IN_ADMIN?'files':'public'; $authority=new PaFileDbFileAuthority($storage,$table_prefix,$actor,$mode);
        $selected=$authority->select($id?'edit':'create',$id?array($id):array(),$destination); $storage->guard_commit(function()use($authority){return$authority->check();});
        $license=isset($_POST['license'])?PaFileDbFileAuthority::id($_POST['license'],true):0;
        if($license){$r=$storage->sql_query('SELECT license_id FROM '.PA_LICENSE_TABLE.' WHERE license_id='.$license.' FOR UPDATE');try{$exists=$storage->sql_fetchrow($r);}finally{$storage->sql_freeresult($r);}if(!$exists){pafiledb_file_fail('License_not_exist');}}
        $db=$storage; $fresh=$authority->user(); foreach ($fresh as $key=>$value) { if (!is_int($key)) { $userdata[$key]=$value; } }
        $pafiledb_config=array_merge($original_settings,$authority->settings()); $directories=pafiledb_file_directories($pafiledb_config);
        $journal=new PaFileDbFileJournal($storage,$table_prefix,$actor['id'],$directories,$url,$root); $operation=$journal->operation_id();
        $pafiledb_functions=new PaFileDbJournalFunctions($original_functions,$journal,$pafiledb_config,$fresh,$root,$url);
        if ($mode==='public') { $_POST['file_download']=$id?$selected[$id]['file_dls']:'0'; $_POST['pin']=$id?$selected[$id]['file_pin']:'0'; }
        $r=$storage->sql_query('SELECT custom_id FROM '.PA_CUSTOM_TABLE.' ORDER BY custom_id FOR UPDATE');$storage->sql_freeresult($r);
        $fields->init($id); $fields->file_data_values(); $model->init(); $saved=call_user_func($body);
        if (!empty($model->error)) { pafiledb_file_fail('PA_file_invalid'); } $saved=PaFileDbFileAuthority::id($saved);
        if ($id && $saved!==$id) { pafiledb_file_fail(); }
        $r=$storage->sql_query('SELECT * FROM '.PA_FILES_TABLE.' WHERE file_id='.$saved.' FOR UPDATE');
        try { $rows=$storage->sql_fetchrowset($r); } finally { $storage->sql_freeresult($r); }
        if (count($rows)!==1 || (int)$rows[0]['file_catid']!==$destination || (int)$rows[0]['user_id']!==($id?(int)$selected[$id]['user_id']:$actor['id'])) { pafiledb_file_fail(); }
        if ($id && (string)$selected[$id]['file_ssurl']!==(string)$rows[0]['file_ssurl']) { $journal->queue_url($selected[$id]['file_ssurl']); }
        $fields->file_update_data($saved);
        $storage->sql_query('UPDATE '.PA_CATEGORY_TABLE." SET cat_files=-1,cat_last_file_id=0,cat_last_file_name='',cat_last_file_time=0");
        $model->sync_all(); $model->modified(false); $authority->check(); $storage->commit(); $result=$saved;
    } catch (PaFileDbFileActionFailure $error) {
        if (empty($model->error)) { $key=$error->getMessage(); $model->error[]=isset($lang[$key])?$lang[$key]:(isset($lang['PA_file_failed'])?$lang['PA_file_failed']:'The file operation could not be confirmed. Reload the file list before retrying.'); }
    } catch (PaFileDbMutationFailure $error) {
        if (empty($model->error)) { $key=$error->getMessage(); $model->error[]=isset($lang[$key])?$lang[$key]:'The file operation could not be confirmed. Reload the file list before retrying.'; }
    } catch (Exception $error) {
        if (empty($model->error)) { $model->error[]=isset($lang['PA_file_failed'])?$lang['PA_file_failed']:'The file operation could not be confirmed. Reload the file list before retrying.'; }
    } catch (Error $error) {
        if (empty($model->error)) { $model->error[]=isset($lang['PA_file_failed'])?$lang['PA_file_failed']:'The file operation could not be confirmed. Reload the file list before retrying.'; }
    } finally {
        $db=$original_db; $userdata=$original_user; $pafiledb_config=$original_settings; $pafiledb_functions=$original_functions; $_POST=$original_post; $model->modified(false);
        if ($storage) { $storage->release(); } if ($operation!==null && $root!==false && $url!=='') { pafiledb_file_recover($original_db,$table_prefix,$operation,$root,$url); }
    }
    return $result;
}
