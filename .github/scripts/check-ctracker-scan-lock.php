<?php
define('IN_PHPBB', true);
define('CTRACKER_ACP', true);
define('CTRACKER_FILECHK', 'fixture_hash');
define('CTRACKER_FILESCANNER', 'fixture_scan');
define('CRITICAL_ERROR', 1);
define('GENERAL_MESSAGE', 2);
class LockTestExit extends RuntimeException {}
function message_die($level, $message) { throw new LockTestExit($message); }
function lock_assert($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
require dirname(dirname(__DIR__)) . '/phpBB2/ctracker/classes/class_ct_adminfunctions.php';

class LockTestServer
{
	var $locks = array();
	var $tables = array('fixture_hash' => array('previous'), 'fixture_scan' => array('previous'));
	var $connections = array();
	var $queries = array();
	var $inject = null;
	var $failure = '';
	var $scanner_rows = array();
}
// Each constructor represents an independent SQL session, not another handle
// for the same session (GET_LOCK is reentrant within a SQL session).
class sql_db
{
	var $db_connect_id = true;
	var $server_state;
	var $name = '';
	var $closed = false;
	var $rows = array();
	function __construct($server, $user, $password, $dbname, $persistent)
	{
		lock_assert($server === 'fixture' && $persistent === false, 'Lock connection must never be persistent');
		$this->server_state = $GLOBALS['lock_server'];
		$this->server_state->connections[] = $this;
		$this->db_connect_id = $this->server_state->failure !== 'connect';
	}
	function sql_escape($value) { return addslashes($value); }
	function sql_query($sql)
	{
		$s = $this->server_state;
		$s->queries[] = $sql;
		if ($this->closed || !$this->db_connect_id) { return false; }
		if (preg_match("/^SELECT GET_LOCK\('([^']+)', 0\) AS acquired$/", $sql, $m))
		{
			lock_assert(strlen($m[1]) <= 64, 'MySQL lock-name limit');
			if ($s->failure === 'query') { return false; }
			if ($s->failure === 'null') { $this->rows = array(array('acquired' => null)); return 'lock'; }
			if (isset($s->locks[$m[1]])) { $value = '0'; }
			else { $value = '1'; $this->name = $m[1]; $s->locks[$this->name] = $this; }
			$this->rows = array(array('acquired' => $value)); return 'lock';
		}
		lock_assert($this->name !== '' && isset($s->locks[$this->name]), 'Scan SQL must use the session that owns its lock');
		if (is_callable($s->inject)) { call_user_func($s->inject, $sql, $this); }
		// A lost lock connection must not continue on the ordinary forum DB.
		if ($this->closed || !$this->db_connect_id) { return false; }
		if ($s->failure !== '' && strpos($sql, $s->failure) === 0) { return false; }
		if (preg_match('/^DROP TABLE IF EXISTS (\w+)$/', $sql, $m)) { unset($s->tables[$m[1]]); return true; }
		if (preg_match('/^CREATE TABLE (\w+) LIKE /', $sql, $m)) { $s->tables[$m[1]] = array(); return true; }
		if (preg_match('/^INSERT INTO (\w+) /', $sql, $m)) { $s->tables[$m[1]][] = $sql; return true; }
		if (preg_match('/^SELECT id, filepath FROM (\w+)$/', $sql, $m))
		{
			$this->rows = $s->scanner_rows; return 'scan';
		}
		if (preg_match('/^RENAME TABLE (\w+) TO (\w+), (\w+) TO (\w+)$/', $sql, $m))
		{
			$s->tables[$m[2]] = $s->tables[$m[1]];
			$s->tables[$m[4]] = $s->tables[$m[3]];
			unset($s->tables[$m[3]]); return true;
		}
		throw new RuntimeException('Unexpected SQL: ' . $sql);
	}
	function sql_fetchrow($result) { return $this->rows ? array_shift($this->rows) : false; }
	function sql_freeresult($result) {}
	function sql_close()
	{
		lock_assert(!$this->closed, 'A lock connection must only close once');
		$this->closed = true;
		if ($this->name !== '' && isset($this->server_state->locks[$this->name]) &&
			$this->server_state->locks[$this->name] === $this) { unset($this->server_state->locks[$this->name]); }
		if (isset($GLOBALS['shutdown_marker'])) { file_put_contents($GLOBALS['shutdown_marker'], 'released'); }
	}
}
class LockTestForumDatabase
{
	var $server = 'p:fixture';
	var $user = 'fixture';
	var $password = '';
	var $dbname = 'fixture';
	function sql_query($sql) { throw new RuntimeException('Builder used the unlocked forum connection'); }
}
$db = new LockTestForumDatabase();
$lang = array('ctracker_scan_busy' => 'busy', 'ctracker_error_database_op' => 'database', 'ctracker_error_fileop' => 'file');
$lock_server = new LockTestServer();
if (isset($argv[1]) && $argv[1] === '--shutdown-child')
{
	$shutdown_marker = $argv[2];
	$lock = new ct_scan_lock($db, CTRACKER_FILECHK);
	lock_assert($lock->acquired === 1, 'Child lock acquired');
	exit; // finally blocks do not run on exit; the shutdown handler must.
}
$root = sys_get_temp_dir() . '/ct-lock-' . md5(uniqid('', true));
mkdir($root, 0700);
foreach (array('a.php', 'b.php', 'c.php') as $file) { file_put_contents($root . '/' . $file, "<?php echo 'fixture';"); }
$phpbb_root_path = $root; $phpEx = 'php';
function run_locked_scan($kind)
{
	global $root;
	$admin = new ct_adminfunctions();
	if ($kind === 'hash') { $admin->do_filechk(); } else { $admin->RunFileScan($root, 'php'); }
	return $admin;
}
try
{
	foreach (array('hash', 'scan') as $kind)
	{
		$table = 'fixture_' . $kind;
		$lock_server = new LockTestServer(); $attempted = false;
		$lock_server->inject = function($sql) use ($kind, $table, &$attempted)
		{
			if ($sql !== 'DROP TABLE IF EXISTS ' . $table . '_old' || $attempted) { return; }
			$attempted = true;
			try { run_locked_scan($kind); throw new RuntimeException('Competing builder was accepted'); }
			catch (LockTestExit $e) { lock_assert($e->getMessage() === 'busy', 'Competing scan needs a clear busy response'); }
		};
		run_locked_scan($kind);
		lock_assert($attempted && count($lock_server->tables[$table]) === 3, 'The complete first scan must be published');
		lock_assert(!$lock_server->locks, 'Normal return releases the lock');
		run_locked_scan($kind); // the same scan can run again immediately
		foreach (array('connect', 'query', 'null', 'CREATE TABLE', 'INSERT INTO', 'RENAME TABLE') as $failure)
		{
			$lock_server = new LockTestServer(); $lock_server->failure = $failure;
			try { run_locked_scan($kind); throw new RuntimeException('Failure accepted: ' . $failure); }
			catch (LockTestExit $e) { lock_assert($e->getMessage() === 'database', 'Fail closed on connection/SQL failure'); }
			lock_assert($lock_server->tables[$table] === array('previous') && !$lock_server->locks, 'Failure must preserve report and release lock');
			if (in_array($failure, array('connect', 'query', 'null'), true))
			{
				lock_assert(strpos(implode("\n", $lock_server->queries), 'DROP TABLE') === false, 'No staging mutations without a lock');
			}
		}
		$lock_server = new LockTestServer();
		$lock_server->inject = function($sql) use ($table)
		{
			if ($sql === 'DROP TABLE IF EXISTS ' . $table . '_old') { throw new RuntimeException('interrupted'); }
		};
		try { run_locked_scan($kind); } catch (RuntimeException $e) { lock_assert($e->getMessage() === 'interrupted', 'Expected interruption'); }
		lock_assert(!$lock_server->locks && $lock_server->tables[$table] === array('previous'), 'Exception must release lock without publication');
		$lock_server = new LockTestServer();
		$lock_server->inject = function($sql, $connection) use ($table)
		{
			if ($sql === 'DROP TABLE IF EXISTS ' . $table . '_old') { $connection->db_connect_id = false; unset($connection->server_state->locks[$connection->name]); }
		};
		try { run_locked_scan($kind); } catch (LockTestExit $e) {}
		lock_assert($lock_server->tables[$table] === array('previous'), 'Loss of lock session must stop publishing');
	}
	$lock_server = new LockTestServer();
	$lock_server->scanner_rows = array(array('id' => 1, 'filepath' => $root . '/missing.php'));
	$lock_server->failure = 'UPDATE fixture_scan_new SET safety = 10';
	try { run_locked_scan('scan'); throw new RuntimeException('Unreadable-file status write failure was ignored'); }
	catch (LockTestExit $e) { lock_assert($e->getMessage() === 'database', 'Status write must fail closed'); }
	lock_assert($lock_server->tables['fixture_scan'] === array('previous') && !$lock_server->locks, 'Failed status write preserves report and releases lock');
	$lock_server = new LockTestServer();
	$a = new ct_scan_lock($db, CTRACKER_FILECHK);
	$b = new ct_scan_lock($db, CTRACKER_FILESCANNER);
	$other_db = clone $db; $other_db->dbname = 'other';
	$c = new ct_scan_lock($other_db, CTRACKER_FILECHK);
	lock_assert($a->acquired === 1 && $b->acquired === 1 && $c->acquired === 1, 'Different reports and databases must not block each other');
	$a->release(); $a->release(); $b->release(); $c->release();
	if (function_exists('exec'))
	{
		$marker = $root . '/shutdown.txt'; $output = array(); $status = 1;
		exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --shutdown-child ' . escapeshellarg($marker), $output, $status);
		lock_assert($status === 0 && is_file($marker) && file_get_contents($marker) === 'released', 'Actual exit must run lock cleanup');
	}
	echo "CrackerTracker scan locking tests passed.\n";
}
finally
{
	foreach (array('a.php', 'b.php', 'c.php', 'shutdown.txt') as $file) { if (is_file($root . '/' . $file)) { unlink($root . '/' . $file); } }
	rmdir($root);
}
