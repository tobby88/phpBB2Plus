<?php
// Recovery is a separate, freshly authenticated ACP action, not a continuation
// of the original frontend deletion. Only disposable canonical native schemas.
require_once __DIR__.'/uacp-list-native-fixture.php';
function native_attachment_shadow_recover($ids)
{
    $peer=$GLOBALS['peer'];
    $query=function($sql)use($peer){$r=$peer->sql_query($sql);if(!$r){throw new RuntimeException('Owned Shadow recovery setup failed');}return $r;};
    $rows=function($sql)use($peer,$query){$r=$query($sql);try{return $peer->sql_fetchrowset($r);}finally{$peer->sql_freeresult($r);}};
    $schema=$rows('SELECT DATABASE() name');
    if(count($schema)!==1||preg_match('/^codex_(?:post_delete|topic_removal|kb_publish)_[a-f0-9]{16}$/D',$schema[0]['name'])!==1){throw new RuntimeException('Owned deletion fixture schema required');}
    $saved=array();foreach(array('userdata','db','_POST','_SERVER')as$key){$saved[$key]=array_key_exists($key,$GLOBALS)?array(true,$GLOBALS[$key]):array(false,null);}
    $id=8388500;$sid=bin2hex(phpbb_random_bytes(16));$created=false;
    if($rows('SELECT user_id FROM fixture_users WHERE user_id='.$id)){throw new RuntimeException('Synthetic recovery actor already exists');}
    $ttl=$rows("SELECT config_value FROM fixture_config WHERE config_name='session_length'");
    $jr=$rows("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='fixture_jr_admin_users'");
    try{
        if(!$jr){$ddl=file_get_contents($GLOBALS['phpbb_root_path'].'install/schemas/mysql_schema.sql');if(preg_match('/^CREATE TABLE phpbb_jr_admin_users\s*\([\s\S]*?;/m',$ddl,$m)!==1){throw new RuntimeException('Canonical recovery grants table required');}$query(str_replace('phpbb_jr_admin_users','fixture_jr_admin_users',$m[0]));$created=true;}
        uacp_list_fixture_insert($peer,'fixture_users',array('user_id'=>$id,'username'=>'Synthetic recovery owner','user_level'=>ADMIN,'user_active'=>1));
        uacp_list_fixture_insert($peer,'fixture_sessions',array('session_id'=>$sid,'session_user_id'=>$id,'session_logged_in'=>1,'session_admin'=>1,'session_time'=>time()));
        $query("INSERT INTO fixture_config(config_name,config_value) VALUES('session_length','3600') ON DUPLICATE KEY UPDATE config_value='3600'");
        $GLOBALS['userdata']=array('user_id'=>$id,'session_id'=>$sid,'session_logged_in'=>true,'session_admin'=>true);
        $GLOBALS['db']=$peer;$_POST=array('sid'=>$sid);$_SERVER=array('REQUEST_METHOD'=>'POST','HTTP_SEC_FETCH_SITE'=>'same-origin');
        $result=attach_shadow_cleanup(array(),$ids);
        if(empty($result['cleanup_complete'])){throw new RuntimeException('Authenticated ACP recovery must finish detached files');}
        return $result;
    }finally{
        $query("DELETE FROM fixture_sessions WHERE session_id='".$peer->sql_escape($sid)."'");$query('DELETE FROM fixture_users WHERE user_id='.$id);
        if($ttl){$query("UPDATE fixture_config SET config_value='".$peer->sql_escape($ttl[0]['config_value'])."' WHERE config_name='session_length'");}else{$query("DELETE FROM fixture_config WHERE config_name='session_length'");}
        if($created){$query('DROP TABLE fixture_jr_admin_users');}
        foreach($saved as$key=>$entry){if($entry[0]){$GLOBALS[$key]=$entry[1];}else{unset($GLOBALS[$key]);}}
    }
}
