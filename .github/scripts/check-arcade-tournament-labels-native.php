<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_ARCADE_TOUR_LABEL_NATIVE')!=='1'){echo "Tournament label checks require an explicitly enabled disposable database.\n";return;}
require __DIR__.'/arcade-tournament-fixture.php';require $as_app.'db/mysqli.php';
as_function($as_app.'includes/functions.php','generate_pagination');
as_function($as_app.'includes/functions.php','create_date');
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
  $end=strpos($at_source,'$tours = arcade_tournament_overview', $start);
 }else{
  $start=strpos($at_source,"else if (\$mode == 'champions')");
  $start=$start===false?false:strpos($at_source,'{',$start)+1;
  $end=$start===false?false:strpos($at_source,"\n}",$start);
 }
 as_check($start!==false&&$end>$start,'Actual overview or full champion archive branch');
 eval(substr($at_source,$start,$end-$start));
 return array($template->record,$champions_heading,$s_options);
}
function tn_tournament_overview($user, $start_enabled, $limit)
{
 global $db,$lang,$at_source;
 $arcade=(object)array('arcade_config'=>array('games_tournament_user'=>$start_enabled,'games_tournament_max'=>$limit));
 $userdata=array('user_id'=>$user);
 $filename='arcade_tournament.php';
 $template=new ArcadeTournamentChampionTemplate();
 $start=strpos($at_source,'$tours = arcade_tournament_overview');
 $end=$start===false?false:strpos($at_source,"\n}",$start);
 as_check($start!==false&&$end>$start,'Actual tournament overview action branch');
 $before=$db->read_queries;
 eval(substr($at_source,$start,$end-$start));
 as_check($db->read_queries===$before+1,'Tournament rows, counts and membership come from one query');
 return array($template->blocks,$s_options);
}
function tn_tournament_detail($tour, $tour_players, $userdata)
{
 global $at_source,$lang;
 $template=new ArcadeTournamentChampionTemplate();
 $total_players=count($tour_players);
 $in_tour=false;
 $GameData=array();
 $played_games=0;
 $games_played_list=array();
 $start=strpos($at_source,'$template->assign_block_vars(\'player_head\'');
 $end=$start===false?false:strpos($at_source,'$template->assign_block_vars(\'game_head\'',$start);
 as_check($start!==false&&$end>$start,'Actual tournament player display and action branch');
 eval(substr($at_source,$start,$end-$start));
 return array($template->blocks,$s_options,$GameData,$games_played_list);
}
function tn_tournament_start($enabled,$limit)
{
 global $db,$lang,$at_source;
 $arcade=(object)array('arcade_config'=>array('games_tournament_user'=>$enabled,'games_tournament_max'=>$limit));
 $template=new ArcadeTournamentChampionTemplate();
 $tour=array('tour_name'=>'','tour_desc'=>'','tour_max_players'=>2,'tour_player_turns'=>1);
 $start=strpos($at_source,"else if(!empty(\$HTTP_POST_VARS['start']))");
 $start=$start===false?false:strpos($at_source,'{',$start)+1;
 $end=$start===false?false:strpos($at_source,"\n}",$start);
 as_check($start!==false&&$end>$start,'Actual tournament start form branch');
 try{eval(substr($at_source,$start,$end-$start));}
 catch(ArcadeSummaryExit $error){return $error->getMessage();}
 as_check(isset($template->blocks['tournament_add'])&&strpos($s_options,'name="submit"')!==false,'Correctly available start form');
 return '';
}
function tn_tournament_summary($viewer)
{
 global $arcade,$as_app,$board_config,$lang,$phpEx,$userdata;
 $board_config['default_dateformat']='Y-m-d H:i';
 $board_config['board_timezone']=1;
 $userdata=array('user_id'=>3,'user_level'=>$viewer,'session_logged_in'=>true);
 $template=new ArcadeTournamentChampionTemplate();
 $source=file_get_contents($as_app.'activity.php');
 $start=strpos($source,'$tour_link = \'\';');
 $end=$start===false?false:strpos($source,"\n//\n//  All Games Output",$start);
 as_check($start!==false&&$end>$start,'Actual category-page tournament panel');
 eval(substr($source,$start,$end-$start));
 return $template->blocks['tournament_menu'][0];
}
function tn_query_links($html)
{
 $links=array();
 preg_match_all('/href="([^"]+)"/',$html,$matches);
 foreach($matches[1] as $url){
  parse_str(parse_url(html_entity_decode($url,ENT_QUOTES,'UTF-8'),PHP_URL_QUERY),$params);
  $links[]=$params;
 }
 return $links;
}
class ArcadeTournamentNativeDatabase extends sql_db
{
 var $setup=true;var $fail_alias=false;var $read_queries=0;
 function sql_query($sql='',$transaction=false){if(!$this->setup){if(!preg_match('/^SELECT\b/i',ltrim($sql))){throw new RuntimeException('Tournament label rendering must remain read-only');}$this->read_queries++;}if($this->fail_alias&&strpos($sql,'SELECT n.author_user_id FROM ')===0){return false;}return parent::sql_query($sql,$transaction);}
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
 tn_change('UPDATE fixture_ina_tour SET tour_active=3, champion=NULL WHERE tour_id=29');
 tn_change("INSERT INTO fixture_ina_tour(tour_id,tour_name,tour_desc,tour_active,tour_max_players) VALUES
  (40,'Open & <fixture>','<description>',2,3),(41,'Full','Two members',2,2),(42,'Joined','Two members',2,2),(43,NULL,NULL,0,4)");
 tn_change("INSERT INTO fixture_ina_tour_data(tour_id,game_name) VALUES(40,'Alpha'),(40,'Beta'),(41,'One'),(42,'One')");
 tn_change("INSERT INTO fixture_ina_tour_play(tour_id,user_id) VALUES(40,8),(40,9),(41,7),(41,8),(42,3),(42,7)");
 $before=tn_snapshot();
 foreach(array('english','german') as $locale){
  as_language($locale);
  foreach(array(array(0,10,false),array(1,5,false),array(1,6,true)) as $settings){
   list($blocks,$actions)=tn_tournament_overview(3,$settings[0],$settings[1]);
   $rows=$blocks['tour'];
   as_check(count($rows)===5&&strpos($rows[0]['NAME'],'id=40')!==false&&strpos($rows[0]['NAME'],'Open &amp; &lt;fixture&gt;')!==false,'Visible active and inactive tournaments are ordered and safely rendered');
   as_check($rows[0]['DESC']==='&lt;description&gt;'&&$rows[0]['TOTAL_GAMES']===sprintf($lang['tournament_games'],2)&&$rows[0]['TOTAL_PLAYERS']===sprintf($lang['tournament_players'],2),'Two independent counts are not multiplied by the other table');
   as_check(strpos($rows[0]['JOIN'],'join_tour[]')!==false&&$rows[1]['JOIN']===$lang['arcade_tournament_full']&&$rows[2]['JOIN']===$lang['arcade_tournament_already_joined']&&$rows[4]['JOIN']===$lang['inactive'],'Open, full, joined and inactive rows show the right action');
   as_check((strpos($actions,'name="start"')!==false)===$settings[2]&&strpos($actions,'name="join"')!==false,'Join remains available regardless of tournament creation settings or count');
   as_check(tn_snapshot()===$before,'The overview leaves every stored tournament row alone');
   $as_cases++;
  }
  as_check(tn_tournament_start(0,6)===$lang['arcade_admin_only'].$lang['tour_return']&&
   tn_tournament_start(1,5)===$lang['arcade_tournament_limit'].$lang['tour_return']&&tn_tournament_start(1,6)==='',
   'The start form and overview share creation settings and current event capacity');
  $as_cases++;
 }
 list($occupied,$occupied_actions)=tn_tournament_overview(8,1,8);
 as_check(strpos($occupied_actions,'name="join"')===false&&strpos($occupied_actions,'name="start"')!==false,'Join is hidden when all available games are full or already joined');$as_cases++;
 $detail_tour=array('tour_id'=>40,'tour_active'=>2,'tour_max_players'=>3);
 $saved_game=array('game_name'=>'Original \\ game','played'=>2,'score'=>13.75);
 $detail_players=array(array('user_id'=>3,'username'=>'Member','gamedata'=>serialize(array($saved_game))),
  array('user_id'=>7,'username'=>'Unplayed','gamedata'=>null));
 $detail=tn_tournament_detail($detail_tour,$detail_players,array('user_id'=>3,'user_level'=>ADMIN));
 as_check($detail[2]===array($saved_game)&&isset($detail[3][$saved_game['game_name']])&&$detail[0]['player'][1]['PLAYED_GAMES']==='','Detail reads canonical backslashes and nullable new memberships through the real controller');
 as_check(strpos($detail[1],'name="end"')!==false&&strpos($detail[1],'name="join"')===false,'A participating administrator can end before all seats fill');
 $detail=tn_tournament_detail($detail_tour,array(),array('user_id'=>3,'user_level'=>ADMIN));
 as_check(strpos($detail[1],'name="end"')!==false&&strpos($detail[1],'name="join"')!==false,'An empty active tournament offers both relevant admin actions');$as_cases+=2;

 $arcade->arcade_config['games_tournament_mode']=1;
 as_check(game_get_last_tour()===false,'Joining without playing is not the last play');$as_cases++;
 $state=serialize(array(3=>array('game_name'=>'Saved \\ game','played'=>3),4=>array('game_name'=>'Ignored','played'=>-2)));
 $legacy=addslashes(serialize(array(array('game_name'=>'Legacy \\ game','played'=>4))));
 tn_change("UPDATE fixture_users SET username='Hidden competitor',user_allow_viewonline=0 WHERE user_id=7");
 tn_change("INSERT INTO fixture_ina_tour_play(tour_id,user_id,last_played_time,gamedata) VALUES
  (40,7,1700000100,'".$db->sql_escape($state)."'),(999,7,1700000120,'".$db->sql_escape($state)."')");
 tn_change("UPDATE fixture_ina_tour_play SET last_played_time=1700000100,gamedata='".$db->sql_escape($legacy)."' WHERE tour_id=41 AND user_id=7");
 tn_change('UPDATE fixture_ina_tour_play SET last_played_time=1700000100 WHERE tour_id=40 AND user_id=8');
 $before=tn_snapshot();
 foreach(array('english','german') as $locale){
  as_language($locale);
  $last=game_get_last_tour();
  as_check((int)$last['tour_id']===40&&(int)$last['user_id']===7,'An orphaned event never displaces a real play and timestamp ties are stable');
  as_check(game_tour_played()===7,'Play totals decode both canonical and escaped historical data and omit orphaned turns');
  $member=tn_tournament_summary(USER);
  $links=tn_query_links($member['TOUR_LAST']);
  as_check((int)$member['TOUR_TOTAL']===3&&$member['TOUR_PLAYED']===7&&count($links)===1&&
   $links[0]===array('mode'=>'tour','id'=>'40','sid'=>'tournament_fixture'),'Tournament count and latest play route use existing events and the current SID');
  as_check(strpos($member['TOUR_LAST'],'Hidden competitor')===false&&strpos($member['TOUR_LAST'],$lang['game_hidden'])!==false,'Ordinary visitors see the same invisible setting as for other Arcade play summaries');
  foreach(array(ADMIN,MOD) as $staff){
   $staff_summary=tn_tournament_summary($staff);
   $links=tn_query_links($staff_summary['TOUR_LAST']);
   as_check(strpos($staff_summary['TOUR_LAST'],'Hidden competitor')!==false&&
    $links[1]===array('mode'=>'viewprofile','u'=>'7','sid'=>'tournament_fixture'),'Existing staff display and exact account target are preserved');
  }
  $sidebar=tn_query_links(game_list_tour());
  as_check(array_column($sidebar,'id')===array('40','41','42')&&count(array_unique(array_column($sidebar,'sid')))===1&&$sidebar[0]['sid']==='tournament_fixture','The sidebar keeps each tournament ID and session separate');
  as_check(tn_snapshot()===$before,'Tournament summary leaves database data untouched');$as_cases++;
 }
 tn_change('DELETE FROM fixture_users WHERE user_id=7');$before=tn_snapshot();at_check_output(at_render(7,null),$lang['Former_member'],7,false);as_check(tn_snapshot()===$before,'Native account retirement cannot leak the last successful username or modify tournament history');$as_cases++;
 $retired=tn_tournament_summary(USER);
 as_check(strpos($retired['TOUR_LAST'],$lang['Former_member'])!==false&&count(tn_query_links($retired['TOUR_LAST']))===1,
  'Retired participants keep the historical tournament link without a profile link');$as_cases++;
 $previous=$db;$db=new ArcadeTournamentNativeDatabase($host,'root',$password,$schema,false);as_check((bool)$db->db_connect_id,'Fresh viewer connection for alias failure');$db->setup=false;$previous->sql_close();$db->fail_alias=true;$userdata=array('user_id'=>-1,'session_logged_in'=>false);$board_config=array('hide_removed_author_names'=>1);$caught=false;try{at_render(9,'Legacy former');}catch(ArcadeSummaryExit$e){$caught=$e->getMessage()===$lang['Author_privacy_unavailable'];}as_check($caught,'Native privacy read failure never silently falls back to exposing a byline');$as_cases++;
 echo 'Arcade tournament labels native: ',$as_cases,' cases, ',$as_checks," assertions PASS; actual participant/top-player/champion JOINs, retained retired owners, current account names, safe links, raw tournament metadata and read-only history.\n";
}finally{if($db){$db->sql_close();}if($created){as_check(preg_match('/^codex_arc_tourlabel_[a-f0-9]{16}$/D',$schema)&&$control->sql_query('DROP DATABASE '.$schema),'Exact own tournament schema cleanup');}if($control){$control->sql_close();}restore_error_handler();}
