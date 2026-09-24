<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_board_config.php';

// Share the existing recovery/attachment owner lock, but keep all editor writes
// in one explicit transaction. Closing this dedicated connection also releases
// the named lock; it cannot reconnect and continue without ownership.
class PhpbbStyleDataWriter extends PhpbbBoardConfigWriter
{
	function __construct($database)
	{
		parent::__construct($database);
		try
		{
			$name = 'attachment:' . md5($database->dbname . "\0" . ATTACHMENTS_TABLE);
			$rows = phpbb_acl_rows($this, "SELECT GET_LOCK('" . $name . "', 10) AS acquired");
			if (count($rows) !== 1 || (int)$rows[0]['acquired'] !== 1) { phpbb_acl_error('xs_data_save_failed'); }
		}
		catch (Exception $error) { $this->release(); throw $error; }
		catch (Error $error) { $this->release(); throw $error; }
	}
	function actor() { global $phpEx; return phpbb_acp_actor($this, 'xs_frameset.' . $phpEx); }
	function update_tables() { return array(THEMES_TABLE, THEMES_NAME_TABLE); }
	function sql_query($sql, $transaction = false)
	{
		if (is_string($sql) && preg_match('/^\s*(INSERT|UPDATE)\b/i', $sql, $match))
		{
			if (!$this->connection || !$this->transactional) { phpbb_acl_error('xs_data_save_failed'); }
			if (strtoupper($match[1]) === 'INSERT')
			{
				if (strpos($sql, 'INSERT INTO ' . THEMES_NAME_TABLE . ' (') !== 0) { phpbb_acl_error('xs_data_save_failed'); }
				$this->actor();
				$result = $this->connection->sql_query($sql, $transaction);
				if (!$result) { phpbb_acl_error('xs_data_save_failed'); }
				return $result;
			}
			$allowed = false;
			foreach ($this->update_tables() as $table) { if (strpos($sql, 'UPDATE ' . $table . ' SET ') === 0) { $allowed = true; break; } }
			if (!$allowed) { phpbb_acl_error('xs_data_save_failed'); }
		}
		return parent::sql_query($sql, $transaction);
	}
}

function phpbb_style_data_values($request, $theme, $names)
{
	$values = array(); $labels = array();
	$ranges = array('fontsize1' => array(-128,127), 'fontsize2' => array(-128,127), 'fontsize3' => array(-128,127),
		'img_size_poll' => array(0,65535), 'img_size_privmsg' => array(0,65535), 'theme_public' => array(0,1));
	foreach (xs_get_vars($theme) as $field => $definition)
	{
		if (!array_key_exists($field, $theme) || empty($definition['len']) || !preg_match('/^[a-zA-Z0-9_]+$/D', $field)) { continue; }
		foreach (array('edit_' => $definition['len'], 'name_' => 50) as $prefix => $length)
		{
			$key = $prefix . $field;
			if (!array_key_exists($key, $request) || ($prefix === 'name_' && !array_key_exists($field . '_name', $names))) { continue; }
			if (!is_string($request[$key])) { phpbb_acl_error('xs_data_save_failed'); }
			$value = stripslashes($request[$key]);
			if (strpos($value, "\0") !== false || preg_match_all('/./us', $value, $chars) === false) { phpbb_acl_error('xs_data_save_failed'); }
			$value = implode('', array_slice($chars[0], 0, $length));
			if ($prefix === 'name_') { $labels[$field . '_name'] = rtrim($value, ' '); continue; }
			if (isset($ranges[$field]))
			{
				if ($value === '' && $field !== 'theme_public') { $value = null; }
				elseif (!preg_match('/^-?[0-9]+$/D', $value) || (int)$value < $ranges[$field][0] || (int)$value > $ranges[$field][1])
				{ phpbb_acl_error('xs_data_save_failed'); }
				else { $value = (string)(int)$value; }
			}
			$values[$field] = $value;
		}
	}
	if (!$values) { phpbb_acl_error('xs_data_save_failed'); }
	return array($values, $labels);
}

function phpbb_style_data_literal($db, $value) { return $value === null ? 'NULL' : "'" . $db->sql_escape($value) . "'"; }

function phpbb_style_data_save($database, $request)
{
	global $userdata, $phpbb_root_path;
	if (!is_array($request) || !isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST'
		|| empty($userdata['session_id']) || !is_string($userdata['session_id']) || !isset($request['sid'])
		|| !is_string($request['sid']) || !hash_equals($userdata['session_id'], $request['sid'])) { phpbb_acl_error('Session_invalid'); }
	$id = phpbb_acl_id(isset($request['edit']) ? $request['edit'] : null);
	$db = new PhpbbStyleDataWriter($database); $attempted = false;
	try
	{
		$db->actor();
		phpbb_style_storage_start($db, array(THEMES_TABLE, THEMES_NAME_TABLE, USERS_TABLE, SESSIONS_TABLE, JR_ADMIN_TABLE));
		$rows = phpbb_acl_rows($db, 'SELECT * FROM ' . THEMES_TABLE . ' WHERE themes_id=' . $id . ' FOR UPDATE');
		if (count($rows) !== 1) { phpbb_acl_error('xs_invalid_style_id'); }
		$names = xs_empty_name($db);
		if (!$names) { phpbb_acl_error('xs_data_save_failed'); }
		list($values, $labels) = phpbb_style_data_values($request, $rows[0], $names);
		$stored_names = phpbb_acl_rows($db, 'SELECT * FROM ' . THEMES_NAME_TABLE . ' WHERE themes_id=' . $id . ' FOR UPDATE');
		if (count($stored_names) > 1) { phpbb_acl_error('xs_data_save_failed'); }
		$updates = array(); foreach ($values as $key => $value) { $updates[] = $key . '=' . phpbb_style_data_literal($db, $value); }
		$actor = $db->actor(); $attempted = true;
		$db->sql_query('UPDATE ' . THEMES_TABLE . ' SET ' . implode(',', $updates) . ' WHERE themes_id=' . $id . ' AND ' . $actor['guard']);
		if ($labels)
		{
			$actor = $db->actor(); $updates = array(); $literals = array();
			foreach ($labels as $key => $value) { $literal = phpbb_style_data_literal($db, $value); $updates[] = $key . '=' . $literal; $literals[] = $literal; }
			if ($stored_names) { $sql = 'UPDATE ' . THEMES_NAME_TABLE . ' SET ' . implode(',', $updates) . ' WHERE themes_id=' . $id; }
			else { $sql = 'INSERT INTO ' . THEMES_NAME_TABLE . ' (themes_id,' . implode(',', array_keys($labels)) . ') SELECT ' . $id . ',' . implode(',', $literals) . ' WHERE 1=1'; }
			$db->sql_query($sql . ' AND ' . $actor['guard']);
		}
		phpbb_style_storage_lock_authority($db);
		foreach (array(THEMES_TABLE => $values, THEMES_NAME_TABLE => $labels) as $table => $expected)
		{
			if (!$expected) { continue; }
			$rows = phpbb_acl_rows($db, 'SELECT * FROM ' . $table . ' WHERE themes_id=' . $id);
			if (count($rows) !== 1) { phpbb_acl_error('xs_data_save_failed'); }
			foreach ($expected as $field => $value)
			{
				if (!array_key_exists($field, $rows[0]) || ($value === null ? $rows[0][$field] !== null : (string)$rows[0][$field] !== $value)) { phpbb_acl_error('xs_data_save_failed'); }
			}
		}
		$db->sql_query('COMMIT');
	}
	finally { phpbb_style_data_finish($db, $attempted, $phpbb_root_path . 'cache/themes.cache'); }
}

function phpbb_style_storage_start($db, $tables)
{
	$db->sql_query("SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_ALL_TABLES')");
	$db->sql_query('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
	$db->sql_query('START TRANSACTION');
	foreach ($tables as $table)
	{
		$result = $db->sql_query('SELECT * FROM ' . $table . ' LIMIT 0'); $db->sql_freeresult($result);
		$escaped = $db->sql_escape($table);
		$rows = phpbb_acl_rows($db, "SELECT ENGINE, ROW_FORMAT, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . $escaped . "'"
			. " AND NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . $escaped . "' AND CHARACTER_SET_NAME IS NOT NULL"
			. " AND (CHARACTER_SET_NAME <> 'utf8mb4' OR COLLATION_NAME <> 'utf8mb4_unicode_ci'))");
		if (count($rows) !== 1 || $rows[0]['ENGINE'] !== 'InnoDB' || strtolower($rows[0]['ROW_FORMAT']) !== 'dynamic'
			|| $rows[0]['TABLE_COLLATION'] !== 'utf8mb4_unicode_ci') { phpbb_acl_error('xs_data_save_failed'); }
	}
}

function phpbb_style_storage_lock_authority($db)
{
	global $userdata;
	// Short current-read authority locks serialize COMMIT with revocation.
	$actor = $db->actor(); $sid = $db->sql_escape($userdata['session_id']);
	foreach (array('SELECT session_id FROM ' . SESSIONS_TABLE . " WHERE session_id='" . $sid . "' AND HEX(session_id)=HEX('" . $sid . "')",
		'SELECT user_id FROM ' . USERS_TABLE . ' WHERE user_id=' . (int)$actor['user_id'],
		'SELECT user_id FROM ' . JR_ADMIN_TABLE . ' WHERE user_id=' . (int)$actor['user_id']) as $sql)
	{ $result = $db->sql_query($sql . ' LOCK IN SHARE MODE'); $db->sql_freeresult($result); }
	$db->actor();
}

function phpbb_style_data_finish($db, $attempted, $cache)
{
	try
	{
		$db->rollback();
		if ($attempted)
		{
			clearstatcache(true, $cache);
			if ((file_exists($cache) || is_link($cache)) && !@unlink($cache)) { phpbb_acl_error('xs_data_save_failed'); }
		}
	}
	finally { $db->release(); }
}

// Discover actual label columns even when the optional label table is empty.
function xs_empty_name($database = null)
{
	global $db;
	if ($database === null) { $database = $db; }
	$result = $database->sql_query('SELECT * FROM ' . THEMES_NAME_TABLE . ' LIMIT 0');
	if (!$result) { return array(); }
	$names = array();
	try
	{
		for ($i = 0; $i < $database->sql_numfields($result); $i++)
		{
			$name = $database->sql_fieldname($i, $result);
			if ($name !== 'themes_id' && preg_match('/^[a-zA-Z0-9_]+$/D', $name)) { $names[$name] = ''; }
		}
	}
	finally { $database->sql_freeresult($result); }
	return $names;
}

function xs_get_vars($theme)
{
	$arr1 = array();
	$arr2 = array();
	$vars_100 = array('head_stylesheet', 'body_background');
	$vars_50 = array('fontface');
	$vars_30 = array('style_name');
	$vars_25 = array('tr_class', 'th_class', 'td_class', 'span_class');
	$vars_6 = array('body_bgcolor', 'body_text', 'body_link', 'body_vlink', 'body_alink', 'body_hlink', 'tr_color', 'th_color', 'td_color', 'fontcolor');
	$vars_5 = array('img_size_poll', 'img_size_privmsg');
	$vars_4 = array('fontsize', 'theme_public');
	foreach($theme as $var => $value)
	{
		if(!is_integer($var) && $var !== 'themes_id' && $var !== 'template_name')
		{
			// editable variable
			$len = 0;
			$sub = substr($var, 0, strlen($var) - 1);
			if(in_array($var, $vars_100) || in_array($sub, $vars_100))
			{
				$len = 100;
			}
			elseif(in_array($var, $vars_50) || in_array($sub, $vars_50))
			{
				$len = 50;
			}
			elseif(in_array($var, $vars_30) || in_array($sub, $vars_30))
			{
				$len = 30;
			}
			elseif(in_array($var, $vars_25) || in_array($sub, $vars_25))
			{
				$len = 25;
			}
			elseif(in_array($var, $vars_6) || in_array($sub, $vars_6))
			{
				$len = 6;
			}
			elseif(in_array($var, $vars_5) || in_array($sub, $vars_5))
			{
				$len = 5;
			}
			elseif(in_array($var, $vars_4) || in_array($sub, $vars_4))
			{
				$len = 4;
			}
			elseif(strpos($var, 'class') !== false)
			{
				$len = 25;
			}
			elseif(strpos($var, 'color') !== false)
			{
				$len = 6;
			}
			if($len)
			{
				$item = array(
					'var'		=> $var,
					'len'		=> $len,
					'color'		=> $len == 6 ? true : false,
					'font'		=> $len == 25 ? true : false,
					);
				if($var === 'style_name' || $var === 'head_stylesheet' || $var === 'body_background')
				{
					$arr1[$var] = $item;
				}
				else
				{
					$arr2[$var] = $item;
				}
			}
		}
	}
	krsort($arr1);
	ksort($arr2);
	if(defined('XS_MODS_CATEGORY_HIERARCHY210'))
	{
		// force sort for the added fields
		$added = array(
			'style_name' => array(),
			'images_pack' => array('var' => 'images_pack', 'len' => 100, 'color' => false, 'font' => false),
			'custom_tpls' => array('var' => 'custom_tpls', 'len' => 100, 'color' => false, 'font' => false),
			'head_stylesheet' => array(),
		);
		$arr1 = array_merge($added, $arr1);
		// we need to add lang entries
		global $lang;
		$lang['xs_data_images_pack'] = $lang['Images_pack'];
		$lang['xs_data_images_pack_explain'] = $lang['Images_pack_explain'];
		$lang['xs_data_custom_tpls'] = $lang['Custom_tpls'];
		$lang['xs_data_custom_tpls_explain'] = $lang['Custom_tpls_explain'];
	}
	return array_merge($arr1, $arr2);
}
