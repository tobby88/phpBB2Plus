<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_style_removal.php';

class PhpbbStyleExportWriter extends PhpbbStyleRemovalWriter
{
	function update_tables() { return array(CONFIG_TABLE); }
}

// The owner and current authority stay held through capture and file/FTP
// delivery. Downloads start only after confirmed completion.
// Export does not need a healthy default-style setting to back up its source.
function phpbb_style_export_open($database, $request, $source, $selection)
{
	phpbb_style_removal_request($request);
	if (!phpbb_style_removal_name($source) || !is_array($selection) || !$selection || count($selection) > XS_MAX_ITEMS_PER_STYLE)
	{ phpbb_acl_error('xs_export_noselect_themes'); }
	$ids = array();
	foreach ($selection as $id => $name)
	{
		$ids[] = phpbb_style_policy_id((string)$id, 'xs_invalid_style_id');
		if (!is_string($name) || trim($name) !== $name || $name === '' || preg_match_all('/./us', $name, $characters) === false
			|| count($characters[0]) > 30 || preg_match('/[\x00-\x1f\x7f]/', $name)) { phpbb_acl_error('xs_invalid_style_name'); }
	}
	$owner = new PhpbbStyleExportWriter($database);
	try
	{
		$owner->actor(); phpbb_style_storage_start($owner, array(CONFIG_TABLE, THEMES_TABLE, USERS_TABLE, SESSIONS_TABLE, JR_ADMIN_TABLE));
		phpbb_acl_rows($owner, 'SELECT themes_id FROM ' . THEMES_TABLE . ' FORCE INDEX (PRIMARY) ORDER BY themes_id FOR UPDATE');
		phpbb_style_import_require_available($owner, array($source));
		$rows = phpbb_acl_rows($owner, "SELECT * FROM " . THEMES_TABLE . " WHERE template_name='" . $owner->sql_escape($source) . "' AND themes_id IN (" . implode(',', $ids) . ') ORDER BY themes_id FOR UPDATE');
		if (count($rows) !== count($ids)) { phpbb_acl_error('xs_no_themes'); }
		foreach ($rows as $index => $row)
		{
			if ($row['template_name'] !== $source) { phpbb_acl_error('xs_no_themes'); }
			$rows[$index]['style_name'] = $selection[(int)$row['themes_id']];
		}
		phpbb_style_storage_lock_authority($owner);
		return array($owner, $rows);
	}
	catch (Exception $error) { $owner->rollback(); $owner->release(); throw $error; }
	catch (Error $error) { $owner->rollback(); $owner->release(); throw $error; }
}

function phpbb_style_export_complete($owner, $method, $preferences)
{
	global $phpbb_root_path;
	if (!($owner instanceof PhpbbStyleExportWriter) || !in_array($method, array('save','file','ftp'), true) || !is_array($preferences))
	{ phpbb_acl_error('xs_export_error2'); }
	$allowed = $method === 'file' ? array('dir') : ($method === 'ftp' ? array('host','login','ftpdir') : array());
	foreach ($preferences as $key => $value)
	{
		if (!in_array($key, $allowed, true) || !is_string($value) || strpos($value, "\0") !== false || preg_match('//u', $value) !== 1)
		{ phpbb_acl_error('xs_export_error2'); }
	}
	$preferences['method'] = $method; $value = serialize($preferences);
	// Remembering optional delivery fields must not truncate configuration or
	// reject a valid export. Long preferences retain just the selected method.
	if (strlen($value) > 255) { $value = serialize(array('method'=>$method)); }
	$attempted = false;
	try
	{
		$rows = phpbb_acl_rows($owner, 'SELECT config_name FROM ' . CONFIG_TABLE . " WHERE config_name='xs_export_data' FOR UPDATE");
		if (count($rows) > 1 || ($rows && $rows[0]['config_name'] !== 'xs_export_data')) { phpbb_acl_error('xs_export_error2'); }
		$actor = $owner->actor(); $literal = $owner->sql_escape($value);
		$sql = $rows ? 'UPDATE ' . CONFIG_TABLE . " SET config_value='" . $literal . "' WHERE config_name='xs_export_data' AND " :
			'INSERT INTO ' . CONFIG_TABLE . " (config_name,config_value) SELECT 'xs_export_data','" . $literal . "' WHERE ";
		$attempted = true; $owner->sql_query($sql . $actor['guard']);
		$stored = phpbb_acl_rows($owner, 'SELECT config_value FROM ' . CONFIG_TABLE . " WHERE config_name='xs_export_data'");
		if (count($stored) !== 1 || $stored[0]['config_value'] !== $value) { phpbb_acl_error('xs_export_error2'); }
		phpbb_style_storage_lock_authority($owner); $owner->sql_query('COMMIT');
	}
	finally { phpbb_style_data_finish($owner, $attempted, $phpbb_root_path . 'cache/config_data.cache'); }
}
