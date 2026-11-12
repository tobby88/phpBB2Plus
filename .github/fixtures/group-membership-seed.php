<?php
// Disposable native fixture setup only; never change application transactions.
function gmn_seed($callback)
{
    $state = gmn_rows('SELECT DATABASE() AS fixture_schema,@@SESSION.in_transaction AS active_transaction');
    ats_check(count($state) === 1 && preg_match('/^codex_group_membership_[a-f0-9]{16}$/D', $GLOBALS['fixture'])
        && $state[0]['fixture_schema'] === $GLOBALS['fixture'] && $GLOBALS['gmn_open'] === 0,
        'Owned idle group fixture peer required');
    ats_check((int)$state[0]['active_transaction'] === 0, 'Group seed cannot adopt or commit an outer peer transaction');
    gmn_sql('START TRANSACTION'); $committed = false;
    try {
        $result = call_user_func($callback);
        gmn_sql('COMMIT'); $committed = true;
        return $result;
    } finally {
        if (!$committed) { gmn_sql('ROLLBACK'); }
    }
}
