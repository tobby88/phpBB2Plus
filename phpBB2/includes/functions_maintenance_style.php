<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/../attach_mod/includes/functions_mutation.php';
require_once dirname(__FILE__) . '/functions_style_import_receipt.php';

function dbmtnc_erc_style_rows($sql)
{
	global $db;
	$result = $db->sql_query($sql);
	if (!$result) { return false; }
	$rows = array(); while ($row = $db->sql_fetchrow($result)) { $rows[] = $row; }
	$db->sql_freeresult($result); return $rows;
}

function dbmtnc_erc_style_authority($expected, &$guard)
{
	return check_authorisation(false, $guard, $actor_id) && $actor_id === $expected;
}

function dbmtnc_erc_style_config()
{
	$rows = dbmtnc_erc_style_rows("SELECT config_name, config_value FROM " . CONFIG_TABLE . " WHERE config_name = 'default_style'");
	return is_array($rows) && count($rows) === 1 && $rows[0]['config_name'] === 'default_style' ? $rows[0] : false;
}

// Keep cleanup in its own scope: some PHP 5.6 builds loop when this nested
// try/finally is compiled inside the repair's finally block. The owner must
// still be released if filesystem cleanup itself raises an exception.
function dbmtnc_erc_style_finish($original, $lock, $attempted)
{
	global $db, $phpbb_root_path;
	$db = $original; $clean = true;
	try
	{
		if ($attempted)
		{
			foreach (array('config_data.cache', 'themes.cache') as $file)
			{
				$cache = $phpbb_root_path . 'cache/' . $file; clearstatcache(true, $cache);
				if ((file_exists($cache) || is_link($cache)) && !@unlink($cache)) { $clean = false; }
			}
		}
	}
	finally { $lock->release(); }
	return $clean;
}

// Recover only the preserved style. Never execute a theme_info.cfg import or
// overwrite a pre-existing theme's custom properties. Selection is explicit;
// recreation reuses an unambiguous existing standard row, including on retry.
function dbmtnc_erc_reset_style($method, $selected, $expected_actor_id)
{
	global $db, $phpbb_root_path, $board_config;
	if (!is_int($expected_actor_id) || $expected_actor_id <= 0 || !in_array($method, array('select_theme', 'recreate_theme'), true)) { return false; }
	if ($method === 'select_theme' && (!(is_string($selected) || is_int($selected))
		|| !preg_match('/^[1-9][0-9]{0,7}$/D', (string)$selected) || (int)$selected > 16777215)) { return false; }
	foreach (array('fisubsilversh.cfg', 'fisubsilversh.css', 'overall_header.tpl', 'overall_footer.tpl') as $file)
	{
		if (!is_file($phpbb_root_path . 'templates/fisubsilversh/' . $file)) { return false; }
	}
	if (!dbmtnc_erc_style_authority($expected_actor_id, $guard)) { return false; }
	$original = $db; $lock = new attach_mutation_lock($db);
	if (!$lock->acquired) { return false; }
	$attempted = false; $success = false; $created = false;
	try
	{
		$db = $lock->connection;
		if (!dbmtnc_erc_style_authority($expected_actor_id, $guard) || !dbmtnc_erc_style_config()) { return false; }
		$import_key = phpbb_style_import_receipt_key('fisubsilversh');
		$imports = dbmtnc_erc_style_rows('SELECT config_name,config_value FROM ' . CONFIG_TABLE . " WHERE config_name='" . $import_key . "'");
		if (!is_array($imports) || count($imports) > 1) { return false; }
		if ($imports)
		{
			$receipt = phpbb_style_import_receipt($imports[0]['config_value']);
			if (!$receipt || $imports[0]['config_name'] !== $import_key || $receipt['t'] !== 'fisubsilversh' || $receipt['s'] === 'prepared') { return false; }
		}
		$config_guard = 'EXISTS (SELECT 1 FROM (SELECT COUNT(*) AS row_count,'
			. " SUM(BINARY config_name = 'default_style') AS exact_count FROM " . CONFIG_TABLE
			. " WHERE config_name = 'default_style') erc_style_current WHERE row_count = 1 AND exact_count = 1)";
		$standard_where = "template_name = 'fisubsilversh'";
		if ($method === 'recreate_theme')
		{
			$rows = dbmtnc_erc_style_rows('SELECT themes_id, template_name, theme_public FROM ' . THEMES_TABLE . ' WHERE ' . $standard_where);
			if ($rows === false || count($rows) > 1) { return false; }
			if (!$rows)
			{
				if (!dbmtnc_erc_style_authority($expected_actor_id, $guard)) { return false; }
				$attempted = true;
				// Match the installer's standard defaults; the owning connection
				// serializes cooperating repairs. NOT EXISTS also preserves a row
				// independently restored between our read and this dispatch.
				$sql = 'INSERT INTO ' . THEMES_TABLE
					. ' (template_name, style_name, head_stylesheet, body_background, body_bgcolor, body_text, body_link, body_vlink, body_alink, body_hlink, tr_color1, tr_color2, tr_color3, tr_class1, tr_class2, tr_class3, th_color1, th_color2, th_color3, th_class1, th_class2, th_class3, td_color1, td_color2, td_color3, td_class1, td_class2, td_class3, fontface1, fontface2, fontface3, fontsize1, fontsize2, fontsize3, fontcolor1, fontcolor2, fontcolor3, span_class1, span_class2, span_class3, img_size_poll, img_size_privmsg, theme_public)'
					. " SELECT 'fisubsilversh', 'FI Subsilver Shadow', 'fisubsilversh.css', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', 'row1', 'row2', '', '', '', '', 0, 0, 0, '', '006600', 'ffa34f', '', '', '', 0, 0, 1"
					. ' WHERE (' . $guard . ') AND ' . $config_guard . ' AND NOT EXISTS (SELECT 1 FROM ' . THEMES_TABLE . ' WHERE ' . $standard_where . ')';
				if (!$db->sql_query($sql)) { return false; }
				$created = (int)$db->sql_affectedrows() === 1;
				if (!dbmtnc_erc_style_authority($expected_actor_id, $guard)) { return false; }
				$rows = dbmtnc_erc_style_rows('SELECT themes_id, template_name, theme_public FROM ' . THEMES_TABLE . ' WHERE ' . $standard_where);
			}
			if (!is_array($rows) || count($rows) !== 1 || $rows[0]['template_name'] !== 'fisubsilversh' || (int)$rows[0]['theme_public'] !== 1) { return false; }
			$selected = (int)$rows[0]['themes_id'];
		}
		$selected = (int)$selected;
		if ($selected <= 0 || $selected > 16777215) { return false; }
		$theme_where = 'themes_id = ' . $selected . " AND BINARY template_name = 'fisubsilversh' AND theme_public = 1";
		$rows = dbmtnc_erc_style_rows('SELECT themes_id FROM ' . THEMES_TABLE . ' WHERE ' . $theme_where);
		if (!is_array($rows) || count($rows) !== 1 || !dbmtnc_erc_style_authority($expected_actor_id, $guard)) { return false; }
		$attempted = true;
		// The selected theme must remain usable at the very same dispatch that
		// changes both account and board. No intermediate account-only update.
		if (!$db->sql_query('UPDATE ' . USERS_TABLE . ' erc_style_user CROSS JOIN ' . CONFIG_TABLE . ' erc_style_config CROSS JOIN ' . THEMES_TABLE . ' erc_style_theme'
			. ' SET erc_style_user.user_style = ' . $selected . ", erc_style_config.config_value = '" . $selected . "'"
			. ' WHERE erc_style_user.user_id = ' . $expected_actor_id . " AND erc_style_config.config_name = 'default_style'"
			. ' AND erc_style_theme.themes_id = ' . $selected . " AND BINARY erc_style_theme.template_name = 'fisubsilversh' AND erc_style_theme.theme_public = 1"
			. ' AND (' . $guard . ') AND ' . $config_guard)) { return false; }
		if (!dbmtnc_erc_style_authority($expected_actor_id, $guard)) { return false; }
		$config = dbmtnc_erc_style_config();
		$users = dbmtnc_erc_style_rows('SELECT user_style FROM ' . USERS_TABLE . ' WHERE user_id = ' . $expected_actor_id);
		$themes = dbmtnc_erc_style_rows('SELECT themes_id FROM ' . THEMES_TABLE . ' WHERE ' . $theme_where);
		if (!$config || $config['config_value'] !== (string)$selected || !is_array($users) || count($users) !== 1 || (int)$users[0]['user_style'] !== $selected
			|| !is_array($themes) || count($themes) !== 1 || !dbmtnc_erc_style_authority($expected_actor_id, $guard)) { return false; }
		$success = array('style_id' => $selected, 'created' => $created);
	}
	catch (Exception $error) { $success = false; }
	catch (Throwable $error) { $success = false; }
	finally
	{
		// Try BOTH caches even if one fails. A completed insert/update may
		// survive a lost ACK; cleanup failure must not become success.
		if (!dbmtnc_erc_style_finish($original, $lock, $attempted)) { $success = false; }
	}
	if ($success !== false) { $board_config['default_style'] = (string)$selected; }
	return $success;
}
