<?php
require_once $root.'album_mod/album_category_remove.php';
function acat_snapshot()
{
    $state=acm_snapshot();$state['categories']=acd_rows('SELECT * FROM fixture_album_cat ORDER BY cat_id');
    foreach($state['categories']as&$row){foreach(array_keys($row)as$key){if(is_int($key)){unset($row[$key]);}}}unset($row);return $state;
}
function acat_number($previous,$number){return is_int($previous)?(int)$number:(string)$number;}
function acat_reset($public=false,$admin=false)
{
    acd_reset();$owner=$public?0:7;
    acd_sql('UPDATE fixture_album_cat SET cat_parent=10,cat_user_id='.$owner.' WHERE cat_id=1');
    acd_sql("INSERT INTO fixture_album_cat(cat_id,cat_title,cat_order,cat_user_id,cat_parent) VALUES(2,'Destination',20,".$owner.",10),(3,'Child',30,".$owner.",1),(4,'Grandchild',40,".$owner.",3),(10,'Root',10,".$owner.",0),(20,'Other gallery',50,8,0)");
    acd_sql("INSERT INTO fixture_album(pic_id,pic_filename,pic_title,pic_user_id,pic_time,pic_cat_id) VALUES(20,'foreign.png','Other gallery image',8,1,20)");
    acd_sql("INSERT INTO fixture_album_comment(comment_pic_id,comment_cat_id,comment_user_id,comment_user_ip,comment_time,comment_text) VALUES(20,20,8,'7f000001',1,'Other gallery comment')");
    acd_sql("INSERT INTO fixture_album_rate(rate_pic_id,rate_user_id,rate_user_ip,rate_point) VALUES(20,8,'7f000001',4)");
    if($public){acd_sql('UPDATE fixture_sessions SET session_admin=1');acd_sql("INSERT INTO fixture_jr_admin_users(user_id,user_jr_admin,admin_notes) VALUES(7,'".md5('Photo_AlbumCategoriesadmin_album_cat.php')."','')");}
    if($admin){acd_sql('UPDATE fixture_users SET user_level=1 WHERE user_id=7');}
    $GLOBALS['db']->category_operation=true;
}
function acat_remove($target=null,$owner=7)
{
    return (new AlbumCategoryRemove($GLOBALS['db'],'fixture_',$GLOBALS['base']))->remove($GLOBALS['userdata'],$owner,1,$target===null?ALBUM_JUMPBOX_DELETE:$target);
}
function acat_expected($before,$target)
{
    $ids=array();foreach($before['album']as$photo){if((int)$photo['pic_cat_id']===1){$ids[(int)$photo['pic_id']]=true;}}
    foreach(array('album'=>'pic_id','album_comment'=>'comment_pic_id','album_rate'=>'rate_pic_id')as$table=>$field)
    {
        $rows=array();foreach($before[$table]as$row){if(isset($ids[(int)$row[$field]])){
            if($target===ALBUM_JUMPBOX_DELETE){continue;}
            if($table==='album'){$row['pic_cat_id']=acat_number($row['pic_cat_id'],$target);}
            elseif($table==='album_comment'){$row['comment_cat_id']=acat_number($row['comment_cat_id'],$target);}
        }$rows[]=$row;}$before[$table]=$rows;
    }
    $source=null;foreach($before['categories']as$category){if((int)$category['cat_id']===1){$source=$category;break;}}acd_check($source!==null,'Known owned category before expectation');
    $rows=array();foreach($before['categories']as$row){if((int)$row['cat_id']===1){continue;}if((int)$row['cat_parent']===1&&(int)$row['cat_user_id']===(int)$source['cat_user_id']){$row['cat_parent']=acat_number($row['cat_parent'],$source['cat_parent']);}$rows[]=$row;}$before['categories']=$rows;return $before;
}
function acat_files()
{
    foreach($GLOBALS['paths']as$path){$file=$GLOBALS['base'].'/'.$path;acd_check(!file_exists($file)||(is_file($file)&&file_get_contents($file)===$GLOBALS['payload']),'Category cleanup never changes retained bytes');}
    acd_check(file_get_contents($GLOBALS['base'].'/album_mod/upload/foreign.png')==='Owned other-gallery file','Unrelated gallery file is always preserved');
}
function acat_consistent($before,$target)
{
    $actual=acat_snapshot();$jobs=acd_rows('SELECT picture_count,operation_kind,operation_state FROM fixture_album_file_jobs');
    if($actual===$before){acd_check(!$jobs,'Rolled-back category change has no cleanup receipt');acd_files_intact();}
    else{
        acd_check($actual===acat_expected($before,$target),'Hierarchy, photos, comments and ratings never partly commit');
        $pictures=0;foreach($before['album']as$photo){if((int)$photo['pic_cat_id']===1){$pictures++;}}
        if($target===ALBUM_JUMPBOX_DELETE&&$pictures){$recorded=0;foreach($jobs as$job){acd_check($job['operation_kind']==='delete'&&in_array($job['operation_state'],array('cleanup','complete'),true),'Only committed deletion receipts authorize category cleanup');$recorded+=(int)$job['picture_count'];}acd_check($recorded===$pictures,'Every deleted category picture is covered by a committed receipt');}
        else{acd_check(!$jobs,'Moving or removing an empty category never invents file cleanup');acd_files_intact();}
    }
    acat_files();
}
function acat_denied($callback,$description)
{
    $before=acat_snapshot();$denied=false;try{$callback();}catch(AlbumFileCoordinatorFailure$error){$denied=true;}catch(AlbumFileStorageFailure$error){$denied=true;}
    acd_check($denied,$description);acd_check(acat_snapshot()===$before&&!acd_rows('SELECT operation_id FROM fixture_album_file_jobs'),'Refused category change preserves all metadata');acd_files_intact();acat_files();$GLOBALS['cases']++;
}
function acat_controller($public,$target)
{
    $source=file_get_contents($GLOBALS['root'].($public?'admin/admin_album_cat.php':'album_personal_cat_admin.php'));
    if($public&&!function_exists('album_admin_post_scalar')){acd_check(preg_match('/^function album_admin_post_scalar\(.*?^\}/ms',$source,$match)===1,'Actual ACP scalar boundary');eval($match[0]);}
    $start=strpos($source,'$remove_result = album_category_remove_request(');$end=strpos($source,'$album_data = array();',$start);acd_check($start!==false&&$end>$start,'Actual category-removal controller action');
    $album_user_id=7;$cat_id=1;$_POST=array('cat_id'=>'1','target'=>(string)$target);eval(substr($source,$start,$end-$start));return $remove_result;
}
function acat_cases()
{
    global $root,$base,$db,$userdata,$phpbb_root_path,$cases;$count_before=$cases;$baselines=array();
    $foreign=$base.'/album_mod/upload/foreign.png';acd_check(file_put_contents($foreign,'Owned other-gallery file')===24,'Own unrelated-gallery bytes');
    $old_root=isset($phpbb_root_path)?$phpbb_root_path:null;$phpbb_root_path=$base.'/';$old_post=$_POST;$old_log=ini_set('error_log',$base.'/category-errors.log');
    try
    {
        foreach(array(false,true)as$public){foreach(array(ALBUM_JUMPBOX_DELETE,2)as$target){
            acat_reset($public);$before=acat_snapshot();$db->peer_before=$before;$db->category_watch=true;$result=acat_remove($target,$public?0:7);$baselines[]=array('public'=>$public,'target'=>$target,'queries'=>$db->queries);
            acd_check($result['children']===1&&$result['parent']===10&&$result['parent_title']==='Root'&&$result['pending']===0,'Current category removal reparents only direct children to its actual parent');
            acd_check($result[$target===ALBUM_JUMPBOX_DELETE?'deleted':'moved']===1&&acat_snapshot()===acat_expected($before,$target),'Actual root/delegated or personal category action');acat_consistent($before,$target);$cases++;
            if($target===ALBUM_JUMPBOX_DELETE){foreach($GLOBALS['paths']as$path){acd_check(!file_exists($base.'/'.$path),'Confirmed category deletion cleans all five image variants');}}
            acat_reset($public);$before=acat_snapshot();$result=acat_controller($public,$target);acd_check(is_array($result)&&acat_snapshot()===acat_expected($before,$target),'Actual ACP/personal controller delegates to the coordinated writer');acat_consistent($before,$target);$cases++;
            foreach(array('UPDATE fixture_users SET user_active=0 WHERE user_id=7','DELETE FROM fixture_sessions','UPDATE fixture_sessions SET session_time=1','UPDATE fixture_album_cat SET cat_user_id=8 WHERE cat_id=1')as$change){acat_reset($public);acd_sql($change);acat_denied(function()use($public,$target){acat_remove($target,$public?0:7);},'Current actor/session/category owner governs removal');}
        }}
        acat_reset(true);acd_sql('DELETE FROM fixture_jr_admin_users');acat_denied(function(){acat_remove(null,0);},'Stale administrator snapshot does not replace current delegated module grant');
        acat_reset(true);acd_sql('UPDATE fixture_sessions SET session_admin=0');acat_denied(function(){acat_remove(null,0);},'Delegated category deletion requires current ACP reauthentication');
        acat_reset(true,true);acd_sql('DELETE FROM fixture_jr_admin_users');$before=acat_snapshot();acd_check(acat_remove(null,0)['deleted']===1,'Current root admin may manage public categories without delegated grants');acat_consistent($before,ALBUM_JUMPBOX_DELETE);$cases++;
        acat_reset(true);acd_sql('UPDATE fixture_album_cat SET cat_parent=0 WHERE cat_id=1');$before=acat_snapshot();$result=acat_remove(null,0);
        acd_check($result['parent']===0&&$result['children']===1,'Top-level public category retains direct children at the public root');acat_consistent($before,ALBUM_JUMPBOX_DELETE);$cases++;
        acat_reset();acd_sql("UPDATE fixture_album_config SET config_value='0' WHERE config_name='personal_allow_gallery_mod'");acat_denied(function(){acat_remove();},'Fresh personal gallery management switch is enforced');
        acat_reset();acd_sql('UPDATE fixture_album_cat SET cat_parent=0 WHERE cat_id=1');acat_denied(function(){acat_remove();},'Gallery owner cannot bypass hidden root-deletion controls');
        foreach(array(1,3,4,20,999999)as$target){acat_reset();acat_denied(function()use($target){acat_remove($target);},'Self, descendant, foreign-owner or missing destinations are refused');}
        acat_reset();acd_sql('UPDATE fixture_album_cat SET cat_parent=3 WHERE cat_id=1');acat_denied(function(){acat_remove();},'Existing source cycle is preserved for deliberate repair');
        acat_reset();acd_sql('UPDATE fixture_album_cat SET cat_parent=999999 WHERE cat_id=2');acat_denied(function(){acat_remove(2);},'Destination orphan chain is not adopted');
        acat_reset();acd_sql('UPDATE fixture_album_cat SET cat_parent=1 WHERE cat_id=20');acat_denied(function(){acat_remove();},'Cross-owner child reference cannot be silently reparented or orphaned');
        acat_reset();acd_sql("UPDATE fixture_album SET pic_filename='../a.png' WHERE pic_id=1");acat_denied(function(){acat_remove();},'Malformed image path prevents deletion before any metadata commit');
        acat_reset();acd_sql('DELETE FROM fixture_album_comment WHERE comment_pic_id=1');acd_sql('DELETE FROM fixture_album_rate WHERE rate_pic_id=1');acd_sql('DELETE FROM fixture_album WHERE pic_id=1');$before=acat_snapshot();
        acd_check(acat_remove()['deleted']===0&&!acd_rows('SELECT operation_id FROM fixture_album_file_jobs'),'Empty category removal records no invented file operation');acat_consistent($before,ALBUM_JUMPBOX_DELETE);$cases++;
        acat_reset();$before=acat_snapshot();$db->lost=true;$denied=false;try{acat_remove();}catch(AlbumFileCoordinatorFailure$error){$denied=true;}
        acd_check($denied&&acat_snapshot()===acat_expected($before,ALBUM_JUMPBOX_DELETE),'Lost category commit acknowledgement leaves exact committed metadata');acd_files_intact();acat_consistent($before,ALBUM_JUMPBOX_DELETE);$cases++;
        $header=acd_rows('SELECT operation_id,binding_hash,capability_hash FROM fixture_album_file_jobs')[0];$job=array('operation'=>$header['operation_id'],'binding'=>$header['binding_hash'],'capability'=>$header['capability_hash']);$db->reset_case();acd_check(!in_array('pending',acd_resolve($job),true),'Committed category cleanup resolves even though source category no longer exists');acat_files();$cases++;
        acat_reset();$before=acat_snapshot();$db->lost=true;$denied=false;try{acat_remove(2);}catch(AlbumFileCoordinatorFailure$error){$denied=true;}
        acd_check($denied&&acat_snapshot()===acat_expected($before,2),'Lost move acknowledgement retains all committed photo/comment/hierarchy changes');acat_consistent($before,2);$cases++;
        acat_reset();acd_sql("UPDATE fixture_album SET pic_filename='a.png' WHERE pic_id=20");$before=acat_snapshot();acat_remove();acd_check(is_file($base.'/album_mod/upload/a.png'),'Surviving other-gallery reference protects shared original');acat_consistent($before,ALBUM_JUMPBOX_DELETE);$cases++;
        acat_reset();acd_sql("CREATE TRIGGER own_category_parent BEFORE UPDATE ON fixture_album_cat FOR EACH ROW SET NEW.cat_parent=0");acat_denied(function(){acat_remove();},'Changed child-parent readback rolls back deletion and receipt');acd_sql('DROP TRIGGER own_category_parent');
        acat_reset();acd_sql("CREATE TRIGGER own_category_comment BEFORE UPDATE ON fixture_album_comment FOR EACH ROW SET NEW.comment_cat_id=1");acat_denied(function(){acat_remove(2);},'Wrong moved comment category rolls back source-category removal');acd_sql('DROP TRIGGER own_category_comment');
        acat_reset();$before=acat_snapshot();$failed=false;try{acat_controller(false,0);}catch(RuntimeException$error){$failed=$error->getMessage()==='The category change could not be confirmed. Please reload the album page.';}
        acd_check($failed&&acat_snapshot()===$before,'Actual category controller gives controlled error after invalid target');acat_files();$cases++;
        acat_reset();$values=array();for($id=21;$id<=1020;$id++){$values[]="(".$id.",'a.png','t.png','Bulk category move',7,1)";}
        acd_sql('INSERT INTO fixture_album(pic_id,pic_filename,pic_thumbnail,pic_title,pic_user_id,pic_time) VALUES'.implode(',',$values));$before=acat_snapshot();
        acd_check(acat_remove(2)['moved']===1001,'Category content move spans SQL chunks without a UI selection limit');acat_consistent($before,2);$cases++;
        acat_reset();$values=array();for($id=21;$id<=1020;$id++){$values[]="(".$id.",'a.png','t.png','Bulk category rollback',7,1)";}
        acd_sql('INSERT INTO fixture_album(pic_id,pic_filename,pic_thumbnail,pic_title,pic_user_id,pic_time) VALUES'.implode(',',$values));
        acd_sql('CREATE TRIGGER own_category_late BEFORE UPDATE ON fixture_album FOR EACH ROW SET NEW.pic_cat_id=IF(OLD.pic_id=1020,OLD.pic_cat_id,NEW.pic_cat_id)');
        acat_denied(function(){acat_remove(2);},'A changed later chunk rolls back every earlier picture/comment move');acd_sql('DROP TRIGGER own_category_late');
        acat_reset();$values=array();for($id=21;$id<=10020;$id++){$values[]="(".$id.",'a.png','t.png','Bulk category delete',7,1)";}
        acd_sql('INSERT INTO fixture_album(pic_id,pic_filename,pic_thumbnail,pic_title,pic_user_id,pic_time) VALUES'.implode(',',$values));$before=acat_snapshot();$result=acat_remove();
        acd_check($result['deleted']===10001&&count($result['jobs'])===21&&$result['pending']===0,'Category deletion spans bounded journals without a total photo cap');
        foreach(acd_rows('SELECT picture_count,item_count,operation_state FROM fixture_album_file_jobs')as$job){acd_check((int)$job['picture_count']>=1&&(int)$job['picture_count']<=500&&(int)$job['item_count']<=2500&&$job['operation_state']==='complete','Every category cleanup receipt stays bounded and completes');}
        acat_consistent($before,ALBUM_JUMPBOX_DELETE);$cases++;
        foreach(array('before-commit','deleted','unlinked','complete')as$boundary){
            acat_reset();$before=acat_snapshot();acd_crash_worker($boundary,'category');acat_consistent($before,ALBUM_JUMPBOX_DELETE);
            if($boundary==='before-commit'){acd_check(acat_snapshot()===$before,'Killed category transaction preserves hierarchy and all picture metadata');acat_remove();}
            else{$header=acd_rows('SELECT operation_id,binding_hash,capability_hash FROM fixture_album_file_jobs')[0];$job=array('operation'=>$header['operation_id'],'binding'=>$header['binding_hash'],'capability'=>$header['capability_hash']);acd_sql('UPDATE fixture_users SET user_active=0 WHERE user_id=7');acd_sql('DELETE FROM fixture_sessions');acd_check(!in_array('pending',acd_resolve($job),true),'Previously committed category cleanup survives actor/session removal');}
            foreach($GLOBALS['paths']as$path){acd_check(!file_exists($base.'/'.$path),'Killed category cleanup safely resolves exact recorded variants');}acat_files();$cases++;
        }
        if(getenv('PHPBB_ALBUM_DELETE_QUICK')!=='1'){
            foreach($baselines as$baseline){foreach($baseline['queries']as$index=>$sql){acat_reset($baseline['public']);$before=acat_snapshot();$db->reset_case($index+1);$db->category_operation=true;try{acat_remove($baseline['target'],$baseline['public']?0:7);}catch(AlbumFileCoordinatorFailure$error){}catch(AlbumFileStorageFailure$error){}acat_consistent($before,$baseline['target']);$cases++;}}
        }
        echo 'Native Album category removal: '.($cases-$count_before).' cases PASS; actual ACP/personal actions, delegated grants, direct-child preservation, atomic picture/comment moves, cross-owner isolation, lost COMMIT and four forced process kills; no live data.',"\n";
    }
    finally{ini_set('error_log',$old_log);if(is_file($base.'/category-errors.log')){unlink($base.'/category-errors.log');}unlink($foreign);$_POST=$old_post;$phpbb_root_path=$old_root;}
}
