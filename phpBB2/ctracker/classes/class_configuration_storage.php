<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once __DIR__.'/class_acp_storage.php';
abstract class CtrackerConfigurationStorage extends CtrackerAcpStorage
{
    protected function storage_tables(){return array_merge(parent::storage_tables(),array(CTRACKER_CONFIG));}
    protected function require_primary_key($table,$column)
    {
        $name=$this->escape($table);
        $rows=$this->rows("SELECT NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND HEX(TABLE_NAME)=HEX('".$name."') AND INDEX_NAME='PRIMARY'");
        if(count($rows)!==1||(string)$rows[0]['NON_UNIQUE']!=='0'||(string)$rows[0]['SEQ_IN_INDEX']!=='1'||$rows[0]['COLUMN_NAME']!==$column||$rows[0]['SUB_PART']!==null){$this->storage_fail('Owned_storage_upgrade');}
    }
    protected function pin_tables(){parent::pin_tables();$this->require_primary_key(CTRACKER_CONFIG,'ct_config_name');}
    protected function write_configuration($values)
    {
        foreach($values as$key=>$value){
            $this->query('INSERT INTO '.CTRACKER_CONFIG." (ct_config_name,ct_config_value) VALUES ('".$this->escape($key)."','".$this->escape($value)."') ON DUPLICATE KEY UPDATE ct_config_name=VALUES(ct_config_name),ct_config_value=VALUES(ct_config_value)");
        }
        if($values){
            $names=array();foreach($values as$key=>$value){$names[]="'".$this->escape($key)."'";}
            $saved=$this->rows('SELECT ct_config_name,ct_config_value FROM '.CTRACKER_CONFIG.' WHERE ct_config_name IN('.implode(',',$names).') FOR UPDATE');
            if(count($saved)!==count($values)){$this->storage_fail();}
            foreach($saved as$row){if(!array_key_exists($row['ct_config_name'],$values)||$row['ct_config_value']!==$values[$row['ct_config_name']]){$this->storage_fail();}}
        }
    }
}
