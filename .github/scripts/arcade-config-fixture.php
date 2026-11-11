<?php
require __DIR__.'/arcade-tournament-manage-fixture.php';
require_once $as_app.'includes/functions_arcade_config_storage.php';
foreach(array('phpbb_admin_html','phpbb_admin_session_field')as$name){as_check(preg_match('/^\tfunction '.$name.'\([^\r\n]*\)\r?\n\t\{.*?^\t\}/ms',file_get_contents($as_app.'admin/pagestart.php'),$m)===1,'Actual config form helper');eval($m[0]);}
class ArcadeConfigViewExit extends RuntimeException {}
class ArcadeConfigTemplate {var $vars=array();var $blocks=array();var $files=array();function set_filenames($v){$this->files=$v;}function assign_vars($v){$this->vars=$v;}function assign_block_vars($key,$v){$this->blocks[$key]=$v;}function pparse($name){throw new ArcadeConfigViewExit();}}
function ac_controller($mode='',$post=array(),$view=false){
 global $as_app,$db,$arcade,$userdata,$HTTP_POST_VARS,$HTTP_GET_VARS,$lang,$phpEx,$phpbb_root_path,$board_config,$mode_types,$mode_types_text;
 $template=new ArcadeConfigTemplate();$file='admin_arcade.php';$version=ARCADE_VERSION;$new_mode=$mode;$reset_navbar='';$submit=!$view;
 profile_fixture_request(array_replace(array('sid'=>'fixture-import','submit'=>'1','new_mode'=>$mode),$post));if($submit){$_SERVER['REQUEST_METHOD']='POST';phpbb_admin_require_post_session();}else{$_SERVER['REQUEST_METHOD']='GET';$HTTP_POST_VARS=$_POST=array();}
 $s=file_get_contents($as_app.'admin/admin_arcade.php');$a=strpos($s,"if ( !\$mode || \$mode == 'switches'");as_check($a!==false,'Actual complete config controller');$body=substr($s,$a);$body=str_replace("require_once dirname(__DIR__).'/includes/functions_arcade_config_storage.php';","require_once \$as_app.'includes/functions_arcade_config_storage.php';",$body);
 try{eval($body);throw new RuntimeException('Actual config must report or render a result');}catch(ArcadeSummaryExit$e){return array('body'=>$e->getMessage(),'template'=>$template);}catch(ArcadeConfigViewExit$e){return array('body'=>'view','template'=>$template);}
}
function ac_call($mode='',$values=array()){
 global $db;profile_fixture_request(array_replace(array('sid'=>'fixture-import','submit'=>'1','new_mode'=>$mode),$values));$_SERVER['REQUEST_METHOD']='POST';
 try{return array('error'=>false,'result'=>phpbb_arcade_config_save($db,$GLOBALS['HTTP_POST_VARS']));}catch(PhpbbOwnedStorageFailure$e){return array('error'=>true,'key'=>$e->getMessage());}catch(PhpbbAclException$e){return array('error'=>true,'key'=>$e->getMessage());}
}
