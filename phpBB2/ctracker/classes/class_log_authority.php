<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once __DIR__.'/class_acp_storage.php';
class CtrackerLogAuthorityFailure extends RuntimeException {}

// Manual log access freezes authority only; SQL cannot roll back file changes.
// Automatic guest logging/rotation remain independent of this ACP owner.
class CtrackerLogAuthority extends CtrackerAcpStorage
{
    protected function authority_route(){global$phpEx;return'admin_cracker_tracker.'.$phpEx.'?modu=6';}
    protected function storage_fail($key='Owned_storage_failed'){throw new CtrackerLogAuthorityFailure($key==='Owned_storage_upgrade'?$key:'ctracker_log_manager_operation_failed');}
    function check(){try{return parent::check();}catch(CtrackerAcpAuthorityFailure$error){throw new CtrackerLogAuthorityFailure($error->getMessage());}}
    function finish(){try{$this->check();}finally{$this->release();}}
}
function ctracker_log_scope_open($write=false)
{
    global$db;$scope=null;
    try{$scope=new CtrackerLogAuthority($db,ctracker_acp_scope_actor($write));$scope->check();return$scope;}
    catch(CtrackerAcpAuthorityFailure$error){if($scope){$scope->release();}throw new CtrackerLogAuthorityFailure($error->getMessage());}
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
