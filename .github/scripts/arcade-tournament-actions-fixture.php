<?php
require __DIR__.'/arcade-tournament-manage-fixture.php';
require_once $as_app.'includes/functions_arcade_tournament_actions.php';
as_function($as_app.'arcade_tournament.php','arcade_tournament_player_html');
class TournamentPreviewExit extends RuntimeException {}
class TournamentPreviewTemplate {var $vars=array();function set_filenames($v){}function assign_vars($v){$this->vars=$v;}function pparse($name){throw new TournamentPreviewExit();}}
$ta_messages=array();$ta_delivery_fail=false;
function ta_capture_pm($recipient,$subject,$message,$actor,$quiet,$optional){if(isset($GLOBALS['ta_commit_probe'])){call_user_func($GLOBALS['ta_commit_probe']);}$GLOBALS['ta_messages'][]=func_get_args();if($GLOBALS['ta_delivery_fail']){throw new RuntimeException('Own delivery failure');}return true;}
function ta_call($op='end',$id=9,$tokens=array(),$revision=null){if($revision===null){$revision=$op==='preview-end'?'':tm_revision($id);}try{return array('error'=>false,'result'=>phpbb_arcade_tournament_action($GLOBALS['db'],$op,$id,$tokens,$revision));}catch(PhpbbOwnedStorageFailure$e){return array('error'=>true,'key'=>$e->getMessage());}catch(PhpbbAclException$e){return array('error'=>true,'key'=>$e->getMessage());}}
function ta_controller($action,$post=array()){
 global $as_app,$db,$arcade,$userdata,$HTTP_POST_VARS,$HTTP_GET_VARS,$lang,$phpEx,$phpbb_root_path;
 $tour_id=9;$filename='arcade_tournament.php';$file='admin_arcade_tournaments.php';$mode='';$template=new TournamentPreviewTemplate();$revision=tm_revision(9);
 $defaults=array('sid'=>'fixture-import','id'=>'9','tour_revision'=>$revision);
 if($action==='end'||$action==='preview'){$defaults+=array('end'=>'1');if($action==='end'){$defaults['confirm']='1';}}
 elseif($action==='delete'){$defaults['delete_tour']='9:'.$revision;}else{$defaults+=array('add_game'=>'1','game_id'=>array('42:'.phpbb_arcade_game_revision(tm_game(42))));}
 profile_fixture_request(array_replace($defaults,$post));$s=file_get_contents($as_app.(in_array($action,array('end','preview'),true)?$filename:'admin/'.$file));
 if($action==='end'||$action==='preview'){$a=strpos($s,"else if(!empty(\$HTTP_POST_VARS['end']))");$b=strpos($s,"else if (\$mode == 'tour')",$a);$body=substr($s,$a+5,$b-$a-5);as_check(substr_count($body,'ina_send_user_pm(')===1,'Only actual post-commit delivery endpoint instrumented');$body=str_replace('ina_send_user_pm(','ta_capture_pm(',$body);$body=str_replace("require_once dirname(__FILE__).'/includes/functions_arcade_tournament_actions.php';","require_once \$as_app.'includes/functions_arcade_tournament_actions.php';",$body);$body=str_replace("include(\$phpbb_root_path . 'includes/page_header.'.\$phpEx);",'/* Own preview header sink */',$body);}
 else{
  if($action==='delete'){$a=strpos($s,"if (isset(\$HTTP_POST_VARS['delete_tour'])");$b=strpos($s,"if(isset(\$HTTP_POST_VARS['tour_submit']))",$a);as_check($a!==false&&$b>$a,'Actual deletion-button parser');$body=substr($s,$a,$b-$a);$a=strpos($s,"if (\$mode == 'delete')");$b=strpos($s,"else if (\$mode == 'add_tour'",$a);$body.=substr($s,$a,$b-$a);}
  else{$a=strpos($s,"if(isset(\$HTTP_POST_VARS['add_game']))");$b=strpos($s,"if (\$mode == 'delete')",$a);$body=substr($s,$a,$b-$a);}
 }
 as_check($a!==false&&$b>$a,'Actual complete action controller');try{eval($body);return array('body'=>'','vars'=>$template->vars);}catch(ArcadeSummaryExit$e){return array('body'=>$e->getMessage(),'vars'=>$template->vars);}catch(TournamentPreviewExit$e){return array('body'=>'preview','vars'=>$template->vars);}
}
