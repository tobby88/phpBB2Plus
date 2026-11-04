<?php
// Widen counters without rewriting history or changing banner identities.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(2); }
require_once __DIR__ . '/banner_id_migration.php';

function plus_banner_counters_plan($db, $prefix, $required = false, $canonical = false)
{
    $table = plus_banner_ids_table($prefix); $quoted = plus_storage_identifier($table);
    $meta = plus_storage_metadata($db, $table);
    if (!$meta) { if ($required) { throw new RuntimeException('Banner table is missing'); } return array(); }
    if ($canonical && ($meta['ENGINE'] !== 'InnoDB' || strtolower($meta['ROW_FORMAT']) !== 'dynamic' || $meta['TABLE_COLLATION'] !== 'utf8mb4_unicode_ci')) {
        throw new RuntimeException('Complete canonical storage migration before widening banner counters');
    }
    $columns = array(); foreach (plus_storage_rows($db, 'SHOW FULL COLUMNS FROM ' . $quoted) as $column) { $columns[$column['Field']] = $column; }
    foreach (array('banner_id','banner_click','banner_view') as $field) {
        if (!isset($columns[$field]) || !preg_match('/^(?:tinyint|smallint|mediumint|int|bigint)(?:\([0-9]+\))?(?: unsigned)?$/iD', $columns[$field]['Type'])) {
            throw new RuntimeException('Custom non-integer banner counters or identities require manual review; no wider definitions are narrowed');
        }
        if ($field !== 'banner_id' && $columns[$field]['Extra'] !== '') { throw new RuntimeException('Custom generated banner counter requires manual review'); }
    }
    if (plus_storage_rows($db, 'SELECT banner_id FROM ' . $quoted . ' GROUP BY banner_id HAVING COUNT(*)>1 LIMIT 1')
        || plus_storage_rows($db, 'SELECT banner_id FROM ' . $quoted . ' WHERE banner_click IS NULL OR banner_click<0 OR banner_view IS NULL OR banner_view<0 LIMIT 1')) {
        throw new RuntimeException('Ambiguous banner identities or negative/missing counters require manual review; no rows or counts are replaced');
    }
    $parts = array(); $mode = plus_storage_rows($db, 'SELECT @@SESSION.sql_mode AS mode');
    foreach (array('banner_click','banner_view') as $field) {
        $column = $columns[$field];
        if (preg_match('/^bigint(?:\([0-9]+\))? unsigned$/Di', $column['Type']) && $column['Null'] === 'NO' && (string)$column['Default'] === '0') { continue; }
        $comment = in_array('NO_BACKSLASH_ESCAPES', explode(',', $mode[0]['mode']), true)
            ? str_replace("'", "''", $column['Comment']) : mysqli_real_escape_string($db, $column['Comment']);
        $parts[] = 'MODIFY ' . plus_storage_identifier($field) . " BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '" . $comment . "'";
    }
    if (!$parts) { return array(); }
    if ($meta['AUTO_INCREMENT'] !== null) {
        if (!preg_match('/^[1-9][0-9]*$/D', (string)$meta['AUTO_INCREMENT'])) { throw new RuntimeException('Invalid banner identity allocation floor'); }
        $parts[] = 'AUTO_INCREMENT=' . $meta['AUTO_INCREMENT'];
    }
    return array($table => array('sql'=>'ALTER TABLE ' . $quoted . ' ' . implode(', ', $parts)));
}

function plus_banner_counters_apply($db, $prefix, $backup_confirmed, $maintenance_confirmed, $wait_seconds = 30)
{
    if (!$backup_confirmed || !$maintenance_confirmed) { throw new RuntimeException('Banner counter migration requires a verified backup and stopped web/cron writers'); }
    if (!is_int($wait_seconds) || $wait_seconds < 1 || $wait_seconds > 30) { throw new RuntimeException('Invalid banner counter DDL wait limit'); }
    $table = plus_banner_ids_table($prefix);
    $identity = plus_storage_rows($db, 'SELECT DATABASE() AS db,CONNECTION_ID() AS id,@@SESSION.autocommit AS automatic,@@SESSION.sql_mode AS mode,@@SESSION.lock_wait_timeout AS wait_timeout'); $identity = $identity[0];
    if (!$identity['db'] || (string)$identity['automatic'] !== '1' || !preg_match('/^[A-Z0-9_,]*$/D', $identity['mode']) || !preg_match('/^[0-9]{1,10}$/D', (string)$identity['wait_timeout'])) {
        throw new RuntimeException('Banner counter migration requires an idle autocommit connection');
    }
    plus_storage_query($db, 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $owned = $held = false; $lock = 'plus_innodb_' . sha1($identity['db']);
    try {
        plus_storage_query($db, 'START TRANSACTION READ ONLY'); $owned = true;
        $owner = plus_storage_rows($db, "SELECT IS_USED_LOCK('" . $lock . "') AS owner");
        if ((string)$owner[0]['owner'] === (string)$identity['id']) { throw new RuntimeException('Caller already owns the migration lock'); }
        $rows = plus_storage_rows($db, "SELECT GET_LOCK('" . $lock . "',0) AS held");
        if (count($rows) !== 1 || (string)$rows[0]['held'] !== '1') { throw new RuntimeException('Another schema migration is running'); } $held = true;
        $plan = plus_banner_counters_plan($db,$prefix,true,true);
        $meta = plus_storage_metadata($db,$table); $signature = plus_storage_signature($db,$table); $digest = plus_banner_ids_digest($db,$table);
        plus_storage_query($db, 'COMMIT'); $owned = false;
        plus_storage_query($db, "SET SESSION sql_mode=CONCAT_WS(',',@@SESSION.sql_mode,'STRICT_ALL_TABLES','NO_ENGINE_SUBSTITUTION')");
        plus_storage_query($db, 'SET SESSION lock_wait_timeout=' . $wait_seconds);
        foreach ($plan as $item) {
            // DDL cannot be rolled back: keep writers stopped after any error.
            plus_storage_query($db,$item['sql']);
            if (plus_storage_rows($db,'SHOW WARNINGS')) { throw new RuntimeException('Banner counter rebuild reported warnings; inspect before retrying'); }
        }
        $after = plus_storage_signature($db,$table);
        if ($plan) {
            foreach ($signature[0] as $i=>$column) {
                if (!in_array($column['Field'],array('banner_click','banner_view'),true)) { continue; }
                $signature[0][$i]['Type'] = $after[0][$i]['Type']; $signature[0][$i]['Null'] = 'NO'; $signature[0][$i]['Default'] = $after[0][$i]['Default'];
            }
            foreach ($signature[1] as &$index) {
                // SHOW INDEX reflects the intended nullable -> NOT NULL change;
                // every actual index definition and unrelated column stays pinned.
                if (in_array($index['Column_name'],array('banner_click','banner_view'),true)) { $index['Null'] = ''; }
            } unset($index);
        }
        $after_meta = plus_storage_metadata($db,$table); $floor=$meta['AUTO_INCREMENT']; $after_floor=$after_meta['AUTO_INCREMENT']; unset($meta['AUTO_INCREMENT'],$after_meta['AUTO_INCREMENT']);
        if ($signature !== $after) { throw new RuntimeException('Banner counter column/index verification failed; keep writers stopped'); }
        if ($meta !== $after_meta) { throw new RuntimeException('Banner counter table options changed unexpectedly; keep writers stopped'); }
        if ($floor !== null && !plus_storage_counter_at_least($after_floor,$floor)) { throw new RuntimeException('Banner allocation floor changed unexpectedly; keep writers stopped'); }
        if ($digest !== plus_banner_ids_digest($db,$table)) { throw new RuntimeException('Banner payload verification failed; keep writers stopped and inspect the backup'); }
        if (plus_banner_counters_plan($db,$prefix,true,true)) { throw new RuntimeException('Banner counter migration is incomplete; keep writers stopped'); }
        return count($plan);
    } finally {
        try { if ($owned) { plus_storage_query($db,'ROLLBACK'); } }
        finally {
            try { plus_storage_query($db,"SET SESSION sql_mode='".$identity['mode']."'"); plus_storage_query($db,'SET SESSION lock_wait_timeout='.$identity['wait_timeout']); }
            finally { if ($held) { plus_storage_query($db,"DO RELEASE_LOCK('".$lock."')"); } }
        }
    }
}
