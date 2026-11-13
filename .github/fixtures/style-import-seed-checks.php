<?php
function sim_seed_snapshot()
{
    $out = array();
    foreach (array(THEMES_TABLE, THEMES_NAME_TABLE, USERS_TABLE, SESSIONS_TABLE, JR_ADMIN_TABLE, CONFIG_TABLE) as $table) {
        $rows = phpbb_acl_rows($GLOBALS['peer'], 'SELECT * FROM ' . $table);
        usort($rows, function($a, $b) { return strcmp(serialize($a), serialize($b)); });
        $out[$table] = $rows;
    }
    return $out;
}
class StyleSeedCommitPeer
{
    var $inner; var $ack; var $armed = false; var $hit = false;
    function __construct($inner, $ack) { $this->inner = $inner; $this->ack = $ack; }
    function __call($name, $args) { return call_user_func_array(array($this->inner, $name), $args); }
    function sql_query($sql, $transaction = false) {
        if ($sql === 'COMMIT' && $this->armed) {
            $this->armed = false; $this->hit = true;
            if ($this->ack) { sd_check($this->inner->sql_query($sql, $transaction), 'Actual seed commit before lost acknowledgement'); }
            return false;
        }
        return $this->inner->sql_query($sql, $transaction);
    }
}
function sim_seed_checks()
{
    $checks = 0;
    foreach (array('root', 'delegated') as $actor) {
        // Keep the original row adapter as the unbatched reference.
        sim_reset_rows($actor); $reference = sim_seed_snapshot();
        sim_reset($actor === 'root' ? 'delegated' : 'root'); sim_reset($actor);
        sd_check(sim_seed_snapshot() === $reference, 'All six original seed tables remain byte-identical: ' . $actor); $checks++;
        sim_reset($actor === 'root' ? 'delegated' : 'root'); $before = sim_seed_snapshot();
        sd_sql("CREATE TRIGGER fixture_style_seed_failure BEFORE INSERT ON fixture_config FOR EACH ROW BEGIN IF NEW.config_name='unrelated' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic style seed failure'; END IF; END");
        $denied = false;
        try { sim_reset($actor); } catch (RuntimeException $error) { $denied = strpos($error->getMessage(), 'Fixture SQL:') === 0; }
        finally { sd_sql('DROP TRIGGER fixture_style_seed_failure'); }
        sd_check($denied && $GLOBALS['peer']->sql_query('SET TRANSACTION READ WRITE') && sim_seed_snapshot() === $before,
            'Late real SQL failure rolls back all six tables: ' . $actor); $checks++;
        $denied = false;
        try { sim_seed(function()use($actor) { sim_reset_rows($actor); throw new RuntimeException('Synthetic style seed callback'); }); }
        catch (RuntimeException $error) { $denied = $error->getMessage() === 'Synthetic style seed callback'; }
        sd_check($denied && $GLOBALS['peer']->sql_query('SET TRANSACTION READ WRITE') && sim_seed_snapshot() === $before,
            'Callback failure leaves an idle peer and unchanged rows: ' . $actor); $checks++;
        foreach (array(false, true) as $ack) {
            $inner = $GLOBALS['peer']; $proxy = new StyleSeedCommitPeer($inner, $ack); $GLOBALS['peer'] = $proxy; $denied = false;
            try { sim_seed(function()use($actor, $proxy) { sim_reset_rows($actor); $proxy->armed = true; }); }
            catch (RuntimeException $error) { $denied = strpos($error->getMessage(), 'Fixture SQL:') === 0; }
            finally { $GLOBALS['peer'] = $inner; }
            sd_check($denied && $proxy->hit && $inner->sql_query('SET TRANSACTION READ WRITE')
                && sim_seed_snapshot() === ($ack ? $reference : $before), 'Commit failure preserves exact before/after rows: ' . $actor); $checks++;
            sim_reset($actor === 'root' ? 'delegated' : 'root'); $before = sim_seed_snapshot();
        }
        sd_sql('START TRANSACTION'); sd_sql("UPDATE fixture_config SET config_value='pending' WHERE config_name='unrelated'"); $denied = false;
        try { sim_reset($actor); } catch (RuntimeException $error) { $denied = $error->getMessage() === 'Style seed must not commit an outer transaction'; }
        $inside = phpbb_acl_rows($GLOBALS['peer'], "SELECT config_value FROM fixture_config WHERE config_name='unrelated'");
        $outside = phpbb_acl_rows($GLOBALS['db'], "SELECT config_value FROM fixture_config WHERE config_name='unrelated'");
        sd_check($denied && $inside[0]['config_value'] === 'pending' && $outside[0]['config_value'] === 'keep', 'Outer transaction remains pending and uncommitted: ' . $actor); $checks++;
        sd_sql('ROLLBACK'); sd_check(sim_seed_snapshot() === $before, 'Outer transaction remains rollbackable: ' . $actor); $checks++;
    }
    sim_reset();
    echo 'Style import fixture seeds: ' . $checks . " checks PASS; six original tables, native SQL/callback/commit failures and untouched outer transactions.\n";
}
