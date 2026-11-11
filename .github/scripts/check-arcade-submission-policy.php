<?php
require __DIR__.'/arcade-game-fixture.php';
require_once $as_app.'includes/functions_arcade_submission.php';
require_once dirname(dirname(__DIR__)).'/update/arcade_protocol_log_redaction.php';
$schema=file_get_contents($as_app.'install/schemas/mysql_schema.sql');
as_check(preg_match('/CREATE TABLE phpbb_users \((.*?)\) ENGINE=InnoDB/s',$schema,$match)===1,'Canonical core users table');
preg_match_all('/^\s+([A-Za-z_][A-Za-z0-9_]*)\s+(?:tinyint|smallint|mediumint|int|bigint|varchar|char|text|mediumtext|longtext|decimal|float|double|timestamp|datetime|date|blob)\b/im',$match[1],$columns);
$protected=phpbb_arcade_submission_core_user_fields();
foreach(array_merge($columns[1],array('games_block_pm','arcade_banned'))as$field){as_check(in_array($field,$protected,true),'Wallet cannot update any canonical account field: '.$field);}
foreach(array('user_points','user_wallet','cash_gold')as$field){as_check(!in_array($field,$protected,true),'Typed external wallet column remains usable');}
$hash=str_repeat('a',32);$other=str_repeat('b',32);
$vectors=array(
    array(null,null),array('',''),array('game_name=>Grüße😀 score=>7 ','game_name=>Grüße😀 score=>7 '),
    array('game_name=>Fixture arcade_hash=>'.$hash.' sid=>'.$other.' score=>7 ','game_name=>Fixture arcade_hash=>[redacted] sid=>[redacted] score=>7 '),
    array('arcade_hash=>[redacted] sid=>[redacted]','arcade_hash=>[redacted] sid=>[redacted]'),
    array('SID=>'.$hash.' old_token=>'.$other,'SID=>[redacted] old_token=>[redacted]'),
    array('password=>hello world score=>7','[redacted legacy request containing credential fields]'),
    array('password=>'.$hash.' fakefield=>secret_tail','[redacted legacy request containing credential fields]'),
    array('cookie=>key=value; sid=secret game_name=>Fixture','[redacted legacy request containing credential fields]'),
    array('session_id=>'.$hash.' score=>7','[redacted legacy request containing credential fields]'),
    array('sid=>short-or-malformed score=>7','[redacted legacy request containing credential fields]'),
    array('csrf-token=>'.$hash.' score=>7','csrf-token=>[redacted] score=>7'),
    array('account[sid]=>'.$hash.' score=>7','account[sid]=>[redacted] score=>7'),
    array('password-extra=>secret score=>7','[redacted legacy request containing credential fields]'),
    array("SELECT * FROM ina_sessions WHERE arcade_hash = '$hash'","SELECT * FROM ina_sessions WHERE arcade_hash = '[redacted]'"),
    array('SQL diagnostic token='.str_repeat('a',64),'SQL diagnostic token=[redacted]'),
    array('password=>[redacted] fakefield=>unframed-secret','[redacted legacy request containing credential fields]'),
    array('field=>'.str_repeat(' ',30000).str_repeat('x',30000),'field=>'.str_repeat(' ',30000).str_repeat('x',30000)),
    array('sid=>'.$hash." \t score=>7  ",'sid=>[redacted]' . " \t score=>7  ")
);
foreach($vectors as$vector){$value=plus_arcade_log_redact($vector[0]);as_check($value===$vector[1],'Exact legacy credential redaction and unrelated UTF-8 preservation');as_check(plus_arcade_log_redact($value)===$value,'Credential cleanup is idempotent');}
class ArcadeCurrencyPolicyDatabase
{
    var $name=null;var $queries=0;var $freed=0;
    function sql_query($sql){as_check(strpos($sql,'SELECT cash_name FROM fixture_cash ')===0,'Currency label lookup stays read-only and scoped');$this->queries++;return $this->name!==null;}
    function sql_escape($value){return addslashes($value);}
    function sql_fetchrow($result){return array('cash_name'=>$this->name);}
    function sql_freeresult($result){$this->freed++;}
}
foreach(array('english','german')as$locale){
    as_language($locale);$board_config['points_name']='Punkte & <Gold>';$actor=array('user_id'=>2,'user_points'=>17,'user_wallet'=>23);$settings=array('use_rewards_mod'=>1,'use_point_system'=>1,'use_cash_system'=>1,'default_cash'=>'user_wallet');
    as_check(phpbb_arcade_wallet_display($settings,$actor)===sprintf($lang['games_total_points'],17,phpbb_profile_text($board_config['points_name'])),'Actual currency display uses the same points priority and escaped label as charging');
    $db=new ArcadeCurrencyPolicyDatabase();$settings['use_point_system']=0;
    as_check(phpbb_arcade_wallet_display($settings,$actor)===sprintf($lang['games_total_points'],23,$lang['game_points']),'Missing optional cash module falls back without warnings');
    $db->name='Gold & <Münzen>';
    as_check(phpbb_arcade_wallet_display($settings,$actor)===sprintf($lang['games_total_points'],23,phpbb_profile_text($db->name))&&$db->freed===1,'Actual cash name is escaped and its query result released');
    foreach(array('user_level','USER_PASSWORD','missing_wallet','invalid-wallet')as$field){$settings['default_cash']=$field;as_check(phpbb_arcade_wallet_display($settings,$actor)==='','Missing or core account fields are never displayed as a wallet');}
    $settings['default_cash']='user_wallet';$actor['user_id']=ANONYMOUS;as_check(phpbb_arcade_wallet_display($settings,$actor)==='','Guests never expose a wallet balance');
}
class ArcadeLogPolicyDatabase
{
    var $query='';
    function sql_error(){return array('message'=>'Fixture database error');}
    function sql_escape($text){return addslashes($text);}
    function sql_query($query){$this->query=$query;return true;}
}
$db=new ArcadeLogPolicyDatabase();$userdata=array('user_id'=>2);$arcade=new arcade();$arcade->arcade_config=array('games_use_log'=>1);
$arcade->log_error(1,__FILE__,"SELECT * FROM ina_sessions WHERE arcade_hash='$hash'");
as_check(strpos($db->query,$hash)===false&&strpos($db->query,'[redacted]')!==false&&strpos($db->query,"'ERROR'")!==false,'Actual diagnostic logger never stores a capability from failing SQL');
$updater=file_get_contents(dirname(dirname(__DIR__)).'/update/update_from_153a.php');
as_check(strpos($updater,"require_once __DIR__ . '/arcade_protocol_log_redaction.php';")!==false&&strpos($updater,'plus_arcade_log_redaction_apply($connection,$table_prefix,$backup_confirmed,')!==false&&strpos($updater,'plus_arcade_log_redaction_plan($connection,$table_prefix)')!==false,'Automatic full updater previews and applies the historical cleanup');
echo 'Arcade submission policy: '.count($protected).' protected core fields, '.count($vectors).' exact legacy redaction/idempotence vectors, '.$as_checks." assertions PASS; wallet scope and automatic updater preserved.\n";
