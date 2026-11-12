<?php
// Disposable native fixture setup only. Application mutation owners and their
// transactions are deliberately unaffected by this batching.
function card_report_seed($callback)
{
    $state = lp_rows('SELECT DATABASE() AS fixture_schema, @@SESSION.in_transaction AS active_transaction');
    ats_check(count($state) === 1 && preg_match('/^codex_login_publication_[a-f0-9]{16}$/D', $GLOBALS['fixture'])
        && $state[0]['fixture_schema'] === $GLOBALS['fixture'] && $GLOBALS['lp_open'] === 0, 'Owned idle card/report fixture peer required');
    // START TRANSACTION would implicitly commit an existing transaction. Raw
    // seed adapters are explicit for callers which already own setup work.
    ats_check((int)$state[0]['active_transaction'] === 0, 'Card/report seed cannot adopt or commit an outer peer transaction');
    lp_query('START TRANSACTION');
    $committed = false;
    try {
        $result = call_user_func($callback);
        lp_query('COMMIT');
        $committed = true;
        return $result;
    } finally {
        if (!$committed) { lp_query('ROLLBACK'); }
    }
}
