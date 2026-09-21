<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__DIR__) . '/attach_mod/includes/functions_mutation.php';

class PhpbbActivationException extends RuntimeException {}
function phpbb_activation_error($key = 'Activation_save_failed')
{
	global $lang;
	throw new PhpbbActivationException(isset($lang[$key]) ? $lang[$key] : $key);
}

// Token consumption, credentials and login revocation are one publication.
// A normal activation link is a bearer capability; admin activation additionally
// requires a current root login (not an ACP reauthentication or delegated grant).
class PhpbbActivationDatabase
{
	var $lock;
	var $connection = null;
	var $transactional = false;
	function __construct($database)
	{
		$this->lock = new attach_mutation_lock($database);
		if (!$this->lock->acquired) { phpbb_activation_error('Attachment_storage_busy'); }
		$this->connection = $this->lock->connection;
		register_shutdown_function(array($this, 'release'));
		try
		{
			$this->control("SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_ALL_TABLES')");
			$this->control('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
			$this->control('START TRANSACTION'); $this->transactional = true;
			foreach (array(USERS_TABLE, SESSIONS_TABLE, SESSIONS_KEYS_TABLE, CONFIG_TABLE) as $table)
			{
				$r = $this->sql_query('SELECT * FROM ' . $table . ' LIMIT 0'); $this->sql_freeresult($r);
				$name = $this->sql_escape($table);
				$rows = $this->rows("SELECT ENGINE,ROW_FORMAT,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$name'"
					. " AND NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$name' AND CHARACTER_SET_NAME IS NOT NULL AND (CHARACTER_SET_NAME<>'utf8mb4' OR COLLATION_NAME<>'utf8mb4_unicode_ci'))");
				if (count($rows) !== 1 || $rows[0]['ENGINE'] !== 'InnoDB' || strtolower($rows[0]['ROW_FORMAT']) !== 'dynamic' || $rows[0]['TABLE_COLLATION'] !== 'utf8mb4_unicode_ci') { phpbb_activation_error('Activation_storage_upgrade'); }
			}
		}
		catch (Exception $e) { $this->release(); throw $e; }
		catch (Error $e) { $this->release(); throw $e; }
	}
	function __call($method, $args)
	{
		if ($this->connection === null) { phpbb_activation_error(); }
		return call_user_func_array(array($this->connection, $method), $args);
	}
	private function control($sql)
	{
		if ($this->connection === null) { phpbb_activation_error(); }
		$r = $this->connection->sql_query($sql); if (!$r) { phpbb_activation_error(); } return $r;
	}
	function sql_query($sql, $transaction = false)
	{
		if (!$this->transactional || !is_string($sql) || !preg_match('/^\s*(SELECT|UPDATE|DELETE)\b/i', $sql)) { phpbb_activation_error(); }
		return $this->control($sql);
	}
	function rows($sql)
	{
		$r = $this->sql_query($sql); $rows = $this->sql_fetchrowset($r); $this->sql_freeresult($r); return $rows;
	}
	function commit()
	{
		if (!$this->transactional) { phpbb_activation_error(); }
		$this->control('COMMIT'); $this->transactional = false;
	}
	function release()
	{
		if ($this->connection === null) { return; }
		if ($this->transactional) { try { $this->connection->sql_query('ROLLBACK'); } catch (Exception $e) {} catch (Error $e) {} }
		$this->transactional = false; $this->connection = null; $this->lock->release();
	}
}

function phpbb_account_activate($database, $expected, $key, $new_hash = null, $sid = '')
{
	global $userdata, $board_config;
	if (!is_array($expected) || !isset($expected['user_id']) || !preg_match('/^[1-9][0-9]{0,6}$/D', (string)$expected['user_id']) || (int)$expected['user_id'] > 8388607
		|| !is_string($key) || !preg_match('/^[a-f0-9]{6,32}$/iD', $key)) { phpbb_activation_error('Wrong_activation'); }
	$id = (int)$expected['user_id']; $db = new PhpbbActivationDatabase($database);
	try
	{
		$policy = array();
		foreach ($db->rows("SELECT config_name,config_value FROM " . CONFIG_TABLE . " WHERE config_name IN ('require_activation','min_password_len','password_not_login','force_complex_password','password_hashing') LOCK IN SHARE MODE") as $setting) { $policy[$setting['config_name']] = $setting['config_value']; }
		if (count($policy) !== 5 || !in_array((string)$policy['require_activation'], array('0','1','2'), true)) { phpbb_activation_error('Activation_storage_upgrade'); }
		$rows = $db->rows('SELECT user_active,user_id,username,user_email,user_password,user_newpasswd,user_lang,user_actkey,ct_last_pw_reset FROM ' . USERS_TABLE . ' WHERE user_id=' . $id . ' FOR UPDATE');
		if (count($rows) !== 1) { phpbb_activation_error('Wrong_activation'); }
		$row = $rows[0];
		foreach (array('user_active','username','user_email','user_password','user_newpasswd','user_lang','user_actkey','ct_last_pw_reset') as $field)
		{ if (!array_key_exists($field, $expected) || (string)$row[$field] !== (string)$expected[$field]) { phpbb_activation_error('Wrong_activation'); } }
		if ($row['user_actkey'] === '' || !hash_equals((string)$row['user_actkey'], $key)) { phpbb_activation_error('Wrong_activation'); }
		$reset = $row['user_newpasswd'] === PHPBB_PASSWORD_RESET_PENDING;
		$legacy = !$reset && $row['user_newpasswd'] !== '' && $row['user_newpasswd'] !== null;
		$admin = !$reset && !$legacy && (int)$policy['require_activation'] === USER_ACTIVATION_ADMIN;
		if ($reset || $legacy)
		{
			// A reset must never reactivate a subsequently disabled account.
			if ((int)$row['user_active'] !== 1) { phpbb_activation_error('Wrong_activation'); }
		}
		if ($reset || $admin)
		{
			$token = isset($userdata['session_id']) && is_string($userdata['session_id']) ? $userdata['session_id'] : '';
			$actor_id = isset($userdata['user_id']) ? (int)$userdata['user_id'] : 0;
			if ($token === '' || ($reset && (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST' || !is_string($sid) || !hash_equals($token, $sid)))) { phpbb_activation_error('Session_invalid'); }
			$token_sql = $db->sql_escape($token);
			$session = $db->rows('SELECT session_id FROM ' . SESSIONS_TABLE . " WHERE session_id='$token_sql' AND HEX(session_id)=HEX('$token_sql') AND session_user_id=" . $actor_id . ' AND session_logged_in=' . (empty($userdata['session_logged_in']) ? 0 : 1) . ' LOCK IN SHARE MODE');
			if (count($session) !== 1) { phpbb_activation_error('Session_invalid'); }
			if ($admin)
			{
				$actor = $db->rows('SELECT user_id FROM ' . USERS_TABLE . ' WHERE user_id=' . $actor_id . ' AND user_active=1 AND user_level=' . ADMIN . ' LOCK IN SHARE MODE');
				if (empty($userdata['session_logged_in']) || $actor_id <= 0 || count($actor) !== 1) { phpbb_activation_error('Not_Authorised'); }
			}
		}
		$now = time(); $password_sql = '';
		if ($reset)
		{
			if ((int)$row['ct_last_pw_reset'] < $now) { phpbb_activation_error('Password_reset_expired'); }
			// The controller validated/hashed against this policy and username.
			// A policy change during hashing requires a fresh form submission.
			foreach (array('min_password_len','password_not_login','force_complex_password','password_hashing') as $field)
			{ if (!isset($board_config[$field]) || (string)$policy[$field] !== (string)$board_config[$field]) { phpbb_activation_error('Wrong_activation'); } }
			if (!is_string($new_hash) || $new_hash === '') { phpbb_activation_error('Password_hash_failed'); }
			$password_sql = ",user_password='" . $db->sql_escape($new_hash) . "',user_newpasswd='',user_passwd_change=$now,ct_last_pw_change=$now";
		}
		elseif ($legacy)
		{
			$info = password_get_info($row['user_newpasswd']);
			if (!preg_match('/^[a-f0-9]{32}$/iD', $row['user_newpasswd']) && $info['algoName'] === 'unknown') { phpbb_activation_error('Wrong_activation'); }
			$password_sql = ",user_password='" . $db->sql_escape($row['user_newpasswd']) . "',user_newpasswd='',user_passwd_change=" . ($row['user_newpasswd'] === $row['user_password'] ? $now : 0) . ",ct_last_pw_change=$now";
		}
		elseif ($new_hash !== null) { phpbb_activation_error('Wrong_activation'); }
		$key_sql = $db->sql_escape($key);
		$db->sql_query('UPDATE ' . USERS_TABLE . " SET user_active=1,user_actkey=''" . $password_sql . " WHERE user_id=$id AND HEX(user_actkey)=HEX('$key_sql')");
		if ((int)$db->sql_affectedrows() !== 1) { phpbb_activation_error('Wrong_activation'); }
		$guest = null;
		if ($reset || $legacy)
		{
			$db->sql_query('DELETE FROM ' . SESSIONS_KEYS_TABLE . ' WHERE user_id=' . $id);
			$db->sql_query('DELETE FROM ' . SESSIONS_TABLE . ' WHERE session_user_id=' . $id);
			if (!empty($userdata['session_logged_in']) && (int)$userdata['user_id'] === $id)
			{
				$rows = $db->rows('SELECT * FROM ' . USERS_TABLE . ' WHERE user_id=' . ANONYMOUS);
				if (count($rows) !== 1) { phpbb_activation_error(); } $guest = $rows[0];
			}
		}
		$db->commit();
		return array('row'=>$row, 'reset'=>$reset, 'legacy'=>$legacy, 'admin'=>$admin, 'guest'=>$guest);
	}
	finally { $db->release(); }
}

function phpbb_activation_publish_logout($result)
{
	global $userdata, $board_config, $SID;
	if ($result['guest'] === null) { return; }
	foreach (array('_data','_sid') as $suffix) { phpbb_setcookie($board_config['cookie_name'] . $suffix, '', time()-31536000, $board_config['cookie_path'], $board_config['cookie_domain'], $board_config['cookie_secure']); }
	$userdata = $result['guest']; $userdata['session_logged_in'] = false; $userdata['session_id'] = ''; $userdata['session_key'] = ''; $SID = '';
}
