<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }

// Shared by catalogue maintenance and score ingestion on owned storage.
trait PhpbbArcadeRankings
{
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
}
