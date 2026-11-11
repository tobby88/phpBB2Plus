<?php
require __DIR__.'/arcade-config-fixture.php';
require_once $as_app.'includes/functions_arcade_bulk_storage.php';
function ab_controller($mode='game_charge',$value='5',$view=false,$post=null){
 global $as_app,$db,$arcade,$userdata,$HTTP_POST_VARS,$HTTP_GET_VARS,$lang,$phpEx,$phpbb_root_path,$board_config;
 $template=new ArcadeConfigTemplate();$file='admin_arcade_set.php';$version=ARCADE_VERSION;profile_fixture_request($post===null?array('sid'=>'fixture-import',$mode=>$value):$post);$_SERVER['REQUEST_METHOD']=$view?'GET':'POST';$_GET=$HTTP_GET_VARS=array('mode'=>$mode);if($view){$_POST=$HTTP_POST_VARS=array();}
 $s=file_get_contents($as_app.'admin/admin_arcade_set.php');$a=strpos($s,'$allowed_fields =');as_check($a!==false,'Actual complete bulk controller and form bindings');
 try{eval(substr($s,$a));throw new RuntimeException('Actual bulk controller must render or report');}catch(ArcadeSummaryExit$e){return array('body'=>$e->getMessage(),'template'=>$template);}catch(ArcadeConfigViewExit$e){return array('body'=>'view','template'=>$template);}
}
function ab_call($mode='game_charge',$value='5'){
 global $db;profile_fixture_request(array('sid'=>'fixture-import',$mode=>$value));$_SERVER['REQUEST_METHOD']='POST';
 try{return array('error'=>false,'value'=>phpbb_arcade_bulk_save($db,$mode,$GLOBALS['HTTP_POST_VARS']));}catch(PhpbbOwnedStorageFailure$e){return array('error'=>true,'key'=>$e->getMessage());}catch(PhpbbAclException$e){return array('error'=>true,'key'=>$e->getMessage());}
}
