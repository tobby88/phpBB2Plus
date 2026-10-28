<?php
if (PHP_SAPI !== 'cli' || getenv('PHPBB_ALBUM_LIFECYCLE_AUTHORITY_NATIVE') !== '1') { echo "Native Album lifecycle authority fixture skipped.\n"; return; }
define('IN_PHPBB', true); define('ADMIN', 1); define('GENERAL_ERROR', 202); define('GENERAL_MESSAGE', 200);
$phpEx='php';$table_prefix = 'fixture_'; foreach (array('USERS_TABLE'=>'users','SESSIONS_TABLE'=>'sessions','USER_GROUP_TABLE'=>'user_group','CONFIG_TABLE'=>'config','JR_ADMIN_TABLE'=>'jr_admin_users') as $name => $suffix) { define($name, $table_prefix . $suffix); }
$root = dirname(dirname(__DIR__)); $source_root = $root . '/phpBB2/';
require $source_root . 'includes/php_compat.php'; require $source_root . 'db/mysqli.php'; require $source_root . 'album_mod/album_constants.php'; require $source_root . 'album_mod/album_functions.php'; require $source_root . 'album_mod/album_hierarchy_auth.php';
require $source_root . 'album_mod/album_lifecycle_authority.php'; require __DIR__ . '/album-native-observer.php';
function ala_check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
function message_die($type, $message) { throw new RuntimeException($message); }
function checkFlag($value, $flag) { return ($value & $flag) === $flag; }
function album_is_debug_enabled() { return false; }
$common = file_get_contents($source_root . 'includes/functions.php'); ala_check(preg_match('/^function phpbb_sql_id_list\(.*?^\}/ms', $common, $match) === 1, 'Actual stored group-list parser'); eval($match[0]);
function album_sql_id_list($ids) { return phpbb_sql_id_list($ids); }
class AlbumLifecycleAuthoritySource extends sql_db
{
    var $queries = array(); var $failure = 0; var $count = 0; var $hook = null;
    function sql_dedicated_connection() { return new AlbumNativeObservedConnection(parent::sql_dedicated_connection(), $this); }
    function observed_query($database, $sql, $transaction = false) { $this->queries[] = $sql; if (++$this->count === $this->failure) { return false; } if (is_callable($this->hook)) { call_user_func($this->hook, $database, $sql); } return $database->sql_query($sql, $transaction); }
    function reset_case($failure = 0) { $this->queries = array(); $this->failure = $failure; $this->count = 0; $this->hook = null; }
}
$port = getenv('PHPBB_ALBUM_LIFECYCLE_AUTHORITY_PORT') ?: '3306'; $password = getenv('PHPBB_ALBUM_LIFECYCLE_AUTHORITY_PASSWORD') ?: '';
ala_check(preg_match('/^[0-9]{1,5}$/D', $port) && (int) $port > 0 && (int) $port <= 65535, 'Own loopback-only authority port');
$host = '127.0.0.1:' . $port; $schema = 'codex_album_live_auth_' . bin2hex(phpbb_random_bytes(8));
$control = new sql_db($host, 'root', $password, '', false); $db = null; $storage = null; $created = false; $cases = 0; $worker = null; $worker_pipes = array(); $directory = sys_get_temp_dir() . '/' . $schema;
$userdata = array(); $album_config = array(); $album_data = array(); $board_config = array('session_length'=>999999,'marker'=>'preserve'); $lang = array('Category_not_exist'=>'missing');
function ala_sql($sql) { $result = $GLOBALS['control']->sql_query($sql); ala_check($result !== false, 'Own native authority fixture SQL'); return $result; }
function ala_rows($sql) { $result = ala_sql($sql); try { return $GLOBALS['control']->sql_fetchrowset($result); } finally { $GLOBALS['control']->sql_freeresult($result); } }
function ala_literal($value) { return 'CONVERT(0x' . bin2hex($value) . ' USING utf8mb4)'; }
function ala_globals() { return array($GLOBALS['db'],$GLOBALS['userdata'],$GLOBALS['album_config'],$GLOBALS['album_data'],$GLOBALS['board_config']); }
function ala_reset($guest = false)
{
    foreach (array('album','album_file_items','album_file_jobs','album_cat','album_config','config','sessions','user_group','jr_admin_users','users') as $suffix) { ala_sql('DELETE FROM fixture_' . $suffix); }
    ala_sql("INSERT INTO fixture_users(user_id,username,user_password,user_level,user_active) VALUES(-1,'Anonymous','',0,0),(7," . ala_literal("O'Connor \ 😀") . ",'',0,1),(8,'Gallery owner','',0,1)");
    ala_sql("INSERT INTO fixture_config VALUES('session_length','3600'),('unrelated','preserve')");
    foreach ($GLOBALS['album_seeds'] as $sql) { ala_sql($sql); }
    ala_sql("INSERT INTO fixture_album_cat(cat_id,cat_title,cat_order,cat_user_id,cat_upload_level,cat_upload_groups,cat_moderator_groups) VALUES(1,'Public',1,0,0,'',''),(2,'Personal',2,8,3,'10',''),(3,'Private',3,0,3,'10',''),(4,'Moderated',4,0,2,'','10')");
    ala_sql('INSERT INTO fixture_user_group(group_id,user_id,user_pending) VALUES(10,7,0)');
    $GLOBALS['userdata'] = array('user_id'=>$guest ? -1 : 7,'username'=>'Stale name','user_level'=>ADMIN,'user_active'=>1,'session_id'=>str_repeat('a',32),'session_logged_in'=>!$guest,'session_ip'=>'ffffffff','marker'=>'preserve');
    ala_sql("INSERT INTO fixture_sessions(session_id,session_user_id,session_logged_in,session_time,session_ip,session_admin) VALUES('" . str_repeat('a',32) . "'," . ($guest ? -1 : 7) . ',' . ($guest ? 0 : 1) . ',' . time() . ",'7f000001',0)");
    if ($guest) { ala_sql('UPDATE fixture_album_cat SET cat_upload_level=-1 WHERE cat_id=1'); }
    $GLOBALS['album_config'] = array('personal_gallery'=>ALBUM_ADMIN,'rate'=>1,'comment'=>1,'marker'=>'preserve'); $GLOBALS['album_data'] = array('auth'=>array(3=>array('upload'=>1)));
    $GLOBALS['db']->reset_case();
}
function ala_authorize($owner = 0, $category = 1, $checks = ALBUM_AUTH_VIEW_AND_UPLOAD)
{
    $GLOBALS['storage'] = new AlbumFileCoordinator($GLOBALS['db'], 'fixture_', 0); $GLOBALS['storage']->begin();
    $authority = new AlbumLifecycleAuthority($GLOBALS['storage'], 'fixture_'); $before = ala_globals();
    try { $context = $authority->lock($GLOBALS['userdata'], $owner, $category, $checks); }
    finally { ala_check(ala_globals() === $before, 'Exact global request DB/config/userdata/authcache restored'); }
    return array($authority, $context);
}
function ala_release() { if ($GLOBALS['storage']) { $GLOBALS['storage']->release(); $GLOBALS['storage'] = null; } }
function ala_denied($owner = 0, $category = 1, $checks = ALBUM_AUTH_VIEW_AND_UPLOAD)
{
    $allowed = false; try { $result = ala_authorize($owner, $category, $checks); $allowed = $result[1] !== false; } catch (AlbumFileCoordinatorFailure $error) {}
    finally { ala_release(); }
    ala_check(!$allowed && !ala_rows('SELECT pic_id FROM fixture_album'), 'Changed current authority denies without metadata writes'); $GLOBALS['cases']++;
}
if (isset($argv[1]) && $argv[1] === 'board-order-worker') {
    ala_check(count($argv) === 4 && preg_match('/^codex_album_live_auth_[a-f0-9]{16}$/D', $argv[2]) && in_array($argv[3], array('1800','1'), true), 'Exact owned config-order worker');
    $directory = sys_get_temp_dir() . '/' . $argv[2]; ala_check(is_dir($directory) && !is_link($directory) && realpath(dirname($directory)) === realpath(sys_get_temp_dir()), 'Own parent barrier directory');
    $peer = new sql_db($host, 'root', $password, $argv[2], false);
    try {
        ala_check($peer->sql_query('SET SESSION innodb_lock_wait_timeout=3') && $peer->sql_query('START TRANSACTION') && $peer->sql_query("UPDATE fixture_config SET config_value='" . $argv[3] . "' WHERE config_name='session_length'"), 'Actual peer config update holds its row');
        ala_check(file_put_contents($directory . '/config-held','ready') === 5, 'Own config barrier'); $deadline = microtime(true)+6;
        while (!is_file($directory . '/album-at-config')) { clearstatcache(true,$directory . '/album-at-config'); ala_check(microtime(true)<$deadline,'Album reaches current lifetime before actor locks'); usleep(10000); }
        // The actual board config writer updates CONFIG before its final
        // account/session share locks. Exercise that order on real rows.
        ala_check($peer->sql_query('SELECT user_id FROM fixture_users WHERE user_id=7 LOCK IN SHARE MODE') && $peer->sql_query("SELECT session_id FROM fixture_sessions WHERE session_id='" . str_repeat('a',32) . "' LOCK IN SHARE MODE") && $peer->sql_query('COMMIT'), 'Peer final authority locks and commit do not deadlock');
        echo "BOARD_ORDER_COMMIT\n";
    } finally { $peer->sql_close(); } exit(0);
}
function ala_start_order_worker($seconds)
{
    $command = (DIRECTORY_SEPARATOR === chr(92) ? '' : 'exec ') . escapeshellarg(PHP_BINARY) . ' ';
    if (DIRECTORY_SEPARATOR === chr(92)) { $command .= '-n -d ' . escapeshellarg('extension_dir=' . ini_get('extension_dir')) . ' -d date.timezone=UTC -d extension=php_mysqli.dll -d extension=php_openssl.dll '; if (extension_loaded('mbstring')) { $command .= '-d extension=php_mbstring.dll '; } }
    $command .= escapeshellarg(__FILE__) . ' board-order-worker ' . escapeshellarg($GLOBALS['schema']) . ' ' . escapeshellarg((string)$seconds);
    $GLOBALS['worker'] = proc_open($command,array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$GLOBALS['worker_pipes'],null,null,array('bypass_shell'=>true)); ala_check(is_resource($GLOBALS['worker']),'Actual separate config writer process'); fclose($GLOBALS['worker_pipes'][0]);
    $ready = $GLOBALS['directory'] . '/config-held'; $deadline = microtime(true)+6;
    while (true) { clearstatcache(true,$ready); if (is_file($ready) && file_get_contents($ready)==='ready') { return; } $status=proc_get_status($GLOBALS['worker']); ala_check($status['running'] && microtime(true)<$deadline,'Config worker holds actual row before Album starts'); usleep(10000); }
}
function ala_finish_order_worker()
{
    $deadline=microtime(true)+6; do { $status=proc_get_status($GLOBALS['worker']); if (!$status['running']) { break; } ala_check(microtime(true)<$deadline,'Bounded independent config worker'); usleep(10000); } while (true);
    // Windows anonymous pipes block despite stream_set_blocking(false): read
    // only after the independently running worker actually exits.
    $out=stream_get_contents($GLOBALS['worker_pipes'][1]); $err=stream_get_contents($GLOBALS['worker_pipes'][2]); fclose($GLOBALS['worker_pipes'][1]); fclose($GLOBALS['worker_pipes'][2]); $closed=proc_close($GLOBALS['worker']); $GLOBALS['worker']=null;
    $code=$status['exitcode']>=0?$status['exitcode']:$closed; ala_check($code===0 && $out==="BOARD_ORDER_COMMIT\n" && $err==='', 'Actual config writer completes without lock cycle: '.$out.$err);
    foreach(array('config-held','album-at-config') as $name) { ala_check(unlink($GLOBALS['directory'].'/'.$name),'Remove only own exact handshake'); }
}
$reporting = error_reporting(E_ALL); set_error_handler(function ($severity, $message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } });
try {
    ala_check($control->db_connect_id, 'Own native control'); ala_sql('CREATE DATABASE ' . $schema . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); $created = true; ala_sql('USE ' . $schema);
    $ddl = file_get_contents($source_root . 'install/schemas/mysql_schema.sql');
    foreach (array('users','sessions','user_group','config','jr_admin_users','album','album_cat','album_comment','album_rate','album_config','album_file_jobs','album_file_items') as $suffix) { ala_check(preg_match('/^CREATE TABLE `?phpbb_' . $suffix . '`?\s*\([\s\S]*?;/m', $ddl, $match) === 1, 'Canonical authority table'); ala_sql(str_replace('phpbb_' . $suffix, 'fixture_' . $suffix, $match[0])); }
    preg_match_all('/^INSERT INTO phpbb_album_config\b[^\r\n]*;/m', file_get_contents($source_root . 'install/schemas/mysql_basic.sql'), $seed_matches); $album_seeds = array(); foreach ($seed_matches[0] as $sql) { $album_seeds[] = str_replace('phpbb_album_config','fixture_album_config',$sql); } ala_check(count($album_seeds) > 40, 'Actual canonical Album config seeds');
    $db = new AlbumLifecycleAuthoritySource($host, 'root', $password, $schema, false); ala_check($db->db_connect_id, 'Own request source');
    if(getenv('PHPBB_ALBUM_AUTHORITY_PART')==='admin'){require __DIR__.'/album-admin-authority-native-cases.php';ala_admin_cases();return;}
    foreach (array(false,true) as $guest) { ala_reset($guest); $result = ala_authorize(); ala_check($result[1] !== false && $result[0]->current() && $result[1]['userdata']['user_id'] === ($guest ? -1 : 7) && $result[1]['userdata']['user_level'] === 0 && $result[1]['userdata']['session_ip'] === '7f000001', 'Guest/public and registered authority use current identity'); if (!$guest) { ala_check($result[1]['userdata']['username'] === "O'Connor \ 😀", 'Current Unicode account name, not stale snapshot'); } $storage->commit(); ala_release(); $cases++; }
    foreach (array(array(8,2),array(0,3),array(0,4)) as $target) { ala_reset(); $result = ala_authorize($target[0],$target[1]); ala_check($result[1] !== false, 'Current private/moderator membership grants preserved'); $storage->commit(); ala_release(); $cases++; }
    ala_reset(); ala_sql('UPDATE fixture_users SET user_level=1 WHERE user_id=7'); $result = ala_authorize(8,2); ala_check($result[1] !== false && $result[1]['userdata']['user_level'] === ADMIN, 'Current admin grant preserved'); $storage->commit(); ala_release(); $cases++;
    foreach (array("UPDATE fixture_users SET user_active=0 WHERE user_id=7","DELETE FROM fixture_users WHERE user_id=7","DELETE FROM fixture_sessions","UPDATE fixture_sessions SET session_user_id=8","UPDATE fixture_sessions SET session_logged_in=0","UPDATE fixture_sessions SET session_time=1","UPDATE fixture_sessions SET session_id=UPPER(session_id)","UPDATE fixture_album_cat SET cat_upload_level=1 WHERE cat_id=1","DELETE FROM fixture_album_cat WHERE cat_id=1","UPDATE fixture_album_cat SET cat_user_id=8 WHERE cat_id=1","UPDATE fixture_config SET config_value='0' WHERE config_name='session_length'","DELETE FROM fixture_config WHERE config_name='session_length'") as $change) { ala_reset(); ala_sql($change); ala_denied(); }
    foreach (array("DELETE FROM fixture_user_group","UPDATE fixture_user_group SET user_pending=1","UPDATE fixture_user_group SET group_id=20","UPDATE fixture_album_cat SET cat_upload_groups='20' WHERE cat_id=3") as $change) { ala_reset(); ala_sql($change); ala_denied(0,3); }
    ala_reset(); ala_sql('UPDATE fixture_users SET user_level=1 WHERE user_id=7'); ala_sql("UPDATE fixture_album_config SET config_value='0' WHERE config_name='comment'"); ala_denied(0,1,ALBUM_AUTH_COMMENT);
    ala_reset(); ala_sql('UPDATE fixture_users SET user_level=1 WHERE user_id=7'); ala_sql("UPDATE fixture_album_config SET config_value='0' WHERE config_name='rate'"); ala_denied(0,1,ALBUM_AUTH_RATE);
    ala_reset(true); ala_denied(8,2);
    ala_reset(); ala_sql("UPDATE fixture_album_config SET config_value='1' WHERE config_name='personal_gallery'"); ala_denied(8,2);
    ala_reset(); ala_sql('DELETE FROM fixture_users WHERE user_id=8'); ala_denied(8,2);
    ala_reset(); ala_denied(8,1); ala_reset(); ala_denied(0,0);
    foreach (array(array('user_id',0),array('user_id',array()),array('session_id',array()),array('session_id','bad'),array('session_logged_in',array()),array('session_logged_in',false)) as $bad) { ala_reset(); $userdata[$bad[0]]=$bad[1]; ala_denied(); }
    ala_reset(); ala_sql('DELETE FROM fixture_sessions'); ala_sql("INSERT INTO fixture_sessions(session_id,session_user_id,session_logged_in,session_time,session_ip) VALUES('" . str_repeat('A',32) . "',7,1," . time() . ",'7f000001')"); $userdata['session_id']=str_repeat('A',32); $result=ala_authorize(); ala_check($result[1]!==false,'Exact uppercase session still legitimate when both stored and supplied bytes match'); $storage->commit(); ala_release(); $cases++;
    ala_reset(); $result=ala_authorize(8,2); ala_check($result[1]!==false,'Legitimate private grant before lock contention'); ala_sql('SET SESSION innodb_lock_wait_timeout=1');
    foreach (array("UPDATE fixture_users SET user_active=0 WHERE user_id=7","UPDATE fixture_users SET user_active=0 WHERE user_id=8","DELETE FROM fixture_sessions","UPDATE fixture_user_group SET user_pending=1 WHERE user_id=7","UPDATE fixture_album_cat SET cat_upload_level=1 WHERE cat_id=2","UPDATE fixture_album_config SET config_value='1' WHERE config_name='personal_gallery'","UPDATE fixture_config SET config_value='1' WHERE config_name='session_length'") as $revoke) { ala_check($control->sql_query($revoke)===false,'Actual grant/config/session rows remain locked through commit'); $cases++; }
    $storage->commit(); ala_release(); ala_sql('SET SESSION innodb_lock_wait_timeout=5');
    ala_check(mkdir($directory,0700),'Own config-order barriers');
    foreach (array(1800,1) as $seconds) { ala_reset(); ala_sql('UPDATE fixture_sessions SET session_time=' . (time()-($seconds===1?2:1200))); ala_start_order_worker($seconds);
        $db->hook=function($connection,$sql) { if (strpos($sql,"SELECT config_value FROM fixture_config WHERE config_name='session_length'")===0) { $GLOBALS['db']->hook=null; ala_check(file_put_contents($GLOBALS['directory'].'/album-at-config','ready')===5,'Album reaches config before locking actor'); } };
        $result=ala_authorize(); ala_check(($result[1]!==false)===($seconds===1800),'Read newly committed actual session lifetime and preserve valid/deny expired request'); if ($result[1]!==false) { $storage->commit(); } ala_release(); ala_finish_order_worker(); $cases++;
    }
    ala_reset(); $db->reset_case(); $result = ala_authorize(); $storage->commit(); ala_release(); $baseline = $db->queries;
    foreach ($baseline as $index => $sql) { ala_reset(); $db->reset_case($index+1); $failed = false; try { $result = ala_authorize(); ala_check($result[1] !== false, 'Untampered fixture grants authority before injected fault'); $storage->commit(); } catch (AlbumFileCoordinatorFailure $error) { $failed = true; } finally { ala_release(); } ala_check($failed && !ala_rows('SELECT pic_id FROM fixture_album'), 'Every real authority SQL failure aborts safely: ' . ($index+1)); $cases++; }
    ala_reset(); ala_sql("UPDATE fixture_config SET config_value='2' WHERE config_name='session_length'"); $start = time(); ala_sql('UPDATE fixture_sessions SET session_time=' . $start); $result = ala_authorize(); ala_check($result[1] !== false, 'Short live session initially valid'); $storage->sql_query("INSERT INTO fixture_album(pic_id,pic_filename,pic_title,pic_user_id,pic_time) VALUES(7,'owned.jpg','Pending',7,1)"); while (time() < $start+2) { usleep(10000); } $denied = false; try { $storage->commit(); } catch (AlbumFileCoordinatorFailure $error) { $denied = true; } ala_release(); ala_check($denied && !ala_rows('SELECT pic_id FROM fixture_album'), 'Wall-clock expiry prevents actual final commit and rolls back picture'); $cases++;
    foreach (array('ENGINE=MyISAM','ROW_FORMAT=COMPACT','DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci') as $change) { ala_reset(); ala_sql('ALTER TABLE fixture_config ' . $change); ala_denied(); ala_sql('ALTER TABLE fixture_config ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'); }
    ala_reset(); $before = ala_globals(); $db->sql_query('START TRANSACTION'); $db->sql_query("UPDATE fixture_config SET config_value='pending' WHERE config_name='unrelated'"); $result = ala_authorize(); ala_check($result[1] !== false, 'Authority uses independent committed connection'); $storage->commit(); ala_release(); ala_check(ala_rows("SELECT config_value FROM fixture_config WHERE config_name='unrelated'")[0]['config_value'] === 'preserve' && ala_globals() === $before, 'Caller pending transaction and exact globals preserved'); $db->sql_query('ROLLBACK'); $cases++;
    require __DIR__.'/album-admin-authority-native-cases.php';ala_admin_cases();
    echo 'Native Album lifecycle authority: ' . $cases . ' cases PASS; ' . count($baseline) . " real SQL boundaries, current guest/user/admin/group/session/category/config, commit expiry and caller preservation; no live data.\n";
} finally { if(is_resource($worker)){proc_terminate($worker,9);foreach(array(1,2)as$i){if(isset($worker_pipes[$i])&&is_resource($worker_pipes[$i])){fclose($worker_pipes[$i]);}}proc_close($worker);} ala_release(); if ($db) { $db->sql_close(); } if ($created) { $control->sql_query('DROP DATABASE ' . $schema); } $control->sql_close(); if(is_dir($directory)){foreach(array('config-held','album-at-config')as$name){if(is_file($directory.'/'.$name)){unlink($directory.'/'.$name);}}rmdir($directory);} restore_error_handler(); error_reporting($reporting); }
