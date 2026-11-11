<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_ARCADE_SUBMISSION_NATIVE')!=='1'){echo "Arcade submission checks require an explicitly enabled disposable database.\n";return;}
require __DIR__.'/arcade-submission-fixture.php';
function su_complete()
{
    as_check(su_rows('SELECT score FROM fixture_ina_scores WHERE player_id=2')[0]['score']==='7.0000','Current best stored');
    as_check(su_rows('SELECT score FROM fixture_ina_at_scores WHERE player_id=2')[0]['score']==='7.0000','All-time best stored');
    as_check(su_rows('SELECT player_name FROM fixture_ina_at_scores WHERE player_id=2')[0]['player_name']==='Actor','All-time name snapshot comes from the current account');
    as_check(su_rows('SELECT highscore_score FROM fixture_ina_highscore WHERE highscore_year='.date('Y'))[0]['highscore_score']==='7.0000','Monthly best stored');
    as_check(su_rows('SELECT * FROM fixture_ina_sessions')===array(),'Exactly one successful capability consumed');
    $logs=su_rows('SELECT value FROM fixture_ina_log');as_check(count($logs)===1&&strpos($logs[0]['value'],'protocol=')!==false&&strpos($logs[0]['value'],'fixture-credential')===false&&strpos($logs[0]['value'],str_repeat('a',32))===false,'Only controlled credential-free committed audit: '.json_encode($logs));
}
class ArcadeNotificationFailureFixture
{
    var $queries=array();
    function sql_query($sql){$this->queries[]=$sql;if(strpos($sql,'SELECT config_value FROM ')===0){return 'config';}if(strpos($sql,'SELECT * FROM fixture_ina_data')===0){return 'settings';}return strpos($sql,'SELECT user_id, username, user_notify_pm')===0;}
    function sql_fetchrow($result){if($result==='config'){return array('config_value'=>0);}return array('user_id'=>8,'username'=>'Fixture recipient','games_block_pm'=>0,'user_notify_pm'=>0,'user_email'=>'','user_lang'=>'german','user_active'=>1);}
    function sql_fetchrowset($result){$rows=array();foreach(su_config(array('games_use_pms'=>1))as$key=>$value){$rows[]=array('config_name'=>$key,'config_value'=>$value);}return $rows;}
    function failures(){return count(array_filter($this->queries,function($sql){return strpos($sql,'SELECT COUNT(privmsgs_id)')===0;}));}
}
function su_tour()
{
    global $su_cap,$arcade;
    ag_sql("INSERT INTO fixture_ina_tour(tour_id,tour_name,tour_active,tour_player_turns) VALUES(9,'Fixture tour',2,3)");
    ag_sql("INSERT INTO fixture_ina_tour_data(tour_id,game_name,top_score,top_player) VALUES(9,'old_game',0,0)");
    $data=serialize(array(array('game_name'=>'old_game','score'=>0,'played'=>1),array('game_name'=>'unrelated','score'=>3,'played'=>2,'caption'=>'Grüße 😀')));
    ag_sql("INSERT INTO fixture_ina_tour_play(tour_id,user_id,gamedata) VALUES(9,2,'".$GLOBALS['ag_peer']->sql_escape($data)."')");
    ag_sql('UPDATE fixture_ina_sessions SET page='.PAGE_ARCADE_TOUR.',tour_id=9');
    $su_cap=ag_rows('SELECT * FROM fixture_ina_sessions')[0];$arcade->arcade_session=$su_cap;
}
$db=$control=$ag_peer=$su_observer=null;$created=false;$schema='codex_arc_submit_'.bin2hex(phpbb_random_bytes(8));$cases=$serialized=0;
try{
    $port=getenv('PHPBB_ARCADE_GAME_PORT')?:'3306';as_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Own loopback port');$host='127.0.0.1:'.$port;$password=getenv('PHPBB_ARCADE_GAME_PASSWORD')?:'';
    $control=new sql_db($host,'root',$password,'',false);as_check($control->sql_query('CREATE DATABASE '.$schema.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Own disposable submission schema');$created=true;
    $db=new ArcadeSubmissionNativeDatabase($host,'root',$password,$schema,false);$ag_peer=new sql_db($host,'root',$password,$schema,false);$su_observer=new sql_db($host,'root',$password,$schema,false);su_schema();as_open();$ag_routes=aif_routes();
    su_reset();$r=su_call();as_check(!$r['error'],'Shared member ingestion succeeds');su_complete();$writes=count(array_filter($ag_trace,function($s){return preg_match('/^(INSERT|UPDATE|DELETE) /',$s);}));$cases++;
    foreach(array('english','german')as$locale){foreach(array('standard','pnflash')as$route){su_reset();as_language($locale);$request=$route==='pnflash'?array('func'=>'storeScore','score'=>'7','gid'=>'41','arcade_hash'=>str_repeat('a',32),'sid'=>'fixture-credential-must-not-log'):null;$r=su_controller($route,$request);as_check(!$r['error'],'Actual localized protocol succeeds '.$locale.'/'.$route);su_complete();if($route==='standard'){as_check(strpos($r['vars']['SAVED'],$lang['game_score_updated'])!==false&&strpos($r['vars']['COMMENT_ID'],'game_id=41')!==false&&strpos($r['vars']['RATE_ID'],'game_id=41')!==false&&strpos($r['vars']['FAV_FORM'],'fixture-import')!==false,'Original localized feedback, comment, rate and favorite controls');}else{as_check($r['output']==='&opSuccess=true&endvar=1','Original pnFlash response unchanged');}$cases++;}}
    for($n=1;$n<=$writes;$n++){su_reset();$before=su_snapshot();$db->fault='write-'.$n;as_check(su_call()['error']&&su_snapshot()===$before,'All score/capability/ranking/audit writes roll back at '.$n);$cases++;}
    foreach(array('commit','commit-after','audit')as$fault){su_reset();$db->fault=$fault;$before=su_snapshot();as_check(su_call()['error'],'Failed/uncertain acknowledgement returned '.$fault);if($fault==='commit-after'){su_complete();$after=su_snapshot();as_check(su_call()['error']&&su_snapshot()===$after,'Consumed capability prevents replay after lost commit acknowledgement');}else{as_check(su_snapshot()===$before,'No partial records after '.$fault);}$cases++;}
    foreach(array('standard','pnflash')as$route){su_reset();$before=su_snapshot();$db->fault='write-3';$post=$route==='pnflash'?array('func'=>'storeScore','score'=>'7','gid'=>'41','arcade_hash'=>str_repeat('a',32)):null;$r=su_controller($route,$post);as_check(su_snapshot()===$before,'Actual protocol retains capability and complete old score state after failure '.$route);as_check($route==='pnflash'?$r['output']==='&opSuccess=Missing info&endvar=1':$r['error'],'No success result after failed actual ingestion '.$route);$cases++;}
    foreach(array('revoke-active','revoke-session')as$fault){su_reset();$db->fault=$fault;$before=su_snapshot();as_check(su_call()['error']&&su_snapshot()===$before,'Fresh actor revocation before owned transaction '.$fault);$cases++;}
    su_reset();$db->fault='serialize-logout';as_check(!su_call()['error'],'Ingestion commits before already queued logout');ag_reap();su_complete();$cases++;$serialized++;
    foreach(array('success','write-3','same','missing')as$kind){su_reset();as_check($db->sql_query('START TRANSACTION')&&$db->sql_query("UPDATE fixture_groups SET group_name='Caller' WHERE group_id=99"),'Own caller transaction');if($kind==='write-3'){$db->fault=$kind;}elseif($kind!=='success'){$db->factory_mode=$kind;}$r=su_call();as_check($kind==='success'?!$r['error']:$r['error'],'Dedicated transaction '.$kind);as_check(ag_rows('SELECT group_name FROM fixture_groups WHERE group_id=99')[0]['group_name']==='Sentinel'&&$db->sql_query('ROLLBACK'),'Caller work remains uncommitted '.$kind);$cases++;}
    foreach(array('points','wallet','forbidden','missing')as$kind){su_reset(array('use_rewards_mod'=>1,'use_point_system'=>$kind==='points'?1:0,'use_allowance_system'=>$kind==='points'?0:1));if($kind==='forbidden'){$board_config['default_reward_dbfield']='user_level';}elseif($kind==='missing'){$board_config['default_reward_dbfield']='not_installed_wallet';}ag_sql('UPDATE fixture_ina_games SET game_reward=1');$su_game=ag_rows('SELECT * FROM fixture_ina_games WHERE game_id=41')[0];$before=su_snapshot();$r=su_call();$allowed=in_array($kind,array('points','wallet'),true);as_check($allowed?!$r['error']:$r['error']&&su_snapshot()===$before,'Configured rewards cannot write core or missing account columns '.$kind);if($allowed){$field=$kind==='points'?'user_points':'user_wallet';as_check((float)ag_rows('SELECT '.$field.' FROM fixture_users WHERE user_id=2')[0][$field]===107.0&&(float)$userdata[$field]===107.0,'Reward balance committed and cache updated once '.$kind);$after=su_snapshot();as_check(su_call()['error']&&su_snapshot()===$after,'Reward cannot replay '.$kind);}$cases++;}
    foreach(array(false,true)as$reverse){su_reset();su_tour();ag_sql('UPDATE fixture_ina_games SET reverse_list='.(int)$reverse);$su_game=ag_rows('SELECT * FROM fixture_ina_games WHERE game_id=41')[0];$r=su_call();as_check(!$r['error'],'Tournament result commits with all other boards');$data=phpbb_safe_unserialize_array(ag_rows('SELECT gamedata FROM fixture_ina_tour_play WHERE tour_id=9 AND user_id=2')[0]['gamedata']);as_check($data[0]['played']===1&&$data[0]['score']===7.0&&!empty($data[0]['score_recorded'])&&$data[1]['caption']==='Grüße 😀'&&$data[1]['played']===2,'Tournament score never overwrites turn count or unrelated UTF-8 game data');as_check((float)ag_rows('SELECT top_score FROM fixture_ina_tour_data WHERE tour_id=9')[0]['top_score']===7.0,'First lower-is-better tournament result beats uninitialized zero');$cases++;}
    su_reset();su_tour();$before=su_snapshot();$db->fault='write-3';as_check(su_call()['error']&&su_snapshot()===$before,'Tournament failure rolls back capability, metadata and all boards');$cases++;
    su_reset();ag_sql('UPDATE fixture_ina_sessions SET page='.PAGE_ARCADE_LAUNCH_PENDING);$su_cap=ag_rows('SELECT * FROM fixture_ina_sessions')[0];$before=su_snapshot();as_check(su_call()['error']&&su_snapshot()===$before,'Prepared but failed launch cannot submit a result');$cases++;
    foreach(array('wrong-user','logged-out','case-sid','inactive','banned','game-level','game-group','category-group','pending-group','posts','rank','offline','expired','future','duplicate-cap','missing-cap','changed-game')as$bad){
        su_reset();
        if($bad==='wrong-user'){ag_sql('UPDATE fixture_sessions SET session_user_id=8');}
        elseif($bad==='logged-out'){ag_sql('UPDATE fixture_sessions SET session_logged_in=0');}
        elseif($bad==='case-sid'){$userdata['session_id']='FIXTURE-IMPORT';}
        elseif($bad==='inactive'){ag_sql('UPDATE fixture_users SET user_active=0 WHERE user_id=2');}
        elseif($bad==='banned'){ag_sql('UPDATE fixture_users SET arcade_banned=1 WHERE user_id=2');}
        elseif($bad==='game-level'){ag_sql('UPDATE fixture_ina_games SET level_required=1');$su_game=ag_rows('SELECT * FROM fixture_ina_games WHERE game_id=41')[0];}
        elseif($bad==='game-group'||$bad==='pending-group'){ag_sql('UPDATE fixture_ina_games SET group_required=5');$su_game=ag_rows('SELECT * FROM fixture_ina_games WHERE game_id=41')[0];if($bad==='pending-group'){ag_sql("INSERT INTO fixture_groups(group_id,group_name,group_description) VALUES(5,'Private','')");ag_sql('INSERT INTO fixture_user_group(group_id,user_id,user_pending) VALUES(5,2,1)');}}
        elseif($bad==='category-group'){ag_sql('UPDATE fixture_ina_cat SET group_required=5 WHERE cat_id=7');}
        elseif($bad==='posts'||$bad==='rank'||$bad==='offline'){$key=$bad==='posts'?'games_posts_required':($bad==='rank'?'games_rank_required':'games_offline');ag_sql("UPDATE fixture_ina_data SET config_value=1 WHERE config_name='$key'");}
        elseif($bad==='expired'){ag_sql('UPDATE fixture_ina_sessions SET start_time=1');$su_cap=ag_rows('SELECT * FROM fixture_ina_sessions')[0];}
        elseif($bad==='future'){ag_sql('UPDATE fixture_ina_sessions SET start_time='.(time()+3600));$su_cap=ag_rows('SELECT * FROM fixture_ina_sessions')[0];}
        elseif($bad==='duplicate-cap'){ag_sql('INSERT INTO fixture_ina_sessions SELECT * FROM fixture_ina_sessions');}
        elseif($bad==='missing-cap'){ag_sql('DELETE FROM fixture_ina_sessions');}
        else{ag_sql("UPDATE fixture_ina_games SET game_name='renamed'");}
        $before=su_snapshot();as_check(su_call()['error']&&su_snapshot()===$before,'Reject current authority/capability without any partial state '.$bad);$cases++;
    }
    foreach(array('standard','pnflash')as$route){su_reset();$_SERVER['HTTP_SEC_FETCH_SITE']='cross-site';$before=su_snapshot();$r=su_controller($route,$route==='pnflash'?array('func'=>'storeScore','score'=>'7','arcade_hash'=>str_repeat('a',32)):null);as_check($r['error']&&su_snapshot()===$before,'Actual origin gate retains capability and complete state '.$route);$cases++;}
    su_reset();$old=time()-90000;
    foreach(array(array('c',2,PAGE_ARCADE_LAUNCH_PENDING),array('d',8,PAGE_ARCADE_LAUNCH_PENDING),array('e',2,PAGE_PLAYING_GAMES))as$entry){$hash=str_repeat($entry[0],32);ag_sql("INSERT INTO fixture_ina_sessions(user_id,game_name,arcade_hash,start_time,page,tour_id,session_ip) VALUES(".$entry[1].",'old_game','$hash',$old,".$entry[2].",0,'7f000001')");}
    $prepared=update_ina_session(2,'7f000001',PAGE_PLAYING_GAMES,'old_game','','SELF');
    as_check(!ag_rows("SELECT * FROM fixture_ina_sessions WHERE arcade_hash='".str_repeat('c',32)."'")&&count(ag_rows('SELECT * FROM fixture_ina_sessions'))===4,'Preparation cleans only this account\'s expired pending candidate, preserving active and other-account entries');$cases++;
    profile_fixture_request(array('arcade_hash'=>$prepared));$before=su_snapshot();$denied=false;try{$arcade->get_session();}catch(ArcadeSummaryExit$error){$denied=true;}as_check($denied&&su_snapshot()===$before,'Actual protocol lookup cannot use an unactivated candidate');$cases++;
    foreach(array('points','wallet','both')as$kind){
        su_reset(array('use_rewards_mod'=>1,'use_point_system'=>$kind!=='wallet'?1:0,'use_allowance_system'=>$kind==='wallet'||$kind==='both'?1:0));
        ag_sql('UPDATE fixture_ina_games SET game_charge=5,game_reward=1');$su_game=ag_rows('SELECT * FROM fixture_ina_games WHERE game_id=41')[0];
        $old=$su_cap['arcade_hash'];$prepared=update_ina_session(2,'7f000001',PAGE_PLAYING_GAMES,'old_game','','SELF');
        $pending=ag_rows("SELECT * FROM fixture_ina_sessions WHERE arcade_hash='$prepared'")[0];
        as_check((int)$pending['page']===PAGE_ARCADE_LAUNCH_PENDING&&$pending['user_win']==='SELF'&&count(ag_rows('SELECT * FROM fixture_ina_sessions'))===2,'Preparation preserves running game and initial window mode');
        $launch=phpbb_arcade_record_launch($db,$su_game,$prepared);$field=$kind==='wallet'?'user_wallet':'user_points';
        as_check($launch['recorded']&&(float)ag_rows('SELECT '.$field.' FROM fixture_users WHERE user_id=2')[0][$field]===95.0&&(float)$userdata[$field]===95.0,'Game cost and cached balance commit exactly once '.$kind);
        as_check(count(ag_rows('SELECT * FROM fixture_ina_sessions'))===1&&ag_rows('SELECT * FROM fixture_ina_sessions')[0]['arcade_hash']===$prepared,'Only completed launch retires older capability');
        $su_cap=ag_rows('SELECT * FROM fixture_ina_sessions')[0];as_check((int)$su_cap['page']===PAGE_PLAYING_GAMES,'Pending launch becomes valid only on commit');
        as_check(!su_call()['error']&&(float)ag_rows('SELECT '.$field.' FROM fixture_users WHERE user_id=2')[0][$field]===102.0,'Paid game can submit and reward once '.$kind);
        if($kind==='both'){as_check((float)ag_rows('SELECT user_wallet FROM fixture_users WHERE user_id=2')[0]['user_wallet']===100.0,'Mutually selected reward modes never double-charge');}$cases++;
    }
    foreach(array('funds','write-3','commit-after')as$fault){
        su_reset(array('use_rewards_mod'=>1,'use_point_system'=>1));ag_sql('UPDATE fixture_ina_games SET game_charge=5');if($fault==='funds'){ag_sql('UPDATE fixture_users SET user_points=4 WHERE user_id=2');}
        $su_game=ag_rows('SELECT * FROM fixture_ina_games WHERE game_id=41')[0];$prepared=update_ina_session(2,'7f000001',PAGE_PLAYING_GAMES,'old_game','','SELF');$before=su_snapshot();$db->fault=$fault==='funds'?'':$fault;
        try{phpbb_arcade_record_launch($db,$su_game,$prepared);$failed=false;}catch(PhpbbOwnedStorageFailure$error){$failed=true;}
        as_check($failed,'Paid launch failure returned '.$fault);
        if($fault==='commit-after'){as_check((float)ag_rows('SELECT user_points FROM fixture_users WHERE user_id=2')[0]['user_points']===95.0,'Uncertain launch debited once');$after=su_snapshot();try{phpbb_arcade_record_launch($db,$su_game,$prepared);$failed=false;}catch(PhpbbOwnedStorageFailure$error){$failed=true;}as_check($failed&&su_snapshot()===$after,'Activated capability prevents launch debit replay');}
        else{as_check(su_snapshot()===$before,'Failed paid launch keeps previous active game, money and counters '.$fault);}$cases++;
    }
    foreach(array('success','write-3')as$kind){
        su_reset();su_tour();ag_sql('UPDATE fixture_ina_sessions SET page='.PAGE_ARCADE_LAUNCH_PENDING);$hash=$su_cap['arcade_hash'];$before=su_snapshot();$db->fault=$kind==='success'?'':$kind;
        try{$r=phpbb_arcade_record_launch($db,$su_game,$hash);$failed=false;}catch(PhpbbOwnedStorageFailure$error){$failed=true;}
        if($kind==='success'){$data=phpbb_safe_unserialize_array(ag_rows('SELECT gamedata FROM fixture_ina_tour_play WHERE tour_id=9 AND user_id=2')[0]['gamedata']);as_check(!$failed&&$data[0]['played']===2&&$data[1]['played']===2,'Tournament launch spends precisely one owned turn');$su_cap=ag_rows('SELECT * FROM fixture_ina_sessions')[0];as_check(!su_call()['error'],'Activated tournament capability submits result');$data=phpbb_safe_unserialize_array(ag_rows('SELECT gamedata FROM fixture_ina_tour_play WHERE tour_id=9 AND user_id=2')[0]['gamedata']);as_check($data[0]['played']===2,'Score submission does not spend another turn');}
        else{as_check($failed&&su_snapshot()===$before,'Failed tournament launch spends no turn and retains pending/previous capabilities');}$cases++;
    }
    foreach(array(ARCADE_GET,ARCADE_POST,ARCADE_NEW,ARCADE_IBPRO,ARCADE_IBPROv3,ARCADE_MIXED,ARCADE_pnFlashGames,ARCADE_vBULLETIN,0)as$type){
        su_reset(array('games_cheat_mode'=>$type===ARCADE_GET?0:1));ag_sql('UPDATE fixture_ina_games SET score_type='.(int)$type);
        $post=array('mode'=>'submit','score'=>'7','game_name'=>'old_game','gamename'=>'old_game','gscore'=>'7','gname'=>'old_game','arcade_hash'=>str_repeat('a',32));
        profile_fixture_request($post);if($type===ARCADE_GET){$_SERVER['REQUEST_METHOD']='GET';$_GET=$HTTP_GET_VARS=$HTTP_POST_VARS;$_POST=$HTTP_POST_VARS=array();}
        $r=su_controller();as_check(!$r['error'],'Actual standard controller preserves protocol '.$type);su_complete();$cases++;
    }
    foreach(array(array('nested'),'',0,'NaN','INF','1e999','10000000001')as$bad){
        su_reset();$before=su_snapshot();$r=su_controller('standard',array('game_name'=>'old_game','score'=>$bad,'arcade_hash'=>str_repeat('a',32)));
        $after=su_snapshot();unset($before[iNA_LOG],$after[iNA_LOG]);
        as_check($after===$before,'Malformed/zero/overflow protocol score never consumes the capability or changes a board: '.json_encode($bad));
        foreach(su_rows('SELECT name,value FROM fixture_ina_log')as$log){as_check($log['name']==='BAD_GAME'&&strpos($log['value'],str_repeat('a',32))===false&&strpos($log['value'],'fixture-credential')===false,'Existing rejection diagnostics contain no capability/session credentials');}$cases++;
    }
    foreach(array(USER,ADMIN,MOD)as$level){foreach(array(USER,ADMIN,MOD)as$required){
        su_reset();ag_sql('UPDATE fixture_users SET user_level='.$level.' WHERE user_id=2');ag_sql('UPDATE fixture_ina_games SET level_required='.$required);$su_game=ag_rows('SELECT * FROM fixture_ina_games WHERE game_id=41')[0];
        $before=su_snapshot();$allowed=$level===ADMIN||$required===USER||($level===MOD&&$required===MOD);$r=su_call();as_check($allowed?!$r['error']:$r['error']&&su_snapshot()===$before,'Fresh nonnumeric USER/ADMIN/MOD role semantics '.$level.'/'.$required);$cases++;
    }}
    foreach(array('disabled','administrator')as$mode){su_reset(array('games_mod_ban_users'=>$mode==='disabled'?0:1));ag_sql('UPDATE fixture_users SET arcade_banned=1,user_level='.($mode==='administrator'?ADMIN:USER).' WHERE user_id=2');as_check(!su_call()['error'],'Configured moderation ban exceptions preserved '.$mode);$cases++;}
    foreach(array(false,true)as$foreign){
        su_reset(array('games_guest_highscore'=>1));ag_sql("INSERT INTO fixture_users(user_id,username,user_password,user_active) VALUES(-1,'Anonymous','',0)");
        $userdata['user_id']=ANONYMOUS;ag_sql('UPDATE fixture_sessions SET session_user_id=-1,session_logged_in=0');ag_sql('UPDATE fixture_ina_sessions SET user_id=-1');ag_sql('UPDATE fixture_ina_games SET allow_guest=1');
        $su_game=ag_rows('SELECT * FROM fixture_ina_games WHERE game_id=41')[0];$su_cap=ag_rows('SELECT * FROM fixture_ina_sessions')[0];if($foreign){$userdata['session_ip']='7f000002';}
        $before=su_snapshot();$r=su_call();as_check($foreign?$r['error']&&su_snapshot()===$before:!$r['error'],'Guest capability stays scoped to its started visitor');
        if(!$foreign){as_check(count(ag_rows('SELECT * FROM fixture_ina_scores WHERE player_id=-1'))===1&&!ag_rows('SELECT * FROM fixture_ina_at_scores WHERE player_id=-1')&&!ag_rows('SELECT * FROM fixture_ina_highscore WHERE highscore_user_id=-1'),'Permitted guests retain only their ordinary current board');}$cases++;
    }
    foreach(array('success','serialize-group','serialize-game','serialize-config')as$change){
        su_reset(array('games_group_required'=>5));ag_sql("INSERT INTO fixture_groups(group_id,group_name,group_description) VALUES(5,'Current group','')");ag_sql('INSERT INTO fixture_user_group(group_id,user_id,user_pending) VALUES(5,2,0)');ag_sql('UPDATE fixture_ina_games SET group_required=5');ag_sql('UPDATE fixture_ina_cat SET group_required=5 WHERE cat_id=7');$su_game=ag_rows('SELECT * FROM fixture_ina_games WHERE game_id=41')[0];
        if($change!=='success'){$db->fault=$change;}$r=su_call();as_check(!$r['error'],'Current approved group participates in owned save '.$change);if($change!=='success'){ag_reap();$serialized++;}su_complete();$cases++;
    }
    foreach(array(false,true)as$reverse){
        su_reset();ag_sql('UPDATE fixture_ina_games SET highscore_limit=1,at_highscore_limit=1,reverse_list='.(int)$reverse);$su_game=ag_rows('SELECT * FROM fixture_ina_games WHERE game_id=41')[0];
        ag_sql("INSERT INTO fixture_ina_scores(game_name,player_id,score,date) VALUES('old_game',2,6,3)");ag_sql("INSERT INTO fixture_ina_at_scores(game_name,player_id,score,date) VALUES('old_game',2,6,3)");
        as_check(!su_call()['error'],'Duplicate pruning commits for either score order');foreach(array(iNA_SCORES,iNA_AT_SCORES)as$table){$rows=ag_rows('SELECT * FROM '.$table." WHERE game_name='old_game'");as_check(count($rows)===1&&(int)$rows[0]['player_id']===($reverse?2:8),'Pruning removes exact duplicate rows without deleting a retained player');}$cases++;
    }
    su_reset();$accepted=su_call();as_check(!$accepted['error'],'Notification fixture starts after a real accepted save');$before=su_snapshot();$original=$db;$original_root=$phpbb_root_path;
    try{
        $db=new ArcadeNotificationFailureFixture();$phpbb_root_path=$as_app;$arcade->arcade_config['games_use_pms']=1;$userdata['games_block_pm']=0;
        $caught=false;try{ina_send_user_pm(8,'Fixture subject','Fixture body',2,'NO',true);}catch(ArcadeSummaryExit$error){throw new RuntimeException('Optional delivery must not terminate an accepted score response');}catch(RuntimeException$error){$caught=$error->getMessage()==='Arcade notification unavailable';}
        as_check($caught&&$db->failures()===1,'Actual optional PM failure is catchable before any write or delivery');$cases++;
        $caught=false;try{ina_send_user_pm(8,'Fixture subject','Fixture body',2);}catch(ArcadeSummaryExit$error){$caught=true;}
        as_check($caught&&$db->failures()===2,'Existing required PM callers retain visible error behavior');$cases++;
        $result=$accepted['result'];$result['notifications']=array(array('to'=>8,'alltime'=>false));phpbb_arcade_submission_notify($result);
        as_check($db->failures()===3&&su_snapshot()===$before,'Actual deferred notification failure preserves a committed game and its response');as_check(!array_filter($db->queries,function($sql){return preg_match('/^(INSERT|UPDATE|DELETE) /',$sql);}), 'Notification error fixture sends/writes nothing');$cases++;
    }finally{$db=$original;$phpbb_root_path=$original_root;}
    foreach(array(USERS_TABLE,SESSIONS_TABLE,iNA,iNA_GAMES,iNA_SESSIONS,iNA_SCORES,iNA_AT_SCORES,iNA_HIGHSCORES,iNA_USER_DATA,iNA_TOUR,iNA_TOUR_DATA,iNA_TOUR_PLAY,iNA_LOG,GROUPS_TABLE,USER_GROUP_TABLE,iNA_CAT)as$table){su_reset();ag_sql('ALTER TABLE '.$table.' ENGINE=MyISAM');$before=su_snapshot();as_check(su_call()['error']&&su_snapshot()===$before,'Refuse nontransactional ingestion table '.$table);ag_sql('ALTER TABLE '.$table.' ENGINE=InnoDB ROW_FORMAT=DYNAMIC');$cases++;}
    echo 'Native Arcade submissions: '.$cases.' cases, '.$serialized.' serialized access changes, '.$as_checks." assertions PASS; actual EN/DE protocols, atomic capability/scores/rankings/month/tour/rewards/audit, no replay and no caller commit.\n";
}finally{ag_reap();if($db){$db->sql_close();}if($ag_peer){$ag_peer->sql_close();}if($su_observer){$su_observer->sql_close();}if($created){as_check(preg_match('/^codex_arc_submit_[a-f0-9]{16}$/D',$schema)&&$control->sql_query('DROP DATABASE '.$schema),'Drop only own submission schema');}if($control){$control->sql_close();}su_close();}
