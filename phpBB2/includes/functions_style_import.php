<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_style_install.php';

class PhpbbStyleImportWriter extends PhpbbStyleInstallWriter
{
	function update_tables() { return array(THEMES_TABLE, CONFIG_TABLE); }
}

function phpbb_style_import_selection($request, $header, $entries, $archive)
{
	phpbb_style_removal_request($request);
	if (!isset($header['template'], $header['styles']) || !phpbb_style_removal_name($header['template']) || !is_array($header['styles'])
		|| !isset($request['total']) || !is_string($request['total']) || !preg_match('/^[0-9]{1,2}$/D', $request['total'])
		|| (int)$request['total'] > count($header['styles']) || (int)$request['total'] > XS_MAX_ITEMS_PER_STYLE) { phpbb_acl_error('xs_import_failed'); }
	$default = isset($request['import_default']) ? $request['import_default'] : '-1';
	if (!is_string($default) || !preg_match('/^(?:-1|[0-9]{1,2})?$/D', $default)) { phpbb_acl_error('xs_import_failed'); }
	$default = $default === '' ? -1 : (int)$default; $selected = array();
	foreach ($request as $key => $value)
	{
		if (strpos($key, 'import_install_') !== 0) { continue; }
		$index = substr($key, 15);
		if (!preg_match('/^(?:0|[1-9][0-9]?)$/D', $index) || (int)$index >= (int)$request['total'] || !is_string($value)
			|| !in_array($value, array('','0','1','on'), true)) { phpbb_acl_error('xs_import_failed'); }
		if ($value === '1' || $value === 'on') { $selected[(int)$index] = true; }
	}
	if ($default !== -1 && (!isset($selected[$default]) || $header['template'] !== 'fisubsilversh')) { phpbb_acl_error('xs_import_failed'); }
	if (!$selected) { return array(array(), -1); }
	$definitions = array();
	foreach ($entries as $entry)
	{
		if ($entry['filename'] === 'theme_info.cfg' && $entry['typeflag'] === 0)
		{ $definitions = xs_parse_themeinfo($header['template'], substr($archive, $entry['offset'], $entry['size'])); }
	}
	$batch = array();
	foreach ($selected as $index => $ignored)
	{
		$match = array();
		foreach ($definitions as $definition) { if ($definition['style_name'] === $header['styles'][$index]) { $match[] = $definition; } }
		if (count($match) !== 1) { phpbb_acl_error('xs_import_failed'); }
		$batch[$index] = phpbb_style_install_values($match[0], $header['template']);
	}
	return array($batch, $default);
}

// File publication is a separate, checked callback under the same current
// authority/range/owner locks as registration. Files cannot be rolled back by
// SQL; report any failure and never commit a partial metadata/default batch.
function phpbb_style_import($database, $request, $header, $entries, $archive, $publish)
{
	global $phpbb_root_path, $board_config;
	list($batch, $default) = phpbb_style_import_selection($request, $header, $entries, $archive);
	$db = new PhpbbStyleImportWriter($database); $attempted = false; $default_id = null;
	try
	{
		phpbb_style_removal_start($db, false); $names = array();
		// Reject collation-equivalent names and cross-template collisions before
		// publishing even the first file, not halfway through registration.
		foreach ($batch as $values)
		{
			$name = $db->sql_escape($values['style_name']);
			foreach ($names as $previous)
			{
				$equal = phpbb_acl_rows($db, "SELECT '" . $name . "' COLLATE utf8mb4_unicode_ci = '" . $previous . "' COLLATE utf8mb4_unicode_ci AS same_name");
				if (count($equal) !== 1 || (int)$equal[0]['same_name'] !== 0) { phpbb_acl_error('xs_import_failed'); }
			}
			$names[] = $name;
			$rows = phpbb_acl_rows($db, 'SELECT themes_id,template_name FROM ' . THEMES_TABLE . " WHERE style_name='" . $name . "' FOR UPDATE");
			if (count($rows) > 1 || ($rows && $rows[0]['template_name'] !== $header['template'])) { phpbb_acl_error('xs_import_failed'); }
		}
		phpbb_style_storage_lock_authority($db);
		$guard = function () use ($db) { $db->actor(); };
		$attempted = true; call_user_func($publish, $guard);
		$ids = array();
		foreach ($batch as $index => $values)
		{
			$name = $db->sql_escape($values['style_name']);
			$rows = phpbb_acl_rows($db, 'SELECT * FROM ' . THEMES_TABLE . " WHERE style_name='" . $name . "' FOR UPDATE");
			if (count($rows) > 1) { phpbb_acl_error('xs_import_failed'); }
			$literals = array(); $updates = array();
			foreach ($values as $field => $value) { $literal = phpbb_style_data_literal($db, $value); $literals[] = $literal; $updates[] = $field . '=' . $literal; }
			$actor = $db->actor();
			if ($rows)
			{
				$id = phpbb_style_policy_id($rows[0]['themes_id'], 'xs_import_failed');
				if ($rows[0]['template_name'] !== $header['template']) { phpbb_acl_error('xs_import_failed'); }
				$db->sql_query('UPDATE ' . THEMES_TABLE . ' SET ' . implode(',', $updates) . ' WHERE themes_id=' . $id . ' AND ' . $actor['guard']);
			}
			else { $db->sql_query('INSERT INTO ' . THEMES_TABLE . ' (' . implode(',', array_keys($values)) . ') SELECT ' . implode(',', $literals) . ' WHERE ' . $actor['guard']); }
			$rows = phpbb_acl_rows($db, 'SELECT * FROM ' . THEMES_TABLE . " WHERE style_name='" . $name . "'");
			if (count($rows) !== 1) { phpbb_acl_error('xs_import_failed'); }
			$id = phpbb_style_policy_id($rows[0]['themes_id'], 'xs_import_failed');
			if (in_array($id, $ids, true)) { phpbb_acl_error('xs_import_failed'); }
			foreach ($values as $field => $value) { if ($value === null ? $rows[0][$field] !== null : (string)$rows[0][$field] !== $value) { phpbb_acl_error('xs_import_failed'); } }
			$ids[] = $id; if ($index === $default) { $default_id = $id; }
		}
		if ($default_id !== null)
		{
			$actor = $db->actor();
			$db->sql_query('UPDATE ' . CONFIG_TABLE . " SET config_value='" . $default_id . "' WHERE config_name='default_style' AND " . $actor['guard']);
			if (phpbb_style_policy_default($db, 'xs_import_failed') !== $default_id) { phpbb_acl_error('xs_import_failed'); }
		}
		phpbb_style_storage_lock_authority($db); $db->sql_query('COMMIT');
	}
	finally { phpbb_style_actions_finish($db, $attempted, $phpbb_root_path); }
	if ($default_id !== null) { $board_config['default_style'] = $default_id; }
	return count($batch);
}
