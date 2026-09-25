<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_style_import.php';
require_once dirname(__FILE__) . '/functions_style_import_files.php';
require_once dirname(__FILE__) . '/functions_style_import_journal.php';
require_once dirname(__FILE__) . '/functions_style_import_receipt.php';
require_once dirname(__FILE__) . '/functions_style_archive.php';

class PhpbbStyleImportRecoveryWriter extends PhpbbStyleImportWriter
{
	function sql_query($sql, $transaction = false)
	{
		if (is_string($sql) && strpos($sql, 'INSERT INTO ' . CONFIG_TABLE . ' (config_name,config_value) SELECT ') === 0)
		{
			if (!preg_match("/^INSERT INTO " . preg_quote(CONFIG_TABLE, '/') . " \(config_name,config_value\) SELECT 'xs_import_[a-f0-9]{64}',/", $sql)) { phpbb_acl_error('xs_import_failed'); }
			return PhpbbStyleRemovalWriter::sql_query($sql, $transaction);
		}
		return parent::sql_query($sql, $transaction);
	}
}

function phpbb_style_import_recovery_context($context)
{
	if (!is_array($context) || count($context) !== 3 || !isset($context['template'], $context['batch'], $context['default'])
		|| !phpbb_style_removal_name($context['template']) || !is_array($context['batch']) || count($context['batch']) > XS_MAX_ITEMS_PER_STYLE
		|| !is_int($context['default']) || $context['default'] < -1 || $context['default'] >= XS_MAX_ITEMS_PER_STYLE) { phpbb_acl_error('xs_import_failed'); }
	foreach ($context['batch'] as $index => $values)
	{
		if (!is_int($index) || $index < 0 || $index >= XS_MAX_ITEMS_PER_STYLE || !is_array($values)) { phpbb_acl_error('xs_import_failed'); }
		$input = $values; unset($input['theme_public']);
		foreach ($input as $field => $value) { if ($value === null) { $input[$field] = ''; } }
		if (phpbb_style_install_values($input, $context['template']) !== $values) { phpbb_acl_error('xs_import_failed'); }
	}
	if ($context['default'] !== -1 && ($context['template'] !== 'fisubsilversh' || !isset($context['batch'][$context['default']]))) { phpbb_acl_error('xs_import_failed'); }
	return $context;
}

function phpbb_style_import_recovery_start($db, $rollback)
{
	if (!$rollback) { phpbb_style_removal_start($db, false); return; }
	// Restoring file originals does not select or create a default style. It
	// must remain possible even if independent damage removed that setting;
	// otherwise the pending guard would also prevent an ERC style repair.
	$db->actor(); phpbb_style_storage_start($db, array(CONFIG_TABLE, THEMES_TABLE, USERS_TABLE, SESSIONS_TABLE, JR_ADMIN_TABLE));
	phpbb_acl_rows($db, 'SELECT config_name FROM ' . CONFIG_TABLE . " WHERE config_name='default_style' FOR UPDATE");
	phpbb_acl_rows($db, 'SELECT themes_id FROM ' . THEMES_TABLE . ' FORCE INDEX (PRIMARY) ORDER BY themes_id FOR UPDATE');
}

function phpbb_style_import_recovery_request($request)
{
	phpbb_style_removal_request($request);
	if (!isset($request['recovery_action'], $request['recovery_template'], $request['recovery_operation'])
		|| !in_array($request['recovery_action'], array('start','resume','rollback'), true)
		|| !phpbb_style_removal_name($request['recovery_template']) || !PhpbbStyleImportJournal::operation($request['recovery_operation'])) { phpbb_acl_error('xs_import_failed'); }
	return array($request['recovery_action'], $request['recovery_template'], $request['recovery_operation']);
}

function phpbb_style_import_recovery_jobs($database, $request = null)
{
	if ($request !== null) { list($mode, $name, $operation) = phpbb_style_import_recovery_request($request); if ($mode === 'start') { phpbb_acl_error('xs_import_failed'); } }
	$db = new PhpbbStyleImportRecoveryWriter($database); $jobs = array();
	try
	{
		$db->actor(); phpbb_style_storage_start($db, array(CONFIG_TABLE, USERS_TABLE, SESSIONS_TABLE, JR_ADMIN_TABLE));
		if ($request !== null)
		{
			$r = phpbb_style_import_read_receipt($db, $name);
			if ($r === null || $r['o'] !== $operation || ($r['s'] === 'committed' && $mode === 'rollback') || ($r['s'] === 'rolledback' && $mode !== 'rollback')) { phpbb_acl_error('xs_import_failed'); }
			$jobs[] = $r;
		}
		else
		{
			$rows = phpbb_acl_rows($db, 'SELECT config_name,config_value FROM ' . CONFIG_TABLE . " WHERE LEFT(config_name,10)='xs_import_' ORDER BY config_name FOR UPDATE");
			$seen = array();
			foreach ($rows as $row)
			{
				$r = phpbb_style_import_receipt($row['config_value']);
				if (!$r || $row['config_name'] !== phpbb_style_import_receipt_key($r['t']) || isset($seen[$row['config_name']])) { phpbb_acl_error('xs_import_failed'); }
				$seen[$row['config_name']] = true; $jobs[] = $r;
			}
		}
		phpbb_style_storage_lock_authority($db); $db->sql_query('COMMIT');
	}
	finally { $db->rollback(); $db->release(); }
	return $jobs;
}

function phpbb_style_import_recovery_environment($root, $local, $directory, $ftp, $settings)
{
	$root = PhpbbStyleImportJournal::base($root); $base = $root . DIRECTORY_SEPARATOR . 'cache';
	$base = PhpbbStyleImportJournal::base($base);
	if ($local) { $files = new PhpbbStyleImportLocal($directory); }
	else
	{
		if (!isset($settings['xs_ftp_host'], $settings['xs_ftp_login']) || !is_string($settings['xs_ftp_host']) || !is_string($settings['xs_ftp_login'])) { phpbb_acl_error('xs_import_failed'); }
		$endpoint = json_encode(array(strtolower($settings['xs_ftp_host']), 21, $settings['xs_ftp_login']));
		if (!is_string($endpoint)) { phpbb_acl_error('xs_import_failed'); }
		$files = new PhpbbStyleImportFtp($ftp, $endpoint);
	}
	return array($files, $base, 'forum:' . $root);
}

// No automatic rollback on exceptions: the final SQL COMMIT may have succeeded
// despite a lost acknowledgement. A new owned connection must read its receipt.
function phpbb_style_import_recovery($database, $request, $files, $base, $identity, $start = null)
{
	global $phpbb_root_path, $board_config;
	list($mode, $name, $operation) = phpbb_style_import_recovery_request($request);
	$context = null; $input_hash = null; $desired = array(); $directories = array();
	if ($mode === 'start')
	{
		if (!is_array($start) || !isset($start['header'], $start['archive'], $start['entries']) || !is_array($start['header'])
			|| !isset($start['header']['template']) || $start['header']['template'] !== $name) { phpbb_acl_error('xs_import_failed'); }
		// Revalidate the archive in the worker too; no caller-provided offsets
		// may smuggle files past the whole-package preflight.
		try { $entries = phpbb_style_archive_entries($start['archive']); }
		catch (Exception $error) { phpbb_acl_error('xs_import_failed'); }
		if ($entries !== $start['entries']) { phpbb_acl_error('xs_import_failed'); }
		list($batch, $default) = phpbb_style_import_selection($request, $start['header'], $entries, $start['archive']);
		$context = phpbb_style_import_recovery_context(array('template'=>$name, 'batch'=>$batch, 'default'=>$default));
		$input_hash = hash('sha256', hash('sha256', $start['archive']) . json_encode($context));
		foreach ($entries as $entry)
		{
			$relative = rtrim($entry['filename'], '/'); $path = $name . ($relative === '' ? '' : '/' . $relative);
			if ($entry['typeflag'] === 5) { $directories[] = $path; }
			else { $desired[$path] = substr($start['archive'], $entry['offset'], $entry['size']); }
		}
		if (!$desired) { phpbb_acl_error('xs_import_failed'); }
	}
	elseif ($start !== null) { phpbb_acl_error('xs_import_failed'); }
	$db = new PhpbbStyleImportRecoveryWriter($database); $attempted = false; $default_id = null; $result = null;
	try
	{
		phpbb_style_import_recovery_start($db, $mode === 'rollback'); $receipt = phpbb_style_import_read_receipt($db, $name); $journal = null;
		if ($mode === 'start' && ($receipt === null || $receipt['o'] !== $operation))
		{
			if ($receipt !== null && $receipt['s'] === 'prepared') { phpbb_acl_error('xs_import_pending'); }
			phpbb_style_import_validate_batch($db, $context['batch'], array('template'=>$name));
			phpbb_style_storage_lock_authority($db); $guard = function () use ($db) { $db->actor(); };
			$journal = PhpbbStyleImportJournal::prepare($files, $base, $identity, $desired, $guard, $context, $directories, $operation);
			$receipt = array('v'=>1, 't'=>$name, 'o'=>$operation, 's'=>'prepared', 'h'=>$journal->seal(), 'a'=>$input_hash);
			$attempted = true; phpbb_style_import_retire_cleanup($db, $name); phpbb_style_import_store_receipt($db, $receipt);
			phpbb_style_storage_lock_authority($db); $db->sql_query('COMMIT');
			// The dedicated named owner survives COMMIT, but actor/session/grant
			// and all native ranges are reacquired before the first file effect.
			phpbb_style_removal_start($db, false);
			if (phpbb_style_import_read_receipt($db, $name) !== $receipt) { phpbb_acl_error('xs_import_failed'); }
		}
		if ($receipt === null || $receipt['o'] !== $operation || ($mode === 'start' && $receipt['a'] !== $input_hash)) { phpbb_acl_error('xs_import_failed'); }
		$attempted = true;
		if ($receipt['s'] !== 'prepared')
		{
			if (($receipt['s'] === 'committed' && $mode === 'rollback') || ($receipt['s'] === 'rolledback' && $mode !== 'rollback')) { phpbb_acl_error('xs_import_failed'); }
			// Do not reopen or overwrite files after confirmed finalization.
			// Cache eviction below is retried even if the old acknowledgement died.
			phpbb_style_storage_lock_authority($db); $db->sql_query('COMMIT');
			$result = array('state'=>$receipt['s'], 'installed'=>null, 'retry'=>true);
		}
		else
		{
			if ($journal === null) { $journal = PhpbbStyleImportJournal::reopen($base, $operation, $receipt['h'], $identity); }
			$context = phpbb_style_import_recovery_context($journal->context());
			if ($context['template'] !== $name) { phpbb_acl_error('xs_import_failed'); }
			if ($mode !== 'rollback') { phpbb_style_import_validate_batch($db, $context['batch'], array('template'=>$name)); }
			phpbb_style_storage_lock_authority($db); $guard = function () use ($db) { $db->actor(); };
			$journal->apply($files, $guard, $mode === 'rollback');
			if ($mode !== 'rollback') { $default_id = phpbb_style_import_register_batch($db, $context['batch'], array('template'=>$name), $context['default']); }
			$receipt['s'] = $mode === 'rollback' ? 'rolledback' : 'committed';
			phpbb_style_import_store_receipt($db, $receipt);
			phpbb_style_storage_lock_authority($db); $db->sql_query('COMMIT');
			$result = array('state'=>$receipt['s'], 'installed'=>$mode === 'rollback' ? 0 : count($context['batch']), 'retry'=>false);
		}
	}
	finally { phpbb_style_actions_finish($db, $attempted, $phpbb_root_path); }
	if ($default_id !== null) { $board_config['default_style'] = $default_id; }
	return $result;
}
