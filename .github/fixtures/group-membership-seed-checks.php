<?php
require_once __DIR__.'/group-membership-seed-reference.php';
class GmnSeedFailurePeer
{
    var $inner; var $needle; var $hit = false;
    function __construct($inner,$needle){$this->inner=$inner;$this->needle=$needle;}
    function __call($name,$args){return call_user_func_array(array($this->inner,$name),$args);}
    function sql_query($sql,$transaction=false){
        if(!$this->hit && strpos($sql,$this->needle)===0){$this->hit=true;return false;}
        return $this->inner->sql_query($sql,$transaction);
    }
}
function gmn_seed_state()
{
    return array('rows'=>gmn_snap(),'next_id'=>gmn_rows("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='fixture_groups'")[0]['AUTO_INCREMENT']);
}
function gmn_seed_idle(){return (int)gmn_rows('SELECT @@SESSION.in_transaction AS active_transaction')[0]['active_transaction']===0;}
function gmn_seed_checks()
{
    global $peer,$fixture,$db;
    $checks=0;
    foreach(array('root','leader','pending','member','outsider')as$actor){
        gmn_seed_reference($actor);$reference=gmn_seed_state();
        gmn_reset($actor);
        ats_check(gmn_seed_idle()&&gmn_seed_state()===$reference,'Exact original group seed rows and next AUTO_INCREMENT: '.$actor);$checks++;
        gmn_reset($actor==='root'?'leader':'root');$before=gmn_seed_state();
        foreach(array('UPDATE fixture_sessions','COMMIT')as$needle){
            $original=$peer;$failure=new GmnSeedFailurePeer($peer,$needle);$peer=$failure;$denied=false;
            try{gmn_reset($actor);}catch(RuntimeException $e){$denied=strpos($e->getMessage(),'Native fixture SQL:')===0;}
            finally{$peer=$original;}
            ats_check($failure->hit&&$denied&&gmn_seed_idle()&&gmn_seed_state()===$before,'SQL/commit failure rolls back every group seed table: '.$actor.'/'.$needle);$checks++;
        }
        $denied=false;
        try{gmn_seed(function()use($actor){gmn_reset_rows($actor);throw new RuntimeException('Synthetic group seed callback failure');});}
        catch(RuntimeException $e){$denied=$e->getMessage()==='Synthetic group seed callback failure';}
        ats_check($denied&&gmn_seed_idle()&&gmn_seed_state()===$before,'Late callback failure rolls back group seed: '.$actor);$checks++;
        gmn_sql('START TRANSACTION');
        try{
            gmn_sql("UPDATE fixture_users SET username='Pending seed' WHERE user_id=1");$pending=gmn_seed_state();$denied=false;
            try{gmn_reset($actor);}catch(RuntimeException $e){$denied=strpos($e->getMessage(),'cannot adopt or commit an outer peer transaction')!==false;}
            $r=$db->sql_query('SELECT username FROM fixture_users WHERE user_id=1');$outside=$db->sql_fetchrow($r);$db->sql_freeresult($r);
            ats_check($denied&&!gmn_seed_idle()&&gmn_seed_state()===$pending&&$outside['username']!=='Pending seed','Outer group seed transaction stays private, open and unchanged');$checks++;
        }finally{gmn_sql('ROLLBACK');}
        ats_check(gmn_seed_idle()&&gmn_seed_state()===$before,'Original outer rollback remains effective');
    }
    $before=gmn_seed_state();$lock=new attach_mutation_lock($db);ats_check($lock->acquired,'Real independent group fixture owner');$denied=false;
    try{gmn_reset();}catch(RuntimeException $e){$denied=strpos($e->getMessage(),'Owned idle group fixture')!==false;}
    finally{$lock->release();}
    ats_check($denied&&$GLOBALS['gmn_open']===0&&gmn_seed_state()===$before,'Setup never overlaps an active group owner');$checks++;
    $original=$fixture;$fixture='codex_group_membership_'.str_repeat('0',16);$denied=false;
    try{gmn_reset();}catch(RuntimeException $e){$denied=strpos($e->getMessage(),'Owned idle group fixture')!==false;}
    finally{$fixture=$original;}
    ats_check($denied&&gmn_seed_idle()&&gmn_seed_state()===$before,'Setup rejects an unexpected database identity');$checks++;
    $old=gmn_rows('SELECT @@SESSION.innodb_lock_wait_timeout AS seconds')[0]['seconds'];gmn_sql('SET SESSION innodb_lock_wait_timeout=7');
    try{
        list($r,$error)=native_fixture_conflict_query($peer,'SELECT 1 AS probe');ats_check($r&&(int)gmn_rows('SELECT @@SESSION.innodb_lock_wait_timeout AS seconds')[0]['seconds']===7,'Successful conflict helper restores actual peer wait');$peer->sql_freeresult($r);$checks++;
        list($r,$error)=native_fixture_conflict_query($peer,'SELECT * FROM fixture_seed_absent');ats_check(!$r&&(int)$error['code']===1146&&(int)gmn_rows('SELECT @@SESSION.innodb_lock_wait_timeout AS seconds')[0]['seconds']===7,'SQL failure captured before restoring actual peer wait');$checks++;
        ats_check($db->sql_query('START TRANSACTION'),'Owned contention transaction');$r=$db->sql_query('SELECT group_id FROM fixture_groups WHERE group_id=10 FOR UPDATE');ats_check($r,'Actual group row lock');$db->sql_freeresult($r);
        try{
            list($r,$error)=native_fixture_conflict_query($peer,"UPDATE fixture_groups SET group_name='competing' WHERE group_id=10");
            ats_check(!$r&&(int)$error['code']===1205&&(int)gmn_rows('SELECT @@SESSION.innodb_lock_wait_timeout AS seconds')[0]['seconds']===7,'Real immediate conflict and actual wait restoration');$checks++;
        }finally{$db->sql_query('ROLLBACK');}
    }finally{gmn_sql('SET SESSION innodb_lock_wait_timeout='.(int)$old);}
    ats_check($checks===30&&gmn_seed_state()===$before&&gmn_seed_idle()&&$GLOBALS['gmn_open']===0,'All extra setup checks leave no rows, locks or transactions behind');
    echo "Native group fixture setup: 30 checks PASS; five exact original seeds and next IDs, SQL/commit/callback rollback, isolated outer transactions, idle owners and scoped real conflicts.\n";
}
