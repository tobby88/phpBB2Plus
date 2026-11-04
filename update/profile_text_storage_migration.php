<?php
// Offline widening only. Existing profile values and archived receipts stay intact.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(2); }
require_once __DIR__ . '/banner_id_migration.php';
require_once dirname(__DIR__) . '/phpBB2/includes/functions_profile_fields.php';

function plus_profile_text_targets($db, $prefix, $required = false)
{
    if (!is_string($prefix) || !preg_match('/^[A-Za-z0-9_]{1,42}$/D', $prefix)) { throw new RuntimeException('Invalid profile table prefix'); }
    $metadata = $prefix . 'profile_fields'; $users = $prefix . 'users';
    if (!plus_storage_metadata($db, $metadata)) { if ($required) { throw new RuntimeException('Profile definitions are missing'); } return array(); }
    if (!plus_storage_metadata($db, $users)) { throw new RuntimeException('Profile users table is missing'); }
    $targets = array($metadata => phpbb_profile_text_metadata_columns(), $users => array());
    foreach (plus_storage_rows($db, 'SELECT * FROM ' . plus_storage_identifier($metadata) . ' ORDER BY field_id') as $row) {
        $column = phpbb_profile_field_column($row);
        if ($column === '' || in_array($column, phpbb_profile_definition_core_columns(), true) || in_array($column, $targets[$users], true)
            || phpbb_profile_field_value_limit($row) === false) { throw new RuntimeException('Ambiguous profile field ownership; no columns are renamed or adopted'); }
        $targets[$users][] = $column;
    }
    // Retired columns carry exact physical signatures. Do not invalidate these
    // recovery receipts or adopt a conflicting active claim during widening.
    $actions = $prefix . 'profile_field_actions';
    if ($targets[$users] && plus_storage_metadata($db, $actions)) {
        foreach (plus_storage_rows($db, 'SELECT field_column,action_state FROM ' . plus_storage_identifier($actions)) as $row) {
            if ($row['action_state'] !== 'restored' && in_array($row['field_column'], $targets[$users], true)) { throw new RuntimeException('Conflicting archived profile ownership requires manual review'); }
        }
    }
    return $targets;
}

function plus_profile_text_plan($db, $prefix, $required = false, $canonical = false)
{
    $mode = plus_storage_rows($db,'SELECT @@SESSION.sql_mode AS mode'); $mode = $mode[0]['mode'];
    if (!is_string($mode) || !preg_match('/^[A-Z0-9_,]*$/D',$mode)) { throw new RuntimeException('Unknown profile SQL mode'); }
    $normalized = implode(',',array_diff(explode(',',$mode),array('NO_BACKSLASH_ESCAPES')));
    try {
        if ($normalized !== $mode) { plus_storage_query($db,"SET SESSION sql_mode='".$normalized."'"); }
        return plus_profile_text_plan_normalized($db,$prefix,$required,$canonical);
    } finally { if ($normalized !== $mode) { plus_storage_query($db,"SET SESSION sql_mode='".$mode."'"); } }
}

function plus_profile_text_plan_normalized($db, $prefix, $required, $canonical)
{
    if (mysqli_character_set_name($db) !== 'utf8mb4') { throw new RuntimeException('An exact utf8mb4 profile connection is required'); }
    $mode = plus_storage_rows($db, 'SELECT @@SESSION.sql_mode AS mode');
    if (in_array('NO_BACKSLASH_ESCAPES',explode(',',$mode[0]['mode']),true)) { throw new RuntimeException('Use the profile migration SQL-mode wrapper'); } $plan = array();
    foreach (plus_profile_text_targets($db, $prefix, $required) as $table => $fields) {
        if (!$fields) { continue; }
        $quoted = plus_storage_identifier($table); $meta = plus_storage_metadata($db, $table);
        if ($canonical && ($meta['ENGINE'] !== 'InnoDB' || strtolower($meta['ROW_FORMAT']) !== 'dynamic' || $meta['TABLE_COLLATION'] !== 'utf8mb4_unicode_ci')) { throw new RuntimeException('Complete canonical storage migration before profile text migration'); }
        $columns = array();
        foreach (plus_storage_rows($db, "SELECT COLUMN_NAME,DATA_TYPE,COLUMN_KEY,EXTRA,CHARACTER_SET_NAME,COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . mysqli_real_escape_string($db, $table) . "'") as $column) { $columns[$column['COLUMN_NAME']] = $column; }
        $create = plus_storage_rows($db, 'SHOW CREATE TABLE ' . $quoted); $create = array_values($create[0]); $changes = array();
        foreach ($fields as $field) {
            if (!isset($columns[$field])) { throw new RuntimeException('Mapped profile column is missing'); } $column = $columns[$field];
            if ($canonical && ($column['CHARACTER_SET_NAME'] !== 'utf8mb4' || $column['COLLATION_NAME'] !== 'utf8mb4_unicode_ci')) { throw new RuntimeException('Complete canonical profile character conversion first'); }
            if (!in_array($column['EXTRA'], array('', 'DEFAULT_GENERATED'), true)) { throw new RuntimeException('Generated profile column requires manual review'); }
            if (in_array($column['DATA_TYPE'], array('mediumtext','longtext'), true)) { continue; }
            $literal = false;
            $sql = phpbb_profile_text_widen_sql($table, $field, $create[1], $column, 360000, null, $literal);
            $value = null;
            if ($literal) {
                $value = plus_profile_text_default($db,$table,$field);
                $escaped = mysqli_real_escape_string($db,$value);
                $sql = phpbb_profile_text_widen_sql($table,$field,$create[1],$column,360000,"'".$escaped."'",$literal);
            }
            $changes[$field] = array('sql' => $sql, 'literal' => $literal, 'literal_value' => $value);
        }
        if ($changes) {
            foreach (plus_storage_rows($db, 'SHOW INDEX FROM ' . $quoted) as $index) {
                if (isset($changes[$index['Column_name']])) { throw new RuntimeException('Indexed profile storage requires manual review; no index is shortened or removed'); }
            }
            $plan[$table] = array('changes' => $changes, 'sql' => plus_profile_text_sql($table, $changes, $meta));
        }
    }
    return $plan;
}

function plus_profile_text_sql($table, $changes, $meta)
{
    $lead = 'ALTER TABLE ' . plus_storage_identifier($table) . ' '; $parts = array();
    foreach ($changes as $change) {
        if (strpos($change['sql'], $lead) !== 0) { throw new RuntimeException('Unexpected profile widening command'); }
        $parts[] = substr($change['sql'], strlen($lead));
    }
    if ($meta['AUTO_INCREMENT'] !== null) {
        if (!preg_match('/^[1-9][0-9]*$/D', (string)$meta['AUTO_INCREMENT'])) { throw new RuntimeException('Invalid profile identity floor'); }
        $parts[] = 'AUTO_INCREMENT=' . $meta['AUTO_INCREMENT'];
    }
    return $lead . implode(', ', $parts);
}

// Called only after the shared planner has proved a literal DEFAULT token.
// Read the real value: SHOW CREATE can replace supplementary characters by '?'.
function plus_profile_text_default($db, $table, $field)
{
    $expression = 'DEFAULT(' . plus_storage_identifier($field) . ')';
    $rows = plus_storage_rows($db, 'SELECT ' . $expression . ' AS literal_default FROM ' . plus_storage_identifier($table) . ' LIMIT 1');
    if (!$rows) {
        // MariaDB cannot evaluate DEFAULT(column) on an empty table without
        // a real row. Its printed metadata may also replace non-BMP text by
        // '?'. Accept only an unambiguous complete literal; never insert a
        // probe account or silently replace a potentially damaged default.
        $mode = plus_storage_rows($db,'SELECT @@SESSION.sql_mode AS mode'); $mode = $mode[0]['mode'];
        if (!is_string($mode) || !preg_match('/^[A-Z0-9_,]*$/D',$mode)) { throw new RuntimeException('Unknown profile default-reader SQL mode'); }
        $normalized = implode(',',array_diff(explode(',',$mode),array('NO_BACKSLASH_ESCAPES')));
        try {
        if ($normalized !== $mode) { plus_storage_query($db,"SET SESSION sql_mode='".$normalized."'"); }
        $create = plus_storage_rows($db, 'SHOW CREATE TABLE ' . plus_storage_identifier($table)); $create = array_values($create[0]);
        $literal = <<<'PROFILE_LITERAL'
'(?:[^'\\]|\\[\s\S]|'')*'
PROFILE_LITERAL;
        if (!preg_match('/^\s*`' . preg_quote($field,'/') . '` [^\r\n]+$/m',$create[1],$line)
            || !preg_match('/\bDEFAULT (\(' . $literal . '\)|' . $literal . ')(?: COMMENT |,?$)/D',$line[0],$printed)
            || strpos($printed[1],'?') !== false || strpos($printed[1],"\xef\xbf\xbd") !== false) { throw new RuntimeException('Empty-table profile default is ambiguous; manual review required, no replacement is written'); }
        $rows = plus_storage_rows($db,'SELECT ' . $printed[1] . ' AS literal_default');
        } finally { if ($normalized !== $mode) { plus_storage_query($db,"SET SESSION sql_mode='".$mode."'"); } }
    }
    if (count($rows) !== 1 || !is_string($rows[0]['literal_default']) || preg_match('//u', $rows[0]['literal_default']) !== 1) { throw new RuntimeException('Cannot prove the exact profile literal default; manual review required'); }
    return $rows[0]['literal_default'];
}

function plus_profile_text_digest($db, $table, $identity)
{
    if (!in_array($identity, array('field_id','user_id'), true)) { throw new RuntimeException('Unknown profile row identity'); }
    $keys = plus_storage_rows($db, 'SHOW INDEX FROM ' . plus_storage_identifier($table) . " WHERE Key_name='PRIMARY'");
    if (count($keys) !== 1 || $keys[0]['Column_name'] !== $identity) { throw new RuntimeException('Profile row identities require manual review'); }
    $result = mysqli_query($db, 'SELECT * FROM ' . plus_storage_identifier($table) . ' ORDER BY ' . plus_storage_identifier($identity), MYSQLI_USE_RESULT);
    if (!$result) { throw new RuntimeException('Cannot inventory profile contents'); } $hash = hash_init('sha256');
    try {
        while ($row = mysqli_fetch_assoc($result)) { hash_update($hash, serialize($row)); }
        if (mysqli_errno($db)) { throw new RuntimeException('Profile content inventory interrupted'); }
    } finally { mysqli_free_result($result); }
    return hash_final($hash);
}

function plus_profile_text_apply($db, $prefix, $backup_confirmed, $maintenance_confirmed, $wait_seconds = 30)
{
    if (!$backup_confirmed || !$maintenance_confirmed) { throw new RuntimeException('Profile text migration requires a verified backup and stopped web/cron writers'); }
    if (!is_int($wait_seconds) || $wait_seconds < 1 || $wait_seconds > 30) { throw new RuntimeException('Invalid profile DDL wait limit'); }
    $identity = plus_storage_rows($db, 'SELECT DATABASE() AS db,CONNECTION_ID() AS id,@@SESSION.autocommit AS automatic,@@SESSION.sql_mode AS mode,@@SESSION.lock_wait_timeout AS wait_timeout'); $identity = $identity[0];
    if (!$identity['db'] || (string)$identity['automatic'] !== '1' || !preg_match('/^[A-Z0-9_,]*$/D', $identity['mode']) || !preg_match('/^[0-9]{1,10}$/D', (string)$identity['wait_timeout'])) { throw new RuntimeException('Profile DDL requires an idle autocommit connection'); }
    plus_storage_query($db, 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $owned = $held = false; $lock = 'plus_innodb_' . sha1($identity['db']);
    try {
        plus_storage_query($db, 'START TRANSACTION READ ONLY'); $owned = true;
        $owner = plus_storage_rows($db, "SELECT IS_USED_LOCK('" . $lock . "') AS owner");
        if ((string)$owner[0]['owner'] === (string)$identity['id']) { throw new RuntimeException('Caller already owns the migration lock'); }
        $rows = plus_storage_rows($db, "SELECT GET_LOCK('" . $lock . "',0) AS held");
        if (count($rows) !== 1 || (string)$rows[0]['held'] !== '1') { throw new RuntimeException('Another schema migration is running'); } $held = true;
        $plan = plus_profile_text_plan($db, $prefix, true, true); $before = array();
        foreach ($plan as $table => &$item) {
            $signature = plus_storage_signature($db, $table); $meta = plus_storage_metadata($db, $table); $defaults = array();
            foreach ($item['changes'] as $field => $change) { if ($change['literal']) { $defaults[$field] = $change['literal_value']; } }
            $key = $table === $prefix . 'users' ? 'user_id' : 'field_id';
            $before[$table] = array('meta'=>$meta,'signature'=>$signature,'defaults'=>$defaults,'digest'=>plus_profile_text_digest($db,$table,$key),'key'=>$key);
        } unset($item);
        plus_storage_query($db, 'COMMIT'); $owned = false;
        $ddl_mode = implode(',',array_diff(explode(',',$identity['mode']),array('NO_BACKSLASH_ESCAPES')));
        plus_storage_query($db, "SET SESSION sql_mode=CONCAT_WS(',', '".$ddl_mode."','STRICT_ALL_TABLES','NO_ENGINE_SUBSTITUTION')");
        plus_storage_query($db, 'SET SESSION lock_wait_timeout=' . $wait_seconds);
        foreach ($plan as $table => $item) {
            // DDL cannot be rolled back. Keep writers stopped after an error.
            plus_storage_query($db, $item['sql']);
            if (plus_storage_rows($db, 'SHOW WARNINGS')) { throw new RuntimeException('Profile rebuild reported warnings; inspect before retrying'); }
            $after = plus_storage_signature($db,$table); $signature = $before[$table]['signature'];
            foreach ($signature[0] as $i => $column) {
                if (!isset($item['changes'][$column['Field']])) { continue; }
                if ($after[0][$i]['Field'] !== $column['Field'] || $after[0][$i]['Type'] !== 'mediumtext') { throw new RuntimeException('Profile type verification failed'); }
                $signature[0][$i]['Type'] = 'mediumtext';
                if (isset($before[$table]['defaults'][$column['Field']])) {
                    if (plus_profile_text_default($db,$table,$column['Field']) !== $before[$table]['defaults'][$column['Field']]) { throw new RuntimeException('Profile default changed; keep writers stopped'); }
                    $signature[0][$i]['Default'] = $after[0][$i]['Default'];
                    if (!in_array($after[0][$i]['Extra'],array('','DEFAULT_GENERATED'),true)) { throw new RuntimeException('Profile default became an expression'); }
                    $signature[0][$i]['Extra'] = $after[0][$i]['Extra'];
                }
            }
            $meta = $before[$table]['meta']; $after_meta = plus_storage_metadata($db,$table); $floor = $meta['AUTO_INCREMENT']; $after_floor = $after_meta['AUTO_INCREMENT']; unset($meta['AUTO_INCREMENT'],$after_meta['AUTO_INCREMENT']);
            if ($signature !== $after || $meta !== $after_meta || ($floor !== null && !plus_storage_counter_at_least($after_floor,$floor)) || $before[$table]['digest'] !== plus_profile_text_digest($db,$table,$before[$table]['key'])) { throw new RuntimeException('Profile schema/data verification failed; keep writers stopped'); }
        }
        if (plus_profile_text_plan($db,$prefix,true,true)) { throw new RuntimeException('Profile text migration incomplete'); }
        return count($plan);
    } finally {
        try { if ($owned) { plus_storage_query($db,'ROLLBACK'); } }
        finally {
            try { plus_storage_query($db,"SET SESSION sql_mode='".$identity['mode']."'"); plus_storage_query($db,'SET SESSION lock_wait_timeout='.$identity['wait_timeout']); }
            finally { if ($held) { plus_storage_query($db,"DO RELEASE_LOCK('".$lock."')"); } }
        }
    }
}
