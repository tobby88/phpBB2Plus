<?php
require __DIR__.'/arcade-config-fixture.php';
require_once $as_app.'includes/functions_arcade_log_storage.php';
foreach(array('create_date','generate_pagination')as$name){if(!function_exists($name)){as_function($as_app.'includes/functions.php',$name);}}
class ArcadeLogTemplate extends ArcadeConfigTemplate {function assign_block_vars($key,$value){$this->blocks[$key][]=$value;}}
function al_controller($post=array(),$view=false,$start=0){
 global $as_app,$db,$arcade,$userdata,$HTTP_POST_VARS,$HTTP_GET_VARS,$lang,$phpEx,$phpbb_root_path,$board_config;
 $template=new ArcadeLogTemplate();$file='admin_arcade_log.php';$version=ARCADE_VERSION;$board_config['default_dateformat']='Y-m-d H:i';$board_config['board_timezone']=0;
 profile_fixture_request($view?$post:array_replace(array('sid'=>'fixture-import'),$post));$_SERVER['REQUEST_METHOD']=$view?'GET':'POST';if($view){$_GET=$HTTP_GET_VARS=$post;$_POST=$HTTP_POST_VARS=array();}$_GET['start']=$HTTP_GET_VARS['start']=$start;
 $s=file_get_contents($as_app.'admin/admin_arcade_log.php');$a=strpos($s,'$start        =');as_check($a!==false,'Actual complete log controller including list and bindings');$body=str_replace("require_once dirname(__DIR__).'/includes/functions_arcade_log_storage.php';","require_once \$as_app.'includes/functions_arcade_log_storage.php';",substr($s,$a));
 try{eval($body);throw new RuntimeException('Actual log controller must render or report');}catch(ArcadeSummaryExit$e){return array('body'=>$e->getMessage(),'template'=>$template);}catch(ArcadeConfigViewExit$e){return array('body'=>'view','template'=>$template);}
}
function al_call($post){
 global $db;profile_fixture_request(array_replace(array('sid'=>'fixture-import'),$post));$_SERVER['REQUEST_METHOD']='POST';
 try{return array('error'=>false,'removed'=>phpbb_arcade_log_remove($db,$GLOBALS['HTTP_POST_VARS']));}catch(PhpbbOwnedStorageFailure$e){return array('error'=>true,'key'=>$e->getMessage());}catch(PhpbbAclException$e){return array('error'=>true,'key'=>$e->getMessage());}
}
