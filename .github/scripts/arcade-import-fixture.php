<?php
require __DIR__.'/arcade-summary-fixture.php';
require_once $as_app.'attach_mod/includes/constants.php';
require __DIR__.'/profile-request-fixture.php';
if (!defined('ARCADE_IMPORT_LAZY_BOOTSTRAP')) { require_once $as_app.'includes/functions_arcade_import_storage.php'; }
require_once $as_app.'includes/functions_jr_admin.php';
as_function($as_app.'admin/admin_arcade_games.php','arcade_admin_post_string');
function aif_values($name='fixture_game',$category=7,$online=1,$description='Grüße 😀')
{ return phpbb_arcade_import_values($name,'games/',0,$description,1,$online,550,450,$category); }
function aif_request()
{
 global $userdata;$_SERVER['REQUEST_METHOD']='POST';$userdata=array('user_id'=>2,'session_id'=>'fixture-import','session_logged_in'=>true,'session_admin'=>1,'user_level'=>ADMIN);
 profile_fixture_request(array('sid'=>'fixture-import','import_submit'=>'1'));
}
function aif_routes()
{
 $routes=jr_admin_authorization_routes();as_check(is_array($routes),'Actual ACP declaration routes');$out=array();
 foreach(array('import_game','add_game','edit_games')as$mode){$keys=array_keys($routes,'admin_arcade_games.php?mode='.$mode,true);as_check(count($keys)===1,'Exact actual Arcade module route '.$mode);$out[$mode]=$keys[0];}return $out;
}
function aif_call($name='fixture_game',$category=7,$online=1,$description='Grüße 😀')
{
 try{return array('id'=>insert_ina_game($name,'games/',0,$description,1,$online,550,450,$category),'error'=>false);}
 catch(ArcadeSummaryExit$e){return array('id'=>false,'error'=>true,'message'=>$e->getMessage());}
}
