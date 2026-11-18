<?php
// Actual migration functions and CLI on owned schemas, using a SQL account
// with database-local privileges and no server-administration privileges.
if (PHP_SAPI !== 'cli' || getenv('PHPBB_UPDATER_SESSION_NATIVE') !== '1') { echo "Native updater sessions skipped.\n"; return; }
define('IN_PHPBB', true); $root = dirname(dirname(__DIR__)); $cases = $assertions = 0;
require $root . '/phpBB2/includes/php_compat.php';
require $root . '/update/album_file_journal_migration.php';
require $root . '/update/pafiledb_file_journal_migration.php';
require $root . '/update/pafiledb_download_storage_migration.php';
require $root . '/update/arcade_progress_migration.php';
require $root . '/update/post_tombstone_migration.php';
require $root . '/update/arcade_protocol_log_redaction.php';
mysqli_report(MYSQLI_REPORT_OFF);
function us_check($ok, $why) { if (!$ok) { throw new RuntimeException($why); } $GLOBALS['assertions']++; }
function us_query($db, $sql) { return plus_storage_query($db, $sql); }
function us_rows($db, $sql) { return plus_storage_rows($db, $sql); }
function us_denied($body) { try { $body(); } catch (Exception $error) { return true; } return false; }
function us_state($db) {
    $isolation = $GLOBALS['mysql'] ? 'transaction_isolation' : 'tx_isolation';
    return us_rows($db, 'SELECT CONNECTION_ID() id,@@SESSION.autocommit automatic,@@SESSION.sql_mode mode,@@SESSION.innodb_strict_mode strict_mode,@@SESSION.lock_wait_timeout ddl_wait,@@SESSION.innodb_lock_wait_timeout row_wait,@@SESSION.' . $isolation . ' isolation_level');
}
function us_data($db, $tables) {
    $out = array(); foreach ($tables as $table) { $out[$table] = plus_pa_download_digest($db, $table); } return $out;
}
function us_schema($db) {
    $out = array(); foreach (us_rows($db, 'SHOW TABLES') as $row) { $table = reset($row); $out[$table] = array(us_rows($db, 'SHOW CREATE TABLE ' . plus_storage_identifier($table)), plus_pa_download_digest($db, $table)); } ksort($out); return $out;
}
function us_apply($db, $kind) {
    if ($kind === 'storage') { return plus_storage_apply($db, array('fixture_legacy'), true, true); }
    if ($kind === 'album') { return plus_album_file_journal_apply($db, 'fixture_', true, true, 1); }
    if ($kind === 'journal') { return plus_pa_file_journal_apply($db, 'fixture_', true, true, 1); }
    if ($kind === 'download') { return plus_pa_download_apply($db, 'fixture_', true, true, 1); }
    if ($kind === 'progress') { return plus_arcade_progress_apply($db, 'fixture_', true, true, 1); }
    if ($kind === 'tombstones') { return plus_post_tombstone_apply($db, 'fixture_', true, true, 1); }
    if ($kind === 'logs') { return plus_arcade_log_redaction_apply($db, 'fixture_', true, true, 1); }
    throw new RuntimeException('Unknown own migration');
}
function us_install($db, $sql) {
    us_check(!preg_match('/^\s*(?:USE\s|(?:CREATE|DROP)\s+DATABASE\b|GRANT\s|DELIMITER\s)/mi', $sql), 'Installer confined to selected schema');
    us_check(mysqli_multi_query($db, $sql), 'Canonical installer started');
    do { if ($result = mysqli_store_result($db)) { mysqli_free_result($result); } if (!mysqli_more_results($db)) { break; } us_check(mysqli_next_result($db), 'Canonical installer continued'); } while (true);
}
function us_cli($arguments) {
    $parts = array(PHP_BINARY);
    if (DIRECTORY_SEPARATOR === chr(92)) { $parts = array_merge($parts, array('-n','-d','extension_dir=' . ini_get('extension_dir'),'-d','extension=php_mysqli.dll','-d','extension=php_openssl.dll')); }
    $parts = array_merge($parts, array('-d','date.timezone=UTC','-d','disable_functions=mail',$GLOBALS['root'] . '/update/update_from_153a.php','--config=' . $GLOBALS['config']), $arguments);
    $output = tempnam(sys_get_temp_dir(), 'codex-updater-out-'); $errors = tempnam(sys_get_temp_dir(), 'codex-updater-err-');
    try {
        us_check($output !== false && $errors !== false, 'Owned child outputs');
        $child = proc_open(implode(' ', array_map('escapeshellarg', $parts)), array(0=>array('pipe','r'),1=>array('file',$output,'w'),2=>array('file',$errors,'w')), $pipes, null, null, array('bypass_shell'=>true));
        us_check(is_resource($child), 'Actual updater child'); fclose($pipes[0]); $code = proc_close($child);
        return array($code, file_get_contents($output), file_get_contents($errors));
    } finally { foreach (array($output,$errors) as $file) { if ($file !== false && is_file($file)) { unlink($file); } } }
}
function us_config($prefix) {
    $body = "<?php\n"; foreach (array('dbhost'=>$GLOBALS['host'],'dbuser'=>$GLOBALS['account'],'dbpasswd'=>$GLOBALS['secret'],'dbname'=>$GLOBALS['schema'],'table_prefix'=>$prefix,'dbms'=>'mysqli') as $key=>$value) { $body .= '$' . $key . '=' . var_export($value, true) . ";\n"; }
    us_check(file_put_contents($GLOBALS['config'], $body) === strlen($body), 'Only owned SQL credentials in temporary config');
}
$port = getenv('PHPBB_UPDATER_SESSION_PORT') ?: '3306';
us_check(preg_match('/^[0-9]{1,5}$/D',$port) && (int)$port > 0 && (int)$port <= 65535, 'Loopback fixture port');
$password = getenv('PHPBB_UPDATER_SESSION_PASSWORD') ?: ''; $host = '127.0.0.1:' . $port;
$schema = 'codex_updater_session_' . bin2hex(phpbb_random_bytes(8)); $account = 'codex_us_' . bin2hex(phpbb_random_bytes(8)); $secret = bin2hex(phpbb_random_bytes(32));
$control = $db = $off = null; $config = null; $created = $account_created = false; $global_strict = null;
$kinds = array('storage','album','journal','download','progress','tombstones','logs');
set_error_handler(function ($severity,$message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } });
try {
    $control = mysqli_connect('127.0.0.1','root',$password,'',(int)$port); us_check($control && mysqli_set_charset($control,'utf8mb4'), 'Own native control');
    $version = us_rows($control,'SELECT VERSION() version')[0]['version']; $mysql = getenv('PHPBB_UPDATER_SESSION_MYSQL') === '8.4';
    us_check(!$mysql || (strpos($version,'8.4.') === 0 && stripos($version,'MariaDB') === false), 'Actual requested MySQL8.4 server');
    $legacy = @mysqli_query($control,'SELECT @@SESSION.in_transaction active');
    us_check($mysql ? ($legacy === false && mysqli_errno($control) === 1193) : ($legacy instanceof mysqli_result), 'Original Arcade identity variable compatibility');
    if ($legacy instanceof mysqli_result) { mysqli_free_result($legacy); } $cases++;
    us_query($control,'CREATE DATABASE ' . $schema . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); $created = true;
    us_check(mysqli_select_db($control,$schema), 'Selected owned schema');
    // Preserve the actual MySQL warning which used to reject creation of the
    // Download journal. The numeric range does not depend on display width.
    us_query($control,'CREATE TABLE own_legacy_width(actor_id MEDIUMINT(8)) ENGINE=InnoDB');
    $width_warnings=us_rows($control,'SHOW WARNINGS');$width_note=false;
    foreach($width_warnings as$warning){if((int)$warning['Code']===1681&&strpos($warning['Message'],'Integer display width is deprecated')!==false){$width_note=true;}}
    us_check($mysql?$width_note:!$width_warnings,'Original explicit integer width warning reproduced on actual backend');$cases++;
    us_query($control,'DROP TABLE own_legacy_width');
    us_query($control,"CREATE USER '" . $account . "'@'%' IDENTIFIED BY '" . $secret . "'"); $account_created = true;
    us_query($control,'GRANT ALL ON ' . $schema . ".* TO '" . $account . "'@'%'");
    $db = mysqli_connect('127.0.0.1',$account,$secret,$schema,(int)$port); us_check($db && mysqli_set_charset($db,'utf8mb4'), 'Database-local SQL account connects');
    $ddl = file_get_contents($root . '/phpBB2/install/schemas/mysql_schema.sql');
    foreach (array('users','ina_games','ina_scores','pa_download_info','pa_cat','pa_auth','pa_files','posts','posts_text','config','ina_log') as $suffix) {
        us_check(preg_match('/^CREATE TABLE phpbb_' . $suffix . '\s*\([\s\S]*?;/m',$ddl,$match) === 1, 'Canonical fixture definition');
        us_query($control,str_replace('phpbb_' . $suffix,'fixture_' . $suffix,$match[0]));
    }
    us_query($control,'CREATE TABLE caller_marker(id INT PRIMARY KEY,body VARCHAR(40)) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    us_query($control,'CREATE TABLE fixture_legacy(id INT PRIMARY KEY,body VARCHAR(80)) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $text = "Grüße 😀 O'Reilly \\ &amp;"; $literal = 'CONVERT(0x' . bin2hex($text) . ' USING utf8mb4)';
    us_query($control,'INSERT INTO fixture_legacy VALUES(0,' . $literal . '),(7,NULL)');
    us_query($control,"INSERT INTO fixture_users(user_id,username,user_password) VALUES(8,'Synthetic owner','')");
    us_query($control,"INSERT INTO fixture_ina_games(game_id,game_name) VALUES(41,'Own game')");
    us_query($control,'INSERT INTO fixture_ina_scores(game_name,player_id,score,gameData)VALUES(\'Own game\',8,12,' . $literal . ')');
    us_query($control,'ALTER TABLE fixture_pa_download_info MODIFY file_id MEDIUMINT NOT NULL DEFAULT 0');
    foreach (array('file_actor','file_address') as $index) { $keys = plus_pa_download_indexes($control,'fixture_pa_download_info'); if (isset($keys[$index])) { us_query($control,'ALTER TABLE fixture_pa_download_info DROP INDEX ' . plus_storage_identifier($index)); } }
    us_query($control,'ALTER TABLE fixture_pa_auth MODIFY cat_id SMALLINT UNSIGNED NOT NULL DEFAULT 0');
    us_query($control,'ALTER TABLE fixture_pa_cat MODIFY cat_files MEDIUMINT NOT NULL DEFAULT -1,MODIFY cat_last_file_id MEDIUMINT UNSIGNED NOT NULL DEFAULT 0');
    us_query($control,'INSERT INTO fixture_pa_auth(group_id,cat_id,auth_download)VALUES(22,65535,1),(22,65535,0)');
    us_query($control,'ALTER TABLE fixture_posts DROP COLUMN post_deleted,DROP COLUMN post_deleted_time,DROP COLUMN post_deleted_by');
    us_query($control,"INSERT INTO fixture_posts(post_id,topic_id,forum_id,poster_id,poster_ip,post_username)VALUES(7,1,1,8,'7f000001','Own historical author')");
    us_query($control,'INSERT INTO fixture_posts_text(post_id,post_subject,bbcode_uid,post_text)VALUES(7,\'Own historical post\',\'\',' . $literal . ')');
    us_query($control,"INSERT INTO fixture_ina_log(record_no,user_id,name,value,date)VALUES(1,8,'Own diagnostic','score=>12 session_id=>" . str_repeat('d',32) . " note=>preserved',123)");
    $post_before=us_rows($control,'SELECT post_id,topic_id,forum_id,poster_id,post_username FROM fixture_posts');
    $log_before=us_rows($control,'SELECT record_no,user_id,name,date FROM fixture_ina_log');
    us_query($control,'SET SESSION default_storage_engine=InnoDB'); us_query($db,'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $sources = array('caller_marker','fixture_legacy','fixture_users','fixture_ina_games','fixture_ina_scores','fixture_pa_download_info','fixture_pa_cat','fixture_pa_auth','fixture_pa_files','fixture_posts_text');
    foreach (array('','ANSI_QUOTES','STRICT_ALL_TABLES,NO_BACKSLASH_ESCAPES','TRADITIONAL') as $mode) {
        us_query($db,"SET SESSION sql_mode='" . $mode . "',lock_wait_timeout=7,innodb_lock_wait_timeout=8");
        foreach ($kinds as $kind) {
            $state = us_state($db); $data = us_data($control,$sources); $result = us_apply($db,$kind);
            us_check(us_state($db) === $state && us_data($control,$sources) === $data, 'Actual success preserves source rows and caller settings: ' . $kind);
            if ($kind === 'storage') { us_check(plus_storage_metadata($db,'fixture_legacy')['ENGINE'] === 'InnoDB', 'Actual engine conversion'); }
            if ($kind === 'progress') { us_check(us_rows($db,'SELECT game_data FROM fixture_ina_game_saves')[0]['game_data'] === $text, 'Actual historical checkpoint import'); }
            us_check(us_rows($db,'SELECT post_id,topic_id,forum_id,poster_id,post_username FROM fixture_posts')===$post_before,'Historical post fields preserved');
            if($kind==='logs'){$log=us_rows($db,'SELECT value FROM fixture_ina_log')[0]['value'];us_check(us_rows($db,'SELECT record_no,user_id,name,date FROM fixture_ina_log')===$log_before&&strpos($log,str_repeat('d',32))===false&&strpos($log,'[redacted')!==false,'Only diagnostic credential text changed');}
            $cases++;
        }
    }
    foreach ($kinds as $kind) {
        us_query($db,'START TRANSACTION'); us_query($db,"INSERT INTO caller_marker VALUES(1,'Pending original caller')");
        $state = us_state($db);
        us_check(us_denied(function () use ($db,$kind) { us_apply($db,$kind); }) && us_state($db) === $state, 'Active caller refused before session changes');
        us_check(count(us_rows($db,'SELECT * FROM caller_marker')) === 1 && !us_rows($control,'SELECT * FROM caller_marker'), 'Caller retains uncommitted work without commit or rollback');
        us_query($db,'ROLLBACK'); us_check(!us_rows($control,'SELECT * FROM caller_marker'), 'Caller still controls rollback'); $cases++;
        us_query($db,'SET autocommit=0'); us_query($db,"INSERT INTO caller_marker VALUES(1,'Pending implicit caller')"); $state = us_state($db);
        us_check(us_denied(function () use ($db,$kind) { us_apply($db,$kind); }) && us_state($db) === $state && count(us_rows($db,'SELECT * FROM caller_marker')) === 1 && !us_rows($control,'SELECT * FROM caller_marker'), 'Autocommit0 pending work retained');
        us_query($db,'ROLLBACK'); us_query($db,'SET autocommit=1'); $cases++;
    }
    $lock = 'plus_innodb_' . sha1($schema);
    foreach ($kinds as $kind) {
        us_query($db,"DO GET_LOCK('" . $lock . "',0)"); $owner = us_rows($db,"SELECT IS_USED_LOCK('" . $lock . "') owner");
        us_check(us_denied(function () use ($db,$kind) { us_apply($db,$kind); }) && us_rows($db,"SELECT IS_USED_LOCK('" . $lock . "') owner") === $owner, 'Inherited caller lease is neither adopted nor released');
        us_query($db,"DO RELEASE_LOCK('" . $lock . "')"); us_check(us_rows($db,"SELECT IS_FREE_LOCK('" . $lock . "') free")[0]['free'] === '1', 'No recursive lease reference leaked'); $cases++;
        us_query($control,"DO GET_LOCK('" . $lock . "',0)"); $state = us_state($db);
        try { us_check(us_denied(function () use ($db,$kind) { us_apply($db,$kind); }) && us_state($db) === $state, 'Peer lease denial restores caller settings'); $cases++; }
        finally { us_query($control,"DO RELEASE_LOCK('" . $lock . "')"); }
    }
    foreach (array(1,0,'1',null) as $bad) { us_check(us_denied(function () use ($db,$bad) { plus_storage_strict_mode($db,$bad); }), 'Strict setter requires booleans'); $cases++; }
    $global_strict = us_rows($control,'SELECT @@GLOBAL.innodb_strict_mode strict_mode')[0]['strict_mode'];
    us_check((string)$global_strict === '1', 'Disposable service starts strict');
    try {
        us_query($control,'SET GLOBAL innodb_strict_mode=OFF');
        $off = mysqli_connect('127.0.0.1',$account,$secret,$schema,(int)$port); us_check($off && mysqli_set_charset($off,'utf8mb4'), 'Own inherited-OFF connection');
        foreach ($kinds as $kind) {
            $state = us_state($off); $data = us_data($control,$sources);
            if ($mysql && !in_array($kind,array('tombstones','logs'),true)) { us_check(us_denied(function () use ($off,$kind) { us_apply($off,$kind); }), 'Ordinary MySQL cannot bypass disabled strictness'); }
            else { us_apply($off,$kind); }
            us_check(us_state($off) === $state && us_data($control,$sources) === $data, 'Strictness success/refusal preserves original OFF mode and rows'); $cases++;
        }
    } finally { if ($off) { mysqli_close($off); $off = null; } us_query($control,'SET GLOBAL innodb_strict_mode=' . (int)$global_strict); $global_strict = null; }
    $config = tempnam(sys_get_temp_dir(),'codex-updater-session-'); us_check($config !== false && chmod($config,0600), 'Owned temporary CLI config'); us_config('fixture_');
    foreach (array('--storage-only','--album-file-journal-only','--pafiledb-file-journal-only','--pafiledb-download-storage-only','--arcade-progress-only','--post-tombstones-only','--arcade-log-privacy-only') as $flag) {
        $before = us_schema($control); $child = us_cli(array($flag)); us_check($child[0] === 0 && $child[2] === '' && us_schema($control) === $before, 'Actual restricted CLI preview: ' . $flag . ': ' . $child[2]); $cases++;
        $child = us_cli(array($flag,'--apply','--backup-confirmed','--maintenance-confirmed')); us_check($child[0] === 0 && $child[2] === '' && us_schema($control) === $before, 'Actual restricted CLI repeat: ' . $flag . ': ' . $child[2]); $cases++;
    }
    us_install($control,$ddl); us_install($control,file_get_contents($root . '/phpBB2/install/schemas/mysql_basic.sql'));
    us_query($control,'ALTER TABLE phpbb_config ENGINE=MyISAM');
    us_query($control,'INSERT INTO phpbb_users(user_id,username,user_password,user_email)VALUES(900001,' . $literal . ",'','fixture@example.invalid')");
    us_query($control,"DELETE FROM phpbb_hacks_list WHERE hack_name='CrackerTracker Professional G5'");
    us_query($control,"INSERT INTO phpbb_hacks_list(hack_id,hack_add_date,hack_name,hack_desc,hack_author,hack_author_email,hack_author_website,hack_version,hack_hide,hack_download_url,hack_file,hack_file_mtime)VALUES(900001,123,'CrackerTracker Professional G5','Old description','Own author','fixture@example.invalid','','old','Yes','','own-legacy.hl',456)");
    $credits=us_rows($control,"SELECT hack_id,hack_add_date,hack_author_email,hack_hide,hack_download_url,hack_file,hack_file_mtime FROM phpbb_hacks_list WHERE hack_name='CrackerTracker Professional G5'");
    $user = us_rows($control,'SELECT username,user_email FROM phpbb_users WHERE user_id=900001');
    us_config('phpbb_'); $child = us_cli(array('--apply','--backup-confirmed','--maintenance-confirmed'));
    us_check($child[0] === 0 && $child[2] === '' && strpos($child[1],'Database update complete.') !== false, 'Actual full updater with database-local grants: ' . substr($child[2],0,900));
    us_check(plus_storage_metadata($control,'phpbb_config')['ENGINE'] === 'InnoDB' && us_rows($control,'SELECT username,user_email FROM phpbb_users WHERE user_id=900001') === $user, 'Full updater preserves UTF8 user fields and converts legacy engine'); $cases++;
    us_check(us_rows($control,"SELECT hack_id,hack_add_date,hack_author_email,hack_hide,hack_download_url,hack_file,hack_file_mtime FROM phpbb_hacks_list WHERE hack_name='CrackerTracker Professional G5'")===$credits&&us_rows($control,"SELECT hack_version FROM phpbb_hacks_list WHERE hack_name='CrackerTracker Professional G5'")[0]['hack_version']==='5.0.6','Credit refresh preserves original identity, visibility and dates while updating version');$cases++;
    $before = us_schema($control); $child = us_cli(array('--apply','--backup-confirmed','--maintenance-confirmed')); $after = us_schema($control);
    $differences=array();foreach(array_unique(array_merge(array_keys($before),array_keys($after)))as$table){if(!isset($before[$table],$after[$table])||$before[$table]!==$after[$table]){$differences[$table]=array('definition_before'=>isset($before[$table])?$before[$table][0]:null,'definition_after'=>isset($after[$table])?$after[$table][0]:null,'data_changed'=>isset($before[$table],$after[$table])&&$before[$table][1]!==$after[$table][1]);}}
    us_check($child[0] === 0 && $child[2] === '' && $after === $before, 'Actual full updater repeat preserves exact data/schema: ' . substr($child[2],0,900) . json_encode($differences)); $cases++;
    echo 'Native updater sessions: ', $cases, ' cases, ', $assertions, " assertions PASS; seven actual helpers, four SQL modes, ordinary database grants, active/implicit caller preservation, inherited/peer leases, strict OFF restoration, seven targeted CLIs, preserved credit IDs and complete full-updater retry.\n";
    if ($mysql) { echo "Native updater MySQL8.4: former Arcade variable fails1193; ordinary-account helpers, scoped CLIs and full updater pass without SQLadmin grants.\n"; }
} finally {
    restore_error_handler();
    if ($global_strict !== null && $control) { @mysqli_query($control,'SET GLOBAL innodb_strict_mode=' . (int)$global_strict); }
    if ($off) { mysqli_close($off); } if ($db) { @mysqli_query($db,'ROLLBACK'); mysqli_close($db); }
    if ($account_created && preg_match('/^codex_us_[a-f0-9]{16}$/D',$account)) { @mysqli_query($control,"DROP USER '" . $account . "'@'%'"); }
    if ($created && preg_match('/^codex_updater_session_[a-f0-9]{16}$/D',$schema)) { @mysqli_query($control,'DROP DATABASE ' . $schema); }
    if ($control) { mysqli_close($control); } if ($config !== null && is_file($config)) { unlink($config); }
}
