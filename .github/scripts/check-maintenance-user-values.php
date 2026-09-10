<?php
namespace MaintenanceUserValuesFixture;
// Execute the actual language/key repair sections without the live bootstrap.
$root = dirname(dirname(__DIR__)) . '/phpBB2/';
define('USERS_TABLE', 'fixture_users'); define('CONFIG_TABLE', 'fixture_config');
define('SESSIONS_KEYS_TABLE', 'fixture_keys'); define('ANONYMOUS', -1);
function check($ok, $message) { if (!$ok) { throw new \RuntimeException($message); } }
class RepairFailure extends \RuntimeException {}
function throw_error($message) { throw new RepairFailure($message); }
function dbmtnc_user_error($message) { throw new RepairFailure($message); }
function phpbb_realpath($path) { return $path; }
class Database {
 public $pdo; public $affected = 0; public $failure = ''; public $hook = null;
 function __construct($pdo) { $this->pdo = $pdo; }
 function sql_query($sql) {
  if (is_callable($this->hook)) { call_user_func($this->hook, $sql, $this); }
  if ($this->failure !== '' && strpos($sql, $this->failure) === 0) { return false; }
  try { $r = $this->pdo->query($sql); $this->affected = $r->rowCount(); return $r; }
  catch (\PDOException $e) { throw new RepairFailure('Invalid SQL in user repair'); }
 }
 function sql_fetchrow($r) { return $r->fetch(\PDO::FETCH_ASSOC); }
 function sql_freeresult($r) { $r->closeCursor(); }
 function sql_affectedrows() { return $this->affected; }
 function sql_escape($value) { return substr($this->pdo->quote($value), 1, -1); }
 function sql_write($sql) { return $this->sql_query($sql); }
}
function fragment($source, $start, $end) {
 $a = strpos($source, $start); $b = $a === false ? false : strpos($source, $end, $a);
 check($a !== false && $b > $a, 'Actual maintenance section found');
 return 'namespace MaintenanceUserValuesFixture;' . substr($source, $a, $b - $a);
}
$source = file_get_contents($root . 'admin/admin_db_maintenance.php');
$languageCode = fragment($source, '// Checking for invalid languages', '// Remove ban data without a valid user');
$keyCode = fragment($source, '// Remove session key data without valid user', '$db->actor();');
$dsn = getenv('PHPBB_MAINTENANCE_TEST_DSN'); $native = $dsn !== false && $dsn !== '';
if ($native) { check(preg_match('/^mysql:host=127\.0\.0\.1;port=33119;dbname=codex_maintenance_[a-f0-9]{16};charset=utf8mb4$/D', $dsn) === 1, 'Only owned loopback schema allowed'); }
$pdo = new \PDO($native ? $dsn : 'sqlite::memory:', $native ? 'root' : null, $native ? '' : null, array(\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION));
function run_code($code, $db, $lang, $path, $expectedFailure = false, $userLanguage = 'english') {
 $phpbb_root_path = $path; $phpEx = 'php'; $phpbb_version = array(0, 23);
 $userdata = array('user_lang' => $userLanguage); $list_open = false; $failed = false;
 ob_start();
 try { eval($code); } catch (RepairFailure $e) { $failed = true; }
 finally { $html = ob_get_clean(); }
 check($failed === $expectedFailure, 'Expected repair outcome');
 return $html;
}
function rows($pdo, $table, $order) { return $pdo->query('SELECT * FROM ' . $table . ' ORDER BY ' . $order)->fetchAll(\PDO::FETCH_ASSOC); }
set_error_handler(function($severity, $message) { if (error_reporting() & $severity) { throw new \RuntimeException($message); } });
try {
 foreach ($native ? array('MyISAM', 'InnoDB') : array('SQLite') as $engine) {
  foreach (array('english', 'german') as $locale) {
   $lang = array(); $mtnc = array(); $phpEx = 'php'; include $root . 'language/lang_' . $locale . '/lang_dbmtnc.php';
   foreach (array('users', 'config', 'keys') as $table) { $pdo->exec('DROP TABLE IF EXISTS fixture_' . $table); }
   $suffix = $native ? ' ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
   $pdo->exec('CREATE TABLE fixture_users (user_id INTEGER PRIMARY KEY, user_lang VARCHAR(255), username VARCHAR(255), user_active INTEGER)' . $suffix);
   $pdo->exec('CREATE TABLE fixture_config (config_name VARCHAR(255), config_value VARCHAR(255))' . $suffix);
   $pdo->exec('CREATE TABLE fixture_keys (key_id VARCHAR(32), user_id INTEGER, last_login INTEGER, PRIMARY KEY (key_id,user_id))' . $suffix);
   $pdo->exec("INSERT INTO fixture_config VALUES ('default_lang','german'),('board_disable','1')");
   $values = array(-1 => '<guest untouched>', 1 => 'english', 2 => 'german', 3 => "missing' OR 1=1 -- ",
    4 => '<img src=x onerror=alert(1)>', 5 => null, 6 => '', 7 => 'missing\\pack', 8 => 'English',
    9 => '../lang_english', 10 => 'english ', 11 => 'Grüße', 12 => 'changed-later');
   $insert = $pdo->prepare('INSERT INTO fixture_users VALUES (?,?,?,?)');
   foreach ($values as $id => $value) { $insert->execute(array($id, $value, 'Grüße ' . $id, 1)); }
   $before = rows($pdo, 'fixture_users', 'user_id'); $config = rows($pdo, 'fixture_config', 'config_name'); $db = new Database($pdo);
   $db->hook = function($sql, $db) {
    if (strpos($sql, 'UPDATE fixture_users') !== 0) { return; }
    $db->hook = null; $db->pdo->exec("UPDATE fixture_users SET user_lang='english' WHERE user_id=12");
   };
   $html = run_code($languageCode, $db, $lang, $root);
   foreach (rows($pdo, 'fixture_users', 'user_id') as $index => $row) {
    $id = (int)$row['user_id']; $expected = $id === -1 || $id === 1 || $id === 2 ? $values[$id] : ($id === 12 ? 'english' : 'german');
    check($row['user_lang'] === $expected, 'Only exact invalid language repaired: ' . $id);
    $copy = $row; $copy['user_lang'] = $before[$index]['user_lang']; check($copy === $before[$index], 'Profile/account fields untouched');
   }
   check(strpos($html, '<img') === false && strpos($html, '&lt;img') !== false, 'Stored markup escaped in translated output');
   check(rows($pdo, 'fixture_config', 'config_name') === $config, 'Configuration and board availability untouched');
   $stable = rows($pdo, 'fixture_users', 'user_id'); run_code($languageCode, $db, $lang, $root);
   check(rows($pdo, 'fixture_users', 'user_id') === $stable, 'Language repair idempotent');
   foreach (array('fallback', 'no-packs', 'write-failure', 'missing-config') as $case) {
    $pdo->exec("UPDATE fixture_users SET user_lang='broken' WHERE user_id=3");
    $pdo->exec("UPDATE fixture_config SET config_value='../../invalid' WHERE config_name='default_lang'");
    if ($case === 'missing-config') { $pdo->exec("DELETE FROM fixture_config WHERE config_name='default_lang'"); }
    $db->failure = $case === 'write-failure' ? 'UPDATE fixture_users' : '';
    run_code($languageCode, $db, $lang, $case === 'no-packs' ? $root . 'absent-fixture-root/' : $root, $case !== 'fallback', '../invalid');
    check($pdo->query('SELECT user_lang FROM fixture_users WHERE user_id=3')->fetchColumn() === ($case === 'fallback' ? 'english' : 'broken'), 'Fallback fails closed or selects installed English');
   }
   $db->failure = '';
   $insert = $pdo->prepare('INSERT INTO fixture_keys VALUES (?,?,?)'); $now = time();
   foreach (array(array('same-key',1,$now-10),array('same-key',900,$now-10),array('same-key',-1,$now-10),
    array('future-pair',2,$now-10),array('future-pair',3,$now+3600),array("quote'key",4,$now-10),
    array("quote'key",901,$now-10),array('repaired-later',902,$now-10),array('time-repaired',5,$now+3600)) as $row) { $insert->execute($row); }
   $db->hook = function($sql, $db) {
    if (strpos($sql, 'DELETE FROM fixture_keys') !== 0) { return; }
    $db->hook = null;
    $db->pdo->exec("INSERT INTO fixture_users VALUES (902,'english','Restored user',1)");
    $db->pdo->exec("UPDATE fixture_keys SET last_login=1 WHERE key_id='time-repaired'");
   };
   $html = run_code($keyCode, $db, $lang, $root);
   $keys = rows($pdo, 'fixture_keys', 'key_id,user_id');
   check(count($keys) === 5, 'Only four currently invalid composite keys deleted');
   foreach ($keys as $row) { check(in_array((int)$row['user_id'], array(1,2,4,5,902), true), 'Valid duplicate key identities and restored users survive'); }
   check(strpos($html, 'same-key') === false && strpos($html, "quote'key") === false, 'Login tokens never rendered');
   check(strpos($html, sprintf($lang['Affected_rows'], 4)) !== false, 'Accurate translated removal count');
   run_code($keyCode, $db, $lang, $root); check(rows($pdo, 'fixture_keys', 'key_id,user_id') === $keys, 'Key repair idempotent');
   $insert->execute(array('failure-orphan',999,$now)); $snapshot = rows($pdo, 'fixture_keys', 'key_id,user_id');
   $db->failure = 'DELETE FROM fixture_keys'; run_code($keyCode, $db, $lang, $root, true);
   check(rows($pdo, 'fixture_keys', 'key_id,user_id') === $snapshot, 'Failed deletion preserves keys');
   echo $engine . ' ' . $locale . " actual language and login-key repair passed.\n";
  }
 }
} finally { restore_error_handler(); }
