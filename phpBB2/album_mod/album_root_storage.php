<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__DIR__) . '/includes/functions_moderator_identity.php';

class AlbumRootStorageFailure extends RuntimeException {}

// This operation owns its connection and transaction. Never commit, roll back
// or replace an upload/category caller's unrelated transaction or named locks.
class AlbumRootStorage
{
	var $connection = null;
	var $owned = false;
	var $transactional = false;
	var $ready = false;
	var $finished = false;
	var $mode = '';
	var $pinned = array();
	var $actor_id = false;
	var $actor_locked = false;

	function __construct($database)
	{
		if (!is_object($database) || !method_exists($database, 'sql_dedicated_connection')) { throw new AlbumRootStorageFailure(); }
		try
		{
			$source = $this->connection_info($database);
			$connection = $database->sql_dedicated_connection();
			if (!is_object($connection) || $connection === $database) { throw new AlbumRootStorageFailure(); }
			$this->connection = $connection;
			if (isset($database->db_connect_id, $connection->db_connect_id) &&
				$database->db_connect_id instanceof mysqli && $connection->db_connect_id instanceof mysqli &&
				$database->db_connect_id !== $connection->db_connect_id) { $this->owned = true; }
			$target = $this->connection_info($connection);
			if ($target['connection_id'] === $source['connection_id'])
			{
				if (!$this->owned) { $this->connection = null; }
				throw new AlbumRootStorageFailure();
			}
			$this->owned = true;
			if ($target['schema_name'] !== $source['schema_name']) { throw new AlbumRootStorageFailure(); }
			$this->mode = $source['sql_mode'];
			register_shutdown_function(array($this, 'release'));
		}
		catch (Exception $error) { $this->release(); throw new AlbumRootStorageFailure(); }
		catch (Error $error) { $this->release(); throw new AlbumRootStorageFailure(); }
	}

	function __call($method, $arguments)
	{
		if (!$this->connection || !is_callable(array($this->connection, $method))) { throw new AlbumRootStorageFailure(); }
		return call_user_func_array(array($this->connection, $method), $arguments);
	}

	private function rows($database, $sql)
	{
		$result = $database->sql_query($sql);
		if (!$result) { throw new AlbumRootStorageFailure(); }
		try { $rows = $database->sql_fetchrowset($result); }
		finally { $database->sql_freeresult($result); }
		if (!is_array($rows)) { throw new AlbumRootStorageFailure(); }
		return $rows;
	}

	private function connection_info($database)
	{
		$rows = $this->rows($database, 'SELECT CONNECTION_ID() AS connection_id, DATABASE() AS schema_name, @@SESSION.sql_mode AS sql_mode');
		if (count($rows) !== 1 || !isset($rows[0]['connection_id'], $rows[0]['schema_name'], $rows[0]['sql_mode']) ||
			(!is_int($rows[0]['connection_id']) && !is_string($rows[0]['connection_id'])) || !preg_match('/^[0-9]+$/D', (string) $rows[0]['connection_id']) || !is_string($rows[0]['schema_name']) ||
			$rows[0]['schema_name'] === '' || !is_string($rows[0]['sql_mode']) || !preg_match('/^[A-Z0-9_,]*$/D', $rows[0]['sql_mode']))
		{ throw new AlbumRootStorageFailure(); }
		$rows[0]['connection_id'] = (string) $rows[0]['connection_id'];
		return $rows[0];
	}

	private function control($sql)
	{
		if (!$this->owned || !$this->connection || !$this->connection->sql_query($sql)) { throw new AlbumRootStorageFailure(); }
	}

	private function pin($table)
	{
		if (!$this->transactional || !is_string($table) || !preg_match('/^[A-Za-z0-9_]{1,64}$/D', $table)) { throw new AlbumRootStorageFailure(); }
		if (isset($this->pinned[$table])) { return; }
		$this->rows($this->connection, 'SELECT * FROM ' . $table . ' LIMIT 0');
		$name = $this->connection->sql_escape($table);
		$rows = $this->rows($this->connection, "SELECT ENGINE, ROW_FORMAT, TABLE_COLLATION FROM information_schema.TABLES t WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . $name . "'"
			. " AND NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS c WHERE c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME='" . $name . "' AND c.CHARACTER_SET_NAME IS NOT NULL AND (c.CHARACTER_SET_NAME <> 'utf8mb4' OR c.COLLATION_NAME <> 'utf8mb4_unicode_ci'))");
		if (count($rows) !== 1 || $rows[0]['ENGINE'] !== 'InnoDB' || strtolower($rows[0]['ROW_FORMAT']) !== 'dynamic' || $rows[0]['TABLE_COLLATION'] !== 'utf8mb4_unicode_ci')
		{ throw new AlbumRootStorageFailure(); }
		$this->pinned[$table] = true;
	}

	function begin($owner)
	{
		global $userdata;
		if ($this->transactional || $this->finished || !is_int($owner) || $owner <= 0 || $owner > 8388607) { throw new AlbumRootStorageFailure(); }
		$mode = $this->mode === '' ? 'STRICT_ALL_TABLES' : $this->mode . ',STRICT_ALL_TABLES';
		$this->control("SET SESSION sql_mode='" . $this->connection->sql_escape($mode) . "'");
		$this->control('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
		$this->control('SET SESSION innodb_lock_wait_timeout=5');
		$this->control('START TRANSACTION'); $this->transactional = true;
		$this->pin(USERS_TABLE); $this->pin(ALBUM_CAT_TABLE);
		// All creators of this owner's root serialize on an existing primary
		// key, even while the category table has no row for that owner yet.
		$this->actor_id = isset($userdata['user_id']) ? album_user_id_value($userdata['user_id']) : false;
		$ids = array($owner);
		if ($this->actor_id !== false && $this->actor_id > 0 && $this->actor_id !== $owner) { $ids[] = $this->actor_id; }
		sort($ids, SORT_NUMERIC);
		$where = count($ids) === 1 ? 'user_id=' . $owner : 'user_id IN(' . implode(',', $ids) . ') ORDER BY user_id';
		$rows = $this->rows($this->connection, 'SELECT user_id FROM ' . USERS_TABLE . ' WHERE ' . $where . ' FOR UPDATE');
		$owners = 0; $actors = 0;
		foreach ($rows as $row)
		{
			$id = isset($row['user_id']) ? album_user_id_value($row['user_id']) : false;
			if ($id === $owner) { $owners++; }
			if ($id !== false && $id === $this->actor_id) { $actors++; }
		}
		$this->ready = $owners === 1;
		$this->actor_locked = $actors === 1;
		return $this->ready;
	}

	function actor()
	{
		if (!$this->ready || !$this->actor_locked || $this->actor_id === false || $this->actor_id <= 0) { return false; }
		// Both actor and owner rows stay locked through COMMIT. A request's
		// earlier role/active snapshot is not a current creation grant.
		// An ID absent at lock time must not acquire authority from a later
		// replacement account inserted with that same ID.
		$actor = phpbb_current_moderator_user($this);
		if (!$actor || album_user_id_value($actor['user_id']) !== $this->actor_id) { return false; }
		global $userdata, $board_config;
		$sid = isset($userdata['session_id']) ? $userdata['session_id'] : null;
		if (!is_string($sid) || !preg_match('/^[a-f0-9]{32}$/iD', $sid) || !defined('SESSIONS_TABLE')) { return false; }
		$lifetime = is_array($board_config) && isset($board_config['session_length']) ? $board_config['session_length'] : null;
		if ((!is_int($lifetime) && !is_string($lifetime)) || !preg_match('/^[0-9]{1,10}$/D', (string) $lifetime) ||
			(float) $lifetime > 2147483647 || (int) $lifetime < 1) { return false; }
		$expiry = time() - (int) $lifetime;
		$this->pin(SESSIONS_TABLE);
		$sid = $this->connection->sql_escape($sid);
		// Use the primary key first, then compare exact bytes despite its text
		// collation. Retain this live login row until the root has committed;
		// an earlier session_logged_in snapshot does not survive a real logout.
		$sessions = $this->rows($this->connection, 'SELECT session_user_id, session_logged_in FROM ' . SESSIONS_TABLE .
			" WHERE session_id='" . $sid . "' AND HEX(session_id)=HEX('" . $sid . "') AND session_time > " . $expiry . ' FOR UPDATE');
		if (count($sessions) !== 1 || !isset($sessions[0]['session_user_id'], $sessions[0]['session_logged_in']) ||
			album_user_id_value($sessions[0]['session_user_id']) !== $this->actor_id ||
			(!is_int($sessions[0]['session_logged_in']) && !is_string($sessions[0]['session_logged_in'])) ||
			!preg_match('/^0*1$/D', (string) $sessions[0]['session_logged_in'])) { return false; }
		return $actor;
	}

	function sql_query($sql, $transaction = false)
	{
		if (!$this->transactional || !$this->ready || $this->finished || !is_string($sql)) { throw new AlbumRootStorageFailure(); }
		$read = preg_match('/^\s*SELECT\b/i', $sql);
		// Inspect SELECT structure only. A quoted title/name in an INSERT may
		// itself contain "FROM <table>" and must remain ordinary stored text.
		if ($read && defined('USER_GROUP_TABLE') && preg_match('/\bFROM\s+' . preg_quote(USER_GROUP_TABLE, '/') . '\b/i', $sql))
		{
			$this->pin(USER_GROUP_TABLE);
			// A private-group grant cannot disappear between its check and the
			// root commit. No group membership is changed by this operation.
			$sql .= ' LOCK IN SHARE MODE';
		}
		if (!$read && !preg_match('/^INSERT INTO ' . preg_quote(ALBUM_CAT_TABLE, '/') . '\s*\(/', $sql)) { throw new AlbumRootStorageFailure(); }
		$result = $this->connection->sql_query($sql, $transaction);
		// Do not enter message_die's terminating renderer while global db is
		// the owned writer. Restore/close first; the caller renders its failure.
		if (!$result && $read) { throw new AlbumRootStorageFailure(); }
		return $result;
	}

	function sql_numrows($result = 0)
	{
		if (!$this->connection || !$this->transactional) { throw new AlbumRootStorageFailure(); }
		$count = $this->connection->sql_numrows($result);
		if ($count === false) { throw new AlbumRootStorageFailure(); }
		return $count;
	}

	function commit()
	{
		if (!$this->transactional || !$this->ready || $this->finished) { throw new AlbumRootStorageFailure(); }
		$this->control('COMMIT'); $this->transactional = false; $this->finished = true;
		return true;
	}

	function release()
	{
		$connection = $this->connection; $this->connection = null;
		if ($this->owned && $connection)
		{
			if ($this->transactional)
			{
				try { $connection->sql_query('ROLLBACK'); } catch (Exception $error) {} catch (Error $error) {}
			}
			try { $connection->sql_close(); } catch (Exception $error) {} catch (Error $error) {}
		}
		$this->owned = false; $this->transactional = false; $this->ready = false; $this->actor_locked = false; $this->finished = true;
	}
}
