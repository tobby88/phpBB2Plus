<?php
require __DIR__ . '/check-user-removal-storage.php';
function managed_removal_fixture($actor=1,$level=0)
{
    removal_fixture($actor); $p=$GLOBALS['mutation_server']->pdo;
    $p->exec('UPDATE fixture_users SET user_active=1,user_level='.$level.' WHERE user_id=9');
    $p->exec('INSERT INTO fixture_messages (privmsgs_id,privmsgs_type,privmsgs_from_userid,privmsgs_to_userid,privmsgs_attachment) VALUES (30,0,8,10,1)');
    $p->exec("INSERT INTO fixture_pm_text VALUES (30,'unrelated')");
    $p->exec('INSERT INTO fixture_links (attach_id,post_id,privmsgs_id,user_id_1,user_id_2) VALUES (2,0,30,8,10)');
}
function managed_removal_run($post=array())
{
    return phpbb_admin_user_remove($GLOBALS['db'],array_merge(array('sid'=>'fixture-session'),$post?$post:array('deleteuser'=>'on','mode'=>'save','submit'=>'Save','id'=>9,'new_user'=>'0')));
}
function managed_removal_complete($actor=1)
{
    global $mutation_server,$removal_files_root;
    mutation_check(group_value('SELECT COUNT(*) FROM fixture_users WHERE user_id=9')===0 && group_value('SELECT COUNT(*) FROM fixture_user_removals')===0 && group_value('SELECT COUNT(*) FROM fixture_user_removal_items')===0,'Managed removal finishes and clears recovery journal');
    mutation_check(group_value('SELECT COUNT(*) FROM fixture_messages')===1 && group_value('SELECT COUNT(*) FROM fixture_pm_text')===1 && group_value('SELECT privmsgs_id FROM fixture_messages')===30,'General user manager preserves its all-target-copy PM policy without deleting unrelated mail');
    mutation_check(group_value('SELECT COUNT(*) FROM fixture_descriptions')===1 && group_value('SELECT attach_id FROM fixture_descriptions')===2 && is_file($removal_files_root.'/shared.txt'),'Unrelated copy keeps its shared attachment');
    foreach(array('only.txt','twin.txt','thumb.txt','thumbs/t_thumb.txt') as $file) { mutation_check(!file_exists($removal_files_root.'/'.$file),'Only attachments whose final copies were removed are deleted'); }
    mutation_check($mutation_server->pdo->query('SELECT post_username FROM fixture_posts')->fetchColumn()==="O'Brien Grüße" && group_value('SELECT COUNT(*) FROM fixture_posts WHERE poster_id=-1')===1,'Published content remains under current UTF8 guest name');
    mutation_check(group_value('SELECT COUNT(*) FROM fixture_groups WHERE group_id IN (4,11)')===0 && group_value('SELECT COUNT(*) FROM fixture_groups WHERE group_id=12')===1,'Captured personal groups removed, unrelated orphan preserved');
    mutation_check(group_value('SELECT group_moderator FROM fixture_groups WHERE group_id=10')===$actor && group_value('SELECT user_pending FROM fixture_memberships WHERE group_id=10 AND user_id='.$actor)===0,'Successor leader is an approved member');
    mutation_check(group_value('SELECT COUNT(*) FROM fixture_sessions WHERE session_user_id=9')===0 && group_value('SELECT COUNT(*) FROM fixture_keys WHERE user_id=9')===0,'Removed account sessions and keys revoked');
    mutation_check(group_value('SELECT user_new_privmsg FROM fixture_users WHERE user_id=8')===0,'Surviving recipient counters recomputed');
}
set_error_handler(function($severity,$message) { if(error_reporting()&$severity) { throw new RuntimeException($message); } });
try
{
    foreach(array(0,1) as $level) { managed_removal_fixture(1,$level); managed_removal_run(); managed_removal_complete(); }
    managed_removal_fixture(); $mutation_server->pdo->exec('UPDATE fixture_users SET user_active=0 WHERE user_id=9'); managed_removal_run(); managed_removal_complete();
    managed_removal_fixture(8); $mutation_server->pdo->exec("INSERT INTO fixture_junior VALUES (8,'".md5('UsersManageadmin_users.php')."')"); managed_removal_run(); managed_removal_complete(8);
    foreach(array('delegated-admin','first-admin','self','inactive-grant-only','no-grant') as $case)
    {
        managed_removal_fixture($case==='first-admin'?12:8,$case==='delegated-admin'?1:0);
        if($case==='first-admin') { $mutation_server->pdo->exec('UPDATE fixture_users SET user_level=1 WHERE user_id=12'); }
        elseif($case!=='no-grant') { $mutation_server->pdo->exec("INSERT INTO fixture_junior VALUES (8,'".md5($case==='inactive-grant-only'?'UsersActivate_titleadmin_account.php':'UsersManageadmin_users.php')."')"); }
        $id=$case==='first-admin'?1:($case==='self'?8:9);
        removal_failure(function() use($id) { managed_removal_run(array('deleteuser'=>'on','mode'=>'save','submit'=>'Save','id'=>$id)); },'Not_Authorised');
        mutation_check(group_value('SELECT COUNT(*) FROM fixture_user_removals')===0 && group_value('SELECT COUNT(*) FROM fixture_messages')===8 && group_value('SELECT COUNT(*) FROM fixture_posts WHERE poster_id=9')===1,'Protected/unauthorized deletion has no side effects: '.$case);
    }
    foreach(array(array('id'=>array(9)),array('id'=>'9garbage'),array('deleteuser'=>array('on')),array('new_user'=>1),array('mode'=>'edit'),array('removal_resume'=>str_repeat('a',32)),array('delete'=>9)) as $change)
    {
        managed_removal_fixture(); $post=array_merge(array('deleteuser'=>'on','mode'=>'save','submit'=>'Save','id'=>9),$change);
        removal_failure(function() use($post) { managed_removal_run($post); },'Removal_invalid');
        mutation_check(group_value('SELECT COUNT(*) FROM fixture_messages')===8 && group_value('SELECT COUNT(*) FROM fixture_user_removals')===0,'Invalid original profile selection must not reach quota/account writes');
    }
    foreach(array('DELETE FROM fixture_users','DELETE FROM fixture_keys','UPDATE fixture_posts','DELETE FROM fixture_messages','DELETE FROM fixture_pm_text','DELETE FROM fixture_links','DELETE FROM fixture_descriptions','DELETE FROM fixture_auth','DELETE FROM fixture_groups','DELETE FROM fixture_user_removals') as $failure)
    {
        managed_removal_fixture(); $mutation_server->failure=$failure;
        removal_failure(function() { managed_removal_run(); },'Removal_storage_failed'); $job=removal_job(); $mutation_server->failure='';
        if($failure==='DELETE FROM fixture_users')
        {
            mutation_check(group_value('SELECT COUNT(*) FROM fixture_messages')===8 && group_value('SELECT COUNT(*) FROM fixture_posts WHERE poster_id=9')===1,'Account DELETE failure no longer alters any PM or public content first');
            removal_failure(function() use($job) { managed_removal_run(array('removal_resume'=>$job)); },'Removal_account_changed');
            managed_removal_run(array('removal_cancel'=>$job)); managed_removal_run();
        }
        else { managed_removal_run(array('removal_resume'=>$job)); }
        managed_removal_complete();
    }
    foreach(array('role','activation','first-admin') as $case)
    {
        managed_removal_fixture($case==='first-admin'?12:1,$case==='first-admin'?1:0);
        if($case==='first-admin') { $mutation_server->pdo->exec('UPDATE fixture_users SET user_level=1 WHERE user_id=12'); }
        $mutation_server->hook=function($sql) use($case) { if(strpos($sql,'DELETE FROM fixture_users')===0) { $GLOBALS['mutation_server']->hook=null; $GLOBALS['mutation_server']->pdo->exec($case==='role'?'UPDATE fixture_users SET user_level=1 WHERE user_id=9':($case==='activation'?'UPDATE fixture_users SET user_active=0 WHERE user_id=9':'UPDATE fixture_users SET user_level=0 WHERE user_id=1')); } };
        removal_failure(function() { managed_removal_run(); },'Removal_account_changed');
        mutation_check(group_value('SELECT COUNT(*) FROM fixture_messages')===8 && group_value('SELECT COUNT(*) FROM fixture_posts WHERE poster_id=9')===1,'Late role/activation/founder change prevents pre-delete content mutations');
    }
    managed_removal_fixture(1,1); $mutation_server->failure='DELETE FROM fixture_keys'; removal_failure(function() { managed_removal_run(); },'Removal_storage_failed'); $job=removal_job(); $mutation_server->failure='';
    $userdata['user_id']=8; $mutation_server->pdo->exec("INSERT INTO fixture_junior VALUES (8,'".md5('UsersManageadmin_users.php')."')");
    removal_failure(function() use($job) { managed_removal_run(array('removal_resume'=>$job)); },'Not_Authorised');
    mutation_check(group_value('SELECT COUNT(*) FROM fixture_messages')===8,'Delegated manager cannot resume cleanup of a removed administrator');
    $userdata['user_id']=1; managed_removal_run(array('removal_resume'=>$job)); managed_removal_complete();
    foreach(array('before-delete','during-cleanup') as $phase)
    {
        managed_removal_fixture(8); $mutation_server->pdo->exec("INSERT INTO fixture_junior VALUES (8,'".md5('UsersManageadmin_users.php')."')");
        $mutation_server->hook=function($sql) use($phase) {
            if(strpos($sql,$phase==='before-delete'?'DELETE FROM fixture_users':'DELETE FROM fixture_keys')===0) { $GLOBALS['mutation_server']->hook=null; $GLOBALS['mutation_server']->pdo->exec('UPDATE fixture_junior SET user_jr_admin=UPPER(user_jr_admin) WHERE user_id=8'); }
        };
        removal_failure(function() { managed_removal_run(); },'Removal_account_changed');
        mutation_check(group_value('SELECT COUNT(*) FROM fixture_messages')===8 && group_value('SELECT COUNT(*) FROM fixture_posts WHERE poster_id=9')===1,'Byte-exact live grant snapshot prevents writes after a case-only permission change');
        mutation_check(group_value('SELECT COUNT(*) FROM fixture_users WHERE user_id=9')===($phase==='before-delete'?1:0),'Failure accurately reflects whether the account was already removed');
    }
    // A job token cannot cross workflows, even for a full administrator.
    removal_fixture(); $mutation_server->failure='DELETE FROM fixture_keys'; removal_failure(function() { removal_run(); },'Removal_storage_failed'); $job=removal_job(); $mutation_server->failure='';
    removal_failure(function() use($job) { managed_removal_run(array('removal_resume'=>$job)); },'Removal_invalid');
    removal_run(array('removal_resume'=>$job)); removal_assert_completed();
    managed_removal_fixture(); $mutation_server->failure='DELETE FROM fixture_keys'; removal_failure(function() { managed_removal_run(); },'Removal_storage_failed'); $job=removal_job(); $mutation_server->failure='';
    removal_failure(function() use($job) { removal_run(array('removal_resume'=>$job)); },'Removal_invalid');
    managed_removal_run(array('removal_resume'=>$job)); managed_removal_complete();
    $controller=file_get_contents($forum_root.'admin/admin_users.php');
    $begin=strpos($controller,'// Durable user deletion is dispatched'); $end=strpos($controller,'// Start add - Admin add user MOD',$begin);
    mutation_check($begin!==false && $end>$begin && $end<strpos($controller,'$new_user =') && $end<strpos($controller,'attachment_quota_settings('),'Deletion dispatch precedes creation, quotas and profile mutations');
    mutation_check(strpos($controller,'phpbb_pm_delete_user_messages(')===false,'No legacy pre-account-delete PM path remains in user manager');
    $body=substr($controller,$begin,$end-$begin); $lang['Click_return_useradmin']='Return %susers%s';
    managed_removal_fixture(); $reader=new RemovalReader(); $template=new RemovalTemplate(); $_POST=array('sid'=>'fixture-session','deleteuser'=>'on','mode'=>'save','submit'=>'Save','id'=>9); $mutation_server->failure='DELETE FROM fixture_keys';
    $message=''; try { call_user_func(function() use($body,$reader,$template,$lang) { global $phpEx; $db=$reader; eval($body); }); } catch(MutationFailure $error) { $message=$error->getMessage(); }
    mutation_check(strpos($message,'Removal_storage_failed')!==false && strpos($message,'name="removal_resume"')!==false && strpos($message,'action="admin_users.php"')!==false && strpos($message,'name="removal_cancel"')===false,'Actual failed profile deletion returns recovery form on its own route, not a stale profile save');
    $job=removal_job(); $mutation_server->failure=''; $_POST=array('sid'=>'fixture-session','removal_resume'=>$job);
    try { call_user_func(function() use($body,$reader,$template,$lang) { global $phpEx; $db=$reader; eval($body); }); } catch(MutationFailure $error) { $message=$error->getMessage(); }
    mutation_check(strpos($message,'Removal_completed')!==false && strpos($message,'name="removal_resume"')===false,'Actual resume completes and clears recovery form'); managed_removal_complete();
    echo "Managed account removal, privilege and recovery checks passed.\n";
}
finally { removal_clear_files(); restore_error_handler(); }
