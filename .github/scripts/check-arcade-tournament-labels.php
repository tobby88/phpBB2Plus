<?php
require __DIR__.'/arcade-tournament-fixture.php';
class ArcadeTournamentLabelDatabase
{
 var $users=array();var $queries=array();var $alias_opened=0;var $fail_alias=false;
 function sql_escape($v){return str_replace("'","''",$v);}
 function sql_query($sql){$this->queries[]=$sql;if(preg_match('/^SELECT username FROM fixture_users\s+WHERE user_id = (-?[0-9]+)$/D',$sql,$m)){return(object)array('rows'=>isset($this->users[(int)$m[1]])?array(array('username'=>$this->users[(int)$m[1]])):array(),'offset'=>0,'alias'=>false);}
  if(strpos($sql,'SELECT n.author_user_id FROM fixture_removed_author_names n WHERE ')!==0){throw new RuntimeException('Only synthetic account/alias reads allowed');}if($this->fail_alias){return false;}$this->alias_opened++;return(object)array('rows'=>array(),'offset'=>0,'alias'=>true);
 }
 function sql_fetchrow($r){return isset($r->rows[$r->offset])?$r->rows[$r->offset++]:false;}function sql_fetchrowset($r){return $r->rows;}function sql_freeresult($r){if($r->alias){$this->alias_opened--;}}
}
$arcade=new arcade();$SID='sid=tournament_fixture';$keep=array();
$viewers=array(array('id'=>-1,'logged'=>false,'privacy'=>1),array('id'=>3,'logged'=>true,'privacy'=>1));if(isset($at_extra_viewers)){$viewers=array_merge($viewers,$at_extra_viewers);}
foreach(array('english','german')as$locale){as_language($locale);foreach($viewers as$viewer){$userdata=array('user_id'=>$viewer['id'],'session_logged_in'=>$viewer['logged']);$board_config=array('hide_removed_author_names'=>$viewer['privacy'],'sitename'=>'Fixture');if(isset($at_extra_config)){$board_config=array_merge($board_config,$at_extra_config);}$db=new ArcadeTournamentLabelDatabase();$keep[]=$db;
 foreach(array('A&B',"O'Reilly",'"Name"','&#39;','&amp;','<b>ü</b>','Grüße 😀','0')as$raw){foreach(array(ENT_QUOTES,ENT_COMPAT)as$flags){$key=phpbb_username_key($raw,$flags);as_check($key!=='','Valid canonical/historical account key');$db->users=array(7=>$key);$before=serialize(array($db->users,$userdata,$board_config));at_check_output(at_render(7,$key),$raw,7);as_check(serialize(array($db->users,$userdata,$board_config))===$before&&$db->alias_opened===0,'Rendering changes no identity or policy and releases alias results');$as_cases++;}}
 $db->users=array();at_check_output(at_render(40,null),$lang['Former_member'],40,false);$as_cases++;foreach(array(0,-1)as$id){at_check_output(at_render($id,null),$lang['Guest'],$id,false);$as_cases++;}
 $db->users=array(7=>phpbb_username_key('Renamed & Current'));at_check_output(at_render(7,$db->users[7]),'Renamed & Current',7);$as_cases++;
}}
$userdata=array('user_id'=>-1,'session_logged_in'=>false);$board_config=array('hide_removed_author_names'=>1);$db=new ArcadeTournamentLabelDatabase();$keep[]=$db;$db->users=array(7=>'Current');$db->fail_alias=true;$caught=false;try{at_render(7,'Current');}catch(ArcadeSummaryExit$e){$caught=$e->getMessage()===$lang['Author_privacy_unavailable'];}as_check($caught,'Privacy lookup failure cannot silently disclose a byline');$as_cases++;

$tour=array('tour_id'=>7,'tour_active'=>2,'tour_max_players'=>2);
foreach(array(
 array(2,false,1,false,true,false),
 array(2,false,2,false,false,false),
 array(2,true,1,false,false,false),
 array(0,false,0,false,false,false),
 array(2,false,0,true,true,true),
 array(2,true,1,true,false,true),
 array(3,false,0,true,false,false),
 array(0,false,0,true,false,true),
)as$case){
 $tour['tour_active']=$case[0];
 $html=arcade_tournament_detail_options($tour,$case[1],$case[2],$case[3]);
 as_check((strpos($html,'name="join"')!==false)===$case[4]&&(strpos($html,'name="end"')!==false)===$case[5],
  'Detail actions follow event state, seat availability, membership and admin role');
 as_check((strpos($html,'name="join_tour[]" value="7"')!==false)===$case[4]&&(strpos($html,'name="id" value="7"')!==false)===$case[5],
  'Only displayed actions carry the matching tournament ID');
 $as_cases++;
}
$record=array('game_name'=>'Old \\ Arcade','played'=>2,'score'=>13.75);
foreach(array(serialize(array(4=>$record)),addslashes(serialize(array(4=>$record))),serialize(array(4=>$record,5=>true)))as$value){
 as_check(arcade_tournament_player_games($value)===array($record),'Canonical backslashes and recoverable legacy escaping preserve game history');
 $as_cases++;
}
foreach(array(null,'','broken',serialize(false))as$value){
 as_check(arcade_tournament_player_games($value)===array(),'Unplayed or malformed tournament records render as empty');
 $as_cases++;
}
restore_error_handler();echo 'Arcade tournament labels: ',$as_cases,' cases, ',$as_checks," assertions PASS; five actual current-account/winner/player/champion bindings, safe profile targets, guest/former placeholders, literal entities and shared privacy; synthetic reads only.\n";
