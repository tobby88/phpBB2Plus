<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once __DIR__.'/class_configuration_storage.php';
class CtrackerAdminActionFailure extends RuntimeException {}
function ctracker_admin_action_module($kind)
{
    $modules=array('notice_publish'=>4,'notice_retract'=>4,'block_add'=>5,'block_delete'=>5,'layout'=>11);
    if(!is_string($kind)||!isset($modules[$kind])){throw new CtrackerAdminActionFailure('ctracker_error_database_op');}return$modules[$kind];
}
function ctracker_admin_action_values($config,$kind,$values)
{
    ctracker_admin_action_module($kind);
    $fields=array('notice_publish'=>array('global_message','global_message_type'),'notice_retract'=>array(),'block_add'=>array('entry'),'block_delete'=>array('id'),'layout'=>array('footer_layout'));
    if(!($config instanceof ct_database)||!is_array($values)){throw new CtrackerAdminActionFailure('ctracker_error_database_op');}
    $keys=array_keys($values);$expected=$fields[$kind];sort($keys);sort($expected);if($keys!==$expected){throw new CtrackerAdminActionFailure('ctracker_error_database_op');}
    if($kind==='notice_publish'){
        if(!in_array($values['global_message_type'],array('0','1'),true)){throw new CtrackerInputFailure('ctracker_glob_msg_invalid_type');}
        $values['global_message']=$config->normalize_global_message($values['global_message'],true);
        if($values['global_message_type']==='0'&&phpbb_profile_http_url($values['global_message'])===''){throw new CtrackerInputFailure('ctracker_glob_msg_invalid_url');}
    }elseif($kind==='block_add'){$values['entry']=$config->normalize_blocklist_value($values['entry'],true);}
    elseif($kind==='block_delete'){$values['id']=$config->normalize_blocklist_id($values['id'],true);}
    elseif($kind==='layout'){if(!is_string($values['footer_layout'])||!$config->valid_numeric_setting('footer_layout',$values['footer_layout'])){throw new CtrackerInputFailure('ctracker_error_updating_config');}}
    return$values;
}
class CtrackerAdminActions extends CtrackerConfigurationStorage
{
    protected$kind;
    function __construct($database,$actor,$kind){ctracker_admin_action_module($kind);$this->kind=$kind;parent::__construct($database,$actor);}
    protected function authority_route(){global$phpEx;return'admin_cracker_tracker.'.$phpEx.'?modu='.ctracker_admin_action_module($this->kind);}
    protected function storage_tables(){return strpos($this->kind,'block_')===0?array_merge(parent::storage_tables(),array(CTRACKER_IPBLOCKER)):parent::storage_tables();}
    protected function storage_fail($key='Owned_storage_failed'){throw new CtrackerAdminActionFailure($key==='Owned_storage_upgrade'?$key:'ctracker_admin_action_failed');}
    protected function pin_tables()
    {
        parent::pin_tables();if(strpos($this->kind,'block_')!==0){return;}$this->require_primary_key(CTRACKER_IPBLOCKER,'id');$name=$this->escape(CTRACKER_IPBLOCKER);
        $rows=$this->rows("SELECT EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND HEX(TABLE_NAME)=HEX('".$name."') AND HEX(COLUMN_NAME)=HEX('id')");
        if(count($rows)!==1||stripos($rows[0]['EXTRA'],'auto_increment')===false){$this->storage_fail('Owned_storage_upgrade');}
    }
    function apply($config,$values)
    {
        ctracker_acp_scope_actor(true);
        $values=ctracker_admin_action_values($config,$this->kind,$values);$this->check();$saved=array();
        if($this->kind==='notice_publish'||$this->kind==='notice_retract'){
            if($this->kind==='notice_publish'){$saved=$values;$this->write_configuration($saved);}
            $flag=$this->kind==='notice_publish'?1:0;
            $this->query('UPDATE '.USERS_TABLE.' SET ct_global_msg_read = '.$flag);
            if($this->rows('SELECT user_id FROM '.USERS_TABLE.' WHERE ct_global_msg_read IS NULL OR ct_global_msg_read <> '.$flag.' LIMIT 1')){$this->storage_fail();}
        }elseif($this->kind==='layout'){$saved=$values;$this->write_configuration($saved);}
        elseif($this->kind==='block_add'){
            $this->query('INSERT INTO '.CTRACKER_IPBLOCKER." (ct_blocker_value)VALUES('".$this->escape($values['entry'])."')");
            $id=(int)$this->connection->sql_nextid();if($id<1||$id>16777215){$this->storage_fail();}
            $rows=$this->rows('SELECT id,ct_blocker_value FROM '.CTRACKER_IPBLOCKER.' WHERE id='.$id.' FOR UPDATE');
            if(count($rows)!==1||$rows[0]['ct_blocker_value']!==$values['entry']){$this->storage_fail();}
        }else{
            $id=$values['id'];$rows=$this->rows('SELECT id FROM '.CTRACKER_IPBLOCKER.' WHERE id='.$id.' FOR UPDATE');
            if(count($rows)>1){$this->storage_fail();}
            // Deleting a rule that was already removed remains an idempotent no-op.
            if($rows){$this->query('DELETE FROM '.CTRACKER_IPBLOCKER.' WHERE id='.$id);}
            if($this->rows('SELECT id FROM '.CTRACKER_IPBLOCKER.' WHERE id='.$id)){$this->storage_fail();}
        }
        $this->check();$this->commit();return$saved;
    }
}
function ctracker_admin_action_save($config,$kind,$values)
{
    global$db;$scope=null;
    try{$values=ctracker_admin_action_values($config,$kind,$values);$scope=new CtrackerAdminActions($db,ctracker_acp_scope_actor(true),$kind);return$scope->apply($config,$values);}
    finally{if($scope){$scope->release();}}
}
function ctracker_admin_action_error_message($error)
{
    global$lang;$key=($error instanceof CtrackerAdminActionFailure||$error instanceof CtrackerInputFailure||$error instanceof CtrackerAcpAuthorityFailure)?$error->getMessage():'ctracker_admin_action_failed';
    return isset($lang[$key])?$lang[$key]:$lang['ctracker_admin_action_failed'];
}
