<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_ARCADE_TOUR_LABEL_NATIVE')!=='1'){echo "Tournament label checks require an explicitly enabled disposable database.\n";return;}
require __DIR__.'/arcade-tournament-fixture.php';require $as_app.'db/mysqli.php';
as_function($as_app.'includes/functions.php','generate_pagination');
class ArcadeTournamentChampionRequest
{
 public $offset;
 function __construct($offset){$this->offset=$offset;}
 function pass_var($name,$default,$quiet){as_check($name==='start'&&$default===0&&$quiet,'Archive offset is read without logging');return $this->offset;}
}
function tn_champion_page($overview,$offset=0)
{
 global $db,$lang,$at_source;
 $template=new ArcadeTournamentChampionTemplate();
 $filename='arcade_tournament.php';
 $arcade=new ArcadeTournamentChampionRequest($offset);
 $champions_heading=sprintf($lang['Champions'],5);
 $s_options='';
 if($overview){
  $start=strpos($at_source,'$tour_champs = arcade_tournament_champions');
  $end=strpos($at_source,'$sql = "SELECT * FROM " . iNA_TOUR', $start);
 }else{
  $start=strpos($at_source,"else if (\$mode == 'champions')");
  $start=$start===false?false:strpos($at_source,'{',$start)+1;
  $end=$start===false?false:strpos($at_source,"\n}",$start);
 }
 as_check($start!==false&&$end>$start,'Actual overview or full champion archive branch');
 eval(substr($at_source,$start,$end-$start));
 return array($template->record,$champions_heading,$s_options);
}
class ArcadeTournamentNativeDatabase extends sql_db
{
 var $setup=true;var $fail_alias=false;
 function sql_query($sql='',$transaction=false){if(!$this->setup&&!preg_match('/^SELECT\b/i',ltrim($sql))){throw new RuntimeException('Tournament label rendering must remain read-only');}if($this->fail_alias&&strpos($sql,'SELECT n.author_user_id FROM ')===0){return false;}return parent::sql_query($sql,$transaction);}
}
function tn_sql($sql){$r=$GLOBALS['db']->sql_query($sql);as_check($r!==false,'Own canonical tournament fixture SQL');return $r;}
function tn_change($sql){$GLOBALS['db']->setup=true;try{return tn_sql($sql);}finally{$GLOBALS['db']->setup=false;}}
function tn_query($source){global $db;$tour_id=1;$game_info=array('game_name'=>'fixture_game');eval($source);$r=tn_sql($sql);try{return $db->sql_fetchrowset($r);}finally{$db->sql_freeresult($r);}}
function tn_snapshot(){global $db;$out=array();foreach(array('users','ina_tour_play','ina_tour','ina_tour_data','removed_author_names')as$table){$r=tn_sql('SELECT * FROM fixture_'.$table);$rows=$db->sql_fetchrowset($r);$db->sql_freeresult($r);$rows=array_map('json_encode',$rows);sort($rows);$out[$table]=$rows;}return $out;}
$db=$control=null;$created=false;$schema='codex_arc_tourlabel_'.bin2hex(phpbb_random_bytes(8));
try{
 $port=getenv('PHPBB_ARCADE_TOUR_LABEL_PORT')?:'3306';$password=getenv('PHPBB_ARCADE_TOUR_LABEL_PASSWORD')?:'';as_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Own loopback port');$host='127.0.0.1:'.$port;$control=new sql_db($host,'root',$password,'',false);as_check((bool)$control->db_connect_id,'Own server connection');as_check(preg_match('/^codex_arc_tourlabel_[a-f0-9]{16}$/D',$schema)&&$control->sql_query('CREATE DATABASE '.$schema.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Exclusive tournament schema');$created=true;
 $db=new ArcadeTournamentNativeDatabase($host,'root',$password,$schema,false);as_check((bool)$db->db_connect_id,'Own schema connection');$canonical=file_get_contents($as_app.'install/schemas/mysql_schema.sql');foreach(array('users','ina_tour_play','ina_tour','ina_tour_data','removed_author_names')as$table){as_check(preg_match('/CREATE TABLE phpbb_'.preg_quote($table,'/').' \(.*?\) ENGINE=InnoDB[^;]*;/s',$canonical,$m)===1,'Exact canonical schema');tn_sql(str_replace('phpbb_'.$table,'fixture_'.$table,$m[0]));}
 tn_sql("INSERT INTO fixture_users(user_id,username,user_password,user_active) VALUES(7,'Current','',1),(8,'0','',0),(9,'Legacy former','',1)");tn_sql("INSERT INTO fixture_ina_tour_play(tour_id,user_id,gamedata,last_played_game) VALUES(1,7,'a:0:{}','fixture_game'),(1,40,'a:0:{}','retired_game'),(1,8,'a:0:{}','zero_name'),(1,9,'a:0:{}','reused_name')");tn_sql("INSERT INTO fixture_ina_tour(tour_id,tour_name,tour_active,champion) VALUES(1,'Raw &amp; <tour>',0,7),(2,'Older &amp; <tour>',3,40),(3,'Latest &amp; <tour>',3,7)");tn_sql("INSERT INTO fixture_ina_tour_data(tour_id,game_name,top_score,top_player) VALUES(1,'fixture_game',0,7)");tn_sql("INSERT INTO fixture_removed_author_names(author_user_id,author_name_hash,author_name,recorded_at) VALUES(0,'".hash('sha256','Legacy former')."','Legacy former',1)");$db->setup=false;$arcade=new arcade();$SID='sid=tournament_fixture';
 $a=strpos($at_source,'$sql = "SELECT top_score, top_player FROM');$b=$a===false?false:strpos($at_source,'if( !$result =',$a);as_check($a!==false&&$b>$a,'Actual top-player query');$top_query=substr($at_source,$a,$b-$a);
 foreach(array('english','german')as$locale){as_language($locale);foreach(array(false,true)as$logged){$userdata=array('user_id'=>$logged?3:-1,'session_logged_in'=>$logged);$board_config=array('hide_removed_author_names'=>1,'sitename'=>'Fixture');foreach(array('A&B',"O'Reilly",'&#39;','Grüße 😀')as$raw){foreach(array(ENT_QUOTES,ENT_COMPAT)as$flags){$key=phpbb_username_key($raw,$flags);tn_change("UPDATE fixture_users SET username='".$db->sql_escape($key)."' WHERE user_id=7");$before=tn_snapshot();foreach($at_queries[0]as$query){$rows=tn_query($query);$by_id=array();foreach($rows as$row){$by_id[(int)$row['user_id']]=$row;}
  $owner_ids=array_keys($by_id);sort($owner_ids,SORT_NUMERIC);as_check($owner_ids===array(7,8,9,40),'Actual finish/view participant JOIN preserves every original owner including deleted IDs');as_check($by_id[40]['username']===null&&$by_id[40]['last_played_game']==='retired_game'&&$by_id[40]['gamedata']==='a:0:{}','Missing account never overwrites the participant or serialized game state');as_check($by_id[7]['username']===$key,'Actual participant JOIN reads the current canonical account key');at_check_output(at_render(7,$by_id[7]['username']),$raw,7);at_check_output(at_render(40,$by_id[40]['username']),$lang['Former_member'],40,false);at_check_output(at_render(8,$by_id[8]['username']),'0',8);at_check_output(at_render(9,$by_id[9]['username']),'Legacy former',9);$as_cases++;
 }$top=tn_query($top_query);as_check(count($top)===1&&$top[0]['top_player']==='7'&&(float)$top[0]['top_score']===0.0,'Original zero top-score identity remains unchanged');$champs=arcade_tournament_champions($db,6);as_check(count($champs)===2&&$champs[0]['champion']==='7'&&$champs[0]['username']===$key&&$champs[1]['champion']==='40'&&$champs[1]['username']===null&&$champs[0]['tour_name']==='Latest &amp; <tour>','Champion history ordering, current owners and raw tour metadata remain intact');as_check(tn_snapshot()===$before,'All name rendering and actual SELECTs leave canonical history/ownership unchanged');$as_cases++;
 }}}}
 $userdata=array('user_id'=>3,'session_logged_in'=>true);
 $short=tn_champion_page(true)[0];as_check($short['LINK']===''&&substr_count($short['NAME'],'images/crown.gif')===4,'Two winners display with no archive link');$as_cases++;
 for($id=4;$id<=6;$id++){tn_change("INSERT INTO fixture_ina_tour(tour_id,tour_name,tour_active,champion) VALUES($id,'Tournament $id',3,7)");}
 $five=tn_champion_page(true)[0];as_check($five['LINK']===''&&substr_count($five['NAME'],'images/crown.gif')===10,'Exactly five winners do not need an archive link');$as_cases++;
 for($id=7;$id<=28;$id++){tn_change("INSERT INTO fixture_ina_tour(tour_id,tour_name,tour_active,champion) VALUES($id,'Tournament $id',3,7)");}
 tn_change("INSERT INTO fixture_ina_tour(tour_id,tour_name,tour_active,champion) VALUES(29,'Inactive fixture',2,7),(30,'Finished without winner',3,NULL),(31,'Finished no real winner',3,0)");
 $before=tn_snapshot();
 foreach(array('english','german')as$locale){
  as_language($locale);
  $short=tn_champion_page(true)[0];
  as_check(substr_count($short['NAME'],'images/crown.gif')===10&&strpos($short['LIST'],'Tournament 28')===0&&strpos($short['LIST'],'Tournament 23')===false,'Overview shows exactly five newest winners');
  as_check(strpos($short['LINK'],'mode=champions')!==false&&strpos($short['LINK'],'sid=tournament_fixture')!==false,'Overview links to the archive with the current SID');
  list($first,$heading,$back)=tn_champion_page(false);
  as_check($heading===$lang['tournament_champions']&&strpos($back,'arcade_tournament.php')!==false&&strpos($back,'mode=champions')===false,'Archive heading and return target');
  as_check(substr_count($first['NAME'],'images/crown.gif')===50&&strpos($first['LIST'],'Tournament 28')===0&&strpos($first['LIST'],'Inactive fixture')===false,'Archive excludes tournaments with no champion and has a bounded first page');
  as_check(strpos($first['LINK'],'mode=champions')!==false&&strpos($first['LINK'],'start=25')!==false,'Pagination stays on the champions archive');
  $last=tn_champion_page(false,99)[0];
  as_check(substr_count($last['NAME'],'images/crown.gif')===4&&strpos($last['LIST'],'Latest &amp;amp; &lt;tour&gt;')===0&&strpos($last['NAME'],$lang['Former_member'])!==false,'Out-of-range offset reaches the last page, preserving escaped titles and deleted champions');
  as_check(tn_snapshot()===$before,'Both tournament pages are read-only');
  $as_cases++;
 }
 tn_change('DELETE FROM fixture_users WHERE user_id=7');$before=tn_snapshot();at_check_output(at_render(7,null),$lang['Former_member'],7,false);as_check(tn_snapshot()===$before,'Native account retirement cannot leak the last successful username or modify tournament history');$as_cases++;
 $previous=$db;$db=new ArcadeTournamentNativeDatabase($host,'root',$password,$schema,false);as_check((bool)$db->db_connect_id,'Fresh viewer connection for alias failure');$db->setup=false;$previous->sql_close();$db->fail_alias=true;$userdata=array('user_id'=>-1,'session_logged_in'=>false);$board_config=array('hide_removed_author_names'=>1);$caught=false;try{at_render(9,'Legacy former');}catch(ArcadeSummaryExit$e){$caught=$e->getMessage()===$lang['Author_privacy_unavailable'];}as_check($caught,'Native privacy read failure never silently falls back to exposing a byline');$as_cases++;
 echo 'Arcade tournament labels native: ',$as_cases,' cases, ',$as_checks," assertions PASS; actual participant/top-player/champion JOINs, retained retired owners, current account names, safe links, raw tournament metadata and read-only history.\n";
}finally{if($db){$db->sql_close();}if($created){as_check(preg_match('/^codex_arc_tourlabel_[a-f0-9]{16}$/D',$schema)&&$control->sql_query('DROP DATABASE '.$schema),'Exact own tournament schema cleanup');}if($control){$control->sql_close();}restore_error_handler();}
