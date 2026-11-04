<?php
// Widen the historical banned-game reference; preserve every existing row.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(2); }
require_once __DIR__ . '/innodb_migration.php';

function plus_arcade_name_table($prefix)
{
    if (!is_string($prefix) || !preg_match('/^[A-Za-z0-9_]{1,42}$/D', $prefix)) { throw new RuntimeException('Invalid Arcade table prefix'); }
    return $prefix . 'ina_banned';
}

function plus_arcade_name_plan($db, $prefix, $required = false, $canonical = false)
{
    if (mysqli_character_set_name($db) !== 'utf8mb4') { throw new RuntimeException('An exact utf8mb4 Arcade connection is required'); }
    $mode = plus_storage_rows($db, 'SELECT @@SESSION.sql_mode AS mode'); $mode = $mode[0]['mode'];
    if (!is_string($mode) || !preg_match('/^[A-Z0-9_,]*$/D', $mode)) { throw new RuntimeException('Unknown Arcade SQL mode'); }
    $normalized = implode(',', array_diff(explode(',', $mode), array('NO_BACKSLASH_ESCAPES','ANSI_QUOTES')));
    try {
        if ($normalized !== $mode) { plus_storage_query($db, "SET SESSION sql_mode='" . $normalized . "'"); }
        $table = plus_arcade_name_table($prefix); $quoted = plus_storage_identifier($table); $meta = plus_storage_metadata($db, $table);
        if (!$meta) { if ($required) { throw new RuntimeException('Arcade banned-game table is missing'); } return array(); }
        if ($canonical && ($meta['ENGINE'] !== 'InnoDB' || strtolower($meta['ROW_FORMAT']) !== 'dynamic' || $meta['TABLE_COLLATION'] !== 'utf8mb4_unicode_ci')) { throw new RuntimeException('Complete canonical storage migration before widening Arcade references'); }
        $columns = plus_storage_rows($db, 'SHOW FULL COLUMNS FROM ' . $quoted . " WHERE Field='game'");
        if (count($columns) !== 1) { throw new RuntimeException('Arcade game reference is missing'); } $column = $columns[0];
        if ($column['Extra'] !== '' || !is_string($column['Collation']) || !preg_match('/^[a-z0-9_]+$/iD', $column['Collation'])
            || ($canonical && $column['Collation'] !== 'utf8mb4_unicode_ci')) { throw new RuntimeException('Custom or noncanonical Arcade reference requires manual review'); }
        if (in_array(strtolower($column['Type']), array('text','mediumtext','longtext'), true)) { return array(); }
        if (!preg_match('/^varchar\(([1-9][0-9]*)\)$/iD', $column['Type'], $width)) { throw new RuntimeException('Custom Arcade reference type requires manual review; no definition is narrowed'); }
        if ((int)$width[1] >= 50) { return array(); }
        $create = plus_storage_rows($db, 'SHOW CREATE TABLE ' . $quoted); $create = array_values($create[0]);
        if (!preg_match('/^[\t ]*`game` [^\r\n]+$/m', $create[1], $line)) { throw new RuntimeException('Exact Arcade reference definition unavailable'); }
        $default = ''; $literal = null;
        if (preg_match('/\bDEFAULT\b/', $line[0])) {
            $pattern = <<<'ARCADE_DEFAULT'
/\bDEFAULT (NULL|'(?:[^'\\]|\\[\s\S]|'')*')(?= COMMENT |,?$)/D
ARCADE_DEFAULT;
            if (!preg_match($pattern, $line[0], $token)) { throw new RuntimeException('Expression default requires manual review; it is never replaced'); }
            if ($token[1] === 'NULL') { $default = ' DEFAULT NULL'; }
            else {
                // Read the real value: SHOW CREATE may print non-BMP text as '?'.
                $rows = plus_storage_rows($db, 'SELECT DEFAULT(`game`) AS value FROM ' . $quoted . ' LIMIT 1');
                if (!$rows) {
                    if (strpos($token[1], '?') !== false || strpos($token[1], "\xef\xbf\xbd") !== false) { throw new RuntimeException('Empty-table Arcade literal default is ambiguous'); }
                    $rows = plus_storage_rows($db, 'SELECT ' . $token[1] . ' AS value');
                }
                if (count($rows) !== 1 || !is_string($rows[0]['value']) || preg_match('//u', $rows[0]['value']) !== 1) { throw new RuntimeException('Exact Arcade literal default unavailable'); }
                $literal = $rows[0]['value']; $default = " DEFAULT '" . mysqli_real_escape_string($db, $literal) . "'";
            }
        }
        $definition = 'VARCHAR(50) COLLATE ' . plus_storage_identifier($column['Collation']) . ($column['Null'] === 'YES' ? ' NULL' : ' NOT NULL') . $default;
        $definition .= " COMMENT '" . mysqli_real_escape_string($db, $column['Comment']) . "'";
        $sql = 'ALTER TABLE ' . $quoted . ' MODIFY `game` ' . $definition;
        if ($meta['AUTO_INCREMENT'] !== null) {
            if (!preg_match('/^[1-9][0-9]*$/D', (string)$meta['AUTO_INCREMENT'])) { throw new RuntimeException('Invalid custom Arcade allocation floor'); }
            $sql .= ', AUTO_INCREMENT=' . $meta['AUTO_INCREMENT'];
        }
        return array($table => array('sql' => $sql, 'literal_default' => $literal));
    } finally { if ($normalized !== $mode) { plus_storage_query($db, "SET SESSION sql_mode='" . $mode . "'"); } }
}

function plus_arcade_name_digest($db, $table)
{
    // There is no primary key. Include duplicate rows without imposing a new
    // identity or relying on the physical row order after a table rebuild.
    $result = mysqli_query($db, 'SELECT * FROM ' . plus_storage_identifier($table), MYSQLI_USE_RESULT);
    if (!$result) { throw new RuntimeException('Arcade content inventory unavailable'); } $rows = array();
    try { while ($row = mysqli_fetch_assoc($result)) { $rows[] = hash('sha256', serialize($row)); } if (mysqli_errno($db)) { throw new RuntimeException('Arcade content inventory interrupted'); } }
    finally { mysqli_free_result($result); }
    sort($rows, SORT_STRING); return hash('sha256', count($rows) . "\n" . implode("\n", $rows));
}

function plus_arcade_name_apply($db, $prefix, $backup_confirmed, $maintenance_confirmed, $wait_seconds = 30)
{
    if (!$backup_confirmed || !$maintenance_confirmed) { throw new RuntimeException('Arcade reference migration requires a verified backup and stopped web/cron writers'); }
    if (!is_int($wait_seconds) || $wait_seconds < 1 || $wait_seconds > 30) { throw new RuntimeException('Invalid Arcade DDL wait limit'); }
    $table = plus_arcade_name_table($prefix);
    $identity = plus_storage_rows($db, 'SELECT DATABASE() AS db,CONNECTION_ID() AS id,@@SESSION.autocommit AS automatic,@@SESSION.sql_mode AS mode,@@SESSION.lock_wait_timeout AS wait_timeout'); $identity = $identity[0];
    if (!$identity['db'] || (string)$identity['automatic'] !== '1' || !preg_match('/^[A-Z0-9_,]*$/D', $identity['mode']) || !preg_match('/^[0-9]{1,10}$/D', (string)$identity['wait_timeout'])) { throw new RuntimeException('Arcade DDL requires an idle autocommit connection'); }
    plus_storage_query($db, 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $owned = $held = false; $lock = 'plus_innodb_' . sha1($identity['db']);
    try {
        plus_storage_query($db, 'START TRANSACTION READ ONLY'); $owned = true;
        $owner = plus_storage_rows($db, "SELECT IS_USED_LOCK('" . $lock . "') AS owner");
        if ((string)$owner[0]['owner'] === (string)$identity['id']) { throw new RuntimeException('Caller already owns the migration lock'); }
        $rows = plus_storage_rows($db, "SELECT GET_LOCK('" . $lock . "',0) AS held");
        if (count($rows) !== 1 || (string)$rows[0]['held'] !== '1') { throw new RuntimeException('Another schema migration is running'); } $held = true;
        $plan = plus_arcade_name_plan($db, $prefix, true, true); $meta = plus_storage_metadata($db, $table); $signature = plus_storage_signature($db, $table); $digest = plus_arcade_name_digest($db, $table);
        plus_storage_query($db, 'COMMIT'); $owned = false;
        $ddl_mode = implode(',', array_diff(explode(',', $identity['mode']), array('NO_BACKSLASH_ESCAPES','ANSI_QUOTES')));
        plus_storage_query($db, "SET SESSION sql_mode=CONCAT_WS(',', '" . $ddl_mode . "','STRICT_ALL_TABLES','NO_ENGINE_SUBSTITUTION')");
        plus_storage_query($db, 'SET SESSION lock_wait_timeout=' . $wait_seconds);
        foreach ($plan as $item) { plus_storage_query($db, $item['sql']); if (plus_storage_rows($db, 'SHOW WARNINGS')) { throw new RuntimeException('Arcade rebuild reported warnings; keep writers stopped'); } }
        $after = plus_storage_signature($db, $table);
        if ($plan) { foreach ($signature[0] as &$column) { if ($column['Field'] === 'game') { $column['Type'] = 'varchar(50)'; } } unset($column); }
        $after_meta = plus_storage_metadata($db, $table); $floor = $meta['AUTO_INCREMENT']; $after_floor = $after_meta['AUTO_INCREMENT']; unset($meta['AUTO_INCREMENT'], $after_meta['AUTO_INCREMENT']);
        if ($signature !== $after || $meta !== $after_meta || ($floor !== null && !plus_storage_counter_at_least($after_floor, $floor)) || $digest !== plus_arcade_name_digest($db, $table)) { throw new RuntimeException('Arcade definition or payload changed unexpectedly; keep writers stopped and inspect backup'); }
        if (plus_arcade_name_plan($db, $prefix, true, true)) { throw new RuntimeException('Arcade reference migration is incomplete; keep writers stopped'); }
        return count($plan);
    } finally {
        try { if ($owned) { plus_storage_query($db, 'ROLLBACK'); } }
        finally { try { plus_storage_query($db, "SET SESSION sql_mode='" . $identity['mode'] . "'"); plus_storage_query($db, 'SET SESSION lock_wait_timeout=' . $identity['wait_timeout']); }
            finally { if ($held) { plus_storage_query($db, "DO RELEASE_LOCK('" . $lock . "')"); } } }
    }
}
