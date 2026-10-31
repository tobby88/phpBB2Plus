<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(dirname(__DIR__)) . '/includes/functions_owned_storage.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions_acl_storage.php';

class CtrackerLogAuthorityFailure extends RuntimeException {}

// This transaction freezes current manual ACP authority only. It never writes
// SQL and cannot roll back filesystem changes. Automatic guest logging and
// rotation keep using log_manager independently of this privileged scope.
class CtrackerLogAuthority extends PhpbbOwnedStore
{
    protected function storage_tables(){return array(USERS_TABLE,SESSIONS_TABLE,JR_ADMIN_TABLE,CONFIG_TABLE);}
    protected function storage_fail($key='Owned_storage_failed'){throw new CtrackerLogAuthorityFailure($key==='Owned_storage_upgrade'?$key:'ctracker_log_manager_operation_failed');}
    function sql_query($sql,$transaction=false){if($transaction!==false||!is_string($sql)||!preg_match('/^SELECT\b/i',$sql)){$this->storage_fail();}return$this->query($sql);}
    function sql_escape($value){return$this->escape($value);}
    function sql_fetchrow($result){return$this->connection->sql_fetchrow($result);}
    function sql_fetchrowset($result){return$this->connection->sql_fetchrowset($result);}
    function sql_freeresult($result){return$this->connection->sql_freeresult($result);}
    function rows($sql){if(!is_string($sql)||!preg_match('/^SELECT\b/i',$sql)){$this->storage_fail();}return parent::rows($sql);}
    function check()
    {
        global$userdata,$phpEx;
        if(!isset($userdata['user_id'],$userdata['session_id'])||(string)$userdata['user_id']!==(string)$this->actor['id']||!is_string($userdata['session_id'])||!hash_equals($this->actor['sid'],$userdata['session_id'])||!isset($userdata['session_logged_in'],$userdata['session_admin'])||!in_array($userdata['session_logged_in'],array(true,1,'1'),true)||!in_array($userdata['session_admin'],array(true,1,'1'),true)){throw new CtrackerLogAuthorityFailure('Not_Authorised');}
        $sid=$this->escape($this->actor['sid']);$sessions=$this->rows('SELECT session_id,session_user_id,session_logged_in,session_admin,session_time FROM '.SESSIONS_TABLE." WHERE session_id='".$sid."' AND HEX(session_id)=HEX('".$sid."') LOCK IN SHARE MODE");
        if(count($sessions)!==1||(int)$sessions[0]['session_user_id']!==$this->actor['id']||(string)$sessions[0]['session_logged_in']!=='1'||(string)$sessions[0]['session_admin']!=='1'){throw new CtrackerLogAuthorityFailure('Session_invalid');}
        $users=$this->rows('SELECT user_id,user_level,user_active FROM '.USERS_TABLE.' WHERE user_id='.$this->actor['id'].' LOCK IN SHARE MODE');
        if(count($users)!==1||(string)$users[0]['user_active']!=='1'||!in_array((int)$users[0]['user_level'],array(USER,MOD,ADMIN),true)){throw new CtrackerLogAuthorityFailure('Not_Authorised');}
        $this->rows('SELECT user_id FROM '.JR_ADMIN_TABLE.' WHERE user_id='.$this->actor['id'].' LOCK IN SHARE MODE');
        $ttl=$this->rows("SELECT config_name,config_value FROM ".CONFIG_TABLE." WHERE config_name='session_length' LOCK IN SHARE MODE");
        if(count($ttl)!==1||$ttl[0]['config_name']!=='session_length'||!preg_match('/^[1-9][0-9]*$/D',(string)$ttl[0]['config_value'])||(float)$ttl[0]['config_value']>2147483647){$this->storage_fail();}
        if(!preg_match('/^[0-9]+$/D',(string)$sessions[0]['session_time'])||(float)$sessions[0]['session_time']<time()-(int)$ttl[0]['config_value']){throw new CtrackerLogAuthorityFailure('Session_invalid');}
        // The exact existing registered module owns both its normal log pages
        // and its debug-download helper, never other CrackerTracker modules.
        try{return phpbb_acp_actor($this,'admin_cracker_tracker.'.$phpEx.'?modu=6');}
        catch(PhpbbAclException$error){throw new CtrackerLogAuthorityFailure('Not_Authorised');}
    }
    function finish(){try{$this->check();}finally{$this->release();}}
}

function ctracker_log_scope_open($write=false)
{
    global$db,$userdata;
    if(!is_bool($write)||!defined('IN_ADMIN')||!IN_ADMIN||!isset($userdata['user_id'],$userdata['session_id'],$userdata['session_logged_in'],$userdata['session_admin'])||!(is_int($userdata['user_id'])||is_string($userdata['user_id']))||!preg_match('/^[1-9][0-9]{0,6}$/D',(string)$userdata['user_id'])||(int)$userdata['user_id']>8388607||!is_string($userdata['session_id'])||$userdata['session_id']===''||strlen($userdata['session_id'])>32||!in_array($userdata['session_logged_in'],array(true,1,'1'),true)||!in_array($userdata['session_admin'],array(true,1,'1'),true)){throw new CtrackerLogAuthorityFailure('Not_Authorised');}
    if($write&&(!isset($_SERVER['REQUEST_METHOD'])||$_SERVER['REQUEST_METHOD']!=='POST'||!isset($_POST['sid'])||!is_string($_POST['sid'])||!hash_equals($userdata['session_id'],$_POST['sid']))){throw new CtrackerLogAuthorityFailure('Session_invalid');}
    $scope=null;
    try{$scope=new CtrackerLogAuthority($db,array('id'=>(int)$userdata['user_id'],'sid'=>$userdata['session_id']));$scope->check();return$scope;}
    catch(Exception$error){if($scope){$scope->release();}throw$error;}catch(Error$error){if($scope){$scope->release();}throw$error;}
}
function ctracker_log_error_message($error)
{
    global$lang;$key=$error instanceof CtrackerLogAuthorityFailure?$error->getMessage():'ctracker_log_manager_operation_failed';return isset($lang[$key])?$lang[$key]:$lang['ctracker_log_manager_operation_failed'];
}

// The registered log-manager module also owns its non-menu debug download.
// Authorize before sending headers; retain authority and the file's shared lock
// until streaming completes. A late stream failure cannot undo sent bytes.
function ctracker_log_debug_download()
{
    global$phpbb_root_path;
    $scope=null;$file=null;$started=false;
    try{
        $scope=ctracker_log_scope_open(false);
        $path=$phpbb_root_path.'ctracker/logfiles/logfile_debug_mode.txt';
        clearstatcache(true,$path);
        $before=@lstat($path);
        if(!$before||($before['mode']&0170000)!==0100000||!($file=@fopen($path,'rb'))||!flock($file,LOCK_SH)){throw new CtrackerLogAuthorityFailure('ctracker_error_logfileop');}
        clearstatcache(true,$path);$after=@lstat($path);$stat=fstat($file);
        if(!$after||!$stat||($after['mode']&0170000)!==0100000||($stat['mode']&0170000)!==0100000){throw new CtrackerLogAuthorityFailure('ctracker_error_logfileop');}
        // Legacy Windows PHP exposes no file inode and reports different dev
        // values for lstat/fstat. Compare its available locked-file metadata.
        if($stat['ino']&&$after['ino']){$same=$before['dev']===$stat['dev']&&$before['ino']===$stat['ino']&&$after['dev']===$stat['dev']&&$after['ino']===$stat['ino'];}
        else{$same=$after['size']===$stat['size']&&$after['mtime']===$stat['mtime'];}
        if(!$same){throw new CtrackerLogAuthorityFailure('ctracker_error_logfileop');}
        $scope->check();
        header('Content-Type: text/plain; charset=UTF-8');
        header('Content-Disposition: attachment; filename="logfile_debug_mode.txt"');
        header('Content-Length: '.$stat['size']);
        header('Cache-Control: no-store, private, max-age=0');
        header('X-Content-Type-Options: nosniff');
        header('Pragma: no-cache');header('Expires: 0');$started=true;
        if(fpassthru($file)!==$stat['size']){throw new CtrackerLogAuthorityFailure('ctracker_error_logfileop');}
        $scope->finish();return true;
    }catch(Exception$error){if(!$started){throw$error;}error_log('CrackerTracker debug download interrupted.');return false;}
    catch(Error$error){if(!$started){throw$error;}error_log('CrackerTracker debug download interrupted.');return false;}
    finally{if(is_resource($file)){flock($file,LOCK_UN);fclose($file);}if($scope){$scope->release();}}
}
