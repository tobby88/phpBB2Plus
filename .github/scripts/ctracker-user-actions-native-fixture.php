<?php
require $app . 'ctracker/classes/class_user_actions.php';
class CtUserRedirect extends RuntimeException {}
function redirect($url) { throw new CtUserRedirect($url); }
function ua_fragment($source, $start, $end) {
    $a=strpos($source,$start); $b=($a===false)?false:strpos($source,$end,$a);
    if($a===false||$b===false){throw new RuntimeException('Actual member controller unavailable');}
    return substr($source,$a,$b-$a);
}
function ua_snapshot() {
    $rows=cl_rows('SELECT user_id,user_active,user_level,user_blocktime,ct_enable_ip_warn,ct_last_ip,ct_last_used_ip,ct_global_msg_read FROM '.USERS_TABLE.' ORDER BY user_id');
    foreach($rows as&$row){foreach(array_keys($row)as$key){if(is_int($key)){unset($row[$key]);}}}unset($row);
    return array($rows,
        cl_rows('SELECT * FROM '.CTRACKER_CONFIG.' ORDER BY ct_config_name'),cl_rows('SELECT * FROM '.CONFIG_TABLE.' ORDER BY config_name'));
}
function ua_reset($action,$id=11) {
    global $userdata,$HTTP_POST_VARS,$HTTP_SERVER_VARS;
    cl_reset($id); cl_sql('DELETE FROM '.JR_ADMIN_TABLE); cl_sql('UPDATE '.SESSIONS_TABLE.' SET session_admin=0');
    cl_insert(CONFIG_TABLE,array('config_name'=>'board_disable','config_value'=>'0'));
    cl_sql('DELETE FROM '.CTRACKER_CONFIG);
    foreach(array('login_ip_check'=>'1','global_message'=>"Grüße 😀 O'Reilly \\ exact",'global_message_type'=>'1')as$key=>$value){cl_insert(CTRACKER_CONFIG,array('ct_config_name'=>$key,'ct_config_value'=>$value));}
    cl_sql("UPDATE ".USERS_TABLE." SET ct_enable_ip_warn=1,ct_last_ip='192.0.2.1',ct_last_used_ip='203.0.113.2',ct_global_msg_read=1");
    $userdata=array_merge($userdata,cl_rows('SELECT * FROM '.USERS_TABLE.' WHERE user_id='.$id)[0]);$userdata['session_admin']=false;
    if($action==='preference'){$_POST=array('submit'=>'1','sid'=>$userdata['session_id'],'ct_enable_ip_warn'=>'0');}
    else{
        $settings=array('global_message'=>"Grüße 😀 O'Reilly \\ exact",'global_message_type'=>'1');
        $context=ctracker_user_notice_context($action,$userdata,$settings);
        $_POST=array('marknow'=>$action,'ct_context'=>$context,'ct_token'=>ctracker_user_notice_token($action,$context,$userdata));
    }
    $HTTP_POST_VARS=$_POST; $HTTP_SERVER_VARS=array('REQUEST_METHOD'=>'POST');
}
function ua_controller($action) {
    global $db,$userdata,$lang,$HTTP_POST_VARS,$HTTP_SERVER_VARS,$phpbb_root_path,$phpEx,$marknow;
    $HTTP_POST_VARS=$_POST;$marknow=$action;$original_root=$phpbb_root_path;$phpbb_root_path=$GLOBALS['app'];
    try {
        eval($GLOBALS['ua_bodies'][$action]);
        cl_check($action==='preference','Notice action must redirect only after success');
    } catch(CtUserRedirect $redirect) { cl_check($action!=='preference','Only notices redirect'); return $redirect->getMessage(); }
    finally { $phpbb_root_path=$original_root; }
}
function ua_failure($action,$label,$callback=null) {
    $before=ua_snapshot();$memory=$GLOBALS['userdata'];$logs=cl_fingerprint();$denied=false;
    try { if($callback){$callback();}else{ua_controller($action);} }
    catch(CtLogControllerExit $error){$denied=true;}catch(CtrackerUserActionFailure $error){$denied=true;}catch(PhpbbOwnedStorageFailure $error){$denied=true;}
    cl_check($denied&&ua_snapshot()===$before&&$GLOBALS['userdata']===$memory&&cl_fingerprint()===$logs&&cl_closed(),$label.' preserves all user flags/policies/runtime/logs and releases the owner');
    $GLOBALS['ua_cases']++;
}
function ua_success($action) {
    $before=ua_snapshot();$logs=cl_fingerprint();$destination=ua_controller($action);$after=ua_snapshot();$id=(int)$GLOBALS['userdata']['user_id'];
    foreach($after[0]as$i=>$row){
        $expected=$before[0][$i];if((int)$row['user_id']===$id){if($action==='preference'){$expected['ct_enable_ip_warn']='0';}elseif($action==='ipfeature'){$expected['ct_last_ip']=$expected['ct_last_used_ip'];}else{$expected['ct_global_msg_read']='0';}}
        cl_check($row===$expected,'Actual action modifies only its one intended own field: '.json_encode(array('action'=>$action,'actor'=>$id,'actual'=>$row,'expected'=>$expected)));
    }
    cl_check($before[1]===$after[1]&&$before[2]===$after[2]&&cl_fingerprint()===$logs&&cl_closed(),'Acknowledged member action preserves policies/logs and closes its owner');$GLOBALS['ua_cases']++;
    return $destination;
}
$posting=file_get_contents($app.'ct_login_history.php');$header=file_get_contents($app.'includes/page_header.php');
$ua_bodies=array('preference'=>ua_fragment($posting,'// Save before rendering','// Include the page_header'),
    'ipfeature'=>ua_fragment($header,"if ( \$marknow == 'ipfeature' &&","if ( \$ctracker_settings['login_ip_check'] == 1 &&"),
    'globmsg'=>ua_fragment($header,"if ( \$marknow == 'globmsg' &&","if ( \$userdata['ct_global_msg_read'] == 1 &&"));
cl_check(preg_match('/^CREATE TABLE `phpbb_ctracker_config`\s*\([\s\S]*?;/m',$canonical,$m)===1,'Canonical CT config for ordinary member actions');
cl_sql(str_replace('phpbb_ctracker_config',CTRACKER_CONFIG,$m[0]));$ua_cases=0;
$actions=array('preference','ipfeature','globmsg');
foreach($actions as$action){
    foreach(array(11,12,7)as$id){ua_reset($action,$id);ua_success($action);}
    foreach(array('logout'=>'DELETE FROM fixture_sessions','inactive'=>'UPDATE fixture_users SET user_active=0 WHERE user_id=11',
        'removed'=>'DELETE FROM fixture_users WHERE user_id=11','foreign'=>'UPDATE fixture_sessions SET session_user_id=12',
        'logged-out'=>'UPDATE fixture_sessions SET session_logged_in=0','expired'=>'UPDATE fixture_sessions SET session_time=1',
        'case-mismatch'=>"UPDATE fixture_sessions SET session_id='LOG-OWN-SESSION'",
        'blocked'=>'UPDATE fixture_users SET user_blocktime=2147483647 WHERE user_id=11','invalid-level'=>'UPDATE fixture_users SET user_level=3 WHERE user_id=11',
        'missing-TTL'=>"DELETE FROM fixture_config WHERE config_name='session_length'",'invalid-TTL'=>"UPDATE fixture_config SET config_value='0' WHERE config_name='session_length'",
        'board-disabled'=>"UPDATE fixture_config SET config_value='1' WHERE config_name='board_disable'")as$label=>$sql){ua_reset($action);cl_sql($sql);ua_failure($action,'Current '.$label);}
    foreach(array('method','token','array-token','logged-out-cache','foreign-cache')as$bad){
        ua_reset($action);if($bad==='method'){$_SERVER['REQUEST_METHOD']='GET';}elseif($bad==='logged-out-cache'){$userdata['session_logged_in']=false;}
        elseif($bad==='foreign-cache'){$userdata['user_id']=12;}else{$key=$action==='preference'?'sid':'ct_token';$_POST[$key]=$bad==='array-token'?array('invalid'):'wrong';}
        $connections=count($db->connections);
        ua_failure($action,'Invalid '.$bad,$bad==='logged-out-cache'?function()use($action){ctracker_user_apply($GLOBALS['db'],$action);}:null);
        if($bad!=='foreign-cache'||$action!=='preference'){cl_check(count($db->connections)===$connections,'Cheap request rejection opens no database owner');}
    }
    ua_reset($action);$db->kind='same';ua_failure($action,'Foreign caller handle');cl_check(!$db->closed&&$db->sql_query('SELECT 1')!==false,'Caller stays usable after bad factory');
    foreach(array('SELECT session_user_id','SELECT user_id,user_active','SELECT config_name,config_value','SELECT ct_config_name,ct_config_value','UPDATE '.USERS_TABLE.' SET','SELECT ct_enable_ip_warn,ct_last_ip')as$failure){ua_reset($action);$db->fail=$failure;ua_failure($action,'Real query failure '.$failure);}
    ua_reset($action);$observations=array();$before_memory=$userdata;
    $db->query_hook=function($sql,$owner)use(&$observations,$action,$before_memory){
        if(strpos($sql,'UPDATE '.USERS_TABLE.' SET')===0){
            $observations[]=($GLOBALS['userdata']===$before_memory);
            $feature=$action==='globmsg'?"UPDATE ".CTRACKER_CONFIG." SET ct_config_value='new' WHERE ct_config_name='global_message'":"UPDATE ".CTRACKER_CONFIG." SET ct_config_value='0' WHERE ct_config_name='login_ip_check'";
            foreach(array('DELETE FROM '.SESSIONS_TABLE,'UPDATE '.USERS_TABLE.' SET user_active=0 WHERE user_id=11',"UPDATE ".CONFIG_TABLE." SET config_value='1' WHERE config_name='session_length'",$feature)as$change){$r=native_fixture_conflict_query($GLOBALS['peer'],$change);$observations[]=($r[0]===false&&(int)$r[1]['code']===1205);}
        }return true;
    };
    ua_success($action);cl_check($observations===array(true,true,true,true,true),'User/session/TTL/relevant feature rows remain held through commit; memory is unchanged before acknowledgement');
    ua_reset($action);$db->query_hook=function($sql,$owner)use($action){if(strpos($sql,'UPDATE '.USERS_TABLE.' SET')===0){$_POST[$action==='preference'?'sid':'ct_token']='changed-after-write';}return true;};ua_failure($action,'Changed request identity before commit');
    ua_reset($action);cl_check($db->sql_query('START TRANSACTION')!==false&&$db->sql_query("INSERT INTO ".CONFIG_TABLE." VALUES('caller_pending','preserved')")!==false,'Caller pending transaction');ua_success($action);
    $r=$db->sql_query("SELECT config_value FROM ".CONFIG_TABLE." WHERE config_name='caller_pending'");cl_check($db->sql_fetchrow($r)!==false&&!cl_rows("SELECT * FROM ".CONFIG_TABLE." WHERE config_name='caller_pending'"),'Member action does not commit/roll back caller work');$db->sql_freeresult($r);cl_check($db->sql_query('ROLLBACK')!==false,'Caller can still roll back');
    ua_reset($action);$db->lose_commit=true;$memory=$userdata;$denied=false;
    try{ua_controller($action);}catch(CtLogControllerExit$error){$denied=true;}
    $expected=$action==='preference'?'ct_enable_ip_warn':($action==='ipfeature'?'ct_last_ip':'ct_global_msg_read');$row=cl_rows('SELECT '.$expected.' FROM '.USERS_TABLE.' WHERE user_id=11')[0];
    cl_check($denied&&$userdata===$memory&&cl_closed()&&(string)$row[$expected]===($action==='ipfeature'?'203.0.113.2':'0'),'Lost COMMIT acknowledgement never publishes success, retries or changes runtime');$ua_cases++;
}
foreach(array('preference','ipfeature')as$action){ua_reset($action);cl_sql("UPDATE ".CTRACKER_CONFIG." SET ct_config_value='0' WHERE ct_config_name='login_ip_check'");ua_failure($action,'Feature disabled after bootstrap');}
foreach(array('globmsg','ipfeature')as$action){
    ua_reset($action);$old_post=$_POST;
    cl_sql($action==='globmsg'?"UPDATE ".CTRACKER_CONFIG." SET ct_config_value='New unseen notice' WHERE ct_config_name='global_message'":"UPDATE ".USERS_TABLE." SET ct_last_ip='203.0.113.2',ct_last_used_ip='198.51.100.3' WHERE user_id=11");
    ua_failure($action,'Old signed form cannot acknowledge a newer notice');
    ua_reset($action);$old_post=$_POST;ua_success($action);$_POST=$old_post;$before=ua_snapshot();ua_controller($action);cl_check(ua_snapshot()===$before&&cl_closed(),'Repeated old valid form is a harmless already-clear no-op');$ua_cases++;
    ua_reset($action);$_POST['ct_context']=str_repeat('0',64);ua_failure($action,'Changed context without its matching HMAC');
}
ua_reset('globmsg');cl_sql("UPDATE ".CTRACKER_CONFIG." SET ct_config_value='0' WHERE ct_config_name='global_message_type'");ua_failure('globmsg','Old form cannot acknowledge a changed notice type');
ua_reset('globmsg');cl_sql("UPDATE ".CTRACKER_CONFIG." SET ct_config_value='' WHERE ct_config_name='global_message'");ua_failure('globmsg','Retracted notice is not acknowledged from an old form');
foreach(array(array('invalid'),null,'short',str_repeat('G',64))as$context){ua_reset('globmsg');$_POST['ct_context']=$context;ua_failure('globmsg','Malformed notice context');}
ua_reset('ipfeature');cl_sql('UPDATE '.USERS_TABLE.' SET ct_enable_ip_warn=0 WHERE user_id=11');ua_failure('ipfeature','Warning disabled on current account');
foreach(array(null,array('1'),'1tail','2',true,1.0)as$value){ua_reset('preference');$_POST['ct_enable_ip_warn']=$value;ua_failure('preference','Malformed preference');}
ua_reset('preference');unset($_POST['ct_enable_ip_warn']);ua_success('preference');
ua_reset('preference');cl_sql('UPDATE '.USERS_TABLE.' SET ct_enable_ip_warn=NULL WHERE user_id=11');ua_success('preference');
ua_reset('preference');$_POST['ct_enable_ip_warn']='1';$before=ua_snapshot();ua_controller('preference');cl_check(ua_snapshot()===$before&&$userdata['ct_enable_ip_warn']===1&&cl_closed(),'An already-enabled preference succeeds even when no value changes');$ua_cases++;
ua_reset('preference');cl_sql("UPDATE ".USERS_TABLE." SET ct_last_ip='old non-IP text' WHERE user_id=11");ua_success('preference');
ua_reset('globmsg');cl_sql('UPDATE '.USERS_TABLE.' SET ct_global_msg_read=NULL WHERE user_id=11');$before=ua_snapshot();ua_controller('globmsg');cl_check(ua_snapshot()===$before&&$userdata['ct_global_msg_read']===0,'Nullable historical unread flag remains an unchanged no-op');$ua_cases++;
ua_reset('preference',7);cl_sql("UPDATE ".CONFIG_TABLE." SET config_value='1' WHERE config_name='board_disable'");ua_success('preference');
// Revocation after cached bootstrap but immediately before the locked read.
foreach(array('DELETE FROM '.SESSIONS_TABLE,'UPDATE '.USERS_TABLE.' SET user_active=0 WHERE user_id=11')as$change){
    ua_reset('preference');$done=false;$memory=$userdata;$denied=false;$db->query_hook=function($sql,$owner)use($change,&$done){if(!$done&&strpos($sql,'SELECT session_user_id')===0){$done=true;cl_sql($change);}return true;};
    try{ua_controller('preference');}catch(CtLogControllerExit$error){$denied=true;}
    cl_check($done&&$denied&&$userdata===$memory&&cl_rows('SELECT ct_enable_ip_warn FROM '.USERS_TABLE.' WHERE user_id=11')[0]['ct_enable_ip_warn']==='1'&&cl_closed(),'Revocation wins before owned read and leaves preferences unchanged');$ua_cases++;
}
// Expiry can elapse while storage waits, even though all authority rows are held.
ua_reset('preference');cl_sql("UPDATE ".CONFIG_TABLE." SET config_value='1' WHERE config_name='session_length'");$waited=false;$db->query_hook=function($sql,$owner)use(&$waited){if(strpos($sql,'UPDATE '.USERS_TABLE.' SET')===0){$waited=true;sleep(3);}return true;};ua_failure('preference','Session expiry before commit');cl_check($waited,'Expiry occurred after the authorized request reached its pending update');
foreach($actions as$action){
    ua_reset($action);$rewrite=$action==='preference'?'NEW.ct_enable_ip_warn=1':($action==='ipfeature'?"NEW.ct_last_ip='192.0.2.1'":'NEW.ct_global_msg_read=1');
    cl_sql('CREATE TRIGGER fixture_member_rewrite BEFORE UPDATE ON '.USERS_TABLE.' FOR EACH ROW SET '.$rewrite);
    try{ua_failure($action,'Native SQL postcondition mismatch');}finally{cl_sql('DROP TRIGGER fixture_member_rewrite');}
}
foreach(array(USERS_TABLE,SESSIONS_TABLE,CONFIG_TABLE,CTRACKER_CONFIG)as$table){ua_reset('preference');cl_sql('ALTER TABLE '.$table.' CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');try{ua_failure('preference','Noncanonical '.$table);}finally{cl_sql('ALTER TABLE '.$table.' CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');}}
ua_reset('preference');$owner=new CtrackerUserActions($db,ctracker_user_request('preference'));
try{$denied=false;try{$owner->rows('UPDATE '.USERS_TABLE.' SET ct_enable_ip_warn=0');}catch(PhpbbOwnedStorageFailure$error){$denied=true;}cl_check($denied,'Public row adapter cannot expose arbitrary writes');}finally{$owner->release();}
foreach(array('unknown',array('preference'),null)as$kind){ua_reset('preference');ua_failure('preference','Unknown direct operation',function()use($kind){ctracker_user_apply($GLOBALS['db'],$kind);});}
$board_config+=array('server_name'=>'www.example.invalid','server_port'=>443,'script_path'=>'/forum');
foreach(array('ipfeature','globmsg')as$action){
 foreach(array(array('https://example.invalid/forum/viewtopic.php?t=13&start=15#p42','viewtopic.php?t=13&start=15#p42'),
  array('https://www.example.invalid/forum/profile.php?mode=viewprofile&u=7','profile.php?mode=viewprofile&u=7'),
  array('https://www.example.invalid/forum/ftopic13.html','ftopic13.html'),
  array('https://evil.invalid/forum/viewtopic.php?t=13','portal.php'),array(array('invalid'),'portal.php'),
  array('https://www.example.invalid/other/viewtopic.php?t=13','portal.php'))as$case){
   ua_reset($action);$HTTP_SERVER_VARS['HTTP_REFERER']=$case[0];$destination=ua_success($action);
   cl_check($destination===$case[1],'Actual committed member action preserves a safe local return or uses the fixed fallback');
 }
}
echo 'Native CrackerTracker member actions: ',$ua_cases,' actual-controller cases, ',$checks," assertions PASS; current identity, notice context, locks, rollback, lost acknowledgement and caller isolation.\n";
