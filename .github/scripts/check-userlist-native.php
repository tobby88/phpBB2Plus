<?php
// Actual controller branch and complete native bulk writer, disposable schema.
if (PHP_SAPI !== 'cli' || getenv('PHPBB_USERLIST_NATIVE') !== '1') { echo "Native userlist checks require an explicitly enabled disposable database.\n"; return; }
putenv('PHPBB_LOGIN_PUBLICATION_NATIVE=1');
putenv('PHPBB_LOGIN_PUBLICATION_PORT=' . (getenv('PHPBB_USERLIST_PORT') ?: '3306'));
putenv('PHPBB_LOGIN_PUBLICATION_PASSWORD=' . (getenv('PHPBB_USERLIST_PASSWORD') ?: ''));
$source=file_get_contents(__DIR__.'/check-login-publication-native.php'); $cut=strpos($source,"\ntry{\n set_error_handler");
if ($cut===false) { throw new RuntimeException('Shared fixture boundary changed'); }
eval(substr(str_replace('__DIR__',var_export(__DIR__,true),substr($source,0,$cut)),5));
foreach (array('MOD'=>2,'USER'=>0,'POST_USERS_URL'=>'u','GROUPS_TABLE'=>'fixture_groups','USER_GROUP_TABLE'=>'fixture_user_group','AUTH_ACCESS_TABLE'=>'fixture_auth_access','FORUMS_TABLE'=>'fixture_forums') as $key=>$value) { define($key,$value); }
require $ats_source.'includes/functions_userlist_storage.php';
$controller=file_get_contents($ats_source.'admin/admin_users_list.php');
$start=strpos($controller,'$selected_users = isset('); $end=strpos($controller,'$template->set_filenames',$start);
ats_check($start!==false && $end>$start,'Complete actual controller bulk branch'); $ul_body=substr($controller,$start,$end-$start);
function ul_reset($actor='root', $setup=null) {
    global $lp_open,$lp_owner_queries,$lp_query_count,$lp_failure,$lp_hook,$lp_after_commit,$lp_committed,$lp_commit_failure,$lp_reuse,$db,$main,$userdata;
    ats_check($lp_open===0,'Previous bulk owner released'); $lp_owner_queries=array(); $lp_query_count=$lp_failure=0;
    $lp_hook=$lp_after_commit=null; $lp_committed=$lp_reuse=false; $lp_commit_failure='';
    foreach (array('users','sessions','sessions_keys','config','banlist','jr','groups','user_group','auth_access','forums') as $table) { lp_query('DELETE FROM fixture_'.$table); }
    foreach (array(-1,1,2,3,4,5,6) as $id) {
        lp_insert('fixture_users',array('user_id'=>$id,'username'=>'Fixture '.$id,'user_level'=>$id===3||($id===1&&$actor==='root')?ADMIN:($id===5||$id===6?MOD:USER),'user_active'=>$id===4?0:1,'user_warnings'=>$id===4?2:0));
        lp_insert('fixture_sessions',array('session_id'=>$id===1?'fixture-admin':'fixture-user-'.$id,'session_user_id'=>$id,'session_logged_in'=>$id>0?1:0,'session_admin'=>$id===1?1:0,'session_ip'=>'7f000001'));
    }
    lp_insert('fixture_sessions',array('session_id'=>'fixture-member-second','session_user_id'=>2,'session_logged_in'=>1,'session_admin'=>0));
    lp_insert('fixture_config',array('config_name'=>'max_user_bancard','config_value'=>'10'));
    foreach (array(10,11,12,13,14) as $id) { lp_insert('fixture_groups',array('group_id'=>$id,'group_single_user'=>$id===11?1:0,'group_name'=>'Fixture group '.$id)); }
    lp_insert('fixture_forums',array('forum_id'=>20));
    foreach (array(10,12,14) as $id) { lp_insert('fixture_auth_access',array('group_id'=>$id,'forum_id'=>$id===14?999:20,'auth_mod'=>1)); }
    lp_insert('fixture_user_group',array('group_id'=>10,'user_id'=>4,'user_pending'=>1));
    lp_insert('fixture_user_group',array('group_id'=>11,'user_id'=>2,'user_pending'=>0));
    lp_insert('fixture_user_group',array('group_id'=>12,'user_id'=>5,'user_pending'=>0));
    foreach (array(array('ban_userid'=>2),array('ban_userid'=>1),array('ban_userid'=>0,'ban_ip'=>'7f000001'),array('ban_userid'=>0,'ban_email'=>'fixture@example.invalid')) as $rule) { lp_insert('fixture_banlist',$rule); }
    if ($actor!=='root') {
        $hash=array_search($actor==='wrong'?'admin_ranks.php':'admin_users_list.php',jr_admin_authorization_routes(),true);
        ats_check($hash!==false,'Exact registered junior module'); lp_insert('fixture_jr',array('user_id'=>1,'user_jr_admin'=>$hash));
    }
    $db=$main; $userdata=array('user_id'=>1,'user_level'=>$actor==='root'?ADMIN:USER,'session_logged_in'=>1,'session_admin'=>1,'session_id'=>'fixture-admin');
    if ($setup!==null) { call_user_func($setup); }
}
function ul_request($action, $values=array()) {
    global $db,$phpbb_root_path,$phpEx,$lang,$ul_body;
    $lang['Admin_userlist_result']='%d changed; %d unchanged'; $lang['Click_return_userlist']='%sReturn%s';
    profile_fixture_request(array_merge(array('u'=>array('1','2','3','4','5','6','999','2'),'bulk_action'=>$action,'group_id'=>'10','sid'=>'fixture-admin'),$values)); $_SERVER['REQUEST_METHOD']='POST';
    try { eval($ul_body); throw new RuntimeException('Actual controller must terminate'); }
    catch (AttachSettingsExit $e) { ats_check($GLOBALS['lp_open']===0 && $db===$GLOBALS['main'],'Owner released/caller intact before response'); return $e->getMessage(); }
}
function ul_success($message) { return preg_match('/^[0-9]+ changed; [0-9]+ unchanged<br/', $message)===1; }
function ul_snapshot() {
    return array(lp_rows('SELECT user_id,user_level,user_active,user_warnings FROM fixture_users WHERE user_id<>1 ORDER BY user_id'),
        lp_rows("SELECT session_id,session_user_id FROM fixture_sessions WHERE session_id<>'fixture-admin' ORDER BY session_id"),
        lp_rows('SELECT ban_userid,ban_ip,ban_email,ban_ip_mask FROM fixture_banlist ORDER BY ban_id'),lp_rows('SELECT group_id,user_id,user_pending FROM fixture_user_group ORDER BY group_id,user_id'));
}
function ul_revoke($kind) {
    $changes=array('role'=>'UPDATE fixture_users SET user_level=0 WHERE user_id=1','inactive'=>'UPDATE fixture_users SET user_active=0 WHERE user_id=1','grant'=>'DELETE FROM fixture_jr WHERE user_id=1',
        'session'=>"DELETE FROM fixture_sessions WHERE session_id='fixture-admin'",'admin-off'=>"UPDATE fixture_sessions SET session_admin=0 WHERE session_id='fixture-admin'",
        'logout'=>"UPDATE fixture_sessions SET session_logged_in=0 WHERE session_id='fixture-admin'",'foreign'=>"UPDATE fixture_sessions SET session_user_id=2 WHERE session_id='fixture-admin'",'case'=>"UPDATE fixture_sessions SET session_id='FIXTURE-ADMIN' WHERE session_id='fixture-admin'");
    return $changes[$kind];
}
$cases=$serialized=0;
try {
    set_error_handler(function($severity,$message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } });
    $schema=file_get_contents($ats_source.'install/schemas/mysql_schema.sql');
    foreach (array('users','sessions','sessions_keys','config','banlist','jr_admin_users','groups','user_group','auth_access','forums') as $table) {
        ats_check(preg_match('/CREATE TABLE `?phpbb_'.$table.'`?\s*\([\s\S]*?;/',$schema,$m)===1,'Canonical bulk participant'); lp_query(str_replace('phpbb_'.$table,$table==='jr_admin_users'?'fixture_jr':'fixture_'.$table,$m[0]));
    }
    require_once dirname(rtrim($ats_source,'/')).'/update/innodb_migration.php'; $covered=plus_storage_tables($schema,'fixture_');
    foreach (array('users','sessions','sessions_keys','config','banlist','jr_admin_users','groups','user_group','auth_access','forums') as $table) { ats_check(in_array('fixture_'.$table,$covered,true),'Existing updater covers every bulk participant'); }
    foreach (array('', 'STRICT_ALL_TABLES','ANSI_QUOTES','NO_BACKSLASH_ESCAPES') as $mode) {
        $main->sql_query("SET SESSION sql_mode='$mode'"); lp_query("SET SESSION sql_mode='$mode'");
        foreach (array('root','delegated') as $actor) { foreach (array('activate'=>1,'deactivate'=>3,'ban'=>4,'unban'=>1,'group'=>4) as $action=>$changed) {
            ul_reset($actor); $out=ul_request($action); ats_check(strpos($out,$changed.' changed; '.(7-$changed).' unchanged')===0,'Actual complete bulk '.$mode.'/'.$actor.'/'.$action.': '.$out);
            ats_check(lp_rows('SELECT session_id FROM fixture_sessions WHERE session_user_id IN(2,4,5,6)')===array(),'Every eligible target session invalidated atomically');
            ats_check(count(lp_rows('SELECT session_id FROM fixture_sessions WHERE session_user_id IN(-1,1,3)'))===3,'Guest/actor/current administrators protected');
            ats_check(count(lp_rows('SELECT ban_id FROM fixture_banlist WHERE ban_userid=0'))===2,'Independent IP/email bans preserved');
            if ($action==='group') { ats_check(count(lp_rows('SELECT user_id FROM fixture_users WHERE user_id IN(2,4,5,6) AND user_level=2'))===4,'Approved memberships grant only actual moderator role'); }
            if ($action==='ban') { ats_check(count(lp_rows('SELECT user_id FROM fixture_users WHERE user_id IN(2,4,5,6) AND user_warnings=10'))===4,'Bulk bans use current locked warning policy'); }
            if ($action==='unban') { ats_check((int)lp_rows('SELECT user_warnings FROM fixture_users WHERE user_id=4')[0]['user_warnings']===2,'Non-banned warning points preserved'); }
            ats_check(strpos(ul_request($action),'0 changed; 7 unchanged')===0,'Repeated operation is idempotent'); $cases++;
        } }
    }
    foreach (array('root','delegated') as $actor) { foreach (array('activate','deactivate','ban','unban','group') as $action) {
        ul_reset($actor); ats_check(ul_success(ul_request($action)),'Record entire native batch'); $total=$lp_query_count; $expected=ul_snapshot();
        for ($at=1;$at<=$total;$at++) {
            ul_reset($actor); $before=ul_snapshot(); $lp_failure=$at;
            ats_check(!ul_success(ul_request($action)) && ul_snapshot()===$before,'Each failure restores full batch including earlier targets'); $cases++;
        }
        foreach (array('before','ack') as $failure) {
            ul_reset($actor); $before=ul_snapshot(); $lp_commit_failure=$failure;
            ats_check(!ul_success(ul_request($action)) && ul_snapshot()===($failure==='ack'?$expected:$before),'Failed/uncertain batch commit never reports success'); $cases++;
        }
        foreach (array('inactive','session','admin-off','logout','foreign','case',$actor==='root'?'role':'grant') as $change) {
            ul_reset($actor); lp_query(ul_revoke($change)); $before=ul_snapshot(); ats_check(!ul_success(ul_request($action)) && ul_snapshot()===$before,'Current exact actor refusal before writes'); $cases++;
        }
        foreach (array('session',$actor==='root'?'role':'grant') as $change) { foreach (array('before-lock','write','commit') as $boundary) {
            ul_reset($actor); $before=ul_snapshot(); $seen=$blocked=false;
            $lp_hook=function($sql) use ($change,$boundary,&$seen,&$blocked) {
                $match=$boundary==='before-lock'?strpos($sql,'SELECT * FROM fixture_banlist LIMIT 0')===0:($boundary==='write'?strpos($sql,'DELETE FROM fixture_sessions WHERE session_user_id=2')===0:$sql==='COMMIT');
                if (!$match||$seen) { return; } $seen=true; $GLOBALS['lp_hook']=null; lp_query('SET SESSION innodb_lock_wait_timeout=0');
                try { $r=$GLOBALS['peer']->sql_query(ul_revoke($change)); if (!$r) { $e=$GLOBALS['peer']->sql_error(); ats_check((int)$e['code']===1205,'Real lock serialization'); $blocked=true; } }
                finally { lp_query('SET SESSION innodb_lock_wait_timeout=1'); }
            };
            $out=ul_request($action); ats_check($seen,'Recorded full batch boundary reached');
            if ($blocked) { ats_check(ul_success($out) && ul_snapshot()===$expected,'Whole batch precedes serialized revocation'); lp_query(ul_revoke($change)); $serialized++; }
            else { ats_check(!ul_success($out) && ul_snapshot()===$before,'Prior revocation refuses complete batch'); }
            $cases++;
        } }
        ul_reset($actor); $lp_after_commit=function() { lp_query(ul_revoke('session')); };
        ats_check(ul_success(ul_request($action)) && ul_snapshot()===$expected,'Confirmed batch remains successful after later logout'); $lp_after_commit=null;
        ats_check(!ul_success(ul_request($action)),'Following logged-out request refused'); $cases++;
    } }
    foreach (array(13,14) as $group) {
        ul_reset(); ats_check(ul_success(ul_request('group',array('group_id'=>(string)$group))),'Ordinary/orphan group assignment');
        ats_check((int)lp_rows('SELECT user_level FROM fixture_users WHERE user_id=2')[0]['user_level']===USER && (int)lp_rows('SELECT user_level FROM fixture_users WHERE user_id=6')[0]['user_level']===USER,'No orphan moderator role; stale role corrected');
        ats_check((int)lp_rows('SELECT user_level FROM fixture_users WHERE user_id=5')[0]['user_level']===MOD,'Other valid moderator membership preserved'); $cases++;
    }
    foreach (array('11','999') as $group) { ul_reset(); $before=ul_snapshot(); ats_check(!ul_success(ul_request('group',array('group_id'=>$group))) && ul_snapshot()===$before,'Personal/missing group cannot change batch'); $cases++; }
    foreach (array(array('u'=>array(array(2))),array('u'=>array(true)),array('u'=>array('2junk')),array('u'=>array_fill(0,1001,'2')),array('group_id'=>array('10')),array('sid'=>array('fixture-admin')),array('sid'=>'wrong')) as $payload) {
        ul_reset(); $before=ul_snapshot(); ats_check(!ul_success(ul_request('group',$payload)) && ul_snapshot()===$before,'Malformed actual form never writes'); $cases++;
    }
    ul_reset('wrong'); $before=ul_snapshot(); ats_check(!ul_success(ul_request('deactivate')) && ul_snapshot()===$before,'Unrelated junior module refused'); $cases++;
    foreach (array('activate','deactivate','group') as $action) {
        ul_reset(); lp_query('UPDATE fixture_users SET '.($action==='group'?'user_level':'user_active').'=NULL WHERE user_id=2');
        ats_check(strpos(ul_request($action,array('u'=>array('2'))),'1 changed; 0 unchanged')===0,'Canonical nullable historical state can be corrected');
        $target=lp_rows('SELECT user_active,user_level FROM fixture_users WHERE user_id=2')[0];
        ats_check($action==='group'?(int)$target['user_level']===MOD:((int)$target['user_active']===($action==='activate'?1:0) && $target['user_active']!==null),'Complete action stores the intended state, not NULL'); $cases++;
    }
    ul_reset(); lp_insert('fixture_banlist',array('ban_userid'=>2,'ban_ip'=>'0a000001','ban_ip_mask'=>'ffffffff','ban_email'=>'keep@example.invalid')); lp_query('UPDATE fixture_users SET user_warnings=10 WHERE user_id=2');
    ats_check(ul_success(ul_request('unban')) && count(lp_rows("SELECT ban_id FROM fixture_banlist WHERE ban_userid=0 AND ban_ip='0a000001' AND ban_ip_mask='ffffffff' AND ban_email='keep@example.invalid'"))===1 && (int)lp_rows('SELECT user_warnings FROM fixture_users WHERE user_id=2')[0]['user_warnings']===0,'Unban preserves mixed rule IP/mask/email components and clears only removed user ban warnings'); $cases++;
    foreach (array('users','sessions','sessions_keys','config','banlist','jr','groups','user_group','auth_access','forums') as $table) {
        foreach (array('ENGINE=MyISAM','ROW_FORMAT=COMPACT','CONVERT TO CHARACTER SET latin1') as $legacy) {
            ul_reset(); lp_query('SET SESSION innodb_strict_mode=OFF'); try { lp_query('ALTER TABLE fixture_'.$table.' '.$legacy); } finally { lp_query('SET SESSION innodb_strict_mode=ON'); }
            $before=ul_snapshot(); ats_check(!ul_success(ul_request('group')) && ul_snapshot()===$before,'Each noncanonical participant refuses full batch');
            lp_query('ALTER TABLE fixture_'.$table.' ENGINE=InnoDB ROW_FORMAT=DYNAMIC, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); $cases++;
        }
    }
    foreach (array('activate','deactivate','ban','unban','group') as $action) {
        ul_reset(); $before=ul_snapshot(); $seen=false;
        $lp_hook=function($sql,$connection) use (&$seen) { if (strpos($sql,'DELETE FROM fixture_sessions WHERE session_user_id=4')!==0||$seen) { return; } $seen=true; $GLOBALS['lp_hook']=null; lp_query('KILL CONNECTION '.mysqli_thread_id($connection->db_connect_id)); };
        ats_check(!ul_success(ul_request($action)) && $seen && ul_snapshot()===$before,'Killed owner restores earlier target changes'); $cases++;
    }
    foreach (array('target'=>'UPDATE fixture_users SET user_level=1 WHERE user_id=2','group'=>'UPDATE fixture_groups SET group_single_user=1 WHERE group_id=10','acl'=>'DELETE FROM fixture_auth_access WHERE group_id=10','forum'=>'DELETE FROM fixture_forums WHERE forum_id=20','membership'=>'DELETE FROM fixture_user_group WHERE user_id=4','ddl'=>'ALTER TABLE fixture_user_group ENGINE=MyISAM') as $kind=>$query) {
        ul_reset(); $seen=$blocked=false;
        $lp_hook=function($sql) use ($query,&$seen,&$blocked) {
            if ($sql!=='COMMIT'||$seen) { return; } $seen=true; $GLOBALS['lp_hook']=null; lp_query('SET SESSION innodb_lock_wait_timeout=0'); lp_query('SET SESSION lock_wait_timeout=0');
            try { $r=$GLOBALS['peer']->sql_query($query); $e=$GLOBALS['peer']->sql_error(); ats_check(!$r && (int)$e['code']===1205,'Current role/membership/schema serialized'); $blocked=true; }
            finally { lp_query('SET SESSION innodb_lock_wait_timeout=1'); lp_query('SET SESSION lock_wait_timeout=1'); }
        };
        ats_check(ul_success(ul_request('group')) && $seen && $blocked,'Concurrent '.$kind.' cannot invalidate batch'); $cases++; $serialized++;
    }
    foreach (array('missing','-1','01','32768') as $limit) {
        ul_reset(); lp_query($limit==='missing'?"DELETE FROM fixture_config WHERE config_name='max_user_bancard'":"UPDATE fixture_config SET config_value='$limit' WHERE config_name='max_user_bancard'"); $before=ul_snapshot();
        ats_check(!ul_success(ul_request('ban')) && ul_snapshot()===$before,'Missing/invalid warning policy prevents whole ban'); $cases++;
    }
    lp_query('CREATE TABLE fixture_caller(marker INT PRIMARY KEY) ENGINE=InnoDB ROW_FORMAT=DYNAMIC');
    ul_reset(); $main->sql_query('START TRANSACTION'); $lp_fixture_write=true; $main->sql_query('INSERT INTO fixture_caller VALUES(1)'); $lp_fixture_write=false;
    ats_check(ul_success(ul_request('group')) && lp_rows('SELECT * FROM fixture_caller')===array(),'Bulk cannot commit caller transaction'); $main->sql_query('ROLLBACK'); $cases++;
    ul_reset(); $main->sql_query('START TRANSACTION'); $lp_fixture_write=true; $main->sql_query('INSERT INTO fixture_caller VALUES(2)'); $lp_fixture_write=false; $lp_reuse=true; $before=ul_snapshot();
    ats_check(!ul_success(ul_request('group')) && $main->db_connect_id!==null && ul_snapshot()===$before && lp_rows('SELECT * FROM fixture_caller')===array(),'Reused factory cannot commit or close caller'); $main->sql_query('ROLLBACK'); $cases++;
    echo 'Native userlist publication: '.$cases.' boundary/failure cases and '.$serialized." serialized changes passed.\n";
} finally { $main->sql_close(); $peer->sql_close(); $control->sql_query('DROP DATABASE '.$fixture); $control->sql_close(); restore_error_handler(); }
