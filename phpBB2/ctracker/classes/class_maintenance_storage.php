<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once __DIR__.'/class_acp_storage.php';
require_once dirname(dirname(__DIR__)).'/includes/functions_ctracker_legacy_rules.php';
class CtrackerMaintenanceFailure extends RuntimeException {}
function ctracker_maintenance_table($mode)
{
    $tables=array('1'=>CTRACKER_IPBLOCKER,'2'=>CTRACKER_IPBLOCKER,'3'=>CTRACKER_LOGINHISTORY,'4'=>CTRACKER_FILECHK,'5'=>CTRACKER_FILESCANNER);
    if(!is_string($mode)||!isset($tables[$mode])){throw new CtrackerMaintenanceFailure('ctracker_error_database_op');}return$tables[$mode];
}
class CtrackerMaintenanceStorage extends CtrackerAcpStorage
{
    protected$mode,$scan_lock=null,$scan_held=false;
    function __construct($database,$actor,$mode)
    {
        ctracker_maintenance_table($mode);$this->mode=$mode;
        try{
            parent::__construct($database,$actor);
            if($mode==='4'||$mode==='5'){
                $schema=$this->rows('SELECT DATABASE() schema_name');if(count($schema)!==1){$this->storage_fail();}
                $this->scan_lock='ctscan:'.md5($schema[0]['schema_name']."\0".ctracker_maintenance_table($mode));
                $rows=$this->rows("SELECT GET_LOCK('".$this->scan_lock."',0) held");
                if(count($rows)!==1||(string)$rows[0]['held']!=='1'){$this->storage_fail('ctracker_scan_busy');}$this->scan_held=true;
                $this->rows('SELECT 1');
            }
        }catch(Exception$error){$this->release();throw$error;}catch(Error$error){$this->release();throw$error;}
    }
    protected function authority_route(){global$phpEx;return'admin_cracker_tracker.'.$phpEx.'?modu=7';}
    protected function storage_tables(){return array_merge(parent::storage_tables(),array(ctracker_maintenance_table($this->mode)));}
    protected function storage_fail($key='Owned_storage_failed'){throw new CtrackerMaintenanceFailure(in_array($key,array('Owned_storage_upgrade','ctracker_scan_busy'),true)?$key:'ctracker_maintenance_failed');}
    protected function query($sql)
    {
        if($this->scan_held){
            // Use the same connection for both leases and every DELETE/COMMIT.
            // parent::query also verifies its ordinary attachment lease.
            $r=parent::query("SELECT CONNECTION_ID() id,IS_USED_LOCK('".$this->scan_lock."') owner");
            try{$row=$this->connection->sql_fetchrow($r);}finally{$this->connection->sql_freeresult($r);}
            if(!$row||(string)$row['id']!==(string)$row['owner']){$this->storage_fail();}
        }
        return parent::query($sql);
    }
    function run()
    {
        ctracker_acp_scope_actor(true);$this->check();$table=ctracker_maintenance_table($this->mode);
        $where=$this->mode==='2'?ctracker_legacy_blocklist_where():'1=1';
        $this->query($this->mode==='2'?ctracker_legacy_blocklist_cleanup_sql($table):'DELETE FROM '.$table.' WHERE '.$where);
        if($this->rows('SELECT 1 remaining FROM '.$table.' WHERE '.$where.' LIMIT 1')){$this->storage_fail();}
        $this->check();$this->commit();return true;
    }
}
function ctracker_maintenance_run($mode)
{
    global$db;$scope=null;
    try{ctracker_maintenance_table($mode);$scope=new CtrackerMaintenanceStorage($db,ctracker_acp_scope_actor(true),$mode);return$scope->run();}
    finally{if($scope){$scope->release();}}
}
function ctracker_maintenance_error_message($error)
{
    global$lang;$key=($error instanceof CtrackerMaintenanceFailure||$error instanceof CtrackerAcpAuthorityFailure)?$error->getMessage():'ctracker_maintenance_failed';
    return isset($lang[$key])?$lang[$key]:$lang['ctracker_maintenance_failed'];
}
