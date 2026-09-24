<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__DIR__) . '/attach_mod/includes/functions_quota_storage.php';

// Never route validation/gallery rendering through the legacy quota writer:
// that helper derives its own submit flag from the request.
function phpbb_admin_profile_quota_controls($id, $request)
{
	global $db, $template, $attach_config, $lang, $phpbb_root_path, $phpEx;
	$id = phpbb_acl_id($id);
	require_once $phpbb_root_path . 'attach_mod/includes/functions_selects.' . $phpEx;
	$values = array(QUOTA_UPLOAD_LIMIT=>(int)$attach_config['default_upload_quota'], QUOTA_PM_LIMIT=>(int)$attach_config['default_pm_quota']);
	$rows = phpbb_acl_rows($db, 'SELECT quota_type,quota_limit_id FROM ' . QUOTA_TABLE . ' WHERE user_id=' . $id);
	if (!is_array($rows)) { phpbb_acl_error('Admin_profile_save_failed'); }
	if ($rows) { $values = array(QUOTA_UPLOAD_LIMIT=>0, QUOTA_PM_LIMIT=>0); }
	foreach ($rows as $row) { if (isset($values[(int)$row['quota_type']])) { $values[(int)$row['quota_type']] = (int)$row['quota_limit_id']; } }
	foreach (phpbb_attach_quota_assignment_fields('user', $request) as $type=>$limit) { $values[$type] = (int)$limit; }
	$template->assign_vars(array('S_SELECT_UPLOAD_QUOTA'=>quota_limit_select('user_upload_quota', $values[QUOTA_UPLOAD_LIMIT]),
		'S_SELECT_PM_QUOTA'=>quota_limit_select('user_pm_quota', $values[QUOTA_PM_LIMIT]), 'L_UPLOAD_QUOTA'=>$lang['Upload_quota'], 'L_PM_QUOTA'=>$lang['Pm_quota']));
}

// Own the complete ACP profile request, including new-account placeholders.
// Rendering, cookies and removal of replaced avatar bytes follow commit.
class PhpbbAdminProfileScope extends PhpbbAttachQuotaWriter
{
	var $profile_fields = array();
	var $original;
	var $target_id;
	var $creating;
	var $ready = false;
	var $confirmed = false;
	var $commit_attempted = false;
	var $new_avatars = array();
	var $old_avatars = array();
	var $login_cookie = null;
	var $rename_cache_needed = false;
	var $original_identity = null;
	var $identity_validated = false;
	function __construct($database, $id, $creating, $request)
	{
		global $userdata, $table_prefix, $board_config;
		require_once dirname(__FILE__) . '/functions_profile_fields.php';
		phpbb_attach_quota_post($request); $this->target_id = phpbb_acl_id($id);
		$this->original = $database; $this->creating = $creating === true;
		parent::__construct($database, 'user'); $this->failure_key = 'Admin_profile_save_failed';
		try
		{
			$tables = array(USERS_TABLE, SESSIONS_TABLE, SESSIONS_KEYS_TABLE, BANLIST_TABLE, GROUPS_TABLE, USER_GROUP_TABLE,
				QUOTA_TABLE, QUOTA_LIMITS_TABLE, $table_prefix . 'album', $table_prefix . 'album_comment', iNA_GAMES_COMMENT,
				iNA_AT_SCORES, SHOUTBOX_TABLE, iNA_HIGHSCORES, CONFIG_TABLE, DISALLOW_TABLE, WORDS_TABLE, PROFILE_FIELDS_TABLE, THEMES_TABLE);
			$this->begin($tables, true); $actor = $this->actor(); $sid = $this->sql_escape($userdata['session_id']);
			// Authority is held throughout this request's filesystem preparation
			// and writes. Independent revocations happen before or after it.
			foreach (array('SELECT session_id FROM ' . SESSIONS_TABLE . " WHERE session_id='" . $sid . "' AND HEX(session_id)=HEX('" . $sid . "')",
				'SELECT user_id FROM ' . USERS_TABLE . ' WHERE user_id=' . (int)$actor['user_id'],
				'SELECT user_id FROM ' . JR_ADMIN_TABLE . ' WHERE user_id=' . (int)$actor['user_id']) as $sql)
			{ $r = $this->sql_query($sql . ' LOCK IN SHARE MODE'); $this->sql_freeresult($r); }
			$actor = $this->actor();
			$keys = self::policy_keys();
			$policy = phpbb_acl_rows($this, 'SELECT config_name,config_value FROM ' . CONFIG_TABLE . " WHERE config_name IN ('" . implode("','", $keys) . "')");
			if (count($policy) !== count($keys)) { phpbb_acl_error('Registration_storage_upgrade'); }
			foreach ($policy as $row)
			{ if (!array_key_exists($row['config_name'], $board_config) || (string)$board_config[$row['config_name']] !== (string)$row['config_value']) { phpbb_acl_error('Acl_selection_changed'); } }
			// Freeze custom-field definitions before the controller validates them,
			// including an empty range. Identity/rule gaps are locked when checked.
			$this->profile_fields = phpbb_acl_rows($this, 'SELECT * FROM ' . PROFILE_FIELDS_TABLE . ' ORDER BY field_id ASC');
			$rows = phpbb_acl_rows($this, 'SELECT user_id,user_level,username,user_email FROM ' . USERS_TABLE . ' WHERE user_id=' . $this->target_id . ' FOR UPDATE');
			if ($this->creating ? count($rows) !== 0 : count($rows) !== 1) { phpbb_acl_error('Acl_selection_changed'); }
			if (!$this->creating)
			{
				$this->original_identity = $rows[0];
				if ((int)$rows[0]['user_level'] === ADMIN && !$actor['root']) { phpbb_acl_error('Not_Authorised'); }
				$first = phpbb_acl_rows($this, 'SELECT user_id FROM ' . USERS_TABLE . ' WHERE user_level=' . ADMIN . ' AND user_id>0 ORDER BY user_id LIMIT 1');
				if ($first && (int)$first[0]['user_id'] === $this->target_id && (int)$actor['user_id'] !== $this->target_id) { phpbb_acl_error('ctracker_gmb_1stadmin'); }
				$self_ban = isset($request['user_ycard']) && is_scalar($request['user_ycard']) && (int)$request['user_ycard'] > (int)$board_config['max_user_bancard'];
				if ($this->target_id === (int)$actor['user_id'] && ($self_ban || !empty($request['block_account']) || !isset($request['user_status']) || !in_array($request['user_status'], array(1,'1'), true)))
				{ phpbb_acl_error('Admin_profile_self_disable'); }
			}
			$this->ready = true;
		}
		catch (Exception $e) { $this->release(); throw $e; }
		catch (Error $e) { $this->release(); throw $e; }
	}
	function sql_query($sql, $transaction = false)
	{
		// RR supplies next-key locks for unused names and rule ranges. Never
		// validate with an older snapshot after an independent committed change.
		if ($this->transactional && is_string($sql) && preg_match('/^\s*SELECT\b/i', $sql)
			&& stripos($sql, 'information_schema.') === false && !preg_match('/(?:FOR UPDATE|LOCK IN SHARE MODE)\s*$/i', $sql))
		{ $sql = rtrim($sql, "; \t\r\n") . ' LOCK IN SHARE MODE'; }
		if ($this->ready)
		{
			// Legacy helpers may read or write rows, never implicitly commit the
			// enclosing profile through DDL or transaction/session statements.
			if (!is_string($sql) || (!preg_match('/^\s*(?:SELECT|SHOW|DESCRIBE|INSERT|UPDATE|DELETE)\b/i', $sql)
				&& !($sql === 'COMMIT' && $this->commit_attempted))) { phpbb_acl_error('Admin_profile_save_failed'); }
			if (preg_match('/^\s*(?:INSERT|UPDATE|DELETE)\b/i', $sql)) { $this->actor(); }
		}
		return parent::sql_query($sql, $transaction);
	}
	static function policy_keys()
	{
		return array('min_password_len','password_not_login','force_complex_password','password_hashing',
			'block_time','max_user_bancard','allow_html','allow_html_tags','allow_bbcode','allow_smilies',
			'max_sig_chars','min_user_age','max_user_age','allow_avatar_local','allow_avatar_remote','allow_avatar_upload',
			'avatar_filesize','avatar_max_width','avatar_max_height','avatar_path','avatar_gallery_path',
			'default_style');
	}
	function profile_insert_parts()
	{
		if (!$this->creating || !$this->transactional) { phpbb_acl_error('Admin_profile_save_failed'); }
		try { return phpbb_profile_new_account_insert($this, $this->profile_fields); }
		catch (UnexpectedValueException $e) { phpbb_acl_error('Acl_selection_changed'); }
	}
	function actor()
	{
		global $userdata;
		if ($this->transactional)
		{
			// Explicit current session read: a derived EXISTS inside the shared
			// ACP guard must not be satisfied by an earlier RR snapshot.
			$id = phpbb_acl_id(isset($userdata['user_id']) ? $userdata['user_id'] : null);
			$sid = isset($userdata['session_id']) && is_string($userdata['session_id']) ? $this->sql_escape($userdata['session_id']) : '';
			if (!phpbb_acl_rows($this, 'SELECT session_id FROM ' . SESSIONS_TABLE . " WHERE session_id='$sid' AND HEX(session_id)=HEX('$sid') AND session_user_id=" . $id . ' AND session_logged_in=1 AND session_admin=1')) { phpbb_acl_error('Not_Authorised'); }
		}
		return parent::actor();
	}
	function validate_identity($username, $email, $style)
	{
		if (!$this->ready || !$this->transactional || !is_string($username) || !is_string($email) || $username === '') { phpbb_acl_error('Acl_selection_changed'); }
		// Preserve unchanged historical identities, including legacy shared email
		// addresses. Changed/new identities must satisfy current rules and be free.
		$changed_name = $this->creating || $username !== $this->original_identity['username'];
		$changed_email = $this->creating || $email !== $this->original_identity['user_email'];
		foreach (array('username'=>array($username,$changed_name,'Username_taken'), 'user_email'=>array($email,$changed_email,'Email_taken')) as $column=>$check)
		{
			if ($check[1] && phpbb_acl_rows($this, 'SELECT user_id FROM ' . USERS_TABLE . ' WHERE ' . $column . "='" . $this->sql_escape($check[0]) . "' AND user_id<>" . $this->target_id)) { phpbb_acl_error($check[2]); }
		}
		foreach (array('validate_username'=>array($username,$changed_name), 'validate_email'=>array($email,$changed_email)) as $validator=>$check)
		{
			if ($check[1]) { $result = $validator($check[0], false, $this->target_id); if ($result['error']) { throw new PhpbbAclException($result['error_msg']); } }
		}
		if (!phpbb_acl_rows($this, 'SELECT themes_id FROM ' . THEMES_TABLE . ' WHERE themes_id=' . (int)$style)) { phpbb_acl_error('Acl_selection_changed'); }
		$this->identity_validated = true;
	}
	function assign_quotas($request)
	{
		$values = phpbb_attach_quota_assignment_fields('user', $request);
		foreach ($values as $type=>$limit)
		{
			$limit = (int)$limit;
			if ($limit && !phpbb_acl_rows($this, 'SELECT quota_limit_id FROM ' . QUOTA_LIMITS_TABLE . ' WHERE quota_limit_id=' . $limit . ' LOCK IN SHARE MODE')) { phpbb_acl_error('Board_config_invalid'); }
			$this->sql_query('DELETE FROM ' . QUOTA_TABLE . ' WHERE user_id=' . $this->target_id . ' AND quota_type=' . (int)$type);
			if ($limit) { $this->sql_query('INSERT INTO ' . QUOTA_TABLE . ' (user_id,group_id,quota_type,quota_limit_id) VALUES (' . $this->target_id . ',0,' . (int)$type . ',' . $limit . ')'); }
			$stored = phpbb_acl_rows($this, 'SELECT group_id,quota_limit_id FROM ' . QUOTA_TABLE . ' WHERE user_id=' . $this->target_id . ' AND quota_type=' . (int)$type);
			if ($limit ? count($stored) !== 1 || (int)$stored[0]['group_id'] !== 0 || (int)$stored[0]['quota_limit_id'] !== $limit : count($stored) !== 0)
			{ phpbb_acl_error('Admin_profile_save_failed'); }
		}
	}
	function remember_avatar($file, $new)
	{
		if (!$this->ready || !is_string($file) || $file === '' || basename($file) !== $file) { phpbb_acl_error('Admin_profile_save_failed'); }
		if ($new) { $this->new_avatars[$file] = $file; } else { $this->old_avatars[$file] = $file; }
	}
	function clean_avatar($file)
	{
		// Keep shared or uncertain files; only remove a known unreferenced name.
		if (!function_exists('user_avatar_storage_directory')) { return; }
		$dir = user_avatar_storage_directory(); if ($dir === false) { return; }
		$r = $this->original->sql_query('SELECT user_id FROM ' . USERS_TABLE . " WHERE user_avatar_type=" . USER_AVATAR_UPLOAD . " AND user_avatar='" . $this->original->sql_escape($file) . "' LIMIT 1");
		if (!$r) { return; } $used = $this->original->sql_numrows($r); $this->original->sql_freeresult($r);
		$path = $dir . DIRECTORY_SEPARATOR . $file;
		if (!$used && !is_link($path) && is_file($path)) { @unlink($path); }
	}
	function finish()
	{
		if (!$this->identity_validated) { phpbb_acl_error('Admin_profile_save_failed'); }
		$this->actor(); $this->commit_attempted = true; parent::commit();
		$this->confirmed = true;
		$this->release();
		if ($this->login_cookie !== null) { phpbb_session_publish_reset_cookie($this->login_cookie); }
	}
	function release()
	{
		global $db, $phpbb_root_path;
		$had_connection = $this->connection !== null;
		if (!$had_connection) { return; }
		$rolled_back = false;
		if (!$this->confirmed)
		{ try { $rolled_back = (bool)$this->connection->sql_query('ROLLBACK'); } catch (Exception $e) {} catch (Error $e) {} }
		// A lost COMMIT reply can mean the complete profile already exists.
		// Retain both files in that case; never break a possibly saved avatar.
		$files = $this->confirmed ? $this->old_avatars : ($this->commit_attempted || !$rolled_back ? array() : $this->new_avatars);
		foreach ($files as $file)
		{
			try { $this->clean_avatar($file); } catch (Exception $e) {} catch (Error $e) {}
		}
		parent::release();
		// A reader may refill a pre-commit invalidated name cache. Invalidate
		// again after release, including an uncertain commit (safe on rollback).
		if ($this->rename_cache_needed)
		{
			foreach (array('cg_users.cache','arcade_best_player.cache','arcade_best_at_player.cache') as $file) { @unlink($phpbb_root_path . 'cache/' . $file); }
		}
		if ($db === $this) { $db = $this->original; }
		$this->ready = false;
	}
}
