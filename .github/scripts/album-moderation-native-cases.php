<?php
// Runs only inside the owned picture-deletion schema/filesystem fixture.
require $root.'album_mod/album_picture_moderation.php';
function acm_snapshot()
{
    $state=array();
    foreach(array('album'=>'pic_id','album_comment'=>'comment_id','album_rate'=>'rate_pic_id,rate_user_id')as$table=>$order)
    {
        $state[$table]=acd_rows('SELECT * FROM fixture_'.$table.' ORDER BY '.$order);
        foreach($state[$table]as&$row){foreach(array_keys($row)as$key){if(is_int($key)){unset($row[$key]);}}}unset($row);
    }
    return $state;
}
function acm_reset()
{
    acd_reset();
    acd_sql('INSERT INTO fixture_user_group(group_id,user_id,user_pending) VALUES(10,7,0)');
    acd_sql("INSERT INTO fixture_album_cat(cat_id,cat_title,cat_order,cat_user_id,cat_moderator_groups) VALUES(2,'Destination',2,0,'')");
    $GLOBALS['db']->moderation=true;
}
function acm_apply($mode,$ids=null,$target=null)
{
    return (new AlbumPictureModeration($GLOBALS['db'],'fixture_',$GLOBALS['base']))->apply($GLOBALS['userdata'],0,1,$ids===null?array(1):$ids,$mode,$target===null&&$mode==='move'?2:$target);
}
function acm_expected($mode,$before)
{
    $field=$mode==='move'?'pic_cat_id':(in_array($mode,array('lock','unlock'),true)?'pic_lock':'pic_approval');
    $value=$mode==='move'?'2':(in_array($mode,array('lock','approval'),true)?'1':'0');
    $before['album'][0][$field]=is_int($before['album'][0][$field])?(int)$value:$value;
    if($mode==='move'){$before['album_comment'][0]['comment_cat_id']=is_int($before['album_comment'][0]['comment_cat_id'])?2:'2';}
    return $before;
}
function acm_failed($callback,$description)
{
    $before=acm_snapshot(); $denied=false;
    try{$callback();}catch(AlbumFileCoordinatorFailure$error){$denied=true;}
    acd_check($denied,$description);acd_check(acm_snapshot()===$before,'Denied moderation preserves every picture, comment and rating');acd_files_intact();
    acd_check(!acd_rows('SELECT operation_id FROM fixture_album_file_jobs'),'Denied moderation creates no cleanup work');$GLOBALS['cases']++;
}
function acm_controller($mode,$posted=true,$ids=null)
{
    global $userdata,$db,$table_prefix,$phpbb_root_path,$lang;
    $source=$GLOBALS['moderation_source'];$pic_id=$posted?false:1;$album_user_id=0;$cat_id=1;
    $_POST=$posted?array('pic_id'=>$ids===null?array(1):$ids):array();
    if($mode==='move'){$_POST['target']='2';$start=strpos($source,'$target_cat_id = intval(phpbb_request_scalar');}
    elseif($mode==='delete'){$start=strpos($source,"album_modcp_apply_action('delete'");}
    else{$start=strpos($source,'$selected =',strpos($source,"else if (\$mode == '".$mode."')"));}
    $end=strpos($source,'$message =',$start);acd_check($start!==false&&$end>$start,'Actual moderation controller action boundary');
    eval(substr($source,$start,$end-$start));
}
function acm_cases()
{
    global $root,$base,$db,$userdata,$table_prefix,$phpbb_root_path,$lang,$cases;
    $GLOBALS['moderation_source']=file_get_contents($root.'album_modcp.php');
    acd_check(preg_match('/^function album_modcp_apply_action\(.*?^\}/ms',$GLOBALS['moderation_source'],$match)===1,'Actual moderation error adapter');eval($match[0]);
    if(!function_exists('phpbb_request_scalar')){require_once $root.'includes/functions_request.php';}
    // The real class is already loaded above. Keep its include path and media
    // root inside this fixture while executing the unmodified controller adapter.
    $shim=$base.'/album_mod/album_picture_moderation.php';
    acd_check(file_put_contents($shim,"<?php // Owned fixture: real service loaded before controller.\n")!==false,'Own controller include shim');
    $phpbb_root_path=$base.'/';$original_error_log=ini_set('error_log',$base.'/moderation-errors.log');$mode_baselines=array();$count_before=$cases;
    try
    {
        foreach(array('move','lock','unlock','approval','unapproval')as$mode)
        {
            acm_reset();$before=acm_snapshot();$db->peer_before=$before;$db->watch=true;$result=acm_apply($mode);
            acd_check($result['updated']===1&&$result['mode']===$mode,'Current moderator action '.$mode);$mode_baselines[$mode]=$db->queries;
            $after=acm_snapshot();$field=$mode==='move'?'pic_cat_id':(in_array($mode,array('lock','unlock'),true)?'pic_lock':'pic_approval');$value=$mode==='move'?'2':(in_array($mode,array('lock','approval'),true)?'1':'0');
            $expected=acm_expected($mode,$before);
            acd_check($after===$expected,'Exact intended metadata only changes for '.$mode);acd_files_intact();$cases++;
            if($mode!=='move'){acd_check(acm_apply($mode)['updated']===1&&acm_snapshot()===$after,'Repeated state action is idempotent');$cases++;}
            acm_reset();$expected=acm_expected($mode,acm_snapshot());$db->lost=true;$denied=false;try{acm_apply($mode);}catch(AlbumFileCoordinatorFailure$error){$denied=true;}
            acd_check($denied&&acm_snapshot()===$expected,'Lost commit acknowledgement retains exact committed action without guessing');acd_files_intact();$cases++;
            acm_reset();$expected=acm_expected($mode,acm_snapshot());acm_controller($mode);$actual=acm_snapshot();
            acd_check($actual===$expected,'Actual bulk POST controller routes '.$mode.' through the owned writer');$cases++;
            if($mode!=='move'){acm_reset();$expected=acm_expected($mode,acm_snapshot());acm_controller($mode,false);acd_check(acm_snapshot()===$expected,'Actual single-picture controller routes '.$mode.' through owned writer');$cases++;}
            foreach(array('DELETE FROM fixture_user_group','UPDATE fixture_users SET user_active=0 WHERE user_id=7','DELETE FROM fixture_sessions','UPDATE fixture_sessions SET session_time=1','UPDATE fixture_album SET pic_cat_id=2 WHERE pic_id=1','UPDATE fixture_album_cat SET cat_user_id=8 WHERE cat_id=1')as$change)
            {
                acm_reset();acd_sql($change);acm_failed(function()use($mode){acm_apply($mode);},'Fresh actor/session/membership/category recheck for '.$mode);
            }
            acm_reset();acm_failed(function()use($mode){acm_apply($mode,array(1,999999));},'A missing selected picture never causes partial success');
            acm_reset();acm_failed(function()use($mode){acm_apply($mode,array('1'));},'Service refuses unnormalized identifiers');
        }
        foreach(array('approval','unapproval')as$mode)
        {
            acm_reset();acd_sql('UPDATE fixture_album_cat SET cat_approval=1 WHERE cat_id=1');acm_failed(function()use($mode){acm_apply($mode);},'Admin-only approval is checked from locked category');
            acd_sql('UPDATE fixture_users SET user_level=1 WHERE user_id=7');acd_check(acm_apply($mode)['updated']===1,'Actual current administrator may approve admin-only category');$cases++;
        }
        acm_reset();acd_sql('UPDATE fixture_album_cat SET cat_user_id=8 WHERE cat_id=2');acm_failed(function(){acm_apply('move');},'Move cannot cross gallery owners');
        acm_reset();acd_sql('UPDATE fixture_album_cat SET cat_upload_level=1 WHERE cat_id=2');acm_failed(function(){acm_apply('move');},'Destination upload grant is rechecked independently of source moderation');
        acm_reset();acd_sql('UPDATE fixture_album_cat SET cat_upload_level=1 WHERE cat_id=1');acd_check(acm_apply('lock')['updated']===1,'Existing moderator may manage a source category restricted to admin uploads');$cases++;
        acm_reset();acd_sql('UPDATE fixture_album_cat SET cat_upload_level=1 WHERE cat_id=1');acd_check(acm_apply('delete')['deleted']===1,'Moderation deletion retains its separate delete permission without requiring uploads');$cases++;
        acm_reset();acd_sql('DELETE FROM fixture_user_group');acd_sql('UPDATE fixture_album_cat SET cat_user_id=7 WHERE cat_id IN(1,2)');
        acd_check((new AlbumPictureModeration($db,'fixture_',$base))->apply($userdata,7,1,array(1),'lock')['updated']===1,'Current personal-gallery owner retains enabled moderation');$cases++;
        acm_reset();acd_sql('DELETE FROM fixture_user_group');acd_sql('UPDATE fixture_album_cat SET cat_user_id=7 WHERE cat_id IN(1,2)');acd_sql("UPDATE fixture_album_config SET config_value='0' WHERE config_name='personal_allow_gallery_mod'");
        acm_failed(function()use($db,$base,$userdata){(new AlbumPictureModeration($db,'fixture_',$base))->apply($userdata,7,1,array(1),'lock');},'Fresh personal-gallery moderation setting is enforced');
        acm_reset();acd_sql('UPDATE fixture_users SET user_level=1 WHERE user_id=7');acd_sql('DELETE FROM fixture_user_group');acd_check(acm_apply('lock')['updated']===1,'Fresh administrator authority does not require category group');$cases++;
        acm_reset();acd_sql('DELETE FROM fixture_user_group');acd_sql('UPDATE fixture_users SET user_level=0 WHERE user_id=7');
        acm_failed(function(){acm_apply('delete');},'A former moderator cannot use ModCP to delete even their own picture');
        acm_reset();acm_controller('delete');acd_check(!acd_rows('SELECT pic_id FROM fixture_album')&&!acd_rows('SELECT comment_id FROM fixture_album_comment')&&!acd_rows('SELECT rate_pic_id FROM fixture_album_rate'),'Actual moderator delete controller commits all metadata and cleanup');
        foreach($GLOBALS['paths']as$path){acd_check(!is_file($base.'/'.$path),'Moderator delete removes all five unreferenced variants after commit');}$cases++;
        acm_reset();$before=acm_snapshot();$db->reset_case(1);$failed=false;try{acm_controller('lock');}catch(RuntimeException$error){$failed=$error->getMessage()==='The action could not be confirmed. Please reload the album page.';}
        acd_check($failed&&acm_snapshot()===$before,'Actual controller renders controlled fallback after owned connection failure');acd_files_intact();$cases++;
        acm_reset();acd_sql("CREATE TRIGGER own_moderation_change BEFORE UPDATE ON fixture_album FOR EACH ROW SET NEW.pic_title='Changed by trigger'");
        acm_failed(function(){acm_apply('lock');},'Readback refuses unrelated trigger changes to selected pictures');acd_sql('DROP TRIGGER own_moderation_change');
        acm_reset();acd_sql("CREATE TRIGGER own_moderation_comment BEFORE UPDATE ON fixture_album_comment FOR EACH ROW SET NEW.comment_cat_id=1");
        acm_failed(function(){acm_apply('move');},'Wrong moved comment readback rolls back picture move too');acd_sql('DROP TRIGGER own_moderation_comment');
        acm_reset();$values=array();for($id=2;$id<=1001;$id++){$values[]="(".$id.",'a.png','t.png','Bulk moderation',7,1)";}
        acd_sql('INSERT INTO fixture_album(pic_id,pic_filename,pic_thumbnail,pic_title,pic_user_id,pic_time) VALUES'.implode(',',$values));$ids=range(1001,1);$ids[]=1;
        acd_check(acm_apply('lock',$ids)['updated']===1001&&!acd_rows('SELECT pic_id FROM fixture_album WHERE pic_lock<>1'),'Bulk moderation spans SQL chunks without a silent total/UI limit');acd_files_intact();$cases++;
        acm_reset();$values=array();for($id=2;$id<=1001;$id++){$values[]="(".$id.",'a.png','t.png','Bulk moderation',7,1)";}
        acd_sql('INSERT INTO fixture_album(pic_id,pic_filename,pic_thumbnail,pic_title,pic_user_id,pic_time) VALUES'.implode(',',$values));acd_sql('UPDATE fixture_album SET pic_cat_id=2 WHERE pic_id=1001');
        acm_failed(function(){acm_apply('lock',range(1,1001));},'An unauthorized later chunk rolls back earlier updated chunks');
        if(getenv('PHPBB_ALBUM_DELETE_QUICK')!=='1')
        {
            foreach($mode_baselines as$mode=>$queries){foreach($queries as$index=>$sql){acm_reset();$before=acm_snapshot();$db->reset_case($index+1);$db->moderation=true;try{acm_apply($mode);}catch(AlbumFileCoordinatorFailure$error){}acd_check(acm_snapshot()===$before,'Failure at every actual SQL boundary rolls back '.$mode);acd_files_intact();$cases++;}}
        }
        echo 'Native Album moderation: '.($cases-$count_before).' cases PASS; fresh source/destination permissions, five actions, real POST/single-picture controllers, atomic comment moves, bulk rollback and lost COMMIT acknowledgement; no live data.',"\n";
    }
    finally
    {
        ini_set('error_log',$original_error_log);if(is_file($base.'/moderation-errors.log')){unlink($base.'/moderation-errors.log');}unlink($shim);$phpbb_root_path=$root;
    }
}
