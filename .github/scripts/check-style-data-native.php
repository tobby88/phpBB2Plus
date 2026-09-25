<?php
if (PHP_SAPI !== 'cli' || getenv('PHPBB_STYLE_DATA_NATIVE') !== '1') { echo "Style data checks require an explicitly enabled disposable database.\n"; return; }
define('IN_PHPBB', true); define('ADMIN', 1); define('GENERAL_ERROR', 2); define('GENERAL_MESSAGE', 1);
define('THEMES_TABLE', 'fixture_themes'); define('THEMES_NAME_TABLE', 'fixture_names');
define('USERS_TABLE', 'fixture_users'); define('SESSIONS_TABLE', 'fixture_sessions'); define('JR_ADMIN_TABLE', 'fixture_jr');
define('ATTACHMENTS_TABLE', 'fixture_attachments');
if (!defined('CONFIG_TABLE')) { define('CONFIG_TABLE', 'fixture_config'); }
$sd_source = dirname(dirname(__DIR__)) . '/phpBB2/'; $phpEx = 'php';
require $sd_source . 'includes/php_compat.php';
require $sd_source . 'includes/functions_acl_storage.php';
require $sd_source . 'includes/functions_jr_admin.php';
require $sd_source . 'db/mysqli.php';
class StyleDataExit extends RuntimeException {}
function message_die($level, $message) { throw new StyleDataExit($message); }
function xs_error($message) { throw new StyleDataExit('error'); }
function xs_message($title, $message) { throw new StyleDataExit('saved'); }
function append_sid($url) { return $url; }
function xs_in_array($value, $values) { return in_array($value, $values, true); }
function xs_sql($value) { return str_replace("\\'", "''", addslashes($value)); }
class StyleDataTemplate { var $xs_version = 8; function assign_block_vars($name, $data) {} }
$template = new StyleDataTemplate(); $lang = array('Information' => 'Information'); require $sd_source . 'language/lang_english/lang_xs.php';
$bootstrap = file_get_contents($sd_source . 'admin/pagestart.php');
$a = strpos($bootstrap, "if (!function_exists('phpbb_admin_post_session_valid'))");
$b = strpos($bootstrap, 'if (empty($no_page_header))', $a); eval(substr($bootstrap, $a, $b - $a));
function sd_check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
$port = getenv('PHPBB_STYLE_DATA_PORT') ?: '3306'; $password = getenv('PHPBB_STYLE_DATA_PASSWORD') ?: '';
sd_check(preg_match('/^[0-9]{1,5}$/D', $port) && (int)$port > 0 && (int)$port <= 65535, 'Loopback port');
$host = '127.0.0.1:' . $port; $control = new sql_db($host, 'root', $password, '', false);
$fixture = 'codex_style_data_' . bin2hex(function_exists('random_bytes') ? random_bytes(8) : openssl_random_pseudo_bytes(8));
sd_check($control->sql_query('CREATE DATABASE ' . $fixture . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'), 'Own schema');
class StyleDataConnection {
 var $inner; var $db_connect_id;
 function __construct($inner) { $this->inner = $inner; $this->db_connect_id = $inner->db_connect_id; }
 function __call($method, $args) { return call_user_func_array(array($this->inner, $method), $args); }
 function sql_query($sql, $transaction = false) {
  $GLOBALS['sd_queries'][] = $sql;
  if (is_callable($GLOBALS['sd_hook'])) { call_user_func($GLOBALS['sd_hook'], $sql, $this); }
  if ($GLOBALS['sd_failure'] !== '' && strpos($sql, $GLOBALS['sd_failure']) === 0) { return false; }
  $r = $this->inner->sql_query($sql, $transaction);
  if ($sql === 'COMMIT' && $r && is_callable($GLOBALS['sd_after_commit'])) { call_user_func($GLOBALS['sd_after_commit'], $this); }
  if ($sql === 'COMMIT' && $GLOBALS['sd_failure'] === 'lost-ack') { return false; }
  return $r;
 }
}
class StyleDataDatabase extends StyleDataConnection {
 var $dbname;
 function __construct($inner) { parent::__construct($inner); $this->dbname = $inner->dbname; }
 function sql_dedicated_connection() { return new StyleDataConnection($this->inner->sql_dedicated_connection()); }
}
$db = new StyleDataDatabase(new sql_db($host, 'root', $password, $fixture, false));
$peer = new sql_db($host, 'root', $password, $fixture, false);
$sd_hook = null; $sd_failure = ''; $sd_queries = array(); $sd_after_commit = null;
function sd_sql($sql) { $r = $GLOBALS['peer']->sql_query($sql); sd_check($r, 'Fixture SQL: ' . $sql); return $r; }
function sd_snapshot() { return array(phpbb_acl_rows($GLOBALS['peer'], 'SELECT * FROM fixture_themes ORDER BY themes_id'), phpbb_acl_rows($GLOBALS['peer'], 'SELECT * FROM fixture_names ORDER BY themes_id')); }
function sd_boundary($sql) { return preg_match('/^(UPDATE fixture_(themes|names) |INSERT INTO fixture_names )/', $sql) || strpos($sql, ' LOCK IN SHARE MODE') !== false || $sql === 'COMMIT'; }
function sd_revoke($kind) {
 $sql = array('inactive' => 'UPDATE fixture_users SET user_active=0', 'role' => 'UPDATE fixture_users SET user_level=0', 'grant' => 'DELETE FROM fixture_jr',
  'missing' => 'DELETE FROM fixture_sessions', 'admin-off' => 'UPDATE fixture_sessions SET session_admin=0', 'foreign' => 'UPDATE fixture_sessions SET session_user_id=99',
  'logout' => 'UPDATE fixture_sessions SET session_logged_in=0', 'case' => "UPDATE fixture_sessions SET session_id='FIXTURE-ADMIN'"); return $sql[$kind];
}
function sd_unlocked() {
 // COM_QUIT is asynchronous: wait for the server to process the closed socket,
 // not for another PHP request or shutdown handler to release ownership.
 $name = 'attachment:' . md5($GLOBALS['fixture'] . "\0" . ATTACHMENTS_TABLE);
 $rows = phpbb_acl_rows($GLOBALS['peer'], "SELECT GET_LOCK('" . $name . "', 2) AS available");
 sd_check((int)$rows[0]['available'] === 1, 'Dedicated named owner released');
 phpbb_acl_rows($GLOBALS['peer'], "SELECT RELEASE_LOCK('" . $name . "')");
}
function sd_reset($actor = 'root', $labels = true) {
 global $userdata, $sd_hook, $sd_failure, $sd_queries, $sd_after_commit;
 $sd_hook = null; $sd_failure = ''; $sd_queries = array(); $sd_after_commit = null;
 foreach (array(THEMES_TABLE, THEMES_NAME_TABLE, USERS_TABLE, SESSIONS_TABLE, JR_ADMIN_TABLE) as $table) { sd_sql('DELETE FROM ' . $table); }
 sd_sql("INSERT INTO fixture_themes (themes_id,template_name,style_name,tr_color1,theme_public) VALUES (1,'fisubsilversh','Before','aaaaaa',1),(2,'fisubsilversh','Other','bbbbbb',1)");
 if ($labels) { sd_sql("INSERT INTO fixture_names (themes_id,tr_color1_name) VALUES (1,'Before label')"); }
 sd_sql('INSERT INTO fixture_users VALUES (1,' . ($actor === 'root' ? 1 : 0) . ',1)');
 sd_sql("INSERT INTO fixture_sessions VALUES ('fixture-admin',1,1,1)");
 if ($actor === 'delegated') { $hash = array_search('xs_frameset.php', jr_admin_authorization_routes(), true); sd_check($hash !== false, 'Real XS grant'); sd_sql("INSERT INTO fixture_jr VALUES (1,'" . $hash . "')"); }
 $userdata = array('user_id' => 1, 'session_id' => 'fixture-admin', 'session_admin' => 1, 'session_logged_in' => 1, 'user_level' => $actor === 'root' ? 1 : 0);
 $_SERVER['REQUEST_METHOD'] = 'POST';
 file_put_contents($GLOBALS['sd_root'] . '/cache/themes.cache', 'old cache');
}
function sd_run($request) {
 global $db, $phpbb_root_path, $phpEx, $lang, $template, $userdata, $HTTP_POST_VARS, $HTTP_GET_VARS;
 $_POST = $HTTP_POST_VARS = array_merge(array('sid' => 'fixture-admin', 'edit' => '1'), $request); $HTTP_GET_VARS = array();
 try { include $GLOBALS['sd_source'] . 'admin/xs_edit_data.php'; throw new RuntimeException('Controller did not terminate'); }
 catch (StyleDataExit $e) { return $e->getMessage(); }
}
$sd_root = sys_get_temp_dir() . '/' . $fixture; $sd_previous = getcwd(); $sd_files = array(); $sd_dirs = array();
try {
 set_error_handler(function($severity, $message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } });
 foreach (array('', 'admin', 'includes', 'cache') as $dir) { $path = $sd_root . ($dir === '' ? '' : '/' . $dir); mkdir($path, 0700); $sd_dirs[] = $path; }
 foreach (array('extension.inc' => "<?php \$phpEx='php';", 'admin/pagestart.php' => '<?php /* Request-entry identity supplied by fixture. */', 'admin/xs_include.php' => '<?php /* Rendering bootstrap only; real controller and SQL are executed. */',
  'includes/functions_style_data.php' => '<?php require_once ' . var_export($sd_source . 'includes/functions_style_data.php', true) . ';') as $file => $body) {
  file_put_contents($sd_root . '/' . $file, $body); $sd_files[] = $sd_root . '/' . $file;
 }
 chdir($sd_root . '/admin');
 $schema = file_get_contents($sd_source . 'install/schemas/mysql_schema.sql');
 foreach (array('phpbb_themes' => THEMES_TABLE, 'phpbb_themes_name' => THEMES_NAME_TABLE) as $source => $target) {
  sd_check(preg_match('/CREATE TABLE ' . $source . '\s*\([\s\S]*?;/', $schema, $m) === 1, 'Canonical theme schema'); sd_sql(str_replace($source, $target, $m[0]));
 }
 foreach (array(USERS_TABLE => 'user_id INT PRIMARY KEY,user_level INT,user_active INT', SESSIONS_TABLE => 'session_id VARCHAR(32) PRIMARY KEY,session_user_id INT,session_logged_in INT,session_admin INT', JR_ADMIN_TABLE => 'user_id INT PRIMARY KEY,user_jr_admin TEXT') as $table => $columns) {
  sd_sql('CREATE TABLE ' . $table . ' (' . $columns . ') ENGINE=InnoDB ROW_FORMAT=DYNAMIC');
 }
 sd_sql('SET SESSION innodb_lock_wait_timeout=1');
 if (getenv('PHPBB_STYLE_DATA_PROBE') === '1') {
  sd_reset(); $before = sd_snapshot(); $sd_failure = 'UPDATE fixture_names ';
  $out = sd_run(array('edit_style_name' => 'Changed', 'edit_tr_color1' => '123456', 'name_tr_color1' => 'Changed label'));
  $after = sd_snapshot(); sd_check($out === 'error' && $after[0] !== $before[0] && $after[1] === $before[1], 'Reproduce partially persisted actual editor');
  echo "Reproduced: failed label write leaves new style properties persisted and old labels/cache intact.\n";
 } else {
  sd_check(preg_match('/CREATE TABLE phpbb_config\s*\([\s\S]*?;/', $schema, $config_definition) === 1, 'Canonical default-style configuration');
  sd_sql(str_replace('phpbb_config', CONFIG_TABLE, $config_definition[0]));
  sd_sql("INSERT INTO fixture_config VALUES ('default_style','1')");
  $cases = 0; $serialized = 0;
  $request = array('edit_style_name' => addslashes("Änderung ' \\ 😀"), 'edit_tr_color1' => '123456', 'name_tr_color1' => addslashes("Grün ' \\ 😀"));
  foreach (array('root', 'delegated') as $actor) { foreach (array(true, false) as $labels) {
   sd_reset($actor, $labels); $before = sd_snapshot(); sd_check(sd_run($request) === 'saved', 'Actual controller save ' . $actor); $expected = sd_snapshot();
   sd_check($expected[0][0]['style_name'] === stripslashes($request['edit_style_name']) && $expected[1][0]['tr_color1_name'] === stripslashes($request['name_tr_color1']), 'Exact UTF-8 and single decoding');
   sd_check($expected[0][1] === $before[0][1] && !is_file($sd_root . '/cache/themes.cache'), 'Other style unchanged and cache evicted'); sd_unlocked(); $cases++;
   $boundaries = array_values(array_filter($sd_queries, 'sd_boundary'));
   foreach (array('inactive', 'missing', 'admin-off', 'foreign', 'logout', 'case', $actor === 'root' ? 'role' : 'grant') as $kind) {
    sd_reset($actor, $labels); sd_sql(sd_revoke($kind)); $before = sd_snapshot();
    sd_check(sd_run($request) === 'error' && sd_snapshot() === $before, 'Entry revocation denies complete save'); sd_unlocked(); $cases++;
   }
   foreach (array('missing', $actor === 'root' ? 'role' : 'grant') as $kind) { for ($boundary = 1; $boundary <= count($boundaries); $boundary++) {
    sd_reset($actor, $labels); $before = sd_snapshot(); $seen = 0; $reached = false; $blocked = false;
    $sd_hook = function($sql) use ($boundary, $kind, &$seen, &$reached, &$blocked) {
     if (!sd_boundary($sql) || ++$seen !== $boundary) { return; } $GLOBALS['sd_hook'] = null; $reached = true;
     if (!$GLOBALS['peer']->sql_query(sd_revoke($kind))) { $error = $GLOBALS['peer']->sql_error(); sd_check((int)$error['code'] === 1205, 'Only real lock timeout serializes'); $blocked = true; }
    };
    $out = sd_run($request); sd_check($reached, 'Every native boundary exercised');
    if ($blocked) { sd_check($out === 'saved' && sd_snapshot() === $expected, 'Commit precedes serialized revocation'); sd_sql(sd_revoke($kind)); $serialized++; }
    else { sd_check($out === 'error' && sd_snapshot() === $before, 'Revocation rolls back paired style save: ' . $actor . '/' . $kind . '/' . $boundary); }
    sd_check(sd_run($request) === 'error', 'Following revoked request denied'); sd_unlocked(); $cases++;
   } }
   foreach (array('UPDATE fixture_themes ', $labels ? 'UPDATE fixture_names ' : 'INSERT INTO fixture_names ', 'COMMIT', 'lost-ack') as $failure) {
    sd_reset($actor, $labels); $before = sd_snapshot(); $sd_failure = $failure;
    sd_check(sd_run($request) === 'error' && sd_snapshot() === ($failure === 'lost-ack' ? $expected : $before), 'Failure atomicity and unconfirmed commit: ' . $failure);
    sd_check(!is_file($sd_root . '/cache/themes.cache'), 'Attempted/uncertain save evicts stale cache'); sd_unlocked(); $cases++;
    if ($failure === 'lost-ack') { $sd_failure = ''; sd_check(sd_run($request) === 'saved' && sd_snapshot() === $expected, 'Retry reuses committed labels without duplicates'); sd_unlocked(); $cases++; }
   }
   sd_reset($actor, $labels); $before = sd_snapshot();
   $sd_hook = function($sql, $connection) { if (strpos($sql, 'UPDATE fixture_themes ') === 0) { $GLOBALS['sd_hook'] = null; sd_sql('KILL CONNECTION ' . mysqli_thread_id($connection->db_connect_id)); } };
   sd_check(sd_run($request) === 'error' && sd_snapshot() === $before, 'Connection loss cannot continue without lock/transaction'); sd_unlocked(); $cases++;
   echo $actor . '/' . ($labels ? 'existing' : 'new') . " labels: native boundaries passed\n";
  } }
  foreach (array('missing', 'role', 'disconnect') as $change) {
   sd_reset(); $after_queries = array();
   $sd_after_commit = function($connection) use ($change, &$after_queries) {
    if ($change === 'disconnect') { sd_sql('KILL CONNECTION ' . mysqli_thread_id($connection->db_connect_id)); }
    else { sd_sql(sd_revoke($change)); }
    $GLOBALS['sd_hook'] = function($sql) use (&$after_queries) { $after_queries[] = $sql; };
   };
   sd_check(sd_run($request) === 'saved' && !$after_queries, 'Acknowledged commit remains successful after ' . $change); sd_unlocked(); $cases++;
  }
  foreach (array(array('edit_style_name' => array('bad')), array('edit_style_name' => "\xc3"), array('name_tr_color1' => array('bad')),
   array('name_tr_color1' => "\xc3"), array('edit_style_name' => addslashes("a\0b")), array('edit' => '1 OR 1=1'), array('edit' => array('1')),
   array('sid' => array('bad')), array('sid' => 'wrong'), array('edit_fontsize1' => '128'), array('edit_theme_public' => '2')) as $bad) {
   sd_reset(); $before = sd_snapshot(); sd_check(sd_run(array_merge($request, $bad)) !== 'saved' && sd_snapshot() === $before, 'Invalid full form leaves all values unchanged');
   sd_check(!array_filter($sd_queries, function($sql) { return preg_match('/^(UPDATE|INSERT|DELETE)\b/', $sql); }), 'No data writes before complete validation'); sd_unlocked(); $cases++;
  }
  foreach (array(THEMES_TABLE, THEMES_NAME_TABLE, USERS_TABLE, SESSIONS_TABLE, JR_ADMIN_TABLE) as $table) {
   foreach (array('ENGINE=MyISAM', 'ROW_FORMAT=COMPACT', 'CONVERT TO CHARACTER SET latin1') as $ddl) {
    sd_reset(); sd_sql('ALTER TABLE ' . $table . ' ' . $ddl); $before = sd_snapshot();
    sd_check(sd_run($request) === 'error' && sd_snapshot() === $before, 'Non-migrated table fails closed: ' . $table . '/' . $ddl); sd_unlocked();
    sd_sql('ALTER TABLE ' . $table . ' ENGINE=InnoDB ROW_FORMAT=DYNAMIC, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); $cases++;
   }
  }
  sd_reset(); sd_sql('ALTER TABLE fixture_names MODIFY tr_color1_name CHAR(50) CHARACTER SET latin1'); $before = sd_snapshot();
  sd_check(sd_run($request) === 'error' && sd_snapshot() === $before, 'Single old-encoding column refused');
  sd_sql('ALTER TABLE fixture_names CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); $cases++;
  sd_reset(); $full = array(); $theme = sd_snapshot();
  foreach (xs_get_vars($theme[0][0]) as $field => $definition) { $full['edit_' . $field] = $theme[0][0][$field] === null ? '' : addslashes((string)$theme[0][0][$field]); }
  foreach (xs_empty_name($peer) as $column => $ignored) { $full['name_' . substr($column, 0, -5)] = addslashes("Ä 😀 "); }
  sd_check(sd_run($full) === 'saved', 'Complete real field inventory including nullable numbers'); $cases++;
  sd_reset('root', false); $long = str_repeat('😀', 55);
  sd_check(sd_run(array('edit_style_name' => $long, 'edit_row_class1' => 'row', 'name_row_class1' => $long, 'edit_themes_id' => '2', 'edit_template_name' => 'other')) === 'saved', 'Character limits and empty-table extended label metadata');
  $after = sd_snapshot(); sd_check($after[0][0]['style_name'] === str_repeat('😀', 30) && $after[1][0]['row_class1_name'] === str_repeat('😀', 50) && $after[0][0]['template_name'] === 'fisubsilversh', 'Truncation is Unicode-safe; immutable columns remain immutable'); $cases++;
  sd_reset(); unlink($sd_root . '/cache/themes.cache'); mkdir($sd_root . '/cache/themes.cache');
  try { sd_check(sd_run($request) === 'error' && sd_snapshot() === $expected, 'Cache cleanup failure reports incomplete cleanup, not fictional rollback'); sd_unlocked(); }
  finally { rmdir($sd_root . '/cache/themes.cache'); } $cases++;
  sd_check(sd_run($request) === 'saved', 'Idempotent retry after cache repair'); sd_unlocked(); $cases++;
  sd_reset(); $before = sd_snapshot(); $sd_failure = 'SELECT GET_LOCK';
  sd_check(sd_run($request) === 'error' && sd_snapshot() === $before, 'Lock acquisition failure leaves data untouched'); sd_unlocked(); $cases++;
  // Only writes by the owning transaction and only into this editor's tables.
  foreach (array('autocommit-update', 'autocommit-insert', 'foreign-update', 'foreign-insert', 'ddl', 'double-begin', 'after-commit', 'after-release') as $mode) {
   sd_reset(); $before = sd_snapshot(); $owner = new PhpbbStyleDataWriter($db); $caught = false;
   try {
    if ($mode === 'ddl') { $owner->sql_query('START TRANSACTION'); $owner->sql_query('ALTER TABLE fixture_themes ENGINE=MyISAM'); }
    elseif ($mode === 'foreign-update') { $owner->sql_query('START TRANSACTION'); $owner->sql_query('UPDATE fixture_users SET user_level=1'); }
    elseif ($mode === 'foreign-insert') { $owner->sql_query('START TRANSACTION'); $owner->sql_query('INSERT INTO fixture_users (user_id) VALUES (99)'); }
    elseif ($mode === 'autocommit-insert') { $owner->sql_query('INSERT INTO fixture_names (themes_id) VALUES (99)'); }
    elseif ($mode === 'double-begin') { $owner->sql_query('START TRANSACTION'); $owner->sql_query('START TRANSACTION'); }
    else {
     if ($mode === 'after-commit') { $owner->sql_query('START TRANSACTION'); $owner->sql_query('COMMIT'); }
     if ($mode === 'after-release') { $owner->release(); }
     $owner->sql_query("UPDATE fixture_themes SET style_name='forged'");
    }
   } catch (PhpbbAclException $e) { $caught = true; } finally { $owner->release(); }
   sd_check($caught && sd_snapshot() === $before, 'Writer lifecycle rejects ' . $mode); sd_unlocked(); $cases++;
  }
  foreach(array(65536,16777215) as $high_id){
   sd_reset('root',false);sd_sql('UPDATE fixture_themes SET themes_id='.$high_id.' WHERE themes_id=1');
   sd_check(sd_run(array('edit'=>(string)$high_id,'edit_style_name'=>'High ID','name_tr_color1'=>'Größe 😀'))==='saved','Actual label editor accepts full theme ID capacity');
   $labels=sd_snapshot();sd_check($labels[1][0]['themes_id']===(string)$high_id&&$labels[1][0]['tr_color1_name']==='Größe 😀','High ID label persisted exactly');sd_unlocked();$cases++;
  }
  foreach(array('root','delegated') as $actor){
   sd_reset($actor);$before=sd_snapshot();sd_check(sd_run(array('edit_theme_public'=>'0','edit_style_name'=>'after'))==='error'&&sd_snapshot()===$before,'Current default cannot be hidden by property editor');sd_unlocked();$cases++;
   sd_reset($actor);sd_check(sd_run(array('edit'=>'2','edit_theme_public'=>'0','edit_style_name'=>'Private'))==='saved','Non-default style may be private');sd_unlocked();$cases++;
   sd_reset($actor);sd_sql("UPDATE fixture_themes SET template_name='retired',theme_public=0 WHERE themes_id=2");$before=sd_snapshot();sd_check(sd_run(array('edit'=>'2','edit_theme_public'=>'1','edit_style_name'=>'after'))==='error'&&sd_snapshot()===$before,'Retired template cannot become public');sd_unlocked();$cases++;
   sd_reset($actor);sd_sql('UPDATE fixture_themes SET theme_public=0 WHERE themes_id=1');sd_check(sd_run(array('edit_theme_public'=>'1','edit_style_name'=>'Repaired'))==='saved','Existing private default can be repaired');sd_unlocked();$cases++;
  }
  foreach(array('missing','alias','invalid') as $bad){sd_reset();sd_sql("DELETE FROM fixture_config");if($bad!=='missing'){sd_sql("INSERT INTO fixture_config VALUES ('".($bad==='alias'?'Default_style':'default_style')."','".($bad==='invalid'?'999999999':'1')."')");}$before=sd_snapshot();sd_check(sd_run(array('edit'=>'2','edit_theme_public'=>'0','edit_style_name'=>'after'))==='error'&&sd_snapshot()===$before,'Invalid default metadata rejected before visibility writes');sd_sql('DELETE FROM fixture_config');sd_sql("INSERT INTO fixture_config VALUES ('default_style','1')");$cases++;}
  sd_reset();$blocked=false;$sd_hook=function($sql)use(&$blocked){if(strpos($sql,'UPDATE fixture_themes ')!==0){return;}$GLOBALS['sd_hook']=null;$r=$GLOBALS['peer']->sql_query("UPDATE fixture_config SET config_value='2' WHERE config_name='default_style'");if(!$r){$e=$GLOBALS['peer']->sql_error();sd_check((int)$e['code']===1205,'Only current row-lock timeout');$blocked=true;}};
  sd_check(sd_run(array('edit'=>'2','edit_theme_public'=>'0','edit_style_name'=>'Private'))==='saved'&&$blocked,'Visibility save serializes a concurrent default switch');sd_unlocked();$cases++;
  echo 'Style data native checks: ' . $cases . ' cases; ' . $serialized . " revocations serialized after save\n";
 }
} finally {
 $sd_hook = null; $sd_failure = ''; $db->sql_close(); $peer->sql_close(); $control->sql_query('DROP DATABASE ' . $fixture); $control->sql_close(); chdir($sd_previous);
 if (is_file($sd_root . '/cache/themes.cache')) { unlink($sd_root . '/cache/themes.cache'); }
 foreach (array_reverse($sd_files) as $file) { unlink($file); } foreach (array_reverse($sd_dirs) as $dir) { rmdir($dir); }
 restore_error_handler();
}
