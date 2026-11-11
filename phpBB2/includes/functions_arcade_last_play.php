<?php
if(!defined('IN_PHPBB')){die('Hacking attempt');}

function phpbb_arcade_last_play_time($value)
{
 if(!(is_string($value)||is_int($value))||!preg_match('/^[1-9][0-9]{0,19}$/D',(string)$value)){return null;}
 $value=(string)$value;if(strlen($value)===20&&strcmp($value,'18446744073709551615')>0){return null;}return $value;
}
function phpbb_arcade_last_play_player($value)
{
 return (is_string($value)||is_int($value))&&preg_match('/^(?:-1|[1-9][0-9]{0,6})$/D',(string)$value)&&((string)$value==='-1'||(int)$value<=8388607);
}
function phpbb_arcade_last_play_scope($id,$game,$categories)
{
 if(!isset($categories[$id])||!in_array($categories[$id]['cat_type'],array('p','s'),true)){return false;}
 if($id===-1){return true;}$direct=(int)$game['cat_id'];if($direct===$id){return true;}
 return $categories[$id]['cat_type']==='p'&&isset($categories[$direct])&&$categories[$direct]['cat_type']==='s'&&(int)$categories[$direct]['cat_parent']===$id;
}
function phpbb_arcade_last_play_resolution_sql($categories,$games)
{
 // Use the database's actual collation, including legacy case variants.
 return 'SELECT c.cat_id source_cat,g.game_id FROM '.$categories.' c LEFT JOIN '.$games.' g ON g.game_id>0 AND g.game_name=c.last_game ORDER BY c.cat_id,g.game_id';
}
function phpbb_arcade_last_play_resolved($rows)
{
 $matches=array();foreach($rows as$row){$id=(int)$row['source_cat'];if($row['game_id']!==null){$matches[$id][]=(int)$row['game_id'];}}
 $resolved=array();foreach($matches as$id=>$games){if(count($games)===1){$resolved[$id]=$games[0];}}return $resolved;
}
function phpbb_arcade_last_play_plan($categories,$games,$resolved,$affected=null,$renamed=array(),$snapshots=null)
{
 // Only existing snapshots supply a player/time. Offline history remains
 // stored; the request's renderer decides whether it can provide a game link.
 // Category deletion/type changes need their pre-change snapshots as sources,
 // while destination eligibility must use the current topology exclusively.
 ksort($categories,SORT_NUMERIC);$candidates=array();if($snapshots===null){$snapshots=$categories;}ksort($snapshots,SORT_NUMERIC);
 foreach($snapshots as$source=>$row){
  if(!in_array($row['cat_type'],array('p','s'),true)||!isset($resolved[$source],$games[$resolved[$source]],$row['last_game'],$row['last_player'],$row['last_time'])||!is_string($row['last_game'])||$row['last_game']===''||!phpbb_arcade_last_play_player($row['last_player'])){continue;}
  $time=phpbb_arcade_last_play_time($row['last_time']);if($time===null){continue;}$game_id=$resolved[$source];
  $candidates[]=array('source'=>$source,'game'=>$game_id,'last_game'=>isset($renamed[$game_id])?$renamed[$game_id]:$row['last_game'],'last_player'=>(string)$row['last_player'],'last_time'=>$time);
 }
 $changes=array();foreach($categories as$id=>$row){if($row['cat_type']==='l'||($affected!==null&&!isset($affected[$id]))){continue;}$latest=null;
  foreach($candidates as$candidate){if(!phpbb_arcade_last_play_scope($id,$games[$candidate['game']],$categories)){continue;}
   $new=$candidate['last_time'];$old=$latest===null?'0':$latest['last_time'];$comparison=strlen($new)-strlen($old);if($comparison===0){$comparison=strcmp($new,$old);}
   // An equally recent valid local snapshot wins without changing its bytes.
   if($latest===null||$comparison>0||($comparison===0&&$candidate['source']===$id&&$latest['source']!==$id)){$latest=$candidate;}
  }
  $want=$latest===null?array('last_game'=>null,'last_player'=>null,'last_time'=>null):array('last_game'=>$latest['last_game'],'last_player'=>$latest['last_player'],'last_time'=>$latest['last_time']);
  foreach($want as$key=>$value){if(($row[$key]===null?null:(string)$row[$key])!==$value){$changes[$id]=$want;break;}}
 }return $changes;
}
function phpbb_arcade_last_play_aliases()
{
 return array('last_game_cat_id','last_game_avail','last_game_rank','last_game_level','last_game_group','last_game_cat_group','last_game_parent_group','last_game_category_valid');
}
function phpbb_arcade_last_play_projection($row)
{
 $aliases=true;foreach(phpbb_arcade_last_play_aliases()as$key){if(!array_key_exists($key,$row)||($row[$key]!==null&&!is_string($row[$key])&&!is_int($row[$key]))){$aliases=false;}}
 $valid=$aliases&&isset($row['game_id'],$row['game_name'],$row['last_game_category_valid'])&&(is_string($row['game_id'])||is_int($row['game_id']))&&preg_match('/^[1-9][0-9]{0,6}$/D',(string)$row['game_id'])&&(int)$row['game_id']<=8388607
  &&is_string($row['game_name'])&&$row['game_name']!==''&&(!isset($row['game_desc'])||is_string($row['game_desc']))&&(string)$row['last_game_category_valid']==='1'
  &&isset($row['last_game_cat_id'])&&preg_match('/^(?:-1|0|[1-9][0-9]{0,6})$/D',(string)$row['last_game_cat_id'])&&(int)$row['last_game_cat_id']<=8388607
  &&isset($row['last_player'],$row['last_time'])&&phpbb_arcade_last_play_player($row['last_player'])&&phpbb_arcade_last_play_time($row['last_time'])!==null;
 if(!$valid){foreach(array_merge(array('last_game','last_player','last_time','game_name','game_id','game_desc'),phpbb_arcade_last_play_aliases())as$key){$row[$key]=null;}}
 return $row;
}
function phpbb_arcade_last_play_visible($row,$groups,$rank,$level,$admin)
{
 if(empty($row['last_player'])||empty($row['game_id'])||!isset($row['last_game_category_valid'])||(string)$row['last_game_category_valid']!=='1'){return false;}
 foreach(array('last_game_group','last_game_cat_group','last_game_parent_group','last_game_rank','last_game_level')as$key){if(!isset($row[$key])||!preg_match('/^(?:0|[1-9][0-9]{0,9})$/D',(string)$row[$key])){return false;}}
 if($admin){return true;}
 if(!is_array($groups)||(int)$row['last_game_rank']>(int)$rank||!in_array((int)$row['last_game_level'],$level==MOD?array(0,MOD):array(0),true)){return false;}
 foreach(array('last_game_group','last_game_cat_group','last_game_parent_group')as$key){if(!in_array($row[$key],$groups)){return false;}}return true;
}
function phpbb_arcade_last_play_link($row,$filename,$admin=false)
{
 $description=isset($row['game_desc'])&&is_string($row['game_desc'])&&$row['game_desc']!==''?$row['game_desc']:$row['game_name'];$label=arcade_output_html(phpbb_text_excerpt($description,25));
 if((string)$row['last_game_avail']!=='1'&&!$admin){return $label;}
 $category=isset($row['last_game_cat_id'])&&((int)$row['last_game_cat_id']>0)?(int)$row['last_game_cat_id']:0;
 $href=append_sid(htmlspecialchars($filename,ENT_QUOTES,'UTF-8').'?mode=cat&amp;cat_id='.$category);
 return '<a href="'.$href.'#'.(int)$row['game_id'].'" class="gensmall">'.$label.'</a>';
}
