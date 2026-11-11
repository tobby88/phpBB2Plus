<?php
require __DIR__.'/arcade-config-fixture.php';
require_once $as_app.'includes/functions_arcade_category_storage.php';
class ArcadeCategoryTemplate extends ArcadeConfigTemplate {function assign_vars($vars){$this->vars=array_merge($this->vars,$vars);}function assign_block_vars($key,$value){$this->blocks[$key][]=$value;}}
function acy_controller($post=array(),$view=false,$mode='',$id=0){
 global $as_app,$db,$arcade,$userdata,$HTTP_POST_VARS,$HTTP_GET_VARS,$lang,$phpEx,$phpbb_root_path,$board_config;
 $template=new ArcadeCategoryTemplate();$file='admin_arcade_cats.php';$version=ARCADE_VERSION;$images=array('icon_delpost'=>'images/delete.gif','icon_edit'=>'images/edit.gif');
 profile_fixture_request($view?array():array_replace(array('sid'=>'fixture-import'),$post));$_SERVER['REQUEST_METHOD']=$view?'GET':'POST';$_GET=$HTTP_GET_VARS=array('mode'=>$mode,'cat_id'=>(string)$id);
 $s=file_get_contents($as_app.'admin/admin_arcade_cats.php');$a=strpos($s,"require_once dirname(__DIR__).'/includes/functions_arcade_category_storage.php';");as_check($a!==false,'Actual complete category controller/view');$body=str_replace("require_once dirname(__DIR__).'/includes/functions_arcade_category_storage.php';","require_once \$as_app.'includes/functions_arcade_category_storage.php';",substr($s,$a));
 try{eval($body);throw new RuntimeException('Actual category controller must render or report');}catch(ArcadeSummaryExit$e){return array('body'=>$e->getMessage(),'template'=>$template);}catch(ArcadeConfigViewExit$e){return array('body'=>'view','template'=>$template);}
}
function acy_call($post){
 global $db;profile_fixture_request(array_replace(array('sid'=>'fixture-import'),$post));$_SERVER['REQUEST_METHOD']='POST';
 try{return array('error'=>false,'result'=>phpbb_arcade_category_change($db,$GLOBALS['HTTP_POST_VARS']));}catch(PhpbbOwnedStorageFailure$e){return array('error'=>true,'key'=>$e->getMessage());}catch(PhpbbAclException$e){return array('error'=>true,'key'=>$e->getMessage());}
}
function acy_shape($id,$type='p',$parent=0,$order=0){return array('cat_id'=>(string)$id,'cat_name'=>'Category '.$id,'cat_desc'=>'','cat_icon'=>'','special_play'=>'0','group_required'=>'0','mod_id'=>'0','cat_type'=>$type,'cat_parent'=>(string)$parent,'cat_order'=>(string)$order,'total_games'=>'0','total_played'=>'0','last_game'=>null,'last_player'=>null,'last_time'=>null);}
function acy_values($id=0,$overrides=array()){
 $row=$id?su_rows('SELECT * FROM fixture_ina_cat WHERE cat_id='.$id)[0]:acy_shape(0);return array_replace(array('save_cat'=>'1','cat_id'=>(string)$id,'cat_revision'=>$id?phpbb_arcade_category_revision($row):'','cat_order'=>(string)$row['cat_order'],'tree_revision'=>phpbb_arcade_category_tree_revision(su_rows('SELECT * FROM fixture_ina_cat')),'name'=>$row['cat_name'],'desc'=>$row['cat_desc']===null?'':$row['cat_desc'],'icon'=>$row['cat_icon']===null?'':$row['cat_icon'],'cat_type'=>$row['cat_type'],'cat_parent'=>(string)(int)$row['cat_parent'],'group_required'=>(string)$row['group_required'],'moderator'=>'','special'=>(string)$row['special_play']),$overrides);
}
function acy_action($operation='cat_down',$id=7){$rows=su_rows('SELECT * FROM fixture_ina_cat ORDER BY cat_id');$post=array('tree_revision'=>phpbb_arcade_category_tree_revision($rows));if($operation==='resync'){$post['resync_cats']='Resync';}else{$row=su_rows('SELECT * FROM fixture_ina_cat WHERE cat_id='.$id)[0];$post['cat_action']=$operation.':'.$id.':'.phpbb_arcade_category_revision($row);}return $post;}