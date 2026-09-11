<?php
define('IN_PHPBB', true); define('CTRACKER_ACP', true);
define('CTRACKER_BACKUP', 'fixture_backup'); define('CONFIG_TABLE', 'fixture_config');
define('GENERAL_ERROR', 1); define('GENERAL_MESSAGE', 2); define('CRITICAL_ERROR', 3);
class RecoveryExit extends RuntimeException {}
function message_die($level, $message) { throw new RecoveryExit($message); }
function recovery_assert($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
$forum_root = dirname(dirname(__DIR__)) . '/phpBB2/';
require $forum_root . 'ctracker/classes/class_ct_adminfunctions.php';
class RecoveryServer
{
	var $lock = null;
	var $tables = array('fixture_backup' => array('one' => 'saved1', 'two' => 'saved2', 'ct_last_backup' => '123'),
		'fixture_config' => array('one' => 'live1', 'two' => 'live2', 'new' => 'extra'));
	var $queries = array();
	var $hook = null;
	var $failure = '';
	var $engine = 'InnoDB';
}
class RecoveryForumDatabase
{
	var $server = 'fixture'; var $user = 'fixture'; var $password = ''; var $dbname = 'fixture';
	function sql_query($sql) { throw new RuntimeException('Recovery bypassed its owned session'); }
}
class sql_db
{
	var $db_connect_id = true; var $server_state; var $owns_lock = false; var $closed = false;
	var $pending = null;
	function __construct($server, $user, $password, $dbname, $persistent)
	{
		recovery_assert(!$persistent, 'Recovery connection must not be persistent');
		$this->server_state = $GLOBALS['recovery_server'];
		$this->db_connect_id = $this->server_state->failure !== 'connect';
	}
	function result($rows) { $r = new stdClass(); $r->rows = $rows; return $r; }
	function sql_query($sql)
	{
		$s = $this->server_state; $s->queries[] = $sql;
		if ($this->closed || !$this->db_connect_id) { return false; }
		if (strpos($sql, 'SELECT GET_LOCK(') === 0)
		{
			if ($s->failure === 'lock-query') { return false; }
			if ($s->failure === 'lock-null') { return $this->result(array(array('acquired' => null))); }
			if ($s->lock !== null) { return $this->result(array(array('acquired' => '0'))); }
			$s->lock = $this; $this->owns_lock = true;
			return $this->result(array(array('acquired' => '1')));
		}
		recovery_assert($this->owns_lock && $s->lock === $this, 'Every recovery query must own its lock');
		if (is_callable($s->hook)) { call_user_func($s->hook, $sql, $this); }
		if (!$this->db_connect_id) { return false; }
		if ($s->failure !== '' && strpos($sql, $s->failure) === 0) { return false; }
		if (strpos($sql, 'SELECT COUNT(*) AS modern_storage') === 0) { return $this->result(array(array('modern_storage' => '1'))); }
		if ($sql === "SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_ALL_TABLES')") { return true; }
		if ($sql === 'START TRANSACTION') { $this->pending = array(); return true; }
		if ($sql === 'COMMIT')
		{
			recovery_assert(is_array($this->pending), 'Commit must follow a transaction');
			foreach ($this->pending as $key => $value) { $s->tables['fixture_config'][$key] = $value; }
			$this->pending = null; return true;
		}
		if ($sql === 'SELECT config_name FROM fixture_config LIMIT 0') { return $this->result(array()); }
		if (strpos($sql, 'SELECT ENGINE FROM information_schema.TABLES') === 0) { return $this->result(array(array('ENGINE' => $s->engine))); }
		if (strpos($sql, 'CREATE TABLE IF NOT EXISTS fixture_backup') === 0) { return true; }
		if (preg_match('/^DROP TABLE IF EXISTS (\w+)$/', $sql, $m)) { unset($s->tables[$m[1]]); return true; }
		if (preg_match('/^CREATE TABLE (\w+) LIKE /', $sql, $m)) { $s->tables[$m[1]] = array(); return true; }
		if ($sql === 'SELECT * FROM fixture_config' || strpos($sql, 'SELECT config_name, config_value FROM fixture_backup') === 0)
		{
			$backup = strpos($sql, 'fixture_backup') !== false; $rows = array();
			foreach ($s->tables[$backup ? 'fixture_backup' : 'fixture_config'] as $key => $value)
			{
				if ($backup && $key === 'ct_last_backup') { continue; }
				$rows[] = array('config_name' => $key, 'config_value' => $value);
			}
			return $this->result($rows);
		}
		if (strpos($sql, 'SELECT config_value FROM fixture_backup') === 0)
		{
			return $this->result(isset($s->tables['fixture_backup']['ct_last_backup']) ?
				array(array('config_value' => $s->tables['fixture_backup']['ct_last_backup'])) : array());
		}
		if (preg_match("/^INSERT INTO (\w+) .*?VALUES \('([^']*)', '([^']*)'\)/s", $sql, $m))
		{
			if ($m[1] === 'fixture_config')
			{
				recovery_assert(is_array($this->pending), 'Configuration writes must be transactional');
				$this->pending[$m[2]] = $m[3]; return true;
			}
			if ($m[1] !== 'fixture_config' && isset($s->tables[$m[1]][$m[2]])) { return false; }
			$s->tables[$m[1]][$m[2]] = $m[3]; return true;
		}
		if (preg_match('/^RENAME TABLE (\w+) TO (\w+), (\w+) TO (\w+)$/', $sql, $m))
		{
			$s->tables[$m[2]] = $s->tables[$m[1]]; $s->tables[$m[4]] = $s->tables[$m[3]];
			unset($s->tables[$m[3]]); return true;
		}
		throw new RuntimeException('Unexpected SQL ' . $sql);
	}
	function sql_fetchrow($result) { return $result->rows ? array_shift($result->rows) : false; }
	function sql_freeresult($result) {}
	function sql_escape($value) { return addslashes($value); }
	function sql_close()
	{
		recovery_assert(!$this->closed, 'Connection must only close once');
		$this->closed = true;
		$this->pending = null;
		if ($this->server_state->lock === $this) { $this->server_state->lock = null; }
	}
}
function recovery_run($action)
{
	$admin = new ct_adminfunctions();
	if ($action === 'backup') { $admin->recover_configuration(); } else { $admin->restore_configuration(); }
}
$db = new RecoveryForumDatabase();
$lang = array('ctracker_recovery_busy' => 'busy', 'ctracker_error_database_op' => 'database',
	'ctracker_error_loading_config' => 'load', 'ctracker_rec_never_saved' => 'empty', 'ctracker_rec_empty_source' => 'empty',
	'ctracker_rec_transaction_required' => 'engine', 'ctracker_error_storage_migration' => 'migration');
$root = sys_get_temp_dir() . '/ct-recovery-lock-' . md5(uniqid('', true));
mkdir($root, 0700); mkdir($root . '/cache', 0700); $phpbb_root_path = $root . '/';
try
{
	foreach (array('backup', 'restore') as $first)
	{
		foreach (array('backup', 'restore') as $second)
		{
			$recovery_server = new RecoveryServer(); $attempted = false;
			$recovery_server->hook = function($sql) use ($first, $second, &$attempted)
			{
				$point = $first === 'backup' ? 'DROP TABLE IF EXISTS fixture_backup_old' : 'SELECT config_name, config_value FROM fixture_backup';
				if ($attempted || strpos($sql, $point) !== 0) { return; }
				$attempted = true;
				try { recovery_run($second); throw new RuntimeException('Competing operation accepted'); }
				catch (RecoveryExit $e) { recovery_assert($e->getMessage() === 'busy', 'Expected recovery contention response'); }
			};
			recovery_run($first);
			recovery_assert($attempted && $recovery_server->lock === null, 'Overlap tested and lock released');
			recovery_assert($first === 'backup' ? count($recovery_server->tables['fixture_backup']) === 4 :
				$recovery_server->tables['fixture_config'] === array('one' => 'saved1', 'two' => 'saved2', 'new' => 'extra'), 'Expected coherent complete result');
			recovery_run($second);
		}
		foreach (array('connect', 'lock-query', 'lock-null', 'SELECT', 'INSERT', 'RENAME TABLE') as $failure)
		{
			if ($first === 'restore' && $failure === 'RENAME TABLE') { continue; }
			$recovery_server = new RecoveryServer(); $recovery_server->failure = $failure;
			$before = $recovery_server->tables;
			try { recovery_run($first); throw new RuntimeException('Failure accepted ' . $failure); } catch (RecoveryExit $e) {}
			recovery_assert($recovery_server->lock === null && $recovery_server->tables['fixture_backup'] === $before['fixture_backup'] &&
				$recovery_server->tables['fixture_config'] === $before['fixture_config'], 'Early failure preserves active tables and releases ownership');
		}
	}
	foreach (array(array(), array('ct_last_backup' => 'old')) as $source)
	{
		$recovery_server = new RecoveryServer(); $recovery_server->tables['fixture_config'] = $source; $before = $recovery_server->tables['fixture_backup'];
		try { recovery_run('backup'); throw new RuntimeException('Empty snapshot accepted'); } catch (RecoveryExit $e) { recovery_assert($e->getMessage() === 'empty', 'Expected empty-snapshot refusal'); }
		recovery_assert($recovery_server->tables['fixture_backup'] === $before && $recovery_server->lock === null, 'Empty snapshot must not replace the last backup');
	}
	foreach (array('backup', 'restore') as $action)
	{
		foreach (array('exception', 'disconnect') as $failure)
		{
			$recovery_server = new RecoveryServer(); $before = $recovery_server->tables; $triggered = false;
			$recovery_server->hook = function($sql, $connection) use ($action, $failure, &$triggered)
			{
				$point = $action === 'backup' ? 'DROP TABLE IF EXISTS fixture_backup_old' : 'SELECT config_name, config_value FROM fixture_backup';
				if ($triggered || strpos($sql, $point) !== 0) { return; } $triggered = true;
				if ($failure === 'exception') { throw new RuntimeException('interrupted'); }
				$connection->db_connect_id = false; $connection->server_state->lock = null;
			};
			try { recovery_run($action); throw new RuntimeException('Interrupted operation accepted'); }
			catch (RuntimeException $e) { recovery_assert(in_array($e->getMessage(), array('interrupted', 'database', 'load'), true), 'Expected interruption failure'); }
			recovery_assert($triggered && $recovery_server->lock === null &&
				$recovery_server->tables['fixture_backup'] === $before['fixture_backup'] &&
				$recovery_server->tables['fixture_config'] === $before['fixture_config'], 'Pre-publication interruption preserves active tables and releases session');
		}
	}
	$recovery_server = new RecoveryServer(); $recovery_server->tables['fixture_config']['CT_LAST_BACKUP'] = 'old';
	recovery_run('backup');
	recovery_assert(count($recovery_server->tables['fixture_backup']) === 4 && !isset($recovery_server->tables['fixture_backup']['CT_LAST_BACKUP']), 'Historical marker in main config must not collide with the new timestamp');
	foreach (array('', '0', '-1', '1junk', ' 1', '01', '1.0', str_repeat('9', 40), null) as $marker)
	{
		$recovery_server = new RecoveryServer(); $recovery_server->tables['fixture_backup']['ct_last_backup'] = $marker; $before = $recovery_server->tables['fixture_config'];
		try { recovery_run('restore'); throw new RuntimeException('Invalid marker accepted'); } catch (RecoveryExit $e) { recovery_assert($e->getMessage() === 'empty', 'Invalid marker refused'); }
		recovery_assert($recovery_server->tables['fixture_config'] === $before && $recovery_server->lock === null, 'Invalid marker must leave configuration unchanged');
	}
	foreach (array('MyISAM', 'Aria', '', null) as $engine)
	{
		$recovery_server = new RecoveryServer(); $recovery_server->engine = $engine; $before = $recovery_server->tables;
		try { recovery_run('restore'); throw new RuntimeException('Nontransactional restore accepted'); }
		catch (RecoveryExit $e) { recovery_assert($e->getMessage() === 'engine', 'Explain the required migration'); }
		recovery_assert($recovery_server->tables === $before && $recovery_server->lock === null, 'Engine preflight must leave all data unchanged');
	}
	foreach (array('sql', 'exception', 'disconnect', 'commit') as $failure)
	{
		$recovery_server = new RecoveryServer(); $before = $recovery_server->tables; $writes = 0; $triggered = false;
		$recovery_server->hook = function($sql, $connection) use ($failure, $before, &$writes, &$triggered)
		{
			$s = $connection->server_state;
			recovery_assert($s->tables === $before, 'Readers must not observe partially restored values');
			if (strpos($sql, 'INSERT INTO fixture_config') === 0) { $writes++; }
			if (($failure !== 'commit' && $writes === 2) || ($failure === 'commit' && $sql === 'COMMIT'))
			{
				$triggered = true;
				if ($failure === 'exception') { throw new RuntimeException('interrupted'); }
				if ($failure === 'disconnect') { $connection->db_connect_id = false; $s->lock = null; }
				else { $s->failure = $failure === 'commit' ? 'COMMIT' : 'INSERT INTO fixture_config'; }
			}
		};
		try { recovery_run('restore'); throw new RuntimeException('Partial restore accepted'); }
		catch (RuntimeException $e) { recovery_assert(in_array($e->getMessage(), array('interrupted', 'database'), true), 'Expected late failure'); }
		recovery_assert($triggered && $writes >= 2 && $recovery_server->tables === $before && $recovery_server->lock === null, 'Late failures must discard every uncommitted write and release ownership');
	}
	echo "CrackerTracker recovery concurrency tests passed.\n";
}
finally { @unlink($root . '/cache/config_data.cache'); rmdir($root . '/cache'); rmdir($root); }
