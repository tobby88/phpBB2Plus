<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_file_actions.php';

function pafiledb_permission_fields($global = false)
{
    return $global ? array('auth_search','auth_stats','auth_toplist','auth_viewall') : array('auth_view','auth_read','auth_view_file','auth_edit_file','auth_delete_file','auth_upload','auth_download','auth_rate','auth_email','auth_view_comment','auth_post_comment','auth_edit_comment','auth_delete_comment');
}
function pafiledb_permission_draft($post,$key,$id,$fallback,$levels = false)
{
    if(!is_array($post)||!array_key_exists($key,$post)){return$fallback;}$value=$post[$key];if($id!==null){if(!is_array($value)||!array_key_exists($id,$value)){return$fallback;}$value=$value[$id];}
    try{return pafiledb_permission_value($value,$levels);}catch(PaFileDbFileActionFailure$error){return$fallback;}
}
function pafiledb_permission_rows($storage,$sql)
{
    $r=$storage->sql_query($sql);try{return$storage->sql_fetchrowset($r);}finally{$storage->sql_freeresult($r);}
}
function pafiledb_permission_ids($post,$global = false)
{
    // The renderer puts this inventory after every permission control. If PHP
    // truncates input (for example at max_input_vars), refuse the whole batch.
    if(!is_array($post)||!isset($post['permission_ids'])||!is_string($post['permission_ids'])){pafiledb_file_fail('PA_permissions_incomplete');}
    $value=$post['permission_ids'];if($global){if($value!=='0'){pafiledb_file_fail('PA_permissions_incomplete');}return array(0);}
    if(strlen($value)>5500||!preg_match('/^[1-9][0-9]*(?:,[1-9][0-9]*)*$/D',$value)){pafiledb_file_fail('PA_permissions_incomplete');}
    $ids=array();foreach(explode(',',$value)as$id){$id=PaFileDbFileAuthority::id($id);if(isset($ids[$id])){pafiledb_file_fail('PA_permissions_invalid');}$ids[$id]=$id;}if(count($ids)>500){pafiledb_file_fail('PA_permissions_invalid');}ksort($ids,SORT_NUMERIC);return array_values($ids);
}
function pafiledb_permission_value($value,$levels = false)
{
    if(!(is_int($value)||is_string($value))||!in_array((string)$value,$levels?array((string)AUTH_ALL,(string)AUTH_REG,(string)AUTH_ACL,(string)AUTH_MOD,(string)AUTH_ADMIN):array('0','1'),true)){pafiledb_file_fail('PA_permissions_invalid');}return(int)$value;
}
function pafiledb_permission_map($post,$name,$ids,$required = false,$levels = false)
{
    if(!array_key_exists($name,$post)){if($required){pafiledb_file_fail('PA_permissions_incomplete');}return array();}
    if(!is_array($post[$name])||count($post[$name])>500){pafiledb_file_fail('PA_permissions_invalid');}$out=array();
    foreach($post[$name]as$id=>$value){$id=PaFileDbFileAuthority::id($id);if(!in_array($id,$ids,true)){pafiledb_file_fail('PA_permissions_invalid');}$out[$id]=pafiledb_permission_value($value,$levels);}
    if($required&&count($out)!==count($ids)){pafiledb_file_fail('PA_permissions_incomplete');}return$out;
}
function pafiledb_permission_categories($storage,$ids,$authority)
{
    $rows=pafiledb_permission_rows($storage,'SELECT * FROM '.PA_CATEGORY_TABLE.' WHERE cat_id IN('.implode(',',$ids).') ORDER BY cat_id FOR UPDATE');if(count($rows)!==count($ids)){pafiledb_file_fail('PA_permissions_missing');}
    $categories=array();foreach($rows as$row){$categories[(int)$row['cat_id']]=$row;}
    // Preserve the existing ACP catalogue scope for delegates: it displayed
    // categories passing their current auth_view, while root saw all rows.
    $user=$authority->user();if((int)$user['user_level']!==ADMIN){
        $members=pafiledb_permission_rows($storage,'SELECT group_id,user_pending FROM '.USER_GROUP_TABLE.' WHERE user_id='.(int)$user['user_id'].' ORDER BY group_id FOR UPDATE');$groups=array();
        foreach($members as$member){if((string)$member['user_pending']!=='0'){continue;}$id=PaFileDbFileAuthority::id($member['group_id']);if(pafiledb_permission_rows($storage,'SELECT group_id FROM '.GROUPS_TABLE.' WHERE group_id='.$id.' FOR UPDATE')){$groups[$id]=$id;}}
        $grants=array();if($groups){foreach(pafiledb_permission_rows($storage,'SELECT * FROM '.PA_AUTH_ACCESS_TABLE.' WHERE group_id IN('.implode(',',$groups).') ORDER BY group_id,cat_id FOR UPDATE')as$row){$grants[(int)$row['cat_id']][]=$row;}}
        $policy=new pafiledb();foreach($categories as$id=>$row){$level=pafiledb_permission_value($row['auth_view'],true);$access=isset($grants[$id])?$grants[$id]:array();if($level!==AUTH_ALL&&$level!==AUTH_REG&&!$policy->auth_check_user($level,$level===AUTH_MOD?'auth_mod':'auth_view',$access,false)){pafiledb_file_fail('Not_Authorised');}}
    }
    return$categories;
}
function pafiledb_permission_open($grants)
{
    global$db,$table_prefix;
    if(!defined('IN_ADMIN')||!IN_ADMIN||PA_CATEGORY_TABLE!==$table_prefix.'pa_cat'||PA_AUTH_ACCESS_TABLE!==$table_prefix.'pa_auth'){pafiledb_file_fail('Not_Authorised');}
    $actor=pafiledb_mutation_actor();$storage=new PaFileDbFileCoordinator($db,$table_prefix);
    try{$storage->begin_permissions($grants);$authority=new PaFileDbFileAuthority($storage,$table_prefix,$actor,'permissions');$authority->select('permissions',array());$storage->guard_commit(function()use($authority){return$authority->check();});return array($storage,$authority);}
    catch(Exception$error){$storage->release();throw$error;}catch(Error$error){$storage->release();throw$error;}
}
function pafiledb_category_permissions_save($post)
{
    $storage=null;
    try{
        $ids=pafiledb_permission_ids($post);$maps=array();foreach(pafiledb_permission_fields()as$field){$maps[$field]=pafiledb_permission_map($post,$field,$ids,true,true);}
        list($storage,$authority)=pafiledb_permission_open(false);pafiledb_permission_categories($storage,$ids,$authority);
        foreach($ids as$id){$sets=array();foreach($maps as$field=>$values){$sets[]=$field.'='.$values[$id];}$storage->sql_query('UPDATE '.PA_CATEGORY_TABLE.' SET '.implode(',',$sets).' WHERE cat_id='.$id);}
        $authority->check();$storage->commit();return$ids;
    }finally{if($storage){$storage->release();}}
}
function pafiledb_permission_target($storage,$mode,$post)
{
    // Never create or guess a personal group while saving permissions. Resolve
    // and lock its current memberships independently of the rendered form.
    $user=$mode==='user'||$mode==='glb_user';$id=PaFileDbFileAuthority::id(isset($post[$user?POST_USERS_URL:POST_GROUPS_URL])?$post[$user?POST_USERS_URL:POST_GROUPS_URL]:null);
    if($id>8388607){pafiledb_file_fail('PA_permissions_target');}
    if(!$user){$rows=pafiledb_permission_rows($storage,'SELECT group_id,group_name FROM '.GROUPS_TABLE.' WHERE group_id='.$id.' AND group_single_user=0 FOR UPDATE');if(count($rows)!==1){pafiledb_file_fail('PA_permissions_target');}return(int)$rows[0]['group_id'];}
    $rows=pafiledb_permission_rows($storage,'SELECT user_id FROM '.USERS_TABLE.' WHERE user_id='.$id.' AND user_id<>'.ANONYMOUS.' FOR UPDATE');if(count($rows)!==1){pafiledb_file_fail('PA_permissions_target');}
    $members=pafiledb_permission_rows($storage,'SELECT group_id,user_pending FROM '.USER_GROUP_TABLE.' WHERE user_id='.$id.' ORDER BY group_id FOR UPDATE');$groups=array();
    foreach($members as$member){if((string)$member['user_pending']!=='0'){continue;}$gid=PaFileDbFileAuthority::id($member['group_id']);$rows=pafiledb_permission_rows($storage,'SELECT group_id FROM '.GROUPS_TABLE.' WHERE group_id='.$gid.' AND group_single_user=1 FOR UPDATE');if(count($rows)===1){$groups[$gid]=$gid;}}
    if(count($groups)!==1){pafiledb_file_fail('PA_permissions_target');}$group=(int)reset($groups);
    $links=pafiledb_permission_rows($storage,'SELECT user_id,user_pending FROM '.USER_GROUP_TABLE.' WHERE group_id='.$group.' ORDER BY user_id FOR UPDATE');
    if(!$links){pafiledb_file_fail('PA_permissions_target');}foreach($links as$link){if((int)$link['user_id']!==$id||(string)$link['user_pending']!=='0'){pafiledb_file_fail('PA_permissions_target');}}return$group;
}
function pafiledb_permission_write($storage,$group,$cat,$values,$existing)
{
    $where=' WHERE group_id='.$group.' AND cat_id='.$cat;
    if(!array_sum($values)){$storage->sql_query('DELETE FROM '.PA_AUTH_ACCESS_TABLE.$where);return;}
    $sets=array();foreach($values as$field=>$value){$sets[]=$field.'='.$value;}
    if($existing){$storage->sql_query('UPDATE '.PA_AUTH_ACCESS_TABLE.' SET '.implode(',',$sets).$where);}
    else{
        $all=array_fill_keys(array_merge(pafiledb_permission_fields(),array('auth_mod'),pafiledb_permission_fields(true)),0);$all=array_merge($all,$values);$columns=array_merge(array('group_id','cat_id'),array_keys($all));$data=array_merge(array($group,$cat),array_values($all));
        $storage->sql_query('INSERT INTO '.PA_AUTH_ACCESS_TABLE.' ('.implode(',',$columns).') VALUES('.implode(',',$data).')');
    }
    $rows=pafiledb_permission_rows($storage,'SELECT * FROM '.PA_AUTH_ACCESS_TABLE.$where.' FOR UPDATE');if(!$rows){pafiledb_file_fail('PA_permissions_failed');}foreach($rows as$row){foreach($values as$field=>$value){if((string)$row[$field]!== (string)$value){pafiledb_file_fail('PA_permissions_failed');}}}
}
function pafiledb_group_permissions_save($post)
{
    $storage=null;
    try{
        if(!is_array($post)||!isset($post['mode'])||!in_array($post['mode'],array('user','group','glb_user','glb_group'),true)){pafiledb_file_fail('PA_permissions_invalid');}
        $mode=$post['mode'];$global=$mode==='glb_user'||$mode==='glb_group';$ids=pafiledb_permission_ids($post,$global);
        list($storage,$authority)=pafiledb_permission_open(true);$group=pafiledb_permission_target($storage,$mode,$post);
        $existing=array();$moderator=false;foreach(pafiledb_permission_rows($storage,'SELECT * FROM '.PA_AUTH_ACCESS_TABLE.' WHERE group_id='.$group.' ORDER BY cat_id FOR UPDATE')as$row){$existing[(int)$row['cat_id']]=true;$moderator=$moderator||!empty($row['auth_mod']);}
        $batch=array();
        if($global){
            $settings=$authority->settings();foreach(pafiledb_permission_fields(true)as$field){if(!isset($settings[$field])){pafiledb_file_fail('PA_file_upgrade');}$level=pafiledb_permission_value($settings[$field],true);$key='private_'.$field;if($level===AUTH_ACL&&!isset($post[$key])){pafiledb_file_fail('PA_permissions_incomplete');}$value=isset($post[$key])?pafiledb_permission_value($post[$key]):0;$batch[0][$field]=$level===AUTH_ACL&&!$moderator?$value:0;}
        }else{
            $categories=pafiledb_permission_categories($storage,$ids,$authority);$mods=pafiledb_permission_map($post,'moderator',$ids,true);$maps=array();foreach(pafiledb_permission_fields()as$field){$maps[$field]=pafiledb_permission_map($post,'private_'.$field,$ids);}
            foreach($ids as$id){$values=array('auth_mod'=>$mods[$id]);foreach(pafiledb_permission_fields()as$field){$level=pafiledb_permission_value($categories[$id][$field],true);if($level===AUTH_ACL&&!isset($maps[$field][$id])){pafiledb_file_fail('PA_permissions_incomplete');}$values[$field]=$level===AUTH_ACL&&!$mods[$id]&&isset($maps[$field][$id])?$maps[$field][$id]:0;}$batch[$id]=$values;}
        }
        foreach($batch as$cat=>$values){pafiledb_permission_write($storage,$group,$cat,$values,isset($existing[$cat]));}
        $authority->check();$storage->commit();return array('group_id'=>$group,'categories'=>array_keys($batch));
    }finally{if($storage){$storage->release();}}
}
