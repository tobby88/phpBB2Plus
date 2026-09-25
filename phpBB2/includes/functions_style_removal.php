<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_style_actions.php';

class PhpbbStyleRemovalWriter extends PhpbbStyleDataWriter
{
	var $locking_actor = false;
	function update_tables() { return array(USERS_TABLE, CONFIG_TABLE); }
	function actor()
	{
		global $userdata;
		if (!$this->transactional) { return parent::actor(); }
		$this->locking_actor = true;
		try
		{
			// RR range locks protect templates, but snapshot reads must NEVER
			// authorize a stale account, delegated grant or logged-out session.
			$sid = $this->sql_escape($userdata['session_id']);
			$rows = phpbb_acl_rows($this, 'SELECT session_user_id,session_logged_in,session_admin FROM ' . SESSIONS_TABLE . " WHERE session_id='" . $sid . "' AND HEX(session_id)=HEX('" . $sid . "')");
			if (count($rows) !== 1 || (int)$rows[0]['session_user_id'] !== (int)$userdata['user_id'] || (int)$rows[0]['session_logged_in'] !== 1 || (int)$rows[0]['session_admin'] !== 1) { phpbb_acl_error('Not_Authorised'); }
			return parent::actor();
		}
		finally { $this->locking_actor = false; }
	}
	function sql_query($sql, $transaction = false)
	{
		if ($this->locking_actor && $this->transactional && is_string($sql) && strpos($sql, 'SELECT ') === 0 && strpos($sql, ' LOCK IN SHARE MODE') === false) { $sql .= ' LOCK IN SHARE MODE'; }
		if ($sql === 'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED')
		{
			if (!$this->connection || $this->transactional) { phpbb_acl_error('xs_remove_failed'); }
			// Full primary-index locking reads below must also exclude new styles
			// from legacy installers which do not acquire our named owner lock.
			if (!$this->connection->sql_query('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ')) { phpbb_acl_error('xs_remove_failed'); }
			return true;
		}
		if (is_string($sql) && preg_match('/^\s*INSERT\b/i', $sql))
		{
			if (!$this->connection || !$this->transactional || strpos($sql, 'INSERT INTO ' . CONFIG_TABLE . ' (config_name,config_value) SELECT ') !== 0) { phpbb_acl_error('xs_remove_failed'); }
			$this->actor(); $result = $this->connection->sql_query($sql, $transaction);
			if (!$result) { phpbb_acl_error('xs_remove_failed'); } return $result;
		}
		if (is_string($sql) && preg_match('/^\s*DELETE\b/i', $sql))
		{
			if (!$this->connection || !$this->transactional) { phpbb_acl_error('xs_remove_failed'); }
			$allowed = false;
			foreach (array(THEMES_TABLE, THEMES_NAME_TABLE, CONFIG_TABLE) as $table) { if (strpos($sql, 'DELETE FROM ' . $table . ' WHERE ') === 0) { $allowed = true; } }
			if (!$allowed) { phpbb_acl_error('xs_remove_failed'); }
			$this->actor(); $result = $this->connection->sql_query($sql, $transaction);
			if (!$result) { phpbb_acl_error('xs_remove_failed'); } return $result;
		}
		return parent::sql_query($sql, $transaction);
	}
}

function phpbb_style_removal_request($request)
{
	global $userdata;
	if (!is_array($request) || !isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST'
		|| empty($userdata['session_id']) || !is_string($userdata['session_id']) || !isset($request['sid'])
		|| !is_string($request['sid']) || !hash_equals($userdata['session_id'], $request['sid'])) { phpbb_acl_error('Session_invalid'); }
}

function phpbb_style_removal_name($name)
{
	return is_string($name) && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,29}$/D', $name)
		&& strpos($name, '..') === false && substr($name, -1) !== '.';
}

function phpbb_style_removal_file_target($name, $themes)
{
	if (!phpbb_style_removal_name($name) || strcasecmp($name, 'fisubsilversh') === 0) { phpbb_acl_error('xs_remove_protected'); }
	foreach ($themes as $theme) { if (strcasecmp($theme['template_name'], $name) === 0) { phpbb_acl_error('xs_remove_protected'); } }
}

function phpbb_style_removal_receipt_key($name) { return 'xs_removed_' . hash('sha256', strtolower($name)); }

function phpbb_style_removal_receipt($value)
{
	$data = is_string($value) ? json_decode($value, true) : null;
	if (!is_array($data) || !isset($data['template'], $data['id'], $data['token'], $data['state']) ||
		!phpbb_style_removal_name($data['template']) || !is_int($data['id']) || $data['id'] < 1 || $data['id'] > 16777215 ||
		!is_string($data['token']) || !preg_match('/^[a-f0-9]{32}$/D', $data['token']) || !in_array($data['state'], array('pending','done'), true)) { return false; }
	return $data;
}

function phpbb_style_removal_store_receipt($db, $name, $receipt)
{
	$key = phpbb_style_removal_receipt_key($name); $json = json_encode($receipt);
	if (!is_string($json) || strlen($json) > 255 || !phpbb_style_removal_receipt($json)) { phpbb_acl_error('xs_remove_failed'); }
	$rows = phpbb_acl_rows($db, "SELECT config_name FROM " . CONFIG_TABLE . " WHERE config_name='" . $key . "' FOR UPDATE");
	if (count($rows) > 1 || ($rows && $rows[0]['config_name'] !== $key)) { phpbb_acl_error('xs_remove_failed'); }
	$actor = $db->actor(); $value = $db->sql_escape($json);
	$sql = $rows ? 'UPDATE ' . CONFIG_TABLE . " SET config_value='" . $value . "' WHERE config_name='" . $key . "' AND " :
		'INSERT INTO ' . CONFIG_TABLE . " (config_name,config_value) SELECT '" . $key . "','" . $value . "' WHERE ";
	$db->sql_query($sql . $actor['guard']);
	$stored = phpbb_acl_rows($db, "SELECT config_value FROM " . CONFIG_TABLE . " WHERE config_name='" . $key . "'");
	if (count($stored) !== 1 || $stored[0]['config_value'] !== $json) { phpbb_acl_error('xs_remove_failed'); }
}

function phpbb_style_removal_start($db, $labels = true)
{
	$db->actor(); $tables = array(CONFIG_TABLE, THEMES_TABLE, USERS_TABLE, SESSIONS_TABLE, JR_ADMIN_TABLE);
	if ($labels) { $tables[] = THEMES_NAME_TABLE; }
	phpbb_style_storage_start($db, $tables);
	$default = phpbb_style_policy_default($db, 'xs_remove_failed');
	$themes = phpbb_acl_rows($db, 'SELECT themes_id,template_name,style_name,theme_public FROM ' . THEMES_TABLE . ' FORCE INDEX (PRIMARY) ORDER BY themes_id FOR UPDATE');
	$valid = false;
	foreach ($themes as $theme) { if ((int)$theme['themes_id'] === $default && phpbb_style_policy_valid($theme)) { $valid = true; } }
	if (!$valid) { phpbb_acl_error('xs_remove_failed'); }
	return array($default, $themes);
}

function phpbb_style_unregister($database, $request)
{
	global $phpbb_root_path;
	phpbb_style_removal_request($request);
	$id = phpbb_style_policy_id(isset($request['remove_id']) ? $request['remove_id'] : null, 'xs_remove_failed');
	foreach (array('remove_files','keep_config') as $key)
	{ if (isset($request[$key]) && (!is_string($request[$key]) || !in_array($request[$key], array('0','1'), true))) { phpbb_acl_error('xs_remove_failed'); } }
	$files = isset($request['remove_files']) && $request['remove_files'] === '1';
	$keep = isset($request['keep_config']) && $request['keep_config'] === '1';
	$db = new PhpbbStyleRemovalWriter($database); $attempted = false; $result = null;
	try
	{
		list($default, $themes) = phpbb_style_removal_start($db);
		if ($id === $default) { phpbb_acl_error('xs_remove_protected'); }
		$target = null; $remaining = array();
		foreach ($themes as $theme) { if ((int)$theme['themes_id'] === $id) { $target = $theme; } else { $remaining[] = $theme; } }
		if (!$target || !phpbb_style_removal_name($target['template_name'])) { phpbb_acl_error('xs_remove_failed'); }
		$name = $target['template_name']; $shared = false;
		phpbb_style_import_require_available($db, array($name));
		foreach ($remaining as $theme) { if (strcasecmp($theme['template_name'], $name) === 0) { $shared = true; } }
		if ($files) { phpbb_style_removal_file_target($name, $remaining); }
		$config_name = 'xs_style_' . $name;
		$remove_config = !$keep && !$shared && strcasecmp($name, 'fisubsilversh') !== 0;
		if ($remove_config) { phpbb_acl_rows($db, "SELECT config_name FROM " . CONFIG_TABLE . " WHERE config_name='" . $db->sql_escape($config_name) . "' FOR UPDATE"); }
		phpbb_acl_rows($db, 'SELECT themes_id FROM ' . THEMES_NAME_TABLE . ' WHERE themes_id=' . $id . ' FOR UPDATE');
		$users = phpbb_acl_rows($db, 'SELECT user_id FROM ' . USERS_TABLE . ' WHERE user_style=' . $id . ' ORDER BY user_id FOR UPDATE');
		$ids = array(); foreach ($users as $user) { $ids[] = (int)$user['user_id']; }
		foreach (array_chunk($ids, 200) as $chunk)
		{
			$actor = $db->actor(); $attempted = true;
			$db->sql_query('UPDATE ' . USERS_TABLE . ' SET user_style=NULL WHERE user_id IN (' . implode(',', $chunk) . ') AND user_style=' . $id . ' AND ' . $actor['guard']);
		}
		foreach (array(THEMES_NAME_TABLE, THEMES_TABLE) as $table)
		{
			$actor = $db->actor(); $attempted = true;
			$db->sql_query('DELETE FROM ' . $table . ' WHERE themes_id=' . $id . ' AND ' . $actor['guard']);
		}
		if ($remove_config)
		{
			$actor = $db->actor();
			$db->sql_query('DELETE FROM ' . CONFIG_TABLE . " WHERE config_name='" . $db->sql_escape($config_name) . "' AND " . $actor['guard']);
		}
		$receipt = null;
		if (!$shared && strcasecmp($name, 'fisubsilversh') !== 0)
		{
			$receipt = array('template'=>$name, 'id'=>$id, 'token'=>bin2hex(phpbb_random_bytes(16)), 'state'=>'pending');
			phpbb_style_removal_store_receipt($db, $name, $receipt);
		}
		phpbb_style_storage_lock_authority($db);
		foreach (array(THEMES_TABLE, THEMES_NAME_TABLE) as $table)
		{ if (phpbb_acl_rows($db, 'SELECT themes_id FROM ' . $table . ' WHERE themes_id=' . $id)) { phpbb_acl_error('xs_remove_failed'); } }
		if (phpbb_acl_rows($db, 'SELECT user_id FROM ' . USERS_TABLE . ' WHERE user_style=' . $id)) { phpbb_acl_error('xs_remove_failed'); }
		if ($remove_config && phpbb_acl_rows($db, "SELECT config_name FROM " . CONFIG_TABLE . " WHERE config_name='" . $db->sql_escape($config_name) . "'")) { phpbb_acl_error('xs_remove_failed'); }
		$db->sql_query('COMMIT');
		$result = array('template'=>$name, 'files'=>$files, 'config_removed'=>$remove_config, 'receipt'=>$receipt);
	}
	finally { phpbb_style_actions_finish($db, $attempted, $phpbb_root_path); }
	return $result;
}

function phpbb_style_check_unused_template($database, $request)
{
	// Read-only preflight before the FTP form can save settings or connect.
	phpbb_style_remove_files($database, $request, false);
}

function phpbb_style_remove_files($database, $request, $files)
{
	global $userdata, $phpbb_root_path;
	phpbb_style_removal_request($request);
	$name = isset($request['remove']) ? $request['remove'] : null;
	$token = isset($request['remove_token']) ? $request['remove_token'] : null;
	if (!phpbb_style_removal_name($name) || !is_string($token) || !preg_match('/^[a-f0-9]{32}$/D', $token)) { phpbb_acl_error('xs_remove_failed'); }
	$db = new PhpbbStyleRemovalWriter($database); $attempted = false;
	try
	{
		list($default, $themes) = phpbb_style_removal_start($db, false);
		phpbb_style_import_require_available($db, array($name));
		phpbb_style_removal_file_target($name, $themes);
		$key = phpbb_style_removal_receipt_key($name);
		$rows = phpbb_acl_rows($db, "SELECT config_name,config_value FROM " . CONFIG_TABLE . " WHERE config_name='" . $key . "' FOR UPDATE");
		$receipt = count($rows) === 1 ? phpbb_style_removal_receipt($rows[0]['config_value']) : false;
		if (!$receipt || $rows[0]['config_name'] !== $key || $receipt['template'] !== $name || !hash_equals($receipt['token'], $token)) { phpbb_acl_error('xs_remove_failed'); }
		// Keep current authority and the complete theme key range pinned while
		// removing unused assets. Filesystem effects cannot be rolled back.
		phpbb_style_storage_lock_authority($db);
		$guard = function () use ($db) { $db->actor(); };
		if ($files !== false)
		{
			$guard();
			if ($receipt['state'] === 'done')
			{
				if ($files->kind($name) !== null) { phpbb_acl_error('xs_remove_protected'); }
			}
			else { $files->remove($name, $guard); }
			$receipt['state'] = 'done'; $attempted = true;
			phpbb_style_removal_store_receipt($db, $name, $receipt);
		}
		$db->sql_query('COMMIT');
	}
	finally { phpbb_style_actions_finish($db, $attempted, $phpbb_root_path); }
}
