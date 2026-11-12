<?php
if(!defined('ANONYMOUS')){define('ANONYMOUS',-1);}
// Exercise the actual full-editor expressions with the public viewer policy.
// A private moderation policy uses the same hook, without public account IDs.
$fae_source=file_get_contents($root.'posting.php');
$fae_writer=file_get_contents($root.'includes/functions_full_editor_storage.php');
function fae_expression($source,$pattern)
{
    if(preg_match($pattern,$source,$match)!==1){throw new RuntimeException('Actual former-author editor expression missing');}
    return $match[0];
}
function fae_check($value){if(!$value){throw new RuntimeException('Former-author editor privacy regression');}$GLOBALS['fae_assertions']++;}
class FormerEditorTemplate {public $blocks=array();function assign_block_vars($name,$values){$this->blocks[]=$name;}}
$fae_scope=fae_expression($fae_source,'/^\s*\$author_edit_locked = .*;$/m');
$fae_initial=fae_expression($fae_source,'/^\s*\$username = \$author_edit_locked \? phpbb_post_author_name\(\$post_info\) : \(\(.*;$/m');
$fae_retry=fae_expression($fae_source,'/^\s*\$username = \$author_edit_locked \? phpbb_post_author_name\(\$post_info\) : htmlspecialchars.*;$/m');
$fae_preview=fae_expression($fae_source,'/^\s*\$preview_username = \$username;$/m');
$fae_submit=fae_expression($fae_source,'/^\s*\$username = \$author_edit_locked \? \'\' : \$request_scalar.*;$/m');
$fae_control=fae_expression($fae_source,'/^if\( !\$author_edit_locked .*?^\}/ms');
$fae_save=fae_expression($fae_writer,'/^\s*\$post_username = phpbb_post_author_edit_locked.*;$/m');
$fae_saved=array();foreach(array('board_config','userdata','lang','template','_POST')as$key){$fae_saved[$key]=array(array_key_exists($key,$GLOBALS),isset($GLOBALS[$key])?$GLOBALS[$key]:null);}
$fae_assertions=0;$fae_cases=0;
try{
 $lang=array('Former_member'=>'Ehemaliger Nutzer','Guest'=>'Gast');$board_config=array('hide_removed_author_names'=>'1');
 $request_scalar=function($source,$key){return isset($source[$key])&&is_scalar($source[$key])?(string)$source[$key]:'';};
 foreach(array(false,true)as$logged){foreach(array(-1,8)as$poster){foreach(array('0','1')as$removed){foreach(array('editpost','reply')as$mode){
  $userdata=array('session_logged_in'=>$logged);$post_info=array('user_id'=>$poster,'poster_id'=>$poster,'post_author_removed'=>$removed,'post_username'=>phpbb_post_author_key('Original Größe & O\'Reilly 😀'));
  $expected=$mode==='editpost'&&!$logged&&$poster<0&&$removed==='1';eval($fae_scope);fae_check($author_edit_locked===$expected);
  eval($fae_initial);$initial=$username;fae_check($initial===($expected?$lang['Former_member']:($poster<0?$post_info['post_username']:'')));
  foreach(array('Forged replacement',array('invalid'),'')as$forged){
   $_POST=array('username'=>$forged);eval($fae_retry);if($expected){fae_check($username===$lang['Former_member']);eval($fae_preview);fae_check($preview_username===$lang['Former_member']);}
   eval($fae_submit);fae_check($username===($expected?'':$request_scalar($_POST,'username')));
   $post=$post_info;$input=array('username'=>$forged);eval($fae_save);
   $storage_locked=!$logged&&$poster<0&&$removed==='1';fae_check($post_username===($storage_locked?$post_info['post_username']:phpbb_post_author_stored($forged)));
  }
  $template=new FormerEditorTemplate();eval($fae_control);fae_check(in_array('switch_username_select',$template->blocks,true)===(!$expected&&(!$logged||($mode==='editpost'&&$poster<0))));
  $fae_cases++;
 }}}}
 // Both the initial form and a failed save need current classification fields.
 $select=fae_expression($fae_source,'/^\s*\$select_sql = .*p\.post_author_removed\x27;\s*$/m');
 $select.=fae_expression($fae_source,'/^\s*\$select_sql \.= \(!\$submit\).*;$/m');
 foreach(array(false,true)as$submit){eval($select);fae_check(substr_count($select_sql,'p.post_username')===1&&substr_count($select_sql,'p.post_author_removed')===1);}
}finally{foreach($fae_saved as$key=>$state){if($state[0]){$GLOBALS[$key]=$state[1];}else{unset($GLOBALS[$key]);}}}
echo 'Former-author editor: ',$fae_cases,' actual controller/form/write-expression cases, ',$fae_assertions," assertions PASS; initial/retry/preview data, concealed control, retained alias and normal guest/member behavior.\n";
