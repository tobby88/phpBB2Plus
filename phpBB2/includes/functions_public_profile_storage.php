<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__DIR__) . '/attach_mod/includes/functions_mutation.php';

class PhpbbPublicProfileException extends RuntimeException {}
function phpbb_public_profile_error($key = 'Public_profile_save_failed')
{
	global $lang;
	throw new PhpbbPublicProfileException(isset($lang[$key]) ? $lang[$key] : $key);
}

// Self-service edits have no ACP privilege requirement. Own one dedicated
// transaction, pin the exact login and account, then publish cookies/files.
class PhpbbPublicProfileScope
{
	var $original;
	var $lock;
	var $connection;
	var $transactional = false;
	var $confirmed = false;
	var $commit_attempted = false;
	var $id;
	var $account = array();
	var $avatar;
	var $login_cookie = null;
	var $guest = null;
	var $rename_cache_needed = false;
	var $identity_validated = false;
	function __construct($database, $id, $sid, $avatar)
	{
		global $userdata, $table_prefix, $board_config, $ctracker_config;
		$this->original = $database; $this->avatar = $avatar;
		if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST' || empty($userdata['session_logged_in'])
			|| !isset($userdata['user_id'], $userdata['session_id']) || !is_string($sid) || $sid === '' || !is_string($userdata['session_id'])
			|| !hash_equals($userdata['session_id'], $sid) || !(is_int($id) || is_string($id)) || !preg_match('/^[1-9][0-9]{0,7}$/D', (string)$id)
			|| (int)$id > 8388607 || (string)$id !== (string)$userdata['user_id']) { phpbb_public_profile_error('Public_profile_changed'); }
		$this->id = (int)$id;
		$this->lock = new attach_mutation_lock($database);
		if (!$this->lock->acquired) { phpbb_public_profile_error('Attachment_storage_busy'); }
		$this->connection = $this->lock->connection;
		register_shutdown_function(array($this, 'release'));
		try
		{
			$this->control("SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_ALL_TABLES')");
			$this->control('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
			$this->control('START TRANSACTION'); $this->transactional = true;
			foreach (array(USERS_TABLE, SESSIONS_TABLE, SESSIONS_KEYS_TABLE, GROUPS_TABLE, USER_GROUP_TABLE, BANLIST_TABLE,
				PROFILE_FIELDS_TABLE, DISALLOW_TABLE, WORDS_TABLE, CONFIG_TABLE, CTRACKER_CONFIG, THEMES_TABLE, $table_prefix . 'album', $table_prefix . 'album_comment',
				iNA_GAMES_COMMENT, iNA_AT_SCORES, SHOUTBOX_TABLE, iNA_HIGHSCORES) as $table)
			{
				$r = $this->sql_query('SELECT * FROM ' . $table . ' LIMIT 0'); $this->sql_freeresult($r);
				$name = $this->sql_escape($table);
				$rows = $this->rows("SELECT ENGINE,ROW_FORMAT,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$name'"
					. " AND NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$name' AND CHARACTER_SET_NAME IS NOT NULL AND (CHARACTER_SET_NAME<>'utf8mb4' OR COLLATION_NAME<>'utf8mb4_unicode_ci'))");
				if (count($rows) !== 1 || $rows[0]['ENGINE'] !== 'InnoDB' || strtolower($rows[0]['ROW_FORMAT']) !== 'dynamic' || $rows[0]['TABLE_COLLATION'] !== 'utf8mb4_unicode_ci') { phpbb_public_profile_error('Public_profile_storage_upgrade'); }
			}
			$token = $this->sql_escape($sid);
			$session = $this->rows('SELECT session_id FROM ' . SESSIONS_TABLE . " WHERE session_id='$token' AND HEX(session_id)=HEX('$token') AND session_user_id=" . $this->id . ' AND session_logged_in=1 LOCK IN SHARE MODE');
			$user = $this->rows('SELECT * FROM ' . USERS_TABLE . ' WHERE user_id=' . $this->id . ' FOR UPDATE');
			if (count($session) !== 1 || count($user) !== 1 || (int)$user[0]['user_active'] !== 1) { phpbb_public_profile_error('Public_profile_changed'); }
			$this->account = $user[0];
			// Password validation and avatar preparation used the request snapshot.
			// A reset, rename, role change or avatar replacement invalidates it.
			foreach (array('user_password','user_email','username','user_level','user_avatar','user_avatar_type','user_allowavatar') as $key)
			{
				if (!isset($userdata[$key], $user[0][$key]) || (string)$userdata[$key] !== (string)$user[0][$key]) { phpbb_public_profile_error('Public_profile_changed'); }
			}
			if ($this->rows('SELECT ban_id FROM ' . BANLIST_TABLE . ' WHERE ban_userid=' . $this->id . ' LOCK IN SHARE MODE')) { phpbb_public_profile_error('Public_profile_changed'); }
			// Validation and avatar preparation used the request's policy snapshot.
			// Reject a changed policy; keep current settings locked until COMMIT.
			$this->pin_settings(CONFIG_TABLE, self::policy_keys(), $board_config);
			$this->pin_settings(CTRACKER_CONFIG, array('pw_complex','pw_complex_min','pw_complex_mode'), $ctracker_config->settings, 'ct_config_name', 'ct_config_value');
			if (!in_array((string)$board_config['require_activation'], array('0','1','2'), true)) { phpbb_public_profile_error('Public_profile_changed'); }
		}
		catch (Exception $e) { $this->release(); throw $e; }
		catch (Error $e) { $this->release(); throw $e; }
	}
	static function policy_keys()
	{
		// Language, timezone and date format are personalized by init_userprefs,
		// not validation rules; comparing them with board defaults rejects users.
		return array('allow_namechange','require_activation','min_password_len','password_not_login','force_complex_password','password_hashing',
			'birthday_required','min_user_age','max_user_age','max_sig_chars','allow_sig','allow_html','allow_html_tags','allow_bbcode','allow_smilies',
			'allow_avatar_local','allow_avatar_remote','allow_avatar_upload','avatar_filesize','avatar_max_height','avatar_max_width',
			'avatar_path','avatar_gallery_path','default_style');
	}
	private function pin_settings($table, $keys, $expected, $name = 'config_name', $value = 'config_value')
	{
		$rows = $this->rows('SELECT ' . $name . ',' . $value . ' FROM ' . $table . ' WHERE ' . $name . " IN ('" . implode("','", $keys) . "')");
		if (count($rows) !== count($keys)) { phpbb_public_profile_error('Public_profile_storage_upgrade'); }
		foreach ($rows as $row)
		{ if (!array_key_exists($row[$name], $expected) || (string)$row[$value] !== (string)$expected[$row[$name]]) { phpbb_public_profile_error('Public_profile_changed'); } }
	}
	function validate_identity($username, $email, $style, $profile_data)
	{
		global $board_config;
		if (!$this->transactional || !is_string($username) || $username === '' || !is_string($email) || !is_array($profile_data)) { phpbb_public_profile_error('Public_profile_changed'); }
		$changed_name = $username !== $this->account['username'];
		$changed_email = $email !== $this->account['user_email'];
		if ($changed_name && empty($board_config['allow_namechange'])) { phpbb_public_profile_error('Public_profile_changed'); }
		// Preserve untouched legacy duplicates. For changed identities, pin the
		// real database-collation predicate, including its currently empty range.
		foreach (array('username'=>array($username,$changed_name,'Username_taken'), 'user_email'=>array($email,$changed_email,'Email_taken')) as $column=>$check)
		{
			if ($check[1] && $this->rows('SELECT user_id FROM ' . USERS_TABLE . ' WHERE ' . $column . "='" . $this->sql_escape($check[0]) . "' AND user_id<>" . $this->id)) { phpbb_public_profile_error($check[2]); }
		}
		foreach (array('validate_username'=>array($username,$changed_name), 'validate_email'=>array($email,$changed_email)) as $validator=>$check)
		{
			if ($check[1]) { $result = $validator($check[0], false, $this->id); if ($result['error']) { throw new PhpbbPublicProfileException($result['error_msg']); } }
		}
		if (!$this->rows('SELECT themes_id FROM ' . THEMES_TABLE . ' WHERE themes_id=' . (int)$style)) { phpbb_public_profile_error('Public_profile_changed'); }
		$fields = $this->rows('SELECT * FROM ' . PROFILE_FIELDS_TABLE . ' WHERE users_can_view=' . ALLOW_VIEW . ' ORDER BY field_id ASC');
		if ($fields != $profile_data) { phpbb_public_profile_error('Public_profile_changed'); }
		$this->identity_validated = true;
	}
	function __call($method, $args)
	{
		if ($this->connection === null) { phpbb_public_profile_error(); }
		return call_user_func_array(array($this->connection, $method), $args);
	}
	private function control($sql)
	{
		if ($this->connection === null) { phpbb_public_profile_error(); }
		$result = $this->connection->sql_query($sql);
		if (!$result) { phpbb_public_profile_error(); } return $result;
	}
	function sql_query($sql, $transaction = false)
	{
		if (!$this->transactional || !is_string($sql) || !preg_match('/^\s*(?:SELECT|INSERT|UPDATE|DELETE)\b/i', $sql)) { phpbb_public_profile_error(); }
		// Current locking reads avoid stale RR snapshots, while next-key locks
		// prevent an independent writer from changing validated identities/rules.
		if (preg_match('/^\s*SELECT\b/i', $sql) && stripos($sql, 'information_schema.') === false
			&& !preg_match('/(?:FOR UPDATE|LOCK IN SHARE MODE)\s*$/i', $sql))
		{ $sql = rtrim($sql, "; \t\r\n") . ' LOCK IN SHARE MODE'; }
		return $this->control($sql);
	}
	function rows($sql)
	{
		$r = $this->sql_query($sql); $rows = $this->sql_fetchrowset($r); $this->sql_freeresult($r);
		if (!is_array($rows)) { phpbb_public_profile_error(); } return $rows;
	}
	function end_sessions()
	{
		// Reactivation invalidates every login/key, not just the current cookie.
		$this->sql_query('DELETE FROM ' . SESSIONS_TABLE . ' WHERE session_user_id=' . $this->id);
		$this->sql_query('DELETE FROM ' . SESSIONS_KEYS_TABLE . ' WHERE user_id=' . $this->id);
		$rows = $this->rows('SELECT * FROM ' . USERS_TABLE . ' WHERE user_id=' . ANONYMOUS);
		if (count($rows) !== 1) { phpbb_public_profile_error(); }
		$this->guest = $rows[0]; $this->login_cookie = null;
	}
	function finish()
	{
		global $userdata, $board_config, $SID;
		if (!$this->transactional || $this->confirmed || !$this->identity_validated) { phpbb_public_profile_error(); }
		$this->commit_attempted = true; $this->control('COMMIT');
		$this->transactional = false; $this->confirmed = true;
		$this->release();
		if ($this->guest !== null)
		{
			foreach (array('_data','_sid') as $suffix) { phpbb_setcookie($board_config['cookie_name'] . $suffix, '', time()-31536000, $board_config['cookie_path'], $board_config['cookie_domain'], $board_config['cookie_secure']); }
			$userdata = $this->guest; $userdata['session_logged_in'] = false; $userdata['session_id'] = ''; $userdata['session_key'] = ''; $SID = '';
		}
		elseif ($this->login_cookie !== null) { phpbb_session_publish_reset_cookie($this->login_cookie); }
	}
	function release()
	{
		global $db, $phpbb_root_path;
		if ($this->connection === null) { return; }
		$rolled_back = false;
		if ($this->transactional) { try { $rolled_back = (bool)$this->connection->sql_query('ROLLBACK'); } catch (Exception $e) {} catch (Error $e) {} }
		$this->transactional = false; $this->connection = null; $this->lock->release();
		if ($db === $this) { $db = $this->original; }
		if ($this->confirmed) { $this->avatar->saved(); }
		elseif (!$this->commit_attempted && $rolled_back) { $this->avatar->rolled_back(); }
		if ($this->rename_cache_needed)
		{
			foreach (array('cg_users.cache','arcade_best_player.cache','arcade_best_at_player.cache') as $file) { @unlink($phpbb_root_path . 'cache/' . $file); }
		}
	}
}
