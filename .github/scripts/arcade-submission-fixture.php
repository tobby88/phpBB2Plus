<?php
date_default_timezone_set('UTC');
function phpbb_setcookie($name,$value,$expires,$path,$domain,$secure){$GLOBALS['su_cookies'][]=func_get_args();return true;}
require __DIR__.'/arcade-game-native-fixture.php';
require_once __DIR__.'/native-lock-wait-fixture.php';
as_function($as_app.'includes/functions.php','decode_ip');
require_once $as_app.'includes/functions_arcade_submission.php';
require_once $as_app.'includes/functions_arcade_launch_storage.php';
class ArcadeSubmissionTemplate
{
    var $vars=array();
    function set_filenames($files){}
    function assign_vars($vars){$this->vars=$vars;}
}
class ArcadeSubmissionProtocolExit extends RuntimeException {}
class ArcadeSubmissionNativeDatabase extends ArcadeGameNativeDatabase
{
    function __construct($host,$user,$password,$schema,$persistent=false,$owned=false)
    {
        parent::__construct($host,$user,$password,$schema,$persistent,$owned);
        $this->fixture_factory=function()use($host,$user,$password,$schema){return new ArcadeSubmissionNativeDatabase($host,$user,$password,$schema,false,true);};
    }
    function sql_query($sql='', $transaction=false)
    {
        global $ag_peer,$ag_async,$su_observer;
        if($this->owned&&!$this->fired&&$this->fault==='serialize-logout'&&strpos($sql,'DELETE FROM fixture_ina_sessions WHERE arcade_hash=')===0){
            $this->fired=true;as_check(mysqli_query($ag_peer->db_connect_id,"DELETE FROM fixture_sessions WHERE session_id='fixture-import'",MYSQLI_ASYNC),'Queue real logout');
            $ag_async=true;native_fixture_wait_for_lock($su_observer,mysqli_thread_id($ag_peer->db_connect_id),mysqli_thread_id($this->db_connect_id),'as_check');
        }
        if($this->owned&&!$this->fired&&in_array($this->fault,array('serialize-group','serialize-game','serialize-config'),true)&&strpos($sql,'DELETE FROM fixture_ina_sessions WHERE arcade_hash=')===0){
            $this->fired=true;$changes=array('serialize-group'=>'DELETE FROM fixture_user_group WHERE user_id=2 AND group_id=5',
                'serialize-game'=>'UPDATE fixture_ina_games SET game_avail=0 WHERE game_id=41',
                'serialize-config'=>"UPDATE fixture_ina_data SET config_value='1' WHERE config_name='games_offline'");
            as_check(mysqli_query($ag_peer->db_connect_id,$changes[$this->fault],MYSQLI_ASYNC),'Queue independent current access change');
            $ag_async=true;native_fixture_wait_for_lock($su_observer,mysqli_thread_id($ag_peer->db_connect_id),mysqli_thread_id($this->db_connect_id),'as_check');
        }
        if($this->owned&&!$this->fired&&$this->fault==='audit'&&strpos($sql,'INSERT INTO fixture_ina_log')===0){$this->fired=true;return false;}
        return parent::sql_query($sql,$transaction);
    }
}
function su_config($values=array())
{
    return array_replace(array('version'=>'2.1.8','config_cache'=>0,'use_cache'=>1,'use_rewards_mod'=>0,'use_point_system'=>0,'use_cash_system'=>0,'use_allowance_system'=>0,
        'games_cheat_mode'=>1,'games_at_highscore'=>1,'games_guest_highscore'=>0,'games_show_mhm'=>1,'games_use_log'=>1,'games_use_pms'=>0,
        'games_pm_highscore'=>0,'games_pm_at_highscore'=>0,'games_comments'=>1,'games_rate'=>1,'games_offline'=>0,'games_posts_required'=>0,
        'games_rank_required'=>0,'games_group_required'=>0,'games_mod_ban_users'=>1,'report_cheater'=>0,'warn_cheater'=>0,'default_cash'=>'user_wallet'),$values);
}
function su_settings($values)
{
    global $arcade;$arcade->arcade_config=su_config($values);
    ag_sql('DELETE FROM fixture_ina_data');foreach($arcade->arcade_config as$key=>$value){ag_sql("INSERT INTO fixture_ina_data(config_name,config_value) VALUES('$key','".$GLOBALS['ag_peer']->sql_escape($value)."')");}
}
function su_reset($config=array())
{
    global $arcade,$userdata,$su_game,$su_cap,$board_config,$as_root;
    $arcade->arcade_config['use_cache']=1;ag_reset();ag_sql('START TRANSACTION');
    ag_sql('UPDATE fixture_ina_games SET score_type=2,game_reward=0,game_bonus=0,at_game_bonus=0');
    ag_sql('UPDATE fixture_users SET user_points=100,user_wallet=100,user_level=0,arcade_banned=0');
    ag_sql('UPDATE fixture_sessions SET session_admin=0');
    ag_sql('UPDATE fixture_ina_scores SET score=5 WHERE player_id=2');ag_sql('UPDATE fixture_ina_at_scores SET score=3 WHERE player_id=2');
    ag_sql('DELETE FROM fixture_ina_sessions');$hash=str_repeat('a',32);
    ag_sql("INSERT INTO fixture_ina_sessions(user_id,arcade_hash,game_name,start_time,page,user_win,tour_id,session_ip) VALUES(2,'$hash','old_game',".(time()-5).','.PAGE_PLAYING_GAMES.",'SELF',0,'7f000001')");
    su_settings($config);ag_sql('COMMIT');
    $userdata['username']='Actor';$userdata['session_ip']='7f000001';$userdata['user_level']=0;$userdata['user_points']=100;$userdata['user_wallet']=100;
    $GLOBALS['su_cookies']=array();
    $board_config['cookie_name']='fixture';$board_config['points_name']='Fixture points';$board_config['server_name']='forum.invalid';$board_config['script_path']='';unset($board_config['default_reward_dbfield']);
    $su_game=ag_rows('SELECT * FROM fixture_ina_games WHERE game_id=41')[0];$su_cap=ag_rows('SELECT * FROM fixture_ina_sessions')[0];
    $arcade->arcade_session=$su_cap;$arcade->game_name='old_game';$arcade->score=7;$arcade->user_id=2;$arcade->time_taken=5;$arcade->score_type=0;
    $cache=$as_root.'/cache/arcade_config.cache';if(is_file($cache)){as_check(!is_link($cache)&&unlink($cache),'Discard only own configuration cache between requests');}
    profile_fixture_request(array('game_name'=>'old_game','score'=>'7','arcade_hash'=>$hash,'sid'=>'fixture-credential-must-not-log'));
    $_SERVER['REQUEST_METHOD']='POST';$_SERVER['HTTP_SEC_FETCH_SITE']='same-origin';unset($_SERVER['HTTP_ORIGIN']);
    $_GET=$GLOBALS['HTTP_GET_VARS']=array();$_COOKIE=array();
}
function su_call($route='helper',$score=7)
{
    global $db,$su_game,$su_cap,$arcade;
    try{
        if($route==='class'){$arcade->score=$score;$r=$arcade->newscore();return array('error'=>$r!=='','result'=>$r);}
        return array('error'=>false,'result'=>phpbb_arcade_submit_score($db,$su_game,$su_cap,$score,'standard'));
    }catch(PhpbbOwnedStorageFailure$error){return array('error'=>true,'key'=>$error->getMessage());}
}
function su_controller($route='standard',$post=null)
{
    global $db,$arcade,$userdata,$board_config,$HTTP_POST_VARS,$HTTP_GET_VARS,$phpEx,$phpbb_root_path,$lang,$as_app;
    if($post!==null){profile_fixture_request($post);}
    $template=new ArcadeSubmissionTemplate();
    $file=$route==='standard'?'newscore.php':'pnFlashGames.php';$source=file_get_contents($as_app.$file);
    $a=strpos($source,'if (!phpbb_request_source_is_same_origin())');
    $b=$route==='standard'?strpos($source,"include(\$phpbb_root_path . 'includes/page_header.'",$a):strlen($source);
    as_check($a!==false&&$b>$a,'Complete real protocol/controller after common bootstrap');
    $body=substr($source,$a,$b-$a);
    // Instrument only local source bootstrap resolution; all protocol,
    // validation, SQL, transaction and template binding code remains actual.
    $body=str_replace("require_once(\$phpbb_root_path.'includes/functions_arcade_submission.'.\$phpEx);","require_once(\$as_app.'includes/functions_arcade_submission.'.\$phpEx);",$body);
    $body=str_replace("require_once(\$phpbb_root_path.'includes/functions_arcade_progress_storage.'.\$phpEx);","require_once(\$as_app.'includes/functions_arcade_progress_storage.'.\$phpEx);",$body);
    if($route!=='standard'){$body=preg_replace('/\?>\s*$/','',$body);as_check(substr_count($body,'exit;')===1,'Only the explicit protocol delivery exit is instrumented');$body=str_replace('exit;','throw new ArcadeSubmissionProtocolExit();',$body);}
    ob_start();
    try{eval($body);return array('error'=>false,'vars'=>$template->vars,'output'=>ob_get_contents());}
    catch(ArcadeSummaryExit$error){return array('error'=>true,'message'=>$error->getMessage(),'output'=>ob_get_contents());}
    catch(ArcadeSubmissionProtocolExit$error){return array('error'=>true,'output'=>ob_get_contents());}
    finally{ob_end_clean();}
}
function su_rows($sql)
{
    $out=ag_rows($sql);foreach($out as&$row){foreach(array_keys($row)as$key){if(is_int($key)){unset($row[$key]);}}}unset($row);return $out;
}
function su_snapshot()
{
    $out=array();foreach(array(iNA_GAMES,iNA_SESSIONS,iNA_SCORES,iNA_AT_SCORES,iNA_HIGHSCORES,iNA_USER_DATA,iNA_TOUR,iNA_TOUR_DATA,iNA_TOUR_PLAY,iNA_LOG)as$table){$rows=array_map('json_encode',su_rows('SELECT * FROM '.$table));sort($rows);$out[$table]=$rows;}
    $out['wallets']=su_rows('SELECT user_id,user_points,user_wallet FROM fixture_users ORDER BY user_id');return $out;
}
function su_schema()
{
    global $as_app,$ag_tables;
    $ag_tables=array(USERS_TABLE,SESSIONS_TABLE,JR_ADMIN_TABLE,GROUPS_TABLE,RANKS_TABLE,USER_GROUP_TABLE,iNA_GAMES,iNA_CAT,iNA,iNA_TOUR,iNA_LOG);
    $ag_tables[]=iNA_GAME_SAVES;foreach(phpbb_arcade_game_references()as$ref){$ag_tables[]=$ref[0];}$ag_tables=array_values(array_unique($ag_tables));
    $schema=file_get_contents($as_app.'install/schemas/mysql_schema.sql');foreach($ag_tables as$table){$suffix=substr($table,8);as_check(preg_match('/CREATE TABLE phpbb_'.preg_quote($suffix,'/').' \(.*?\) ENGINE=InnoDB[^;]*;/s',$schema,$m)===1,'Canonical submission fixture '.$suffix);ag_sql(str_replace('phpbb_'.$suffix,$table,$m[0]));}
    ag_sql('ALTER TABLE fixture_users ADD games_block_pm tinyint(1) NOT NULL DEFAULT 1,ADD arcade_banned int NOT NULL DEFAULT 0,ADD user_points DECIMAL(18,8) NOT NULL DEFAULT 100,ADD user_wallet DECIMAL(18,8) NOT NULL DEFAULT 100');
}
function su_close()
{
    global $as_created,$as_root;
    if($as_created){foreach(array('games','categories','user_id','version','config')as$key){$file=$as_root.'/cache/arcade_'.$key.'.cache';if(is_file($file)){as_check(!is_link($file)&&unlink($file),'Own submission cache cleanup');}}as_close();}
}
