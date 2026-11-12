<?php
class CardReportSeedFailurePeer
{
    var $inner; var $needle; var $hit = false;
    function __construct($inner, $needle) { $this->inner = $inner; $this->needle = $needle; }
    function __call($method, $args) { return call_user_func_array(array($this->inner, $method), $args); }
    function sql_query($sql, $transaction = false)
    {
        if (!$this->hit && strpos($sql, $this->needle) === 0) { $this->hit = true; return false; }
        return $this->inner->sql_query($sql, $transaction);
    }
}
function card_report_seed_snapshot($tables)
{
    $out = array();
    foreach ($tables as $table) {
        $rows = card_rows('SELECT * FROM fixture_' . $table);
        usort($rows, function($a, $b) { return strcmp(serialize($a), serialize($b)); });
        $out[$table] = $rows;
    }
    return $out;
}
function card_report_seed_idle()
{
    return (int)lp_rows('SELECT @@SESSION.in_transaction AS active_transaction')[0]['active_transaction'] === 0;
}
function card_report_seed_checks($phase, $actors, $tables, $raw, $batched, $needle)
{
    $checks = 0;
    foreach ($actors as $actor) {
        // The unchanged row adapter preserves the original unbatched seed;
        // compare every value, not merely counts or selected output fields.
        call_user_func($raw, $actor); $reference = card_report_seed_snapshot($tables);
        call_user_func($batched, $actor);
        ats_check(card_report_seed_idle() && card_report_seed_snapshot($tables) === $reference, 'Batched seed retains exact original rows: ' . $phase . '/' . $actor); $checks++;
        call_user_func($batched, $actor === 'admin' ? 'moderator' : 'admin'); $before = card_report_seed_snapshot($tables);
        $original = $GLOBALS['peer']; $failure = new CardReportSeedFailurePeer($original, $needle); $GLOBALS['peer'] = $failure; $denied = false;
        try { call_user_func($batched, $actor); }
        catch (RuntimeException $e) { $denied = strpos($e->getMessage(), 'Owned disposable login SQL:') === 0; }
        finally { $GLOBALS['peer'] = $original; }
        ats_check($failure->hit && $denied && card_report_seed_idle() && card_report_seed_snapshot($tables) === $before, 'Late seed SQL failure rolls back every table: ' . $phase . '/' . $actor); $checks++;
        $denied = false;
        try { card_report_seed(function()use($raw,$actor){call_user_func($raw,$actor);throw new RuntimeException('Synthetic seed callback failure');}); }
        catch (RuntimeException $e) { $denied = $e->getMessage() === 'Synthetic seed callback failure'; }
        ats_check($denied && card_report_seed_idle() && card_report_seed_snapshot($tables) === $before, 'Late callback exception closes and rolls back setup: ' . $phase . '/' . $actor); $checks++;
        lp_query('START TRANSACTION');
        try {
            lp_query("UPDATE fixture_users SET username='Seed pending' WHERE user_id=1");
            $pending = card_report_seed_snapshot($tables); $denied = false;
            try { call_user_func($batched, $actor); }
            catch (RuntimeException $e) { $denied = strpos($e->getMessage(), 'cannot adopt or commit an outer peer transaction') !== false; }
            ats_check($denied && !card_report_seed_idle() && card_report_seed_snapshot($tables) === $pending, 'Outer peer transaction is neither adopted, committed nor rolled back');
            $r = $GLOBALS['main']->sql_query('SELECT username FROM fixture_users WHERE user_id=1'); $other = $GLOBALS['main']->sql_fetchrow($r); $GLOBALS['main']->sql_freeresult($r);
            ats_check($other['username'] !== 'Seed pending', 'Rejected outer seed did not publish the caller change'); $checks++;
        } finally { lp_query('ROLLBACK'); }
        ats_check(card_report_seed_idle() && card_report_seed_snapshot($tables) === $before, 'Original caller rollback remains effective');
    }
    echo 'Native ', $phase, ' seed batches: ', $checks, " checks PASS; byte-exact original rows, late SQL/callback rollback, idle setup boundaries and untouched outer peer transactions.\n";
}
