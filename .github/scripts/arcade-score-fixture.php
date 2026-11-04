<?php
require_once __DIR__.'/arcade-action-fixture.php';
require_once $as_app.'includes/functions_arcade_score_storage.php';
if(!function_exists('create_date')){as_function($as_app.'includes/functions.php','create_date');}
class ArcadeScoreTemplate extends ArcadeActionTemplate
{
 var $blocks=array();var $rendered='';
 function assign_block_vars($name,$values){$this->blocks[$name][]=$values;}
 function pparse($name){$this->rendered=$name;parent::pparse($name);}
}
function sf_controller($request,$method='POST',$full=false,$query=array())
{
 global $as_app,$db,$arcade,$userdata,$HTTP_POST_VARS,$HTTP_GET_VARS,$lang,$phpEx,$phpbb_root_path,$SID,$board_config,$images;
 profile_fixture_request($method==='POST'?$request:array());$_SERVER['REQUEST_METHOD']=$method;
 $HTTP_GET_VARS=$_GET=phpbb_addslashes_recursive($method==='GET'?$request:$query);
 $SID='sid=fixture-import';$file='admin_arcade_scores.php';$template=new ArcadeScoreTemplate();
 $board_config['default_dateformat']='Y-m-d';$board_config['board_timezone']=0;$images=array('icon_edit'=>'edit.png','icon_delpost'=>'delete.png');
 $source=file_get_contents($as_app.'admin/admin_arcade_scores.php');$a=strpos($source,'$score_request =');$b=$full?strlen($source):strpos($source,'// Score listing.',$a);
 as_check($a!==false&&$b>$a,'Actual complete score controller boundary');
 try{eval(substr($source,$a,$b-$a));return array('type'=>'result','result'=>$score_action_result,'game'=>$game_info);}
 catch(ArcadeActionConfirmation$e){$fields=array();if(isset($template->vars['S_HIDDEN_FIELDS'])){preg_match_all('/name="([^"]+)" value="([^"]*)"/',$template->vars['S_HIDDEN_FIELDS'],$matches,PREG_SET_ORDER);foreach($matches as$match){$fields[$match[1]]=html_entity_decode($match[2],ENT_QUOTES,'UTF-8');}}
  return array('type'=>$template->rendered==='confirm'?'confirmation':'page','fields'=>$fields,'vars'=>$template->vars,'blocks'=>$template->blocks,'files'=>$template->files);}
 catch(ArcadeSummaryExit$e){return array('type'=>'message','text'=>$e->getMessage());}
}
function sf_row($board='current',$player=2){return ag_rows('SELECT * FROM '.phpbb_arcade_score_table($board).' WHERE player_id='.(int)$player)[0];}
function sf_revision($board='current',$player=2){return phpbb_arcade_score_revision(ag_rows('SELECT * FROM fixture_ina_games WHERE game_id=41')[0],$board,sf_row($board,$player));}
function sf_call($action='update',$value='200',$board='current',$player=2,$revision=null,$id=41){
 if($revision===null){$revision=sf_revision($board,$player);}
 try{return array('error'=>false,'result'=>phpbb_arcade_score_change($GLOBALS['db'],$id,$board,$player,$revision,$action,$value));}
 catch(PhpbbOwnedStorageFailure$e){return array('error'=>true,'key'=>$e->getMessage());}catch(PhpbbAclException$e){return array('error'=>true,'key'=>$e->getMessage());}
}
