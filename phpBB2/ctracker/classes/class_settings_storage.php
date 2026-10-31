<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once __DIR__.'/class_acp_storage.php';
class CtrackerSettingsFailure extends RuntimeException {}

function ctracker_admin_settings_values($config,$values)
{
    if(!($config instanceof ct_database)||!is_array($values)){throw new CtrackerSettingsFailure('ctracker_error_updating_config');}
    $fields=$config->setting_ranges();
    foreach(array('global_message_type','footer_layout','password_timestamps_split','last_file_scan','last_checksum_scan')as$key){unset($fields[$key]);}
    foreach($values as$key=>$value){if(!is_string($key)||!isset($fields[$key])||!is_string($value)||!$config->valid_numeric_setting($key,$value)){throw new CtrackerSettingsFailure('ctracker_error_updating_config');}}
    return$values;
}
class CtrackerSettingsStorage extends CtrackerAcpStorage
{
    protected function authority_route(){global$phpEx;return'admin_cracker_tracker.'.$phpEx.'?modu=9';}
    protected function storage_tables(){return array_merge(parent::storage_tables(),array(CTRACKER_CONFIG));}
    protected function storage_fail($key='Owned_storage_failed'){throw new CtrackerSettingsFailure($key==='Owned_storage_upgrade'?$key:'ctracker_settings_operation_failed');}
    protected function pin_tables()
    {
        parent::pin_tables();$name=$this->escape(CTRACKER_CONFIG);
        $rows=$this->rows("SELECT NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND HEX(TABLE_NAME)=HEX('".$name."') AND INDEX_NAME='PRIMARY'");
        if(count($rows)!==1||(string)$rows[0]['NON_UNIQUE']!=='0'||(string)$rows[0]['SEQ_IN_INDEX']!=='1'||$rows[0]['COLUMN_NAME']!=='ct_config_name'||$rows[0]['SUB_PART']!==null){$this->storage_fail('Owned_storage_upgrade');}
    }
    function save($config,$values)
    {
        $values=ctracker_admin_settings_values($config,$values);$this->check();
        foreach($values as$key=>$value){
            $this->query('INSERT INTO '.CTRACKER_CONFIG." (ct_config_name,ct_config_value) VALUES ('".$this->escape($key)."','".$this->escape($value)."') ON DUPLICATE KEY UPDATE ct_config_name=VALUES(ct_config_name),ct_config_value=VALUES(ct_config_value)");
        }
        if($values){
            $names=array();foreach($values as$key=>$value){$names[]="'".$this->escape($key)."'";}
            $saved=$this->rows('SELECT ct_config_name,ct_config_value FROM '.CTRACKER_CONFIG.' WHERE ct_config_name IN('.implode(',',$names).') FOR UPDATE');
            if(count($saved)!==count($values)){$this->storage_fail();}
            foreach($saved as$row){if(!array_key_exists($row['ct_config_name'],$values)||$row['ct_config_value']!==$values[$row['ct_config_name']]){$this->storage_fail();}}
        }
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
