<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }

class PaFileDbFileCoordinatorFailure extends RuntimeException {}

// Coordination primitive, not a user permission check. Controllers must check
// current authority while holding the metadata rows, before recording work.
// The second owned connection can commit an upload's prepared receipt before
// any final file is written, without committing the picture transaction.
class PaFileDbFileCoordinator
{
	private $writer;
	private $journal;
	private $writer_id;
	private $lock;
	private $prefix;
	private $held = false;
	private $transaction = false;
	private $journal_transaction = false;
	private $finished = false;
	private $ready = false;
	private $failed = false;
	private $tables = array();
	private $last_result = false;
	private $results = array();
	private $affected = false;
	private $insert_id = false;
	private $commit_guards = array();
	private $operation_mode = '';

	function __construct($database, $prefix, $wait = 3)
	{
		if (!is_object($database) || !method_exists($database, 'sql_dedicated_connection') || !is_string($prefix) ||
			!preg_match('/^[A-Za-z0-9_]{1,40}$/D', $prefix) || !is_int($wait) || $wait < 0 || $wait > 3) { throw new PaFileDbFileCoordinatorFailure('Invalid Download coordinator'); }
		$this->prefix = $prefix;
		try
		{
			$source = $this->identity($database);
			$this->writer = $this->dedicated($database, array($database), $source);
			$writer = $this->identity($this->writer); $this->writer_id = $writer['id'];
			$this->journal = $this->dedicated($database, array($database, $this->writer), $source);
			$this->lock = 'attachment:' . md5($source['schema_name'] . "\0" . $prefix . 'attachments');
			$owners = $this->rows($this->writer, "SELECT IS_USED_LOCK('" . $this->lock . "') AS owner");
			// Never adopt/release a lock inherited from a reused connection.
			if (count($owners) !== 1) { throw new PaFileDbFileCoordinatorFailure('Download namespace owner unavailable'); }
			if ($owners[0]['owner'] !== null && (string) $owners[0]['owner'] === $this->writer_id)
			{
				// A factory returned an already participating handle. Disown it:
				// closing this borrowed participant would release its existing lock.
				$this->writer = null; throw new PaFileDbFileCoordinatorFailure('Inherited Download namespace lock');
			}
			$result = $this->rows($this->writer, "SELECT GET_LOCK('" . $this->lock . "'," . $wait . ') AS held');
			if (count($result) !== 1 || (string) $result[0]['held'] !== '1') { throw new PaFileDbFileCoordinatorFailure('Download namespace busy'); }
			$this->held = true;
			foreach (array($this->writer, $this->journal) as $connection)
			{
				// SET TRANSACTION refuses an already active transaction. All session
				// changes and the consumed one-shot isolation belong to this handle.
				$this->query($connection, 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
				$this->query($connection, 'START TRANSACTION READ ONLY');
				$this->query($connection, 'COMMIT');
				$this->query($connection, 'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
				$this->query($connection, "SET SESSION sql_mode=CONCAT_WS(',',@@SESSION.sql_mode,'STRICT_ALL_TABLES','NO_ENGINE_SUBSTITUTION')");
				$this->query($connection, 'SET SESSION innodb_lock_wait_timeout=5');
			}
			$this->assert_lock();
			register_shutdown_function(array($this, 'release'));
		}
		catch (Exception $error) { $this->release(); throw new PaFileDbFileCoordinatorFailure('Download coordination unavailable', 0, $error); }
		catch (Error $error) { $this->release(); throw new PaFileDbFileCoordinatorFailure('Download coordination unavailable', 0, $error); }
	}

	private function query($connection, $sql)
	{
		try { $result = $connection->sql_query($sql); }
		catch (Exception $error) { $this->failed = true; throw $error; }
		catch (Error $error) { $this->failed = true; throw $error; }
		if ($result === false) { $this->failed = true; throw new PaFileDbFileCoordinatorFailure('Download coordinated statement failed'); }
		return $result;
	}

	private function rows($connection, $sql)
	{
		$result = $this->query($connection, $sql);
		try
		{
			try { $rows = $connection->sql_fetchrowset($result); }
			finally { $connection->sql_freeresult($result); }
			if (!is_array($rows)) { throw new PaFileDbFileCoordinatorFailure('Download coordinated rows unavailable'); }
			return $rows;
		}
		catch (Exception $error) { $this->failed = true; throw $error; }
		catch (Error $error) { $this->failed = true; throw $error; }
	}

	private function identity($connection)
	{
		$rows = $this->rows($connection, 'SELECT CONNECTION_ID() AS id,DATABASE() AS schema_name,@@SESSION.autocommit AS automatic,@@SESSION.in_transaction AS active,@@SESSION.character_set_connection AS charset');
		if (count($rows) !== 1 || !isset($rows[0]['id'], $rows[0]['schema_name'], $rows[0]['automatic'], $rows[0]['active'], $rows[0]['charset']) ||
			!preg_match('/^[1-9][0-9]*$/D', (string) $rows[0]['id']) || !is_string($rows[0]['schema_name']) || $rows[0]['schema_name'] === '' || $rows[0]['charset'] !== 'utf8mb4') { throw new PaFileDbFileCoordinatorFailure('Invalid Download connection identity'); }
		$rows[0]['id'] = (string) $rows[0]['id']; return $rows[0];
	}

	private function dedicated($source, $known, $identity)
	{
		$connection = $source->sql_dedicated_connection();
		if (!is_object($connection) || !isset($connection->db_connect_id) || !($connection->db_connect_id instanceof mysqli)) { throw new PaFileDbFileCoordinatorFailure('Missing native Download connection'); }
		foreach ($known as $borrowed)
		{
			if ($connection === $borrowed || (isset($borrowed->db_connect_id) && $connection->db_connect_id === $borrowed->db_connect_id)) { throw new PaFileDbFileCoordinatorFailure('Borrowed Download connection'); }
		}
		// Only a proven distinct native handle is ours to close on failure.
		try
		{
			$target = $this->identity($connection);
			// A factory may return an unrelated active participant. Disown it
			// before taking any lock or closing a handle with pending work.
			if ((string)$target['active'] !== '0' || (string)$target['automatic'] !== '1') { $connection = null; throw new PaFileDbFileCoordinatorFailure('Active foreign download participant'); }
			if ($target['schema_name'] !== $identity['schema_name'] || (string) $target['automatic'] !== '1') { throw new PaFileDbFileCoordinatorFailure('Wrong Download connection context'); }
			foreach ($known as $borrowed) { if ($this->identity($borrowed)['id'] === $target['id']) { throw new PaFileDbFileCoordinatorFailure('Reused Download connection identity'); } }
			return $connection;
		}
		catch (Exception $error) { if ($connection) { $connection->sql_close(); } throw $error; }
		catch (Error $error) { if ($connection) { $connection->sql_close(); } throw $error; }
	}

	function assert_lock()
	{
		// Failure is one-way: even if a caller catches an error or reacquires a
		// lost lock, it cannot commit a partially completed logical operation.
		if ($this->failed || !$this->held || !$this->writer || !$this->journal) { throw new PaFileDbFileCoordinatorFailure('Download namespace no longer owned'); }
		$rows = $this->rows($this->writer, "SELECT CONNECTION_ID() AS id,IS_USED_LOCK('" . $this->lock . "') AS owner");
		if (count($rows) !== 1 || (string) $rows[0]['id'] !== $this->writer_id || (string) $rows[0]['owner'] !== $this->writer_id) { $this->failed = true; throw new PaFileDbFileCoordinatorFailure('Download namespace ownership changed'); }
		return true;
	}

	function namespace_matches($prefix)
	{
		$this->assert_lock();
		return is_string($prefix) && $this->prefix === $prefix;
	}

	function publication_ready()
	{
		$this->assert_lock();
		return $this->ready && $this->transaction && !$this->journal_transaction && !$this->finished;
	}

	function claim_recovery()
	{
		if (!$this->publication_ready() || $this->operation_mode !== '') { throw new PaFileDbFileCoordinatorFailure('Recovery requires a fresh owning download transaction'); }
		$this->operation_mode = 'recovery';
	}

	function abort()
	{
		$this->failed = true;
		return false;
	}

	function guard_commit($guard)
	{
		if (!$this->publication_ready() || !is_callable($guard)) { throw new PaFileDbFileCoordinatorFailure('Invalid Download commit guard'); }
		$this->commit_guards[] = $guard;
	}

	function can_publish($preparing = false)
	{
		$this->assert_lock();
		$active = $preparing ? ($this->ready && $this->transaction && !$this->finished) : $this->publication_ready();
		if (!$active || !$this->commit_guards) { return false; }
		try { foreach ($this->commit_guards as $guard) { if (call_user_func($guard) !== true) { return $this->abort(); } } }
		catch (Exception $error) { $this->failed = true; throw $error; }
		catch (Error $error) { $this->failed = true; throw $error; }
		return true;
	}

	function pin_authority_table($suffix)
	{
		if (!$this->publication_ready() || !in_array($suffix, array('config','jr_admin_users','pa_config'), true)) { throw new PaFileDbFileCoordinatorFailure('Invalid Download authority table'); }
		$table = $this->prefix . $suffix;
		if (isset($this->tables[$table])) { return; }
		try { $this->pin($this->writer, $table); $this->tables[$table] = true; }
		catch (Exception $error) { $this->failed = true; throw $error; }
		catch (Error $error) { $this->failed = true; throw $error; }
	}

	private function pin($connection, $table)
	{
		$this->rows($connection, 'SELECT * FROM ' . $table . ' LIMIT 0');
		$rows = $this->rows($connection, "SELECT ENGINE,ROW_FORMAT,TABLE_COLLATION FROM information_schema.TABLES t WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . $table . "' AND TABLE_TYPE='BASE TABLE' AND NOT EXISTS(SELECT 1 FROM information_schema.COLUMNS c WHERE c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME='" . $table . "' AND c.CHARACTER_SET_NAME IS NOT NULL AND(c.CHARACTER_SET_NAME<>'utf8mb4' OR c.COLLATION_NAME<>'utf8mb4_unicode_ci'))");
		if (count($rows) !== 1 || $rows[0]['ENGINE'] !== 'InnoDB' || strtolower($rows[0]['ROW_FORMAT']) !== 'dynamic' || $rows[0]['TABLE_COLLATION'] !== 'utf8mb4_unicode_ci') { throw new PaFileDbFileCoordinatorFailure('Download operation requires modern transactional tables'); }
	}

	private function journal_shape($table)
	{
		$expected = array('operation_id'=>'char(32)','item_index'=>'int unsigned','schema_version'=>'smallint unsigned','operation_kind'=>'varchar(16)','actor_id'=>'mediumint','file_scope'=>'varchar(16)','file_dir'=>'varchar(255)','filename'=>'varchar(255)','file_bytes'=>'bigint unsigned','file_sha256'=>'char(64)','binding_hash'=>'char(64)','item_state'=>'tinyint unsigned','created_at'=>'int unsigned','completed_at'=>'int unsigned');
		$columns=$this->rows($this->writer,'SHOW FULL COLUMNS FROM '.$table);$actual=array();
		foreach($columns as$column){
			$type=preg_replace('/^(tinyint|smallint|mediumint|int|bigint)\\([0-9]+\\)/','$1',strtolower($column['Type']));
			$default=in_array($column['Field'],array('item_state','completed_at'),true)?'0':($column['Field']==='schema_version'?'1':null);
			if($column['Null']!=='NO'||$column['Extra']!==''||$column['Default']!==$default){throw new PaFileDbFileCoordinatorFailure('Download journal column protocol differs');}
			$actual[$column['Field']]=$type;
		}
		if($actual!==$expected){throw new PaFileDbFileCoordinatorFailure('Download journal columns differ');}
		$indexes=array();foreach($this->rows($this->writer,'SHOW INDEX FROM '.$table)as$index){
			if($index['Sub_part']!==null||$index['Index_type']!=='BTREE'||$index['Collation']!=='A'||(isset($index['Visible'])&&$index['Visible']!=='YES')||(isset($index['Ignored'])&&$index['Ignored']!=='NO')){throw new PaFileDbFileCoordinatorFailure('Download journal index protocol differs');}
			$indexes[$index['Key_name']][(int)$index['Seq_in_index']]=array($index['Column_name'],(int)$index['Non_unique']);
		}
		foreach($indexes as&$parts){ksort($parts,SORT_NUMERIC);$parts=array_values($parts);}unset($parts);ksort($indexes,SORT_STRING);
		$keys=array('PRIMARY'=>array(array('operation_id',0),array('item_index',0)),'operation_item'=>array(array('operation_id',0),array('binding_hash',0)),'pending_created'=>array(array('item_state',1),array('created_at',1)));
		if($indexes!==$keys){throw new PaFileDbFileCoordinatorFailure('Download journal exact identity indexes differ');}
	}

	function begin()
	{
		$this->assert_lock();
		if ($this->transaction || $this->journal_transaction || $this->finished) { throw new PaFileDbFileCoordinatorFailure('Download operation already started or finished'); }
		$this->query($this->writer, 'START TRANSACTION'); $this->transaction = true;
		foreach (array('users','sessions','user_group','groups','config','jr_admin_users','pa_config','pa_cat','pa_files','pa_votes','pa_comments','pa_custom','pa_customdata','pa_mirrors','pa_license','pa_auth','pa_file_journal') as $suffix)
		{
			$table = $this->prefix . $suffix; $this->pin($this->writer, $table); $this->tables[$table] = true;
		}
		$this->journal_shape($this->prefix.'pa_file_journal');
		$this->ready = true;
		return true;
	}

	private function allowed_statement($sql, $journal)
	{
		if (!is_string($sql)) { return false; }
		if (preg_match('/^SELECT\b/i', $sql)) { return true; }
		if (!preg_match('/^(?:INSERT INTO|UPDATE|DELETE FROM) ([A-Za-z0-9_]+)\b/i', $sql, $match)) { return false; }
		$writable = $journal ? array('pa_file_journal') : array('pa_files','pa_cat','pa_comments','pa_votes','pa_customdata','pa_mirrors','pa_auth','pa_file_journal');
		foreach ($writable as $suffix) { if ($match[1] === $this->prefix . $suffix && isset($this->tables[$match[1]])) { return true; } }
		return false;
	}

	function sql_query($sql, $transaction = false)
	{
		$this->assert_lock();
		if (!$this->ready || !$this->transaction || $this->finished || $transaction !== false || !$this->allowed_statement($sql, false)) { throw new PaFileDbFileCoordinatorFailure('Statement outside Download metadata transaction'); }
		if (!preg_match('/^SELECT\b/i', $sql)) {
			if (!$this->can_publish()) { throw new PaFileDbFileCoordinatorFailure('Missing current download mutation capability'); }
			if ($this->operation_mode === 'recovery' && !preg_match('/^UPDATE ' . preg_quote($this->prefix . 'pa_file_journal', '/') . '\b/', $sql)) { throw new PaFileDbFileCoordinatorFailure('Recovery cannot mutate download metadata'); }
			if ($this->operation_mode === '') { $this->operation_mode = 'producer'; }
		}
		$this->last_result = $this->query($this->writer, $sql);
		if (is_object($this->last_result)) { $this->results[spl_object_hash($this->last_result)] = $this->last_result; }
		// Ownership SELECTs must not overwrite the statement's driver metadata.
		try
		{
			$this->affected = $this->writer->sql_affectedrows();
			$this->insert_id = $this->writer->sql_nextid();
			if ($this->affected === false || $this->insert_id === false) { throw new PaFileDbFileCoordinatorFailure('Download statement result metadata unavailable'); }
		}
		catch (Exception $error) { $this->failed = true; throw $error; }
		catch (Error $error) { $this->failed = true; throw $error; }
		return $this->last_result;
	}

	function __call($method, $arguments)
	{
		if ($method === 'sql_freeresult')
		{
			// Freeing our buffered result is local memory cleanup, not a read or
			// write requiring a live namespace grant. In particular, finally must
			// not replace an ownership failure with another ownership exception
			// (PHP 5.6 can crash while unwinding an unfinished array append).
			$result = isset($arguments[0]) && $arguments[0] !== 0 ? $arguments[0] : $this->last_result;
			if (!$this->writer || !$this->ready || !$this->transaction || $this->finished || !is_object($result) || !isset($this->results[spl_object_hash($result)]) || $this->results[spl_object_hash($result)] !== $result) { throw new PaFileDbFileCoordinatorFailure('Result does not belong to this Download operation'); }
			unset($this->results[spl_object_hash($result)]); if ($this->last_result === $result) { $this->last_result = false; }
			return $this->writer->sql_freeresult($result);
		}
		$this->assert_lock();
		if (!in_array($method, array('sql_escape','sql_fetchrow','sql_fetchrowset','sql_freeresult','sql_numrows','sql_affectedrows','sql_nextid'), true) || !$this->ready || !$this->transaction || $this->finished) { throw new PaFileDbFileCoordinatorFailure('Invalid Download metadata access'); }
		if ($method === 'sql_affectedrows') { return $this->affected; }
		if ($method === 'sql_nextid') { return $this->insert_id; }
		if (in_array($method, array('sql_fetchrow','sql_fetchrowset','sql_freeresult','sql_numrows'), true) && (!isset($arguments[0]) || $arguments[0] === 0)) { $arguments[0] = $this->last_result; }
		try { return call_user_func_array(array($this->writer, $method), $arguments); }
		catch (Exception $error) { $this->failed = true; throw $error; }
		catch (Error $error) { $this->failed = true; throw $error; }
	}

	function durable_begin()
	{
		$this->assert_lock();
		if (!$this->ready || !$this->transaction || $this->finished || $this->journal_transaction) { throw new PaFileDbFileCoordinatorFailure('Invalid prepared Download receipt transaction'); }
		if ($this->operation_mode === 'recovery') { throw new PaFileDbFileCoordinatorFailure('Recovery cannot prepare a new download publication'); }
		if (!$this->can_publish()) { throw new PaFileDbFileCoordinatorFailure('Missing download preparation capability'); }
		$this->operation_mode = 'producer';
		$this->query($this->journal, 'START TRANSACTION'); $this->journal_transaction = true;
		foreach (array('pa_file_journal') as $suffix) { $this->pin($this->journal, $this->prefix . $suffix); }
	}

	function durable_query($sql)
	{
		$this->assert_lock();
		if (!$this->ready || !$this->journal_transaction || !$this->transaction || $this->finished || !$this->allowed_statement($sql, true)) { throw new PaFileDbFileCoordinatorFailure('Statement outside prepared Download receipt transaction'); }
		if (!preg_match('/^SELECT\b/i', $sql) && !$this->can_publish(true)) { throw new PaFileDbFileCoordinatorFailure('Missing current download preparation capability'); }
		return $this->query($this->journal, $sql);
	}

	function durable_commit()
	{
		$this->assert_lock();
		if (!$this->ready || !$this->journal_transaction || !$this->transaction || $this->finished) { throw new PaFileDbFileCoordinatorFailure('No owned prepared Download receipt transaction'); }
		try { $this->query($this->journal, 'COMMIT'); $this->journal_transaction = false; }
		catch (Exception $error) { $this->release(); throw $error; }
		catch (Error $error) { $this->release(); throw $error; }
	}

	// Reads committed receipts, including after the metadata commit. Keeping the
	// namespace lock through cleanup excludes another cooperating publisher.
	function durable_rows($sql)
	{
		$this->assert_lock();
		if ($this->journal_transaction || !is_string($sql) || !preg_match('/^SELECT\b/i', $sql)) { throw new PaFileDbFileCoordinatorFailure('Download receipt read is not committed'); }
		return $this->rows($this->journal, $sql);
	}

	function commit()
	{
		$this->assert_lock();
		if (!$this->ready || !$this->transaction || $this->journal_transaction || $this->finished) { throw new PaFileDbFileCoordinatorFailure('No owned Download metadata transaction'); }
		try
		{
			if (!$this->can_publish()) { throw new PaFileDbFileCoordinatorFailure('Download commit authority expired'); }
			$this->query($this->writer, 'COMMIT'); $this->transaction = false; $this->finished = true; return true;
		}
		catch (Exception $error) { $this->release(); throw $error; }
		catch (Error $error) { $this->release(); throw $error; }
	}

	function release()
	{
		if ($this->writer)
		{
			foreach ($this->results as $result) { try { $this->writer->sql_freeresult($result); } catch (Exception $error) {} catch (Error $error) {} }
		}
		$this->results = array();
		// Close only our two proven distinct handles, never the request database.
		// Closing also resolves an unacknowledged COMMIT without inferring whether
		// it committed; its durable receipt is deliberately left for recovery.
		foreach (array('journal', 'writer') as $name)
		{
			$connection = $this->$name; $this->$name = null;
			if ($connection)
			{
				if (($name === 'journal' && $this->journal_transaction) || ($name === 'writer' && $this->transaction))
				{ try { $connection->sql_query('ROLLBACK'); } catch (Exception $error) {} catch (Error $error) {} }
				try { $connection->sql_close(); } catch (Exception $error) {} catch (Error $error) {}
			}
		}
		$this->held = false; $this->transaction = false; $this->journal_transaction = false; $this->finished = true; $this->ready = false; $this->last_result = false;
	}
}
