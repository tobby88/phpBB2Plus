<?php
require __DIR__ . '/statistics-period-fixture.php';
class StatisticsPeriodSyntheticDatabase {
    var $rows; var $bounds; var $queries = 0; var $fail = false;
    function sql_query($sql, $transaction = false) {
        $this->queries++;
        if (strpos($sql,'SELECT user_id, username FROM ') === 0) {
            $r = new stdClass(); $r->rows = array(array('user_id'=>7,'username'=>'Member 7'),array('user_id'=>8,'username'=>'Member 8')); $r->index = 0; return $r;
        }
        sp_check(preg_match('/p\.post_time >= \'([0-9]+)\'.*p\.post_time < \'([0-9]+)\'/s', $sql, $m) === 1, 'Actual module SQL uses a bounded half-open interval');
        $this->bounds = array((int)$m[1], (int)$m[2]);
        return $this->fail ? false : true;
    }
    function sql_numrows($r) { return count($this->sql_fetchrowset($r)); }
    function sql_fetchrow($r) { return isset($r->rows[$r->index]) ? $r->rows[$r->index++] : false; }
    function sql_freeresult($r) { return true; }
    function sql_fetchrowset($r) {
        $counts = array(); foreach ($this->rows as $row) {
            if (!in_array($row[0], array(7,8), true) || $row[1] < $this->bounds[0] || $row[1] >= $this->bounds[1]) { continue; }
            if (!isset($counts[$row[0]])) { $counts[$row[0]] = 0; } $counts[$row[0]]++;
        }
        arsort($counts); $out = array(); foreach ($counts as $id=>$count) { $out[] = array('user_id'=>$id,'username'=>'Member ' . $id,'user_posts'=>$count); } return $out;
    }
}
date_default_timezone_set('UTC'); $db = new StatisticsPeriodSyntheticDatabase();
foreach (sp_vectors() as $vector) {
    list($server,$timezone,$now,$period) = $vector; date_default_timezone_set($server); $GLOBALS['sp_clock'] = $now;
    list($start,$end) = sp_expected($period,$now,$timezone); $db->rows = sp_rows($start,$end);
    sp_verify(sp_module($period,$now,$timezone),$period,$now,$timezone);
    sp_check($db->bounds === array($start,$end), 'Actual module query matches expected calendar');
}
foreach (array('week','month') as $period) {
    $db->rows = array(); $empty = sp_module($period,$now,$timezone); sp_check(!$empty->blocks, 'Empty period remains a valid empty graph'); $sp_cases++;
    $db->fail = true; $rejected = false; try { sp_module($period,$now,$timezone); } catch (StatisticsPeriodExit $e) { $rejected = true; }
    sp_check($rejected, 'Query error is explicit rather than a fabricated empty ranking'); $db->fail = false; $sp_cases++;
}
echo 'Statistics periods: ', $sp_cases, ' cases, ', $sp_checks, " assertions PASS; complete modules, Sunday/Monday, year/leap/month boundaries, server DST, board offsets, exact midnight, empty and failed reads.\n";
restore_error_handler();
