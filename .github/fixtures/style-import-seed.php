<?php
// Disposable fixture setup only. Keep import transactions and fault boundaries
// unchanged; commit the original starting rows together before invoking them.
function sim_seed($callback)
{
    $peer = $GLOBALS['peer'];
    $state = phpbb_acl_rows($peer, 'SELECT DATABASE() AS fixture_schema,@@SESSION.autocommit AS fixture_autocommit');
    sd_check(count($state) === 1 && isset($state[0]['fixture_schema'], $state[0]['fixture_autocommit'])
        && $state[0]['fixture_schema'] === $GLOBALS['fixture']
        && preg_match('/^codex_style_(?:import|import_recovery|recovery_controller)_[a-f0-9]{16}$/D', $GLOBALS['fixture'])
        && (int)$state[0]['fixture_autocommit'] === 1, 'Owned autocommit style fixture required');
    // This fails inside an existing transaction on MariaDB and MySQL. Starting
    // a transaction unconditionally would silently commit somebody else's work.
    sd_check($peer->sql_query('SET TRANSACTION READ WRITE'), 'Style seed must not commit an outer transaction');
    sd_sql('START TRANSACTION');
    $committed = false;
    try {
        $result = call_user_func($callback);
        sd_sql('COMMIT');
        $committed = true;
        return $result;
    } finally {
        if (!$committed) { sd_sql('ROLLBACK'); }
    }
}
