<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_settings_storage.php';

function phpbb_attach_quota_id($value, $zero = false)
{
	if (!is_string($value) || !preg_match('/^[0-9]{1,8}$/D', $value) || (float)$value > 16777215
		|| (!$zero && (int)$value < 1)) { phpbb_acl_error('Board_config_invalid'); }
	return (int)$value;
}
function phpbb_attach_quota_description($value)
{
	if (!is_string($value)) { phpbb_acl_error('Board_config_invalid'); }
	$value = trim(stripslashes($value));
	if ($value === '' || strlen($value) > 100 || preg_match('//u', $value) !== 1
		|| preg_match('/[\x00-\x1f\x7f]/', $value) || preg_match_all('/./us', $value, $unused) > 25)
	{ phpbb_acl_error('Board_config_invalid'); }
	return $value;
}
function phpbb_attach_quota_bytes($value, $unit)
{
	$values = phpbb_attach_settings_values(array('attachment_quota'=>$value, 'quota_size'=>$unit), 'manage', array('attachment_quota'=>'0'));
	return $values['attachment_quota'];
}
function phpbb_attach_quota_form($request)
{
	$fields = array('sid','submit','mode','quota_change_list','quota_desc_list','max_filesize_list','size_select_list',
		'quota_id_list','quota_description','add_max_filesize','add_size_select','add_quota_check');
	foreach ($request as $key=>$value) { if (!in_array($key, $fields, true)) { phpbb_acl_error('Board_config_invalid'); } }
	if (isset($request['mode']) && $request['mode'] !== 'quota') { phpbb_acl_error('Board_config_invalid'); }
	$lists = array();
	foreach (array('quota_change_list','quota_desc_list','max_filesize_list','size_select_list','quota_id_list') as $key)
	{
		$list = isset($request[$key]) ? $request[$key] : array();
		if (!is_array($list) || count($list) > 10000 || ($list && array_keys($list) !== range(0, count($list)-1)))
		{ phpbb_acl_error('Board_config_invalid'); }
		$lists[$key] = $list;
	}
	$count = count($lists['quota_change_list']);
	foreach (array('quota_desc_list','max_filesize_list','size_select_list') as $key)
	{ if (count($lists[$key]) !== $count) { phpbb_acl_error('Board_config_invalid'); } }
	$changes = $deletes = array();
	foreach ($lists['quota_change_list'] as $i=>$value)
	{
		$id = phpbb_attach_quota_id($value);
		if (isset($changes[$id])) { phpbb_acl_error('Board_config_invalid'); }
		$changes[$id] = array('description'=>phpbb_attach_quota_description($lists['quota_desc_list'][$i]),
			'bytes'=>phpbb_attach_quota_bytes($lists['max_filesize_list'][$i], $lists['size_select_list'][$i]));
	}
	foreach ($lists['quota_id_list'] as $value)
	{
		$id = phpbb_attach_quota_id($value);
		if (!isset($changes[$id]) || isset($deletes[$id])) { phpbb_acl_error('Board_config_invalid'); }
		$deletes[$id] = $id;
	}
	$add = null;
	if (isset($request['add_quota_check']))
	{
		if ($request['add_quota_check'] !== 'on' && $request['add_quota_check'] !== '1') { phpbb_acl_error('Board_config_invalid'); }
		foreach (array('quota_description','add_max_filesize','add_size_select') as $key)
		{ if (!isset($request[$key])) { phpbb_acl_error('Board_config_invalid'); } }
		$add = array('description'=>phpbb_attach_quota_description($request['quota_description']),
			'bytes'=>phpbb_attach_quota_bytes($request['add_max_filesize'], $request['add_size_select']));
	}
	else
	{
		// Inactive add controls are still part of a complete form, not nested inputs.
		foreach (array('quota_description','add_max_filesize','add_size_select') as $key)
		{ if (isset($request[$key]) && !is_string($request[$key])) { phpbb_acl_error('Board_config_invalid'); } }
	}
	if (!$changes && $add === null) { phpbb_acl_error('Board_config_invalid'); }
	ksort($changes); ksort($deletes);
	return array('changes'=>$changes, 'deletes'=>$deletes, 'add'=>$add);
}

class PhpbbAttachQuotaWriter extends PhpbbAclDatabase
{
	var $lock;
	var $route;
	function __construct($database, $mode)
	{
		global $phpEx;
		if (!in_array($mode, array('quota','user','group'), true)) { phpbb_acl_error('Board_config_invalid'); }
		$this->route = $mode === 'quota' ? 'admin_attachments.' . $phpEx . '?mode=quota' : 'admin_' . ($mode === 'user' ? 'users' : 'groups') . '.' . $phpEx;
		// Cooperate with group/user deletion and group quota assignment writers.
		$this->lock = new attach_mutation_lock($database);
		if (!$this->lock->acquired) { phpbb_acl_error('Attachment_storage_busy'); }
		parent::__construct($this->lock->connection, 'Board_config_failed');
		register_shutdown_function(array($this, 'release'));
	}
	function actor() { return phpbb_acp_actor($this, $this->route); }
	function release()
	{
		if ($this->connection)
		{
			try { $this->connection->sql_query('ROLLBACK'); } catch (Exception $e) {} catch (Error $e) {}
			$this->connection = null;
		}
		if ($this->lock) { $this->lock->release(); $this->lock = null; }
	}
	function begin($tables)
	{
		$this->actor();
		$this->sql_query("SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_ALL_TABLES')");
		$this->sql_query('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED'); $this->sql_query('START TRANSACTION');
		foreach (array_unique(array_merge($tables, array(USERS_TABLE, SESSIONS_TABLE, JR_ADMIN_TABLE))) as $table)
		{
			$r = $this->sql_query('SELECT * FROM ' . $table . ' LIMIT 0'); $this->sql_freeresult($r);
			$rows = phpbb_acl_rows($this, "SELECT ENGINE, ROW_FORMAT, TABLE_COLLATION FROM information_schema.TABLES t WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . $this->sql_escape($table) . "'"
				. " AND NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS c WHERE c.TABLE_SCHEMA=t.TABLE_SCHEMA AND c.TABLE_NAME=t.TABLE_NAME AND c.CHARACTER_SET_NAME IS NOT NULL AND (c.CHARACTER_SET_NAME <> 'utf8mb4' OR c.COLLATION_NAME <> 'utf8mb4_unicode_ci'))");
			if (count($rows) !== 1 || $rows[0]['ENGINE'] !== 'InnoDB' || strtolower($rows[0]['ROW_FORMAT']) !== 'dynamic' || $rows[0]['TABLE_COLLATION'] !== 'utf8mb4_unicode_ci')
			{ phpbb_acl_error('Board_config_failed'); }
		}
	}
	function commit()
	{
		global $userdata;
		$actor = $this->actor(); $sid = $this->sql_escape($userdata['session_id']);
		foreach (array('SELECT session_id FROM ' . SESSIONS_TABLE . " WHERE session_id='" . $sid . "' AND HEX(session_id)=HEX('" . $sid . "')",
			'SELECT user_id FROM ' . USERS_TABLE . ' WHERE user_id=' . (int)$actor['user_id'],
			'SELECT user_id FROM ' . JR_ADMIN_TABLE . ' WHERE user_id=' . (int)$actor['user_id']) as $sql)
		{ $r = $this->sql_query($sql . ' LOCK IN SHARE MODE'); $this->sql_freeresult($r); }
		$this->actor(); $this->sql_query('COMMIT'); $this->actor();
	}
}
function phpbb_attach_quota_post($request)
{
	global $userdata;
	if (!is_array($request) || !isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST'
		|| empty($userdata['session_id']) || !is_string($userdata['session_id']) || !isset($request['sid'])
		|| !is_string($request['sid']) || !hash_equals($userdata['session_id'], $request['sid'])) { phpbb_acl_error('Session_invalid'); }
}
function phpbb_attach_quota_save($database, $request)
{
	global $attach_config;
	phpbb_attach_quota_post($request); $form = phpbb_attach_quota_form($request);
	$db = new PhpbbAttachQuotaWriter($database, 'quota');
	try
	{
		$db->begin(array(ATTACH_CONFIG_TABLE, QUOTA_LIMITS_TABLE, QUOTA_TABLE));
		$width = phpbb_acl_rows($db, "SELECT CHARACTER_MAXIMUM_LENGTH AS width FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . $db->sql_escape(QUOTA_LIMITS_TABLE) . "' AND COLUMN_NAME='quota_desc'");
		if (!$width || (int)$width[0]['width'] < 25) { phpbb_acl_error('Board_config_failed'); }
		// Same order as management settings: defaults first, definitions second.
		$defaults = phpbb_acl_rows($db, 'SELECT config_name, config_value FROM ' . ATTACH_CONFIG_TABLE . " WHERE config_name IN ('default_pm_quota','default_upload_quota') ORDER BY config_name FOR UPDATE");
		if (count($defaults) !== 2) { phpbb_acl_error('Board_config_failed'); }
		$ids = array_keys($form['changes']);
		if ($ids)
		{
			$rows = phpbb_acl_rows($db, 'SELECT quota_limit_id FROM ' . QUOTA_LIMITS_TABLE . ' WHERE quota_limit_id IN (' . implode(',', $ids) . ') ORDER BY quota_limit_id FOR UPDATE');
			if (count($rows) !== count($ids)) { phpbb_acl_error('Board_config_invalid'); }
		}
		$cleared = array();
		foreach ($defaults as $row)
		{
			if (isset($form['deletes'][(int)$row['config_value']]))
			{
				$actor = $db->actor();
				$db->sql_query('UPDATE ' . ATTACH_CONFIG_TABLE . " SET config_value='0' WHERE config_name='" . $db->sql_escape($row['config_name']) . "' AND " . $actor['guard']);
				$cleared[$row['config_name']] = '0';
			}
		}
		if ($form['deletes'])
		{
			$in = implode(',', $form['deletes']);
			foreach (array(QUOTA_TABLE, QUOTA_LIMITS_TABLE) as $table)
			{ $actor = $db->actor(); $db->sql_query('DELETE FROM ' . $table . ' WHERE quota_limit_id IN (' . $in . ') AND ' . $actor['guard']); }
		}
		foreach ($form['changes'] as $id=>$change)
		{
			if (isset($form['deletes'][$id])) { continue; }
			$actor = $db->actor();
			$db->sql_query('UPDATE ' . QUOTA_LIMITS_TABLE . " SET quota_desc='" . $db->sql_escape($change['description']) . "', quota_limit=" . $change['bytes'] . ' WHERE quota_limit_id=' . $id . ' AND ' . $actor['guard']);
		}
		if ($form['add'] !== null)
		{
			$add = $form['add'];
			if (phpbb_acl_rows($db, 'SELECT quota_limit_id FROM ' . QUOTA_LIMITS_TABLE . " WHERE quota_desc='" . $db->sql_escape($add['description']) . "'")) { phpbb_acl_error('Board_config_invalid'); }
			$actor = $db->actor();
			$db->sql_query('INSERT INTO ' . QUOTA_LIMITS_TABLE . " (quota_desc,quota_limit) SELECT '" . $db->sql_escape($add['description']) . "'," . $add['bytes'] . ' WHERE ' . $actor['guard']);
			if ((int)$db->sql_affectedrows() !== 1) { phpbb_acl_error('Board_config_failed'); }
			$form['changes'][phpbb_attach_quota_id((string)$db->sql_nextid())] = $add;
		}
		$db->actor();
		foreach ($form['changes'] as $id=>$change)
		{
			$rows = phpbb_acl_rows($db, 'SELECT quota_desc, quota_limit FROM ' . QUOTA_LIMITS_TABLE . ' WHERE quota_limit_id=' . $id);
			if (isset($form['deletes'][$id]))
			{ if ($rows || phpbb_acl_rows($db, 'SELECT quota_limit_id FROM ' . QUOTA_TABLE . ' WHERE quota_limit_id=' . $id)) { phpbb_acl_error('Board_config_failed'); } }
			else if (count($rows) !== 1 || $rows[0]['quota_desc'] !== $change['description'] || (string)$rows[0]['quota_limit'] !== $change['bytes']) { phpbb_acl_error('Board_config_failed'); }
		}
		$stored = phpbb_attach_settings_read($db);
		foreach ($cleared as $key=>$value) { if (!isset($stored[$key]) || $stored[$key] !== $value) { phpbb_acl_error('Board_config_failed'); } }
		$db->commit(); foreach ($cleared as $key=>$value) { $attach_config[$key] = $value; }
	}
	finally { $db->release(); }
}

// Both assignments of the user/group form are one unit. The group controller
// normally writes under its existing attachment mutex; legacy callers use this.
function phpbb_attach_quota_assignment_fields($mode, $request)
{
	if (!in_array($mode, array('user','group'), true) || !is_array($request)) { phpbb_acl_error('Board_config_invalid'); }
	$values = array();
	foreach (array($mode . '_upload_quota'=>QUOTA_UPLOAD_LIMIT, $mode . '_pm_quota'=>QUOTA_PM_LIMIT) as $key=>$type)
	{ if (array_key_exists($key, $request)) { phpbb_attach_quota_id($request[$key], true); $values[$type] = $request[$key]; } }
	return $values;
}
function phpbb_attach_quota_assign($database, $mode, $id, $assignments, $request)
{
	if (!in_array($mode, array('user','group'), true)) { phpbb_acl_error('Board_config_invalid'); }
	phpbb_attach_quota_post($request); $id = phpbb_attach_quota_id((string)$id);
	if (!is_array($assignments) || !$assignments) { return; }
	foreach ($assignments as $type=>$value)
	{
		if (!in_array($type, array(QUOTA_UPLOAD_LIMIT, QUOTA_PM_LIMIT), true)) { phpbb_acl_error('Board_config_invalid'); }
		$assignments[$type] = phpbb_attach_quota_id($value, true);
	}
	$db = new PhpbbAttachQuotaWriter($database, $mode);
	try
	{
		$table = $mode === 'user' ? USERS_TABLE : GROUPS_TABLE; $key = $mode === 'user' ? 'user_id' : 'group_id';
		$db->begin(array(QUOTA_LIMITS_TABLE, QUOTA_TABLE, $table)); $actor = $db->actor();
		$targets = phpbb_acl_rows($db, 'SELECT * FROM ' . $table . ' WHERE ' . $key . '=' . $id . ' FOR UPDATE');
		if (count($targets) !== 1 || ($mode === 'group' && !empty($targets[0]['group_single_user']))) { phpbb_acl_error('Board_config_invalid'); }
		if ($mode === 'user')
		{
			if ((int)$targets[0]['user_level'] === ADMIN && !$actor['root']) { phpbb_acl_error('Not_Authorised'); }
			$first = phpbb_acl_rows($db, 'SELECT user_id FROM ' . USERS_TABLE . ' WHERE user_level=' . ADMIN . ' AND user_id>0 ORDER BY user_id LIMIT 1');
			if ($first && (int)$first[0]['user_id'] === $id && (int)$actor['user_id'] !== $id) { phpbb_acl_error('Not_Authorised'); }
		}
		$limits = array_unique(array_filter($assignments)); sort($limits);
		foreach ($limits as $limit)
		{ if (!phpbb_acl_rows($db, 'SELECT quota_limit_id FROM ' . QUOTA_LIMITS_TABLE . ' WHERE quota_limit_id=' . $limit . ' LOCK IN SHARE MODE')) { phpbb_acl_error('Board_config_invalid'); } }
		foreach ($assignments as $type=>$limit)
		{
			$where = $key . '=' . $id . ' AND quota_type=' . (int)$type; $actor = $db->actor();
			$db->sql_query('DELETE FROM ' . QUOTA_TABLE . ' WHERE ' . $where . ' AND ' . $actor['guard']);
			if ($limit)
			{
				$actor = $db->actor();
				$db->sql_query('INSERT INTO ' . QUOTA_TABLE . ' (user_id,group_id,quota_type,quota_limit_id) SELECT ' . ($mode === 'user' ? $id . ',0' : '0,' . $id) . ',' . (int)$type . ',' . $limit . ' WHERE ' . $actor['guard']);
			}
			$db->actor();
			$rows = phpbb_acl_rows($db, 'SELECT quota_limit_id FROM ' . QUOTA_TABLE . ' WHERE ' . $where);
			if ($limit ? count($rows) !== 1 || (int)$rows[0]['quota_limit_id'] !== $limit : count($rows) !== 0) { phpbb_acl_error('Board_config_failed'); }
		}
		$db->commit();
	}
	finally { $db->release(); }
}
