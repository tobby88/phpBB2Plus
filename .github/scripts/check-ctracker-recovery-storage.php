<?php
// Native fixture only: exercise the real driver, owned lock and restore SQL.
if (PHP_SAPI !== 'cli' || getenv('PHPBB_CT_RECOVERY_NATIVE') !== '1')
{ echo "Native CrackerTracker recovery fixture skipped.\n"; return; }
define('IN_PHPBB', true); define('CTRACKER_ACP', true);
define('CONFIG_TABLE', 'fixture_config'); define('CTRACKER_BACKUP', 'fixture_backup');
define('CTRACKER_FILECHK', 'fixture_filechk'); define('CTRACKER_FILESCANNER', 'fixture_filescanner');
define('CTRACKER_CONFIG', 'fixture_ct_config');
define('GENERAL_ERROR', 1); define('GENERAL_MESSAGE', 2); define('CRITICAL_ERROR', 3); define('END_TRANSACTION', 2);
define('USERS_TABLE', 'fixture_users'); define('SESSIONS_TABLE', 'fixture_sessions'); define('JR_ADMIN_TABLE', 'fixture_jr_admin'); define('ADMIN', 1);
class NativeRecoveryExit extends RuntimeException {}
function message_die($level, $message) { throw new NativeRecoveryExit($message); }
function recovery_check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
$source = dirname(dirname(__DIR__)) . '/phpBB2/';
require $source . 'db/mysqli.php';
require $source . 'ctracker/classes/class_ct_adminfunctions.php';
$port = getenv('PHPBB_CT_RECOVERY_TEST_PORT') ?: '3306';
recovery_check(preg_match('/^[0-9]{1,5}$/D', $port) && (int)$port > 0 && (int)$port <= 65535, 'Fixture port');
$host = '127.0.0.1:' . $port;
$password = getenv('PHPBB_CT_RECOVERY_TEST_PASSWORD') ?: '';
$control = new sql_db($host, 'root', $password, '', false);
recovery_check($control->db_connect_id, 'Isolated loopback fixture connection');
$fixture = 'codex_ct_recovery_' . bin2hex(function_exists('random_bytes') ? random_bytes(8) : openssl_random_pseudo_bytes(8));
recovery_check($control->sql_query('CREATE DATABASE ' . $fixture . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'), 'Create owned fixture');
$db = new sql_db($host, 'root', $password, $fixture, false);
$root = sys_get_temp_dir() . '/' . $fixture;
mkdir($root, 0700); mkdir($root . '/cache', 0700);
$phpbb_root_path = $root . '/'; $phpEx = 'php';
$lang = array('ctracker_error_database_op' => 'database', 'ctracker_error_loading_config' => 'load',
 'ctracker_rec_never_saved' => 'empty', 'ctracker_rec_empty_source' => 'empty', 'ctracker_recovery_busy' => 'busy',
 'ctracker_scan_busy' => 'busy', 'ctracker_rec_transaction_required' => 'engine',
 'ctracker_error_storage_migration' => 'migration', 'ctracker_error_fileop' => 'file');
function recovery_sql($sql) { $r = $GLOBALS['db']->sql_query($sql); recovery_check($r, 'Fixture SQL failed'); return $r; }
function recovery_values() {
 $r = recovery_sql('SELECT config_name,config_value FROM fixture_config'); $values = array();
 while ($row = $GLOBALS['db']->sql_fetchrow($r)) { $values[$row['config_name']] = $row['config_value']; }
 $GLOBALS['db']->sql_freeresult($r); ksort($values); return $values;
}
function recovery_value($table, $name, $value) {
 $db = $GLOBALS['db'];
 recovery_sql('INSERT INTO ' . $table . " VALUES ('" . $db->sql_escape($name) . "','" . $db->sql_escape($value) . "') ON DUPLICATE KEY UPDATE config_value=VALUES(config_value)");
}
set_error_handler(function($severity, $message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } });
try {
 recovery_sql('CREATE TABLE fixture_users (user_id INT PRIMARY KEY,user_level INT,user_active INT) ENGINE=InnoDB');
 recovery_sql('CREATE TABLE fixture_sessions (session_id VARCHAR(32) PRIMARY KEY,session_user_id INT,session_logged_in INT,session_admin INT) ENGINE=InnoDB');
 recovery_sql('CREATE TABLE fixture_jr_admin (user_id INT PRIMARY KEY,user_jr_admin TEXT) ENGINE=InnoDB');
 recovery_sql('INSERT INTO fixture_users VALUES (1,1,1)');
 recovery_sql("INSERT INTO fixture_sessions VALUES ('fixture-admin',1,1,1)");
 $userdata=array('user_id'=>1,'session_id'=>'fixture-admin','session_logged_in'=>1,'session_admin'=>1);
 $_SERVER['REQUEST_METHOD']='POST'; $_POST=array('sid'=>'fixture-admin');
 $schema = file_get_contents($source . 'install/schemas/mysql_schema.sql');
 foreach (array('config', 'ctracker_config', 'ctracker_filechk', 'ctracker_filescanner') as $table) {
  recovery_check(preg_match('/CREATE TABLE `?phpbb_' . $table . '`?\s*\([\s\S]*?;/', $schema, $m) === 1, 'Canonical schema');
  recovery_sql(str_replace('phpbb_' . $table, $table === 'config' ? CONFIG_TABLE : ($table === 'ctracker_config' ? CTRACKER_CONFIG : 'fixture_' . substr($table, 9)), $m[0]));
 }
 // Match the real CrackerTracker bootstrap, which removes the public password.
 unset($db->password);
 $lock = new ct_scan_lock($db, CTRACKER_BACKUP);
 recovery_check($lock->acquired === 1 && $lock->connection !== $db, 'Lock works after public credentials were removed');
 recovery_check(!isset($db->password) && !isset($lock->connection->password), 'Neither connection re-exposes credentials');
 $first = $db->sql_fetchrow($db->sql_query('SELECT CONNECTION_ID() AS id'));
 $second = $lock->connection->sql_fetchrow($lock->connection->sql_query('SELECT CONNECTION_ID() AS id'));
 recovery_check($first['id'] !== $second['id'], 'Independent non-pooled lock session');
 $busy = new ct_scan_lock($db, CTRACKER_BACKUP);
 recovery_check($busy->acquired === 0 && $busy->connection === null, 'Actual competing lock refused');
 $lock->release(); $lock->release();
 $admin = new ct_adminfunctions();
 file_put_contents($root . '/fixture.php', "<?php define('IN_PHPBB',true); echo 'fixture';");
 $admin->do_filechk(); $admin->RunFileScan($phpbb_root_path, 'php');
 foreach (array(CTRACKER_FILECHK, CTRACKER_FILESCANNER) as $table) {
  $r = $db->sql_fetchrow(recovery_sql('SELECT COUNT(*) AS n FROM ' . $table)); recovery_check((int)$r['n'] === 1, 'Scan published with private credentials');
 }
 $protected = array('board_disable', 'version', 'xs_version', 'dbmtnc_rebuild_job', 'dbmtnc_rebuild_pos',
  'dbmtnc_rebuild_end', 'dbmtnc_orphan_recovery_token');
 foreach ($protected as $name) { recovery_value(CONFIG_TABLE, $name, 'old'); }
 recovery_value(CONFIG_TABLE, 'site_desc', "Saved Grüße 😀 O'Brien");
 recovery_value(CONFIG_TABLE, 'dbmtnc_rebuildcfg_timelimit', '240');
 $admin->recover_configuration();
 foreach ($protected as $name) { recovery_value(CONFIG_TABLE, $name, 'current'); }
 recovery_value(CONFIG_TABLE, 'site_desc', 'modified'); recovery_value(CONFIG_TABLE, 'new_setting', 'keep');
 $expected = recovery_values(); $expected['site_desc'] = "Saved Grüße 😀 O'Brien";
 $admin->restore_configuration();
 recovery_check(recovery_values() === $expected, 'Configuration restore must not rewind internal state');
 // Old or custom backups can use different collation, case, accents or padding.
 recovery_sql('ALTER TABLE fixture_backup CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');
 foreach ($protected as $name) {
  recovery_value(CTRACKER_BACKUP, strtoupper($name), 'stale-upper');
  recovery_value(CTRACKER_BACKUP, str_replace('e', 'é', $name) . ' ', 'stale-accent');
 }
 $admin->restore_configuration();
 recovery_check(recovery_values() === $expected, 'Target-collation aliases must not bypass protected keys');
 // An absent internal key must not be resurrected from a historical backup.
 foreach ($protected as $name) { recovery_sql("DELETE FROM fixture_config WHERE config_name='" . $name . "'"); }
 $expected = recovery_values(); $admin->restore_configuration();
 recovery_check(recovery_values() === $expected, 'Absent state is not restored either');
 // A later SQL failure rolls back all normal settings as before.
 recovery_value(CONFIG_TABLE, 'site_desc', 'before-failure');
 $expected = recovery_values();
 recovery_sql("CREATE TRIGGER fixture_fail BEFORE UPDATE ON fixture_config FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture interruption'");
 try { $admin->restore_configuration(); throw new RuntimeException('SQL failure accepted'); }
 catch (NativeRecoveryExit $e) { recovery_check($e->getMessage() === 'database', 'Expected rollback'); }
 recovery_check(recovery_values() === $expected, 'Failed restore leaves all settings intact');
 recovery_sql('DROP TRIGGER fixture_fail');
 recovery_sql("DELETE FROM fixture_backup WHERE config_name NOT IN ('version','ct_last_backup')");
 try { $admin->restore_configuration(); throw new RuntimeException('Metadata-only backup accepted'); }
 catch (NativeRecoveryExit $e) { recovery_check($e->getMessage() === 'empty', 'No restorable settings'); }
 recovery_check(recovery_values() === $expected, 'Metadata-only backup does not modify configuration');
 echo 'Native CrackerTracker credentials, scanners and protected recovery state passed on PHP ' . PHP_VERSION . "\n";
} finally {
 if (isset($lock)) { $lock->release(); }
 $db->sql_close(); $control->sql_query('DROP DATABASE ' . $fixture); $control->sql_close();
 foreach (array('/fixture.php', '/cache/config_data.cache') as $file) { if (is_file($root . $file)) { unlink($root . $file); } }
 rmdir($root . '/cache'); rmdir($root); restore_error_handler();
}
