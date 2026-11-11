<?php
// Repair known last-play snapshots without inventing events or changing totals.
if(PHP_SAPI!=='cli'){http_response_code(404);exit(2);}
require_once __DIR__.'/arcade_counter_migration.php';
require_once dirname(__DIR__).'/phpBB2/includes/functions_arcade_last_play.php';

function plus_arcade_last_play_plan($db,$prefix,$locked=false)
{
 $counter=plus_arcade_counters_plan($db,$prefix,$locked);$table=$counter['table'];
 if(plus_storage_metadata($db,$table)===null){return array('table'=>$table,'changes'=>array());}
 $cats=plus_storage_identifier($table);$games_table=plus_storage_identifier($prefix.'ina_games');$categories=$games=array();
 foreach(plus_storage_rows($db,"SELECT * FROM $cats ORDER BY cat_id")as$row){$categories[(int)$row['cat_id']]=$row;}
 foreach(plus_storage_rows($db,"SELECT game_id,game_name,cat_id,game_avail FROM $games_table ORDER BY game_id")as$row){$games[(int)$row['game_id']]=$row;}
 $resolved=phpbb_arcade_last_play_resolved(plus_storage_rows($db,phpbb_arcade_last_play_resolution_sql($cats,$games_table)));
 return array('table'=>$table,'changes'=>phpbb_arcade_last_play_plan($categories,$games,$resolved));
}
function plus_arcade_last_play_sql($table,$id,$metadata)
{
 $parts=array();foreach($metadata as$key=>$value){$parts[]=plus_storage_identifier($key).'='.($value===null?'NULL':'CONVERT(0x'.bin2hex($value).' USING utf8mb4)');}
 return 'UPDATE '.plus_storage_identifier($table).' SET '.implode(',',$parts).' WHERE cat_id='.(int)$id;
}
function plus_arcade_last_play_print_plan($plan)
{
 echo 'Derived Arcade last-play changes: '.count($plan['changes'])."\n";foreach($plan['changes']as$id=>$metadata){echo plus_arcade_last_play_sql($plan['table'],$id,$metadata).";\n";}
}
function plus_arcade_last_play_apply($db,$prefix,$backup,$maintenance)
{
 if($backup!==true||$maintenance!==true){throw new RuntimeException('Last-play repair requires a verified backup and stopped web/cron writers');}
 $owned=false;$mode=null;
 try{
  plus_storage_query($db,'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$mode=plus_storage_rows($db,'SELECT @@SESSION.sql_mode mode')[0]['mode'];
  if(!preg_match('/^[A-Z0-9_,]*$/D',$mode)){throw new RuntimeException('Unexpected SQL mode');}
  plus_storage_query($db,"SET SESSION sql_mode=CONCAT_WS(',',@@SESSION.sql_mode,'STRICT_ALL_TABLES')");plus_storage_query($db,'START TRANSACTION');$owned=true;
  $plan=plus_arcade_last_play_plan($db,$prefix,true);$table=mysqli_real_escape_string($db,$plan['table']);
  if(plus_storage_rows($db,"SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND HEX(EVENT_OBJECT_TABLE)=HEX('$table')")){throw new RuntimeException('Custom category triggers require manual review');}
  foreach($plan['changes']as$id=>$metadata){plus_storage_query($db,plus_arcade_last_play_sql($plan['table'],$id,$metadata));if(mysqli_affected_rows($db)!==1){throw new RuntimeException('Unexpected last-play repair inventory');}}
  if(plus_arcade_last_play_plan($db,$prefix,true)['changes']){throw new RuntimeException('Last-play verification failed');}
  try{plus_storage_query($db,'COMMIT');}catch(Exception$e){throw new RuntimeException('Last-play commit unconfirmed; inspect before retrying');}
  $owned=false;return count($plan['changes']);
 }finally{try{if($owned){@mysqli_query($db,'ROLLBACK');}}finally{if($mode!==null){plus_storage_query($db,"SET SESSION sql_mode='$mode'");}}}
}
