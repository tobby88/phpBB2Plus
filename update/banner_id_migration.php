<?php
// Preserve banner IDs and payloads; never repair ambiguous references by renumbering.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(2); }
require_once __DIR__ . '/innodb_migration.php';

function plus_banner_ids_table($prefix)
{
    if (!is_string($prefix) || preg_match('/^[A-Za-z0-9_]{1,58}$/D', $prefix) !== 1) { throw new RuntimeException('Invalid banner table prefix'); }
    return $prefix . 'banner';
}
function plus_banner_ids_floor($db, $prefix, $table, $meta)
{
    $floor = 1;
    $tables = array($table);
    if (plus_storage_metadata($db, $prefix . 'banner_stats')) { $tables[] = $prefix . 'banner_stats'; }
    foreach ($tables as $source) {
        $rows = plus_storage_rows($db, 'SELECT MAX(banner_id) AS maximum FROM ' . plus_storage_identifier($source));
        $maximum = $rows[0]['maximum'];
        if ($maximum === null) { continue; }
        if (!preg_match('/^[0-9]+$/D', (string)$maximum) || !plus_storage_counter_at_least('16777215',$maximum)) {
            throw new RuntimeException('Historical banner reference exceeds supported capacity; review before migrating');
        }
        $floor = max($floor, (int)$maximum + 1);
    }
    if ($meta['AUTO_INCREMENT'] !== null) {
        if (!preg_match('/^[1-9][0-9]*$/D', (string)$meta['AUTO_INCREMENT'])) { throw new RuntimeException('Invalid banner identity floor'); }
        if (plus_storage_counter_at_least($meta['AUTO_INCREMENT'],(string)$floor)) { return (string)$meta['AUTO_INCREMENT']; }
    }
    return (string)$floor;
}
function plus_banner_ids_plan($db, $prefix, $required = false)
{
    $table = plus_banner_ids_table($prefix); $quoted = plus_storage_identifier($table);
    $meta = plus_storage_metadata($db, $table);
    if (!$meta) { if ($required) { throw new RuntimeException('Banner table is missing'); } return array(); }
    $column = null;
    foreach (plus_storage_rows($db, 'SHOW FULL COLUMNS FROM ' . $quoted) as $candidate) {
        if ($candidate['Field'] === 'banner_id') { $column = $candidate; }
    }
    if (!$column || preg_match('/^mediumint(?:\([0-9]+\))? unsigned$/Di', $column['Type']) !== 1
        || $column['Null'] !== 'NO' || !in_array($column['Extra'], array('', 'auto_increment'), true)
        || !in_array($column['Default'], array(null, '0'), true)) {
        throw new RuntimeException('Unsupported banner ID definition; review it before migrating');
    }
    $primary = plus_storage_rows($db, 'SHOW INDEX FROM ' . $quoted . " WHERE Key_name='PRIMARY'");
    if ($primary && (count($primary) !== 1 || $primary[0]['Column_name'] !== 'banner_id' || (string)$primary[0]['Non_unique'] !== '0' || $primary[0]['Sub_part'] !== null)) {
        throw new RuntimeException('Custom banner primary key requires manual review');
    }
    $bad = plus_storage_rows($db, 'SELECT COUNT(*) AS n FROM ' . $quoted . ' WHERE banner_id IS NULL OR banner_id<1 OR banner_id>16777215');
    $duplicates = plus_storage_rows($db, 'SELECT banner_id FROM ' . $quoted . ' GROUP BY banner_id HAVING COUNT(*)>1 LIMIT 1');
    if ((string)$bad[0]['n'] !== '0' || $duplicates) { throw new RuntimeException('Invalid or duplicate banner IDs require manual review; no rows are deleted or renumbered'); }
    $floor = plus_banner_ids_floor($db,$prefix,$table,$meta);
    $ready = $primary && $column['Extra'] === 'auto_increment' && $column['Default'] === null;
    if ($ready && $meta['AUTO_INCREMENT'] !== null && plus_storage_counter_at_least($meta['AUTO_INCREMENT'],$floor)) { return array(); }
    $mode = plus_storage_rows($db, 'SELECT @@SESSION.sql_mode AS mode');
    $comment = in_array('NO_BACKSLASH_ESCAPES', explode(',', $mode[0]['mode']), true)
        ? str_replace("'", "''", $column['Comment']) : mysqli_real_escape_string($db, $column['Comment']);
    $parts = $ready ? array() : array("MODIFY banner_id MEDIUMINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '" . $comment . "'");
    if (!$primary) { $parts[] = 'ADD PRIMARY KEY (banner_id)'; }
    $parts[] = 'AUTO_INCREMENT=' . $floor;
    return array($table => array('sql'=>'ALTER TABLE ' . $quoted . ' ' . implode(', ', $parts)));
}
function plus_banner_ids_digest($db, $table)
{
    $result = mysqli_query($db, 'SELECT * FROM ' . plus_storage_identifier($table) . ' ORDER BY banner_id', MYSQLI_USE_RESULT);
    if (!$result) { throw new RuntimeException('Cannot inventory banner contents'); }
    $hash = hash_init('sha256');
    try {
        while ($row = mysqli_fetch_assoc($result)) { hash_update($hash, serialize($row)); }
        if (mysqli_errno($db)) { throw new RuntimeException('Banner inventory interrupted'); }
    } finally { mysqli_free_result($result); }
    return hash_final($hash);
}
function plus_banner_ids_apply($db, $prefix, $backup_confirmed, $maintenance_confirmed, $wait_seconds = 30)
{
    if (!$backup_confirmed || !$maintenance_confirmed) { throw new RuntimeException('Banner ID migration requires a verified backup and stopped web/cron writers'); }
    if (!is_int($wait_seconds) || $wait_seconds < 1 || $wait_seconds > 30) { throw new RuntimeException('Invalid banner DDL wait limit'); }
    $table = plus_banner_ids_table($prefix);
    $identity = plus_storage_rows($db, 'SELECT DATABASE() AS db,CONNECTION_ID() AS id,@@SESSION.autocommit AS automatic,@@SESSION.sql_mode AS mode,@@SESSION.lock_wait_timeout AS wait_timeout'); $identity = $identity[0];
    if (!$identity['db'] || (string)$identity['automatic'] !== '1' || preg_match('/^[A-Z0-9_,]*$/D', $identity['mode']) !== 1 || !preg_match('/^[0-9]{1,10}$/D', (string)$identity['wait_timeout'])) {
        throw new RuntimeException('Banner migration requires an idle autocommit connection');
    }
    // This must fail before a caller's active transaction can be committed by DDL.
    plus_storage_query($db, 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $owned = $held = false; $lock = 'plus_innodb_' . sha1($identity['db']);
    try {
        plus_storage_query($db, 'START TRANSACTION READ ONLY'); $owned = true;
        $owner = plus_storage_rows($db, "SELECT IS_USED_LOCK('" . $lock . "') AS owner");
        if ((string)$owner[0]['owner'] === (string)$identity['id']) { throw new RuntimeException('Caller already owns the migration lock'); }
        $rows = plus_storage_rows($db, "SELECT GET_LOCK('" . $lock . "',0) AS held");
        if (count($rows) !== 1 || (string)$rows[0]['held'] !== '1') { throw new RuntimeException('Another schema migration is running'); } $held = true;
        $plan = plus_banner_ids_plan($db, $prefix, true);
        $meta = plus_storage_metadata($db, $table); $signature = plus_storage_signature($db, $table); $digest = plus_banner_ids_digest($db, $table);
        if ($plan && $meta['ENGINE'] !== 'InnoDB') { throw new RuntimeException('Convert banner storage to InnoDB first using --storage-only or the full updater'); }
        plus_storage_query($db, 'COMMIT'); $owned = false;
        plus_storage_query($db, "SET SESSION sql_mode=CONCAT_WS(',',@@SESSION.sql_mode,'STRICT_ALL_TABLES','NO_ENGINE_SUBSTITUTION')");
        plus_storage_query($db, 'SET SESSION lock_wait_timeout=' . $wait_seconds);
        foreach ($plan as $item) {
            plus_storage_query($db, $item['sql']);
            if (plus_storage_rows($db, 'SHOW WARNINGS')) { throw new RuntimeException('Banner ID rebuild reported warnings; keep writers stopped'); }
        }
        $after = plus_storage_signature($db, $table); $after_meta = plus_storage_metadata($db, $table);
        if ($plan) {
            foreach ($signature[0] as &$column) { if ($column['Field'] === 'banner_id') { $column['Extra'] = 'auto_increment'; $column['Default'] = null; } } unset($column);
            // Ignore display width only; no type, sign, capacity or comment changes.
            $normalize = function($value) {
                foreach ($value[0] as &$column) { if ($column['Field'] === 'banner_id') { $column['Type'] = preg_replace('/\([0-9]+\)/', '', $column['Type']); } } unset($column);
                $value[1] = array_values(array_filter($value[1], function($i) { return $i['Key_name'] !== 'PRIMARY'; }));
                return $value;
            };
            $signature = $normalize($signature); $after = $normalize($after);
        }
        $floor = $meta['AUTO_INCREMENT']; $after_floor = $after_meta['AUTO_INCREMENT']; unset($meta['AUTO_INCREMENT'], $after_meta['AUTO_INCREMENT']);
        if ($signature !== $after || $meta !== $after_meta || ($floor !== null && !plus_storage_counter_at_least($after_floor,$floor))
            || $digest !== plus_banner_ids_digest($db,$table) || plus_banner_ids_plan($db,$prefix,true)) {
            throw new RuntimeException('Banner migration verification failed; keep writers stopped and inspect the backup');
        }
        return count($plan);
    } finally {
        try { if ($owned) { plus_storage_query($db, 'ROLLBACK'); } }
        finally {
            try { plus_storage_query($db, "SET SESSION sql_mode='" . $identity['mode'] . "'"); plus_storage_query($db, 'SET SESSION lock_wait_timeout=' . $identity['wait_timeout']); }
            finally { if ($held) { plus_storage_query($db, "DO RELEASE_LOCK('" . $lock . "')"); } }
        }
    }
}
