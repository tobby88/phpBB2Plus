<?php
// Rebuild derived totals only; never rewrite games, scores or link visits.
if(PHP_SAPI!=='cli'){http_response_code(404);exit(2);}
if(!defined('IN_PHPBB')){define('IN_PHPBB',true);}
require_once __DIR__.'/innodb_migration.php';
require_once dirname(__DIR__).'/phpBB2/includes/functions_arcade_category_storage.php';

function plus_arcade_counters_plan($db,$prefix,$locked=false)
{
 if(!is_string($prefix)||!preg_match('/^[A-Za-z0-9_]{1,42}$/D',$prefix)){throw new RuntimeException('Exact Arcade counter prefix required');}
 $names=array('games'=>$prefix.'ina_games','categories'=>$prefix.'ina_cat');$present=0;
 foreach($names as$name){$meta=plus_storage_metadata($db,$name);if($meta===null){continue;}$present++;
  if($meta['TABLE_TYPE']!=='BASE TABLE'){throw new RuntimeException('Arcade counters require base tables');}
  if($locked){$r=plus_storage_query($db,'SELECT * FROM '.plus_storage_identifier($name).' LIMIT 0');mysqli_free_result($r);$meta=plus_storage_metadata($db,$name);
   if($meta===null||$meta['ENGINE']!=='InnoDB'||strtolower($meta['ROW_FORMAT'])!=='dynamic'||$meta['TABLE_COLLATION']!=='utf8mb4_unicode_ci'){throw new RuntimeException('Complete canonical storage migration before repairing Arcade counters');}
  }
 }
 if(!$present){return array('table'=>$names['categories'],'changes'=>array());}if($present!==2){throw new RuntimeException('Incomplete Arcade counter tables');}
 $games=plus_storage_identifier($names['games']);$cats=plus_storage_identifier($names['categories']);
 if($locked){$r=plus_storage_query($db,"SELECT game_id FROM $games ORDER BY game_id FOR UPDATE");mysqli_free_result($r);}
 $rows=plus_storage_rows($db,"SELECT * FROM $cats ORDER BY cat_id".($locked?' FOR UPDATE':''));
 if(!$rows&&!plus_storage_rows($db,"SELECT game_id FROM $games LIMIT 1")){return array('table'=>$names['categories'],'changes'=>array());}
 $layout=phpbb_arcade_category_layout($rows);$nodes=$layout['rows'];$totals=array();
 foreach($nodes as$id=>$row){$totals[$id]=array('games'=>'0','plays'=>'0');}
 if(plus_storage_rows($db,"SELECT game_id FROM $games WHERE game_id<>0 AND (played IS NULL OR played<0) LIMIT 1")){throw new RuntimeException('Missing/negative Arcade play history requires manual review');}
 foreach(plus_storage_rows($db,"SELECT cat_id,COUNT(CASE WHEN game_avail=1 THEN 1 END) games,COALESCE(SUM(CAST(played AS DECIMAL(20,0))),0) plays FROM $games WHERE game_id<>0 GROUP BY cat_id")as$group){
  $id=(int)$group['cat_id'];$totals[-1]['games']=phpbb_arcade_category_sum($totals[-1]['games'],$group['games']);$totals[-1]['plays']=phpbb_arcade_category_sum($totals[-1]['plays'],$group['plays']);
  if($id>0&&isset($nodes[$id])&&$nodes[$id]['cat_type']!=='l'){
   $totals[$id]['games']=(string)$group['games'];$totals[$id]['plays']=phpbb_arcade_category_sum($totals[$id]['plays'],$group['plays']);
   if($nodes[$id]['cat_type']==='s'){$parent=(int)$nodes[$id]['cat_parent'];$totals[$parent]['plays']=phpbb_arcade_category_sum($totals[$parent]['plays'],$group['plays']);}
  }
 }
 $changes=array();foreach($nodes as$id=>$row){if($row['cat_type']==='l'){continue;}$want=$totals[$id];
  if(strlen($want['games'])>10||(strlen($want['games'])===10&&strcmp($want['games'],'4294967295')>0)){throw new RuntimeException('Arcade game count exceeds canonical capacity');}
  if((string)$row['total_games']!==$want['games']||(string)$row['total_played']!==$want['plays']){$changes[$id]=array('old'=>array('games'=>$row['total_games'],'plays'=>$row['total_played']),'new'=>$want);}
 }
 return array('table'=>$names['categories'],'changes'=>$changes);
}
function plus_arcade_counters_print_plan($plan)
{
 echo 'Derived Arcade category counter changes: '.count($plan['changes'])."\n";
 foreach($plan['changes']as$id=>$change){echo 'UPDATE '.plus_storage_identifier($plan['table']).' SET total_games='.$change['new']['games'].',total_played='.$change['new']['plays'].' WHERE cat_id='.$id.";\n";}
}
function plus_arcade_counters_apply($db,$prefix,$backup,$maintenance)
{
 if($backup!==true||$maintenance!==true){throw new RuntimeException('Arcade counter repair requires a verified backup and stopped web/cron writers');}
 $owned=false;$mode=null;
 try{
  // SET TRANSACTION refuses caller-owned work before START could commit it.
  plus_storage_query($db,'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
  $row=plus_storage_rows($db,'SELECT @@SESSION.sql_mode AS mode');$mode=$row[0]['mode'];
  if(!preg_match('/^[A-Z0-9_,]*$/D',$mode)){throw new RuntimeException('Unexpected SQL mode');}
  plus_storage_query($db,"SET SESSION sql_mode=CONCAT_WS(',',@@SESSION.sql_mode,'STRICT_ALL_TABLES')");
  plus_storage_query($db,'START TRANSACTION');$owned=true;
  $plan=plus_arcade_counters_plan($db,$prefix,true);
  $table=mysqli_real_escape_string($db,$plan['table']);
  if(plus_storage_rows($db,"SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND HEX(EVENT_OBJECT_TABLE)=HEX('$table')")){throw new RuntimeException('Custom category triggers require manual review');}
  foreach($plan['changes']as$id=>$change){$want=$change['new'];plus_storage_query($db,'UPDATE '.plus_storage_identifier($plan['table']).' SET total_games='.$want['games'].',total_played='.$want['plays'].' WHERE cat_id='.$id);
   if(mysqli_affected_rows($db)!==1){throw new RuntimeException('Unexpected Arcade counter repair inventory');}
  }
  if(plus_arcade_counters_plan($db,$prefix,true)['changes']){throw new RuntimeException('Arcade counter verification failed');}
  try{plus_storage_query($db,'COMMIT');}catch(Exception$e){throw new RuntimeException('Arcade counter commit unconfirmed; inspect before retrying');}
  $owned=false;return count($plan['changes']);
 }finally{
  try{if($owned){@mysqli_query($db,'ROLLBACK');}}
  finally{if($mode!==null){plus_storage_query($db,"SET SESSION sql_mode='$mode'");}}
 }
}
function plus_arcade_counters_evict($forum_root)
{
 $cache=$forum_root.'/cache';if(is_link($cache)){throw new RuntimeException('Inspect linked Arcade cache directory before restarting writers');}
 $file=$forum_root.'/cache/arcade_categories.cache';
 if(is_link($file)||(file_exists($file)&&(!is_file($file)||!unlink($file)))){throw new RuntimeException('Counter repair committed; clear the Arcade category cache before restarting writers');}
}
