<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once __DIR__ . '/functions_admin_storage.php';
require_once __DIR__ . '/functions_pm_staging.php';

function phpbb_attach_admin_delete_ids($values)
{
    if (!is_array($values) || !$values || count($values)>1000 || array_keys($values)!==range(0,count($values)-1)) { throw new PhpbbOwnedStorageFailure('Attachment_admin_delete_invalid'); }
    $ids=array();foreach($values as$id){
        if (!(is_int($id)||is_string($id)) || preg_match('/^[1-9][0-9]{0,7}$/D',(string)$id)!==1 || (float)$id>16777215 || isset($ids[(int)$id])) { throw new PhpbbOwnedStorageFailure('Attachment_admin_delete_invalid'); }
        $ids[(int)$id]=(int)$id;
    }
    ksort($ids,SORT_NUMERIC);return array_values($ids);
}

class PhpbbAttachAdminDeleteStore extends PhpbbAttachAdminStore
{
    var $commit_attempted=false;
    var $confirmed=false;
    private $descriptions=array();
    private $thumbnail_files=array();
    protected function storage_tables() { return array(CONFIG_TABLE,SESSIONS_TABLE,USERS_TABLE,JR_ADMIN_TABLE,POSTS_TABLE,TOPICS_TABLE,PRIVMSGS_TABLE,ATTACHMENTS_TABLE,ATTACHMENTS_DESC_TABLE); }
    protected function storage_fail($key='Attachment_admin_delete_failed')
    {
        if($key==='Owned_storage_failed'){$key='Attachment_admin_delete_failed';}
        if($key==='Owned_storage_upgrade'){$key='Attachment_admin_delete_upgrade';}
        throw new PhpbbOwnedStorageFailure($key);
    }
    private function guard($row,$unreferenced=false)
    {
        $terms=array();foreach($row as$key=>$value){if(!is_string($key)){continue;}if(preg_match('/^[a-z_][a-z0-9_]*$/D',$key)!==1){$this->storage_fail();}$terms[]=$value===null?$key.' IS NULL':"HEX(CAST($key AS CHAR))=HEX('".$this->escape((string)$value)."')";}
        if(!$terms){$this->storage_fail();}if($unreferenced){$terms[]='NOT EXISTS(SELECT 1 FROM '.ATTACHMENTS_TABLE.' WHERE attach_id='.(int)$row['attach_id'].')';}return implode(' AND ',$terms);
    }
    private function active_upload($row)
    {
        return attach_pm_stage_is_claimed($this,$row['physical_filename'])
            || ($row['pm_write_token']!==null && $row['pm_write_token']!=='' && $this->rows('SELECT privmsgs_id FROM '.PRIVMSGS_TABLE." WHERE privmsgs_write_payload IS NOT NULL AND HEX(privmsgs_write_token)=HEX('".$this->escape($row['pm_write_token'])."') LIMIT 1"));
    }
    private function sync_flag($table,$key,$id,$field,$expected,$actor)
    {
        $this->query('UPDATE '.$table.' SET '.$field.'='.$expected.' WHERE '.$key.'='.$id.' AND '.$actor['guard']);
        $rows=$this->rows('SELECT '.$field.','.$expected.' AS expected FROM '.$table.' WHERE '.$key.'='.$id);
        if(count($rows)!==1 || (string)$rows[0][$field] !== (string)$rows[0]['expected']){$this->storage_fail();}
    }
    function remove($ids,$confirm)
    {
        if(!$ids || !is_bool($confirm)){$this->storage_fail('Attachment_admin_delete_invalid');}
        $actor=$this->current_actor();$selection=implode(',',$ids);
        $descs=$this->rows('SELECT * FROM '.ATTACHMENTS_DESC_TABLE.' WHERE attach_id IN ('.$selection.') ORDER BY attach_id FOR UPDATE');
        if(count($descs)!==count($ids)){$this->storage_fail('Attachment_admin_delete_invalid');}
        foreach($descs as$row){if($this->active_upload($row)){$this->storage_fail('Attachment_admin_delete_busy');}$this->descriptions[(int)$row['attach_id']]=$row;if((string)$row['thumbnail']==='1'){$this->thumbnail_files[$row['physical_filename']]=true;}}
        $links=$this->rows('SELECT attach_id,post_id,privmsgs_id FROM '.ATTACHMENTS_TABLE.' WHERE attach_id IN ('.$selection.') ORDER BY attach_id,post_id,privmsgs_id FOR UPDATE');
        $posts=$topics=$messages=array();
        foreach($links as$row){if((int)$row['post_id']>0){$posts[(int)$row['post_id']]=true;}if((int)$row['privmsgs_id']>0){$messages[(int)$row['privmsgs_id']]=true;}}
        ksort($posts,SORT_NUMERIC);ksort($messages,SORT_NUMERIC);
        foreach($posts as$id=>$unused){$rows=$this->rows('SELECT post_id,topic_id FROM '.POSTS_TABLE.' WHERE post_id='.$id.' FOR UPDATE');if(!$rows){unset($posts[$id]);continue;}$topics[(int)$rows[0]['topic_id']]=true;}
        ksort($topics,SORT_NUMERIC);foreach($topics as$id=>$unused){if(!$this->rows('SELECT topic_id FROM '.TOPICS_TABLE.' WHERE topic_id='.$id.' FOR UPDATE')){unset($topics[$id]);}}
        foreach($messages as$id=>$unused){if(!$this->rows('SELECT privmsgs_id FROM '.PRIVMSGS_TABLE.' WHERE privmsgs_id='.$id.' FOR UPDATE')){unset($messages[$id]);}}
        if($confirm){
            $this->query('DELETE FROM '.ATTACHMENTS_TABLE.' WHERE attach_id IN ('.$selection.') AND '.$actor['guard']);
            if($this->rows('SELECT attach_id FROM '.ATTACHMENTS_TABLE.' WHERE attach_id IN ('.$selection.')')){$this->storage_fail();}
            foreach($posts as$id=>$unused){$this->sync_flag(POSTS_TABLE,'post_id',$id,'post_attachment','EXISTS(SELECT 1 FROM '.ATTACHMENTS_TABLE.' WHERE post_id='.$id.' AND privmsgs_id=0)',$actor);}
            foreach($topics as$id=>$unused){$this->sync_flag(TOPICS_TABLE,'topic_id',$id,'topic_attachment','EXISTS(SELECT 1 FROM '.POSTS_TABLE.' WHERE topic_id='.$id.' AND post_deleted=0 AND post_attachment=1)',$actor);}
            foreach($messages as$id=>$unused){$this->sync_flag(PRIVMSGS_TABLE,'privmsgs_id',$id,'privmsgs_attachment','EXISTS(SELECT 1 FROM '.ATTACHMENTS_TABLE.' WHERE privmsgs_id='.$id.' AND post_id=0)',$actor);}
        }
        foreach($this->descriptions as$row){if(count($this->rows('SELECT attach_id FROM '.ATTACHMENTS_DESC_TABLE.' WHERE '.$this->guard($row)))!==1){$this->storage_fail();}}
        $this->current_actor();$this->commit_attempted=$confirm;$this->commit();$this->confirmed=true;
        return array('count'=>count($ids),'cleanup_complete'=>!$confirm || $this->cleanup());
    }
    // Detached descriptions reserve filenames after the atomic link/flag commit.
    // Failures leave them visible to the existing Shadow attachments recovery.
    private function cleanup()
    {
        if(!$this->confirmed || $this->transaction || !function_exists('attach_delete_file')){return false;}
        try{
            foreach($this->descriptions as$id=>$old){
                $this->finished=false;$this->query('START TRANSACTION');$this->transaction=true;$this->pin_tables();$this->current_actor();$guard=$this->guard($old,true);
                if(!$this->rows('SELECT attach_id FROM '.ATTACHMENTS_DESC_TABLE.' WHERE '.$guard.' FOR UPDATE')){
                    if(!$this->rows('SELECT attach_id FROM '.ATTACHMENTS_TABLE.' WHERE attach_id='.$id.' LIMIT 1')){$this->storage_fail();}$this->commit();continue;
                }
                if($this->active_upload($old)){$this->storage_fail();}
                $shared=$this->rows('SELECT attach_id FROM '.ATTACHMENTS_DESC_TABLE." WHERE physical_filename='".$this->escape($old['physical_filename'])."' AND attach_id<>$id LIMIT 1 LOCK IN SHARE MODE");
                if(!$shared){
                    if((string)$old['thumbnail']==='1'||isset($this->thumbnail_files[$old['physical_filename']])){
                        if(!attach_delete_file($old['physical_filename'],MODE_THUMBNAIL)){$this->storage_fail();}
                        $this->query('UPDATE '.ATTACHMENTS_DESC_TABLE.' SET thumbnail=0 WHERE '.$guard);$old['thumbnail']='0';$guard=$this->guard($old,true);
                    }
                    if(!attach_delete_file($old['physical_filename'])){$this->storage_fail();}
                }
                $this->query('DELETE FROM '.ATTACHMENTS_DESC_TABLE.' WHERE '.$guard);
                if($this->rows('SELECT attach_id FROM '.ATTACHMENTS_DESC_TABLE.' WHERE attach_id='.$id)){$this->storage_fail();}
                $this->current_actor();$this->commit();
            }
            return true;
        }catch(Exception$error){return false;}catch(Error$error){return false;}
    }
}

function phpbb_attach_admin_remove($database,$request,$confirm)
{
    $store=null;
    try{
        $actor=phpbb_attach_admin_request_actor($request);
        if(!is_bool($confirm)) { throw new PhpbbOwnedStorageFailure('Attachment_admin_delete_invalid'); }
        $ids=phpbb_attach_admin_delete_ids(isset($request['delete_id_list'])?$request['delete_id_list']:null);
        $store=new PhpbbAttachAdminDeleteStore($database,$actor);return $store->remove($ids,$confirm);
    }catch(PhpbbOwnedStorageFailure$error){if($store&&$store->commit_attempted&&!$store->confirmed){throw new PhpbbOwnedStorageFailure('Attachment_admin_delete_unconfirmed');}throw$error;}
    catch(Exception$error){throw new PhpbbOwnedStorageFailure($store&&$store->commit_attempted&&!$store->confirmed?'Attachment_admin_delete_unconfirmed':'Attachment_admin_delete_failed');}
    catch(Error$error){throw new PhpbbOwnedStorageFailure($store&&$store->commit_attempted&&!$store->confirmed?'Attachment_admin_delete_unconfirmed':'Attachment_admin_delete_failed');}
    finally{if($store){$store->release();}}
}
