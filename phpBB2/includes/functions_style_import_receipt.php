<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }

function phpbb_style_import_receipt_key($template)
{
	if (!is_string($template) || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,29}$/D', $template)
		|| strpos($template, '..') !== false || substr($template, -1) === '.') { phpbb_acl_error('xs_import_failed'); }
	return 'xs_import_' . hash('sha256', strtolower($template));
}

function phpbb_style_import_receipt($value)
{
	if (!is_string($value) || strlen($value) > 255) { return false; }
	$r = json_decode($value, true);
	// Compact keys fit the existing 255-byte configuration value: version,
	// template, operation, state, manifest seal and exact input/selection hash.
	if (!is_array($r) || count($r) !== 6 || !isset($r['v'], $r['t'], $r['o'], $r['s'], $r['h'], $r['a']) || $r['v'] !== 1
		|| !is_string($r['t']) || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,29}$/D', $r['t']) || strpos($r['t'], '..') !== false || substr($r['t'], -1) === '.'
		|| !is_string($r['o']) || !preg_match('/^[a-f0-9]{32}$/D', $r['o']) || !in_array($r['s'], array('prepared','committed','rolledback'), true)
		|| !is_string($r['h']) || !preg_match('/^[a-f0-9]{64}$/D', $r['h']) || !is_string($r['a']) || !preg_match('/^[a-f0-9]{64}$/D', $r['a'])) { return false; }
	// Writer output is canonical. Reject duplicate JSON keys and altered
	// encodings instead of interpreting an ambiguous recovery state.
	return json_encode($r) === $value ? $r : false;
}

function phpbb_style_import_read_receipt($db, $template)
{
	$key = phpbb_style_import_receipt_key($template);
	$rows = phpbb_acl_rows($db, 'SELECT config_name,config_value FROM ' . CONFIG_TABLE . " WHERE config_name='" . $key . "' FOR UPDATE");
	if (!$rows) { return null; }
	$r = count($rows) === 1 ? phpbb_style_import_receipt($rows[0]['config_value']) : false;
	if (!$r || $rows[0]['config_name'] !== $key || $r['t'] !== $template) { phpbb_acl_error('xs_import_failed'); }
	return $r;
}

function phpbb_style_import_store_receipt($db, $receipt)
{
	$json = json_encode($receipt); $valid = phpbb_style_import_receipt($json);
	if (!$valid) { phpbb_acl_error('xs_import_failed'); }
	$key = phpbb_style_import_receipt_key($receipt['t']); $old = phpbb_style_import_read_receipt($db, $receipt['t']);
	$actor = $db->actor(); $value = $db->sql_escape($json);
	$sql = $old === null ? 'INSERT INTO ' . CONFIG_TABLE . " (config_name,config_value) SELECT '" . $key . "','" . $value . "' WHERE " :
		'UPDATE ' . CONFIG_TABLE . " SET config_value='" . $value . "' WHERE config_name='" . $key . "' AND ";
	$db->sql_query($sql . $actor['guard']);
	if (phpbb_style_import_read_receipt($db, $receipt['t']) !== $receipt) { phpbb_acl_error('xs_import_failed'); }
}

function phpbb_style_import_require_available($db, $templates)
{
	foreach (array_unique($templates) as $template)
	{
		$receipt = phpbb_style_import_read_receipt($db, $template);
		if ($receipt !== null && $receipt['s'] === 'prepared') { phpbb_acl_error('xs_import_pending'); }
	}
}
