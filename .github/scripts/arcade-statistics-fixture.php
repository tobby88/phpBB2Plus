<?php
require_once __DIR__.'/arcade-action-fixture.php';
require_once $as_app.'includes/functions_arcade_statistics_storage.php';

function ast_controller($request, $method='POST', $query=array())
{
    global $as_app,$db,$lang,$HTTP_POST_VARS,$HTTP_GET_VARS,$userdata,$phpEx;
    profile_fixture_request($method==='POST'?$request:array());
    $_SERVER['REQUEST_METHOD']=$method;
    $_GET=phpbb_addslashes_recursive($method==='POST'?$query:$request);$HTTP_GET_VARS=$_GET;
    $_REQUEST=array_merge($_GET,$_POST);
    $source=file_get_contents($as_app.'admin/admin_arcade_reset.php');
    $start=strpos($source,"try\n{");$end=strpos($source,"include('page_footer_admin.'",$start);
    as_check($start!==false&&$end>$start,'Actual complete statistics controller and form rendering');
    $body=substr($source,$start,$end-$start);ob_start();
    try { eval($body);return array('type'=>'page','html'=>ob_get_contents()); }
    catch(ArcadeSummaryExit $error){return array('type'=>'message','text'=>$error->getMessage());}
    finally { ob_end_clean(); }
}

function ast_call($mode)
{
    try { return array('error'=>false,'result'=>phpbb_arcade_statistics_reset($GLOBALS['db'],$mode)); }
    catch(PhpbbOwnedStorageFailure $error){return array('error'=>true,'key'=>$error->getMessage());}
    catch(PhpbbAclException $error){return array('error'=>true,'key'=>$error->getMessage());}
}
