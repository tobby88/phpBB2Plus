<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__).'/functions_arcade_tournament_storage.php';

function phpbb_arcade_tournament_post_int($request,$key,$default=0)
{
    if(!is_array($request)){throw new PhpbbOwnedStorageFailure('error_no_tour_info');}
    if(!array_key_exists($key,$request)){return $default;}
    $v=$request[$key];if(!is_string($v)||!preg_match('/^(?:0|[1-9][0-9]{0,9})$/D',$v)||(float)$v>2147483647){throw new PhpbbOwnedStorageFailure('error_no_tour_info');}
    return (int)$v;
}

function phpbb_arcade_tournament_values($values, $admin)
{
    if (!is_bool($admin) || !is_array($values) || array_keys($values)!==array('name','desc','players','turns','block','active')
        || !is_string($values['name']) || $values['name']==='' || phpbb_arcade_text_limit($values['name'],25)!==$values['name']
        || !is_string($values['desc']) || phpbb_arcade_text_limit($values['desc'],65535,65535)!==$values['desc']
        || (!$admin && (strlen($values['desc'])<3 || phpbb_arcade_text_limit($values['desc'],255)!==$values['desc']))) {
        throw new PhpbbOwnedStorageFailure('error_no_tour_info');
    }
    foreach (array('players','turns','block','active') as $key) {
        $v=$values[$key]; if (!(is_int($v)||is_string($v)) || !preg_match('/^(?:0|[1-9][0-9]{0,9})$/D',(string)$v) || (float)$v>2147483647) { throw new PhpbbOwnedStorageFailure('error_no_tour_info'); }
        $values[$key]=(int)$v;
    }
    if ($values['block']>1 || $values['active']>3 || (!$admin && $values['active']!==0)) { throw new PhpbbOwnedStorageFailure('error_no_tour_info'); }
    $values['turns']=min(10000,max(1,$values['turns'])); return $values;
}
function phpbb_arcade_tournament_revision($row)
{
    $copy=array();foreach(array('tour_id','tour_name','tour_desc','tour_max_players','tour_player_turns','block_plays','tour_active','start_id','start_date','end_date','champion')as$key){
        if(!is_array($row)||!array_key_exists($key,$row)||($row[$key]!==null&&!is_scalar($row[$key]))){throw new PhpbbOwnedStorageFailure('arcade_tournament_changed');}
        $copy[$key]=$row[$key]===null?null:(string)$row[$key];
    }
    $json=json_encode($copy);if($json===false){throw new PhpbbOwnedStorageFailure('arcade_tournament_changed');}return hash('sha256',$json);
}
function phpbb_arcade_tournament_setting_keys(){return array('games_tournament_max','games_tournament_games','games_tournament_players','games_tournament_user');}

class PhpbbArcadeTournamentWriter extends PhpbbArcadeTournamentJoinWriter
{
    protected function storage_tables(){return array_merge(parent::storage_tables(),array(JR_ADMIN_TABLE));}
    function sql_query($sql,$transaction=false){return $this->query($sql);}
    function sql_escape($value){return $this->escape($value);}
    function sql_fetchrow($r){return $this->connection->sql_fetchrow($r);}
    function sql_fetchrowset($r){return $this->connection->sql_fetchrowset($r);}
    function sql_freeresult($r){return $this->connection->sql_freeresult($r);}
    protected function limits($settings)
    {
        foreach(phpbb_arcade_tournament_setting_keys()as$key){
            $min=$key==='games_tournament_players'?2:($key==='games_tournament_user'?0:1);$max=$key==='games_tournament_user'?1:10000;
            if(!isset($settings[$key])||!preg_match('/^(?:0|[1-9][0-9]{0,4})$/D',$settings[$key])||(int)$settings[$key]<$min||(int)$settings[$key]>$max){$this->storage_fail('Owned_storage_upgrade');}
        }
    }
    protected function audit($settings,$actor,$action,$id=0)
    {
        if(!empty($settings['games_use_log'])){$this->query('INSERT INTO '.iNA_LOG.' (user_id,name,value,date) VALUES('.(int)$actor['user_id'].",'GAME','Tournament $action id=".(int)$id."',".time().')');}
    }
    function save($id,$values,$admin,$revision)
    {
        $actor=$this->current_actor($admin);$settings=$this->settings(true);$this->limits($settings);
        if(!$admin){$this->public_access($actor,$settings);if(empty($settings['games_tournament_user'])){$this->storage_fail('arcade_admin_only');}}
        $old=null;
        if($id){$rows=$this->rows('SELECT * FROM '.iNA_TOUR.' WHERE tour_id='.$id.' FOR UPDATE');if(count($rows)!==1||!hash_equals(phpbb_arcade_tournament_revision($rows[0]),$revision)){$this->storage_fail('arcade_tournament_changed');}$old=$rows[0];}
        // Lock the actual event inventory while deciding whether another
        // nonfinished event may be created or reactivated. Public and ACP
        // writers share both this transaction and the mutation lease.
        if($values['active']!==3&&(!$old||(int)$old['tour_active']===3)){
            $events=$this->rows('SELECT tour_id FROM '.iNA_TOUR.' WHERE tour_active<>3 ORDER BY tour_id FOR UPDATE');
            if(count($events)>=(int)$settings['games_tournament_max']){$this->storage_fail('arcade_tournament_limit');}
        }
        $maximum=(int)$settings['games_tournament_players'];$players=$values['players']<2?$maximum:min($maximum,$values['players']);
        if($old){$members=$this->rows('SELECT user_id FROM '.iNA_TOUR_PLAY.' WHERE tour_id='.$id.' ORDER BY user_id FOR UPDATE');if(count($members)>$players){$this->storage_fail('arcade_tournament_capacity');}}
        $assign="tour_name='".$this->escape($values['name'])."',tour_desc='".$this->escape($values['desc'])."',tour_max_players=$players,tour_player_turns=".$values['turns'].',block_plays='.$values['block'].',tour_active='.$values['active'];
        if($old){$this->query('UPDATE '.iNA_TOUR.' SET '.$assign.' WHERE tour_id='.$id);}
        else{
            $this->query('INSERT INTO '.iNA_TOUR.' SET '.$assign.',start_id='.(int)$actor['user_id'].',start_date='.time());
            $new=$this->connection->sql_nextid();if(!preg_match('/^[1-9][0-9]{0,6}$/D',(string)$new)||(int)$new>8388607){$this->storage_fail('no_tour_data');}$id=(int)$new;
            if(!$admin){$this->query('INSERT INTO '.iNA_TOUR_PLAY.' (tour_id,user_id) VALUES('.$id.','.(int)$actor['user_id'].')');if((int)$this->connection->sql_affectedrows()!==1){$this->storage_fail('no_tour_play_data');}}
        }
        $this->audit($settings,$actor,$old?'edit':'create',$id);
        $this->commit_attempted=true;$this->commit();return array('id'=>$id,'created'=>$old===null);
    }
    function configure($request)
    {
        $actor=$this->current_actor(true);$settings=$this->settings(true);$this->limits($settings);$values=array();
        foreach(phpbb_arcade_tournament_setting_keys()as$key){
            $v=array_key_exists($key,$request)?$request[$key]:$settings[$key];
            if(!is_string($v)||!preg_match('/^[0-9]{1,10}$/D',$v)){ $this->storage_fail('error_no_tour_info'); }
            $min=$key==='games_tournament_players'?2:($key==='games_tournament_user'?0:1);$max=$key==='games_tournament_user'?1:10000;
            $values[$key]=(string)min($max,max($min,(float)$v));
        }
        foreach($values as$key=>$v){$this->query('UPDATE '.iNA." SET config_value='$v' WHERE config_name='$key'");}
        $this->audit($settings,$actor,'settings');$this->commit_attempted=true;$this->commit();return $values;
    }
}
function phpbb_arcade_tournament_manage($database,$operation,$id,$values,$admin=false,$revision='')
{
    global $arcade,$phpbb_root_path;
    phpbb_arcade_tournament_require_request();
    if(!in_array($operation,array('save','settings'),true)||!is_bool($admin)||!is_int($id)||$id<0||$id>8388607
        ||!is_string($revision)||($id?!preg_match('/^[a-f0-9]{64}$/D',$revision):$revision!=='')
        ||($operation==='settings'&&($id!==0||$revision!==''))
        ||(!$admin&&($id!==0||$operation!=='save'))){throw new PhpbbOwnedStorageFailure('error_no_tour_info');}
    if($operation==='save'){$values=phpbb_arcade_tournament_values($values,$admin);}
    else{
        if(!is_array($values)||array_diff(array_keys($values),phpbb_arcade_tournament_setting_keys())){throw new PhpbbOwnedStorageFailure('error_no_tour_info');}
        foreach($values as$v){if(!is_string($v)||!preg_match('/^[0-9]{1,10}$/D',$v)){throw new PhpbbOwnedStorageFailure('error_no_tour_info');}}
    }
    $writer=null;
    try{$writer=new PhpbbArcadeTournamentWriter($database,0);return $operation==='save'?$writer->save($id,$values,$admin,$revision):$writer->configure($values);}
    finally{if($writer){try{if($writer->commit_attempted){foreach(array('tournaments','config')as$key){try{$arcade->clear_cache($key,$phpbb_root_path);}catch(Exception$ignored){}catch(Error$ignored){}}}}finally{$writer->release();}}}
}
