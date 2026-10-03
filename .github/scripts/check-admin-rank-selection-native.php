<?php
// Actual ACP profile owner/projection, disposable native database only.
if (PHP_SAPI !== 'cli' || getenv('PHPBB_ADMIN_PROFILE_NATIVE') !== '1') { echo "Native ACP rank selection checks require an explicitly enabled disposable database.\n"; return; }
$source = file_get_contents(__DIR__ . '/check-admin-profile-native.php');
$cut = strpos($source, " foreach(array('root','junior') as \$actor)");
if ($cut === false) { throw new RuntimeException('Profile fixture setup boundary missing'); }
$head = str_replace('__DIR__', var_export(__DIR__, true), substr($source, 5, $cut - 5));
$head = str_replace('codex_admin_profile_', 'codex_admin_rank_', $head);
function ar_reset($actor = 'root', $scenario = 'edit')
{
    ap_reset($actor, $scenario);
    ap_sql("INSERT INTO fixture_ranks VALUES (1,'Special',-1,1,''),(2,'Regular',10,0,''),(65535,'Maximum ID',-1,1,'')");
}
function ar_suite()
{
    global $peer, $ap_hook, $ap_main, $db, $schema, $ats_source;
    $cases = 0;
    foreach (array('root','junior') as $actor) {
        foreach (array('edit','new') as $scenario) {
            foreach (array(null,'0','1','00001','65535') as $rank) {
                ar_reset($actor, $scenario); if ($rank !== null) { $_POST['user_rank'] = $rank; }
                ats_check(ap_run($scenario) === true, 'Accepted current special/no rank: ' . $actor . '/' . $scenario);
                $rows = ap_rows('SELECT user_rank FROM fixture_users WHERE user_id=' . $GLOBALS['ap_target']);
                ats_check(count($rows) === 1 && (int)$rows[0]['user_rank'] === (int)$rank, 'Actual projection stores validated rank');
                ats_check($db === $ap_main, 'Original connection restored'); $cases++;
            }
            foreach (array('999','2','-1','65536','1.0','1e0','1foo',' 1','+1','000001',array('1'),true,false,1.5,null) as $rank) {
                ar_reset($actor, $scenario); $_POST['user_rank'] = $rank; $before = ap_snap();
                ats_check(ap_run($scenario) === 'error' && ap_snap() === $before && !$GLOBALS['ap_cookies'], 'Invalid rank refuses whole profile/account creation');
                ats_check($GLOBALS['ap_write'] === 0, 'Refused rank creates no placeholders, quotas or other writes'); $cases++;
            }
        }
    }
    foreach (array('delete','regular') as $change) {
        foreach (array('before-lookup','after-lookup','before-commit') as $boundary) {
            ar_reset(); $_POST['user_rank'] = '1'; $seen = $blocked = false;
            $sql_change = $change === 'delete' ? 'DELETE FROM fixture_ranks WHERE rank_id=1' : 'UPDATE fixture_ranks SET rank_special=0 WHERE rank_id=1';
            $ap_hook = function($sql) use ($boundary, $sql_change, &$seen, &$blocked) {
                $match = $boundary === 'before-lookup' ? strpos($sql,'SELECT rank_id,rank_special FROM fixture_ranks WHERE rank_id=1') === 0
                    : ($boundary === 'after-lookup' ? strpos($sql,'SELECT config_name,config_value FROM fixture_config') === 0 : $sql === 'COMMIT');
                if (!$match) { return; } $GLOBALS['ap_hook'] = null; $seen = true;
                ap_sql('SET SESSION innodb_lock_wait_timeout=0');
                try {
                    if (!$GLOBALS['peer']->sql_query($sql_change)) {
                        $error = $GLOBALS['peer']->sql_error(); ats_check((int)$error['code'] === 1205, 'Only native row-lock timeout proves serialization'); $blocked = true;
                    }
                } finally { ap_sql('SET SESSION innodb_lock_wait_timeout=1'); }
            };
            $before = ap_snap(); $result = ap_run('edit'); ats_check($seen, 'Race boundary reached');
            if ($blocked) {
                ats_check($result === true && (int)ap_rows('SELECT user_rank FROM fixture_users WHERE user_id=2')[0]['user_rank'] === 1, 'Profile commits while selected special rank stays locked');
            } else {
                ats_check($result === 'error' && $GLOBALS['ap_write'] === 0, 'Earlier deletion/demotion refuses profile before writes');
                $after = ap_snap(); unset($before['ranks'], $after['ranks']); ats_check($after === $before, 'Only deliberate peer rank change persists');
            }
            $cases++;
        }
    }
    ar_reset(); $_POST['user_rank'] = '1'; ap_sql('SET SESSION lock_wait_timeout=0'); $seen = false;
    $ap_hook = function($sql) use (&$seen) {
        if (strpos($sql,'SELECT rank_id,rank_special FROM fixture_ranks ') !== 0) { return; }
        $GLOBALS['ap_hook'] = null; $seen = true;
        ats_check(!$GLOBALS['peer']->sql_query('ALTER TABLE fixture_ranks ENGINE=MyISAM'), 'Selected rank storage remains pinned');
        $error=$GLOBALS['peer']->sql_error(); ats_check((int)$error['code']===1205,'Native metadata-lock timeout');
    };
    ats_check(ap_run('edit') === true && $seen, 'Concurrent storage replacement excluded'); $cases++;
    ats_load_function(dirname(rtrim($ats_source,'/')).'/update/innodb_migration.php','plus_storage_identifier');
    ats_load_function(dirname(rtrim($ats_source,'/')).'/update/innodb_migration.php','plus_storage_tables');
    ats_check(in_array('fixture_ranks', plus_storage_tables($schema,'fixture_'), true), 'Existing updater covers canonical rank storage');
    echo 'Native ACP rank selection: ' . $cases . " current/stale/input/concurrency cases passed.\n";
}
$tail = <<<'PHP'
 ar_suite();
} finally {
 if(isset($admin_profile_scope)&&$admin_profile_scope!==null){$admin_profile_scope->release();}
 $ap_hook=null;$ap_fail=0;$ap_commit='';$ap_main->sql_close();$peer->sql_close();$control->sql_query('DROP DATABASE '.$fixture);$control->sql_close();
 foreach(array('before.png','after.png','cache/cg_users.cache')as$file){if(is_file($ap_files.'/'.$file)){unlink($ap_files.'/'.$file);}}rmdir($ap_files.'/cache');rmdir($ap_files);restore_error_handler();
}
PHP;
eval($head . $tail);
