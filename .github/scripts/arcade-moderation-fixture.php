<?php
require_once __DIR__.'/arcade-score-fixture.php';
require_once $as_app.'includes/functions_arcade_moderation_storage.php';
foreach(array('arcade_modcp_html','arcade_modcp_ip','arcade_modcp_fields')as$name){as_function($as_app.'arcade_modcp.php',$name);}
class ArcadeModeratorRedirect extends RuntimeException {}
function redirect($url){throw new ArcadeModeratorRedirect($url);}
function am_controller($request,$method='POST',$query=array())
{
 global $as_app,$db,$arcade,$userdata,$HTTP_POST_VARS,$HTTP_GET_VARS,$lang,$phpEx,$phpbb_root_path,$SID,$board_config,$images;
 profile_fixture_request($method==='POST'?$request:array());$_SERVER['REQUEST_METHOD']=$method;
 $HTTP_GET_VARS=$_GET=phpbb_addslashes_recursive($method==='GET'?$request:$query);$SID='sid=fixture-import';$template=new ArcadeScoreTemplate();
 $board_config=array_merge($board_config,array('default_dateformat'=>'Y-m-d','board_timezone'=>0,'games_image_width'=>20,'games_image_height'=>20,'games_auto_size'=>0,'cookie_name'=>'fixture-cookie'));$images=array('icon_delpost'=>'delete.png');
 $source=file_get_contents($as_app.'arcade_modcp.php');$start=strpos($source,'$moderation_request =');as_check($start!==false,'Actual complete moderation controller boundary');$body=substr($source,$start);
 foreach(array('page_header','page_tail')as$frame){$body=str_replace("include(\$phpbb_root_path . 'includes/".$frame.".'.\$phpEx);",'',$body,$removed);as_check($removed===2,'Only the two actual site frame endpoints instrumented '.$frame);}
 try{eval($body);throw new RuntimeException('Actual moderation controller must render or redirect');}
 catch(ArcadeModeratorRedirect$e){return array('type'=>'redirect','url'=>$e->getMessage());}
 catch(ArcadeActionConfirmation$e){$fields=array();$hidden=isset($template->vars['S_HIDDEN_FIELDS'])?$template->vars['S_HIDDEN_FIELDS']:(isset($template->vars['S_HIDDEN_OPTIONS'])?$template->vars['S_HIDDEN_OPTIONS']:'');preg_match_all('/name="([^"]+)" value="([^"]*)"/',$hidden,$matches,PREG_SET_ORDER);foreach($matches as$match){$fields[$match[1]]=html_entity_decode($match[2],ENT_QUOTES,'UTF-8');}
  return array('type'=>$template->files['body']==='confirm_body.tpl'?'confirmation':'page','fields'=>$fields,'vars'=>$template->vars,'blocks'=>$template->blocks,'files'=>$template->files);}
 catch(ArcadeSummaryExit$e){return array('type'=>'message','text'=>$e->getMessage());}
}
function am_reset($role='root')
{
 global $db,$arcade,$userdata,$phpbb_root_path;
 ag_reset();$db->mod_fault='';$db->mod_fired=false;
 if($role!=='root'){
  ag_sql('UPDATE fixture_sessions SET session_admin=0');$userdata['session_admin']=0;
  if($role!=='root-no-acp'){ag_sql('UPDATE fixture_users SET user_level='.($role==='moderator-level'?2:0).' WHERE user_id=2');$userdata['user_level']=$role==='moderator-level'?2:0;}
  if(in_array($role,array('category','moderator-level'),true)){ag_sql('UPDATE fixture_ina_cat SET mod_id=2 WHERE cat_id=7');}
  elseif($role==='other-category'){ag_sql('UPDATE fixture_ina_cat SET mod_id=8 WHERE cat_id=7');ag_sql('UPDATE fixture_ina_cat SET mod_id=2 WHERE cat_id=9');}
 }
 $arcade->arcade_config['games_use_log']=0;$arcade->arcade_config['games_path']='games/';$arcade->arcade_config['games_default_img']='';as_check($arcade->write_cache('version','fixture-version',$phpbb_root_path),'Actual private version cache');
}
function am_call($category=7,$board='current',$player=8,$revision=null,$id=41)
{
 if($revision===null){$revision=sf_revision($board,$player);}
 try{return array('error'=>false,'result'=>phpbb_arcade_moderator_score_delete($GLOBALS['db'],$id,$category,$board,$player,$revision));}
 catch(PhpbbOwnedStorageFailure$e){return array('error'=>true,'key'=>$e->getMessage());}catch(PhpbbAclException$e){return array('error'=>true,'key'=>$e->getMessage());}
}
function am_preserved($before,$board,$except=array())
{
 $after=ag_snapshot();foreach($before as$table=>$rows){if(in_array($table,array_merge(array(phpbb_arcade_score_table($board),iNA_GAMES,iNA_USER_DATA),$except),true)){continue;}as_check($after[$table]===$rows,'Unrelated moderation data preserved '.$table);}
}
