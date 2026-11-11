<?php
require __DIR__.'/arcade-tournament-join-fixture.php';
class TournamentNoDatabase {var $calls=0;function sql_dedicated_connection(){$this->calls++;throw new RuntimeException('Invalid tournament input opened SQL');}}
$db=new TournamentNoDatabase();$cases=0;$userdata=array('user_id'=>2,'session_id'=>'fixture-import','session_logged_in'=>true);
as_check(phpbb_arcade_tournament_join_ids(array('9',2,9,'8388607'))===array(9,2,8388607),'Bounded typed IDs, stable deduplication');$cases++;
foreach(array(null,false,'9',array('0'),array(0),array('-1'),array('09'),array('9tail'),array('8388608'),array(array()),array(true),array(1.0),array_fill(0,101,'9'))as$ids){
    profile_fixture_request(array('sid'=>'fixture-import'));$_SERVER['REQUEST_METHOD']='POST';$_SERVER['HTTP_SEC_FETCH_SITE']='same-origin';$r=tj_call($ids);as_check($r['error']&&$db->calls===0,'Invalid tournament list rejected before opening SQL');$cases++;
}
foreach(array('get','missing-sid','nested-sid','wrong-sid','guest','bad-id','inactive-view','empty-session','long-session','cross-site')as$bad){
    $userdata=array('user_id'=>2,'session_id'=>'fixture-import','session_logged_in'=>true);profile_fixture_request(array('sid'=>'fixture-import'));$_SERVER['REQUEST_METHOD']='POST';$_SERVER['HTTP_SEC_FETCH_SITE']='same-origin';
    if($bad==='get'){$_SERVER['REQUEST_METHOD']='GET';}elseif($bad==='missing-sid'){unset($HTTP_POST_VARS['sid']);}elseif($bad==='nested-sid'){$HTTP_POST_VARS['sid']=array();}elseif($bad==='wrong-sid'){$HTTP_POST_VARS['sid']='wrong';}elseif($bad==='guest'){$userdata['user_id']=ANONYMOUS;}elseif($bad==='bad-id'){$userdata['user_id']='2tail';}elseif($bad==='inactive-view'){$userdata['session_logged_in']=false;}elseif($bad==='empty-session'){$userdata['session_id']='';}elseif($bad==='long-session'){$userdata['session_id']=str_repeat('s',33);}else{$_SERVER['HTTP_SEC_FETCH_SITE']='cross-site';}
    as_check(tj_call()['error']&&$db->calls===0,'Invalid actor/request never opens SQL '.$bad);$cases++;
}
echo 'Arcade tournament join policy: '.$cases.' cases, '.$as_checks." assertions PASS.\n";
