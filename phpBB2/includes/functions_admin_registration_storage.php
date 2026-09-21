<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_acl_storage.php';

// ACP quick-add has its own delegated module, not the full user editor's grant.
// Reserve the durable user ID before entering this owner, never inside rollback.
class PhpbbAdminRegistrationScope extends PhpbbAclDatabase
{
	var $original;
	var $lock;
	var $transactional = false;
	var $confirmed = false;
	var $current_reads = false;
	function __construct($database, $request, $username, $email, $style)
	{
		global $db, $userdata, $board_config, $phpEx;
		$this->original = $database;
		if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST' || !is_array($request)
			|| !isset($userdata['session_id'], $request['sid']) || !is_string($userdata['session_id']) || $userdata['session_id'] === ''
			|| !is_string($request['sid']) || !hash_equals($userdata['session_id'], $request['sid'])) { phpbb_acl_error('Session_invalid'); }
		$id = phpbb_acl_id(isset($userdata['user_id']) ? $userdata['user_id'] : null);
		$this->lock = new attach_mutation_lock($database);
		if (!$this->lock->acquired) { phpbb_acl_error('Attachment_storage_busy'); }
		parent::__construct($this->lock->connection, 'Admin_profile_save_failed');
		register_shutdown_function(array($this, 'release'));
		try
		{
			$this->control("SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_ALL_TABLES')");
			$this->control('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
			$this->control('START TRANSACTION'); $this->transactional = true; $db = $this;
			foreach (array(USERS_TABLE, SESSIONS_TABLE, JR_ADMIN_TABLE, CONFIG_TABLE, GROUPS_TABLE, USER_GROUP_TABLE,
				DISALLOW_TABLE, WORDS_TABLE, BANLIST_TABLE, THEMES_TABLE) as $table)
			{
				$r = $this->sql_query('SELECT * FROM ' . $table . ' LIMIT 0'); $this->sql_freeresult($r);
				$name = $this->sql_escape($table);
				$rows = phpbb_acl_rows($this, "SELECT ENGINE,ROW_FORMAT,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$name'"
					. " AND NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$name' AND CHARACTER_SET_NAME IS NOT NULL AND (CHARACTER_SET_NAME<>'utf8mb4' OR COLLATION_NAME<>'utf8mb4_unicode_ci'))");
				if (count($rows) !== 1 || $rows[0]['ENGINE'] !== 'InnoDB' || strtolower($rows[0]['ROW_FORMAT']) !== 'dynamic' || $rows[0]['TABLE_COLLATION'] !== 'utf8mb4_unicode_ci') { phpbb_acl_error('Registration_storage_upgrade'); }
			}
			// All subsequent validation reads are current locking reads, not an
			// earlier RR snapshot. Hold exact actor/session/delegation through ACK.
			$this->current_reads = true; $sid = $this->sql_escape($request['sid']);
			phpbb_acl_rows($this, 'SELECT session_id FROM ' . SESSIONS_TABLE . " WHERE session_id='$sid' AND HEX(session_id)=HEX('$sid')");
			phpbb_acl_rows($this, 'SELECT user_id FROM ' . USERS_TABLE . ' WHERE user_id=' . $id);
			phpbb_acl_rows($this, 'SELECT user_id FROM ' . JR_ADMIN_TABLE . ' WHERE user_id=' . $id);
			phpbb_acp_actor($this, 'admin_user_register.' . $phpEx);
			$keys = array('min_password_len','password_not_login','force_complex_password','password_hashing');
			$rows = phpbb_acl_rows($this, 'SELECT config_name,config_value FROM ' . CONFIG_TABLE . " WHERE config_name IN ('" . implode("','", $keys) . "')");
			if (count($rows) !== count($keys)) { phpbb_acl_error('Registration_storage_upgrade'); }
			foreach ($rows as $row)
			{ if (!isset($board_config[$row['config_name']]) || (string)$row['config_value'] !== (string)$board_config[$row['config_name']]) { phpbb_acl_error('Acl_selection_changed'); } }
			if (!phpbb_acl_rows($this, 'SELECT themes_id FROM ' . THEMES_TABLE . ' WHERE themes_id=' . (int)$style)) { phpbb_acl_error('Acl_selection_changed'); }
			// Explicit predicates also reject the administrator's own identity;
			// legacy profile validators exempt the current user's name.
			if (phpbb_acl_rows($this, 'SELECT user_id FROM ' . USERS_TABLE . " WHERE username='" . $this->sql_escape($username) . "'")) { phpbb_acl_error('Username_taken'); }
			if (phpbb_acl_rows($this, 'SELECT user_id FROM ' . USERS_TABLE . " WHERE user_email='" . $this->sql_escape($email) . "'")) { phpbb_acl_error('Email_taken'); }
			foreach (array(validate_username($username, false), validate_email($email, false)) as $checked)
			{ if ($checked['error']) { throw new PhpbbAclException($checked['error_msg']); } }
		}
		catch (Exception $e) { $this->release(); throw $e; }
		catch (Error $e) { $this->release(); throw $e; }
	}
	private function control($sql)
	{
		if ($this->connection === null) { phpbb_acl_error($this->failure_key); }
		return parent::sql_query($sql);
	}
	function sql_query($sql, $transaction = false)
	{
		if (!$this->transactional || !is_string($sql) || !preg_match('/^\s*(SELECT|INSERT|UPDATE|DELETE)\b/i', $sql)) { phpbb_acl_error($this->failure_key); }
		if ($this->current_reads && preg_match('/^\s*SELECT\b/i', $sql)) { $sql = rtrim($sql, "; \t\r\n") . ' LOCK IN SHARE MODE'; }
		return $this->control($sql);
	}
	function finish()
	{
		if (!$this->transactional || $this->confirmed) { phpbb_acl_error($this->failure_key); }
		$this->control('COMMIT'); $this->transactional = false; $this->confirmed = true;
		$this->release();
	}
	function release()
	{
		global $db;
		if ($this->connection === null) { return; }
		if ($this->transactional) { try { $this->connection->sql_query('ROLLBACK'); } catch (Exception $e) {} catch (Error $e) {} }
		$this->transactional = false; $this->connection = null; $this->lock->release();
		if ($db === $this) { $db = $this->original; }
	}
}
