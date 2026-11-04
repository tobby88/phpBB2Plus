<?php
require __DIR__.'/arcade-moderation-fixture.php';$count=0;
class ArcadeModeratorNoQuery {var $queries=0;function sql_query($sql){$this->queries++;throw new RuntimeException('Malformed actor must not query storage');}}
$invalid=array(array(),array('session_logged_in'=>false,'user_id'=>2,'session_id'=>'x'));
foreach(array(null,true,false,array(2),-1,0,'01','2x','16777216')as$id){$invalid[]=array('session_logged_in'=>true,'user_id'=>$id,'session_id'=>'x');}
foreach(array(null,false,array('x'),'',str_repeat('a',33))as$sid){$invalid[]=array('session_logged_in'=>true,'user_id'=>2,'session_id'=>$sid);}
foreach($invalid as$actor){$userdata=$actor;$db=new ArcadeModeratorNoQuery();$caught=false;try{phpbb_arcade_moderator_actor($db,7);}catch(PhpbbOwnedStorageFailure$e){$caught=true;}as_check($caught&&$db->queries===0,'Malformed or anonymous public actor rejected before SQL');$count++;}
foreach(array(-2,'07','7junk',array(7),true,8388608)as$category){$userdata=array('session_logged_in'=>true,'user_id'=>2,'session_id'=>'x');$db=new ArcadeModeratorNoQuery();$caught=false;try{phpbb_arcade_moderator_actor($db,$category);}catch(PhpbbOwnedStorageFailure$e){$caught=true;}as_check($caught&&$db->queries===0,'Malformed category cannot widen scope');$count++;}
foreach(array('english','german')as$locale){as_language($locale);as_check(isset($lang['arcade_score_changed'],$lang['arcade_mod_delete_score'])&&preg_match('//u',$lang['arcade_score_changed'].$lang['arcade_mod_delete_score'])===1,'Recovery and delete text available without loading ACP language');$count++;}
$values=array('mode'=>'mod','cat_id'=>7,'player_id'=>-1,'score_revision'=>str_repeat('a',64),'sid'=>"literal'\\&amp;😀");$html=arcade_modcp_fields($values);preg_match_all('/name="([^"]+)" value="([^"]*)"/',$html,$m,PREG_SET_ORDER);$parsed=array();foreach($m as$field){$parsed[html_entity_decode($field[1],ENT_QUOTES,'UTF-8')]=html_entity_decode($field[2],ENT_QUOTES,'UTF-8');}
as_check($parsed===array_map('strval',$values),'Actual hidden fields preserve zero/negative/quoted literal values');$count++;
foreach(array('confirm_body.tpl'=>'{S_HIDDEN_FIELDS}','arcade_mod_body.tpl'=>'{S_HIDDEN_OPTIONS}')as$file=>$field){as_check(strpos(file_get_contents($as_app.'templates/fisubsilversh/'.$file),$field)!==false,'Actual moderator template retains bound state '.$file);$count++;}
$source=file_get_contents($as_app.'arcade_modcp.php');as_check(strpos($source,'DELETE FROM')===false&&strpos($source,'$arcade->pass_var(')===false,'Controller delegates writes and uses one raw request boundary');
as_check(substr_count($source,"include(\$phpbb_root_path . 'includes/page_tail.'.\$phpEx); exit;")===1,'Confirmation stops after actual page tail');
echo 'Arcade moderation inputs: '.$count.' cases, '.$as_checks." assertions PASS; current public actors, confined categories, real form state and EN/DE recovery.\n";
