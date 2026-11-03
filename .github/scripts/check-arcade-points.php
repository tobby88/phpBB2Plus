<?php
require __DIR__.'/arcade-points-fixture.php';
class ArcadePointsDatabaseFixture
{
 var $monthly=array();var $descending=array();var $ascending=array();var $queries=array();var $opened=0;var $fail='';
 function sql_escape($v){return str_replace("'","''",$v);}
 function sql_query($sql){
  $this->queries[]=$sql;
  if(strpos($sql,'SELECT h.highscore_user_id,')===0){$kind='monthly';$rows=$this->monthly;}
  elseif(strpos($sql,'SELECT s.game_name, s.player_id, u.username AS player')===0){$kind='alltime';$rows=strpos($sql,'g.reverse_list = 0')!==false?$this->descending:$this->ascending;}
  elseif(strpos($sql,'SELECT n.author_user_id FROM fixture_removed_author_names n WHERE ')===0){$kind='alias';$match=strpos($sql,'n.author_user_id=40 OR')!==false||strpos($sql,"n.author_name='Legacy former'")!==false;if(strpos($sql,'current_author.user_id=9')!==false){$match=false;}$rows=$match?array(array('author_user_id'=>0)):array();}
  else{throw new RuntimeException('Only actual synthetic points and alias SELECTs allowed');}
  if($this->fail===$kind){return false;}$this->opened++;return(object)array('rows'=>$rows,'offset'=>0);
 }
 function sql_fetchrow($r){return isset($r->rows[$r->offset])?$r->rows[$r->offset++]:false;}function sql_fetchrowset($r){return $r->rows;}function sql_freeresult($r){$this->opened--;}
}
$viewers=array(array('id'=>-1,'logged'=>false,'privacy'=>0,'mask'=>false),array('id'=>-1,'logged'=>false,'privacy'=>1,'mask'=>true),array('id'=>3,'logged'=>true,'privacy'=>0,'mask'=>false),array('id'=>3,'logged'=>true,'privacy'=>1,'mask'=>false));if(isset($ap_extra_viewers)){$viewers=array_merge($viewers,$ap_extra_viewers);}$keep=array();
foreach(array('english','german')as$locale){ap_language($locale);foreach($viewers as$viewer){$userdata=array('user_id'=>$viewer['id'],'session_logged_in'=>$viewer['logged']);$board_config=array('hide_removed_author_names'=>$viewer['privacy']);if(isset($ap_extra_config)){$board_config=array_merge($board_config,$ap_extra_config);}
 foreach(array('A&B',"O'Reilly",'&#39;','Grüße 😀')as$raw){foreach(array(ENT_QUOTES,ENT_COMPAT)as$flags){foreach(array(false,true)as$reverse){$db=new ArcadePointsDatabaseFixture();$keep[]=$db;$key=phpbb_username_key($raw,$flags);$db->monthly=array(array('highscore_user_id'=>40,'player'=>'Old name','score_count'=>2),array('highscore_user_id'=>40,'player'=>'Legacy former','score_count'=>1),array('highscore_user_id'=>0,'player'=>'Guest A','score_count'=>2),array('highscore_user_id'=>0,'player'=>'Guest B','score_count'=>1),array('highscore_user_id'=>7,'player'=>$key,'score_count'=>1),array('highscore_user_id'=>9,'player'=>'Legacy former','score_count'=>1),array('highscore_user_id'=>41,'player'=>'Alive recorded','score_count'=>1));if($reverse){$db->monthly=array_reverse($db->monthly);}
  $db->descending=array(array('game_name'=>'a','player_id'=>7,'player'=>$key),array('game_name'=>'a','player_id'=>9,'player'=>'Legacy former'),array('game_name'=>'b','player_id'=>9,'player'=>'Legacy former'));$db->ascending=array(array('game_name'=>'c','player_id'=>7,'player'=>$key),array('game_name'=>'c','player_id'=>9,'player'=>'Legacy former'),array('game_name'=>'d','player_id'=>41,'player'=>'Alive recorded'));
  $before=serialize(array($db->monthly,$db->ascending,$db->descending,$userdata,$board_config));$out=ap_run();$t=$out['totals'];$labels=ap_labels($out);
  ap_check(count($t)===6&&$t['u:40']['monthly']===3&&$t['u:40']['total']===3,'Deleted account aliases accumulate by stable ID independent of input order');ap_check($t['n:Guest A']['monthly']===2&&$t['n:Guest B']['monthly']===1,'Different genuine guests stay separate');ap_check($t['u:7']['monthly']===1&&$t['u:7']['all_time']===2&&$t['u:7']['total']===9&&$t['u:9']['all_time']===1&&$t['u:41']['all_time']===1,'Current/previous monthly weights and independent ascending/descending winners count once per game');
  ap_check($labels['u:7']===$raw&&$labels['u:9']==='Legacy former'&&$labels['u:41']==='Alive recorded','Stored account keys display once and reused/live-recorded accounts remain visible');ap_check($labels['u:40']===($viewer['mask']?$lang['Former_member']:($reverse?'Old name':'Legacy former')),'Known former owners follow shared viewer policy');ap_check($out['ranges']===array(array(2026,1),array(2025,12)),'Actual January range crosses the year correctly');
  $keys=array_keys($t);ap_check($keys[0]==='u:7'&&array_search('u:41',$keys,true)<array_search('u:9',$keys,true),'Actual points/all-time/name tie ordering remains intact');foreach($out['rows']as$i=>$row){ap_check($row['RANK']===$i+1&&$row['TOTAL']===$t[$keys[$i]]['total'],'Ranks are contiguous and template totals match stable aggregates');}
  ap_check(serialize(array($db->monthly,$db->ascending,$db->descending,$userdata,$board_config))===$before&&$db->opened===0,'All reads preserve history/settings and close query results');$ap_cases++;
 }}}
 $db=new ArcadePointsDatabaseFixture();$keep[]=$db;$out=ap_run(2026,6,false);ap_check($out['rows']===array()&&$out['ranges']===array(array(2026,6))&&$db->opened===0,'Empty ranking and disabled previous-month window remain supported');$ap_cases++;
}}
$userdata=array('user_id'=>-1,'session_logged_in'=>false);$board_config=array('hide_removed_author_names'=>1);foreach(array('monthly','alltime','alias')as$failure){$db=new ArcadePointsDatabaseFixture();$keep[]=$db;$db->monthly=array(array('highscore_user_id'=>40,'player'=>'Legacy former','score_count'=>1));$db->fail=$failure;$caught=false;try{ap_run();}catch(ArcadePointsReadFailure$e){$caught=$failure!=='alias'||$e->getMessage()===$lang['Author_privacy_unavailable'];}ap_check($caught&&$db->opened===0,'Read failures stop rendering and release prior result sets');$ap_cases++;}
restore_error_handler();echo 'Arcade points: ',$ap_cases,' cases, ',$ap_checks," assertions PASS; actual full aggregation and ranking, stable owners, historical aliases, genuine guests, current names, shared privacy, winner directions, weights and period ranges; synthetic reads only.\n";
