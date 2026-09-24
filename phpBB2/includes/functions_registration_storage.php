<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__DIR__) . '/attach_mod/includes/functions_mutation.php';

class PhpbbRegistrationException extends RuntimeException {}
function phpbb_registration_error($key = 'Registration_save_failed')
{
	global $lang;
	throw new PhpbbRegistrationException(isset($lang[$key]) ? $lang[$key] : $key);
}

// ID reservation deliberately precedes this transaction and is never undone.
// The shared mutex coordinates bundled writers; next-key locks also prevent
// changes/inserts through independent connections during local validation.
class PhpbbRegistrationScope
{
	var $original;
	var $lock;
	var $connection = null;
	var $transactional = false;
	var $confirmed = false;
	var $commit_attempted = false;
	var $avatar;
	var $rate_identity = null;
	var $locking_validation = false;
	var $profile_fields = array();
	function __construct($database, $sid, $username, $email, $profile_data, $avatar)
	{
		global $db, $userdata, $board_config, $plus_config, $ctracker_config, $lang, $user_ip;
		$this->original = $database; $this->avatar = $avatar;
		if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST' || !empty($userdata['session_logged_in'])
			|| !isset($userdata['user_id'], $userdata['session_id']) || (int)$userdata['user_id'] !== ANONYMOUS
			|| !is_string($sid) || $sid === '' || !is_string($userdata['session_id']) || !hash_equals($userdata['session_id'], $sid)) { phpbb_registration_error('Session_invalid'); }
		$this->lock = new attach_mutation_lock($database);
		if (!$this->lock->acquired) { phpbb_registration_error('Attachment_storage_busy'); }
		$this->connection = $this->lock->connection;
		register_shutdown_function(array($this, 'release'));
		try
		{
			$this->control("SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_ALL_TABLES')");
			$this->control('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
			$this->control('START TRANSACTION'); $this->transactional = true; $db = $this;
			foreach (array(USERS_TABLE, SESSIONS_TABLE, CONFIG_TABLE, PLUS_TABLE, CTRACKER_CONFIG, GROUPS_TABLE, USER_GROUP_TABLE,
				PROFILE_FIELDS_TABLE, DISALLOW_TABLE, WORDS_TABLE, BANLIST_TABLE, CONFIRM_TABLE, ANTI_ROBOT_TABLE, CTRACKER_RATE_LIMITS) as $table)
			{
				$r = $this->sql_query('SELECT * FROM ' . $table . ' LIMIT 0'); $this->sql_freeresult($r);
				$name = $this->sql_escape($table);
				$rows = $this->rows("SELECT ENGINE,ROW_FORMAT,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$name'"
					. " AND NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$name' AND CHARACTER_SET_NAME IS NOT NULL AND (CHARACTER_SET_NAME<>'utf8mb4' OR COLLATION_NAME<>'utf8mb4_unicode_ci'))");
				if (count($rows) !== 1 || $rows[0]['ENGINE'] !== 'InnoDB' || strtolower($rows[0]['ROW_FORMAT']) !== 'dynamic' || $rows[0]['TABLE_COLLATION'] !== 'utf8mb4_unicode_ci') { phpbb_registration_error('Registration_storage_upgrade'); }
			}
			$token = $this->sql_escape($sid);
			if (count($this->rows('SELECT session_id FROM ' . SESSIONS_TABLE . " WHERE session_id='$token' AND HEX(session_id)=HEX('$token') AND session_user_id=" . ANONYMOUS . ' AND session_logged_in=0 LOCK IN SHARE MODE')) !== 1) { phpbb_registration_error('Session_invalid'); }
			$this->pin_settings(CONFIG_TABLE, self::policy_keys(), $board_config);
			$this->pin_settings(PLUS_TABLE, array('enable_antirobot'), $plus_config);
			$this->pin_settings(CTRACKER_CONFIG, array('reg_protection','reg_blocktime','autoban_mails','spam_keyword_det','pw_complex','pw_complex_min','pw_complex_mode'), $ctracker_config->settings, 'ct_config_name', 'ct_config_value');
			if ((string)$board_config['registration_status'] !== '0') { phpbb_registration_error('registration_status'); }
			if (!in_array((string)$board_config['require_activation'], array('0','1','2'), true)) { phpbb_registration_error('Registration_changed'); }
			// Freeze local name/ban/custom-field rules, including empty ranges. Then
			// the regular validation reads the same current committed state.
			foreach (array(GROUPS_TABLE => 'group_id', DISALLOW_TABLE => 'disallow_id', WORDS_TABLE => 'word_id', BANLIST_TABLE => 'ban_id') as $table => $column)
			{ $this->rows('SELECT ' . $column . ' FROM ' . $table . ' LOCK IN SHARE MODE'); }
			$this->profile_fields = $this->rows('SELECT * FROM ' . PROFILE_FIELDS_TABLE . ' ORDER BY field_id ASC LOCK IN SHARE MODE');
			$fields = array();
			foreach ($this->profile_fields as $field) { if ((string)$field['users_can_view'] === (string)ALLOW_VIEW) { $fields[] = $field; } }
			if ($fields != $profile_data) { phpbb_registration_error('Registration_changed'); }
			// These predicates use the actual stored identity/collation. This also
			// closes duplicate insert races with writers not using our mutex.
			if ($this->rows('SELECT user_id FROM ' . USERS_TABLE . " WHERE username='" . $this->sql_escape($username) . "' OR user_email='" . $this->sql_escape($email) . "' LOCK IN SHARE MODE")) { phpbb_registration_error('Username_taken'); }
			$this->locking_validation = true;
			foreach (array(validate_username($username, false), validate_email($email, false)) as $checked)
			{ if ($checked['error']) { throw new PhpbbRegistrationException($checked['error_msg']); } }
			$this->locking_validation = false;
			if (isset($user_ip) && is_string($user_ip) && preg_match('/^[a-f0-9]{8}$/iD', $user_ip))
			{
				$ip = strtolower($user_ip); $ips = array($ip, substr($ip,0,6).'ff', substr($ip,0,4).'ffff', substr($ip,0,2).'ffffff');
				if ($this->rows('SELECT ban_id FROM ' . BANLIST_TABLE . " WHERE ban_ip IN ('" . implode("','", $ips) . "') LOCK IN SHARE MODE")) { phpbb_registration_error('You_been_banned'); }
			}
			$this->consume_challenge($token);
			if ((int)$ctracker_config->settings['reg_protection'] === 1)
			{
				$identity = isset($ctracker_config->user_ip_value) ? $ctracker_config->user_ip_value : '';
				if (!is_string($identity) || filter_var($identity, FILTER_VALIDATE_IP) === false) { phpbb_registration_error('Registration_changed'); }
				$this->rate_identity = $identity;
				$hash = $this->sql_escape(hash('sha256', "registration-success\0" . $identity));
				$rate = $this->rows('SELECT updated_at FROM ' . CTRACKER_RATE_LIMITS . " WHERE bucket_hash='$hash' FOR UPDATE");
				$cooldown = max(1, min(200, (int)$ctracker_config->settings['reg_blocktime']));
				if ($rate && (int)$rate[0]['updated_at'] + $cooldown > time())
				{ throw new PhpbbRegistrationException(sprintf($lang['ctracker_info_regist_time'], $cooldown, (int)$rate[0]['updated_at'] + $cooldown - time())); }
			}
		}
		catch (Exception $e) { $this->release(); throw $e; }
		catch (Error $e) { $this->release(); throw $e; }
	}

	static function policy_keys()
	{
		return array('registration_status','require_activation','enable_confirm','sfs_enable','min_password_len','password_not_login',
			'force_complex_password','password_hashing','birthday_required','min_user_age','max_user_age','max_sig_chars',
			'allow_sig','allow_html','allow_html_tags','allow_bbcode','allow_smilies','allow_avatar_local','allow_avatar_remote',
			'allow_avatar_upload','avatar_filesize','avatar_max_height','avatar_max_width','avatar_path','avatar_gallery_path');
	}
	function profile_insert_parts($submitted)
	{
		if (!$this->transactional) { phpbb_registration_error('Registration_changed'); }
		try { return phpbb_profile_new_account_insert($this, $this->profile_fields, $submitted); }
		catch (UnexpectedValueException $e) { phpbb_registration_error('Registration_changed'); }
	}
	private function pin_settings($table, $keys, $expected, $name = 'config_name', $value = 'config_value')
	{
		$rows = $this->rows('SELECT ' . $name . ',' . $value . ' FROM ' . $table . ' WHERE ' . $name . " IN ('" . implode("','", $keys) . "') LOCK IN SHARE MODE");
		if (count($rows) !== count($keys)) { phpbb_registration_error('Registration_storage_upgrade'); }
		foreach ($rows as $row)
		{ if (!array_key_exists($row[$name], $expected) || (string)$row[$value] !== (string)$expected[$row[$name]]) { phpbb_registration_error('Registration_changed'); } }
	}
	private function consume_challenge($token)
	{
		global $plus_config, $board_config;
		if (!empty($plus_config['enable_antirobot']))
		{
			$rows = $this->rows('SELECT reg_key FROM ' . ANTI_ROBOT_TABLE . " WHERE session_id='$token' AND HEX(session_id)=HEX('$token') FOR UPDATE");
			$key = isset($_POST['reg_key']) && is_string($_POST['reg_key']) ? strtolower($_POST['reg_key']) : '';
			if (count($rows) !== 1 || $key === '' || !hash_equals((string)$rows[0]['reg_key'], $key)) { phpbb_registration_error('Wrong_reg_key'); }
			$this->sql_query('DELETE FROM ' . ANTI_ROBOT_TABLE . " WHERE session_id='$token' AND HEX(session_id)=HEX('$token')");
		}
		elseif (!empty($board_config['enable_confirm']))
		{
			$id = isset($_POST['confirm_id']) && is_string($_POST['confirm_id']) ? $_POST['confirm_id'] : '';
			$code = isset($_POST['confirm_code']) && is_string($_POST['confirm_code']) ? trim(htmlspecialchars($_POST['confirm_code'])) : '';
			if (!preg_match('/^[A-Za-z0-9]{1,32}$/D', $id) || $code === '') { phpbb_registration_error('Confirm_code_wrong'); }
			$id = $this->sql_escape($id);
			$predicate = " WHERE session_id='$token' AND HEX(session_id)=HEX('$token') AND confirm_id='$id' AND HEX(confirm_id)=HEX('$id')";
			$rows = $this->rows('SELECT code FROM ' . CONFIRM_TABLE . $predicate . ' FOR UPDATE');
			if (count($rows) !== 1 || !hash_equals((string)$rows[0]['code'], $code)) { phpbb_registration_error('Confirm_code_wrong'); }
			$this->sql_query('DELETE FROM ' . CONFIRM_TABLE . $predicate);
		}
	}
	function __call($method, $args)
	{
		if ($this->connection === null) { phpbb_registration_error(); }
		return call_user_func_array(array($this->connection, $method), $args);
	}
	private function control($sql)
	{
		if ($this->connection === null) { phpbb_registration_error(); }
		$r = $this->connection->sql_query($sql); if (!$r) { phpbb_registration_error(); } return $r;
	}
	function sql_query($sql, $transaction = false)
	{
		if (!$this->transactional || !is_string($sql) || !preg_match('/^\s*(SELECT|INSERT|UPDATE|DELETE)\b/i', $sql)) { phpbb_registration_error(); }
		if ($this->locking_validation && preg_match('/^\s*SELECT\b/i', $sql)) { $sql = rtrim($sql, "; \t\r\n") . ' LOCK IN SHARE MODE'; }
		return $this->control($sql);
	}
	function rows($sql)
	{
		$r = $this->sql_query($sql); $rows = $this->sql_fetchrowset($r); $this->sql_freeresult($r); return $rows;
	}
	function finish()
	{
		if (!$this->transactional || $this->confirmed) { phpbb_registration_error(); }
		if ($this->rate_identity !== null && !ctracker_rate_limit_mark_success('registration-success', $this->rate_identity)) { phpbb_registration_error(); }
		$this->commit_attempted = true; $this->control('COMMIT');
		$this->transactional = false; $this->confirmed = true; $this->release();
	}
	function release()
	{
		global $db;
		if ($this->connection === null) { return; }
		$rolled_back = false;
		if ($this->transactional) { try { $rolled_back = (bool)$this->connection->sql_query('ROLLBACK'); } catch (Exception $e) {} catch (Error $e) {} }
		$this->transactional = false; $this->connection = null; $this->lock->release();
		if ($db === $this) { $db = $this->original; }
		if ($this->confirmed) { $this->avatar->saved(); }
		elseif (!$this->commit_attempted && $rolled_back) { $this->avatar->rolled_back(); }
	}
}
