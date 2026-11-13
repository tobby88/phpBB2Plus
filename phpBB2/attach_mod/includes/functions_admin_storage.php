<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(dirname(__DIR__)) . '/includes/functions_owned_storage.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions_acl_storage.php';

function phpbb_attach_admin_request_actor($request)
{
    global $userdata;
    if(!is_array($request)||!isset($_SERVER['REQUEST_METHOD'])||$_SERVER['REQUEST_METHOD']!=='POST'||!is_array($userdata)
        ||!isset($userdata['user_id'],$userdata['session_id'],$userdata['session_logged_in'],$userdata['session_admin'],$request['sid'])
        ||!(is_int($userdata['user_id'])||is_string($userdata['user_id']))||preg_match('/^[1-9][0-9]{0,6}$/D',(string)$userdata['user_id'])!==1||(float)$userdata['user_id']>8388607
        ||!in_array($userdata['session_logged_in'],array(true,1,'1'),true)||!in_array($userdata['session_admin'],array(true,1,'1'),true)
        ||!is_string($userdata['session_id'])||$userdata['session_id']===''||strlen($userdata['session_id'])>32||strpos($userdata['session_id'],"\0")!==false
        ||!is_string($request['sid'])||!hash_equals($userdata['session_id'],$request['sid'])||!phpbb_request_source_is_same_origin()){
        throw new PhpbbOwnedStorageFailure('Session_invalid');
    }
    return array('id'=>(int)$userdata['user_id'],'sid'=>$userdata['session_id']);
}

abstract class PhpbbAttachAdminStore extends PhpbbOwnedStore
{
    protected function storage_route() { global $phpEx; return 'admin_attach_cp.'.$phpEx; }
    // The exact ACP/JR evaluator may only issue SELECTs through this facade.
    function sql_query($sql,$transaction=false) { if(!is_string($sql)||strpos($sql,'SELECT ')!==0||$transaction){$this->storage_fail();}return $this->query($sql); }
    function sql_fetchrow($r) { return $this->connection->sql_fetchrow($r); }
    function sql_numrows($r) { return $this->connection->sql_numrows($r); }
    function sql_fetchrowset($r) { return $this->connection->sql_fetchrowset($r); }
    function sql_freeresult($r) { return $this->connection->sql_freeresult($r); }
    function sql_escape($value) { return $this->escape($value); }
    protected function current_actor()
    {
        global $userdata,$phpEx;
        if (!is_array($userdata)||!isset($userdata['user_id'],$userdata['session_id'])||(string)$userdata['user_id']!==(string)$this->actor['id']||$userdata['session_id']!==$this->actor['sid']) { $this->storage_fail('Session_invalid'); }
        $ttl=$this->rows('SELECT config_value FROM '.CONFIG_TABLE." WHERE config_name='session_length' AND HEX(config_name)=HEX('session_length') LOCK IN SHARE MODE");
        if(count($ttl)!==1||preg_match('/^[1-9][0-9]{0,9}$/D',(string)$ttl[0]['config_value'])!==1||(float)$ttl[0]['config_value']>2147483647){$this->storage_fail();}
        $sid=$this->escape($this->actor['sid']);$id=$this->actor['id'];
        $sessions=$this->rows('SELECT session_time FROM '.SESSIONS_TABLE." WHERE session_id='$sid' AND HEX(session_id)=HEX('$sid') AND session_user_id=$id AND session_logged_in=1 AND session_admin=1 LOCK IN SHARE MODE");
        if(count($sessions)!==1||preg_match('/^(?:0|[1-9][0-9]{0,9})$/D',(string)$sessions[0]['session_time'])!==1||(float)$sessions[0]['session_time']<time()-(int)$ttl[0]['config_value']){$this->storage_fail('Session_invalid');}
        $users=$this->rows('SELECT user_id,user_active,user_level,user_blocktime FROM '.USERS_TABLE.' WHERE user_id='.$id.' LOCK IN SHARE MODE');
        if(count($users)!==1||(string)$users[0]['user_active']!=='1'||!in_array((string)$users[0]['user_level'],array((string)USER,(string)MOD,(string)ADMIN),true)||preg_match('/^(?:0|[1-9][0-9]{0,9})$/D',(string)$users[0]['user_blocktime'])!==1||(float)$users[0]['user_blocktime']>=time()){$this->storage_fail('Not_Authorised');}
        $this->rows('SELECT user_id FROM '.JR_ADMIN_TABLE.' WHERE user_id='.$id.' LOCK IN SHARE MODE');
        try { return phpbb_acp_actor($this,$this->storage_route()); }
        catch(PhpbbAclException $error){$this->storage_fail('Not_Authorised');}
    }
}
