<?php
if (PHP_SAPI !== 'cli' || !defined('IN_PHPBB')) { return; }

class RtgOwnerAdapter
{
    var $inner, $db_connect_id;
    function __construct($owner) { $this->inner=$owner; $this->db_connect_id=$owner->db_connect_id; }
    function __call($name,$args) { return call_user_func_array(array($this->inner,$name),$args); }
    function sql_query($sql,$transaction=false)
    {
        if (preg_match('/^INSERT INTO fixture_ina_rate \(rate_game_name,rate_user_id,rate_user_ip,rate_point\) VALUES\(/D',$sql)) {
            return $this->inner->inner->sql_query($sql,$transaction);
        }
        return $this->inner->sql_query($sql,$transaction);
    }
    function sql_close() { try { return $this->inner->sql_close(); } finally { $this->db_connect_id=null; } }
}
class RtgSource extends TpSource
{
    var $factory_calls=0,$identity_failure=false;
    function sql_query($sql='',$transaction=false)
    {
        if($this->identity_failure&&strpos($sql,'SELECT CONNECTION_ID()')===0){return false;}
        return parent::sql_query($sql,$transaction);
    }
    function sql_dedicated_connection()
    {
        $this->factory_calls++;
        $candidate=parent::sql_dedicated_connection();
        if ($GLOBALS['tp_factory']==='normal') { $candidate->inner=new RtgOwnerAdapter($candidate->inner); }
        return $candidate;
    }
}
class RtgCache
{
    var $calls=array(),$throw=false;
    function clear_cache($name,$root)
    {
        nt_check($GLOBALS['tn_open_owners']===0,'Rating releases its owned locks before optional cache invalidation');
        $this->calls[]=array($name,$root);
        if ($this->throw) { throw new RuntimeException('SYNTHETIC_PRIVATE_CACHE'); }
    }
}
class RtgControllerExit extends RuntimeException
{
    var $level;
    function __construct($level,$message) { parent::__construct($message);$this->level=$level; }
}
class RtgRedirectExit extends RuntimeException {}
eval('namespace RtgUiSink; function message_die($level,$message){throw new \\RtgControllerExit($level,$message);} function redirect($url){throw new \\RtgRedirectExit($url);}');

function rtg_prepare($locale='english')
{
    global $tn_tables,$tn_app,$tn_peer,$userdata,$board_config,$lang,$arcade,$phpEx;
    tp_prepare(null,USER,$locale);
    require_once $tn_app.'includes/constants_arcade.php';
    if (!in_array('ina_data',$tn_tables,true)) {
        $canonical=file_get_contents($tn_app.'install/schemas/mysql_schema.sql');
        foreach(array('ina_data','ina_games','ina_cat','ina_rate','groups')as$table) {
            nt_check(preg_match('/CREATE TABLE phpbb_'.$table.'\s*\(.*?\) ENGINE=InnoDB[^;]*;/s',$canonical,$match)===1,'Actual canonical rating participant');
            tn_sql(str_replace('phpbb_'.$table,'fixture_'.$table,$match[0]));$tn_tables[]=$table;
        }
        nt_check(preg_match('/ALTER TABLE phpbb_users\s+ADD games_block_pm[^;]*;/s',$canonical,$match)===1,'Actual installer Arcade account extensions');
        tn_sql(str_replace('phpbb_users','fixture_users',$match[0]));
    }
    tn_sql('START TRANSACTION');
    try {
        foreach(array('ina_rate','ina_games','ina_cat','ina_data','groups')as$table){tn_sql('DELETE FROM fixture_'.$table);}
        tn_sql("INSERT INTO fixture_ina_data(config_name,config_value) VALUES('games_rate','1'),('games_default_rate','5'),('games_offline','0'),('games_mod_ban_users','1'),('games_posts_required','0'),('games_rank_required','0'),('games_group_required','0')");
        tn_sql("INSERT INTO fixture_ina_cat(cat_id,cat_name,cat_type,cat_parent) VALUES(-1,'Root fixture','c',0),(3,'Category & Grüße','c',0),(4,'Second category','c',0)");
        tn_sql("INSERT INTO fixture_ina_games(game_id,game_name,game_desc,cat_id,game_avail) VALUES(1,'synthetic-rating','Synthetic & Grüße 😀',3,1),(2,'second-game','Second fixture game',4,1)");
        tn_sql("INSERT INTO fixture_ina_rate(rate_game_name,rate_user_id,rate_user_ip,rate_point) VALUES('synthetic-rating',9,'7f000002',5),('synthetic-rating',10,'7f000003',3)");
        tn_sql("INSERT INTO fixture_groups(group_id,group_name,group_description) VALUES(100,'Current approved group','Synthetic'),(101,'Second fixture group','Synthetic')");
        tn_sql("UPDATE fixture_sessions SET session_ip='7f000001' WHERE session_user_id=7");
        tn_sql('COMMIT');
    } catch(Exception$error) { tn_sql('ROLLBACK');throw$error; }
    require $tn_app.'language/lang_'.$locale.'/lang_extend_arcade.php';
    $board_config['default_lang']=$locale;$arcade=new RtgCache();
    require_once $tn_app.'includes/functions_arcade_rating_storage.php';
}
function rtg_call($write=false,$point='3',$id=1,$actor=null)
{
    if ($actor===null) { $actor=$GLOBALS['userdata']; }
    try { return array('ok'=>true,'value'=>$write?phpbb_arcade_rate_game($GLOBALS['db'],$actor,$id,$point):phpbb_arcade_rating_view($GLOBALS['db'],$actor,$id),'error'=>''); }
    catch(PhpbbArcadeRatingFailure$error) { return array('ok'=>false,'value'=>null,'error'=>$error->getMessage()); }
}
function rtg_controller($post=array(),$get=array('game_id'=>'1'),$method='GET')
{
    global $db,$userdata,$lang,$phpEx,$phpbb_root_path,$board_config,$template,$HTTP_POST_VARS,$HTTP_GET_VARS;
    $HTTP_POST_VARS=$_POST=$post;$HTTP_GET_VARS=$_GET=$get;$_SERVER['REQUEST_METHOD']=$method;
    $template=nt_template('arcade_rate_body.tpl');$phpbb_root_path=$GLOBALS['tn_app'];
    $source=file_get_contents($phpbb_root_path.'arcade_rate.php');
    $start=strpos($source,"require_once(\$phpbb_root_path . 'includes/functions_arcade_rating_storage.'");
    nt_check($start!==false,'Actual full rating request/view/token/write/controller body');
    $code=substr($source,$start);$code=preg_replace('/\?>\s*$/D','',$code);
    foreach(array('page_header','page_tail')as$part) {
        $include="include(\$phpbb_root_path . 'includes/$part.'.\$phpEx);";
        nt_check(strpos($code,$include)!==false,'Only unrelated global header/tail replaced by a controlled UI sink');
        $code=str_replace($include,'/* unrelated global layout sink */',$code);
    }
    $message='';$level=null;$redirect='';ob_start();
    try { eval('namespace RtgUiSink; use \\Exception; use \\Error;'.$code); }
    catch(RtgControllerExit$error) { $message=$error->getMessage();$level=$error->level; }
    catch(RtgRedirectExit$error) { $redirect=$error->getMessage(); }
    finally { $html=ob_get_contents();ob_end_clean(); }
    return array('html'=>$html,'message'=>$message,'level'=>$level,'redirect'=>$redirect);
}

function rtg_change($kind)
{
    $sql=array(
        'logout'=>'UPDATE fixture_sessions SET session_logged_in=0 WHERE session_user_id=7',
        'expired'=>'UPDATE fixture_sessions SET session_time=0 WHERE session_user_id=7',
        'future'=>'UPDATE fixture_sessions SET session_time='.(time()+600).' WHERE session_user_id=7',
        'case'=>"UPDATE fixture_sessions SET session_id=UPPER(session_id) WHERE session_user_id=7",
        'foreign'=>'UPDATE fixture_sessions SET session_user_id=9 WHERE session_user_id=7',
        'inactive'=>'UPDATE fixture_users SET user_active=0 WHERE user_id=7',
        'blocked'=>'UPDATE fixture_users SET user_blocktime='.(time()+3600).' WHERE user_id=7',
        'banned'=>"INSERT INTO fixture_banlist(ban_userid,ban_ip,ban_email) VALUES(7,'','')",
        'deleted'=>'DELETE FROM fixture_users WHERE user_id=7',
        'disabled'=>"UPDATE fixture_ina_data SET config_value='0' WHERE config_name='games_rate'",
        'offline'=>"UPDATE fixture_ina_data SET config_value='1' WHERE config_name='games_offline'",
        'arcade-ban'=>'UPDATE fixture_users SET arcade_banned=1 WHERE user_id=7',
        'posts'=>"UPDATE fixture_ina_data SET config_value='1' WHERE config_name='games_posts_required'",
        'rank'=>"UPDATE fixture_ina_data SET config_value='1' WHERE config_name='games_rank_required'",
        'global-group'=>"UPDATE fixture_ina_data SET config_value='100' WHERE config_name='games_group_required'",
        'group'=>'UPDATE fixture_ina_games SET group_required=100 WHERE game_id=1',
        'cat-group'=>'UPDATE fixture_ina_cat SET group_required=100 WHERE cat_id=3',
        'missing-parent'=>'UPDATE fixture_ina_cat SET cat_parent=99 WHERE cat_id=3',
        'cycle'=>'UPDATE fixture_ina_cat SET cat_parent=3 WHERE cat_id=3',
        'missing-category'=>'DELETE FROM fixture_ina_cat WHERE cat_id=3',
        'unavailable'=>'UPDATE fixture_ina_games SET game_avail=0 WHERE game_id=1',
        'level'=>'UPDATE fixture_ina_games SET level_required='.(int)ADMIN.' WHERE game_id=1',
        'game-rank'=>'UPDATE fixture_ina_games SET rank_required=1 WHERE game_id=1',
        'missing-game'=>'DELETE FROM fixture_ina_games WHERE game_id=1',
        'name-collision'=>"UPDATE fixture_ina_games SET game_name='SYNTHETIC-RATING' WHERE game_id=2",
        'bad-scale'=>"UPDATE fixture_ina_data SET config_value='256' WHERE config_name='games_default_rate'",
        'missing-setting'=>"DELETE FROM fixture_ina_data WHERE config_name='games_rate'",
        'case-setting'=>"UPDATE fixture_ina_data SET config_name='GAMES_RATE' WHERE config_name='games_rate'",
        'bad-ttl'=>"UPDATE fixture_config SET config_value='0' WHERE config_name='session_length'",
        'invalid-ip'=>"UPDATE fixture_sessions SET session_ip='' WHERE session_user_id=7"
    );
    if(isset($sql[$kind])){tn_sql($sql[$kind]);return;}
    if($kind==='pending-group'||$kind==='missing-group'){
        tn_sql('UPDATE fixture_ina_games SET group_required=100 WHERE game_id=1');
        tn_sql('INSERT INTO fixture_user_group(group_id,user_id,user_pending) VALUES(100,7,'.($kind==='pending-group'?1:0).')');
        if($kind==='missing-group'){tn_sql('DELETE FROM fixture_groups WHERE group_id=100');}return;
    }
    if($kind==='parent-group'){
        tn_sql("INSERT INTO fixture_ina_cat(cat_id,cat_name,cat_type,cat_parent,group_required) VALUES(99,'Private ancestor','c',0,100)");
        tn_sql('UPDATE fixture_ina_cat SET cat_parent=99 WHERE cat_id=3');return;
    }
    throw new RuntimeException('Unknown synthetic rating transition');
}

function rtg_only_rating_changed($before,$committed)
{
    $after=tp_snapshot();$saved=array();$retained=array();
    foreach($after['ina_rate']as$row){if((int)$row['rate_user_id']===7){$saved[]=$row;}else{$retained[]=$row;}}
    $after['ina_rate']=$retained;
    return $after===$before&&($committed?(count($saved)===1&&$saved[0]['rate_game_name']==='synthetic-rating'&&$saved[0]['rate_user_ip']==='7f000001'&&(int)$saved[0]['rate_point']===3):!$saved);
}

function tp_arcade_ratings()
{
    global $db,$host,$password,$schema,$userdata,$arcade,$tp_hook,$tp_factory,$tp_busy;
    $db->sql_close();$db=new RtgSource($host,'root',$password,$schema,false);
    $cases=$views=$faults=0;
    foreach(array('english','german')as$locale) {
        rtg_prepare($locale);$before=tp_snapshot();$trace=array();$GLOBALS['tp_hook']=function($phase,$sql)use(&$trace){if($phase==='before'){$trace[]=$sql;}};$result=rtg_call();$GLOBALS['tp_hook']=null;nt_check($result['ok'],'Initial native rating view: '.json_encode(array($result,array_slice($trace,-5))));
        nt_check($result['ok']&&(int)$result['value']['game']['total']===2&&(float)$result['value']['game']['rating']===4.0&&!$result['value']['already_rated']&&$result['value']['scale']===5,'Current deterministic game aggregate');
        $body=rtg_controller();nt_check($body['message']===''&&strpos($body['html'],'Synthetic &amp; Grüße 😀')!==false&&strpos($body['html'],'&amp;amp;')===false&&substr_count($body['html'],'<option value=')===6,'Actual XS form uses current scale, localized heading and escaped stored caption');
        nt_check(tp_snapshot()===$before&&tp_released(),'Rating display leaves every native dataset unchanged');$cases++;$views++;
        $body=rtg_controller(array('sid'=>$userdata['session_id'],'rate'=>'3'),array('game_id'=>'1'),'POST');
        nt_check(strpos($body['message'],$GLOBALS['lang']['Stored'])!==false&&count(tp_rows('SELECT * FROM fixture_ina_rate'))===3&&count($arcade->calls)===1&&tp_released(),'Actual signed controller confirms one committed rating and optional cache invalidation');$cases++;
        $body=rtg_controller();nt_check(substr_count($body['html'],'disabled="disabled"')===2&&strpos($body['html'],$GLOBALS['lang']['already_rated'])!==false,'Already-rated controls are disabled after a fresh current read');$cases++;$views++;
        foreach(array('logout','expired','future','case','foreign','inactive','blocked','banned','deleted','disabled','offline','arcade-ban','posts','rank','global-group','group','pending-group','missing-group','cat-group','parent-group','missing-parent','cycle','missing-category','unavailable','level','game-rank','missing-game','name-collision','bad-scale','missing-setting','case-setting','bad-ttl','invalid-ip')as$kind){
            rtg_prepare($locale);rtg_change($kind);$before=tp_snapshot();$read=rtg_call();$write=rtg_call(true);
            nt_check(!$read['ok']&&!$write['ok']&&tp_snapshot()===$before&&tp_released()&&!$arcade->calls,'Current read/write policy rejects changed state without mutation: '.$kind);
            if(in_array($kind,array('logout','blocked','missing-game','bad-scale'),true)){
                $body=rtg_controller();nt_check($body['message']!==''&&strpos($body['html'],'Synthetic')===false,'Actual failed view exposes no denied title or form');$views++;
                $body=rtg_controller(array('sid'=>$userdata['session_id'],'rate'=>'3'),array('game_id'=>'1'),'POST');nt_check($body['message']!==''&&strpos($body['message'],$GLOBALS['lang']['Stored'])===false&&tp_snapshot()===$before&&tp_released(),'Actual failed POST never claims a saved rating');
            }$cases++;
        }
        foreach(array('moderator-offline','admin-offline','approved-group','admin-hidden','promotion','ban-disabled','current-ip','legacy-collation')as$kind){
            rtg_prepare($locale);
            if($kind==='moderator-offline'||$kind==='admin-offline'){tn_sql('UPDATE fixture_users SET user_level='.($kind==='admin-offline'?ADMIN:MOD).' WHERE user_id=7');rtg_change('offline');}
            elseif($kind==='approved-group'){rtg_change('global-group');rtg_change('group');rtg_change('cat-group');tn_sql('INSERT INTO fixture_user_group(group_id,user_id,user_pending) VALUES(100,7,0)');}
            elseif($kind==='admin-hidden'){tn_sql('UPDATE fixture_users SET user_level='.(int)ADMIN.' WHERE user_id=7');rtg_change('unavailable');rtg_change('cat-group');}
            elseif($kind==='promotion'){tn_sql('UPDATE fixture_users SET user_level='.(int)ADMIN.' WHERE user_id=7');rtg_change('level');$userdata['user_level']=USER;}
            elseif($kind==='ban-disabled'){rtg_change('arcade-ban');tn_sql("UPDATE fixture_ina_data SET config_value='0' WHERE config_name='games_mod_ban_users'");}
            elseif($kind==='current-ip'){$userdata['session_ip']='ffffffff';}
            else{tn_sql("UPDATE fixture_ina_rate SET rate_game_name='SYNTHETIC-RATING'");}
            $before=tp_snapshot();$read=rtg_call();nt_check($read['ok']&&(int)$read['value']['game']['total']===2&&tp_snapshot()===$before&&tp_released(),'Legitimate current policy and retained historical ratings: '.$kind);
            $write=rtg_call(true);$saved=tp_rows('SELECT rate_user_ip,rate_point FROM fixture_ina_rate WHERE rate_user_id=7');nt_check($write['ok']&&count($saved)===1&&$saved[0]['rate_user_ip']==='7f000001'&&(int)$saved[0]['rate_point']===3&&tp_released(),'Current legitimate writer and stored session IP: '.$kind);$cases++;
        }
        rtg_prepare($locale);$userdata['user_level']=ADMIN;rtg_change('level');$before=tp_snapshot();nt_check(!rtg_call()['ok']&&!rtg_call(true)['ok']&&tp_snapshot()===$before&&tp_released(),'Stale cached administrator role cannot override current normal role');$cases++;
        rtg_prepare($locale);tn_sql("UPDATE fixture_ina_data SET config_value='2' WHERE config_name='games_default_rate'");$body=rtg_controller();$before=tp_snapshot();nt_check(substr_count($body['html'],'<option value=')===3&&!rtg_call(true,'3')['ok']&&tp_snapshot()===$before&&tp_released(),'Current scale bounds both actual UI and submitted values');$cases++;$views++;
        rtg_prepare($locale);tn_sql("INSERT INTO fixture_ina_rate(rate_game_name,rate_user_id,rate_user_ip,rate_point) VALUES('SYNTHETIC-RATING',7,'7f000001',4),('synthetic-rating',7,'7f000001',2)");$before=tp_snapshot();$read=rtg_call();$write=rtg_call(true);nt_check($read['ok']&&$read['value']['already_rated']&&(int)$read['value']['game']['total']===4&&!$write['ok']&&$write['error']==='already_rated'&&tp_snapshot()===$before&&tp_released(),'Existing duplicate/case-variant history remains intact and does not permit another rating');$cases++;
        foreach(array(1,255)as$scale){rtg_prepare($locale);tn_sql("UPDATE fixture_ina_data SET config_value='$scale' WHERE config_name='games_default_rate'");$body=rtg_controller();nt_check(substr_count($body['html'],'<option value=')===$scale+1,'Actual scale stays within the existing unsigned rating storage');$result=rtg_call(true,(string)$scale);nt_check($result['ok']&&(int)tp_rows('SELECT rate_point FROM fixture_ina_rate WHERE rate_user_id=7')[0]['rate_point']===$scale&&tp_released(),'Both physical rating endpoints save without truncation');$cases++;$views++;}
    }
    foreach(array(0,-1,8388608,'1junk','1.0','1e0','01',true,1.0,array(1),null)as$id){rtg_prepare();$before=tp_snapshot();$calls=$db->factory_calls;nt_check(!rtg_call(false,'3',$id)['ok']&&!rtg_call(true,'3',$id)['ok']&&$db->factory_calls===$calls&&tp_snapshot()===$before,'Malformed IDs fail before either factory');$cases++;}
    foreach(array(0,-1,256,'1junk','1.0','1e0','01',true,1.0,array(1),null)as$point){rtg_prepare();$before=tp_snapshot();$calls=$db->factory_calls;nt_check(!rtg_call(true,$point)['ok']&&$db->factory_calls===$calls&&tp_snapshot()===$before,'Malformed rating values fail before ownership');$cases++;}
    foreach(array('GET','missing','nested','wrong','numeric')as$kind){rtg_prepare();$before=tp_snapshot();$calls=$db->factory_calls;$post=array('sid'=>$userdata['session_id'],'rate'=>'3');if($kind==='missing'){unset($post['sid']);}elseif($kind==='nested'){$post['sid']=array($post['sid']);}elseif($kind==='wrong'){$post['sid']=str_repeat('f',32);}elseif($kind==='numeric'){$post['sid']=7;}$body=rtg_controller($post,array('game_id'=>'1'),$kind==='GET'?'GET':'POST');nt_check($body['message']===$GLOBALS['lang']['Not_Authorised']&&$db->factory_calls===$calls&&tp_snapshot()===$before&&tp_released(),'Actual unsigned or wrong-method POST is rejected before storage');$cases++;}
    foreach(array('STRICT_ALL_TABLES,ONLY_FULL_GROUP_BY','STRICT_ALL_TABLES,ONLY_FULL_GROUP_BY,NO_BACKSLASH_ESCAPES')as$mode){
        rtg_prepare();tn_sql("SET SESSION sql_mode='$mode'");$GLOBALS['tp_hook']=function($phase,$sql,$candidate)use($mode){if($phase==='after'&&strpos($sql,'SET SESSION lock_wait_timeout=3')===0){nt_check($candidate->inner->inner->inner->sql_query("SET SESSION sql_mode='$mode'")!==false,'Own actual read candidate strict SQL mode');}};
        try{$read=rtg_call();nt_check($read['ok']&&(int)$read['value']['game']['total']===2,'Actual aggregate succeeds with strict grouping');}finally{$GLOBALS['tp_hook']=null;}
        $name="Quote' slash\\ Grüße 😀";tn_sql("UPDATE fixture_ina_games SET game_name='".$GLOBALS['tn_peer']->sql_escape($name)."' WHERE game_id=1");tn_sql('DELETE FROM fixture_ina_rate');
        $tp_hook=function($phase,$sql,$candidate)use($mode){if($phase==='after'&&strpos($sql,'SET SESSION sql_mode=CONCAT_WS')===0){nt_check($candidate->inner->inner->inner->sql_query("SET SESSION sql_mode='$mode'")!==false,'Own actual writer strict SQL mode');}};
        try{$write=rtg_call(true);nt_check($write['ok']&&tp_rows('SELECT rate_game_name FROM fixture_ina_rate')[0]['rate_game_name']===$name&&tp_released(),'Canonical quoted Unicode identity survives actual rating write');}finally{$tp_hook=null;}$cases++;
    }
    foreach(array('guest','logged-array','id-array','id-range','sid-empty','sid-array','sid-long','sid-nul')as$kind){
        rtg_prepare();$actor=$userdata;if($kind==='guest'){$actor['session_logged_in']=false;}elseif($kind==='logged-array'){$actor['session_logged_in']=array(1);}elseif($kind==='id-array'){$actor['user_id']=array(7);}elseif($kind==='id-range'){$actor['user_id']=8388608;}elseif($kind==='sid-empty'){$actor['session_id']='';}elseif($kind==='sid-array'){$actor['session_id']=array('a');}elseif($kind==='sid-long'){$actor['session_id']=str_repeat('a',33);}else{$actor['session_id']="a\0b";}
        $before=tp_snapshot();$calls=$db->factory_calls;nt_check(!rtg_call(false,'3',1,$actor)['ok']&&!rtg_call(true,'3',1,$actor)['ok']&&$db->factory_calls===$calls&&tp_snapshot()===$before&&tp_released(),'Malformed current actor fails before both factories: '.$kind);$cases++;
    }
    foreach(array('config','sessions','users','banlist','ina_data','ina_games','ina_cat','ina_rate','groups','user_group')as$table){
        rtg_prepare();tn_sql('ALTER TABLE fixture_'.$table.' ENGINE=MyISAM');
        try{$before=tp_snapshot();nt_check(!rtg_call()['ok']&&!rtg_call(true)['ok']&&tp_snapshot()===$before&&tp_released(),'Both participants reject each nontransactional canonical table: '.$table);}
        finally{tn_sql('ALTER TABLE fixture_'.$table.' ENGINE=InnoDB ROW_FORMAT=DYNAMIC');}$cases++;
    }
    foreach(array('column-collation','compact','view','missing')as$kind){
        rtg_prepare();if($kind==='column-collation'){tn_sql('ALTER TABLE fixture_ina_rate MODIFY rate_game_name varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL');}
        elseif($kind==='compact'){tn_sql('ALTER TABLE fixture_ina_rate ROW_FORMAT=COMPACT');}
        else{tn_sql('RENAME TABLE fixture_ina_rate TO fixture_ina_rate_saved');if($kind==='view'){tn_sql('CREATE VIEW fixture_ina_rate AS SELECT * FROM fixture_ina_rate_saved');}}
        try{$before=$kind==='missing'?null:tp_snapshot();nt_check(!rtg_call()['ok']&&!rtg_call(true)['ok']&&($before===null||tp_snapshot()===$before)&&tp_released(),'Invalid participant shape never accepts a partial rating: '.$kind);}
        finally{if($kind==='column-collation'){tn_sql('ALTER TABLE fixture_ina_rate MODIFY rate_game_name varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL');}elseif($kind==='compact'){tn_sql('ALTER TABLE fixture_ina_rate ROW_FORMAT=DYNAMIC');}else{if($kind==='view'){tn_sql('DROP VIEW fixture_ina_rate');}tn_sql('RENAME TABLE fixture_ina_rate_saved TO fixture_ina_rate');}}$cases++;
    }
    foreach(array('missing','borrow','throw','busy')as$factory){
        rtg_prepare();if($factory==='busy'){$tp_busy=$db->sql_dedicated_connection();nt_check(mysqli_query($tp_busy->db_connect_id,'START TRANSACTION')&&mysqli_query($tp_busy->db_connect_id,"UPDATE fixture_users SET username='Borrowed rating' WHERE user_id=10"),'Own pending borrowed transaction');}
        $tp_factory=$factory;$before=tp_snapshot();
        try{nt_check(!rtg_call()['ok']&&!rtg_call(true)['ok']&&tp_snapshot()===$before&&$db->db_connect_id,'Unsafe factory cannot read/commit/close caller work: '.$factory);if($factory==='busy'){$r=mysqli_query($tp_busy->db_connect_id,'SELECT username FROM fixture_users WHERE user_id=10');$row=mysqli_fetch_assoc($r);mysqli_free_result($r);nt_check($row['username']==='Borrowed rating'&&tp_rows('SELECT username FROM fixture_users WHERE user_id=10')[0]['username']!=='Borrowed rating','Refused borrowed transaction stays open and private');}}
        finally{$tp_factory='normal';if($factory==='busy'){mysqli_query($tp_busy->db_connect_id,'ROLLBACK');$tp_busy->sql_close();}}nt_check(tp_released(),'Only adopted rating connections are released');$cases++;
    }
    rtg_prepare();$before=tp_snapshot();$calls=$db->factory_calls;$db->identity_failure=true;
    try{nt_check(!rtg_call()['ok']&&!rtg_call(true)['ok']&&$db->factory_calls===$calls&&tp_snapshot()===$before,'Failed source identity never obtains a candidate');}finally{$db->identity_failure=false;}$cases++;
    // Every SQL statement after actual ownership is established, including
    // connection checks and both sides of COMMIT, is faulted independently.
    foreach(array(false,true)as$write){
        rtg_prepare();$trace=array();$tp_hook=function($phase,$sql)use(&$trace){if($phase==='before'){$trace[]=$sql;}};nt_check(rtg_call($write)['ok'],'Successful full native trace');$tp_hook=null;$owned=false;$wroteAt=null;$positions=array();
        foreach($trace as$i=>$sql){if($write?strpos($sql,'SELECT GET_LOCK(')===0:$sql==='SET SESSION lock_wait_timeout=3'){$owned=true;}if(strpos($sql,'INSERT INTO fixture_ina_rate ')===0){$wroteAt=$i;}if($owned&&$sql!=='ROLLBACK'){$positions[$i+1]=$sql;}}
        foreach($positions as$position=>$statement){foreach(array('before','after')as$phase){
            rtg_prepare();$before=tp_snapshot();$seen=0;$reached=false;$tp_hook=function($event,$sql)use($position,$phase,&$seen,&$reached){if($event==='before'){$seen++;}if($event===$phase&&$seen===$position){$reached=true;return false;}};
            try{$result=rtg_call($write);$committed=$write&&$phase==='after'&&$statement==='COMMIT'&&$wroteAt!==null&&$position>$wroteAt;nt_check(!$result['ok']&&$reached&&rtg_only_rating_changed($before,$committed)&&tp_released(),'Every owned SQL fault rolls back or retains exactly one complete acknowledged-unknown rating');}
            finally{$tp_hook=null;}$faults++;
        }}
    }
    foreach(array('english','german')as$locale){
        rtg_prepare($locale);$before=tp_snapshot();$wrote=false;$tp_hook=function($phase,$sql)use(&$wrote){if($phase==='after'&&strpos($sql,'INSERT INTO fixture_ina_rate ')===0){$wrote=true;}if($phase==='after'&&$sql==='COMMIT'&&$wrote){return false;}};
        try{$body=rtg_controller(array('sid'=>$userdata['session_id'],'rate'=>'3'),array('game_id'=>'1'),'POST');nt_check($body['message']===$GLOBALS['lang']['arcade_rating_unconfirmed']&&rtg_only_rating_changed($before,true)&&count($arcade->calls)===1&&tp_released(),'Actual uncertain POST never claims success or replays a complete commit');}finally{$tp_hook=null;}
        $body=rtg_controller();nt_check(strpos($body['html'],$GLOBALS['lang']['already_rated'])!==false&&substr_count($body['html'],'disabled="disabled"')===2&&rtg_only_rating_changed($before,true),'Next actual page observes the committed rating');$retry=rtg_call(true);nt_check(!$retry['ok']&&$retry['error']==='already_rated'&&rtg_only_rating_changed($before,true)&&tp_released(),'A retry cannot insert a duplicate after a lost acknowledgement');$cases++;$views++;
    }
    rtg_prepare();$before=tp_snapshot();$arcade->throw=true;nt_check(rtg_call(true)['ok']&&rtg_only_rating_changed($before,true)&&tp_released(),'Cache failure cannot undo or misreport a confirmed durable rating');$cases++;
    rtg_prepare();$before=tp_snapshot();$changed=false;$tp_hook=function($phase,$sql)use(&$changed){if($phase==='after'&&strpos($sql,'INSERT INTO fixture_ina_rate ')===0){$GLOBALS['tp_hook']=null;tn_sql("INSERT INTO fixture_banlist(ban_userid,ban_ip,ban_email) VALUES(7,'','')");$changed=true;}};
    try{$result=rtg_call(true);$after=tp_snapshot();$after['banlist']=$before['banlist'];nt_check(!$result['ok']&&$changed&&$after===$before&&tp_released(),'A real late stored ban is rechecked and rolls back only the rating');}finally{$tp_hook=null;}$cases++;
    rtg_prepare();nt_check(mysqli_query($db->db_connect_id,'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ')&&mysqli_query($db->db_connect_id,'START TRANSACTION'),'Own caller snapshot');$r=$db->sql_query('SELECT * FROM fixture_ina_rate');$db->sql_freeresult($r);nt_check(mysqli_query($db->db_connect_id,"INSERT INTO fixture_config(config_name,config_value) VALUES('rating-pending','private')"),'Own unrelated pending caller work');
    try{$write=rtg_call(true);$read=rtg_call();nt_check($write['ok']&&$read['ok']&&$read['value']['already_rated']&&!tp_rows("SELECT * FROM fixture_config WHERE config_name='rating-pending'")&&count(tp_rows('SELECT * FROM fixture_ina_rate'))===3&&tp_released(),'Fresh reader/writer bypass caller snapshot without committing its pending work');$r=$db->sql_query("SELECT config_value FROM fixture_config WHERE config_name='rating-pending'");$row=$db->sql_fetchrow($r);$db->sql_freeresult($r);nt_check($row['config_value']==='private','Caller still owns its pending transaction');}finally{mysqli_query($db->db_connect_id,'ROLLBACK');}$cases++;
    rtg_prepare();$changed=false;$tp_hook=function($phase,$sql)use(&$changed){if($phase==='after'&&strpos($sql,'SELECT config_value FROM fixture_config')===0){$GLOBALS['tp_hook']=null;tn_sql("INSERT INTO fixture_ina_rate(rate_game_name,rate_user_id,rate_user_ip,rate_point) VALUES('synthetic-rating',7,'7f000001',1)");$changed=true;}};
    try{$read=rtg_call();nt_check($read['ok']&&$changed&&!$read['value']['already_rated']&&(int)$read['value']['game']['total']===2&&tp_released(),'Independent peer writes while the actual view retains one coherent read-only snapshot');$next=rtg_call();nt_check($next['ok']&&$next['value']['already_rated']&&(int)$next['value']['game']['total']===3,'Next actual view sees the completed peer rating');}finally{$tp_hook=null;}$cases++;
    rtg_prepare();rtg_change('global-group');rtg_change('group');tn_sql('INSERT INTO fixture_user_group(group_id,user_id,user_pending) VALUES(100,7,0)');$blocked=0;$coordinator=false;
    $tp_hook=function($phase,$sql)use(&$blocked,&$coordinator){
        if($phase!=='after'||strpos($sql,'INSERT INTO fixture_ina_rate ')!==0){return;}$GLOBALS['tp_hook']=null;
        $lock=$GLOBALS['tn_lock'];$rows=tn_rows("SELECT GET_LOCK('$lock',0) acquired");$coordinator=count($rows)===1&&(string)$rows[0]['acquired']==='0';if(!$coordinator){tn_sql("DO RELEASE_LOCK('$lock')");}nt_check($coordinator,'Real peer cannot obtain the rating mutation coordinator');
        $queries=array('fixture_users WHERE user_id=7',"fixture_sessions WHERE session_id='".str_repeat('a',32)."'","fixture_config WHERE config_name='session_length'","fixture_ina_data WHERE config_name='games_rate'",'fixture_ina_games WHERE game_id=1','fixture_ina_cat WHERE cat_id=3','fixture_groups WHERE group_id=100','fixture_user_group WHERE group_id=100 AND user_id=7',"fixture_ina_rate WHERE rate_game_name='synthetic-rating' AND rate_user_id=7");
        foreach($queries as$target){tn_sql('START TRANSACTION');try{$r=$GLOBALS['tn_peer']->sql_query('SELECT * FROM '.$target.' FOR UPDATE NOWAIT');$error=$GLOBALS['tn_peer']->sql_error();if($r!==false){$GLOBALS['tn_peer']->sql_freeresult($r);}nt_check($r===false&&in_array((int)$error['code'],array(1205,3572),true),'Actual canonical authority/rating row remains pinned until commit: '.$target);$blocked++;}finally{tn_sql('ROLLBACK');}}
    };
    try{$write=rtg_call(true);nt_check($write['ok']&&$blocked===9&&$coordinator&&tp_released(),'Nine real row conflicts plus shared writer coordination preserve current rating authority');$retry=rtg_call(true);nt_check(!$retry['ok']&&$retry['error']==='already_rated'&&count(tp_rows('SELECT * FROM fixture_ina_rate WHERE rate_user_id=7'))===1,'Next serialized submission cannot create another rating');}finally{$tp_hook=null;}$cases++;
    rtg_prepare();$before=tp_snapshot();$changed=false;$tp_hook=function($phase,$sql)use(&$changed){if($phase==='after'&&strpos($sql,'INSERT INTO fixture_ina_rate ')===0){$GLOBALS['tp_hook']=null;tn_sql("INSERT INTO fixture_ina_games(game_id,game_name,game_desc,cat_id,game_avail) VALUES(3,'SYNTHETIC-RATING','Concurrent identity',3,1)");$changed=true;}};
    try{$result=rtg_call(true);$after=tp_snapshot();$after['ina_games']=array_values(array_filter($after['ina_games'],function($row){return (int)$row['game_id']!==3;}));nt_check(!$result['ok']&&$changed&&$after===$before&&tp_released(),'A real late catalogue identity collision rolls back only the pending rating');}finally{$tp_hook=null;}$cases++;
    foreach(array('rate_point=0',"rate_user_ip='ffffffff'","rate_game_name='second-game'",'rate_user_id=9')as$corruption){rtg_prepare();$before=tp_snapshot();tn_sql('CREATE TRIGGER fixture_rtg_corrupt BEFORE INSERT ON fixture_ina_rate FOR EACH ROW SET NEW.'.$corruption);try{$result=rtg_call(true);nt_check(!$result['ok']&&tp_snapshot()===$before&&tp_released(),'Readback rejects and rolls back an actual stored payload alteration');}finally{tn_sql('DROP TRIGGER fixture_rtg_corrupt');}$cases++;}
    rtg_prepare();tn_sql('ALTER TABLE fixture_ina_rate MODIFY rate_user_id mediumint(8) NULL DEFAULT 0');
    try{tn_sql("INSERT INTO fixture_ina_rate(rate_game_name,rate_user_id,rate_user_ip,rate_point) VALUES('synthetic-rating',NULL,'7f000004',4)");$before=tp_snapshot();$result=rtg_call();nt_check($result['ok']&&(int)$result['value']['game']['total']===2&&(float)$result['value']['game']['rating']===4.0&&tp_snapshot()===$before&&tp_released(),'Legacy NULL-author count/average semantics stay identical without deleting data');}
    finally{tn_sql('DELETE FROM fixture_ina_rate WHERE rate_user_id IS NULL');tn_sql('ALTER TABLE fixture_ina_rate MODIFY rate_user_id mediumint(8) NOT NULL DEFAULT 0');}$cases++;
    echo 'Native Arcade ratings: '.$cases.' cases, '.$faults.' SQL faults, '.$views." actual views, 9 real row conflicts PASS; deterministic aggregates, current authority, bounded values, atomic writes, caller isolation and EN/DE controllers.\n";
}
