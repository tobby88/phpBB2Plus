<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__).'/functions_arcade_tournament_manage.php';
require_once dirname(__FILE__).'/functions_arcade_game_storage.php';

function phpbb_arcade_tournament_action_token($token)
{
    if(!is_string($token)||!preg_match('/^([1-9][0-9]{0,6}):([a-f0-9]{64})$/D',$token,$m)||(int)$m[1]>8388607){throw new PhpbbOwnedStorageFailure('arcade_tournament_changed');}
    return array('id'=>(int)$m[1],'revision'=>$m[2]);
}
function phpbb_arcade_tournament_game_tokens($tokens)
{
    if(!is_array($tokens)||count($tokens)>100){throw new PhpbbOwnedStorageFailure('no_game_data');}$out=array();
    foreach($tokens as$token){$item=phpbb_arcade_tournament_action_token($token);$id=$item['id'];if(isset($out[$id])&&$out[$id]!==$item['revision']){throw new PhpbbOwnedStorageFailure('arcade_tournament_changed');}$out[$id]=$item['revision'];}
    return $out;
}
class PhpbbArcadeTournamentActionWriter extends PhpbbArcadeTournamentWriter
{
    protected function storage_tables(){return array_merge(parent::storage_tables(),array(iNA_GAMES,iNA_TOUR_DATA,iNA_TOUR_INVITE,iNA_SESSIONS));}
    protected function remove_children($id)
    {
        foreach(array(iNA_TOUR_DATA,iNA_TOUR_PLAY,iNA_TOUR_INVITE,iNA_SESSIONS)as$table){$this->query('DELETE FROM '.$table.' WHERE tour_id='.$id);}
    }
    function act($operation,$id,$games,$revision)
    {
        $public=in_array($operation,array('end','preview-end'),true);$actor=$this->current_actor(!$public);
        if($public&&(int)$actor['user_level']!==ADMIN){$this->storage_fail('arcade_admin_only');}
        $settings=$this->settings();$events=$this->rows('SELECT * FROM '.iNA_TOUR.' WHERE tour_id='.$id.' FOR UPDATE');
        if(count($events)!==1||($operation!=='preview-end'&&!hash_equals(phpbb_arcade_tournament_revision($events[0]),$revision))){$this->storage_fail('arcade_tournament_changed');}
        $tour=$events[0];
        if($operation==='preview-end'){if((int)$tour['tour_active']===3){$this->storage_fail('arcade_tournament_changed');}return array('tour'=>$tour);}
        if($operation==='add'){
            $this->limits($settings);if((int)$tour['tour_active']===3){$this->storage_fail('arcade_tournament_changed');}
            $selected=array();if($games){$ids=array_keys($games);sort($ids,SORT_NUMERIC);foreach($this->rows('SELECT * FROM '.iNA_GAMES.' WHERE game_id IN ('.implode(',',$ids).') ORDER BY game_id LOCK IN SHARE MODE')as$game){$selected[(int)$game['game_id']]=$game;}}
            if(count($selected)!==count($games)){$this->storage_fail('arcade_tournament_changed');}
            $existing=$this->rows('SELECT game_name FROM '.iNA_TOUR_DATA.' WHERE tour_id='.$id.' ORDER BY game_name FOR UPDATE');$adding=array();
            foreach($games as$game_id=>$hash){
                $game=$selected[$game_id];if(empty($game['game_avail'])||!is_string($game['game_name'])||$game['game_name']===''||phpbb_arcade_text_limit($game['game_name'],50,50)!==$game['game_name']||!hash_equals(phpbb_arcade_game_revision($game),$hash)){$this->storage_fail('arcade_tournament_changed');}
                $name=$this->escape($game['game_name']);
                if(count($this->rows('SELECT game_id FROM '.iNA_GAMES." WHERE game_name='$name' LOCK IN SHARE MODE"))!==1){$this->storage_fail('arcade_tournament_changed');}
                // Use database collation for membership, just like the actual
                // composite key. A case/accent alias must never become IGNORE.
                if(!$this->rows('SELECT game_name FROM '.iNA_TOUR_DATA." WHERE tour_id=$id AND game_name='$name' FOR UPDATE")){$adding[]=$game['game_name'];}
            }
            if($adding&&count($existing)+count($adding)>(int)$settings['games_tournament_games']){$this->storage_fail('arcade_tournament_game_limit');}
            foreach($adding as$name){$this->query('INSERT INTO '.iNA_TOUR_DATA." (tour_id,game_name) VALUES($id,'".$this->escape($name)."')");if((int)$this->connection->sql_affectedrows()!==1){$this->storage_fail('no_game_data');}}
            if($adding){$this->audit($settings,$actor,'add-games',$id);}
            $result=array('added'=>count($adding));
        }elseif($operation==='delete'){
            $this->remove_children($id);$this->query('DELETE FROM '.iNA_TOUR.' WHERE tour_id='.$id);$this->audit($settings,$actor,'delete',$id);$result=array('deleted'=>true);
        }else{
            if((int)$tour['tour_active']===3){$this->storage_fail('arcade_tournament_changed');}
            $scores=$this->rows('SELECT game_name,top_score,top_player FROM '.iNA_TOUR_DATA.' WHERE tour_id='.$id.' ORDER BY game_name FOR UPDATE');$wins=array();
            foreach($scores as$row){if($row['top_score']===null){continue;}$player=$row['top_player'];
                if($player!==null&&(!preg_match('/^(?:0|-?[1-9][0-9]{0,6})$/D',(string)$player)||(int)$player<-8388608||(int)$player>8388607)){$this->storage_fail('no_tour_data');}
                $key=$player===null?'null':(string)$player;if(!isset($wins[$key])){$wins[$key]=array('id'=>(int)$player,'total'=>0);}if($player!==null){$wins[$key]['total']++;}
            }
            $wins=array_values($wins);usort($wins,function($a,$b){return $a['total']===$b['total']?($a['id']===$b['id']?0:($a['id']<$b['id']?-1:1)):($a['total']>$b['total']?-1:1);});
            // Preserve the existing draw policy: leave the event playable,
            // with all scores/members/capabilities intact and no messages.
            if(count($wins)>1&&$wins[0]['total']===$wins[1]['total']){return array('tour'=>$tour,'draw'=>true,'winner'=>0,'players'=>array());}
            $winner=$wins?$wins[0]['id']:0;$members=$this->rows('SELECT user_id FROM '.iNA_TOUR_PLAY.' WHERE tour_id='.$id.' ORDER BY user_id FOR UPDATE');$players=array();foreach($members as$member){if((int)$member['user_id']>0){$players[(int)$member['user_id']]=(int)$member['user_id'];}}
            $this->remove_children($id);
            if($winner>0){$this->query('UPDATE '.iNA_TOUR.' SET tour_active=3,end_date='.time().',champion='.$winner.' WHERE tour_id='.$id);}
            else{$this->query('DELETE FROM '.iNA_TOUR.' WHERE tour_id='.$id);}
            $this->audit($settings,$actor,'end',$id);$result=array('tour'=>$tour,'draw'=>false,'winner'=>$winner,'players'=>array_values($players));
        }
        $this->commit_attempted=true;$this->commit();return $result;
    }
}
function phpbb_arcade_tournament_action($database,$operation,$id,$tokens=array(),$revision='')
{
    global $arcade,$phpbb_root_path;phpbb_arcade_tournament_require_request();
    if(!in_array($operation,array('preview-end','end','delete','add'),true)||!is_int($id)||$id<1||$id>8388607||!is_string($revision)
        ||($operation==='preview-end'?$revision!=='':!preg_match('/^[a-f0-9]{64}$/D',$revision))||($operation!=='add'&&$tokens!==array())){throw new PhpbbOwnedStorageFailure('arcade_tournament_changed');}
    $games=$operation==='add'?phpbb_arcade_tournament_game_tokens($tokens):array();$writer=null;
    try{$writer=new PhpbbArcadeTournamentActionWriter($database,0);return $writer->act($operation,$id,$games,$revision);}
    finally{if($writer){try{if($writer->commit_attempted){try{$arcade->clear_cache('tournaments',$phpbb_root_path);}catch(Exception$ignored){}catch(Error$ignored){}}}finally{$writer->release();}}}
}
