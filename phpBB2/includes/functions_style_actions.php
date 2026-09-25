<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_style_data.php';

class PhpbbStyleActionWriter extends PhpbbStyleDataWriter
{
	function update_tables() { return array(CONFIG_TABLE, USERS_TABLE, THEMES_TABLE); }
	function sql_query($sql, $transaction = false)
	{
		if (is_string($sql) && preg_match('/^\s*INSERT\b/i', $sql)) { phpbb_acl_error('xs_actions_save_failed'); }
		return parent::sql_query($sql, $transaction);
	}
}

function phpbb_style_action_request($request)
{
	global $userdata;
	if (!is_array($request) || !isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST'
		|| empty($userdata['session_id']) || !is_string($userdata['session_id']) || !isset($request['sid'])
		|| !is_string($request['sid']) || !hash_equals($userdata['session_id'], $request['sid'])) { phpbb_acl_error('Session_invalid'); }
	if (!isset($request['style_action']) || !is_string($request['style_action'])
		|| !preg_match('/^(default|override|moveusers|moveaway|admin):([0-9]+)(?::([01]))?$/D', $request['style_action'], $m)) { phpbb_acl_error('xs_actions_save_failed'); }
	$action = $m[1];
	if (($action === 'admin') !== isset($m[3])) { phpbb_acl_error('xs_actions_save_failed'); }
	if ($action === 'override')
	{
		if (!in_array($m[2], array('0','1'), true)) { phpbb_acl_error('xs_actions_save_failed'); }
		return array($action, (int)$m[2], null);
	}
	$id = phpbb_style_policy_id($m[2], 'xs_invalid_style_id'); $destination = $action === 'admin' ? (int)$m[3] : null;
	if ($action === 'moveaway')
	{
		if (!isset($request['movestyle']) || !is_string($request['movestyle'])) { phpbb_acl_error('xs_actions_save_failed'); }
		$destination = $request['movestyle'] === '0' ? 0 : phpbb_style_policy_id($request['movestyle'], 'xs_invalid_style_id');
	}
	return array($action, $id, $destination);
}

function phpbb_style_action_save($database, $request)
{
	global $phpbb_root_path, $board_config;
	list($action, $id, $destination) = phpbb_style_action_request($request);
	$db = new PhpbbStyleActionWriter($database); $attempted = false; $config = array();
	try
	{
		$db->actor();
		phpbb_style_storage_start($db, array(THEMES_TABLE, CONFIG_TABLE, USERS_TABLE, SESSIONS_TABLE, JR_ADMIN_TABLE));
		$rows = phpbb_acl_rows($db, "SELECT config_name,config_value FROM " . CONFIG_TABLE . " WHERE config_name IN ('default_style','override_user_style') ORDER BY config_name FOR UPDATE");
		foreach ($rows as $row)
		{
			if (!in_array($row['config_name'], array('default_style','override_user_style'), true) || array_key_exists($row['config_name'], $config)) { phpbb_acl_error('xs_actions_save_failed'); }
			$config[$row['config_name']] = $row['config_value'];
		}
		if (count($config) !== 2 || !in_array($config['override_user_style'], array('0','1'), true)) { phpbb_acl_error('xs_actions_save_failed'); }
		$default_id = phpbb_style_policy_id($config['default_style'], 'xs_invalid_style_id');
		$ids = array($default_id); if ($action !== 'override') { $ids[] = $id; } if ($action === 'moveaway' && $destination) { $ids[] = $destination; }
		$ids = array_values(array_unique($ids)); sort($ids);
		$rows = phpbb_acl_rows($db, 'SELECT themes_id,template_name,theme_public FROM ' . THEMES_TABLE . ' WHERE themes_id IN (' . implode(',', $ids) . ') ORDER BY themes_id FOR UPDATE');
		$themes = array(); foreach ($rows as $row) { $themes[(int)$row['themes_id']] = $row; }
		if (count($themes) !== count($ids)) { phpbb_acl_error('xs_invalid_style_id'); }
		// A new default may repair an inaccessible old default; other actions
		// must not build further state on a missing/non-public board default.
		$new_default = $action === 'default' ? $id : $default_id;
		if (!phpbb_style_policy_valid($themes[$new_default], $action !== 'default')) { phpbb_acl_error('xs_invalid_style_id'); }
		$target = $action === 'moveusers' ? $id : ($action === 'moveaway' ? $destination : 0);
		if ($target && !phpbb_style_policy_valid($themes[$target])) { phpbb_acl_error('xs_invalid_style_id'); }
		if ($action === 'admin' && (($id === $default_id && $destination === 0) || ($destination === 1 && $themes[$id]['template_name'] !== 'fisubsilversh'))) { phpbb_acl_error('xs_invalid_style_id'); }
		$sqls = array(); $user_ids = array(); $user_value = null; $public = null;
		if ($action === 'default')
		{
			$config['default_style'] = (string)$id; $public = 1;
			$sqls[] = 'UPDATE ' . CONFIG_TABLE . " SET config_value='" . $id . "' WHERE config_name='default_style'";
			$sqls[] = 'UPDATE ' . THEMES_TABLE . ' SET theme_public=1 WHERE themes_id=' . $id;
		}
		elseif ($action === 'override')
		{
			$config['override_user_style'] = (string)$id;
			$sqls[] = 'UPDATE ' . CONFIG_TABLE . " SET config_value='" . $id . "' WHERE config_name='override_user_style'";
		}
		elseif ($action === 'admin')
		{
			$public = $destination;
			$sqls[] = 'UPDATE ' . THEMES_TABLE . ' SET theme_public=' . $destination . ' WHERE themes_id=' . $id;
		}
		else
		{
			$where = 'user_id > 0' . ($action === 'moveaway' ? ' AND user_style=' . $id : '');
			$rows = phpbb_acl_rows($db, 'SELECT user_id FROM ' . USERS_TABLE . ' WHERE ' . $where . ' ORDER BY user_id FOR UPDATE');
			foreach ($rows as $row) { $user_ids[] = (int)$row['user_id']; }
			$user_value = $action === 'moveusers' ? (string)$id : ($destination ? (string)$destination : null);
			// Freeze precisely the selected accounts; a new registration after
			// this selection is not silently pulled into a partially checked batch.
			foreach (array_chunk($user_ids, 200) as $chunk)
			{ $sqls[] = 'UPDATE ' . USERS_TABLE . ' SET user_style=' . phpbb_style_data_literal($db, $user_value) . ' WHERE user_id IN (' . implode(',', $chunk) . ')'; }
		}
		foreach ($sqls as $sql)
		{
			$actor = $db->actor(); $attempted = true;
			$db->sql_query($sql . ' AND ' . $actor['guard']);
		}
		phpbb_style_storage_lock_authority($db);
		$rows = phpbb_acl_rows($db, "SELECT config_name,config_value FROM " . CONFIG_TABLE . " WHERE config_name IN ('default_style','override_user_style')");
		$stored = array(); foreach ($rows as $row) { $stored[$row['config_name']] = $row['config_value']; }
		if (count($rows) !== 2 || count($stored) !== 2) { phpbb_acl_error('xs_actions_save_failed'); }
		foreach ($config as $key => $value) { if (!array_key_exists($key, $stored) || $stored[$key] !== $value) { phpbb_acl_error('xs_actions_save_failed'); } }
		if ($public !== null)
		{
			$rows = phpbb_acl_rows($db, 'SELECT theme_public FROM ' . THEMES_TABLE . ' WHERE themes_id=' . $id);
			if (count($rows) !== 1 || (int)$rows[0]['theme_public'] !== $public) { phpbb_acl_error('xs_actions_save_failed'); }
		}
		foreach (array_chunk($user_ids, 200) as $chunk)
		{
			$rows = phpbb_acl_rows($db, 'SELECT user_id,user_style FROM ' . USERS_TABLE . ' WHERE user_id IN (' . implode(',', $chunk) . ')');
			if (count($rows) !== count($chunk)) { phpbb_acl_error('xs_actions_save_failed'); }
			foreach ($rows as $row) { if ($user_value === null ? $row['user_style'] !== null : (string)$row['user_style'] !== $user_value) { phpbb_acl_error('xs_actions_save_failed'); } }
		}
		// A successful no-op retry must also remove cache files left by a prior
		// committed action whose filesystem cleanup failed.
		$attempted = true;
		$db->sql_query('COMMIT');
	}
	finally { phpbb_style_actions_finish($db, $attempted, $phpbb_root_path); }
	// Do not publish speculative request-local defaults before confirmed commit.
	foreach ($config as $key => $value) { $board_config[$key] = $value; }
}

function phpbb_style_actions_finish($db, $attempted, $root)
{
	try
	{
		$db->rollback(); $clean = true;
		if ($attempted)
		{
			foreach (array('config_data.cache','themes.cache') as $file)
			{
				$cache = $root . 'cache/' . $file; clearstatcache(true, $cache);
				if ((file_exists($cache) || is_link($cache)) && !@unlink($cache)) { $clean = false; }
			}
		}
		if (!$clean) { phpbb_acl_error('xs_actions_save_failed'); }
	}
	finally { $db->release(); }
}
