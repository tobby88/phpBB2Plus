<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once __DIR__.'/class_configuration_storage.php';
class CtrackerSettingsFailure extends RuntimeException {}

function ctracker_admin_settings_values($config,$values)
{
    if(!($config instanceof ct_database)||!is_array($values)){throw new CtrackerSettingsFailure('ctracker_error_updating_config');}
    $fields=$config->setting_ranges();
    foreach(array('global_message_type','footer_layout','password_timestamps_split','last_file_scan','last_checksum_scan')as$key){unset($fields[$key]);}
    foreach($values as$key=>$value){if(!is_string($key)||!isset($fields[$key])||!is_string($value)||!$config->valid_numeric_setting($key,$value)){throw new CtrackerSettingsFailure('ctracker_error_updating_config');}}
    return$values;
}
class CtrackerSettingsStorage extends CtrackerConfigurationStorage
{
    protected function authority_route(){global$phpEx;return'admin_cracker_tracker.'.$phpEx.'?modu=9';}
    protected function storage_fail($key='Owned_storage_failed'){throw new CtrackerSettingsFailure($key==='Owned_storage_upgrade'?$key:'ctracker_settings_operation_failed');}
    function save($config,$values)
    {
        ctracker_acp_scope_actor(true);
        $values=ctracker_admin_settings_values($config,$values);$this->check();
        $this->write_configuration($values);
        $this->check();$this->commit();return$values;
    }
}
function ctracker_admin_settings_save($config,$values)
{
    global$db;$scope=null;
    try{
        $values=ctracker_admin_settings_values($config,$values);
        $scope=new CtrackerSettingsStorage($db,ctracker_acp_scope_actor(true));
        return$scope->save($config,$values);
    }finally{if($scope){$scope->release();}}
}
function ctracker_settings_error_message($error)
{
    global$lang;$key=($error instanceof CtrackerSettingsFailure||$error instanceof CtrackerAcpAuthorityFailure)?$error->getMessage():'ctracker_settings_operation_failed';
    return isset($lang[$key])?$lang[$key]:$lang['ctracker_settings_operation_failed'];
}
