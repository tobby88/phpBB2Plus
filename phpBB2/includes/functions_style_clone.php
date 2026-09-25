<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_style_install.php';

class PhpbbStyleCloneWriter extends PhpbbStyleInstallWriter
{
	function sql_query($sql, $transaction = false)
	{
		if (is_string($sql) && strpos($sql, 'INSERT INTO ' . THEMES_NAME_TABLE . ' (') === 0)
		{
			if (!$this->connection || !$this->transactional || strpos($sql, ') SELECT ') === false) { phpbb_acl_error('xs_clone_failed'); }
			$this->actor(); $result = $this->connection->sql_query($sql, $transaction);
			if (!$result) { phpbb_acl_error('xs_clone_failed'); } return $result;
		}
		return parent::sql_query($sql, $transaction);
	}
}

function phpbb_style_clone_columns($row)
{
	$values = array();
	foreach ($row as $field => $value)
	{
		// The legacy DB adapter returns both numeric and named field keys.
		if (is_int($field) || $field === 'themes_id') { continue; }
		if (!is_string($field) || !preg_match('/^[a-zA-Z0-9_]+$/D', $field) || (!is_string($value) && $value !== null)) { phpbb_acl_error('xs_clone_failed'); }
		$values[$field] = $value;
	}
	return $values;
}

function phpbb_style_clone_verify($row, $values)
{
	foreach ($values as $field => $value)
	{
		if (!array_key_exists($field, $row) || ($value === null ? $row[$field] !== null : (string)$row[$field] !== $value)) { phpbb_acl_error('xs_clone_failed'); }
	}
}

function phpbb_style_clone_insert($db, $table, $values)
{
	$fields = array(); $literals = array();
	foreach ($values as $field => $value) { $fields[] = '`' . $field . '`'; $literals[] = phpbb_style_data_literal($db, $value); }
	$actor = $db->actor();
	$db->sql_query('INSERT INTO ' . $table . ' (' . implode(',', $fields) . ') SELECT ' . implode(',', $literals) . ' WHERE ' . $actor['guard']);
}

function phpbb_style_clone($database, $request)
{
	global $phpbb_root_path;
	phpbb_style_removal_request($request);
	$id = phpbb_style_policy_id(isset($request['clone_style']) ? $request['clone_style'] : null, 'xs_invalid_style_id');
	if (isset($request['clone_tpl']) || !isset($request['clone_name']) || !is_string($request['clone_name'])) { phpbb_acl_error('xs_invalid_style_name'); }
	$name = trim(stripslashes($request['clone_name']));
	$count = preg_match_all('/./us', $name, $characters);
	if ($name === '' || $count === false || $count > 30 || preg_match('/[\x00-\x1f\x7f]/', $name)) { phpbb_acl_error('xs_invalid_style_name'); }
	$db = new PhpbbStyleCloneWriter($database); $attempted = false; $clone_id = null;
	try
	{
		phpbb_style_removal_start($db);
		$source = phpbb_acl_rows($db, 'SELECT * FROM ' . THEMES_TABLE . ' WHERE themes_id=' . $id . ' FOR UPDATE');
		if (count($source) !== 1 || !phpbb_style_removal_name($source[0]['template_name'])) { phpbb_acl_error('xs_invalid_style_id'); }
		phpbb_style_import_require_available($db, array($source[0]['template_name']));
		$values = phpbb_style_clone_columns($source[0]); $values['style_name'] = $name;
		if ($values['template_name'] !== 'fisubsilversh') { $values['theme_public'] = '0'; }
		$labels = phpbb_acl_rows($db, 'SELECT * FROM ' . THEMES_NAME_TABLE . ' WHERE themes_id=' . $id . ' FOR UPDATE');
		if (count($labels) > 1) { phpbb_acl_error('xs_clone_failed'); }
		$label_values = $labels ? phpbb_style_clone_columns($labels[0]) : array();
		$condition = " WHERE style_name='" . $db->sql_escape($name) . "'";
		$rows = phpbb_acl_rows($db, 'SELECT * FROM ' . THEMES_TABLE . $condition . ' FOR UPDATE');
		$created = !$rows;
		if ($created)
		{
			$attempted = true; phpbb_style_clone_insert($db, THEMES_TABLE, $values);
			$rows = phpbb_acl_rows($db, 'SELECT * FROM ' . THEMES_TABLE . $condition);
		}
		if (count($rows) !== 1 || (int)$rows[0]['themes_id'] === $id) { phpbb_acl_error('xs_clone_taken'); }
		$clone_id = phpbb_style_policy_id($rows[0]['themes_id'], 'xs_clone_failed');
		phpbb_style_clone_verify($rows[0], $values);
		$stored_labels = phpbb_acl_rows($db, 'SELECT * FROM ' . THEMES_NAME_TABLE . ' WHERE themes_id=' . $clone_id . ' FOR UPDATE');
		if ($created && $stored_labels) { phpbb_acl_error('xs_clone_failed'); }
		if ($created && $labels)
		{
			phpbb_style_clone_insert($db, THEMES_NAME_TABLE, array_merge(array('themes_id'=>(string)$clone_id), $label_values));
			$stored_labels = phpbb_acl_rows($db, 'SELECT * FROM ' . THEMES_NAME_TABLE . ' WHERE themes_id=' . $clone_id);
		}
		if (count($stored_labels) !== count($labels)) { phpbb_acl_error('xs_clone_failed'); }
		if ($labels) { phpbb_style_clone_verify($stored_labels[0], $label_values); }
		// Matching retry after lost ACK/cache failure: preserve the existing ID.
		$attempted = true; phpbb_style_storage_lock_authority($db); $db->sql_query('COMMIT');
	}
	finally { phpbb_style_actions_finish($db, $attempted, $phpbb_root_path); }
	return $clone_id;
}

// Capture metadata and files while holding the same owner used by style
// imports/removal. Never clone a partially published, recoverable template.
function phpbb_style_clone_package($database, $request, $source, $target, $selection)
{
	global $pack_error, $pack_list, $pack_replace;
	phpbb_style_removal_request($request);
	if (!phpbb_style_removal_name($source) || !phpbb_style_removal_name($target) || strcasecmp($source, $target) === 0
		|| !is_array($selection) || !$selection || count($selection) > XS_MAX_ITEMS_PER_STYLE) { phpbb_acl_error('xs_invalid_style_name'); }
	$ids = array();
	foreach ($selection as $id => $name)
	{
		$ids[] = phpbb_style_policy_id((string)$id, 'xs_invalid_style_id');
		if (!is_string($name)) { phpbb_acl_error('xs_invalid_style_name'); }
		$count = preg_match_all('/./us', $name, $characters);
		if ($name === '' || trim($name) !== $name || $count === false || $count > 30 || preg_match('/[\x00-\x1f\x7f]/', $name)) { phpbb_acl_error('xs_invalid_style_name'); }
	}
	$db = new PhpbbStyleCloneWriter($database);
	try
	{
		phpbb_style_removal_start($db, false);
		phpbb_style_import_require_available($db, array($source, $target));
		$rows = phpbb_acl_rows($db, "SELECT * FROM " . THEMES_TABLE . " WHERE template_name='" . $db->sql_escape($source) . "' AND themes_id IN (" . implode(',', $ids) . ') ORDER BY themes_id FOR UPDATE');
		if (count($rows) !== count($ids)) { phpbb_acl_error('xs_clone_failed'); }
		foreach ($rows as $index => $row)
		{
			if ($row['template_name'] !== $source) { phpbb_acl_error('xs_clone_failed'); }
			$rows[$index]['style_name'] = $selection[(int)$row['themes_id']];
		}
		phpbb_style_storage_lock_authority($db);
		$pack_error = ''; $pack_list = array();
		$pack_replace = array('./theme_info.cfg' => xs_generate_themeinfo($rows, $source, $target));
		$data = pack_style($source, $target, $rows, '');
		if ($pack_error || !is_string($data) || $data === '' || strlen($data) > XS_MAX_STYLE_UPLOAD_BYTES) { phpbb_acl_error('xs_clone_failed'); }
		// Lost ownership/authority during capture must never return usable data.
		phpbb_style_storage_lock_authority($db); $db->sql_query('COMMIT');
	}
	finally { $db->rollback(); $db->release(); }
	return $data;
}
