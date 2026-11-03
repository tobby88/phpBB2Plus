<?php
if (PHP_SAPI !== 'cli' || getenv('PHPBB_STATS_PERIOD_NATIVE') !== '1') { echo "Statistics period native checks require an explicitly enabled disposable database.\n"; return; }
require __DIR__ . '/statistics-period-fixture.php';
require $sp_root . 'db/mysqli.php';
class StatisticsPeriodNativeDatabase extends sql_db {
    var $setup = true;
    function sql_query($sql = '', $transaction = false) {
        if (!$this->setup && !preg_match('/^SELECT\b/i', ltrim($sql))) { throw new RuntimeException('Statistics must be read-only'); }
        return parent::sql_query($sql, $transaction);
    }
}
function sp_sql($sql) { $r = $GLOBALS['db']->sql_query($sql); sp_check($r !== false, 'Own native statistics query'); return $r; }
function sp_snapshot() {
    $out = array(); foreach (array('users','posts') as $table) {
        $r = sp_sql('SELECT * FROM fixture_' . $table); $out[$table] = $GLOBALS['db']->sql_fetchrowset($r); $GLOBALS['db']->sql_freeresult($r);
    } return serialize($out);
}
$db = $control = null; $created = false; $schema = 'codex_stats_period_' . bin2hex(phpbb_random_bytes(8));
try {
    $port = getenv('PHPBB_STATS_PERIOD_PORT') ?: '3306'; $password = getenv('PHPBB_STATS_PERIOD_PASSWORD') ?: '';
    sp_check(preg_match('/^[0-9]{1,5}$/D', $port) && (int)$port > 0 && (int)$port <= 65535, 'Explicit loopback port');
    $host = '127.0.0.1:' . $port; $control = new sql_db($host, 'root', $password, '', false);
    sp_check((bool)$control->db_connect_id, 'Own server connection');
    sp_check(preg_match('/^codex_stats_period_[a-f0-9]{16}$/D', $schema) && $control->sql_query('CREATE DATABASE ' . $schema . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'), 'Exclusive owned schema'); $created = true;
    $db = new StatisticsPeriodNativeDatabase($host, 'root', $password, $schema, false); sp_check((bool)$db->db_connect_id, 'Own schema connection');
    $canonical = file_get_contents($sp_root . 'install/schemas/mysql_schema.sql');
    foreach (array('users','posts') as $table) {
        sp_check(preg_match('/CREATE TABLE phpbb_' . $table . ' \(.*?\) ENGINE=InnoDB[^;]*;/s', $canonical, $m) === 1, 'Canonical InnoDB table');
        sp_sql(str_replace('phpbb_' . $table, 'fixture_' . $table, $m[0]));
    }
    sp_sql("INSERT INTO fixture_users(user_id,username,user_password) VALUES(7,'Member 7',''),(8,'Member 8',''),(-1,'Anonymous','')");
    foreach (sp_vectors() as $vector) {
        list($server,$timezone,$now,$period) = $vector; date_default_timezone_set($server); $GLOBALS['sp_clock'] = $now;
        list($start,$end) = sp_expected($period,$now,$timezone);
        $db->setup = true; sp_sql('DELETE FROM fixture_posts'); $values = array(); $id = 0;
        foreach (sp_rows($start,$end) as $row) { $values[] = '(' . (++$id) . ',' . $row[0] . ',' . $row[1] . ",'7f000001')"; }
        sp_sql('INSERT INTO fixture_posts(post_id,poster_id,post_time,poster_ip) VALUES' . implode(',', $values)); $db->setup = false;
        $before = sp_snapshot(); sp_verify(sp_module($period,$now,$timezone),$period,$now,$timezone);
        sp_check(sp_snapshot() === $before, 'Entire actual module/driver/cache wrapper leaves canonical data unchanged');
    }
    echo 'Statistics periods native: ', $sp_cases, ' cases, ', $sp_checks, " assertions PASS; real module SQL, canonical InnoDB users/posts, boundary inclusion, Sunday/year/leap/DST/board offsets, unchanged data.\n";
} finally {
    if ($db) { $db->sql_close(); }
    if ($created) { sp_check(preg_match('/^codex_stats_period_[a-f0-9]{16}$/D', $schema) && $control->sql_query('DROP DATABASE ' . $schema), 'Drop exact owned schema'); }
    if ($control) { $control->sql_close(); } restore_error_handler();
}
