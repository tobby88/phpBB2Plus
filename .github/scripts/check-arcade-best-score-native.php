<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_ARCADE_BEST_NATIVE')!=='1'){echo "Arcade best-score checks require an explicitly enabled disposable database.\n";return;}
date_default_timezone_set('UTC');
require __DIR__.'/arcade-game-native-fixture.php';
as_function($as_app.'includes/functions.php','decode_ip');

function ab_better($score,$old,$reverse)
{
    return $old===null || ($reverse ? $score<$old : $score>$old);
}
function ab_seed($current,$alltime,$reverse,$monthly=null,$peer=0)
{
    global $userdata,$arcade;
    ag_reset();ag_sql('START TRANSACTION');
    ag_sql('UPDATE fixture_ina_games SET reverse_list='.(int)$reverse.',highscore_limit=0,at_highscore_limit=0,score_type=1');
    foreach(array(iNA_SCORES=>$current,iNA_AT_SCORES=>$alltime)as$table=>$value){
        ag_sql('DELETE FROM '.$table.' WHERE player_id=2');ag_sql('UPDATE '.$table.' SET score='.$peer);
        if($value!==null){ag_sql('INSERT INTO '.$table." (game_name,player_id,score,date,time_taken,player_ip) VALUES('old_game',2,$value,2,9,'fixture-ip')");}
    }
    foreach(array('first_places'=>$current,'at_first_places'=>$alltime)as$field=>$old){
        $winner=$old!==null&&($reverse?$old<$peer:$old>$peer)?2:8;
        ag_sql('UPDATE fixture_ina_user_data SET '.$field.'=CASE WHEN user_id='.$winner.' THEN 1 ELSE 0 END');
    }
    if($monthly!==null){ag_sql("INSERT INTO fixture_ina_highscore(highscore_game,highscore_year,highscore_mon,highscore_user_id,highscore_player,highscore_score,highscore_date) VALUES('old_game',".date('Y').','.date('n').",8,'Player',$monthly,1)");}
    ag_sql('COMMIT');
    $userdata['username']='Actor';$userdata['session_ip']='7f000001';$userdata['user_level']=0;
    $arcade->arcade_config['games_at_highscore']=1;$arcade->arcade_config['games_guest_highscore']=0;
    $arcade->arcade_config['games_pm_highscore']=0;$arcade->arcade_config['games_pm_at_highscore']=0;
    $arcade->arcade_session=array('arcade_hash'=>str_repeat('a',32),'user_id'=>2,'game_name'=>'old_game');
    $arcade->game_name='old_game';$arcade->time_taken=1;$arcade->user_id=2;$arcade->score_type=0;
}
function ab_submit($score)
{
    global $arcade;$arcade->score=$score;
    as_check($arcade->newscore()==='','Actual score method reports success');
}
function ab_board($table,$old,$score,$reverse,$field,$pointer,$peer=0)
{
    $improved=ab_better($score,$old,$reverse);$expected=$improved?$score:$old;
    $rows=ag_rows('SELECT score,date,time_taken,player_ip FROM '.$table." WHERE game_name='old_game' AND player_id=2");
    as_check(count($rows)===1&&(float)$rows[0]['score']===(float)$expected,'Exactly one personal score with best value, including genuine zero/negative');
    if($improved){as_check((int)$rows[0]['date']>time()-60&&(int)$rows[0]['time_taken']===1&&$rows[0]['player_ip']==='127.0.0.1','Improvement stores current metadata');}
    else{as_check((int)$rows[0]['date']===2&&(int)$rows[0]['time_taken']===9&&$rows[0]['player_ip']==='fixture-ip','Worse/equal score leaves original metadata unchanged');}
    $winner=($reverse?$expected<$peer:$expected>$peer)?2:8;
    as_check((int)ag_rows('SELECT '.$pointer.' FROM fixture_ina_games WHERE game_id=41')[0][$pointer]===$winner,'Actual pruning retains the correct numeric winner');
    foreach(ag_rows('SELECT user_id,'.$field.' FROM fixture_ina_user_data')as$row){as_check((int)$row[$field]===((int)$row['user_id']===$winner?1:0),'First-place counts retain real zero and negative winners');}
}
$db=$ag_peer=$control=null;$created=false;$schema='codex_arc_best_'.bin2hex(phpbb_random_bytes(8));$cases=0;
try {
    $port=getenv('PHPBB_ARCADE_GAME_PORT')?:'3306';as_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Own loopback port');
    $host='127.0.0.1:'.$port;$password=getenv('PHPBB_ARCADE_GAME_PASSWORD')?:'';
    $control=new sql_db($host,'root',$password,'',false);as_check($control->sql_query('CREATE DATABASE '.$schema.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Own best-score database');$created=true;
    $db=new ArcadeGameNativeDatabase($host,'root',$password,$schema,false);$ag_peer=new sql_db($host,'root',$password,$schema,false);
    $ag_tables=array(USERS_TABLE,SESSIONS_TABLE,JR_ADMIN_TABLE,GROUPS_TABLE,RANKS_TABLE,iNA_GAMES,iNA_CAT);foreach(phpbb_arcade_game_references()as$ref){$ag_tables[]=$ref[0];}$ag_tables=array_values(array_unique($ag_tables));
    $canonical=file_get_contents($as_app.'install/schemas/mysql_schema.sql');foreach($ag_tables as$table){$suffix=substr($table,8);as_check(preg_match('/CREATE TABLE phpbb_'.preg_quote($suffix,'/').' \(.*?\) ENGINE=InnoDB[^;]*;/s',$canonical,$m)===1,'Canonical best-score table');ag_sql(str_replace('phpbb_'.$suffix,$table,$m[0]));}
    as_open();$ag_routes=aif_routes();
    foreach(array(false,true)as$reverse){foreach(array(null,-5,0,5)as$current){foreach(array(null,-3,0,3)as$alltime){foreach(array(-7,7)as$score){
        ab_seed($current,$alltime,$reverse);ab_submit($score);
        ab_board(iNA_SCORES,$current,$score,$reverse,'first_places','highscore_id');
        ab_board(iNA_AT_SCORES,$alltime,$score,$reverse,'at_first_places','at_highscore_id');
        as_check(ag_rows('SELECT highscore_score FROM fixture_ina_highscore WHERE highscore_year=2020')[0]['highscore_score']==='0.0000','Historical monthly records remain unchanged');$cases++;
    }}}}
    foreach(array(false,true)as$reverse){foreach(array(null,0,3,9)as$old){foreach(array(1,7,11)as$score){
        // Deliberately different current, all-time and monthly best values:
        // a comparison must only use the matching board/period.
        ab_seed(null,5,$reverse,$old,10);ab_submit($score);
        $rows=ag_rows('SELECT highscore_score,highscore_user_id,highscore_date FROM fixture_ina_highscore WHERE highscore_year='.date('Y').' AND highscore_mon='.date('n'));
        $improved=ab_better($score,$old,$reverse);as_check(count($rows)===1&&(float)$rows[0]['highscore_score']===(float)($improved?$score:$old),'Monthly best compares the monthly value, including stored zero');
        as_check((int)$rows[0]['highscore_user_id']===($improved?2:8)&&($improved?(int)$rows[0]['highscore_date']>time()-60:(int)$rows[0]['highscore_date']===1),'Monthly owner/date changes only for an improvement');$cases++;
    }}}
    foreach(array(false,true)as$reverse){ab_seed(5,3,$reverse);$before=ag_snapshot();$arcade->score=0;as_check($arcade->newscore()===false&&ag_snapshot()===$before,'Existing protocol zero-submission behavior unchanged');$cases++;}
    foreach(array(NAN,INF,-INF,10000000001,-10000000001)as$score){ab_seed(5,3,false);$before=ag_snapshot();$arcade->score=$score;as_check($arcade->newscore()===false&&ag_snapshot()===$before,'Non-finite and out-of-range results stop without warnings or database writes');$cases++;}
    foreach(array(false,true)as$reverse){ab_seed(null,null,$reverse);ag_sql('DELETE FROM fixture_ina_scores');ag_sql('DELETE FROM fixture_ina_at_scores');ag_sql('UPDATE fixture_ina_user_data SET first_places=0,at_first_places=0');ab_submit(7);foreach(array(iNA_SCORES,iNA_AT_SCORES)as$table){as_check((float)ag_rows('SELECT score FROM '.$table)[0]['score']===7.0,'First board result is not confused with zero');}foreach(ag_rows('SELECT user_id,first_places,at_first_places FROM fixture_ina_user_data')as$row){as_check((int)$row['first_places']===((int)$row['user_id']===2?1:0)&&(int)$row['at_first_places']===(int)$row['first_places'],'Empty boards get exactly one winner');}$cases++;}
    foreach(array(false,true)as$reverse){ab_seed(5,3,$reverse,3,10);$extra=$reverse?1:9;$at=$reverse?0:8;ag_sql("INSERT INTO fixture_ina_scores(game_name,player_id,score,date) VALUES('old_game',2,$extra,3)");ag_sql("INSERT INTO fixture_ina_at_scores(game_name,player_id,score,date) VALUES('old_game',2,$at,3)");$before=array(ag_rows('SELECT * FROM fixture_ina_scores ORDER BY player_id,score'),ag_rows('SELECT * FROM fixture_ina_at_scores ORDER BY player_id,score'));ab_submit($reverse?2:7);as_check(ag_rows('SELECT * FROM fixture_ina_scores ORDER BY player_id,score')===$before[0],'Existing duplicate current rows retain their better score');$after=ag_rows('SELECT * FROM fixture_ina_at_scores ORDER BY player_id,score');foreach($after as$index=>$row){as_check($row['score']===$before[1][$index]['score']&&$row['date']===$before[1][$index]['date'],'Existing duplicate all-time scores retain their better score');}$cases++;}
    foreach(array(false,true)as$reverse){ab_seed(0,0,$reverse);ag_sql('UPDATE fixture_ina_scores SET date=1');ag_sql('UPDATE fixture_ina_at_scores SET date=1');ag_sql('UPDATE fixture_ina_user_data SET first_places=CASE WHEN user_id=2 THEN 1 ELSE 0 END,at_first_places=CASE WHEN user_id=2 THEN 1 ELSE 0 END');ab_submit($reverse?-7:7);ab_board(iNA_SCORES,0,$reverse?-7:7,$reverse,'first_places','highscore_id');ab_board(iNA_AT_SCORES,0,$reverse?-7:7,$reverse,'at_first_places','at_highscore_id');$cases++;}
    foreach(array('missing','duplicate','offline')as$bad){ab_seed(5,3,false);if($bad==='missing'){ag_sql('DELETE FROM fixture_ina_games');}elseif($bad==='duplicate'){ag_sql("INSERT INTO fixture_ina_games(game_name) VALUES('OLD_GAME')");}else{ag_sql('UPDATE fixture_ina_games SET game_avail=0');}$before=ag_snapshot();$arcade->score=7;$r=$arcade->newscore();as_check(($bad==='offline'?$r===false:is_string($r)&&$r!=='')&&ag_snapshot()===$before,'Unavailable/ambiguous game returns without score writes '.$bad);$cases++;}
    foreach(array('english','german')as$locale){as_language($locale);ab_seed(null,5,true,3,10);ab_submit(7);as_check((float)ag_rows('SELECT score FROM fixture_ina_at_scores WHERE player_id=2')[0]['score']===5.0&&(float)ag_rows('SELECT highscore_score FROM fixture_ina_highscore WHERE highscore_year='.date('Y'))[0]['highscore_score']===3.0,'Reported lower-is-better regression fixed in actual method '.$locale);$cases++;}
    as_check(count($ag_notifications)===0,'No actual messages sent');
    echo 'Native Arcade best scores: '.$cases.' cases, '.$as_checks." assertions PASS; actual full submission/pruning, zero and negative records, independent current/all-time/monthly comparisons and unchanged worse-score metadata.\n";
} finally {
    if($db){$db->sql_close();}if($ag_peer){$ag_peer->sql_close();}if($created){as_check(preg_match('/^codex_arc_best_[a-f0-9]{16}$/D',$schema)&&$control->sql_query('DROP DATABASE '.$schema),'Drop only own best-score schema');}if($control){$control->sql_close();}
    if($as_created){foreach(array('games','categories','user_id','version')as$key){$file=$as_root.'/cache/arcade_'.$key.'.cache';if(is_file($file)){as_check(!is_link($file)&&unlink($file),'Own best-score cache cleanup');}}as_close();}
}
