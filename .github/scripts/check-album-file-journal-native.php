<?php
if (PHP_SAPI !== 'cli' || getenv('PHPBB_ALBUM_FILE_JOURNAL_NATIVE') !== '1') { echo "Native Album file journal fixture skipped.\n"; return; }
define('IN_PHPBB', true); $root = dirname(dirname(__DIR__));
require $root . '/phpBB2/includes/php_compat.php'; require $root . '/update/album_file_journal_migration.php'; require $root . '/phpBB2/includes/sql_parse.php';
require $root . '/update/album_rating_storage_migration.php';
require $root . '/update/album_comment_parent_migration.php';
require $root . '/update/pafiledb_file_journal_migration.php';
require $root . '/update/pafiledb_download_storage_migration.php';
mysqli_report(MYSQLI_REPORT_OFF);
function ajm_check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
function ajm_sql($sql) { return plus_storage_query($GLOBALS['db'], $sql); }
function ajm_rows($sql) { return plus_storage_rows($GLOBALS['db'], $sql); }
function ajm_denied($callback, $message) { $denied = false; try { $callback(); } catch (Exception $error) { $denied = true; } ajm_check($denied, $message); $GLOBALS['cases']++; }
$port = getenv('PHPBB_ALBUM_FILE_JOURNAL_PORT') ?: '3306'; $password = getenv('PHPBB_ALBUM_FILE_JOURNAL_PASSWORD') ?: '';
ajm_check(preg_match('/^[0-9]{1,5}$/D', $port) && (int) $port > 0 && (int) $port <= 65535, 'Own loopback port');
$db = @mysqli_connect('127.0.0.1', 'root', $password, '', (int) $port); ajm_check($db && mysqli_set_charset($db, 'utf8mb4'), 'Owned loopback journal control');
$schema = 'codex_album_journal_' . bin2hex(phpbb_random_bytes(8)); $directory = sys_get_temp_dir() . '/' . $schema; $created = false; $created_directory = false; $peer = null; $cases = 0;
function ajm_snapshot()
{
    $result = array();
    foreach (array('fixture_album_file_jobs', 'fixture_album_file_items', 'unrelated', 'caller_marker') as $table) {
        $meta = plus_storage_metadata($GLOBALS['db'], $table); if (!$meta) { $result[$table] = null; continue; }
        $create = ajm_rows('SHOW CREATE TABLE ' . plus_storage_identifier($table)); $rows = ajm_rows('SELECT * FROM ' . plus_storage_identifier($table) . ($table === 'fixture_album_file_jobs' ? ' ORDER BY operation_id' : ($table === 'fixture_album_file_items' ? ' ORDER BY operation_id,item_index' : ' ORDER BY value')));
        $result[$table] = array($create, $rows);
    }
    return $result;
}
function ajm_drop()
{
    ajm_check(ajm_rows('SELECT DATABASE() AS name')[0]['name'] === $GLOBALS['schema'], 'Exact owned schema before fixture replacement');
    foreach (array('fixture_album_file_items', 'fixture_album_file_jobs') as $table) { ajm_sql('DROP TABLE IF EXISTS ' . plus_storage_identifier($table)); }
}
function ajm_seed()
{
    $operation = str_repeat('a', 32); $hash = str_repeat('b', 64);
    ajm_sql("INSERT INTO fixture_album_file_jobs(operation_id,operation_kind,operation_state,actor_id,binding_hash,capability_hash,item_count,picture_count,created_at) VALUES('" . $operation . "','upload','prepared',-1,'" . $hash . "','" . $hash . "',2,1,123)");
    foreach (array('Größe.jpg', 'größe.jpg') as $index => $name) {
        $item = hash('sha256', "small\0" . $name); $literal = 'CONVERT(0x' . bin2hex($name) . ' USING utf8mb4)';
        ajm_sql("INSERT INTO fixture_album_file_items(operation_id,item_index,item_hash,file_scope,filename,file_present,file_bytes,file_sha256,item_state) VALUES('" . $operation . "'," . $index . ",'" . $item . "','small'," . $literal . ",1,42,'" . $hash . "','pending')");
    }
}
function ajm_config($prefix)
{
    ajm_check(in_array($prefix, array('fixture_', 'legacy_'), true), 'Only own test prefixes');
    $values = array('dbhost' => '127.0.0.1:' . $GLOBALS['port'], 'dbuser' => 'root', 'dbpasswd' => $GLOBALS['password'], 'dbname' => $GLOBALS['schema'], 'table_prefix' => $prefix, 'dbms' => 'mysqli');
    $body = "<?php\n"; foreach ($values as $key => $value) { $body .= '$' . $key . '=' . var_export($value, true) . ";\n"; }
    ajm_check(file_put_contents($GLOBALS['directory'] . '/config.php', $body) === strlen($body) && chmod($GLOBALS['directory'] . '/config.php', 0600), 'Own temporary CLI config only');
}
function ajm_cli($arguments)
{
    $command = (DIRECTORY_SEPARATOR === chr(92) ? '' : 'exec ') . escapeshellarg(PHP_BINARY) . ' ';
    if (DIRECTORY_SEPARATOR === chr(92)) { $command .= '-n -d ' . escapeshellarg('extension_dir=' . ini_get('extension_dir')) . ' -d extension=php_mysqli.dll -d extension=php_openssl.dll '; if (extension_loaded('mbstring')) { $command .= '-d extension=php_mbstring.dll '; } }
    $command .= escapeshellarg($GLOBALS['root'] . '/update/update_from_153a.php') . ' ' . escapeshellarg('--config=' . $GLOBALS['directory'] . '/config.php');
    foreach ($arguments as $argument) { $command .= ' ' . escapeshellarg($argument); }
    $process = proc_open($command, array(0 => array('pipe','r'), 1 => array('pipe','w'), 2 => array('pipe','w')), $pipes, null, null, array('bypass_shell' => true)); ajm_check(is_resource($process), 'Own real updater child'); fclose($pipes[0]);
    stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false); $out = ''; $err = ''; $deadline = microtime(true) + 90; $observed = null;
    try {
        do {
            $out .= stream_get_contents($pipes[1]); $err .= stream_get_contents($pipes[2]); $status = proc_get_status($process);
            if (!$status['running']) { $observed = $status['exitcode']; break; }
            ajm_check(microtime(true) < $deadline && strlen($out) + strlen($err) < 8388608, 'Bounded owned updater child'); usleep(10000);
        } while (true);
        $out .= stream_get_contents($pipes[1]); $err .= stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $closed = proc_close($process); $process = null;
        return array($observed >= 0 ? $observed : $closed, $out, $err);
    } finally { if (is_resource($process)) { proc_terminate($process); foreach (array(1,2) as $i) { if (is_resource($pipes[$i])) { fclose($pipes[$i]); } } proc_close($process); } }
}
try {
    ajm_sql('CREATE DATABASE ' . plus_storage_identifier($schema) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); $created = true; ajm_check(mysqli_select_db($db, $schema), 'Only own schema selected');
    ajm_check(!file_exists($directory) && mkdir($directory, 0700), 'Own updater config directory'); $created_directory = true;
    ajm_sql('CREATE TABLE unrelated(value VARCHAR(255)) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    ajm_sql('CREATE TABLE caller_marker(value VARCHAR(255)) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    ajm_sql("INSERT INTO unrelated VALUES(CONVERT(0x" . bin2hex("Preserve's \\ Größe 😀") . ' USING utf8mb4))');
    $before = ajm_snapshot(); ajm_check(count(plus_album_file_journal_plan($db, 'fixture_')) === 2 && ajm_snapshot() === $before, 'Dry run preserves all schema/data and creates no tables'); $cases++;
    foreach (array(array(false,true), array(true,false), array(true,'yes')) as $flags) { ajm_denied(function () use ($db, $flags) { plus_album_file_journal_apply($db, 'fixture_', $flags[0], $flags[1]); }, 'Typed backup AND stopped-writer confirmation required'); ajm_check(ajm_snapshot() === $before, 'Missing confirmation changes nothing'); }
    foreach (array(null, array(), '', '../bad', str_repeat('a', 41), "x'bad") as $bad) { ajm_denied(function () use ($db, $bad) { plus_album_file_journal_plan($db, $bad); }, 'Invalid prefix never reaches DDL'); }
    foreach (array(0, 31, '1', null) as $wait) { ajm_denied(function () use ($db, $wait) { plus_album_file_journal_apply($db, 'fixture_', true, true, $wait); }, 'DDL wait is typed and bounded'); ajm_check(ajm_snapshot() === $before, 'Bad wait parameter never creates tables'); }
    $canonical = file_get_contents($root . '/phpBB2/install/schemas/mysql_schema.sql');
    foreach (array(str_replace('UNIQUE KEY operation_item (operation_id,item_hash)', 'UNIQUE KEY operation_item (operation_id,filename)', $canonical), str_replace('UNIQUE KEY operation_item (operation_id,item_hash)', 'KEY operation_item (operation_id,item_hash)', $canonical)) as $bad_schema) { ajm_denied(function () use ($bad_schema) { plus_album_file_journal_definitions('fixture_', $bad_schema); }, 'Canonical source cannot silently weaken identity/uniqueness'); }
    ajm_sql("SET SESSION default_storage_engine='MyISAM'");
    ajm_check(plus_album_file_journal_apply($db, 'fixture_', true, true, 1) === 2 && !plus_album_file_journal_plan($db, 'fixture_'), 'Create two canonical modern tables'); $cases++;
    ajm_check(ajm_rows('SELECT @@SESSION.default_storage_engine AS engine')[0]['engine'] === 'MyISAM', 'Explicit InnoDB creation does not overwrite caller default engine'); ajm_sql("SET SESSION default_storage_engine='InnoDB'"); $cases++;
    ajm_seed(); $stored = ajm_rows('SELECT HEX(filename) AS name FROM fixture_album_file_items ORDER BY item_index');
    ajm_check($stored === array(array('name' => strtoupper(bin2hex('Größe.jpg'))), array('name' => strtoupper(bin2hex('größe.jpg')))), 'Exact case/normalization-distinct filenames survive unicode_ci via item hashes'); $cases++;
    ajm_check(ajm_rows('SELECT actor_id,owner_id,picture_id FROM fixture_album_file_jobs') === array(array('actor_id' => '-1', 'owner_id' => '0', 'picture_id' => null)), 'Guest/public owner and prepared nullable identity preserved'); $cases++;
    foreach (array('STRICT_ALL_TABLES', 'STRICT_ALL_TABLES,NO_BACKSLASH_ESCAPES', '') as $mode) {
        ajm_sql("SET SESSION sql_mode='" . $mode . "'"); ajm_sql('SET SESSION lock_wait_timeout=12,innodb_strict_mode=OFF');
        $settings = ajm_rows('SELECT CONNECTION_ID() AS id,@@SESSION.sql_mode AS mode,@@SESSION.lock_wait_timeout AS waiting,@@SESSION.innodb_strict_mode AS strict_mode,@@SESSION.autocommit AS automatic'); $before = ajm_snapshot();
        ajm_check(plus_album_file_journal_apply($db, 'fixture_', true, true, 1) === 0 && ajm_snapshot() === $before && ajm_rows('SELECT CONNECTION_ID() AS id,@@SESSION.sql_mode AS mode,@@SESSION.lock_wait_timeout AS waiting,@@SESSION.innodb_strict_mode AS strict_mode,@@SESSION.autocommit AS automatic') === $settings, 'Idempotence preserves rows/schema/connection/session mode/waits/strictness'); $cases++;
    }
    ajm_sql("SET SESSION sql_mode='STRICT_ALL_TABLES'"); $before = ajm_snapshot();
    ajm_sql('START TRANSACTION'); ajm_sql("INSERT INTO caller_marker VALUES('pending explicit caller')");
    ajm_denied(function () use ($db) { plus_album_file_journal_apply($db, 'fixture_', true, true); }, 'Explicit caller TX must not be committed or replaced by DDL');
    ajm_check(count(ajm_rows('SELECT value FROM caller_marker')) === 1, 'Pending caller data still present'); ajm_sql('ROLLBACK'); ajm_check(ajm_snapshot() === $before, 'Caller rollback remains effective'); $cases++;
    mysqli_autocommit($db, false); ajm_sql("INSERT INTO caller_marker VALUES('pending autocommit-off')");
    ajm_denied(function () use ($db) { plus_album_file_journal_apply($db, 'fixture_', true, true); }, 'Autocommit-off caller TX refused'); ajm_check(count(ajm_rows('SELECT value FROM caller_marker')) === 1, 'Autocommit-off pending work retained'); mysqli_rollback($db); mysqli_autocommit($db, true); ajm_check(ajm_snapshot() === $before, 'Autocommit caller can still roll back'); $cases++;
    $migration_lock = 'plus_innodb_' . sha1($schema); ajm_sql("DO GET_LOCK('" . $migration_lock . "',0)"); $owner = ajm_rows("SELECT IS_USED_LOCK('" . $migration_lock . "') AS owner");
    ajm_denied(function () use ($db) { plus_album_file_journal_apply($db, 'fixture_', true, true); }, 'Do not adopt/release an already inherited migration lock'); ajm_check(ajm_rows("SELECT IS_USED_LOCK('" . $migration_lock . "') AS owner") === $owner, 'Inherited lock ownership preserved'); ajm_sql("DO RELEASE_LOCK('" . $migration_lock . "')"); $cases++;
    $peer = mysqli_connect('127.0.0.1', 'root', $password, $schema, (int) $port); ajm_check($peer && mysqli_set_charset($peer, 'utf8mb4'), 'Own separate migration contender'); plus_storage_query($peer, "DO GET_LOCK('" . $migration_lock . "',0)");
    ajm_denied(function () use ($db) { plus_album_file_journal_apply($db, 'fixture_', true, true); }, 'Foreign migration lock excludes DDL'); plus_storage_query($peer, "DO RELEASE_LOCK('" . $migration_lock . "')"); mysqli_close($peer); $peer = null;
    $other_lock = 'album_other_' . sha1($schema); ajm_sql("DO GET_LOCK('" . $other_lock . "',0)"); $owner = ajm_rows("SELECT IS_USED_LOCK('" . $other_lock . "') AS owner");
    ajm_check(plus_album_file_journal_apply($db, 'fixture_', true, true) === 0 && ajm_rows("SELECT IS_USED_LOCK('" . $other_lock . "') AS owner") === $owner, 'Unrelated caller named lock retained'); ajm_sql("DO RELEASE_LOCK('" . $other_lock . "')"); $cases++;
    foreach (array('ALTER TABLE fixture_album_file_jobs MODIFY binding_hash VARCHAR(255) NOT NULL', 'ALTER TABLE fixture_album_file_jobs ADD custom_data VARCHAR(16) NULL', 'ALTER TABLE fixture_album_file_jobs ADD KEY custom_actor(actor_id)', 'ALTER TABLE fixture_album_file_jobs DEFAULT COLLATE=utf8mb4_general_ci', 'ALTER TABLE fixture_album_file_jobs ENGINE=MyISAM', 'ALTER TABLE fixture_album_file_jobs ROW_FORMAT=COMPACT') as $custom) {
        ajm_drop(); plus_album_file_journal_apply($db, 'fixture_', true, true); ajm_seed(); ajm_sql($custom); $before = ajm_snapshot();
        ajm_denied(function () use ($db) { plus_album_file_journal_apply($db, 'fixture_', true, true); }, 'Unknown column/index/collation/engine/row-format not rewritten'); ajm_check(ajm_snapshot() === $before, 'Foreign definition and data retained byte-exact'); $cases++;
    }
    ajm_drop(); plus_album_file_journal_apply($db, 'fixture_', true, true); ajm_seed(); ajm_sql('DROP TABLE fixture_album_file_items'); $before_jobs = ajm_rows('SELECT * FROM fixture_album_file_jobs');
    ajm_check(plus_album_file_journal_apply($db, 'fixture_', true, true) === 1 && ajm_rows('SELECT * FROM fixture_album_file_jobs') === $before_jobs, 'Interrupted one-table creation resumes without rewriting existing receipt rows'); $cases++;
    ajm_drop(); plus_album_file_journal_apply($db, 'fixture_', true, true); ajm_seed(); ajm_sql('DROP TABLE fixture_album_file_jobs'); ajm_sql('ALTER TABLE fixture_album_file_items ADD custom_item INT NULL'); $before = ajm_snapshot();
    ajm_denied(function () use ($db) { plus_album_file_journal_apply($db, 'fixture_', true, true); }, 'All existing definitions checked before even first missing-table CREATE'); ajm_check(ajm_snapshot() === $before, 'Failed preflight did not partially create a table'); $cases++;
    ajm_drop(); ajm_sql('CREATE VIEW fixture_album_file_jobs AS SELECT 1 AS foreign_value'); $view = ajm_rows('SHOW CREATE VIEW fixture_album_file_jobs');
    ajm_denied(function () use ($db) { plus_album_file_journal_apply($db, 'fixture_', true, true); }, 'Never adopt or replace a foreign journal view');
    ajm_check(ajm_rows('SHOW CREATE VIEW fixture_album_file_jobs') === $view && plus_storage_metadata($db, 'fixture_album_file_items') === null, 'Foreign view preserved and missing peer table not created'); ajm_sql('DROP VIEW fixture_album_file_jobs'); $cases++;
    mysqli_set_charset($db, 'latin1'); ajm_denied(function () use ($db) { plus_album_file_journal_plan($db, 'fixture_'); }, 'Do not guess connection encoding'); mysqli_set_charset($db, 'utf8mb4');
    ajm_drop(); ajm_config('fixture_'); $before = ajm_snapshot(); $cli = ajm_cli(array('--album-file-journal-only'));
    ajm_check($cli[0] === 0 && $cli[2] === '' && strpos($cli[1], 'CREATE TABLE') !== false && ajm_snapshot() === $before, 'Real scoped updater dry run changes nothing'); $cases++;
    foreach (array(array('--album-file-journal-only','--apply'), array('--album-file-journal-only','--apply','--backup-confirmed'), array('--album-file-journal-only','--album-text-storage-only','--apply','--backup-confirmed','--maintenance-confirmed'), array('--album-file-journals-only','--apply','--backup-confirmed','--maintenance-confirmed')) as $flags) {
        $cli = ajm_cli($flags); ajm_check($cli[0] !== 0 && ajm_snapshot() === $before, 'Missing guards/conflicting modes/typo never fall through to broad update'); $cases++;
    }
    $cli = ajm_cli(array('--album-file-journal-only','--apply','--backup-confirmed','--maintenance-confirmed'));
    ajm_check($cli[0] === 0 && $cli[2] === '' && !plus_album_file_journal_plan($db, 'fixture_'), 'Real scoped updater creates two verified tables: ' . substr($cli[2], 0, 1000)); $cases++;
    ajm_seed(); $before = ajm_snapshot(); $cli = ajm_cli(array('--album-file-journal-only','--apply','--backup-confirmed','--maintenance-confirmed'));
    ajm_check($cli[0] === 0 && $cli[2] === '' && ajm_snapshot() === $before, 'Real scoped updater retry retains all existing identities/bytes'); $cases++;
    $schema_source = file_get_contents($root . '/phpBB2/install/schemas/mysql_schema.sql'); $imported = 0;
    foreach (split_sql_file(trim(remove_remarks($schema_source)), ';') as $sql) {
        $sql = trim($sql); if ($sql === '') { continue; }
        if (preg_match('/^ALTER TABLE\s+`?phpbb_users`?\s+ADD\b/i', $sql)) { ajm_sql(preg_replace('/\bphpbb_/', 'legacy_', $sql)); continue; }
        ajm_check(preg_match('/^CREATE TABLE\s+`?phpbb_([A-Za-z0-9_]+)`?\s*\(/', $sql, $table), 'Full-updater fixture imports only canonical CREATE statements and the users ADD extension: ' . substr($sql, 0, 160));
        if (in_array($table[1], array('album_file_jobs','album_file_items','pa_file_journal'), true)) { continue; }
        ajm_sql(preg_replace('/\bphpbb_/', 'legacy_', $sql)); $imported++;
    }
    ajm_check($imported > 80, 'Complete existing-forum schema fixture'); ajm_config('legacy_'); $before = ajm_snapshot();
    ajm_sql("ALTER TABLE legacy_album_rate MODIFY rate_hon_point TINYINT NOT NULL DEFAULT 0");
    ajm_sql("INSERT INTO legacy_album_rate VALUES(1,7,'7f000001',255,-128),(1,7,'7f000001',255,-128),(2,-1,'7f000002',0,127)");
    $votes_before = plus_album_rating_digest($db, 'legacy_album_rate');
    ajm_sql('ALTER TABLE legacy_pa_download_info MODIFY file_id MEDIUMINT NOT NULL DEFAULT 0,DROP INDEX file_actor,DROP INDEX file_address');
    ajm_sql('ALTER TABLE legacy_pa_cat MODIFY cat_files MEDIUMINT NOT NULL DEFAULT -1,MODIFY cat_last_file_id MEDIUMINT UNSIGNED NOT NULL DEFAULT 0');
    ajm_sql('ALTER TABLE legacy_pa_files DROP INDEX file_category_time');
    ajm_sql("INSERT INTO legacy_pa_files(file_id,real_name,file_dir,file_name,file_catid,file_approved)VALUES(42,'synthetic.zip','downloads/','Preserved download',7,1)");
    $download_files_before = plus_pa_download_digest($db, 'legacy_pa_files');
    ajm_sql("INSERT INTO legacy_pa_download_info(file_id,user_id,downloader_ip,downloader_os)VALUES(42,7,'7f000001','Preserved download'),(42,7,'7f000001','Preserved download')");
    $download_rows_before = plus_pa_download_digest($db, 'legacy_pa_download_info');
    ajm_sql("INSERT INTO legacy_album_cat(cat_id,cat_title,cat_desc,cat_order) VALUES(1,'One','Original category',1),(2,'Two','Current category',2)");
    ajm_sql("INSERT INTO legacy_album(pic_id,pic_filename,pic_title,pic_user_id,pic_user_ip,pic_time,pic_cat_id) VALUES(1,'owned.jpg','Moved',7,'7f000001',1,2),(2,'orphan.jpg','Missing category',7,'7f000001',1,999)");
    ajm_sql("INSERT INTO legacy_album_comment(comment_id,comment_pic_id,comment_cat_id,comment_user_id,comment_username,comment_user_ip,comment_time,comment_text) VALUES(1,1,1,-1,'Exact Guest','7f000001',1,'Original text'),(2,999,77,-1,'Orphan Guest','7f000001',2,'Missing picture'),(3,2,1,-1,'Unknown category','7f000001',3,'Missing category')");
    $comments_expected = plus_album_comment_parent_digest($db, 'legacy_', true);
    $cli = ajm_cli(array('--apply','--backup-confirmed','--maintenance-confirmed'));
    ajm_check($cli[0] === 0 && $cli[2] === '' && !plus_album_file_journal_plan($db, 'legacy_') && ajm_snapshot() === $before, 'Real full updater integrates journal schema while preserving other-prefix rows: ' . substr($cli[2], 0, 1400)); $cases++;
    ajm_check(!plus_pa_file_journal_plan($db, 'legacy_'), 'Real full updater also creates the canonical download file journal'); $cases++;
    ajm_check(!plus_pa_download_plan($db, 'legacy_') && plus_pa_download_digest($db, 'legacy_pa_download_info') === $download_rows_before, 'Real full updater widens download references/counters and preserves duplicate visitor rows'); $cases++;
    ajm_check(plus_pa_download_digest($db, 'legacy_pa_files') === $download_files_before && isset(plus_pa_download_indexes($db, 'legacy_pa_files')['file_category_time']), 'Real full updater adds category lookup index without altering file metadata'); $cases++;
    ajm_check(!plus_album_rating_plan($db, 'legacy_', true) && plus_album_rating_digest($db, 'legacy_album_rate') === $votes_before, 'Real full updater widens legacy HotOrNot scores and preserves duplicate/negative votes'); $cases++;
    ajm_check(!plus_album_comment_parent_plan($db, 'legacy_')['changes'] && plus_album_comment_parent_digest($db, 'legacy_', false) === $comments_expected, 'Real full updater reconciles current comment parents and preserves orphan/content rows'); $cases++;
    $cli = ajm_cli(array('--apply','--backup-confirmed','--maintenance-confirmed'));
    ajm_check($cli[0] === 0 && $cli[2] === '' && plus_album_rating_digest($db, 'legacy_album_rate') === $votes_before && ajm_snapshot() === $before, 'Repeated full updater preserves widened scores and unrelated journals'); $cases++;
    ajm_check(plus_album_comment_parent_digest($db, 'legacy_', false) === $comments_expected, 'Repeated full updater preserves repaired and orphan comment snapshots'); $cases++;
    ajm_check(!plus_pa_download_plan($db, 'legacy_') && plus_pa_download_digest($db, 'legacy_pa_download_info') === $download_rows_before, 'Repeated full updater preserves migrated download statistics'); $cases++;
    ajm_check(plus_pa_download_digest($db, 'legacy_pa_files') === $download_files_before, 'Repeated full updater preserves indexed download file metadata'); $cases++;
    echo 'Native Album file journal migration: ', $cases, " dry/apply/idempotence/modern-schema/guest/Unicode/row-preservation/caller-TX/locks/custom-refusal/partial-resume/real-scoped-and-full-updater cases PASS; own loopback schemas only, no image operations or live data.\n";
} finally {
    if ($peer) { mysqli_close($peer); }
    if ($created) { ajm_check(ajm_rows('SELECT DATABASE() AS name')[0]['name'] === $schema && preg_match('/^codex_album_journal_[a-f0-9]{16}$/D', $schema), 'Refuse unowned database cleanup'); ajm_sql('DROP DATABASE ' . plus_storage_identifier($schema)); }
    mysqli_close($db);
    if ($created_directory) { ajm_check(!is_link($directory) && realpath(dirname($directory)) === realpath(sys_get_temp_dir()) && basename($directory) === $schema, 'Own config cleanup'); foreach (array('/config.php') as $file) { if (is_file($directory . $file)) { unlink($directory . $file); } } rmdir($directory); }
}
