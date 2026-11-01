<?php
// Real renderers and owned driver, disposable canonical MariaDB/MySQL tables.
if (getenv('PHPBB_CONFIRMATION_NATIVE') !== '1') {
    echo "Native CAPTCHA checks require an explicitly enabled disposable database.\n"; return;
}
define('IN_PHPBB', true); define('CRACKER_TRACKER_VCONFIRM', true);
define('GENERAL_MESSAGE', 0); define('GENERAL_ERROR', 1);
foreach (array('CONFIRM_TABLE'=>'phpbb_confirm', 'SESSIONS_TABLE'=>'phpbb_sessions',
    'CONFIG_TABLE'=>'phpbb_config', 'ATTACHMENTS_TABLE'=>'phpbb_attachments') as $key=>$value) { define($key, $value); }
$root = dirname(dirname(__DIR__)).'/phpBB2/'; $phpbb_root_path=$root; $phpEx='php';
require $root.'includes/php_compat.php'; require $root.'db/mysqli.php';
require $root.'includes/functions_confirmation.php'; require __DIR__.'/native-fixture-locks.php';
$cf_completed=false;
register_shutdown_function(function()use(&$cf_completed){if(!$cf_completed){fwrite(STDERR,"Native CAPTCHA fixture did not finish.\n");exit(3);}});
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
class ConfirmationUiFailure extends RuntimeException {}
function message_die($type,$message){throw new ConfirmationUiFailure($message);}
function append_sid($url){return $url;}
class ConfirmationTemplate { function assign_block_vars($name,$values){} }
function cf_check($ok,$label){if(!$ok){throw new RuntimeException($label);}$GLOBALS['checks']++;}
$cf_fault=''; $cf_hook=null; $cf_queries=array();
class ConfirmationConnection {
    public $db_connect_id; private $inner;
    function __construct($inner){$this->inner=$inner;$this->db_connect_id=$inner->db_connect_id;}
    function sql_query($sql='',$transaction=false){
        global $cf_fault,$cf_hook,$cf_queries;
        $cf_queries[]=$sql;
        if(is_callable($cf_hook)){call_user_func($cf_hook,$sql,$this);}
        if(($cf_fault==='insert'&&strpos($sql,'INSERT INTO phpbb_confirm ')===0)||
            ($cf_fault==='readback'&&strpos($sql,'SELECT code FROM phpbb_confirm ')===0)||
            ($cf_fault==='commit'&&$sql==='COMMIT'&&count(array_filter($cf_queries,function($q){return strpos($q,'INSERT INTO phpbb_confirm ')===0;})))){return false;}
        $result=$this->inner->sql_query($sql,$transaction);
        if($cf_fault==='lost-ack'&&$sql==='COMMIT'&&count(array_filter($cf_queries,function($q){return strpos($q,'INSERT INTO phpbb_confirm ')===0;}))){return false;}
        return $result;
    }
    function __call($name,$args){return call_user_func_array(array($this->inner,$name),$args);}
}
class ConfirmationSource extends sql_db {
    function sql_dedicated_connection(){return new ConfirmationConnection(parent::sql_dedicated_connection());}
    function sql_query($sql='',$transaction=false){
        if(isset($GLOBALS['cf_post_hook'])&&is_callable($GLOBALS['cf_post_hook'])){call_user_func($GLOBALS['cf_post_hook'],$sql);}
        return parent::sql_query($sql,$transaction);
    }
}
function cf_rows($sql){$result=$GLOBALS['peer']->sql_query($sql);if(!$result){throw new RuntimeException('Own native CAPTCHA query failed');}try{return $GLOBALS['peer']->sql_fetchrowset($result);}finally{$GLOBALS['peer']->sql_freeresult($result);}}
function cf_sql($sql){if(!$GLOBALS['peer']->sql_query($sql)){throw new RuntimeException('Own native CAPTCHA statement failed');}}
function cf_reset(){
    global $userdata,$cf_fault,$cf_hook,$cf_queries;
    $cf_fault='';$cf_hook=null;$cf_queries=array();
    cf_sql('DELETE FROM phpbb_confirm');cf_sql('DELETE FROM phpbb_sessions');cf_sql('DELETE FROM phpbb_config');
    cf_sql("INSERT INTO phpbb_config VALUES('session_length','3600')");
    foreach(array(str_repeat('a',32),str_repeat('b',32))as$sid){cf_sql("INSERT INTO phpbb_sessions(session_id,session_user_id,session_time) VALUES('$sid',-1,".time().')');}
    $userdata=array('user_id'=>-1,'session_id'=>str_repeat('a',32),'session_logged_in'=>false);
}
function cf_render($kind){
    global $db,$userdata,$lang,$root,$phpbb_root_path,$phpEx,$registration,$posting_render;
    $mode=$kind==='register'?'register':'display';$error=false;$HTTP_POST_VARS=array();$s_hidden_fields=$hidden_form_fields='';
    $board_config=array('enable_confirm'=>1,'session_length'=>3600);$plus_config=array('enable_antirobot'=>0,'enable_confirm_post'=>1);
    $ctracker_config=new stdClass();$ctracker_config->settings=array('vconfirm_guest'=>$kind==='ct-post'?1:0);$template=new ConfirmationTemplate();
    if($kind==='register'){eval($registration);}elseif($kind==='plus-post'||$kind==='ct-post'){eval($posting_render);}
    else{include $root.'ctracker/engines/ct_visual_confirm.php';}
    $hidden=$kind==='plus-post'?$hidden_form_fields:$s_hidden_fields;
    cf_check(preg_match('/name="confirm_id" value="([a-f0-9]{32})"/i',$hidden,$match)===1,'Actual renderer publishes a challenge ID');
    cf_check(strpos($confirm_image,'id='.$match[1])!==false,'Actual image and hidden field share the committed ID');
    return $match[1];
}
function cf_denied($callable,$label){$denied=false;try{call_user_func($callable);}catch(PhpbbOwnedStorageFailure$error){$denied=true;}cf_check($denied,$label);}
function cf_fragment($source,$start,$end){$source=str_replace("\r\n","\n",$source);$begin=strpos($source,$start);$stop=$begin===false?false:strpos($source,$end,$begin);if($begin===false||$stop===false){throw new RuntimeException('Actual renderer fragment missing');}return substr($source,$begin,$stop-$begin);}
$registration=cf_fragment(file_get_contents($root.'includes/usercp_register.php'),"\t// Visual Confirmation\n","\t//\n\t// Let's do an overall check");
$posting_render=cf_fragment(file_get_contents($root.'posting.php'),'// Visual confirmation for guests','// Generate smilies listing');
$posting_check=cf_fragment(file_get_contents($root.'posting.php'),"\t\t\tif ( \$ctracker_config->settings['vconfirm_guest'] != 1", "\t\t\t\$username = \$request_scalar");
$image_query=cf_fragment(file_get_contents($root.'includes/usercp_confirm.php'),'// Try and grab code for this id and session','// If we have a row');
function cf_image_code($sid,$id){
    global$db,$image_query;$userdata=array('session_id'=>$sid);$confirm_id=$id;eval($image_query);
    try{$row=$db->sql_fetchrow($result);return $row?(string)$row['code']:false;}finally{$db->sql_freeresult($result);}
}
function cf_post_check($id,$code){
    global$db,$userdata,$lang,$posting_check;
    $ctracker_config=new stdClass();$ctracker_config->settings=array('vconfirm_guest'=>0);$plus_config=array('enable_confirm_post'=>1);
    $_POST=array('confirm_id'=>$id,'confirm_code'=>$code);$error=false;$error_msg='';
    $request_scalar=function($input,$name,$default=''){return isset($input[$name])&&is_scalar($input[$name])?(string)$input[$name]:$default;};
    eval($posting_check);return !$error;
}
$host=getenv('PHPBB_CONFIRMATION_HOST')?:'127.0.0.1';$port=(int)(getenv('PHPBB_CONFIRMATION_PORT')?:3306);
$user=getenv('PHPBB_CONFIRMATION_USER')?:'root';$password=getenv('PHPBB_CONFIRMATION_PASSWORD');if($password===false){$password='fixture-only';}
if(isset($argv[1])&&$argv[1]==='--worker'){
    $worker=isset($argv[2])?$argv[2]:'';if(!preg_match('/\Acodex_confirmation_[a-f0-9]{16}\z/',$worker)){throw new RuntimeException('Own worker schema required');}
    $source=new sql_db($host.':'.$port,$user,$password,$worker,false);
    try{echo phpbb_confirmation_challenge($source,array('user_id'=>-1,'session_id'=>str_repeat('a',32),'session_logged_in'=>false)),"\n";$cf_completed=true;}
    finally{$source->sql_close();}exit;
}
$schema='codex_confirmation_'.bin2hex(phpbb_random_bytes(8));
if(!preg_match('/\Acodex_confirmation_[a-f0-9]{16}\z/',$schema)){throw new RuntimeException('Own schema identity required');}
$admin=new sql_db($host.':'.$port,$user,$password,'',false);$db=$peer=null;$created=false;$checks=0;
try {
    if(!$admin->sql_query('CREATE DATABASE `'.$schema.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')){throw new RuntimeException('Own CAPTCHA schema create failed');}$created=true;
    $db=new ConfirmationSource($host.':'.$port,$user,$password,$schema,false);$peer=new sql_db($host.':'.$port,$user,$password,$schema,false);
    $ddl=file_get_contents($root.'install/schemas/mysql_schema.sql');
    foreach(array('confirm','sessions','config')as$name){if(!preg_match('/CREATE TABLE phpbb_'.$name.' \(.*?\) ENGINE=InnoDB[^;]*;/s',$ddl,$match)){throw new RuntimeException('Canonical installer participant missing');}cf_sql($match[0]);}
    foreach(array('english','german')as$locale){
        $lang=array();require $root.'language/lang_'.$locale.'/lang_cback_ctracker.php';
        $lang+=array('Session_invalid'=>'invalid session','Owned_storage_failed'=>'failed storage','Owned_storage_upgrade'=>'upgrade storage');
        foreach(array('guest-post','register','plus-post','ct-post')as$kind){
            cf_reset();$ids=array();for($i=0;$i<12;$i++){$ids[]=cf_render($kind);}
            cf_check(count(array_unique(array_slice($ids,0,4)))===4,'First four tabs keep distinct challenges');
            $rows=cf_rows('SELECT confirm_id,code FROM phpbb_confirm ORDER BY confirm_id');
            cf_check(count($rows)===4,'Repeated actual page renders stay bounded');
            foreach($ids as$id){cf_check(count(cf_rows("SELECT code FROM phpbb_confirm WHERE session_id='".$userdata['session_id']."' AND confirm_id='$id'"))===1,'Every displayed old/new tab retains its code');}
            foreach($rows as$row){cf_check(cf_image_code($userdata['session_id'],$row['confirm_id'])===$row['code'],'Actual image endpoint query reads the committed code');cf_check(cf_image_code(str_repeat('b',32),$row['confirm_id'])===false,'Actual image endpoint query cannot read another session code');}
            $other=$userdata;$other['session_id']=str_repeat('b',32);$otherid=phpbb_confirmation_challenge($db,$other);
            cf_check(!in_array($otherid,$ids,true)&&count(cf_rows('SELECT * FROM phpbb_confirm'))===5,'Other session receives only its own challenge');
            cf_check(count(cf_rows("SELECT * FROM phpbb_confirm WHERE session_id='".$userdata['session_id']."'"))===4,'Other session cannot evict active tabs');
        }
    }
    cf_reset();$first=phpbb_confirmation_challenge($db,$userdata);$row=cf_rows("SELECT * FROM phpbb_confirm WHERE confirm_id='$first'")[0];
    $HTTP_POST_VARS=array('confirm_id'=>$first,'confirm_code'=>$row['code']);$error=false;$mode='check';include $root.'ctracker/engines/ct_visual_confirm.php';
    cf_check(!cf_rows("SELECT * FROM phpbb_confirm WHERE confirm_id='$first'"),'Actual CT consumer removes one solved challenge');
    $rejected=false;try{$error=false;include $root.'ctracker/engines/ct_visual_confirm.php';}catch(ConfirmationUiFailure$error){$rejected=true;}
    cf_check($rejected,'Consumed challenge cannot be replayed');
    define('POST_CONFIRM_CHECK',true);$new=cf_render('ct-post');
    cf_check(count(cf_rows("SELECT * FROM phpbb_confirm WHERE confirm_id='$new'"))===1,'Actual CT posting form renders a fresh challenge after verification already ran');
    $lang['Confirm_code_wrong']='wrong confirmation';
    cf_reset();$id=str_repeat('e',32);cf_sql("INSERT INTO phpbb_confirm VALUES('$id','".$userdata['session_id']."','222E22')");
    cf_check(!cf_post_check($id,'2220000000000000000000000')&&count(cf_rows('SELECT * FROM phpbb_confirm'))===1,'Actual Plus consumer compares code bytes, not numeric-string equivalence');
    cf_check(cf_post_check($id,'222E22')&&!cf_post_check($id,'222E22'),'Actual Plus consumer accepts a solved challenge exactly once');
    cf_sql("INSERT INTO phpbb_confirm VALUES('$id','".$userdata['session_id']."','ABC234')");
    $GLOBALS['cf_post_hook']=function($sql)use($id){if(strpos($sql,'DELETE FROM phpbb_confirm')===0){cf_sql("DELETE FROM phpbb_confirm WHERE confirm_id='$id'");}};
    cf_check(!cf_post_check($id,'ABC234'),'Actual Plus consumer denies a concurrent winner instead of accepting a zero-row delete');$GLOBALS['cf_post_hook']=null;
    foreach(array('insert','readback','commit')as$fault){
        cf_reset();cf_sql("INSERT INTO phpbb_confirm VALUES('".str_repeat('e',32)."','".str_repeat('d',32)."','ABC234')");$before=cf_rows('SELECT * FROM phpbb_confirm ORDER BY session_id,confirm_id');
        $cf_fault=$fault;cf_denied(function()use($db,$userdata){phpbb_confirmation_challenge($db,$userdata);},'Injected '.$fault.' denies publication');$cf_fault='';
        cf_check(cf_rows('SELECT * FROM phpbb_confirm ORDER BY session_id,confirm_id')===$before,'Cleanup and insertion roll back together');
    }
    cf_reset();$cf_fault='lost-ack';cf_denied(function()use($db,$userdata){phpbb_confirmation_challenge($db,$userdata);},'Lost commit acknowledgement publishes no ID');$cf_fault='';
    cf_check(count(cf_rows('SELECT * FROM phpbb_confirm'))===1,'No blind retry after actually committed lost acknowledgement');
    foreach(array(array(),array('user_id'=>2),array('session_logged_in'=>true),array('session_id'=>str_repeat('c',32)),array('session_id'=>array('bad')))as$change){
        cf_reset();$actor=array_merge($userdata,$change);if(!$change){unset($actor['session_id']);}
        cf_denied(function()use($db,$actor){phpbb_confirmation_challenge($db,$actor);},'Malformed/stale/non-guest session denied');cf_check(!cf_rows('SELECT * FROM phpbb_confirm'),'Denied actor creates no code');
    }
    foreach(array('expired','signed-in','missing-policy')as$case){
        cf_reset();if($case==='expired'){cf_sql('UPDATE phpbb_sessions SET session_time=1');}elseif($case==='signed-in'){cf_sql('UPDATE phpbb_sessions SET session_user_id=2,session_logged_in=1');}else{cf_sql('DELETE FROM phpbb_config');}
        cf_denied(function()use($db,$userdata){phpbb_confirmation_challenge($db,$userdata);},'Current '.$case.' state denied');
    }
    cf_reset();$db->sql_query('START TRANSACTION');$db->sql_query("INSERT INTO phpbb_config VALUES('caller_pending','preserved')");
    $id=phpbb_confirmation_challenge($db,$userdata);cf_check(!cf_rows("SELECT * FROM phpbb_config WHERE config_name='caller_pending'"),'Owned renderer never commits caller work');
    $result=$db->sql_query("SELECT config_value FROM phpbb_config WHERE config_name='caller_pending'");cf_check($db->sql_fetchrow($result)['config_value']==='preserved','Caller connection and pending work survive');$db->sql_freeresult($result);$db->sql_query('ROLLBACK');
    cf_reset();for($i=0;$i<7;$i++){cf_sql("INSERT INTO phpbb_confirm VALUES('".str_pad(dechex($i+1),32,'0',STR_PAD_LEFT)."','".$userdata['session_id']."','ABC234')");}
    $before=cf_rows('SELECT * FROM phpbb_confirm ORDER BY confirm_id');phpbb_confirmation_challenge($db,$userdata);
    cf_check(cf_rows('SELECT * FROM phpbb_confirm ORDER BY confirm_id')===$before,'Preserve pre-update oversized active tab sets without adding codes');
    cf_reset();native_fixture_peer_nowait($peer);$conflicts=array();
    $cf_hook=function($sql)use(&$conflicts,$peer){
        if($sql==='COMMIT'&&count(array_filter($GLOBALS['cf_queries'],function($q){return strpos($q,'INSERT INTO phpbb_confirm ')===0;}))){
            foreach(array('UPDATE phpbb_sessions SET session_user_id=2','UPDATE phpbb_config SET config_value=1', 'DELETE FROM phpbb_confirm')as$change){$result=$peer->sql_query($change);$error=$peer->sql_error();$conflicts[]=($result===false&&(int)$error['code']===1205);}
        }
    };
    phpbb_confirmation_challenge($db,$userdata);$cf_hook=null;cf_check($conflicts===array(true,true,true),'Current session, TTL and challenge rows remain locked through commit');
    cf_reset();foreach(range(1,3)as$unused){phpbb_confirmation_challenge($db,$userdata);}$workers=array();
    try{
        for($i=0;$i<4;$i++){
            $command=escapeshellarg(PHP_BINARY);
            if(PHP_OS==='WINNT'){$command.=' -n -d '.escapeshellarg('extension_dir='.dirname(PHP_BINARY).'/ext').' -d extension=php_mysqli.dll -d extension=php_openssl.dll';}
            $command.=' '.escapeshellarg(__FILE__).' --worker '.escapeshellarg($schema);$pipes=array();
            $process=proc_open($command,array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes,null,null,array('bypass_shell'=>true));
            cf_check(is_resource($process),'Concurrent real renderer worker starts');fclose($pipes[0]);$workers[]=array($process,$pipes[1],$pipes[2]);
        }
        foreach($workers as$index=>$worker){$stdout=stream_get_contents($worker[1]);$stderr=stream_get_contents($worker[2]);fclose($worker[1]);fclose($worker[2]);$exit=proc_close($worker[0]);unset($workers[$index]);
            cf_check($exit===0&&$stderr===''&&preg_match('/\A[a-f0-9]{32}\s*\z/',$stdout)===1,'Concurrent worker publishes one acknowledged ID');
            $id=trim($stdout);cf_check(count(cf_rows("SELECT * FROM phpbb_confirm WHERE confirm_id='$id'"))===1,'Concurrent worker returns an existing code');
        }
    }finally{foreach($workers as$worker){proc_terminate($worker[0]);fclose($worker[1]);fclose($worker[2]);proc_close($worker[0]);}}
    cf_check(count(cf_rows('SELECT * FROM phpbb_confirm'))===4,'Real simultaneous connections cannot overfill the four-code display bound');
    $original=file_get_contents($root.'includes/functions_confirmation.php');$body=substr($original,strpos($original,'class PhpbbConfirmationStore'));
    eval('namespace ConfirmationEntropyFixture; use \\PhpbbOwnedStore; use \\PhpbbOwnedStorageFailure; use \\Exception; use \\Error; '.
        'function phpbb_random_string($length,$alphabet){throw new \\RuntimeException("fixture entropy failure");} '.
        'function phpbb_random_bytes($length){return "";} '.$body);
    cf_reset();cf_denied(function()use($db,$userdata){\ConfirmationEntropyFixture\phpbb_confirmation_challenge($db,$userdata);},'Actual new-slot entropy exception fails closed');
    cf_check(!cf_rows('SELECT * FROM phpbb_confirm'),'Entropy failure leaves no partial challenge');
    for($i=0;$i<4;$i++){phpbb_confirmation_challenge($db,$userdata);}
    $id=\ConfirmationEntropyFixture\phpbb_confirmation_challenge($db,$userdata);
    cf_check(count(cf_rows("SELECT * FROM phpbb_confirm WHERE confirm_id='$id'"))===1,'At the cap, existing codes remain usable without demanding new entropy');
    foreach(array('engine','key')as$case){
        cf_reset();if($case==='engine'){cf_sql('ALTER TABLE phpbb_confirm ENGINE=MyISAM');}else{cf_sql('ALTER TABLE phpbb_confirm DROP PRIMARY KEY,ADD PRIMARY KEY(confirm_id)');}
        cf_denied(function()use($db,$userdata){phpbb_confirmation_challenge($db,$userdata);},'Noncanonical '.$case.' participant denied before mutation');
        if($case==='engine'){cf_sql('ALTER TABLE phpbb_confirm ENGINE=InnoDB ROW_FORMAT=DYNAMIC');}else{cf_sql('ALTER TABLE phpbb_confirm DROP PRIMARY KEY,ADD PRIMARY KEY(session_id,confirm_id)');}
    }
    echo 'Native CAPTCHA: '.$checks." actual EN/DE renderer/reload/tab/image/isolation/consume/rollback/ack/session/caller cases PASS.\n";
    $cf_completed=true;
} finally {
    if($db){$db->sql_close();}if($peer){$peer->sql_close();}
    if($created&&!$admin->sql_query('DROP DATABASE `'.$schema.'`')){throw new RuntimeException('Own CAPTCHA fixture cleanup unconfirmed');}$admin->sql_close();
}
