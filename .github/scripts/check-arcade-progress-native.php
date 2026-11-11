<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_ARCADE_PROGRESS_NATIVE')!=='1'){echo "Arcade progress checks require an explicitly enabled disposable database.\n";return;}
require __DIR__.'/arcade-submission-fixture.php';
require_once $as_app.'includes/functions_arcade_progress_storage.php';
class ArcadeProgressNativeDatabase extends ArcadeSubmissionNativeDatabase
{
 function __construct($host,$user,$password,$schema,$persistent=false,$owned=false){parent::__construct($host,$user,$password,$schema,$persistent,$owned);$this->fixture_factory=function()use($host,$user,$password,$schema){return new ArcadeProgressNativeDatabase($host,$user,$password,$schema,false,true);};}
 function sql_query($sql='',$transaction=false){
  global $ag_peer,$ag_async,$su_observer;
  if($this->owned&&!$this->fired&&$this->fault==='progress-read'&&strpos($sql,'SELECT game_data FROM fixture_ina_game_saves')===0){$this->fired=true;return false;}
  $result=parent::sql_query($sql,$transaction);
  if($this->owned&&!$this->fired&&strpos($this->fault,'progress-peer-')===0&&strpos($sql,'INSERT INTO fixture_ina_game_saves')===0){
   as_check($result!==false,'Actual uncommitted progress write');$this->fired=true;
   $changes=array('progress-peer-logout'=>"DELETE FROM fixture_sessions WHERE session_id='fixture-import'",'progress-peer-inactive'=>'UPDATE fixture_users SET user_active=0 WHERE user_id=2',
    'progress-peer-group'=>'DELETE FROM fixture_user_group WHERE user_id=2 AND group_id=5','progress-peer-game'=>'UPDATE fixture_ina_games SET game_avail=0 WHERE game_id=41',
    'progress-peer-config'=>"UPDATE fixture_ina_data SET config_value='1' WHERE config_name='games_offline'");
   as_check(isset($changes[$this->fault])&&mysqli_query($ag_peer->db_connect_id,$changes[$this->fault],MYSQLI_ASYNC),'Queue independent access change');$ag_async=true;
   native_fixture_wait_for_lock($su_observer,mysqli_thread_id($ag_peer->db_connect_id),mysqli_thread_id($this->db_connect_id),'as_check');
  }
  return $result;
 }
}
function ps_reset(){su_reset();as_language('english');}
function ps_request($mode='saveGame',$data='fixture progress',$extra=array()){
 return su_controller('pnflash',array_replace(array('func'=>$mode,'gameData'=>$data,'gid'=>'41','arcade_hash'=>str_repeat('a',32)),$extra));
}
function ps_success($result){return !$result['error']&&strpos($result['output'],'&opSuccess=true&endvar=1')!==false;}
function ps_saved(){return su_rows('SELECT user_id,game_id,game_data,saved_at FROM fixture_ina_game_saves ORDER BY user_id,game_id');}
function ps_snapshot(){return array(su_snapshot(),ps_saved());}
function ps_load($expected,$extra=array()){$r=ps_request('loadGame','unused',$extra);as_check(ps_success($r)&&$r['output']==='gameData='.rawurlencode($expected).'&opSuccess=true&endvar=1','Actual URL-encoded progress response preserves exact bytes');}
$db=$control=$ag_peer=$su_observer=null;$created=false;$schema='codex_arc_progress_'.bin2hex(phpbb_random_bytes(8));$cases=$serialized=0;
try{
 $port=getenv('PHPBB_ARCADE_GAME_PORT')?:'3306';as_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Own loopback port');$host='127.0.0.1:'.$port;$password=getenv('PHPBB_ARCADE_GAME_PASSWORD')?:'';
 $control=new sql_db($host,'root',$password,'',false);as_check($control->sql_query('CREATE DATABASE '.$schema.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Own disposable progress schema');$created=true;
 $db=new ArcadeProgressNativeDatabase($host,'root',$password,$schema,false);$ag_peer=new sql_db($host,'root',$password,$schema,false);$su_observer=new sql_db($host,'root',$password,$schema,false);su_schema();as_open();$ag_routes=aif_routes();
 ps_reset();$db->factory_mode='no-backslash';$data="\0 ' \\ Grüße😀 &amp;\r\n";as_check(ps_success(ps_request('saveGame',$data)),'Exact opaque progress under NO_BACKSLASH_ESCAPES');ps_load($data);$cases++;
 ps_reset();$r=su_controller('pnflash',array('func'=>'saveGame','gameData'=>'legacy protocol'));as_check(ps_success($r),'Historical protocol without explicit token/gid still saves the latest active game');ps_load('legacy protocol',array('arcade_hash'=>'','gid'=>'0'));$cases++;
 ps_reset();profile_fixture_request(array('func'=>'saveGame','gameData'=>'GET Grüße &amp; +','gid'=>'41','arcade_hash'=>str_repeat('a',32)));$_GET=$HTTP_GET_VARS=$HTTP_POST_VARS;$_POST=$HTTP_POST_VARS=array();$_SERVER['REQUEST_METHOD']='GET';as_check(ps_success(su_controller('pnflash')),'Historical same-origin GET progress protocol retained');ps_load('GET Grüße &amp; +');$cases++;
 ps_reset();ag_sql("INSERT INTO fixture_ina_sessions(user_id,game_name,arcade_hash,start_time,page) VALUES(2,'old_game','".str_repeat('b',32)."',".(time()-100).','.PAGE_PLAYING_GAMES.')');as_check(ps_success(ps_request('saveGame','latest',array('arcade_hash'=>''))),'Explicit game ID without nonce chooses the latest distinct legacy capability');ps_load('latest');$cases++;
 ps_reset();ag_sql('INSERT INTO fixture_ina_sessions SELECT * FROM fixture_ina_sessions');$before=ps_snapshot();as_check(!ps_success(ps_request('saveGame','ambiguous',array('arcade_hash'=>'','gid'=>'0')))&&ps_snapshot()===$before,'No-token tied capabilities cannot silently choose a game');$cases++;
 foreach(array(true,false)as$active){ps_reset();ag_sql('UPDATE fixture_ina_sessions SET page='.PAGE_ARCADE_TOUR.',tour_id=9');ag_sql('INSERT INTO fixture_ina_tour(tour_id,tour_active) VALUES(9,'.($active?2:3).')');ag_sql("INSERT INTO fixture_ina_tour_data(tour_id,game_name) VALUES(9,'old_game')");ag_sql('INSERT INTO fixture_ina_tour_play(tour_id,user_id) VALUES(9,2)');$before=ps_snapshot();$r=ps_request('saveGame','tour progress');as_check(ps_success($r)===$active,'Current tournament status also applies to private progress');if($active){ps_load('tour progress');$after=ps_snapshot();unset($before[0][iNA_LOG],$after[0][iNA_LOG],$before[1],$after[1]);as_check($before===$after,'Tournament progress never spends or changes a turn/score');}else{as_check(ps_snapshot()===$before,'Closed tournament refuses all progress writes');}$cases++;}
 foreach(array('english','german')as$locale){foreach(array('', '0'," \t\n Gr üße 😀 &#39; &amp; %2B + = & ' \" \\ \0 \r\n ",str_repeat('x',65535),str_repeat('😀',16383).'xyz')as$data){
  ps_reset();as_language($locale);$before=su_snapshot();as_check(ps_success(ps_request('saveGame',$data)),'Actual save succeeds including empty/zero/full Unicode text');$rows=ps_saved();as_check(count($rows)===1&&(int)$rows[0]['user_id']===2&&(int)$rows[0]['game_id']===41&&$rows[0]['game_data']===$data&&(int)$rows[0]['saved_at']>time()-60,'One private exact progress record');ps_load($data);
  $after=su_snapshot();unset($before[iNA_LOG],$after[iNA_LOG]);as_check($after===$before,'Progress never consumes capabilities, changes scores, wallets, rankings or turns');$cases++;
 }}
 ps_reset();ag_sql('DELETE FROM fixture_ina_scores WHERE player_id=2');as_check(ps_success(ps_request()),'First progress save works without a highscore');ps_load('fixture progress');as_check(!su_rows('SELECT player_id FROM fixture_ina_scores WHERE player_id=2'),'No fake zero highscore inserted');$cases++;
 ps_reset();as_check(ps_success(ps_request('saveGame','first'))&&ps_success(ps_request('saveGame','second'))&&count(ps_saved())===1,'Repeated saves update exactly one private record');ps_load('second');$cases++;
 ps_reset();ag_sql("UPDATE fixture_ina_scores SET gameData='Legacy &amp; Grüße😀' WHERE player_id=2");$before=su_snapshot();ps_load('Legacy &amp; Grüße😀');as_check(ps_saved()===array(),'Reading legacy progress needs no migration writes');$after=su_snapshot();unset($before[iNA_LOG],$after[iNA_LOG]);as_check($before===$after,'Legacy score rows byte-exact after load');$cases++;
 foreach(array(false,true)as$reverse){ps_reset();ag_sql('UPDATE fixture_ina_games SET reverse_list='.(int)$reverse);ag_sql("UPDATE fixture_ina_scores SET gameData='old',score=5,date=1 WHERE player_id=2");ag_sql("INSERT INTO fixture_ina_scores(game_name,player_id,score,date,gameData) VALUES('old_game',2,7,2,'higher'),('old_game',2,3,3,'lower')");ps_load($reverse?'lower':'higher');$cases++;}
 ps_reset();ag_sql('UPDATE fixture_ina_scores SET gameData=NULL WHERE player_id=2');ps_load('');$cases++;
 foreach(array(str_repeat('x',65536),str_repeat('😀',16384),"bad\xff",array('nested'),null)as$bad){ps_reset();$before=ps_snapshot();as_check(!ps_success(ps_request('saveGame',$bad))&&ps_snapshot()===$before,'Invalid, oversized or nested data returns no success and changes nothing');$cases++;}
 foreach(array('loadGame','saveGame')as$mode){foreach(array('logout','wrong-owner','logged-out','inactive','missing-user','case-sid','offline','game-off','ban','posts','rank','game-rank','level','group','pending-group','category-group','missing-category','missing-parent','pending-cap','old-cap','future-cap','wrong-cap-owner','wrong-game','missing-cap','duplicate-cap','case-hash','wrong-gid','duplicate-game','foreign-origin','guest')as$bad){
  ps_reset();$extra=array();
  if($bad==='logout'){ag_sql("DELETE FROM fixture_sessions WHERE session_id='fixture-import'");}
  elseif($bad==='wrong-owner'){ag_sql('UPDATE fixture_sessions SET session_user_id=8');}
  elseif($bad==='logged-out'){ag_sql('UPDATE fixture_sessions SET session_logged_in=0');}
  elseif($bad==='inactive'){ag_sql('UPDATE fixture_users SET user_active=0 WHERE user_id=2');}
  elseif($bad==='missing-user'){ag_sql('DELETE FROM fixture_users WHERE user_id=2');}
  elseif($bad==='case-sid'){$userdata['session_id']='FIXTURE-IMPORT';}
  elseif($bad==='offline'){ag_sql("UPDATE fixture_ina_data SET config_value='1' WHERE config_name='games_offline'");}
  elseif($bad==='game-off'){ag_sql('UPDATE fixture_ina_games SET game_avail=0');}
  elseif($bad==='ban'){ag_sql('UPDATE fixture_users SET arcade_banned=1 WHERE user_id=2');}
  elseif($bad==='posts'||$bad==='rank'){ag_sql("UPDATE fixture_ina_data SET config_value='1' WHERE config_name='games_".($bad==='posts'?'posts':'rank')."_required'");}
  elseif($bad==='game-rank'){ag_sql('UPDATE fixture_ina_games SET rank_required=1');}
  elseif($bad==='level'){ag_sql('UPDATE fixture_ina_games SET level_required=1');}
  elseif($bad==='group'||$bad==='pending-group'){ag_sql('UPDATE fixture_ina_games SET group_required=5');if($bad==='pending-group'){ag_sql("INSERT INTO fixture_groups(group_id,group_name,group_description,group_single_user) VALUES(5,'Fixture','',0)");ag_sql('INSERT INTO fixture_user_group(user_id,group_id,user_pending) VALUES(2,5,1)');}}
  elseif($bad==='category-group'){ag_sql('UPDATE fixture_ina_cat SET group_required=5 WHERE cat_id=7');}
  elseif($bad==='missing-category'){ag_sql('DELETE FROM fixture_ina_cat WHERE cat_id=7');}
  elseif($bad==='missing-parent'){ag_sql('UPDATE fixture_ina_cat SET cat_parent=50 WHERE cat_id=7');}
  elseif($bad==='pending-cap'){ag_sql('UPDATE fixture_ina_sessions SET page='.PAGE_ARCADE_LAUNCH_PENDING);}
  elseif($bad==='old-cap'){ag_sql('UPDATE fixture_ina_sessions SET start_time=1');}
  elseif($bad==='future-cap'){ag_sql('UPDATE fixture_ina_sessions SET start_time='.(time()+3600));}
  elseif($bad==='wrong-cap-owner'){ag_sql('UPDATE fixture_ina_sessions SET user_id=8');}
  elseif($bad==='wrong-game'){$extra['gid']='42';}
  elseif($bad==='missing-cap'){ag_sql('DELETE FROM fixture_ina_sessions');}
  elseif($bad==='duplicate-cap'){ag_sql('INSERT INTO fixture_ina_sessions SELECT * FROM fixture_ina_sessions');}
  elseif($bad==='case-hash'){$extra['arcade_hash']=str_repeat('A',32);}
  elseif($bad==='wrong-gid'){$extra['gid']='41junk';}
  elseif($bad==='duplicate-game'){ag_sql("INSERT INTO fixture_ina_games(game_id,game_name) VALUES(42,'old_game')");}
  elseif($bad==='foreign-origin'){$_SERVER['HTTP_SEC_FETCH_SITE']='cross-site';}
  elseif($bad==='guest'){$userdata['user_id']=ANONYMOUS;}
  $before=ps_snapshot();as_check(!ps_success(ps_request($mode,'must not save',$extra))&&ps_snapshot()===$before,'Actual protocol rejects stale/current denied access '.$mode.'/'.$bad);$cases++;
 }}
 foreach(array('loadGame','saveGame')as$mode){foreach(array('write-1','write-2','progress-read','audit','commit','commit-after')as$fault){if($mode==='loadGame'&&$fault==='write-2'){continue;}ps_reset();$db->fault=$fault;$before=ps_snapshot();$r=ps_request($mode);as_check(!ps_success($r),'No success after owned failure '.$mode.'/'.$fault);$after=ps_snapshot();if($fault==='commit-after'){as_check($mode==='loadGame'||count(ps_saved())===1,'Unknown commit acknowledgement is not replayed');}else{as_check($before===$after,'Full progress/audit rollback '.$mode.'/'.$fault);}$cases++;}}
 foreach(array('logout','inactive','group','game','config')as$kind){ps_reset();if($kind==='group'){ag_sql("INSERT INTO fixture_groups(group_id,group_name,group_description,group_single_user) VALUES(5,'Fixture','',0)");ag_sql('INSERT INTO fixture_user_group(user_id,group_id,user_pending) VALUES(2,5,0)');ag_sql('UPDATE fixture_ina_games SET group_required=5');}$db->fault='progress-peer-'.$kind;as_check(ps_success(ps_request()),'Progress commits before actual competing access change '.$kind);ag_reap();$before=ps_snapshot();as_check(!ps_success(ps_request())&&ps_snapshot()===$before,'Following request sees completed access change '.$kind);$cases++;$serialized++;}
 ps_reset();ag_sql("INSERT INTO fixture_ina_game_saves(user_id,game_id,game_data) VALUES(8,41,'other user'),(2,42,'other game')");as_check(ps_success(ps_request()),'Own save with unrelated private data');$rows=ps_saved();as_check(count($rows)===3&&$rows[1]['game_data']==='other game'&&$rows[2]['game_data']==='other user','Other users/games never overwritten');$cases++;
 ps_reset();as_check(ps_success(ps_request()),'Progress before rename');ag_sql("UPDATE fixture_ina_games SET game_name='renamed' WHERE game_id=41");ag_sql("UPDATE fixture_ina_sessions SET game_name='renamed'");ps_load('fixture progress');$cases++;
 ps_reset();as_check(ps_success(ps_request()),'Progress before score reset');ag_sql('DELETE FROM fixture_ina_scores');ps_load('fixture progress');$cases++;
 foreach(array('success','same','missing')as$factory){ps_reset();as_check($db->sql_query('START TRANSACTION')&&$db->sql_query("UPDATE fixture_groups SET group_name='Caller' WHERE group_id=99"),'Own caller transaction canary');if($factory!=='success'){$db->factory_mode=$factory;}$r=ps_request();as_check(ps_success($r)===($factory==='success')&&ag_rows('SELECT group_name FROM fixture_groups WHERE group_id=99')[0]['group_name']==='Sentinel'&&$db->sql_query('ROLLBACK'),'Caller transaction never borrowed or committed '.$factory);$cases++;}
 foreach(array(iNA_GAME_SAVES,USERS_TABLE,SESSIONS_TABLE,iNA_SCORES)as$table){ps_reset();ag_sql('ALTER TABLE '.$table.' ENGINE=MyISAM');$before=ps_snapshot();as_check(!ps_success(ps_request())&&ps_snapshot()===$before,'Legacy engine refused without fallback '.$table);ag_sql('ALTER TABLE '.$table.' ENGINE=InnoDB ROW_FORMAT=DYNAMIC');$cases++;}
 ps_reset();ag_sql('ALTER TABLE fixture_ina_game_saves ADD UNIQUE KEY unsafe_data(game_data(30))');$before=ps_snapshot();as_check(!ps_success(ps_request())&&ps_snapshot()===$before,'Unknown uniqueness cannot overwrite another user');ag_sql('ALTER TABLE fixture_ina_game_saves DROP KEY unsafe_data');$cases++;
 echo 'Native Arcade progress: '.$cases.' cases, '.$serialized.' serialized access changes, '.$as_checks." assertions PASS; actual protocol, exact UTF-8 checkpoints, legacy reads, score independence, current authority and owned rollback.\n";
}finally{ag_reap();su_close();if($db){$db->sql_close();}if($ag_peer){$ag_peer->sql_close();}if($su_observer){$su_observer->sql_close();}if($created){as_check(preg_match('/^codex_arc_progress_[a-f0-9]{16}$/D',$schema)&&$control->sql_query('DROP DATABASE '.$schema),'Drop exact own progress schema');}if($control){$control->sql_close();}}
