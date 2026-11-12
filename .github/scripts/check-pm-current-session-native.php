<?php
// Real installer tables, mysqli workers and independently committed revocation.
// No live data or transport: the notification fixture owns/drops its database.
if (PHP_SAPI !== 'cli' || getenv('PHPBB_PM_NOTIFY_NATIVE') !== '1') { return; }
function pc_login($actor, $sid = null)
{
    global $userdata;
    kp_sql('DELETE FROM fixture_sessions');
    $userdata['user_id'] = $actor; $userdata['session_id'] = $sid === null ? str_repeat('a',32) : $sid;
    kp_sql("INSERT INTO fixture_sessions(session_id,session_user_id,session_logged_in) VALUES('".$userdata['session_id']."',".(int)$actor.',1)');
    $_POST = array('sid'=>$userdata['session_id']); $_SERVER['REQUEST_METHOD'] = 'POST';
}
function pc_revoke($kind)
{
    if ($kind === 'missing') { kp_sql('DELETE FROM fixture_sessions'); }
    elseif ($kind === 'foreign') { kp_sql('UPDATE fixture_sessions SET session_user_id=2'); }
    elseif ($kind === 'logout') { kp_sql('UPDATE fixture_sessions SET session_logged_in=0'); }
    elseif ($kind === 'case') { kp_sql('UPDATE fixture_sessions SET session_id=UPPER(session_id)'); }
    elseif ($kind === 'replacement') { kp_sql("UPDATE fixture_sessions SET session_id='".str_repeat('d',32)."'"); }
    else { $GLOBALS['userdata']['session_id'] = str_repeat('e',32); $_POST['sid']=$GLOBALS['userdata']['session_id']; }
}
function pc_prepare($action)
{
    global $pn_id,$pn_nonce,$kp_hook;
    pn_prepare('english');
    if ($action === 'retry') {
        $kp_hook = function($phase,$sql) { if ($phase==='before' && strpos($sql,'INSERT INTO fixture_privmsgs_text ')===0) { return false; } };
        $failed=false;
        try { phpbb_pm_write_message(str_repeat('c',32),0,'',pn_data(),0); }
        catch (Exception $error) { $failed=true; }
        finally { $kp_hook=null; }
        kp_check($failed && count(kp_rows('SELECT privmsgs_id FROM fixture_privmsgs WHERE privmsgs_write_payload IS NOT NULL'))===1,'Actual interrupted durable write');
    }
    pc_login(in_array($action,array('read','visit','save','delete','attachments','resume'),true)?3:4);
    if ($action==='read' || $action==='resume') { $_SERVER['REQUEST_METHOD']='GET'; $_POST=array(); }
}
function pc_action($action)
{
    global $pn_id,$pn_nonce;
    if ($action==='send') { return phpbb_pm_write_message(str_repeat('c',32),0,'',pn_data(),0); }
    if ($action==='edit') { $data=pn_data();$data['subject']='Changed Grüße';return phpbb_pm_write_message(str_repeat('c',32),$pn_id,$pn_nonce,$data,0); }
    if ($action==='retry') { return phpbb_pm_retry_write(str_repeat('c',32),0); }
    if ($action==='read') { return phpbb_pm_read_message($pn_id,'inbox',0); }
    if ($action==='visit') { return phpbb_pm_visit_mailbox(100); }
    if ($action==='save') { return phpbb_pm_save_messages(array($pn_id),3,'inbox',0); }
    if ($action==='delete') { return phpbb_pm_delete_messages(array($pn_id),3,'inbox'); }
    if ($action==='attachments') { return phpbb_pm_delete_attachments(array($pn_id),3,'inbox'); }
    if ($action==='resume') { return phpbb_pm_resume_reads(0); }
    return phpbb_pm_claim_notification($pn_nonce);
}
function pc_expect_denied($action)
{
    $denied=false;$failure='operation accepted';
    try { pc_action($action); }
    catch (Exception $error) { $failure=$error->getMessage();$denied=$failure===$GLOBALS['lang']['Not_Authorised']; }
    kp_check($denied && $GLOBALS['pn_open']===0,'Current session denies '.$action.' and releases owner: '.$failure);
}
function pc_suite()
{
    global $kp_source,$pn_open,$pn_claim_pending,$pn_after_claim,$pn_queries,$kp_hook,$db,$userdata;
    require_once $kp_source.'attach_mod/includes/functions_delete.php';
    require $kp_source.'includes/functions_pm_controller.php';
    $pn_open=0;$pn_claim_pending=false;$pn_after_claim=null;$pn_queries=array();
    $kinds=array('missing','foreign','logout','case','replacement','cached-change');$cases=$boundaries=0;
    foreach(array('send','edit','retry','read','visit','save','delete','attachments','resume','claim') as $action) {
        pc_prepare($action);$pn_queries=array();pc_action($action);$reference=$pn_queries;
        kp_check($pn_open===0,'Ordinary '.$action.' succeeds and releases owner');$cases++;
        foreach($kinds as $kind) {
            pc_prepare($action);pc_revoke($kind);$before=kp_snapshot();pc_expect_denied($action);
            kp_check(kp_snapshot()===$before,'Revoked entry changes no persistent data: '.$action.'/'.$kind);$cases++;
        }
        // Exercise every write occurrence and every private-data SELECT in the
        // real successful trace. Rotate revocation variants rather than repeat
        // an expensive Cartesian product of equivalent SQL predicates.
        $positions=array();$position=0;
        foreach($reference as $sql) {
            $position++;
            if(preg_match('/^(INSERT INTO|UPDATE|DELETE FROM) /',$sql)
                || (strpos($sql,'SELECT ')===0 && strpos($sql,'fixture_privmsgs')!==false && strpos($sql,'SELECT 1 AS allowed WHERE')!==0)) { $positions[]=$position; }
        }
        foreach($positions as $position) {
            pc_prepare($action);$seen=0;$revoked=null;$kind=$kinds[$boundaries%5];
            $kp_hook=function($phase,$sql,$owner)use($position,$kind,&$seen,&$revoked) {
                if($phase==='before' && ++$seen===$position) { pc_revoke($kind);$revoked=kp_snapshot(); }
            };
            try { pc_expect_denied($action); } finally { $kp_hook=null; }
            kp_check($revoked!==null && kp_snapshot()===$revoked,'No further writes/private result after revocation at '.$action.'/'.$position);$boundaries++;
            // A fresh login may resume durable stages, retaining completed rows
            // and avoiding a second message/copy. Existing recovery suites cover
            // acknowledgement loss at all stages independently.
            pc_login(in_array($action,array('read','visit','save','delete','attachments','resume'),true)?3:4,str_repeat('f',32));
            if(in_array($action,array('send','edit','retry','read','visit','save','claim'),true)) {
                pc_action($action);kp_check($pn_open===0,'Fresh login resumes '.$action);
                $messages=kp_rows('SELECT COUNT(*) n FROM fixture_privmsgs');
                kp_check((int)$messages[0]['n']===(in_array($action,array('send','retry','read'),true)?2:1),'Resume never duplicates messages/copies: '.$action);
            }
        }
    }
    // The caller's old repeatable-read snapshot cannot grant the worker access.
    foreach(array('send','read','delete')as$action) {
        pc_prepare($action);$db->sql_query('START TRANSACTION');$r=$db->sql_query('SELECT * FROM fixture_sessions');$db->sql_freeresult($r);
        try {pc_revoke('missing');$before=kp_snapshot();pc_expect_denied($action);kp_check(kp_snapshot()===$before,'Dedicated session check ignores caller snapshot');}
        finally {$db->sql_query('ROLLBACK');}$cases++;
    }
    echo 'PM current-session native: '.$cases.' entry/snapshot cases, '.$boundaries." protected SQL boundaries PASS; real send/edit/retry/read/save/delete/attachments/claim, exact session identity and resumable journals.\n";
}
$pc_file=__DIR__.'/check-pm-notifications-native.php';$pc_source=file_get_contents($pc_file);
if(substr_count($pc_source,'pn_suite();return;')!==1){throw new RuntimeException('Canonical PM fixture entry');}
$pc_source=str_replace('pn_suite();return;','pc_suite();return;',$pc_source);
$pc_source=str_replace('CONFIG_TABLE,SESSIONS_KEYS_TABLE,BANLIST_TABLE,PM_WRITE_RECEIPTS_TABLE,ATTACHMENTS_TABLE,ATTACHMENTS_DESC_TABLE','CONFIG_TABLE,SESSIONS_KEYS_TABLE,BANLIST_TABLE,PM_WRITE_RECEIPTS_TABLE,ATTACHMENTS_TABLE,ATTACHMENTS_DESC_TABLE,PM_DELETE_JOBS_TABLE,PM_DELETE_ITEMS_TABLE',$pc_source);
$pc_eval='';foreach(token_get_all($pc_source)as$t){$pc_eval.=is_array($t)?($t[0]===T_DIR?var_export(__DIR__,true):$t[1]):$t;}eval(substr($pc_eval,5));
