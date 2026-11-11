<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__).'/functions_arcade_import_storage.php';
require_once dirname(__FILE__).'/functions_username.php';

function phpbb_arcade_category_fields()
{
    return array('cat_id','cat_name','cat_desc','cat_icon','special_play','group_required','mod_id','cat_type','cat_parent','cat_order');
}
function phpbb_arcade_category_revision($row)
{
    $out=array();foreach(phpbb_arcade_category_fields()as$key){if(!array_key_exists($key,$row)||($row[$key]!==null&&!is_scalar($row[$key]))){throw new PhpbbOwnedStorageFailure('arcade_category_changed');}$out[$key]=$row[$key]===null?null:(string)$row[$key];}
    $json=json_encode($out);if($json===false){throw new PhpbbOwnedStorageFailure('arcade_category_changed');}return hash('sha256',$json);
}
function phpbb_arcade_category_tree_revision($rows)
{
    $out=array();foreach($rows as$row){$out[(int)$row['cat_id']]=array((int)$row['cat_id'],(string)$row['cat_type'],(int)$row['cat_parent'],(int)$row['cat_order']);}ksort($out,SORT_NUMERIC);
    return hash('sha256',json_encode(array_values($out)));
}
function phpbb_arcade_category_layout($rows,$repair=false)
{
    $nodes=array();$changed=false;
    foreach($rows as$row){$id=(int)$row['cat_id'];if(($id!==-1&&($id<1||$id>8388607))||isset($nodes[$id])){throw new PhpbbOwnedStorageFailure('arcade_category_repair');}$nodes[$id]=$row;}
    if(!isset($nodes[-1])||$nodes[-1]['cat_type']!=='p'){throw new PhpbbOwnedStorageFailure('Owned_storage_upgrade');}
    foreach($nodes as$id=>$row){if($id===-1){continue;}$parent=(int)$row['cat_parent'];$bad=!in_array($row['cat_type'],array('p','s','l'),true)||($row['cat_type']==='s'?($parent===$id||!isset($nodes[$parent])||$parent<1||$nodes[$parent]['cat_type']!=='p'):$parent!==0);
        if($bad){if(!$repair){throw new PhpbbOwnedStorageFailure('arcade_category_repair');}$nodes[$id]['cat_type']='p';$nodes[$id]['cat_parent']='0';$changed=true;}}
    $roots=$children=array();foreach($nodes as$id=>$row){if($id===-1){continue;}if($row['cat_type']==='s'){$children[(int)$row['cat_parent']][]=$id;}else{$roots[]=$id;}}
    $compare=function($a,$b)use($nodes){$x=(int)$nodes[$a]['cat_order'];$y=(int)$nodes[$b]['cat_order'];return $x===$y?($a===$b?0:($a<$b?-1:1)):($x<$y?-1:1);};usort($roots,$compare);foreach($children as&$list){usort($list,$compare);}unset($list);
    $order=array(-1);foreach($roots as$id){$order[]=$id;if(isset($children[$id])){foreach($children[$id]as$child){$order[]=$child;}}}
    return array('rows'=>$nodes,'roots'=>$roots,'children'=>$children,'order'=>$order,'repaired'=>$changed);
}
function phpbb_arcade_category_input($request)
{
    if(!is_array($request)){throw new PhpbbOwnedStorageFailure('arcade_category_invalid');}
    $actions=array_intersect(array_keys($request),array('cat_action','save_cat','resync_cats'));
    if(count($actions)!==1){throw new PhpbbOwnedStorageFailure('arcade_category_invalid');}
    $action=reset($actions);$allowed=array('sid','tree_revision',$action);$input=array('operation'=>$action==='save_cat'?'save':($action==='resync_cats'?'resync':''),'id'=>0,'revision'=>'','values'=>null);
    if($action==='save_cat'){$allowed=array_merge($allowed,array('cat_id','cat_revision','cat_order','name','desc','icon','cat_type','cat_parent','group_required','moderator','special'));}
    foreach($request as$key=>$value){if(!in_array($key,$allowed,true)||!is_string($value)){throw new PhpbbOwnedStorageFailure('arcade_category_invalid');}}
    if(!isset($request['tree_revision'])||!preg_match('/^[a-f0-9]{64}$/D',$request['tree_revision'])){throw new PhpbbOwnedStorageFailure('arcade_category_changed');}$input['tree_revision']=$request['tree_revision'];
    if($action==='cat_action'){
        if(!preg_match('/^(cat_up|cat_down|del_cat):([1-9][0-9]{0,6}):([a-f0-9]{64})$/D',$request[$action],$m)||(int)$m[2]>8388607){throw new PhpbbOwnedStorageFailure('arcade_category_changed');}
        $input['operation']=$m[1];$input['id']=(int)$m[2];$input['revision']=$m[3];
    }elseif($action==='save_cat'){
        foreach(array('cat_id'=>8388607,'cat_parent'=>8388607,'group_required'=>8388607,'special'=>32767,'cat_order'=>16777215)as$key=>$maximum){if(!isset($request[$key])||!preg_match('/^(?:0|[1-9][0-9]{0,7})$/D',$request[$key])||(float)$request[$key]>$maximum){throw new PhpbbOwnedStorageFailure('arcade_category_invalid');}}
        $input['id']=(int)$request['cat_id'];$input['revision']=isset($request['cat_revision'])?$request['cat_revision']:'';
        if($input['id']?!preg_match('/^[a-f0-9]{64}$/D',$input['revision']):$input['revision']!==''){throw new PhpbbOwnedStorageFailure('arcade_category_changed');}
        foreach(array('name'=>100,'desc'=>65535,'icon'=>255,'moderator'=>255)as$key=>$maximum){if(!isset($request[$key])){throw new PhpbbOwnedStorageFailure('arcade_category_invalid');}$request[$key]=trim(phpbb_request_raw_value($request[$key]));if(phpbb_arcade_text_limit($request[$key],$maximum,$key==='desc'?65535:0)!==$request[$key]){throw new PhpbbOwnedStorageFailure('arcade_category_invalid');}}
        if($request['name']===''||!isset($request['cat_type'])||!in_array($request['cat_type'],array('p','s','l'),true)){throw new PhpbbOwnedStorageFailure('arcade_category_invalid');}
        $icon=$request['icon'];if($icon!==''&&(phpbb_arcade_local_asset($icon)!==$icon||preg_match('/[\x00-\x20\x7f%?#]/',$icon)||!preg_match('/\.(?:gif|jpe?g|png|webp)$/iD',$icon))){throw new PhpbbOwnedStorageFailure('arcade_category_invalid');}
        if($request['cat_type']==='l'){$url=phpbb_normalize_external_url($request['desc']);if($url===false){throw new PhpbbOwnedStorageFailure('game_link_error');}$request['desc']=$url;}
        $input['values']=array('cat_name'=>$request['name'],'cat_desc'=>$request['desc'],'cat_icon'=>$icon,'special_play'=>$request['special'],'group_required'=>$request['group_required'],'cat_type'=>$request['cat_type'],'cat_parent'=>$request['cat_type']==='s'?$request['cat_parent']:'0','moderator'=>$request['moderator'],'cat_order'=>$request['cat_order']);
    }
    return $input;
}
function phpbb_arcade_category_sum($a,$b)
{
    if(!preg_match('/^[0-9]{1,20}$/D',(string)$a)||!preg_match('/^[0-9]{1,20}$/D',(string)$b)){throw new PhpbbOwnedStorageFailure('arcade_category_invalid');}
    $a=strrev((string)$a);$b=strrev((string)$b);$out='';$carry=0;for($i=0;$i<max(strlen($a),strlen($b));$i++){$n=$carry+($i<strlen($a)?(int)$a[$i]:0)+($i<strlen($b)?(int)$b[$i]:0);$out.=($n%10);$carry=(int)floor($n/10);}if($carry){$out.=$carry;}$out=ltrim(strrev($out),'0');if($out===''){$out='0';}if(strlen($out)>20||(strlen($out)===20&&strcmp($out,'18446744073709551615')>0)){throw new PhpbbOwnedStorageFailure('arcade_category_invalid');}return $out;
}

class PhpbbArcadeCategoryWriter extends PhpbbArcadeImportWriter
{
    protected function actor(){global$phpEx;return phpbb_acp_actor($this,'admin_arcade_cats.'.$phpEx);}
    protected function storage_tables(){return array(USERS_TABLE,SESSIONS_TABLE,JR_ADMIN_TABLE,iNA_GAMES,iNA_CAT,GROUPS_TABLE);}
    protected function counters($nodes,$before)
    {
        // Visible game counts are direct assignments, matching category lists.
        // Historical plays include offline games and, for a parent, its child
        // categories, matching the launch writer's counter increments.
        $groups=$this->rows('SELECT cat_id,COUNT(CASE WHEN game_avail=1 THEN 1 END) games,COALESCE(SUM(CAST(played AS DECIMAL(20,0))),0) plays FROM '.iNA_GAMES.' WHERE game_id<>0 GROUP BY cat_id');
        $totals=array();foreach($nodes as$id=>$row){$totals[$id]=array('games'=>'0','plays'=>'0');}
        foreach($groups as$row){$cat=(int)$row['cat_id'];$totals[-1]['games']=phpbb_arcade_category_sum($totals[-1]['games'],$row['games']);$totals[-1]['plays']=phpbb_arcade_category_sum($totals[-1]['plays'],$row['plays']);if($cat>0&&isset($nodes[$cat])&&$nodes[$cat]['cat_type']!=='l'){$totals[$cat]['games']=(string)$row['games'];$totals[$cat]['plays']=phpbb_arcade_category_sum($totals[$cat]['plays'],$row['plays']);$parent=(int)$nodes[$cat]['cat_parent'];if($parent>0){$totals[$parent]['plays']=phpbb_arcade_category_sum($totals[$parent]['plays'],$row['plays']);}}}
        $games=array();foreach($before as$candidate){$name=$candidate['last_game'];if($name!==null&&!array_key_exists($name,$games)){$match=$this->rows('SELECT cat_id FROM '.iNA_GAMES." WHERE game_name='".$this->escape($name)."' AND game_id<>0 LIMIT 2");$games[$name]=count($match)===1?(int)$match[0]['cat_id']:null;}}
        foreach($nodes as$id=>$row){
            if($row['cat_type']==='l'){$totals[$id]['plays']=isset($before[$id])&&$before[$id]['cat_type']==='l'?(string)$before[$id]['total_played']:'0';}
            if(strlen($totals[$id]['games'])>10||(float)$totals[$id]['games']>4294967295){$this->storage_fail();}
            $latest=$row['cat_type']==='l'&&isset($before[$id])&&$before[$id]['cat_type']==='l'?$before[$id]:null;if($row['cat_type']!=='l'){foreach($before as$candidate){$name=$candidate['last_game'];if($name===null||!isset($games[$name])){continue;}$cat=$games[$name];$belongs=$id===-1||$cat===$id||($cat>0&&isset($nodes[$cat])&&(int)$nodes[$cat]['cat_parent']===$id);if($belongs&&(int)$candidate['last_time']>0&&($latest===null||(int)$candidate['last_time']>(int)$latest['last_time'])){$latest=$candidate;}}}
            $set="total_games='".$totals[$id]['games']."',total_played='".$totals[$id]['plays']."'";
            foreach(array('last_game','last_player','last_time')as$key){$value=$latest===null?null:$latest[$key];$set.=','.$key.'='.($value===null?'NULL':"'".$this->escape((string)$value)."'");}
            $this->query('UPDATE '.iNA_CAT.' SET '.$set.' WHERE cat_id='.$id);
        }
    }
    function change($input)
    {
        global $userdata;
        $actor=$this->actor();$sid=$this->escape($userdata['session_id']);foreach(array('SELECT session_id FROM '.SESSIONS_TABLE." WHERE session_id='$sid' AND HEX(session_id)=HEX('$sid')",'SELECT user_id FROM '.USERS_TABLE.' WHERE user_id='.(int)$actor['user_id'],'SELECT user_id FROM '.JR_ADMIN_TABLE.' WHERE user_id='.(int)$actor['user_id'])as$sql){$this->rows($sql.' LOCK IN SHARE MODE');}$this->actor();
        // Same game -> category lock order as launch/catalogue mutations.
        $this->rows('SELECT game_id FROM '.iNA_GAMES.' ORDER BY game_id FOR UPDATE');
        $rows=$this->rows('SELECT * FROM '.iNA_CAT.' ORDER BY cat_id FOR UPDATE');
        if(!hash_equals(phpbb_arcade_category_tree_revision($rows),$input['tree_revision'])){$this->storage_fail('arcade_category_changed');}
        $layout=phpbb_arcade_category_layout($rows,$input['operation']==='resync');$nodes=$layout['rows'];$before=array();foreach($rows as$row){$before[(int)$row['cat_id']]=$row;}
        $id=$input['id'];$operation=$input['operation'];$recount=$operation==='resync';$game_change=false;
        if($id&&(!isset($nodes[$id])||!hash_equals(phpbb_arcade_category_revision($nodes[$id]),$input['revision']))){$this->storage_fail('arcade_category_changed');}
        if($operation==='save'){
            $values=$input['values'];if((string)$values['cat_order']!==($id?(string)$before[$id]['cat_order']:'0')){$this->storage_fail('arcade_category_changed');}unset($values['cat_order']);
            $parent=(int)$values['cat_parent'];if($values['cat_type']==='s'&&($parent===$id||$parent<1||!isset($nodes[$parent])||$nodes[$parent]['cat_type']!=='p')){$this->storage_fail('admin_cat_no_parent');}
            if((int)$values['group_required']>0&&!$this->rows('SELECT group_id FROM '.GROUPS_TABLE.' WHERE group_id='.(int)$values['group_required'].' AND group_single_user=0 LOCK IN SHARE MODE')){$this->storage_fail('arcade_category_invalid');}
            $mod=0;if($values['moderator']!==''){$user=phpbb_username_lookup($this,$values['moderator'],array('user_id'),'LOCK IN SHARE MODE');if(!$user||(int)$user['user_id']<1){$this->storage_fail('no_game_save');}$mod=(int)$user['user_id'];}unset($values['moderator']);$values['mod_id']=(string)$mod;
            $recount=!$id||$nodes[$id]['cat_type']!==$values['cat_type']||(int)$nodes[$id]['cat_parent']!==$parent;
            if($id){if($values['cat_type']!=='p'){foreach($nodes as$child=>$row){if((int)$row['cat_parent']===$id){$nodes[$child]['cat_parent']='0';$nodes[$child]['cat_type']='p';}}}$nodes[$id]=array_replace($nodes[$id],$values);}
            else{
                $assign=array();foreach($values as$key=>$value){$assign[]=$key."='".$this->escape($value)."'";}$this->query('INSERT INTO '.iNA_CAT.' SET '.implode(',',$assign));$new=$this->connection->sql_nextid();if(!preg_match('/^[1-9][0-9]{0,6}$/D',(string)$new)||(int)$new>8388607){$this->storage_fail();}$id=(int)$new;
                $fresh=$this->rows('SELECT * FROM '.iNA_CAT.' WHERE cat_id='.$id);if(count($fresh)!==1){$this->storage_fail();}$nodes[$id]=$fresh[0];$maximum=0;foreach($nodes as$row){$maximum=max($maximum,(int)$row['cat_order']);}$nodes[$id]['cat_order']=$maximum+1;
            }
            if($values['cat_type']==='l'){$this->query('UPDATE '.iNA_GAMES.' SET cat_id=-1 WHERE cat_id='.$id);$game_change=(int)$this->connection->sql_affectedrows()>0;$recount=$recount||$game_change;}
        }elseif($operation==='del_cat'){
            foreach($nodes as$child=>$row){if((int)$row['cat_parent']===$id){$nodes[$child]['cat_parent']='0';$nodes[$child]['cat_type']='p';}}
            $this->query('UPDATE '.iNA_GAMES.' SET cat_id=-1 WHERE cat_id='.$id);$game_change=(int)$this->connection->sql_affectedrows()>0;
            $this->query('DELETE FROM '.iNA_CAT.' WHERE cat_id='.$id);if((int)$this->connection->sql_affectedrows()!==1){$this->storage_fail('arcade_category_changed');}unset($nodes[$id]);$recount=true;
        }elseif($operation==='resync'){
            $valid=array();foreach($nodes as$cat=>$row){if($row['cat_type']!=='l'){$valid[]=$cat;}}
            $this->query('UPDATE '.iNA_GAMES.' SET cat_id=-1 WHERE cat_id IS NULL OR cat_id NOT IN ('.implode(',',$valid).')');$game_change=(int)$this->connection->sql_affectedrows()>0;
        }
        $layout=phpbb_arcade_category_layout(array_values($nodes));
        if($operation==='cat_up'||$operation==='cat_down'){
            $parent=(int)$nodes[$id]['cat_parent'];$siblings=$nodes[$id]['cat_type']==='s'?(isset($layout['children'][$parent])?$layout['children'][$parent]:array()):$layout['roots'];$at=array_search($id,$siblings,true);$next=$at+($operation==='cat_up'?-1:1);
            if($at===false){$this->storage_fail('arcade_category_changed');}if(isset($siblings[$next])){$siblings[$at]=$siblings[$next];$siblings[$next]=$id;}
            if($nodes[$id]['cat_type']==='s'){$layout['children'][$parent]=$siblings;}else{$layout['roots']=$siblings;}
        }
        // Compact preorder fits the existing unsigned MEDIUMINT even for high
        // category identities. Moving a parent always carries its children.
        $order=array(-1);foreach($layout['roots']as$cat){$order[]=$cat;if(isset($layout['children'][$cat])){foreach($layout['children'][$cat]as$child){$order[]=$child;}}}
        foreach($order as$position=>$cat){$nodes[$cat]['cat_order']=(string)$position;$assign=array();foreach(array('cat_name','cat_desc','cat_icon','special_play','group_required','mod_id','cat_type','cat_parent','cat_order')as$key){$value=$nodes[$cat][$key];if(isset($before[$cat])&&$value===$before[$cat][$key]){continue;}$assign[]=$key.'='.($value===null?'NULL':"'".$this->escape((string)$value)."'");}if($assign){$this->query('UPDATE '.iNA_CAT.' SET '.implode(',',$assign).' WHERE cat_id='.$cat);}}
        if($recount){$this->counters($nodes,$before);}
        $this->commit_attempted=true;$this->commit();return array('operation'=>$operation,'id'=>$id,'games_changed'=>$game_change);
    }
}
function phpbb_arcade_category_change($database,$request)
{
    global $arcade,$phpbb_root_path;$input=phpbb_arcade_category_input($request);phpbb_attach_quota_post($request);if(!phpbb_request_source_is_same_origin()){throw new PhpbbOwnedStorageFailure('Session_invalid');}
    $writer=new PhpbbArcadeCategoryWriter($database);
    try{return $writer->change($input);}
    finally{try{if($writer->commit_attempted){foreach(array('categories','games')as$key){try{$arcade->clear_cache($key,$phpbb_root_path);}catch(Exception$ignored){}catch(Error$ignored){}}}}finally{$writer->release();}}
}
