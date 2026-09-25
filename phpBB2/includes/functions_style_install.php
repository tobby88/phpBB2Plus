<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_style_removal.php';

class PhpbbStyleInstallWriter extends PhpbbStyleRemovalWriter
{
	function update_tables() { return array(); }
	function sql_query($sql, $transaction = false)
	{
		if (is_string($sql) && preg_match('/^\s*(INSERT|DELETE)\b/i', $sql, $m))
		{
			if (strtoupper($m[1]) !== 'INSERT' || !$this->connection || !$this->transactional
				|| strpos($sql, 'INSERT INTO ' . THEMES_TABLE . ' (') !== 0 || strpos($sql, ') SELECT ') === false)
			{ phpbb_acl_error('xs_install_error'); }
			$this->actor(); $result = $this->connection->sql_query($sql, $transaction);
			if (!$result) { phpbb_acl_error('xs_install_error'); } return $result;
		}
		return parent::sql_query($sql, $transaction);
	}
}

function phpbb_style_install_selection($request)
{
	phpbb_style_removal_request($request); $selection = array();
	if (isset($request['install_one']))
	{
		if (!is_string($request['install_one']) || !preg_match('/^([^:]+):([0-9]{1,2})$/D', $request['install_one'], $m)) { phpbb_acl_error('xs_install_error'); }
		$selection[] = array($m[1], (int)$m[2]);
	}
	else
	{
		if (!isset($request['total']) || !is_string($request['total']) || !preg_match('/^[0-9]{1,4}$/D', $request['total']) || (int)$request['total'] > 1000) { phpbb_acl_error('xs_install_error'); }
		for ($i = 0; $i < (int)$request['total']; $i++)
		{
			$key = 'install_' . $i;
			if (!isset($request[$key])) { continue; }
			if (!is_string($request[$key]) || !in_array($request[$key], array('on','1'), true)
				|| !isset($request[$key . '_style'], $request[$key . '_num']) || !is_string($request[$key . '_style'])
				|| !is_string($request[$key . '_num']) || !preg_match('/^[0-9]{1,2}$/D', $request[$key . '_num'])) { phpbb_acl_error('xs_install_error'); }
			$selection[] = array($request[$key . '_style'], (int)$request[$key . '_num']);
		}
	}
	$seen = array();
	foreach ($selection as $item)
	{
		$key = strtolower($item[0]) . ':' . $item[1];
		if (!phpbb_style_removal_name($item[0]) || $item[1] < 0 || $item[1] >= XS_MAX_ITEMS_PER_STYLE || isset($seen[$key])) { phpbb_acl_error('xs_install_error'); }
		$seen[$key] = true;
	}
	return $selection;
}

function phpbb_style_install_values($data, $tpl)
{
	if (!is_array($data) || !isset($data['template_name'], $data['style_name']) || $data['template_name'] !== $tpl) { phpbb_acl_error('xs_install_error'); }
	$values = array();
	foreach ($data as $field => $value)
	{
		if (!is_string($value) || preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $value)) { phpbb_acl_error('xs_install_error'); }
		if (preg_match('/^fontsize[123]$/D', $field) || in_array($field, array('img_size_poll','img_size_privmsg'), true))
		{
			$font = substr($field, 0, 8) === 'fontsize';
			if ($value === '') { $values[$field] = null; continue; }
			if (!preg_match('/^-?[0-9]{1,5}$/D', $value) || (int)$value < ($font ? -128 : 0) || (int)$value > ($font ? 127 : 65535)) { phpbb_acl_error('xs_install_error'); }
			$values[$field] = (string)(int)$value; continue;
		}
		$length = 0;
		if (in_array($field, array('template_name','style_name'), true)) { $length = 30; }
		elseif (in_array($field, array('head_stylesheet','body_background'), true)) { $length = 100; }
		elseif (preg_match('/^fontface[123]$/D', $field)) { $length = 50; }
		elseif (preg_match('/^(?:tr|th|td|span|div|row|col)_class[123]$/D', $field)) { $length = 25; }
		elseif (preg_match('/^(?:(?:tr|th|td)_color[123]|fontcolor[123]|body_(?:bgcolor|text|link|vlink|alink|hlink))$/D', $field)) { $length = 6; }
		if (!$length || preg_match_all('/./us', $value, $chars) > $length) { phpbb_acl_error('xs_install_error'); }
		$values[$field] = $value;
	}
	$values['style_name'] = rtrim($values['style_name'], ' ');
	if ($values['style_name'] === '') { phpbb_acl_error('xs_install_error'); }
	// Retired templates may be preserved in ACP, but never become user styles.
	$values['theme_public'] = $tpl === 'fisubsilversh' ? '1' : '0';
	return $values;
}

function phpbb_style_install($database, $request)
{
	global $phpbb_root_path;
	$selection = phpbb_style_install_selection($request);
	if (!$selection) { return array(); }
	$db = new PhpbbStyleInstallWriter($database); $attempted = false; $ids = array();
	try
	{
		// Shares cleanup ownership and native range locks. Read configuration
		// files only after cleanup has finished, never while it removes them.
		phpbb_style_removal_start($db, false); $batch = array();
		foreach ($selection as $item)
		{
			phpbb_style_import_require_available($db, array($item[0]));
			$directory = $phpbb_root_path . 'templates/' . $item[0];
			if (is_link($directory) || is_link($directory . '/theme_info.cfg')) { phpbb_acl_error('xs_install_error'); }
			$data = xs_get_themeinfo($item[0]);
			if (!isset($data[$item[1]])) { phpbb_acl_error('xs_install_error'); }
			$batch[] = phpbb_style_install_values($data[$item[1]], $item[0]);
		}
		foreach ($batch as $values)
		{
			// Match the database collation, including case/accent equivalents.
			$name = $db->sql_escape($values['style_name']);
			$rows = phpbb_acl_rows($db, 'SELECT * FROM ' . THEMES_TABLE . " WHERE style_name='" . $name . "' FOR UPDATE");
			if (!$rows)
			{
				$literals = array(); foreach ($values as $value) { $literals[] = phpbb_style_data_literal($db, $value); }
				$actor = $db->actor(); $attempted = true;
				$db->sql_query('INSERT INTO ' . THEMES_TABLE . ' (' . implode(',', array_keys($values)) . ') SELECT ' . implode(',', $literals) . ' WHERE ' . $actor['guard']);
				$rows = phpbb_acl_rows($db, 'SELECT * FROM ' . THEMES_TABLE . " WHERE style_name='" . $name . "'");
			}
			if (count($rows) !== 1) { phpbb_acl_error('xs_install_error'); }
			$id = phpbb_style_policy_id($rows[0]['themes_id'], 'xs_install_error');
			if (in_array($id, $ids, true)) { phpbb_acl_error('xs_install_error'); }
			foreach ($values as $field => $value)
			{ if ($value === null ? $rows[0][$field] !== null : (string)$rows[0][$field] !== $value) { phpbb_acl_error('xs_install_error'); } }
			$ids[] = $id;
		}
		// A matching existing batch is a no-op retry after an uncertain commit
		// or failed cache invalidation. Never overwrite differing definitions.
		$attempted = true;
		phpbb_style_storage_lock_authority($db); $db->sql_query('COMMIT');
	}
	finally { phpbb_style_actions_finish($db, $attempted, $phpbb_root_path); }
	return $ids;
}
