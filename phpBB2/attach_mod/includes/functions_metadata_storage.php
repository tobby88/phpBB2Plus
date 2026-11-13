<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(dirname(__DIR__)) . '/includes/functions_owned_storage.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions_acl_storage.php';

function phpbb_attach_admin_comment_text($value)
{
    return trim(str_replace(array("\r\n", "\r", '\\xFF'), array("\n", "\n", ' '), $value));
}
function phpbb_attach_admin_comment_digest($stored)
{
    return hash('sha256', phpbb_attach_admin_comment_text(html_entity_decode((string)$stored, ENT_QUOTES, 'UTF-8')));
}
function phpbb_attach_admin_metadata_input($request)
{
    $fields = array('attach_id_list','attach_comment_list','attach_count_list','attach_original_comment_list','attach_original_count_list');
    if (!is_array($request)) { throw new PhpbbOwnedStorageFailure('Attachment_metadata_invalid'); }
    $keys = null;
    foreach ($fields as $field) {
        if (!isset($request[$field]) || !is_array($request[$field]) || !$request[$field] || count($request[$field]) > 1000) { throw new PhpbbOwnedStorageFailure('Attachment_metadata_invalid'); }
        $actual = array_keys($request[$field]);
        if ($keys === null) { $keys = range(0, count($actual)-1); }
        if ($actual !== $keys) { throw new PhpbbOwnedStorageFailure('Attachment_metadata_invalid'); }
    }
    $entries = array();
    foreach ($keys as $i) {
        $id = $request['attach_id_list'][$i];
        if (!(is_int($id)||is_string($id)) || preg_match('/^[1-9][0-9]{0,7}$/D',(string)$id)!==1 || (float)$id>16777215 || isset($entries[(int)$id])) { throw new PhpbbOwnedStorageFailure('Attachment_metadata_invalid'); }
        $numbers = array();
        foreach (array('attach_count_list','attach_original_count_list') as $field) {
            $value = $request[$field][$i];
            if (!(is_int($value)||is_string($value)) || strlen((string)$value)>32) { throw new PhpbbOwnedStorageFailure('Attachment_metadata_invalid'); }
            $value = trim((string)$value);
            if (preg_match('/^[0-9]{1,16}$/D',$value)!==1 || (float)$value>16777215) { throw new PhpbbOwnedStorageFailure('Attachment_metadata_invalid'); }
            $numbers[] = (string)(int)$value;
        }
        $raw = $request['attach_comment_list'][$i]; $digest = $request['attach_original_comment_list'][$i];
        if (!is_string($raw) || strlen($raw)>2040 || !is_string($digest) || preg_match('/^[a-f0-9]{64}$/D',$digest)!==1) { throw new PhpbbOwnedStorageFailure('Attachment_metadata_invalid'); }
        $text = phpbb_attach_admin_comment_text(phpbb_request_raw_value($raw));
        if (strlen($text)>1020 || strpos($text,"\0")!==false || preg_match('//u',$text)!==1 || preg_match_all('/./us',$text,$characters)>255) { throw new PhpbbOwnedStorageFailure('Attachment_metadata_invalid'); }
        $entries[(int)$id] = array('text'=>$text,'count'=>$numbers[0],'original_count'=>$numbers[1],'original_comment'=>$digest);
    }
    ksort($entries,SORT_NUMERIC); return $entries;
}

class PhpbbAttachAdminMetadataStore extends PhpbbOwnedStore
{
    var $commit_attempted = false;
    protected function storage_tables() { return array(CONFIG_TABLE,SESSIONS_TABLE,USERS_TABLE,JR_ADMIN_TABLE,ATTACHMENTS_DESC_TABLE); }
    protected function storage_fail($key='Attachment_metadata_failed')
    {
        if ($key==='Owned_storage_failed') { $key='Attachment_metadata_failed'; }
        if ($key==='Owned_storage_upgrade') { $key='Attachment_metadata_upgrade'; }
        throw new PhpbbOwnedStorageFailure($key);
    }
    // The exact ACP/JR evaluator may only issue SELECTs through this facade.
    function sql_query($sql,$transaction=false) { if(!is_string($sql)||strpos($sql,'SELECT ')!==0||$transaction){$this->storage_fail();}return $this->query($sql); }
    function sql_fetchrow($r) { return $this->connection->sql_fetchrow($r); }
    function sql_fetchrowset($r) { return $this->connection->sql_fetchrowset($r); }
    function sql_freeresult($r) { return $this->connection->sql_freeresult($r); }
    function sql_escape($value) { return $this->escape($value); }
    private function current_actor()
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
        try { return phpbb_acp_actor($this,'admin_attach_cp.'.$phpEx); }
        catch(PhpbbAclException $error){$this->storage_fail('Not_Authorised');}
    }
    private function named($row) { $out=array();foreach($row as$key=>$value){if(is_string($key)){$out[$key]=$value;}}return$out; }
    function save($entries)
    {
        $actor=$this->current_actor();$ids=array_keys($entries);
        $rows=$this->rows('SELECT * FROM '.ATTACHMENTS_DESC_TABLE.' WHERE attach_id IN ('.implode(',',$ids).') ORDER BY attach_id FOR UPDATE');
        if(count($rows)!==count($ids)){$this->storage_fail('Attachment_metadata_conflict');}
        $plan=array();
        foreach($rows as$row){
            $before=$this->named($row);$id=(int)$row['attach_id'];$input=$entries[$id];$after=$before;
            $desired_digest=hash('sha256',$input['text']);$current_digest=phpbb_attach_admin_comment_digest($row['comment']);
            // An untouched field follows the current database value. A deliberate
            // conflicting edit rejects the complete selection before any UPDATE.
            if($desired_digest!==$input['original_comment']&&$desired_digest!==$current_digest){
                if($current_digest!==$input['original_comment']){$this->storage_fail('Attachment_metadata_conflict');}
                $stored=htmlspecialchars($input['text'],ENT_QUOTES,'UTF-8');
                if(strlen($stored)>1020||preg_match_all('/./us',$stored,$characters)>255){$this->storage_fail('Attachment_metadata_invalid');}
                $after['comment']=$stored;
            }
            if($input['count']!==$input['original_count']&&$input['count']!==(string)$row['download_count']){
                if((string)$row['download_count']!==$input['original_count']){$this->storage_fail('Attachment_metadata_conflict');}
                $after['download_count']=$input['count'];
            }
            $plan[$id]=array('before'=>$before,'after'=>$after);
        }
        $changed=0;
        foreach($plan as$id=>$entry){
            if($entry['before']===$entry['after']){continue;}
            $set=array();foreach(array('comment','download_count')as$field){if($entry['before'][$field]!==$entry['after'][$field]){$set[]=$field."='".$this->escape($entry['after'][$field])."'";}}
            $this->query('UPDATE '.ATTACHMENTS_DESC_TABLE.' SET '.implode(',',$set).' WHERE attach_id='.$id.' AND '.$actor['guard']);
            $stored=$this->rows('SELECT * FROM '.ATTACHMENTS_DESC_TABLE.' WHERE attach_id='.$id);
            if(count($stored)!==1||$this->named($stored[0])!==$entry['after']){$this->storage_fail();}$changed++;
        }
        $stored=$this->rows('SELECT * FROM '.ATTACHMENTS_DESC_TABLE.' WHERE attach_id IN ('.implode(',',$ids).') ORDER BY attach_id');
        if(count($stored)!==count($plan)){$this->storage_fail();}
        foreach($stored as$row){if($this->named($row)!==$plan[(int)$row['attach_id']]['after']){$this->storage_fail();}}
        $this->current_actor();$this->commit_attempted=true;$this->commit();return $changed;
    }
}

function phpbb_attach_admin_metadata_save($database,$request)
{
    global $userdata;$store=null;
    try{
        if(!is_array($request)||!isset($_SERVER['REQUEST_METHOD'])||$_SERVER['REQUEST_METHOD']!=='POST'||!is_array($userdata)
            ||!isset($userdata['user_id'],$userdata['session_id'],$userdata['session_logged_in'],$userdata['session_admin'],$request['sid'])
            ||!(is_int($userdata['user_id'])||is_string($userdata['user_id']))||preg_match('/^[1-9][0-9]{0,6}$/D',(string)$userdata['user_id'])!==1||(float)$userdata['user_id']>8388607
            ||!in_array($userdata['session_logged_in'],array(true,1,'1'),true)||!in_array($userdata['session_admin'],array(true,1,'1'),true)
            ||!is_string($userdata['session_id'])||$userdata['session_id']===''||strlen($userdata['session_id'])>32||strpos($userdata['session_id'],"\0")!==false
            ||!is_string($request['sid'])||!hash_equals($userdata['session_id'],$request['sid'])||!phpbb_request_source_is_same_origin()){
            throw new PhpbbOwnedStorageFailure('Session_invalid');
        }
        $entries=phpbb_attach_admin_metadata_input($request);
        $store=new PhpbbAttachAdminMetadataStore($database,array('id'=>(int)$userdata['user_id'],'sid'=>$userdata['session_id']));
        return $store->save($entries);
    }catch(PhpbbOwnedStorageFailure$error){if($store&&$store->commit_attempted){throw new PhpbbOwnedStorageFailure('Attachment_metadata_unconfirmed');}throw$error;}
    catch(Exception$error){throw new PhpbbOwnedStorageFailure($store&&$store->commit_attempted?'Attachment_metadata_unconfirmed':'Attachment_metadata_failed');}
    catch(Error$error){throw new PhpbbOwnedStorageFailure($store&&$store->commit_attempted?'Attachment_metadata_unconfirmed':'Attachment_metadata_failed');}
    finally{if($store){$store->release();}}
}
