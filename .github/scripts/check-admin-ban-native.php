<?php
// Complete actual ACP submit branch, native driver, canonical disposable schema.
// No live ban, account, configuration or database is used.
if (PHP_SAPI !== 'cli' || getenv('PHPBB_ADMIN_BAN_NATIVE') !== '1') { echo "Native ACP ban checks require an explicitly enabled disposable database.\n"; return; }
putenv('PHPBB_LOGIN_PUBLICATION_NATIVE=1');
putenv('PHPBB_LOGIN_PUBLICATION_PORT=' . (getenv('PHPBB_ADMIN_BAN_PORT') ?: '3306'));
putenv('PHPBB_LOGIN_PUBLICATION_PASSWORD=' . (getenv('PHPBB_ADMIN_BAN_PASSWORD') ?: ''));
$file = __DIR__ . '/check-login-publication-native.php'; $source = file_get_contents($file);
$cut = strpos($source, "\ntry{\n set_error_handler");
if ($cut === false) { throw new RuntimeException('Shared fixture boundary changed'); }
eval(substr(str_replace('__DIR__', var_export(__DIR__, true), substr($source, 0, $cut)), 5));
require $ats_source . 'ctracker/classes/class_ct_database.php';
require $ats_source . 'language/lang_english/lang_cback_ctracker.php';
require $ats_source . 'includes/functions_admin_ban_storage.php';
ats_load_function($ats_source . 'includes/functions.php', 'get_userdata');
ats_load_function($ats_source . 'includes/functions.php', 'encode_ip');
ats_load_function($ats_source . 'admin/admin_user_ban.php', 'admin_ban_post_string');
ats_load_function($ats_source . 'admin/admin_user_ban.php', 'admin_ban_add_ip');
$controller = file_get_contents($ats_source . 'admin/admin_user_ban.php');
$start = strpos($controller, "if ( isset(\$_POST['submit']) )"); $end = strpos($controller, "\nelse\n", $start);
ats_check($start !== false && $end > $start, 'Actual complete ACP submit branch'); $ban_body = substr($controller, $start, $end - $start);
function ban_reset($actor = 'root', $setup = null) {
    global $lp_owner_queries,$lp_open,$lp_query_count,$lp_failure,$lp_hook,$lp_after_commit,$lp_committed,$lp_commit_failure,$lp_reuse,$userdata,$db,$main,$board_config,$ctracker_config;
    ats_check($lp_open === 0, 'Previous owner released');
    $lp_owner_queries=array(); $lp_query_count=$lp_failure=0; $lp_hook=$lp_after_commit=null; $lp_committed=$lp_reuse=false; $lp_commit_failure='';
    foreach (array('users','sessions','sessions_keys','banlist','config','jr') as $table) { lp_query('DELETE FROM fixture_'.$table); }
    foreach (array(-1,1,2,3,4) as $id) {
        lp_insert('fixture_users', array('user_id'=>$id,'username'=>array(-1=>'Anonymous',1=>'Admin',2=>'Member',3=>'Founder',4=>'Other')[$id],
            'user_level'=>$id===3 || ($id===1 && $actor==='root')?ADMIN:0,'user_active'=>1,'user_email'=>($id===2?'member':'actor'.$id).'@example.invalid','user_warnings'=>0));
    }
    lp_insert('fixture_sessions', array('session_id'=>'fixture-admin','session_user_id'=>1,'session_logged_in'=>1,'session_admin'=>1,'session_ip'=>'0a000001'));
    lp_insert('fixture_sessions', array('session_id'=>'fixture-member','session_user_id'=>2,'session_logged_in'=>1,'session_admin'=>0,'session_ip'=>'7F000001'));
    lp_insert('fixture_sessions', array('session_id'=>'fixture-other','session_user_id'=>4,'session_logged_in'=>1,'session_admin'=>0,'session_ip'=>'7f000002'));
    lp_insert('fixture_config', array('config_name'=>'max_user_bancard','config_value'=>'10'));
    if ($actor !== 'root') {
        $hash = array_search($actor==='wrong'?'admin_ranks.php':'admin_user_ban.php', jr_admin_authorization_routes(), true);
        ats_check($hash !== false, 'Exact real junior module grant'); lp_insert('fixture_jr', array('user_id'=>1,'user_jr_admin'=>$hash));
    }
    $db=$main; $userdata=array('user_id'=>1,'user_level'=>$actor==='root'?ADMIN:0,'session_id'=>'fixture-admin','session_logged_in'=>1,'session_admin'=>1);
    // Stale cached settings must not control the current warning update.
    $board_config=array('max_user_bancard'=>99); $ctracker_config=(new ReflectionClass('ct_database'))->newInstanceWithoutConstructor();
    if ($setup !== null) { call_user_func($setup); }
}
function ban_run($payload) {
    global $db,$userdata,$ctracker_config,$phpbb_root_path,$phpEx,$lang,$ban_body,$board_config;
    profile_fixture_request(array_merge(array('submit'=>'Save','sid'=>'fixture-admin'),$payload)); $_SERVER['REQUEST_METHOD']='POST';
    try { eval($ban_body); throw new RuntimeException('Actual controller must terminate'); }
    catch (AttachSettingsExit $e) { ats_check($db === $GLOBALS['main'] && $GLOBALS['lp_open']===0,'Controller restores caller and releases owner before response'); return $e->getMessage(); }
}
function ban_success($out) { return strpos($out, $GLOBALS['lang']['Ban_update_sucessful']) === 0; }
function ban_snapshot() {
    return array(lp_rows('SELECT ban_userid,ban_ip,ban_email,ban_ip_mask FROM fixture_banlist ORDER BY ban_id'),
        lp_rows('SELECT user_id,user_warnings FROM fixture_users ORDER BY user_id'), lp_rows("SELECT session_id,session_user_id,session_logged_in,session_admin FROM fixture_sessions WHERE session_id<>'fixture-admin' ORDER BY session_id"));
}
function ban_revoke($kind) {
    $queries=array('role'=>'UPDATE fixture_users SET user_level=0 WHERE user_id=1','inactive'=>'UPDATE fixture_users SET user_active=0 WHERE user_id=1',
        'session'=>"DELETE FROM fixture_sessions WHERE session_id='fixture-admin'",'admin-off'=>"UPDATE fixture_sessions SET session_admin=0 WHERE session_id='fixture-admin'",
        'logout'=>"UPDATE fixture_sessions SET session_logged_in=0 WHERE session_id='fixture-admin'",'foreign'=>"UPDATE fixture_sessions SET session_user_id=4 WHERE session_id='fixture-admin'",
        'case'=>"UPDATE fixture_sessions SET session_id='FIXTURE-ADMIN' WHERE session_id='fixture-admin'",'grant'=>'DELETE FROM fixture_jr WHERE user_id=1');
    return $queries[$kind];
}
$cases=$serialized=0;
try {
    set_error_handler(function($severity,$message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } });
    $schema=file_get_contents($ats_source.'install/schemas/mysql_schema.sql');
    foreach (array('users','sessions','sessions_keys','banlist','config','jr_admin_users') as $suffix) {
        ats_check(preg_match('/CREATE TABLE `?phpbb_'.$suffix.'`?\s*\([\s\S]*?;/', $schema, $m)===1,'Canonical ban participant');
        lp_query(str_replace('phpbb_'.$suffix, $suffix==='jr_admin_users'?'fixture_jr':'fixture_'.$suffix, $m[0]));
    }
    require_once dirname(rtrim($ats_source,'/')).'/update/innodb_migration.php';
    $covered=plus_storage_tables($schema,'fixture_');
    foreach (array('users','sessions','sessions_keys','banlist','config','jr_admin_users') as $suffix) { ats_check(in_array('fixture_'.$suffix,$covered,true),'Updater covers ban participant'); }
    foreach (array('', 'STRICT_ALL_TABLES', 'ANSI_QUOTES', 'NO_BACKSLASH_ESCAPES') as $mode) {
        $main->sql_query("SET SESSION sql_mode='$mode'"); lp_query("SET SESSION sql_mode='$mode'");
        foreach (array('root','delegated') as $actor) {
            foreach (array('user'=>array('username'=>'Member'),'ip'=>array('ban_ip'=>'127.0.0.1'),'subnet'=>array('ban_ip'=>'127.0.0.*'),'email'=>array('ban_email'=>'member@example.invalid'),'combined'=>array('username'=>'Member','ban_ip'=>'127.0.0.*','ban_email'=>'member@example.invalid')) as $kind=>$payload) {
                ban_reset($actor); ats_check(ban_success(ban_run($payload)), 'Actual authorized '.$mode.'/'.$actor.'/'.$kind);
                ats_check(lp_rows("SELECT session_id FROM fixture_sessions WHERE session_user_id=2")===array(),'Matching active session expires with new ban');
                ats_check(count(lp_rows("SELECT session_id FROM fixture_sessions WHERE session_user_id=4"))===($kind==='subnet'||$kind==='combined'?0:1),'Unrelated or matching subnet session preserved/expired');
                ats_check((int)lp_rows('SELECT user_warnings FROM fixture_users WHERE user_id=2')[0]['user_warnings']===($kind==='user'||$kind==='combined'?10:0),'Current configured warnings only for user bans');
                $cases++;
            }
        }
    }
    foreach (array('root','delegated') as $actor) {
        $payload=array('username'=>'Member','ban_ip'=>'127.0.0.*','ban_email'=>'member@example.invalid');
        ban_reset($actor); ats_check(ban_success(ban_run($payload)),'Record whole request'); $total=$lp_query_count;
        for ($at=1;$at<=$total;$at++) {
            ban_reset($actor); $before=ban_snapshot(); $lp_failure=$at;
            ats_check(!ban_success(ban_run($payload)) && ban_snapshot()===$before,'Each owner query failure rolls back complete ban/warnings/sessions'); $cases++;
        }
        foreach (array('before','ack') as $failure) {
            ban_reset($actor); $before=ban_snapshot(); $lp_commit_failure=$failure;
            ats_check(!ban_success(ban_run($payload)),'Failed/uncertain commit cannot report success');
            ats_check(($failure==='before') === (ban_snapshot()===$before),'Uncertain committed state differs from rollback'); $cases++;
        }
        foreach (array('inactive','session','admin-off','logout','foreign','case',$actor==='root'?'role':'grant') as $kind) {
            ban_reset($actor); lp_query(ban_revoke($kind)); $before=ban_snapshot();
            ats_check(!ban_success(ban_run($payload)) && ban_snapshot()===$before,'Revoked current actor cannot publish'); $cases++;
        }
        foreach (array('session',$actor==='root'?'role':'grant') as $kind) {
            foreach (array('before-lock','rules','write','commit') as $boundary) {
                ban_reset($actor); $before=ban_snapshot(); $seen=$blocked=false;
                $lp_hook=function($sql) use ($kind,$boundary,&$seen,&$blocked) {
                    $match=$boundary==='before-lock'?strpos($sql,'SELECT * FROM fixture_banlist LIMIT 0')===0:($boundary==='rules'?strpos($sql,'SELECT ban_id,ban_userid,ban_ip,ban_email,ban_ip_mask FROM fixture_banlist')===0:($boundary==='write'?strpos($sql,'INSERT INTO fixture_banlist')===0:$sql==='COMMIT'));
                    if (!$match || $seen) { return; } $seen=true; $GLOBALS['lp_hook']=null;
                    lp_query('SET SESSION innodb_lock_wait_timeout=0');
                    try { $r=$GLOBALS['peer']->sql_query(ban_revoke($kind)); if (!$r) { $e=$GLOBALS['peer']->sql_error(); ats_check((int)$e['code']===1205,'Only real native lock serialization'); $blocked=true; } }
                    finally { lp_query('SET SESSION innodb_lock_wait_timeout=1'); }
                };
                $out=ban_run($payload); ats_check($seen,'Actual recorded authority boundary reached');
                if ($blocked) { ats_check(ban_success($out),'Held current authority commits before revocation'); lp_query(ban_revoke($kind)); $serialized++; }
                else { ats_check(!ban_success($out) && ban_snapshot()===$before,'Earlier revocation refuses full publication'); }
                $cases++;
            }
        }
    }
    ban_reset('wrong'); $before=ban_snapshot(); ats_check(!ban_success(ban_run(array('username'=>'Member'))) && ban_snapshot()===$before,'Unrelated junior module refused'); $cases++;
    foreach (array('root','delegated') as $actor) {
        foreach (array('session',$actor==='root'?'role':'grant') as $kind) {
            ban_reset($actor); $lp_after_commit=function() use ($kind) { lp_query(ban_revoke($kind)); };
            ats_check(ban_success(ban_run(array('username'=>'Member'))),'Acknowledged ban survives later authority revocation');
            $lp_after_commit=null; ats_check(!ban_success(ban_run(array('ban_ip'=>'127.0.0.2'))),'Following revoked request refused'); $cases++;
        }
    }
    foreach (array(array('username'=>'Admin'),array('ban_ip'=>'10.0.0.1'),array('ban_email'=>'actor1@example.invalid'),array('ban_email'=>'*@example.invalid')) as $payload) {
        ban_reset(); $before=ban_snapshot(); ats_check(!ban_success(ban_run($payload)) && ban_snapshot()===$before,'Own/founder session cannot be disabled'); $cases++;
    }
    ban_reset('delegated'); $before=ban_snapshot(); ats_check(!ban_success(ban_run(array('username'=>'Founder'))) && ban_snapshot()===$before,'Founder protected for delegated user'); $cases++;
    foreach (array(array('unban_user'=>'1'),array('unban_user'=>array(true)),array('unban_user'=>array('1e0')),array('unban_user'=>array('999')),array('sid'=>array('bad')),array('sid'=>'wrong'),array('username'=>array('Member')),array('ban_ip'=>true),array('ban_email'=>array('member@example.invalid')),array('ban_email'=>str_repeat('x@example.invalid,',100))) as $payload) {
        ban_reset(); $before=ban_snapshot(); ats_check(!ban_success(ban_run(array_merge(array('username'=>'Member'),$payload))) && ban_snapshot()===$before,'Malformed/stale selection refuses full form'); $cases++;
    }
    ban_reset(); lp_insert('fixture_banlist',array('ban_id'=>100,'ban_userid'=>2)); lp_insert('fixture_banlist',array('ban_id'=>101,'ban_userid'=>2)); lp_query('UPDATE fixture_users SET user_warnings=10 WHERE user_id=2');
    ats_check(ban_success(ban_run(array('unban_user'=>array('100')))) && (int)lp_rows('SELECT user_warnings FROM fixture_users WHERE user_id=2')[0]['user_warnings']===10,'Duplicate remaining ban preserves warnings');
    ats_check(ban_success(ban_run(array('unban_user'=>array('101')))) && (int)lp_rows('SELECT user_warnings FROM fixture_users WHERE user_id=2')[0]['user_warnings']===0,'Last user ban removed clears warnings'); $cases+=2;
    ban_reset(); lp_insert('fixture_banlist',array('ban_id'=>100,'ban_userid'=>2)); $before=ban_snapshot();
    ats_check(!ban_success(ban_run(array('unban_email'=>array('100')))) && ban_snapshot()===$before,'Unban selection has exact expected kind'); $cases++;
    $unban_setup=function() {
        lp_insert('fixture_banlist',array('ban_id'=>100,'ban_userid'=>2));
        lp_insert('fixture_banlist',array('ban_id'=>101,'ban_ip'=>'7f000001'));
        lp_insert('fixture_banlist',array('ban_id'=>102,'ban_email'=>'member@example.invalid'));
        lp_query('UPDATE fixture_users SET user_warnings=10 WHERE user_id=2');
    };
    $unban=array('unban_user'=>array('100'),'unban_ip'=>array('101'),'unban_email'=>array('102'));
    ban_reset('root',$unban_setup); ats_check(ban_success(ban_run($unban)) && lp_rows('SELECT ban_id FROM fixture_banlist')===array(),'Actual full unban form removes all selected kinds'); $total=$lp_query_count; $expected=ban_snapshot(); $cases++;
    for ($at=1;$at<=$total;$at++) {
        ban_reset('root',$unban_setup); $before=ban_snapshot(); $lp_failure=$at;
        ats_check(!ban_success(ban_run($unban)) && ban_snapshot()===$before,'Every unban query failure restores rules and warnings'); $cases++;
    }
    foreach (array('before','ack') as $failure) {
        ban_reset('root',$unban_setup); $before=ban_snapshot(); $lp_commit_failure=$failure;
        ats_check(!ban_success(ban_run($unban)) && ban_snapshot()===($failure==='ack'?$expected:$before),'Unban failed/uncertain commit handled truthfully'); $cases++;
    }
    ban_reset(); $before=ban_snapshot(); $seen=false;
    $lp_hook=function($sql,$connection) use (&$seen) { if (strpos($sql,'INSERT INTO fixture_banlist')!==0 || $seen) { return; } $seen=true; $GLOBALS['lp_hook']=null; lp_query('KILL CONNECTION '.mysqli_thread_id($connection->db_connect_id)); };
    ats_check(!ban_success(ban_run(array('username'=>'Member'))) && $seen && ban_snapshot()===$before,'Killed owner cannot continue or publish partial ban'); $cases++;
    foreach (array('policy'=>"UPDATE fixture_config SET config_value='17' WHERE config_name='max_user_bancard'",'target'=>'UPDATE fixture_users SET user_level=1 WHERE user_id=2','rules'=>"INSERT INTO fixture_banlist(ban_userid,ban_ip,ban_email) VALUES(0,'','other@example.invalid')",'ddl'=>'ALTER TABLE fixture_banlist ENGINE=MyISAM') as $kind=>$query) {
        ban_reset(); $seen=$blocked=false;
        $lp_hook=function($sql) use ($query,&$seen,&$blocked) {
            if ($sql!=='COMMIT' || $seen) { return; } $seen=true; $GLOBALS['lp_hook']=null;
            lp_query('SET SESSION innodb_lock_wait_timeout=0'); lp_query('SET SESSION lock_wait_timeout=0');
            try { $r=$GLOBALS['peer']->sql_query($query); $e=$GLOBALS['peer']->sql_error(); ats_check(!$r && (int)$e['code']===1205,'Current policy/target/rules/schema held through COMMIT'); $blocked=true; }
            finally { lp_query('SET SESSION innodb_lock_wait_timeout=1'); lp_query('SET SESSION lock_wait_timeout=1'); }
        };
        ats_check(ban_success(ban_run(array('username'=>'Member'))) && $seen && $blocked,'Concurrent '.$kind.' serialized through confirmed ban'); $serialized++; $cases++;
    }
    foreach (array('missing','-1','01','32768') as $limit) {
        ban_reset(); lp_query($limit==='missing'?"DELETE FROM fixture_config WHERE config_name='max_user_bancard'":"UPDATE fixture_config SET config_value='$limit' WHERE config_name='max_user_bancard'"); $before=ban_snapshot();
        ats_check(!ban_success(ban_run(array('username'=>'Member'))) && ban_snapshot()===$before,'Missing/invalid current warning policy refuses writes'); $cases++;
    }
    ban_reset('delegated'); lp_query('UPDATE fixture_users SET user_level=1 WHERE user_id=4'); $before=ban_snapshot();
    ats_check(!ban_success(ban_run(array('username'=>'Other'))) && ban_snapshot()===$before,'Junior grant cannot ban another non-founder administrator'); $cases++;
    foreach (array('users','sessions','sessions_keys','banlist','config','jr') as $table) {
        foreach (array('ENGINE=MyISAM','ROW_FORMAT=COMPACT','CONVERT TO CHARACTER SET latin1') as $legacy) {
            ban_reset(); lp_query('SET SESSION innodb_strict_mode=OFF');
            try { lp_query('ALTER TABLE fixture_'.$table.' '.$legacy); } finally { lp_query('SET SESSION innodb_strict_mode=ON'); }
            $before=ban_snapshot(); ats_check(!ban_success(ban_run(array('username'=>'Member'))) && ban_snapshot()===$before,'Legacy participant fails closed');
            lp_query('ALTER TABLE fixture_'.$table.' ENGINE=InnoDB ROW_FORMAT=DYNAMIC, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); $cases++;
        }
    }
    lp_query('CREATE TABLE fixture_caller(marker INT PRIMARY KEY) ENGINE=InnoDB ROW_FORMAT=DYNAMIC');
    ban_reset(); $main->sql_query('START TRANSACTION'); $lp_fixture_write=true; $main->sql_query('INSERT INTO fixture_caller VALUES(1)'); $lp_fixture_write=false;
    ats_check(ban_success(ban_run(array('username'=>'Member'))) && lp_rows('SELECT * FROM fixture_caller')===array(),'Owned ban cannot commit caller'); $main->sql_query('ROLLBACK'); $cases++;
    ban_reset(); $main->sql_query('START TRANSACTION'); $lp_fixture_write=true; $main->sql_query('INSERT INTO fixture_caller VALUES(2)'); $lp_fixture_write=false; $lp_reuse=true; $before=ban_snapshot();
    ats_check(!ban_success(ban_run(array('username'=>'Member'))) && $main->db_connect_id!==null && ban_snapshot()===$before && lp_rows('SELECT * FROM fixture_caller')===array(),'Broken factory cannot commit/close caller'); $main->sql_query('ROLLBACK'); $cases++;
    echo 'Native ACP ban publication: '.$cases.' boundary/failure cases and '.$serialized." serialized revocations passed.\n";
} finally {
    $main->sql_close(); $peer->sql_close(); $control->sql_query('DROP DATABASE '.$fixture); $control->sql_close(); restore_error_handler();
}
