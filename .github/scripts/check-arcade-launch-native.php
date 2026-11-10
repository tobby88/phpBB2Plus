<?php
if (PHP_SAPI !== 'cli' || getenv('PHPBB_ARCADE_LAUNCH_NATIVE') !== '1') { echo "Arcade launch checks require an explicitly enabled disposable database.\n"; return; }
require __DIR__.'/arcade-game-native-fixture.php';
require_once $as_app.'includes/functions_arcade_launch_storage.php';
require_once $as_app.'includes/functions_arcade_statistics_storage.php';

class ArcadeLaunchNativeDatabase extends ArcadeGameNativeDatabase
{
    function __construct($host,$user,$password,$schema,$persistent=false,$owned=false)
    {
        parent::__construct($host,$user,$password,$schema,$persistent,$owned);
        $this->fixture_factory=function()use($host,$user,$password,$schema){return new ArcadeLaunchNativeDatabase($host,$user,$password,$schema,false,true);};
    }
    function sql_query($sql='', $transaction=false)
    {
        global $ag_peer,$ag_async;
        if ($this->owned && !$this->fired && strpos($sql,'UPDATE fixture_ina_games SET played=')===0
            && in_array($this->fault,array('serialize-reset','serialize-logout'),true)) {
            $this->fired=true;
            if ($this->fault==='serialize-reset') { as_check($ag_peer->sql_query('START TRANSACTION'),'Competing reset owns its transaction'); }
            $change=$this->fault==='serialize-reset'?'UPDATE fixture_ina_games SET played=0':"DELETE FROM fixture_sessions WHERE session_id='fixture-import'";
            as_check(mysqli_query($ag_peer->db_connect_id,$change,MYSQLI_ASYNC),'Queue competing reset/logout');
            $read=array($ag_peer->db_connect_id);$error=$reject=array();
            as_check(mysqli_poll($read,$error,$reject,0,0)===0,'Competing reset/logout waits for owned locks');$ag_async=true;
        }
        return parent::sql_query($sql,$transaction);
    }
}
function al_reset()
{
    global $arcade,$al_game,$al_hash;
    ag_reset();$arcade->arcade_config['games_posts_required']=0;$arcade->arcade_config['games_offline']=0;$arcade->arcade_config['games_rank_required']=0;
    ag_sql('UPDATE fixture_ina_cat SET cat_parent=9 WHERE cat_id=7');
    ag_sql('UPDATE fixture_users SET user_level=0 WHERE user_id=2');
    ag_sql('UPDATE fixture_sessions SET session_admin=0');
    ag_sql('DELETE FROM fixture_ina_sessions');$al_hash=str_repeat('a',32);
    ag_sql("INSERT INTO fixture_ina_sessions(user_id,game_name,arcade_hash,start_time) VALUES(2,'old_game','$al_hash',".time().')');
    $al_game=ag_rows('SELECT * FROM fixture_ina_games WHERE game_id=41')[0];
}
function al_call($controller=false)
{
    global $db,$al_game,$al_hash,$as_app,$phpbb_root_path,$phpEx,$lang,$arcade,$userdata,$board_config;
    if ($controller) {
        $game_info=$al_game;$session=$al_hash;$win='SELF';
        if (!defined('iNA')) { define('iNA',true); }
        $source=file_get_contents($as_app.'loader.php');$a=$controller==='prefix'?5:strpos($source,'require_once($phpbb_root_path');$b=strpos($source,'// Check the extension of the game',$a);
        as_check($a!==false&&$b>$a,'Whole actual loader accounting boundary');
        // Only resolve the bootstrap against source; runtime caches stay in
        // the owned fixture root, and the real controller/callee execute.
        $body=str_replace('require_once($phpbb_root_path .','require_once($as_app .',substr($source,$a,$b-$a));
        try { eval($body);return array('error'=>false); }
        catch(ArcadeSummaryExit $error){return array('error'=>true,'message'=>$error->getMessage());}
    }
    try { return array('error'=>false,'result'=>phpbb_arcade_record_launch($db,$al_game,$al_hash)); }
    catch(PhpbbOwnedStorageFailure $error){return array('error'=>true,'key'=>$error->getMessage());}
}
function al_recorded($count=1,$user=2,$cats=array(-1=>12,7=>12,9=>0))
{
    as_check((int)ag_rows('SELECT played FROM fixture_ina_games WHERE game_id=41')[0]['played']===12+$count,'Exactly one game increment per completed launch');
    foreach($cats as$id=>$before){$row=ag_rows('SELECT total_played,last_game,last_player,last_time FROM fixture_ina_cat WHERE cat_id='.$id)[0];as_check((int)$row['total_played']===$before+$count&&$row['last_game']==='old_game'&&(int)$row['last_player']===$user&&(int)$row['last_time']>time()-60,'Matching category and last-player metadata '.$id);}
    $players=ag_rows('SELECT * FROM fixture_ina_user_data WHERE user_id='.$user);as_check(count($players)===1&&$players[0]['last_played']==='old_game'&&(int)$players[0]['last_played_date']>time()-60,'One matching player row');
}
function al_cache($evicted)
{
    global $phpbb_root_path;foreach(array('games','categories')as$key){as_check(is_file($phpbb_root_path.'cache/arcade_'.$key.'.cache')!==$evicted,'Launch cache invalidation '.$key);}
    foreach(array('best_player','best_at_player')as$key){as_check(is_file($phpbb_root_path.'cache/arcade_'.$key.'.cache'),'Unrelated score cache retained');}
}
$db=$control=$ag_peer=null;$created=false;$schema='codex_arc_launch_'.bin2hex(phpbb_random_bytes(8));$cases=$serialized=0;
try {
    $port=getenv('PHPBB_ARCADE_GAME_PORT')?:'3306';as_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Own loopback port');
    $host='127.0.0.1:'.$port;$password=getenv('PHPBB_ARCADE_GAME_PASSWORD')?:'';
    $control=new sql_db($host,'root',$password,'',false);as_check($control->sql_query('CREATE DATABASE '.$schema.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Own disposable launch schema');$created=true;
    $db=new ArcadeLaunchNativeDatabase($host,'root',$password,$schema,false);$ag_peer=new sql_db($host,'root',$password,$schema,false);
    $ag_tables=array(USERS_TABLE,SESSIONS_TABLE,JR_ADMIN_TABLE,GROUPS_TABLE,RANKS_TABLE,iNA_GAMES,iNA_CAT);
    foreach(phpbb_arcade_game_references()as$ref){$ag_tables[]=$ref[0];}$ag_tables=array_values(array_unique($ag_tables));
    $canonical=file_get_contents($as_app.'install/schemas/mysql_schema.sql');
    foreach($ag_tables as$table){$suffix=substr($table,8);as_check(preg_match('/CREATE TABLE phpbb_'.preg_quote($suffix,'/').' \(.*?\) ENGINE=InnoDB[^;]*;/s',$canonical,$m)===1,'Canonical launch table '.$suffix);ag_sql(str_replace('phpbb_'.$suffix,$table,$m[0]));}
    as_open();$ag_routes=aif_routes();
    al_reset();as_check(!al_call()['error'],'Ordinary member without ACP can launch');al_recorded();al_cache(true);$cases++;
    al_reset();$before=ag_snapshot();as_check(al_call('prefix')['error']&&ag_snapshot()===$before,'Actual missing-asset loader leaves all counters unchanged');$cases++;
    as_check(mkdir($as_root.'/games',0700)&&file_put_contents($as_root.'/games/old_game','fixture-only asset')===18,'Own inert game asset');
    al_reset();as_check(!al_call('prefix')['error'],'Actual loader gates and accounting succeed with own asset');al_recorded();$cases++;
    al_reset();ag_sql('DELETE FROM fixture_ina_user_data WHERE user_id=2');as_check(!al_call()['error']&&!al_call()['error'],'First/repeated launch creates one player');al_recorded(2);$cases++;
    foreach(array('default','no-backslash')as$mode){al_reset();$name="Grüße😀'game";$escaped=$ag_peer->sql_escape($name);ag_sql("UPDATE fixture_ina_games SET game_name='$escaped'");ag_sql("UPDATE fixture_ina_sessions SET game_name='$escaped'");$al_game=ag_rows('SELECT * FROM fixture_ina_games WHERE game_id=41')[0];if($mode==='no-backslash'){$db->factory_mode=$mode;}as_check(!al_call()['error'],'Raw UTF-8 and quoted game identity '.$mode);foreach(ag_rows('SELECT last_game FROM fixture_ina_cat WHERE cat_id IN(-1,7,9)')as$row){as_check($row['last_game']===$name,'Exact counter metadata bytes '.$mode);}as_check(ag_rows('SELECT last_played FROM fixture_ina_user_data WHERE user_id=2')[0]['last_played']===$name,'Exact player metadata bytes '.$mode);$cases++;}
    foreach(array('english','german')as$locale){al_reset();as_language($locale);as_check(!al_call(true)['error'],'Actual localized loader success '.$locale);al_recorded();al_reset();as_language($locale);$db->fault='write-3';$before=ag_snapshot();$r=al_call(true);as_check($r['error']&&$r['message']===$lang['arcade_launch_failed']&&ag_snapshot()===$before,'Actual localized loader failure rolls back '.$locale);$cases+=2;}
    foreach(array('write-1','write-2','write-3','write-4','write-5','commit','commit-after')as$fault){al_reset();$db->fault=$fault;$before=ag_snapshot();as_check(al_call()['error'],'Injected failure returned '.$fault);if($fault==='commit-after'){al_recorded();}else{as_check(ag_snapshot()===$before,'No partially published play '.$fault);}al_cache(strpos($fault,'commit')===0);$cases++;}
    foreach(array('owner','logged-out','deleted-session','case-sid','inactive','posts','rank','guest-denied','offline')as$bad){al_reset();if($bad==='owner'){ag_sql('UPDATE fixture_sessions SET session_user_id=8');}elseif($bad==='logged-out'){ag_sql('UPDATE fixture_sessions SET session_logged_in=0');}elseif($bad==='deleted-session'){ag_sql('DELETE FROM fixture_sessions');}elseif($bad==='case-sid'){$userdata['session_id']='FIXTURE-IMPORT';}elseif($bad==='inactive'){ag_sql('UPDATE fixture_users SET user_active=0 WHERE user_id=2');}elseif($bad==='posts'){$arcade->arcade_config['games_posts_required']=1;}elseif($bad==='rank'){ag_sql('UPDATE fixture_ina_games SET rank_required=1');$al_game=ag_rows('SELECT * FROM fixture_ina_games WHERE game_id=41')[0];}elseif($bad==='offline'){$arcade->arcade_config['games_offline']=1;}else{$userdata['user_id']=ANONYMOUS;ag_sql('UPDATE fixture_sessions SET session_user_id=-1,session_logged_in=0');ag_sql('UPDATE fixture_ina_sessions SET user_id=-1');}$before=ag_snapshot();as_check(al_call()['error']&&ag_snapshot()===$before,'Current actor cannot launch '.$bad);al_cache(false);$cases++;}
    foreach(array('revoke-active','revoke-session')as$fault){al_reset();$db->fault=$fault;$before=ag_snapshot();as_check(al_call()['error']&&ag_snapshot()===$before,'Revocation before owned transaction refuses launch '.$fault);$cases++;}
    al_reset();$arcade->arcade_config['games_rank_required']=1;$before=ag_snapshot();as_check(al_call()['error']&&ag_snapshot()===$before,'Current rank also enforces board-wide arcade access');$cases++;
    foreach(array(null,array('bad'),false,'',str_repeat('a',31))as$hash){al_reset();$al_hash=$hash;$before=ag_snapshot();as_check(al_call()['error']&&ag_snapshot()===$before,'Malformed launch capability has no writes');$cases++;}
    foreach(array(null,array(),array('game_id'=>array(41)))as$bad){al_reset();$al_game=$bad;$before=ag_snapshot();as_check(al_call()['error']&&ag_snapshot()===$before,'Malformed selected game has no writes');$cases++;}
    foreach(array('wrong-user','wrong-game','expired','future','missing','duplicate','case-hash')as$bad){al_reset();if($bad==='wrong-user'){ag_sql('UPDATE fixture_ina_sessions SET user_id=8');}elseif($bad==='wrong-game'){ag_sql("UPDATE fixture_ina_sessions SET game_name='other'");}elseif($bad==='expired'){ag_sql('UPDATE fixture_ina_sessions SET start_time=1');}elseif($bad==='future'){ag_sql('UPDATE fixture_ina_sessions SET start_time='.(time()+3600));}elseif($bad==='missing'){ag_sql('DELETE FROM fixture_ina_sessions');}elseif($bad==='duplicate'){ag_sql('INSERT INTO fixture_ina_sessions SELECT * FROM fixture_ina_sessions');}else{ag_sql("UPDATE fixture_ina_sessions SET arcade_hash=UPPER(arcade_hash)");}$before=ag_snapshot();as_check(al_call()['error']&&ag_snapshot()===$before,'Reject stale/ambiguous launch capability '.$bad);$cases++;}
    foreach(array('game_name','game_path','cat_id','game_avail','allow_guest')as$field){al_reset();$value=$field==='game_name'?"'renamed'":($field==='game_path'?"'elsewhere/'":($field==='cat_id'?'9':($field==='game_avail'?'0':'1')));ag_sql('UPDATE fixture_ina_games SET '.$field.'='.$value);$before=ag_snapshot();as_check(al_call()['error']&&ag_snapshot()===$before,'Selected game changed '.$field);$cases++;}
    foreach(array('missing-root','missing-category','link-category','missing-parent','link-parent','overflow-game','overflow-category')as$bad){al_reset();if($bad==='missing-root'){ag_sql('DELETE FROM fixture_ina_cat WHERE cat_id=-1');}elseif($bad==='missing-category'){ag_sql('DELETE FROM fixture_ina_cat WHERE cat_id=7');}elseif($bad==='link-category'){ag_sql("UPDATE fixture_ina_cat SET cat_type='l' WHERE cat_id=7");}elseif($bad==='missing-parent'){ag_sql('DELETE FROM fixture_ina_cat WHERE cat_id=9');}elseif($bad==='link-parent'){ag_sql("UPDATE fixture_ina_cat SET cat_type='l' WHERE cat_id=9");}elseif($bad==='overflow-game'){ag_sql('UPDATE fixture_ina_games SET played=4294967295');}else{ag_sql('UPDATE fixture_ina_cat SET total_played=18446744073709551615 WHERE cat_id=7');}$before=ag_snapshot();as_check(al_call()['error']&&ag_snapshot()===$before,'No partial writes for invalid counter topology '.$bad);$cases++;}
    foreach(array(null,'0','-1')as$category){al_reset();ag_sql('UPDATE fixture_ina_games SET cat_id='.($category===null?'NULL':$category));$al_game=ag_rows('SELECT * FROM fixture_ina_games WHERE game_id=41')[0];as_check(!al_call()['error'],'Legacy root category accepted');al_recorded(1,2,array(-1=>12));as_check(ag_rows('SELECT total_played FROM fixture_ina_cat WHERE cat_id=7')[0]['total_played']==='12','No unrelated category increment');$cases++;}
    al_reset();ag_sql('UPDATE fixture_ina_cat SET cat_parent=7 WHERE cat_id=7');as_check(!al_call()['error'],'Self parent never doubles increment');al_recorded(1,2,array(-1=>12,7=>12));$cases++;
    al_reset();$userdata['user_id']=ANONYMOUS;ag_sql('UPDATE fixture_sessions SET session_user_id=-1,session_logged_in=0');ag_sql('UPDATE fixture_ina_sessions SET user_id=-1');ag_sql('UPDATE fixture_ina_games SET allow_guest=1');$al_game=ag_rows('SELECT * FROM fixture_ina_games WHERE game_id=41')[0];as_check(!al_call()['error'],'Allowed guest launch preserved');al_recorded(1,-1);$cases++;
    foreach(array('success','write-3','same','missing')as$mode){al_reset();as_check($db->sql_query('START TRANSACTION')&&$db->sql_query("UPDATE fixture_groups SET group_name='Caller' WHERE group_id=99"),'Own caller canary');if($mode==='write-3'){$db->fault=$mode;}elseif($mode!=='success'){$db->factory_mode=$mode;}$r=al_call();as_check($mode==='success'?!$r['error']:$r['error'],'Isolated launch result '.$mode);as_check(ag_rows('SELECT group_name FROM fixture_groups WHERE group_id=99')[0]['group_name']==='Sentinel'&&$db->sql_query('ROLLBACK'),'Caller transaction untouched '.$mode);$cases++;}
    foreach(array(SESSIONS_TABLE,USERS_TABLE,iNA_GAMES,iNA_CAT,iNA_SESSIONS,iNA_USER_DATA)as$table){al_reset();ag_sql('ALTER TABLE '.$table.' ENGINE=MyISAM');$before=ag_snapshot();as_check(al_call()['error']&&ag_snapshot()===$before,'Reject nontransactional launch table '.$table);ag_sql('ALTER TABLE '.$table.' ENGINE=InnoDB ROW_FORMAT=DYNAMIC');$cases++;}
    foreach(array('serialize-reset','serialize-logout')as$fault){al_reset();$db->fault=$fault;as_check(!al_call()['error'],'Launch completes before competing operation '.$fault);ag_reap();if($fault==='serialize-reset'){ag_sql('UPDATE fixture_ina_cat SET total_played=0');ag_sql('COMMIT');as_check(ag_rows('SELECT played FROM fixture_ina_games')[0]['played']==='0','Reset follows complete launch');}else{$before=ag_snapshot();as_check(al_call()['error']&&ag_snapshot()===$before,'Following launch sees logout');}$cases++;$serialized++;}
    // Execute the real global reset on its owned connection, then a launch.
    al_reset();ag_sql('UPDATE fixture_users SET user_level=1 WHERE user_id=2');ag_sql('UPDATE fixture_sessions SET session_admin=1');$userdata['session_admin']=1;profile_fixture_request(array('sid'=>'fixture-import'));
    as_check(phpbb_arcade_statistics_reset($db,'reset_played')['reset']&&!al_call()['error'],'Actual reset then launch');as_check(ag_rows('SELECT played FROM fixture_ina_games')[0]['played']==='1','Game and actual reset serialize');foreach(ag_rows('SELECT total_played FROM fixture_ina_cat WHERE cat_id IN(-1,7,9)')as$row){as_check($row['total_played']==='1','Category and actual reset serialize');}$cases++;
    echo 'Native Arcade launches: '.$cases.' cases, '.$serialized.' serialized competitors, '.$as_checks." assertions PASS; atomic counters, current sessions/capabilities, owned rollback, actual EN/DE loader.\n";
} finally {
    ag_reap();if($db){$db->sql_close();}if($ag_peer){$ag_peer->sql_close();}if($created){as_check(preg_match('/^codex_arc_launch_[a-f0-9]{16}$/D',$schema)&&$control->sql_query('DROP DATABASE '.$schema),'Drop only own launch schema');}if($control){$control->sql_close();}
    if($as_created){foreach(array('games','categories','user_id','version')as$key){$file=$as_root.'/cache/arcade_'.$key.'.cache';if(is_file($file)){as_check(!is_link($file)&&unlink($file),'Own launch cache cleanup');}}if(is_dir($as_root.'/games')){as_check(!is_link($as_root.'/games')&&!is_link($as_root.'/games/old_game')&&unlink($as_root.'/games/old_game')&&rmdir($as_root.'/games'),'Remove only own inert asset');}as_close();}
}
