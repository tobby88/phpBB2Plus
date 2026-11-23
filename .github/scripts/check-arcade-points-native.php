<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_ARCADE_POINTS_NATIVE')!=='1'){echo "Arcade points checks require an explicitly enabled disposable database.\n";return;}
require __DIR__.'/arcade-points-fixture.php';require $ap_app.'db/mysqli.php';
class ArcadePointsNativeDatabase extends sql_db
{
 var $setup=true;var $opened=0;var $fail_alias=false;
 function sql_query($sql='',$transaction=false){if(!$this->setup&&!preg_match('/^SELECT\b/i',ltrim($sql))){throw new RuntimeException('Points rendering must remain read-only');}if($this->fail_alias&&strpos($sql,'SELECT n.author_user_id FROM ')===0){return false;}$r=parent::sql_query($sql,$transaction);if($r&&!$this->setup){$this->opened++;}return $r;}
 function sql_freeresult($r=false){if(!$this->setup){$this->opened--;}return parent::sql_freeresult($r);}
}
function pn_sql($sql){$r=$GLOBALS['db']->sql_query($sql);ap_check($r!==false,'Own canonical points fixture SQL');return $r;}
function pn_change($sql){$GLOBALS['db']->setup=true;try{return pn_sql($sql);}finally{$GLOBALS['db']->setup=false;}}
function pn_alias($id,$raw){$db=$GLOBALS['db'];pn_change("INSERT INTO fixture_removed_author_names(author_user_id,author_name_hash,author_name,recorded_at) VALUES(".(int)$id.",'".hash('sha256',$raw)."','".$db->sql_escape($raw)."',1)");}
function pn_monthly($game,$id,$name,$year,$month,$score,$date)
{
 $db=$GLOBALS['db'];
 pn_change("INSERT INTO fixture_ina_highscore(highscore_year,highscore_mon,highscore_game,highscore_user_id,highscore_player,highscore_score,highscore_date) VALUES(".
  (int)$year.','.(int)$month.",'".$db->sql_escape($game)."',".(int)$id.",'".$db->sql_escape($name)."',".(int)$score.','.(int)$date.')');
}
function pn_snapshot(){global $db;$data=array();foreach(array('users','ina_highscore','ina_at_scores','ina_games','removed_author_names')as$table){$r=pn_sql('SELECT * FROM fixture_'.$table);$rows=$db->sql_fetchrowset($r);$db->sql_freeresult($r);$rows=array_map('json_encode',$rows);sort($rows);$data[$table]=$rows;}return $data;}
$db=$control=null;$created=false;$schema='codex_arc_points_'.bin2hex(phpbb_random_bytes(8));
try{
 $port=getenv('PHPBB_ARCADE_POINTS_PORT')?:'3306';$password=getenv('PHPBB_ARCADE_POINTS_PASSWORD')?:'';ap_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Own loopback port');$host='127.0.0.1:'.$port;$control=new sql_db($host,'root',$password,'',false);ap_check((bool)$control->db_connect_id,'Own local server connection');ap_check(preg_match('/^codex_arc_points_[a-f0-9]{16}$/D',$schema)&&$control->sql_query('CREATE DATABASE '.$schema.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Exclusive points schema');$created=true;
 $db=new ArcadePointsNativeDatabase($host,'root',$password,$schema,false);ap_check((bool)$db->db_connect_id,'Own schema connection');$canonical=file_get_contents($ap_app.'install/schemas/mysql_schema.sql');foreach(array('users','ina_highscore','ina_at_scores','ina_games','removed_author_names')as$table){ap_check(preg_match('/CREATE TABLE phpbb_'.preg_quote($table,'/').' \(.*?\) ENGINE=InnoDB[^;]*;/s',$canonical,$m)===1,'Exact canonical schema');pn_sql(str_replace('phpbb_'.$table,'fixture_'.$table,$m[0]));}
 pn_sql("SET SESSION sql_mode='STRICT_ALL_TABLES,ONLY_FULL_GROUP_BY,NO_BACKSLASH_ESCAPES'");
 pn_sql("INSERT INTO fixture_users(user_id,username,user_password) VALUES(7,'Current',''),(9,'Legacy former',''),(41,'Alive recorded','')");pn_sql("INSERT INTO fixture_ina_games(game_name,game_desc,reverse_list,game_avail,game_show_score) VALUES('a','Descending tie',0,1,1),('b','Descending',0,1,1),('c','Ascending',1,1,1),('d','Ascending zero',1,1,1),('e','Unavailable',0,0,1),('f','Hidden scores',0,1,0)");$db->setup=false;
 pn_alias(40,'Old name');pn_alias(0,'Legacy former');pn_alias(41,'Alive recorded');
 foreach(array(array(40,'Old name',2026,1),array(40,'Old name',2026,1),array(40,'Legacy former',2025,12),array(7,'Old current A',2026,1),array(7,'Old current B',2025,12),array(0,'Guest A',2026,1),array(0,'Guest A',2025,12),array(0,'Guest B',2026,1),array(9,'Old reused',2026,1),array(41,'Old recorded',2026,1),array(40,'OUTSIDE PERIOD',2025,11),array(7,'FUTURE',2026,2))as$i=>$row){
  $game='fixture_'.$i;
  pn_change("INSERT INTO fixture_ina_games(game_name,game_avail,game_show_score) VALUES('$game',1,1)");
  pn_monthly($game,$row[0],$row[1],$row[2],$row[3],1,1);
 }
 foreach(array(array('a',7,100,2),array('a',9,100,3),array('a',99,999,1),array('b',9,200,1),array('b',7,100,1),array('c',7,1,1),array('c',9,5,1),array('d',41,0,1),array('d',7,2,1),array('e',7,999,1),array('f',7,999,1),array('orphan',7,999,1))as$row){pn_change("INSERT INTO fixture_ina_at_scores(game_name,player_id,score,date) VALUES('".$row[0]."',".$row[1].','.$row[2].','.$row[3].')');}
 foreach(array('english','german')as$locale){ap_language($locale);foreach(array('A&B',"O'Reilly",'&#39;','Grüße 😀')as$raw){foreach(array(ENT_QUOTES,ENT_COMPAT)as$flags){pn_change("UPDATE fixture_users SET username='".$db->sql_escape(phpbb_username_key($raw,$flags))."' WHERE user_id=7");foreach(array(array(false,0),array(false,1),array(true,0),array(true,1))as$viewer){$userdata=array('user_id'=>$viewer[0]?3:-1,'session_logged_in'=>$viewer[0]);$board_config=array('hide_removed_author_names'=>$viewer[1]);$before=pn_snapshot();$out=ap_run();$t=$out['totals'];$labels=ap_labels($out);$mask=!$viewer[0]&&$viewer[1];
  ap_check(count($t)===6&&$t['u:40']['monthly']===3&&$t['u:40']['total']===3,'Real grouped historical aliases accumulate all three monthly records');ap_check($t['u:7']['monthly']===2&&$t['u:7']['all_time']===2&&$t['u:7']['total']===10,'Current account JOIN coalesces old snapshots and counts both winner directions');ap_check($t['u:9']['all_time']===1&&$t['u:41']['all_time']===1&&!isset($t['u:99']),'Per-game score/date ties, availability and missing-account joins select only valid winners');ap_check($t['n:Guest A']['monthly']===2&&$t['n:Guest B']['monthly']===1,'Genuine name-only owners remain distinct');ap_check($labels['u:7']===$raw&&$labels['u:9']==='Legacy former'&&$labels['u:41']==='Alive recorded','Canonical and historical account keys display once; current reused/live aliases remain visible');ap_check($mask?$labels['u:40']===$lang['Former_member']:in_array($labels['u:40'],array('Old name','Legacy former'),true),'Former stable owner follows current viewer privacy');
  $keys=array_keys($t);ap_check($keys===array('u:7','u:41','u:9','u:40','n:Guest A','n:Guest B'),'Actual weighted points/all-time/name tie ordering preserved');ap_check(pn_snapshot()===$before&&$db->opened===0,'Full native aggregation/rendering preserves every row and frees SELECT results');$ap_cases++;
 }}}}
 $userdata=array('user_id'=>3,'session_logged_in'=>true);$board_config=array('hide_removed_author_names'=>1);$out=ap_run(2026,1,false);ap_check($out['totals']['u:40']['monthly']===2&&$out['totals']['u:7']['monthly']===1&&$out['totals']['n:Guest A']['monthly']===1,'Actual SQL excludes the previous year when the optional previous month is disabled');$ap_cases++;
 pn_change("INSERT INTO fixture_ina_games(game_name,reverse_list,game_avail,game_show_score) VALUES('monthly-desc',0,0,1),('monthly-desc',0,1,1),('MONTHLY-DESC',1,1,1),('monthly-asc',1,1,1),('alltime-tie',0,1,1),('ALLTIME-TIE',1,1,1)");
 foreach(array('e','f','orphan','')as$game){pn_monthly($game,0,'Excluded monthly owner',2026,3,999,1);}
 pn_monthly('monthly-desc',9,'Later ID',2026,3,100,1);
 pn_monthly('monthly-desc',7,'Same score, later date',2026,3,100,2);
 pn_monthly('monthly-desc',7,'Decisive tie',2026,3,100,1);
 pn_monthly('monthly-desc',7,'Duplicate winning ID',2026,3,100,1);
 pn_monthly('monthly-desc',41,'Other month',2026,2,200,4);
 pn_monthly('monthly-asc',7,'Higher score loses',2026,3,100,1);
 pn_monthly('monthly-asc',9,'Zero wins',2026,3,0,2);
 pn_change("INSERT INTO fixture_ina_at_scores(game_name,player_id,score,date) VALUES('alltime-tie',9,100,1),('alltime-tie',7,100,1)");
 $before=pn_snapshot();$march=ap_run(2026,3,false)['totals'];$both=ap_run(2026,3,true)['totals'];
 ap_check(!isset($march['n:Excluded monthly owner']),'Monthly points exclude unavailable games, hidden scores and orphaned game names');
 ap_check($march['u:7']['monthly']===1&&$march['u:9']['monthly']===1&&$march['u:41']['monthly']===0,'Each game name awards one monthly point using the earliest eligible configuration and the score/date/account/row tie breakers');
 ap_check($both['u:7']['monthly']===2&&$both['u:41']['monthly']===1,'A game can award a separate point for each included month');
 ap_check($march['u:7']['all_time']===3&&$march['u:9']['all_time']===1,'All-time exact ties resolve to the same owner as the Arcade ranking');
 ap_check(pn_snapshot()===$before&&$db->opened===0,'Historical duplicate scores stay intact and all ranking reads are released');$ap_cases++;
 pn_alias(7,'Current removed');pn_change('DELETE FROM fixture_users WHERE user_id=7');$previous=$db;$db=new ArcadePointsNativeDatabase($host,'root',$password,$schema,false);ap_check((bool)$db->db_connect_id,'Fresh viewer connection after account retirement');$db->setup=false;$previous->sql_close();$userdata=array('user_id'=>-1,'session_logged_in'=>false);$out=ap_run();$t=$out['totals'];$labels=ap_labels($out);ap_check($t['u:7']['monthly']===2&&$t['u:7']['all_time']===0&&$labels['u:7']===$lang['Former_member'],'After native deletion, snapshot aliases still aggregate and remain anonymous while winners fall back to extant accounts');$ap_cases++;
 $previous_failure=$db;$db=new ArcadePointsNativeDatabase($host,'root',$password,$schema,false);ap_check((bool)$db->db_connect_id,'Fresh viewer for native alias failure');$db->setup=false;$previous_failure->sql_close();$db->fail_alias=true;$caught=false;try{ap_run();}catch(ArcadePointsReadFailure$e){$caught=$e->getMessage()===$lang['Author_privacy_unavailable'];}ap_check($caught&&$db->opened===0,'Native privacy storage failure never falls back to exposing protected names');$ap_cases++;
 echo 'Arcade points native: ',$ap_cases,' cases, ',$ap_checks," assertions PASS; actual canonical aggregation and winner queries, historical owners, period rollover, renames/deletion, score/date ties, game filters, privacy and read-only history.\n";
}finally{if($db){$db->sql_close();}if($created){ap_check(preg_match('/^codex_arc_points_[a-f0-9]{16}$/D',$schema)&&$control->sql_query('DROP DATABASE '.$schema),'Exact own points schema cleanup');}if($control){$control->sql_close();}restore_error_handler();}
