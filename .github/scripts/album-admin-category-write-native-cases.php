<?php
require_once $source_root.'album_mod/album_admin_category_storage.php';
$aaw_controller=file_get_contents($source_root.'admin/admin_album_cat.php');
foreach(array('album_admin_post_scalar','album_admin_permission_level','album_admin_category_post_values')as$aaw_function){ala_check(preg_match('/^function '.$aaw_function.'\(.*?^\}/ms',$aaw_controller,$aaw_match)===1,'Actual ACP input helper');eval($aaw_match[0]);}
function showResultMessage($message){throw new RuntimeException($message);}
function aaw_controller($action)
{
 global $aaw_controller,$lang,$userdata;
 $indent=$action==='new'?"\t\t\t":"\t\t";
 if($action==='order'){$branch=strpos($aaw_controller,"else if( \$get_action == 'move' )");$begin=strpos($aaw_controller,'$cat_id =',$branch);$end=strpos($aaw_controller,'// Return a message...',$begin);}
 else{$begin=strpos($aaw_controller,"\n".$indent.'// Get posting variables');$end=strpos($aaw_controller,"\n".$indent.'// Return a message...',$begin);}
 ala_check($begin!==false&&$end>$begin,'Actual complete ACP '.$action.' POST handler');eval(substr($aaw_controller,$begin,$end-$begin));
}
function aaw_values(){return array('cat_title'=>"O'Connor \\ Größe 😀 <category>",'cat_desc'=>'Description & original 😀','cat_view_level'=>ALBUM_GUEST,'cat_upload_level'=>ALBUM_USER,'cat_rate_level'=>ALBUM_PRIVATE,'cat_comment_level'=>ALBUM_MOD,'cat_edit_level'=>ALBUM_USER,'cat_delete_level'=>ALBUM_ADMIN,'cat_approval'=>ALBUM_MOD);}
function aaw_snapshot(){return array(ala_rows('SELECT * FROM fixture_album_cat ORDER BY cat_id'),ala_rows('SELECT * FROM fixture_album ORDER BY pic_id'),ala_rows('SELECT * FROM fixture_album_file_jobs ORDER BY operation_id'),ala_rows('SELECT * FROM fixture_album_file_items ORDER BY operation_id,item_index'));}
function aaw_change($action,$category=null,$parent=0,$values=null,$move=0){$before=ala_globals();try{return(new AlbumAdminCategoryStorage($GLOBALS['db'],'fixture_'))->change($GLOBALS['userdata'],$action,$category,$parent,$values===null?aaw_values():$values,$move);}finally{ala_check(ala_globals()===$before,'Public category writer preserves exact request globals and connection');}}
function aaw_denied($action,$category=null,$parent=0,$values=null,$move=0){$before=aaw_snapshot();$denied=false;try{aaw_change($action,$category,$parent,$values,$move);}catch(AlbumFileCoordinatorFailure$e){$denied=true;}ala_check($denied&&aaw_snapshot()===$before,'Rejected category write preserves all hierarchy/pictures/journals');$GLOBALS['cases']++;}
function aaw_cases()
{
 global $db,$control,$userdata,$cases,$lang;$start=$cases;$baselines=array();
 foreach(array(false,true)as$root){
  ala_admin_reset($root);$private=ala_rows('SELECT * FROM fixture_album_cat WHERE cat_user_id<>0 ORDER BY cat_id');
  $id=aaw_change('new',null,1);$row=ala_rows('SELECT * FROM fixture_album_cat WHERE cat_id='.$id);ala_check(count($row)===1&&$row[0]['cat_title']===aaw_values()['cat_title']&&(int)$row[0]['cat_parent']===1&&(int)$row[0]['cat_user_id']===0&&ala_rows('SELECT * FROM fixture_album_cat WHERE cat_user_id<>0 ORDER BY cat_id')===$private,'Root/delegated create preserves Unicode, private gallery and exact public parent');$baselines[]=array($root,'new',null,1,$db->queries);$cases++;
  ala_admin_reset($root);$values=aaw_values();$id=aaw_change('edit',3,1,$values);$row=ala_rows('SELECT * FROM fixture_album_cat WHERE cat_id=3');ala_check($id===3&&$row[0]['cat_title']===$values['cat_title']&&(int)$row[0]['cat_parent']===1,'Root/delegated edit applies current public parent/text/permissions');$baselines[]=array($root,'edit',3,1,$db->queries);$cases++;
  $before=aaw_snapshot();aaw_change('edit',3,1,$values);ala_check(aaw_snapshot()===$before,'Unchanged edit is a successful no-op');$cases++;
  ala_admin_reset($root);$id=aaw_change('order',1,0,null,15);$ordered=ala_rows('SELECT cat_id FROM fixture_album_cat WHERE cat_user_id=0 AND cat_parent=0 ORDER BY cat_order,cat_id');ala_check($id===1&&array_map('intval',array_column($ordered,'cat_id'))===array(3,1,4)&&ala_rows('SELECT * FROM fixture_album_cat WHERE cat_user_id<>0 ORDER BY cat_id')===$private,'Root/delegated order moves adjacent public sibling only');$baselines[]=array($root,'order',1,0,$db->queries);$cases++;
  $before=aaw_snapshot();aaw_change('order',3,0,null,-15);ala_check(aaw_snapshot()===$before,'Boundary order is an unchanged success');$cases++;
 }
 foreach(array('new','edit')as$action){foreach(array(false,true)as$root){
  ala_admin_reset($root);$_POST=array();foreach(aaw_values()as$key=>$value){$_POST[$key]=is_string($value)?addslashes($value):(string)$value;}$_POST['cat_parent_id']='1';$_POST['cat_id']='3';
  $old_globals=ala_globals();$old_globals[3]=array();aaw_controller($action);$rows=ala_rows("SELECT * FROM fixture_album_cat WHERE HEX(cat_title)='".strtoupper(bin2hex(aaw_values()['cat_title']))."'");ala_check(count($rows)===1&&(int)$rows[0]['cat_parent']===1&&ala_globals()===$old_globals,'Actual ACP POST handler reaches current coordinated writer with exact Unicode/text/levels and invalidates only the old tree cache');$cases++;
  ala_admin_reset($root);ala_sql('UPDATE fixture_sessions SET session_admin=0');$before=aaw_snapshot();$lang['Album_category_save_failed']='controlled-category-error';$denied=false;try{aaw_controller($action);}catch(RuntimeException$e){$denied=$e->getMessage()==='controlled-category-error';}ala_check($denied&&aaw_snapshot()===$before,'Actual ACP POST handler returns controlled error after current admin-session revocation');$cases++;
 }}
 foreach(array(false,true)as$root){
  ala_admin_reset($root);$_GET=array('cat_id'=>'1','move'=>'15','album_token'=>hash_hmac('sha256','album-category-order:1:15',$userdata['session_id']));aaw_controller('order');$ordered=ala_rows('SELECT cat_id FROM fixture_album_cat WHERE cat_user_id=0 ORDER BY cat_order,cat_id');ala_check(array_map('intval',array_column($ordered,'cat_id'))===array(3,1,4),'Actual ACP move handler preserves token binding and delegates coordinated sibling order');$cases++;
  ala_admin_reset($root);$lang['Not_Authorised']='invalid-order-token';$_GET['album_token']=str_repeat('0',64);$before=aaw_snapshot();$denied=false;try{aaw_controller('order');}catch(RuntimeException$e){$denied=$e->getMessage()==='invalid-order-token';}ala_check($denied&&aaw_snapshot()===$before,'Actual ACP move handler refuses altered token without a write');$cases++;
 }
 ala_admin_reset();ala_sql('DELETE FROM fixture_album_cat WHERE cat_user_id=0');$id=aaw_change('new');ala_check($id>0&&(int)ala_rows('SELECT COUNT(*) n FROM fixture_album_cat WHERE cat_user_id=0')[0]['n']===1,'First public category needs exact category module, no existing public row');$cases++;
 foreach(array('new','edit','order')as$action){foreach(array(false,true)as$root){
  foreach(array('UPDATE fixture_sessions SET session_admin=0','UPDATE fixture_users SET user_active=0 WHERE user_id=7','UPDATE fixture_sessions SET session_time=1','DELETE FROM fixture_sessions')as$revoke){ala_admin_reset($root);ala_sql($revoke);aaw_denied($action,$action==='new'?null:1,0,null,15);}
 }}
 foreach(array('new','edit','order')as$action){ala_admin_reset();ala_sql('UPDATE fixture_jr_admin_users SET user_jr_admin='.ala_literal(md5('Photo_AlbumClear_Cacheadmin_album_clearcache.php')));aaw_denied($action,$action==='new'?null:1,0,null,15);ala_admin_reset(true);ala_sql('UPDATE fixture_users SET user_level=0 WHERE user_id=7');aaw_denied($action,$action==='new'?null:1,0,null,15);}
 ala_admin_reset();aaw_denied('new',null,2);aaw_denied('edit',1,2);aaw_denied('edit',2);aaw_denied('order',2,0,null,15);
 ala_admin_reset();ala_sql('UPDATE fixture_album_cat SET cat_parent=1 WHERE cat_id=3');ala_sql('UPDATE fixture_album_cat SET cat_parent=3 WHERE cat_id=4');aaw_denied('edit',1,4);aaw_denied('edit',1,1);
 ala_admin_reset();ala_sql('UPDATE fixture_album_cat SET cat_parent=4 WHERE cat_id=3');ala_sql('UPDATE fixture_album_cat SET cat_parent=3 WHERE cat_id=4');aaw_denied('new',null,3);aaw_denied('order',3,0,null,15);
 foreach(array(array('cat_title',''),array('cat_title',str_repeat('😀',256)),array('cat_title',"bad\xff"),array('cat_desc',str_repeat('x',65536)),array('cat_edit_level',ALBUM_GUEST),array('cat_approval',99))as$invalid){ala_admin_reset();$values=aaw_values();$values[$invalid[0]]=$invalid[1];aaw_denied('new',null,0,$values);}
 ala_admin_reset();aaw_denied('new',null,999);aaw_denied('edit',999);aaw_denied('order',1,0,null,1);
 // Reproduce native failures from the first locked hierarchy read onward;
 // shared authority/connection failures have their own exhaustive fixture.
 foreach($baselines as$baseline){$in_write=false;foreach($baseline[4]as$index=>$sql){
  if(strpos($sql,'SELECT * FROM fixture_album_cat WHERE cat_user_id=0 ORDER BY cat_id FOR UPDATE')===0){$in_write=true;}
  if(!$in_write||$sql==='ROLLBACK'){continue;}
  ala_admin_reset($baseline[0]);$db->reset_case($index+1);aaw_denied($baseline[1],$baseline[2],$baseline[3],null,$baseline[1]==='order'?15:0);
 }}
 echo 'Native public Album category writes: ',($cases-$start)," root/delegated/current-rights/Unicode/first-category/ancestry/sibling/no-op/SQL-failure cases PASS; own disposable schema only.\n";
}
