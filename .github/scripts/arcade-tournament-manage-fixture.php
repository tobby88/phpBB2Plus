<?php
require __DIR__.'/arcade-tournament-join-fixture.php';
require_once $as_app.'includes/functions_arcade_tournament_manage.php';
foreach(array('phpbb_admin_post_session_valid','phpbb_admin_require_post_session')as$name){
    as_check(preg_match('/^\tfunction '.$name.'\([^\r\n]*\)\r?\n\t\{.*?^\t\}/ms',file_get_contents($as_app.'admin/pagestart.php'),$m)===1,'Actual ACP token helper');eval($m[0]);
}
function tm_values($name="Grüße 😀 &amp; '") {return array('name'=>$name,'desc'=>"Description 😀 &amp; \\ '",'players'=>2,'turns'=>3,'block'=>0,'active'=>0);}
function tm_call($operation='save',$id=0,$values=null,$admin=false,$revision=''){
    if($values===null){$values=tm_values();}
    try{return array('error'=>false,'result'=>phpbb_arcade_tournament_manage($GLOBALS['db'],$operation,$id,$values,$admin,$revision));}
    catch(PhpbbOwnedStorageFailure$e){return array('error'=>true,'key'=>$e->getMessage());}catch(PhpbbAclException$e){return array('error'=>true,'key'=>$e->getMessage());}
}
function tm_controller($admin=false,$post=array()){
    global $as_app,$db,$arcade,$userdata,$HTTP_POST_VARS,$HTTP_GET_VARS,$lang,$phpEx;
    $file='admin_arcade_tournaments.php';$tour_id=0;
    profile_fixture_request(array_replace(array('sid'=>'fixture-import','tour_name'=>"Grüße 😀 &amp; '",'tour_desc'=>"Description 😀 &amp; \\ '",'tour_max_players'=>'2','tour_player_turns'=>'3','block_plays'=>'0'),array(($admin?'add_tour_submit':'submit')=>'1'),$post));
    $source=file_get_contents($as_app.($admin?'admin/':'').($admin?$file:'arcade_tournament.php'));
    $a=strpos($source,$admin?"if(isset(\$HTTP_POST_VARS['tour_submit']))":"else if(!empty(\$HTTP_POST_VARS['submit']))");
    $b=strpos($source,$admin?"else if(isset(\$HTTP_POST_VARS['add_tour']))":"else if(!empty(\$HTTP_POST_VARS['start']))",$a);
    as_check($a!==false&&$b>$a,'Actual complete create/settings/edit handlers');$body=substr($source,$a+($admin?0:5),$b-$a-($admin?0:5));
    $body=str_replace("require_once dirname(__FILE__).'/includes/functions_arcade_tournament_manage.php';","require_once \$as_app.'includes/functions_arcade_tournament_manage.php';",$body);
    try{eval($body);throw new RuntimeException('Actual manage handler did not report a result');}catch(ArcadeSummaryExit$e){return $e->getMessage();}
}
