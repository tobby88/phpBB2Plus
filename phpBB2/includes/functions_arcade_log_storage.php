<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__).'/functions_arcade_import_storage.php';

function phpbb_arcade_log_input($request)
{
    if (!is_array($request)) { throw new PhpbbOwnedStorageFailure('arcade_log_invalid'); }
    foreach ($request as $key=>$value)
    {
        if (!in_array($key,array('sid','sort','purge','delete','record_no','x','y'),true)
            || ($key!=='record_no'&&!is_string($value))) { throw new PhpbbOwnedStorageFailure('arcade_log_invalid'); }
    }
    $sort=isset($request['sort'])?$request['sort']:'0';
    if (!preg_match('/^[0-5]$/D',$sort)) { throw new PhpbbOwnedStorageFailure('arcade_log_invalid'); }
    $purge=isset($request['purge']);$delete=isset($request['delete']);
    if (isset($request['x'])||isset($request['y']))
    {
        if (!isset($request['x'],$request['y'])||!preg_match('/^[0-9]{1,10}$/D',$request['x'])||!preg_match('/^[0-9]{1,10}$/D',$request['y'])) { throw new PhpbbOwnedStorageFailure('arcade_log_invalid'); }
        $delete=true;
    }
    if ($purge===$delete || ($purge&&isset($request['record_no']))) { throw new PhpbbOwnedStorageFailure('arcade_log_invalid'); }
    $ids=array();
    if ($delete)
    {
        if (!isset($request['record_no'])||!is_array($request['record_no'])||!count($request['record_no'])||count($request['record_no'])>1000) { throw new PhpbbOwnedStorageFailure('arcade_log_invalid'); }
        foreach ($request['record_no'] as $id)
        {
            if (!is_string($id)||!preg_match('/^[1-9][0-9]{0,7}$/D',$id)||(float)$id>16777215) { throw new PhpbbOwnedStorageFailure('arcade_log_invalid'); }
            $ids[(int)$id]=(int)$id;
        }
        sort($ids,SORT_NUMERIC);
    }
    return array('sort'=>(int)$sort,'ids'=>$purge?null:$ids);
}

class PhpbbArcadeLogWriter extends PhpbbArcadeImportWriter
{
    protected function storage_tables() { return array(USERS_TABLE,SESSIONS_TABLE,JR_ADMIN_TABLE,iNA_LOG); }
    protected function actor()
    {
        global $phpEx;
        return phpbb_acp_actor($this,'admin_arcade_log.'.$phpEx);
    }
    function remove($input)
    {
        global $userdata;
        $actor=$this->actor();$sid=$this->escape($userdata['session_id']);
        foreach (array('SELECT session_id FROM '.SESSIONS_TABLE." WHERE session_id='$sid' AND HEX(session_id)=HEX('$sid')",
            'SELECT user_id FROM '.USERS_TABLE.' WHERE user_id='.(int)$actor['user_id'],
            'SELECT user_id FROM '.JR_ADMIN_TABLE.' WHERE user_id='.(int)$actor['user_id']) as $sql) { $this->rows($sql.' LOCK IN SHARE MODE'); }
        $this->actor();
        $names=array('','BAD_GAME','ERROR','GAME','GAME_ERROR','POST');
        $where=$input['sort']?"name='".$names[$input['sort']]."'":'1=1';
        if ($input['ids']!==null)
        {
            $where.=' AND record_no IN ('.implode(',',$input['ids']).')';
            $rows=$this->rows('SELECT record_no FROM '.iNA_LOG.' WHERE '.$where.' ORDER BY record_no FOR UPDATE');
            if (count($rows)!==count($input['ids'])) { $this->storage_fail('arcade_log_changed'); }
        }
        // DELETE remains transactional and preserves log identities. TRUNCATE
        // would implicitly commit unrelated work and cannot roll back.
        $this->query('DELETE FROM '.iNA_LOG.' WHERE '.$where);
        $removed=(int)$this->connection->sql_affectedrows();
        if ($input['ids']!==null&&$removed!==count($input['ids'])) { $this->storage_fail('arcade_log_changed'); }
        $this->commit_attempted=true;$this->commit();return $removed;
    }
}

function phpbb_arcade_log_remove($database,$request)
{
    $input=phpbb_arcade_log_input($request);phpbb_attach_quota_post($request);
    if (!phpbb_request_source_is_same_origin()) { throw new PhpbbOwnedStorageFailure('Session_invalid'); }
    $writer=new PhpbbArcadeLogWriter($database);
    try { return $writer->remove($input); }
    finally { $writer->release(); }
}
