<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(dirname(__DIR__)) . '/includes/functions_owned_storage.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions_acl_storage.php';

class CtrackerAcpAuthorityFailure extends RuntimeException {}

// Internal ACP owners share the same current identity and row-lock policy.
// The public database adapters are read-only. A writer must expose its own
// fixed, validated operation through the protected owning query/commit API.
abstract class CtrackerAcpStorage extends PhpbbOwnedStore
{
    abstract protected function authority_route();
    protected function storage_tables(){return array(USERS_TABLE,SESSIONS_TABLE,JR_ADMIN_TABLE,CONFIG_TABLE);}
    function sql_query($sql,$transaction=false){if($transaction!==false||!is_string($sql)||!preg_match('/^SELECT\b/i',$sql)){$this->storage_fail();}return$this->query($sql);}
    function sql_escape($value){return$this->escape($value);}
    function sql_fetchrow($result){return$this->connection->sql_fetchrow($result);}
    function sql_fetchrowset($result){return$this->connection->sql_fetchrowset($result);}
    function sql_freeresult($result){return$this->connection->sql_freeresult($result);}
    function rows($sql){if(!is_string($sql)||!preg_match('/^SELECT\b/i',$sql)){$this->storage_fail();}return parent::rows($sql);}
    function check()
    {
        global$userdata;
        if(!isset($userdata['user_id'],$userdata['session_id'])||(string)$userdata['user_id']!==(string)$this->actor['id']||!is_string($userdata['session_id'])||!hash_equals($this->actor['sid'],$userdata['session_id'])||!isset($userdata['session_logged_in'],$userdata['session_admin'])||!in_array($userdata['session_logged_in'],array(true,1,'1'),true)||!in_array($userdata['session_admin'],array(true,1,'1'),true)){throw new CtrackerAcpAuthorityFailure('Not_Authorised');}
        $sid=$this->escape($this->actor['sid']);$sessions=$this->rows('SELECT session_id,session_user_id,session_logged_in,session_admin,session_time FROM '.SESSIONS_TABLE." WHERE session_id='".$sid."' AND HEX(session_id)=HEX('".$sid."') LOCK IN SHARE MODE");
        if(count($sessions)!==1||(int)$sessions[0]['session_user_id']!==$this->actor['id']||(string)$sessions[0]['session_logged_in']!=='1'||(string)$sessions[0]['session_admin']!=='1'){throw new CtrackerAcpAuthorityFailure('Session_invalid');}
        $users=$this->rows('SELECT user_id,user_level,user_active FROM '.USERS_TABLE.' WHERE user_id='.$this->actor['id'].' LOCK IN SHARE MODE');
        if(count($users)!==1||(string)$users[0]['user_active']!=='1'||!in_array((int)$users[0]['user_level'],array(USER,MOD,ADMIN),true)){throw new CtrackerAcpAuthorityFailure('Not_Authorised');}
        $this->rows('SELECT user_id FROM '.JR_ADMIN_TABLE.' WHERE user_id='.$this->actor['id'].' LOCK IN SHARE MODE');
        $ttl=$this->rows("SELECT config_name,config_value FROM ".CONFIG_TABLE." WHERE config_name='session_length' LOCK IN SHARE MODE");
        if(count($ttl)!==1||$ttl[0]['config_name']!=='session_length'||!preg_match('/^[1-9][0-9]*$/D',(string)$ttl[0]['config_value'])||(float)$ttl[0]['config_value']>2147483647){$this->storage_fail();}
        if(!preg_match('/^[0-9]+$/D',(string)$sessions[0]['session_time'])||(float)$sessions[0]['session_time']<time()-(int)$ttl[0]['config_value']){throw new CtrackerAcpAuthorityFailure('Session_invalid');}
        try{return phpbb_acp_actor($this,$this->authority_route());}
        catch(PhpbbAclException$error){throw new CtrackerAcpAuthorityFailure('Not_Authorised');}
    }
}

function ctracker_acp_scope_actor($write)
{
    global$userdata;
    if(!is_bool($write)||!defined('IN_ADMIN')||!IN_ADMIN||!isset($userdata['user_id'],$userdata['session_id'],$userdata['session_logged_in'],$userdata['session_admin'])||!(is_int($userdata['user_id'])||is_string($userdata['user_id']))||!preg_match('/^[1-9][0-9]{0,6}$/D',(string)$userdata['user_id'])||(int)$userdata['user_id']>8388607||!is_string($userdata['session_id'])||$userdata['session_id']===''||strlen($userdata['session_id'])>32||!in_array($userdata['session_logged_in'],array(true,1,'1'),true)||!in_array($userdata['session_admin'],array(true,1,'1'),true)){throw new CtrackerAcpAuthorityFailure('Not_Authorised');}
    if($write&&(!isset($_SERVER['REQUEST_METHOD'])||$_SERVER['REQUEST_METHOD']!=='POST'||!isset($_POST['sid'])||!is_string($_POST['sid'])||!hash_equals($userdata['session_id'],$_POST['sid']))){throw new CtrackerAcpAuthorityFailure('Session_invalid');}
    return array('id'=>(int)$userdata['user_id'],'sid'=>$userdata['session_id']);
}
