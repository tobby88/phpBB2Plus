<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_owned_storage.php';
require_once dirname(__FILE__) . '/../attach_mod/includes/functions_pm_staging.php';

function phpbb_uacp_attachment_ids($values)
{
    if (!is_array($values) || count($values) > 1000) { throw new PhpbbOwnedStorageFailure('Uacp_attachment_selection'); }
    $ids = array();
    foreach ($values as $value) {
        if (!(is_int($value) || is_string($value)) || preg_match('/^[1-9][0-9]{0,7}$/D', (string)$value) !== 1 || (float)$value > 16777215) { throw new PhpbbOwnedStorageFailure('Uacp_attachment_selection'); }
        $ids[(int)$value] = (int)$value;
    }
    sort($ids, SORT_NUMERIC); return array_values($ids);
}

function phpbb_uacp_pm_box($row,$target)
{
    $type=(int)$row['privmsgs_type'];$target=(int)$target;
    if(in_array($type,array(PRIVMSGS_READ_MAIL,PRIVMSGS_NEW_MAIL,PRIVMSGS_UNREAD_MAIL),true) && (int)$row['privmsgs_to_userid']===$target){return 'Inbox';}
    if($type===PRIVMSGS_SENT_MAIL && (int)$row['privmsgs_from_userid']===$target){return 'Sentbox';}
    if($type===PRIVMSGS_SAVED_IN_MAIL && (int)$row['privmsgs_to_userid']===$target || $type===PRIVMSGS_SAVED_OUT_MAIL && (int)$row['privmsgs_from_userid']===$target){return 'Savebox';}
    return false;
}

class PhpbbUacpAttachmentStore extends PhpbbOwnedStore
{
    var $commit_attempted = false;
    var $confirmed = false;
    private $posts = array();
    private $messages = array();
    private $descriptions = array();

    function __construct($database, $actor, $sid, $target)
    {
        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST'
            || !is_array($actor) || !isset($actor['user_id'],$actor['session_id'],$actor['session_logged_in'])
            || !in_array($actor['session_logged_in'],array(true,1,'1'),true)
            || !$this->valid_user($actor['user_id']) || !$this->valid_user($target)
            || !is_string($sid) || $sid === '' || strlen($sid) > 32 || strpos($sid,"\0") !== false
            || !is_string($actor['session_id']) || !hash_equals($actor['session_id'],$sid)) { $this->storage_fail('Session_invalid'); }
        parent::__construct($database,array('id'=>(int)$actor['user_id'],'sid'=>$sid,'target'=>(int)$target));
    }
    private function valid_user($value) { return (is_int($value)||is_string($value)) && preg_match('/^[1-9][0-9]{0,6}$/D',(string)$value)===1 && (float)$value<=8388607; }
    protected function storage_tables() { return array(CONFIG_TABLE,SESSIONS_TABLE,USERS_TABLE,FORUMS_TABLE,TOPICS_TABLE,POSTS_TABLE,AUTH_ACCESS_TABLE,USER_GROUP_TABLE,PRIVMSGS_TABLE,ATTACHMENTS_TABLE,ATTACHMENTS_DESC_TABLE); }
    protected function storage_fail($key = 'Uacp_attachment_failed')
    {
        if ($key === 'Owned_storage_failed') { $key = 'Uacp_attachment_failed'; }
        if ($key === 'Owned_storage_upgrade') { $key = 'Uacp_attachment_upgrade'; }
        throw new PhpbbOwnedStorageFailure($key);
    }
    // The real core/private auth evaluator uses this same owning connection.
    function sql_query($sql,$transaction=false) { if (!is_string($sql) || strpos($sql,'SELECT ') !== 0 || $transaction) { $this->storage_fail(); } return $this->query($sql); }
    function sql_fetchrow($r) { return $this->connection->sql_fetchrow($r); }
    function sql_fetchrowset($r) { return $this->connection->sql_fetchrowset($r); }
    function sql_freeresult($r) { return $this->connection->sql_freeresult($r); }
    function sql_escape($v) { return $this->escape($v); }

    private function current_actor()
    {
        $ttl=$this->rows('SELECT config_value FROM '.CONFIG_TABLE." WHERE config_name='session_length' AND HEX(config_name)=HEX('session_length') LOCK IN SHARE MODE");
        if(count($ttl)!==1 || preg_match('/^[1-9][0-9]{0,9}$/D',(string)$ttl[0]['config_value'])!==1 || (float)$ttl[0]['config_value']>2147483647){$this->storage_fail();}
        $sid=$this->escape($this->actor['sid']);$id=$this->actor['id'];
        $sessions=$this->rows('SELECT session_time FROM '.SESSIONS_TABLE." WHERE session_id='$sid' AND HEX(session_id)=HEX('$sid') AND session_user_id=$id AND session_logged_in=1 FOR UPDATE");
        if(count($sessions)!==1 || preg_match('/^(?:0|[1-9][0-9]{0,9})$/D',(string)$sessions[0]['session_time'])!==1 || (float)$sessions[0]['session_time']<time()-(int)$ttl[0]['config_value']){$this->storage_fail('Session_invalid');}
        $users=$this->rows('SELECT * FROM '.USERS_TABLE.' WHERE user_id='.$id.' FOR UPDATE');
        if(count($users)!==1 || (string)$users[0]['user_active']!=='1' || !in_array((string)$users[0]['user_level'],array((string)USER,(string)MOD,(string)ADMIN),true)
            || preg_match('/^(?:0|[1-9][0-9]{0,9})$/D',(string)$users[0]['user_blocktime'])!==1 || (float)$users[0]['user_blocktime']>=time()
            || ($id!==$this->actor['target'] && (string)$users[0]['user_level']!==(string)ADMIN)){$this->storage_fail('Not_Authorised');}
        $users[0]['session_id']=$this->actor['sid'];$users[0]['session_logged_in']=true;
        return $users[0];
    }
    private function post_access($post,$user)
    {
        $rows=$this->rows('SELECT p.post_id,p.poster_id,p.forum_id,p.topic_id,p.post_deleted,t.forum_id AS topic_forum,t.topic_moved_id FROM '.POSTS_TABLE.' p JOIN '.TOPICS_TABLE.' t ON t.topic_id=p.topic_id WHERE p.post_id='.(int)$post.' FOR UPDATE');
        if(count($rows)!==1 || (int)$rows[0]['poster_id']!==$this->actor['target'] || (string)$rows[0]['post_deleted']!=='0' || (int)$rows[0]['forum_id']<1 || (string)$rows[0]['forum_id']!==(string)$rows[0]['topic_forum'] || (int)$rows[0]['topic_moved_id']!==0){$this->storage_fail('Uacp_attachment_selection');}
        $forum=(int)$rows[0]['forum_id'];
        if(count($this->rows('SELECT forum_id FROM '.FORUMS_TABLE.' WHERE forum_id='.$forum.' FOR UPDATE'))!==1){$this->storage_fail('Uacp_attachment_selection');}
        $groups=$this->rows('SELECT group_id,user_pending FROM '.USER_GROUP_TABLE.' WHERE user_id='.$this->actor['id'].' LOCK IN SHARE MODE');
        foreach($groups as$group){$this->rows('SELECT group_id FROM '.AUTH_ACCESS_TABLE.' WHERE group_id='.(int)$group['group_id'].' AND forum_id='.$forum.' LOCK IN SHARE MODE');}
        $auth=auth(AUTH_ALL,$forum,$user,'',$this);
        if(empty($auth['auth_view']) || empty($auth['auth_read'])){$this->storage_fail('Not_Authorised');}
        return $rows[0];
    }
    private function message_access($message)
    {
        $rows=$this->rows('SELECT * FROM '.PRIVMSGS_TABLE.' WHERE privmsgs_id='.(int)$message.' FOR UPDATE');
        if(count($rows)!==1 || $rows[0]['privmsgs_write_payload']!==null){return false;}
        return phpbb_uacp_pm_box($rows[0],$this->actor['target'])!==false;
    }
    private function links($id) { return $this->rows('SELECT attach_id,post_id,privmsgs_id,user_id_1,user_id_2 FROM '.ATTACHMENTS_TABLE.' WHERE attach_id='.(int)$id.' ORDER BY post_id,privmsgs_id,user_id_1,user_id_2 FOR UPDATE'); }
    private function link_key($row) { return implode(':',array((int)$row['attach_id'],(int)$row['post_id'],(int)$row['privmsgs_id'],(int)$row['user_id_1'],(int)$row['user_id_2'])); }
    private function link_where($row) { $parts=array();foreach(array('attach_id','post_id','privmsgs_id','user_id_1','user_id_2')as$key){$parts[]=$key.'='.(int)$row[$key];}return implode(' AND ',$parts); }

    function remove($ids,$confirm)
    {
        if(!is_bool($confirm) || !$ids){$this->storage_fail('Uacp_attachment_selection');}
        $user=$this->current_actor();$target=$this->actor['target'];
        if(count($this->rows('SELECT user_id FROM '.USERS_TABLE.' WHERE user_id='.$target.' FOR UPDATE'))!==1){$this->storage_fail('No_such_user');}
        $selected=$remaining=array();
        foreach($ids as$id){
            $descs=$this->rows('SELECT * FROM '.ATTACHMENTS_DESC_TABLE.' WHERE attach_id='.$id.' FOR UPDATE');
            if(count($descs)!==1){$this->storage_fail('Uacp_attachment_selection');}$this->descriptions[$id]=$descs[0];$found=false;$remaining[$id]=array();
            foreach($this->links($id)as$link){
                $eligible=false;
                if((int)$link['user_id_1']===$target || (int)$link['user_id_2']===$target){
                    if((int)$link['post_id']>0 && (int)$link['privmsgs_id']===0){$post=$this->post_access((int)$link['post_id'],$user);$this->posts[(int)$post['post_id']]=$post;$eligible=true;}
                    elseif((int)$link['post_id']===0 && (int)$link['privmsgs_id']>0 && $this->message_access((int)$link['privmsgs_id'])){$this->messages[(int)$link['privmsgs_id']]=true;$eligible=true;}
                }
                if($eligible){$selected[$this->link_key($link)]=$link;$found=true;}else{$remaining[$id][]=$this->link_key($link);}
            }
            if(!$found){$this->storage_fail('Uacp_attachment_selection');}
        }
        if($confirm){
            foreach($selected as$link){$this->query('DELETE FROM '.ATTACHMENTS_TABLE.' WHERE '.$this->link_where($link));}
            foreach($remaining as$id=>$keys){$actual=array();foreach($this->links($id)as$row){$actual[]=$this->link_key($row);}if($actual!==$keys){$this->storage_fail();}}
            foreach($this->posts as$post){$id=(int)$post['post_id'];$this->query('UPDATE '.POSTS_TABLE.' SET post_attachment=EXISTS(SELECT 1 FROM '.ATTACHMENTS_TABLE.' WHERE post_id='.$id.' AND privmsgs_id=0) WHERE post_id='.$id);$rows=$this->rows('SELECT post_attachment,EXISTS(SELECT 1 FROM '.ATTACHMENTS_TABLE.' WHERE post_id='.$id.' AND privmsgs_id=0) AS expected FROM '.POSTS_TABLE.' WHERE post_id='.$id);if(count($rows)!==1||(string)$rows[0]['post_attachment']!==(string)$rows[0]['expected']){$this->storage_fail();}}
            $topics=array();foreach($this->posts as$post){$topics[(int)$post['topic_id']]=true;}foreach($topics as$id=>$unused){$this->query('UPDATE '.TOPICS_TABLE.' SET topic_attachment=EXISTS(SELECT 1 FROM '.POSTS_TABLE.' WHERE topic_id='.$id.' AND post_deleted=0 AND post_attachment=1) WHERE topic_id='.$id);$rows=$this->rows('SELECT topic_attachment,EXISTS(SELECT 1 FROM '.POSTS_TABLE.' WHERE topic_id='.$id.' AND post_deleted=0 AND post_attachment=1) AS expected FROM '.TOPICS_TABLE.' WHERE topic_id='.$id);if(count($rows)!==1||(string)$rows[0]['topic_attachment']!==(string)$rows[0]['expected']){$this->storage_fail();}}
            foreach($this->messages as$id=>$unused){$this->query('UPDATE '.PRIVMSGS_TABLE.' SET privmsgs_attachment=EXISTS(SELECT 1 FROM '.ATTACHMENTS_TABLE.' WHERE privmsgs_id='.$id.' AND post_id=0) WHERE privmsgs_id='.$id);$rows=$this->rows('SELECT privmsgs_attachment,EXISTS(SELECT 1 FROM '.ATTACHMENTS_TABLE.' WHERE privmsgs_id='.$id.' AND post_id=0) AS expected FROM '.PRIVMSGS_TABLE.' WHERE privmsgs_id='.$id);if(count($rows)!==1||(string)$rows[0]['privmsgs_attachment']!==(string)$rows[0]['expected']){$this->storage_fail();}}
        }
        $user=$this->current_actor();if(count($this->rows('SELECT user_id FROM '.USERS_TABLE.' WHERE user_id='.$target.' FOR UPDATE'))!==1){$this->storage_fail('No_such_user');}foreach($this->posts as$post){$this->post_access((int)$post['post_id'],$user);}foreach($this->messages as$id=>$unused){if(!$this->message_access($id)){$this->storage_fail('Uacp_attachment_selection');}}
        foreach($this->descriptions as$row){if(count($this->rows('SELECT attach_id FROM '.ATTACHMENTS_DESC_TABLE.' WHERE '.$this->description_guard($row,false)))!==1){$this->storage_fail();}}
        $this->commit_attempted=true;$this->commit();$this->confirmed=true;
        return array('count'=>count($ids),'cleanup_complete'=>!$confirm || $this->cleanup());
    }
    private function description_guard($row,$unreferenced=true)
    {
        $terms=array('attach_id='.(int)$row['attach_id']);foreach($row as$key=>$value){if(!is_string($key)){continue;}if(preg_match('/^[a-z_][a-z0-9_]*$/D',$key)!==1){$this->storage_fail();}$terms[]=$value===null?$key.' IS NULL':"HEX(CAST($key AS CHAR))=HEX('".$this->escape((string)$value)."')";}
        if($unreferenced){$terms[]='NOT EXISTS(SELECT 1 FROM '.ATTACHMENTS_TABLE.' WHERE attach_id='.(int)$row['attach_id'].')';}return implode(' AND ',$terms);
    }
    // Descriptions reserve detached filenames durably. Failed cleanup remains
    // recoverable through the existing ACP Shadow attachments facility.
    private function cleanup()
    {
        if(!$this->confirmed || $this->transaction || !function_exists('attach_delete_file')){return false;}
        try{
            foreach($this->descriptions as$id=>$old){
                $this->finished=false;$this->query('START TRANSACTION');$this->transaction=true;$this->pin_tables();$this->current_actor();
                $guard=$this->description_guard($old);
                if(!$this->rows('SELECT attach_id FROM '.ATTACHMENTS_DESC_TABLE.' WHERE '.$guard.' FOR UPDATE')){
                    if(!$this->rows('SELECT attach_id FROM '.ATTACHMENTS_TABLE.' WHERE attach_id='.$id.' LIMIT 1')){$this->storage_fail();}
                    $this->commit();continue;
                }
                if(attach_pm_stage_is_claimed($this,$old['physical_filename']) || ($old['pm_write_token']!==null && $old['pm_write_token']!=='' && $this->rows('SELECT privmsgs_id FROM '.PRIVMSGS_TABLE." WHERE privmsgs_write_payload IS NOT NULL AND HEX(privmsgs_write_token)=HEX('".$this->escape($old['pm_write_token'])."') LIMIT 1"))){$this->storage_fail();}
                $shared=$this->rows('SELECT attach_id FROM '.ATTACHMENTS_DESC_TABLE." WHERE physical_filename='".$this->escape($old['physical_filename'])."' AND attach_id<>$id LIMIT 1 LOCK IN SHARE MODE");
                if(!$shared){
                    if((string)$old['thumbnail']==='1'){
                        if(!attach_delete_file($old['physical_filename'],MODE_THUMBNAIL)){$this->storage_fail();}
                        $this->query('UPDATE '.ATTACHMENTS_DESC_TABLE.' SET thumbnail=0 WHERE '.$guard);$old['thumbnail']='0';$guard=$this->description_guard($old);
                    }
                    if(!attach_delete_file($old['physical_filename'])){$this->storage_fail();}
                }
                $this->query('DELETE FROM '.ATTACHMENTS_DESC_TABLE.' WHERE '.$guard);
                if($this->rows('SELECT attach_id FROM '.ATTACHMENTS_DESC_TABLE.' WHERE attach_id='.$id)){$this->storage_fail();}
                $this->commit();
            }
            return true;
        }catch(Exception$error){return false;}catch(Error$error){return false;}
    }
}

function phpbb_uacp_attachments_remove($database,$actor,$sid,$target,$values,$confirm)
{
    $store=null;
    try{
        $ids=phpbb_uacp_attachment_ids($values);
        if(!$ids || !is_bool($confirm)){throw new PhpbbOwnedStorageFailure('Uacp_attachment_selection');}
        $store=new PhpbbUacpAttachmentStore($database,$actor,$sid,$target);
        return $store->remove($ids,$confirm);
    }catch(PhpbbOwnedStorageFailure$error){if($store && $store->commit_attempted && !$store->confirmed){throw new PhpbbOwnedStorageFailure('Uacp_attachment_unconfirmed');}throw$error;}
    catch(Exception$error){throw new PhpbbOwnedStorageFailure($store && $store->commit_attempted && !$store->confirmed?'Uacp_attachment_unconfirmed':'Uacp_attachment_failed');}
    catch(Error$error){throw new PhpbbOwnedStorageFailure($store && $store->commit_attempted && !$store->confirmed?'Uacp_attachment_unconfirmed':'Uacp_attachment_failed');}
    finally{if($store){$store->release();}}
}
