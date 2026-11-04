<?php
require_once __DIR__.'/arcade-game-fixture.php';
require_once $as_app.'includes/functions_arcade_action_storage.php';
$aa_page=file_get_contents($as_app.'admin/pagestart.php');
foreach(array('phpbb_admin_post_session_valid','phpbb_admin_require_post_session','phpbb_admin_html','phpbb_admin_session_field')as$name){
 as_check(preg_match('/^\tfunction '.$name.'\([^\r\n]*\)\r?\n\t\{.*?^\t\}/ms',$aa_page,$m)===1,'Actual ACP helper '.$name);eval($m[0]);
}
class ArcadeActionConfirmation extends RuntimeException {}
class ArcadeActionTemplate
{
 var $vars=array();var $files=array();
 function set_filenames($files){$this->files=$files;}
 function assign_vars($values){$this->vars=$values;}
 function pparse($name){throw new ArcadeActionConfirmation('Actual confirmation captured before exit');}
}
function aa_controller($mode,$post)
{
 global $as_app,$db,$arcade,$userdata,$HTTP_POST_VARS,$lang,$phpEx,$phpbb_root_path,$SID;
 profile_fixture_request($post);$SID='sid=fixture-import';$file='admin_arcade_games.php';$cat_id=7;$sort_order='ASC';$sort_mode='game_id';$start=0;$template=new ArcadeActionTemplate();
 $source=file_get_contents($as_app.'admin/admin_arcade_games.php');$a=strpos($source,'$request_is_post = isset(');$b=strpos($source,'//  Build Sort Option',$a);as_check($a!==false&&$b>$a,'Actual complete POST and token gate');$gate=substr($source,$a,$b-$a);
 $a=strpos($source,'else if( $mode == "clear_scores" || $mode == "clear_at_scores" )');$b=strpos($source,'//  Repair Index',$a);as_check($a!==false&&$b>$a,'Actual complete reset and deletion branches');$body=preg_replace('/^else if/','if',substr($source,$a,$b-$a),1);
 try{eval($gate.$body);return array('type'=>'none');}
 catch(ArcadeActionConfirmation$e){as_check(isset($template->vars['S_HIDDEN_FIELDS']),'Actual confirmation hidden fields');$fields=array();preg_match_all('/name="([^"]+)" value="([^"]*)"/',$template->vars['S_HIDDEN_FIELDS'],$matches,PREG_SET_ORDER);foreach($matches as$match){$fields[$match[1]]=html_entity_decode($match[2],ENT_QUOTES,'UTF-8');}return array('type'=>'confirmation','fields'=>$fields,'vars'=>$template->vars,'files'=>$template->files);}
 catch(ArcadeSummaryExit$e){return array('type'=>'message','text'=>$e->getMessage());}
}
