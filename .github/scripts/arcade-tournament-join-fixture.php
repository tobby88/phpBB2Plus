<?php
require __DIR__.'/arcade-submission-fixture.php';
require_once $as_app.'includes/functions_arcade_tournament_storage.php';
as_function($as_app.'arcade_tournament.php','arcade_public_tournament_require_token');
function tj_controller($ids=array('9'),$extra=array())
{
    global $as_app,$db,$arcade,$userdata,$HTTP_POST_VARS,$HTTP_GET_VARS,$lang,$phpEx;
    profile_fixture_request(array_replace(array('sid'=>'fixture-import','join'=>'1','join_tour'=>$ids),$extra));$join_tour=$ids;
    $source=file_get_contents($as_app.'arcade_tournament.php');$a=strpos($source,"if(!empty(\$HTTP_POST_VARS['join']))");$b=strpos($source,"else if(!empty(\$HTTP_POST_VARS['submit']))",$a);
    as_check($a!==false&&$b>$a,'Actual complete public join handler');
    $body=substr($source,$a,$b-$a);
    $body=str_replace("require_once dirname(__FILE__).'/includes/functions_arcade_tournament_storage.php';", "require_once \$as_app.'includes/functions_arcade_tournament_storage.php';", $body);
    try{eval($body);throw new RuntimeException('Actual join handler did not report result');}catch(ArcadeSummaryExit$e){return $e->getMessage();}
}
function tj_call($ids=array('9'))
{
    try{return array('error'=>false,'results'=>phpbb_arcade_tournament_join($GLOBALS['db'],$ids));}
    catch(PhpbbOwnedStorageFailure$e){return array('error'=>true,'key'=>$e->getMessage());}
}
function tj_status($r,$status){return !$r['error']&&count($r['results'])===1&&$r['results'][0]['status']===$status;}
