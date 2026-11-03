<?php
require __DIR__.'/arcade-monthly-fixture.php';
class ArcadeMonthlyAliasFixture
{
 var $queries=array();var $fail=false;var $opened=0;
 function sql_escape($v){return str_replace("'","''",$v);}
 function sql_query($sql){
  if(strpos($sql,'SELECT n.author_user_id FROM fixture_removed_author_names n WHERE ')!==0){throw new RuntimeException('Only synthetic author alias SELECTs allowed');}$this->queries[]=$sql;if($this->fail){return false;}
  $match=strpos($sql,'n.author_user_id=40 OR')!==false||strpos($sql,"n.author_name='Legacy former'")!==false;
  if(strpos($sql,'current_author.user_id=9')!==false){$match=false;}$this->opened++;return(object)array('rows'=>$match?array(array('author_user_id'=>0)):array());
 }
 function sql_fetchrowset($r){return $r->rows;}function sql_freeresult($r){$this->opened--;}
 function sql_fetchrow($r){return isset($r->rows[$r->offset])?$r->rows[$r->offset++]:false;}
}
class ArcadeMonthlyReadFailure extends RuntimeException{}
function message_die($level,$message){throw new ArcadeMonthlyReadFailure($message);}
$viewers=array(array('id'=>-1,'logged'=>false,'privacy'=>0,'mask'=>false),array('id'=>-1,'logged'=>false,'privacy'=>1,'mask'=>true),array('id'=>3,'logged'=>true,'privacy'=>0,'mask'=>false),array('id'=>3,'logged'=>true,'privacy'=>1,'mask'=>false));if(isset($am_extra_viewers)){$viewers=array_merge($viewers,$am_extra_viewers);}$keep=array();
foreach(array('english','german')as$locale){am_language($locale);foreach($viewers as$viewer){$userdata=array('user_id'=>$viewer['id'],'session_logged_in'=>$viewer['logged'],'user_level'=>0);$board_config=array('hide_removed_author_names'=>$viewer['privacy']);if(isset($am_extra_config)){$board_config=array_merge($board_config,$am_extra_config);}$db=new ArcadeMonthlyAliasFixture();$keep[]=$db;
 $cases=array();foreach(array('A&B',"O'Reilly",'"Name"','&#39;','&amp;','<b>ü</b>','Grüße 😀','C:\\notes')as$raw){foreach(array(ENT_QUOTES,ENT_COMPAT)as$flags){$cases[]=array(am_row(7,phpbb_username_key($raw,$flags)),$raw);}}
 foreach(array(array(40,phpbb_username_key('Former & Name'),$viewer['mask']?$lang['Former_member']:'Former & Name'),array(0,'Legacy former',$viewer['mask']?$lang['Former_member']:'Legacy former'),array(-1,phpbb_post_author_key('Genuine & Guest'),'Genuine & Guest'),array(9,'Legacy former','Legacy former'),array(0,'0','0'))as$c){$cases[]=array(am_row($c[0],$c[1]),$c[2]);}
 foreach($cases as$case){$r=(object)array('rows'=>array($case[0]),'offset'=>0);$before=serialize(array($case[0],$userdata,$board_config));$html=am_render($r);$names=am_name($html);
  am_check(count($names[0])===1&&html_entity_decode($names[0][0],ENT_QUOTES,'UTF-8')===$case[1],'Actual monthly cell follows current/guest/former policy without double escaping');am_check($names[1]===array('0'),'Existing monthly zero score stays visible');am_check(strpos($html,'Raw &amp;amp; &lt;game&gt;')!==false,'Raw game captions retain their separate codec');am_check(strpos($html,'id=9&amp;sid=monthly_fixture')!==false&&strpos($html,'<b>ü</b>')===false,'Real session links remain bound to the game and names cannot inject markup');am_check(serialize(array($case[0],$userdata,$board_config))===$before&&$db->opened===0,'Rendering preserves history/settings and closes every alias result');$am_cases++;
 }
 foreach(array('allow_guest','level_required','rank_required','group_required')as$field){$row=am_row(40,'Legacy former');$row[$field]=$field==='allow_guest'?0:5;if($field==='allow_guest'&&$viewer['logged']){continue;}$n=count($db->queries);$html=am_render((object)array('rows'=>array($row),'offset'=>0));$names=am_name($html);am_check($names[0]===array()&&count($db->queries)===$n,'Denied games never render or query author aliases');$am_cases++;}
 $html=am_render((object)array('rows'=>array(),'offset'=>0));am_check(strpos($html,$lang['highscore_no_score'])!==false&&am_name($html)[0]===array(),'Actual empty month message remains intact');$am_cases++;
}}
// A failed alias read may never silently disclose the name being protected.
$userdata=array('user_id'=>-1,'session_logged_in'=>false,'user_level'=>0);$board_config=array('hide_removed_author_names'=>1);$db=new ArcadeMonthlyAliasFixture();$keep[]=$db;$db->fail=true;$caught=false;try{am_render((object)array('rows'=>array(am_row(40,'Legacy former')),'offset'=>0));}catch(ArcadeMonthlyReadFailure$e){$caught=$e->getMessage()===$lang['Author_privacy_unavailable'];}am_check($caught,'Alias read failures stop author rendering with the localized privacy error');$am_cases++;
restore_error_handler();echo 'Arcade monthly authors: ',$am_cases,' cases, ',$am_checks," assertions PASS; actual full monthly renderer, safe account labels, guest/former policy, legacy aliases, permissions, zero scores, session links and raw game metadata; synthetic reads only.\n";
