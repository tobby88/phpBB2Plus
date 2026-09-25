<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }

function phpbb_style_policy_id($value, $error)
{
	if (!is_string($value) && !is_int($value)) { phpbb_acl_error($error); }
	if (!preg_match('/^[0-9]{1,10}$/D', (string)$value) || (float)$value < 1 || (float)$value > 16777215) { phpbb_acl_error($error); }
	return (int)$value;
}

function phpbb_style_policy_valid($theme, $require_public = true)
{
	return isset($theme['template_name'], $theme['theme_public']) && $theme['template_name'] === 'fisubsilversh'
		&& in_array((string)$theme['theme_public'], $require_public ? array('1') : array('0','1'), true);
}

function phpbb_style_policy_default($db, $error)
{
	// Lock the configuration row BEFORE theme rows in every participating editor.
	$rows = phpbb_acl_rows($db, "SELECT config_name,config_value FROM " . CONFIG_TABLE . " WHERE config_name='default_style' FOR UPDATE");
	if (count($rows) !== 1 || $rows[0]['config_name'] !== 'default_style') { phpbb_acl_error($error); }
	return phpbb_style_policy_id($rows[0]['config_value'], $error);
}

function phpbb_style_policy_select($db, $value, $error, $lock = true)
{
	$id = phpbb_style_policy_id($value, $error);
	$rows = phpbb_acl_rows($db, 'SELECT themes_id,template_name,theme_public FROM ' . THEMES_TABLE . ' WHERE themes_id=' . $id . ($lock ? ' FOR UPDATE' : ''));
	if (count($rows) !== 1 || !phpbb_style_policy_valid($rows[0], false)) { phpbb_acl_error($error); }
	return $id;
}
