<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_arcade_import_storage.php';

function phpbb_arcade_game_fields()
{
 return array('game_name','game_path','image_path','game_desc','game_charge','game_reward','game_bonus','at_game_bonus',
  'game_use_gl','game_flash','game_show_score','game_avail','allow_guest','reverse_list','win_width','win_height',
  'highscore_limit','at_highscore_limit','instructions','cat_id','score_type','group_required','rank_required',
  'level_required','game_autosize','game_control');
}
function phpbb_arcade_game_revision($row)
{
 if (!is_array($row)) { throw new PhpbbOwnedStorageFailure('arcade_game_changed'); }
 $snapshot=array();foreach(array_merge(array('game_id'),phpbb_arcade_game_fields())as$key){
  if(!array_key_exists($key,$row)||($row[$key]!==null&&!is_scalar($row[$key]))){throw new PhpbbOwnedStorageFailure('arcade_game_changed');}
  $snapshot[$key]=$row[$key]===null?null:(string)$row[$key];
 }
 $json=json_encode($snapshot);if($json===false){throw new PhpbbOwnedStorageFailure('arcade_game_changed');}return hash('sha256',$json);
}
function phpbb_arcade_game_values($values)
{
 if(!is_array($values)||array_keys($values)!==phpbb_arcade_game_fields()){throw new PhpbbOwnedStorageFailure('no_game_save');}
 foreach(array('game_name'=>50,'game_path'=>255,'image_path'=>255,'game_desc'=>255,'instructions'=>65535)as$key=>$limit){
  $value=$values[$key];$bytes=$key==='game_name'?50:($key==='instructions'?65535:0);
  if(!is_string($value)||phpbb_arcade_text_limit($value,$limit,$bytes)!==$value){throw new PhpbbOwnedStorageFailure('no_game_save');}
 }
 $name=$values['game_name'];if($name===''||trim($name)!==$name||in_array($name,array('.','..'),true)||preg_match('#[\s/\\\\\x00-\x1f\x7f]#',$name)){throw new PhpbbOwnedStorageFailure('admin_game_wrong_name');}
 foreach(array('game_path','image_path')as$key){$value=$values[$key];if(($key==='game_path'&&($value===''||substr($value,-1)!=='/'))||($value!==''&&phpbb_arcade_local_asset($value)!==$value)||preg_match('/[\x00-\x1f\x7f]/',$value)){throw new PhpbbOwnedStorageFailure('admin_game_wrong_name');}}
 $limits=array('game_charge'=>'4294967295','game_reward'=>'4294967295','game_bonus'=>'65535','at_game_bonus'=>'32767',
  'game_use_gl'=>'1','game_flash'=>'1','game_show_score'=>'1','game_avail'=>'1','allow_guest'=>'1','reverse_list'=>'1',
  'win_width'=>'4096','win_height'=>'4096','highscore_limit'=>'9223372036854775807','at_highscore_limit'=>'32767',
  'score_type'=>'8','group_required'=>'8388607','rank_required'=>'2147483647','level_required'=>'127','game_autosize'=>'1','game_control'=>'3');
 foreach($limits as$key=>$maximum){$value=$values[$key];if(!(is_string($value)||is_int($value))||!preg_match('/^(?:0|[1-9][0-9]{0,18})$/D',(string)$value)||strlen((string)$value)>strlen($maximum)||(strlen((string)$value)===strlen($maximum)&&strcmp((string)$value,$maximum)>0)){throw new PhpbbOwnedStorageFailure('no_game_save');}$values[$key]=(string)$value;}
 if(!(is_string($values['cat_id'])||is_int($values['cat_id']))||!preg_match('/^(?:-1|[1-9][0-9]{0,6})$/D',(string)$values['cat_id'])||(int)$values['cat_id']>8388607){throw new PhpbbOwnedStorageFailure('no_cat_data');}$values['cat_id']=(string)$values['cat_id'];
 return $values;
}
function phpbb_arcade_game_references()
{
 return array(array(iNA_SCORES,'game_name'),array(iNA_AT_SCORES,'game_name'),array(iNA_FAV,'fav_game_name'),
  array(iNA_GAMES_COMMENT,'comment_game_name'),array(iNA_GAMES_COMMENT,'game_name'),array(iNA_GAMES_RATE,'rate_game_name'),
  array(iNA_SESSIONS,'game_name'),array(iNA_HIGHSCORES,'highscore_game'),array(iNA_BANNED,'game'),array(iNA_TOUR_DATA,'game_name'),
  array(iNA_TOUR_PLAY,'last_played_game'),array(iNA_USER_DATA,'last_played'),array(iNA_CAT,'last_game'));
}
class PhpbbArcadeGameWriter extends PhpbbArcadeImportWriter
{
 protected $game_id;
 function __construct($database,$id){if(!is_int($id)||$id<0||$id>8388607){throw new PhpbbOwnedStorageFailure('no_game_save');}$this->game_id=$id;parent::__construct($database);}
 protected function actor(){global $phpEx;return phpbb_acp_actor($this,'admin_arcade_games.'.$phpEx.'?mode='.($this->game_id?'edit_games':'add_game'));}
 protected function storage_tables(){ $tables=array_merge(parent::storage_tables(),array(GROUPS_TABLE,RANKS_TABLE));foreach(phpbb_arcade_game_references()as$ref){$tables[]=$ref[0];}return array_values(array_unique($tables)); }
 protected function pin_tables()
 {
  parent::pin_tables();$table=$this->escape(iNA_BANNED);
  $rows=$this->rows("SELECT CHARACTER_MAXIMUM_LENGTH AS capacity FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND HEX(TABLE_NAME)=HEX('$table') AND COLUMN_NAME='game'");
  if(count($rows)!==1||(int)$rows[0]['capacity']<50){$this->storage_fail('Owned_storage_upgrade');}
 }
 protected function ranked_players($table,$name,$reverse)
 {
  return $this->rows('SELECT player_id FROM '.$table." WHERE game_name='".$this->escape($name)."' ORDER BY score ".($reverse?'ASC':'DESC').',date ASC,player_id ASC LIMIT 3 FOR UPDATE');
 }
 protected function rebuild_rankings($players)
 {
  $players=array_values(array_unique($players));sort($players,SORT_NUMERIC);if(!$players){return;}
  // Recalculate only affected existing accounts. All other score rows still
  // occupy their actual places, including guests and retired account IDs.
  $live=$this->rows('SELECT user_id FROM '.USERS_TABLE.' WHERE user_id IN ('.implode(',',$players).') ORDER BY user_id LOCK IN SHARE MODE');
  $summaries=array();foreach($live as$row){$id=(int)$row['user_id'];$summaries[$id]=array();foreach(array('','at_')as$prefix){foreach(array('first','second','third')as$place){$summaries[$id][$prefix.$place.'_places']=0;$summaries[$id][$prefix.$place.'_list']='';}}}
  if(!$summaries){return;}$ids=implode(',',array_keys($summaries));
  $existing=$this->rows('SELECT user_id FROM '.iNA_USER_DATA.' WHERE user_id IN ('.$ids.') ORDER BY user_id FOR UPDATE');$present=array();foreach($existing as$row){$present[(int)$row['user_id']]=true;}
  foreach(array(iNA_SCORES=>'',iNA_AT_SCORES=>'at_')as$table=>$prefix){
   $sql='SELECT g.game_id,g.game_name,s.player_id FROM '.iNA_GAMES.' g INNER JOIN '.$table.' s ON s.game_name=g.game_name WHERE g.game_id<>0 AND g.game_avail=1 AND g.game_show_score=1 ORDER BY g.game_id,CASE WHEN g.reverse_list<>0 THEN s.score ELSE -s.score END ASC,s.date ASC,s.player_id ASC';
   $result=$this->query($sql);$game=null;$place=0;
   try{while($row=$this->connection->sql_fetchrow($result)){
    if($game!==(string)$row['game_id']){$game=(string)$row['game_id'];$place=0;}$place++;$id=(int)$row['player_id'];if($place>3||!isset($summaries[$id])){continue;}
    $key=$prefix.($place===1?'first':($place===2?'second':'third'));$summaries[$id][$key.'_places']++;$summaries[$id][$key.'_list'].=$row['game_name'].'|';
    if($summaries[$id][$key.'_places']>8388607||strlen($summaries[$id][$key.'_list'])>65535){$this->storage_fail();}
   }}finally{$this->connection->sql_freeresult($result);}
  }
  foreach($summaries as$id=>$fields){$assign=array();foreach($fields as$key=>$value){$assign[]=$key."='".$this->escape((string)$value)."'";}
   if(isset($present[$id])){$this->query('UPDATE '.iNA_USER_DATA.' SET '.implode(',',$assign).' WHERE user_id='.$id);}
   else{$this->query('INSERT INTO '.iNA_USER_DATA.' SET user_id='.$id.','.implode(',',$assign));}
  }
 }
 function save($values,$revision,$reset)
 {
  global $userdata;$values=phpbb_arcade_game_values($values);
  if(!is_array($reset)||array_keys($reset)!==array('current','all_time')||!is_bool($reset['current'])||!is_bool($reset['all_time'])){$this->storage_fail();}
  if(!is_string($revision)||($this->game_id?!preg_match('/^[a-f0-9]{64}$/D',$revision):$revision!=='')){$this->storage_fail('arcade_game_changed');}
  $actor=$this->actor();$sid=$this->escape($userdata['session_id']);foreach(array('SELECT session_id FROM '.SESSIONS_TABLE." WHERE session_id='$sid' AND HEX(session_id)=HEX('$sid')",'SELECT user_id FROM '.USERS_TABLE.' WHERE user_id='.(int)$actor['user_id'],'SELECT user_id FROM '.JR_ADMIN_TABLE.' WHERE user_id='.(int)$actor['user_id'])as$sql){$this->rows($sql.' LOCK IN SHARE MODE');}$this->actor();
  $old=null;if($this->game_id){$rows=$this->rows('SELECT * FROM '.iNA_GAMES.' WHERE game_id='.$this->game_id.' FOR UPDATE');if(count($rows)!==1||!hash_equals(phpbb_arcade_game_revision($rows[0]),$revision)){$this->storage_fail('arcade_game_changed');}$old=$rows[0];}
  $name=$this->escape($values['game_name']);$renaming=$old&&strcmp((string)$old['game_name'],$values['game_name'])!==0;
  $ranking_change=!$old||$renaming||$reset['current']||$reset['all_time'];
  if($old){foreach(array('reverse_list','game_avail','game_show_score')as$key){if((int)$old[$key]!== (int)$values[$key]){$ranking_change=true;}}}
  if($this->rows('SELECT game_id FROM '.iNA_GAMES." WHERE game_name='$name'".($this->game_id?' AND game_id<>'.$this->game_id:'').' FOR UPDATE')){$this->storage_fail('admin_game_exists');}
  if($old&&($renaming||$reset['current']||$reset['all_time'])){$old_name=$this->escape((string)$old['game_name']);if($this->rows('SELECT game_id FROM '.iNA_GAMES." WHERE game_name='$old_name' AND game_id<>".$this->game_id.' FOR UPDATE')){$this->storage_fail('arcade_game_changed');}}
  foreach(array('group_required'=>array(GROUPS_TABLE,'group_id',' AND group_single_user=0'),'rank_required'=>array(RANKS_TABLE,'rank_id',''))as$key=>$target){if((int)$values[$key]>0&&(!$old||(string)$old[$key]!==$values[$key])&&!$this->rows('SELECT '.$target[1].' FROM '.$target[0].' WHERE '.$target[1].'='.$values[$key].$target[2].' LOCK IN SHARE MODE')){$this->storage_fail('no_game_save');}}
  $new_cat=(int)$values['cat_id'];$old_cat=$old?(int)$old['cat_id']:-1;$cats=array(-1,$new_cat);if($old_cat>0){$cats[]=$old_cat;}$cats=array_values(array_unique($cats));sort($cats,SORT_NUMERIC);
  $rows=$this->rows('SELECT cat_id,cat_type FROM '.iNA_CAT.' WHERE cat_id IN ('.implode(',',$cats).') ORDER BY cat_id FOR UPDATE');$present=array();foreach($rows as$row){$present[(int)$row['cat_id']]=$row;}
  if(!isset($present[-1],$present[$new_cat])||$present[$new_cat]['cat_type']==='l'){$this->storage_fail('no_cat_data');}
  $affected=array();if($ranking_change&&$old){foreach(array('highscore_id','at_highscore_id')as$key){if((int)$old[$key]>0){$affected[]=(int)$old[$key];}}foreach(array(iNA_SCORES,iNA_AT_SCORES)as$table){foreach($this->ranked_players($table,(string)$old['game_name'],!empty($old['reverse_list']))as$row){if((int)$row['player_id']>0){$affected[]=(int)$row['player_id'];}}}}
  $assign=array();foreach($values as$key=>$value){$assign[]=$key."='".$this->escape($value)."'";}
  if($old){$this->query('UPDATE '.iNA_GAMES.' SET '.implode(',',$assign).' WHERE game_id='.$this->game_id);$id=$this->game_id;}
  else{$this->query('INSERT INTO '.iNA_GAMES.' SET '.implode(',',$assign).',date_added='.time());$id=$this->connection->sql_nextid();if(!preg_match('/^[1-9][0-9]{0,7}$/D',(string)$id)||(float)$id>8388607){$this->storage_fail();}$id=(int)$id;}
  if($renaming){$old_name=$this->escape((string)$old['game_name']);foreach(phpbb_arcade_game_references()as$ref){$this->query('UPDATE '.$ref[0].' SET '.$ref[1]."='$name' WHERE ".$ref[1]."='$old_name'");}}
  $deltas=array();foreach($cats as$cat){$deltas[$cat]=array('games'=>0,'played'=>'0');}
  if($old&&!empty($old['game_avail'])){$deltas[-1]['games']--;if($old_cat>0&&isset($present[$old_cat])){$deltas[$old_cat]['games']--;}}
  if((int)$values['game_avail']){$deltas[-1]['games']++;if($new_cat>0){$deltas[$new_cat]['games']++;}}
  if($old&&$old_cat!==$new_cat){$played=(string)$old['played'];if(!preg_match('/^[0-9]{1,10}$/D',$played)||(float)$played>4294967295){$this->storage_fail();}if($old_cat>0&&isset($present[$old_cat])){$deltas[$old_cat]['played']='-'.$played;}if($new_cat>0){$deltas[$new_cat]['played']=$played;}}
  foreach($deltas as$cat=>$delta){$set=array();if($delta['games']){$set[]=$delta['games']>0?'total_games=total_games+1':'total_games=GREATEST(total_games-1,0)';}if((float)$delta['played']!==0.0){$set[]=substr($delta['played'],0,1)==='-'?'total_played=GREATEST(total_played'.$delta['played'].',0)':'total_played=total_played+'.$delta['played'];}if($set){$this->query('UPDATE '.iNA_CAT.' SET '.implode(',',$set).' WHERE cat_id='.(int)$cat);}}
  if($ranking_change){
   foreach(array('current'=>array(iNA_SCORES,'highscore_id'),'all_time'=>array(iNA_AT_SCORES,'at_highscore_id'))as$key=>$target){
    if($reset[$key]){$this->query('DELETE FROM '.$target[0]." WHERE game_name='$name'");}
    foreach($this->ranked_players($target[0],$values['game_name'],(int)$values['reverse_list']!==0)as$row){if((int)$row['player_id']>0){$affected[]=(int)$row['player_id'];}}
    // Ranked score rows are locked already. Keep accounts shared so a pending
    // authority revoke need not deadlock with an actor becoming the winner.
    $top=$this->rows('SELECT s.player_id FROM '.$target[0].' s INNER JOIN '.USERS_TABLE." u ON u.user_id=s.player_id WHERE s.game_name='$name' ORDER BY s.score ".((int)$values['reverse_list']?'ASC':'DESC').',s.date ASC,s.player_id ASC LIMIT 1 LOCK IN SHARE MODE');
    $winner=$top?(int)$top[0]['player_id']:0;$this->query('UPDATE '.iNA_GAMES.' SET '.$target[1].'='.$winner.' WHERE game_id='.$id);
   }
   $this->rebuild_rankings($affected);
  }
  $this->commit_attempted=true;$this->commit();return array('id'=>$id,'created'=>$old===null,'cat_id'=>$new_cat);
 }
}
function phpbb_arcade_game_save($database,$id,$revision,$values,$reset)
{
 global $arcade,$phpbb_root_path;$values=phpbb_arcade_game_values($values);$writer=new PhpbbArcadeGameWriter($database,$id);
 try{return $writer->save($values,$revision,$reset);}
 finally{try{if($writer->commit_attempted){foreach(array('games','categories','best_player','best_at_player')as$key){try{$arcade->clear_cache($key,$phpbb_root_path);}catch(Exception$ignored){}catch(Error$ignored){}}}}finally{$writer->release();}}
}
