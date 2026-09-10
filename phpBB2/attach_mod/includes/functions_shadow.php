<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
if (!function_exists('attach_pm_stage_is_claimed')) { require_once dirname(__FILE__) . '/functions_pm_staging.php'; }

// Files may belong to unfinished posting forms. Only age-confirmed, abandoned
// uploads are eligible; an unavailable FTP MDTM is not proof of age.
function attach_shadow_expired_files($names, $now = null)
{
	global $upload_dir, $attach_config;
	$now = $now === null ? time() : (int) $now;
	$expired = array(); $connection = false;
	try
	{
		if (!$names) { return $expired; }
		if (intval($attach_config['allow_ftp_upload']))
		{
			if (!function_exists('ftp_mdtm')) { return $expired; }
			$connection = attach_init_ftp(false, true);
			if ($connection === false) { return $expired; }
		}
		foreach ($names as $name)
		{
			if (!is_string($name) || attach_ftp_listing_entry($name, '0') === false || !attach_inventory_file($name)) { continue; }
			if ($connection !== false) { $modified = @ftp_mdtm($connection, $name); }
			else
			{
				$path = $upload_dir . '/' . $name;
				clearstatcache(true, $path);
				if (is_link($path) || !is_file($path)) { continue; }
				$modified = @filemtime($path);
			}
			if (is_int($modified) && $modified > 0 && $modified <= $now - 86400) { $expired[] = $name; }
		}
	}
	catch (Exception $exception) { return array(); }
	catch (Error $exception) { return array(); }
	finally { if ($connection !== false) { @ftp_close($connection); } }
	return $expired;
}

function attach_shadow_query($database, $sql)
{
	global $lang;
	$result = $database->sql_query($sql);
	if (!$result) { message_die(GENERAL_ERROR, $lang['Attachment_shadow_database_failed']); }
	return $result;
}

function attach_shadow_require_pm_idle($database, $id)
{
	global $lang;
	$result = attach_shadow_query($database, 'SELECT d.attach_id FROM ' . ATTACHMENTS_DESC_TABLE . ' d WHERE d.attach_id = ' . (int)$id
		. ' AND EXISTS (SELECT 1 FROM ' . PRIVMSGS_TABLE . ' p WHERE p.privmsgs_write_payload IS NOT NULL AND ('
		. 'HEX(p.privmsgs_write_token) = HEX(d.pm_write_token) OR EXISTS (SELECT 1 FROM ' . ATTACHMENTS_TABLE
		. ' a WHERE a.attach_id = d.attach_id AND a.privmsgs_id = p.privmsgs_id)))');
	$reserved = $database->sql_numrows($result) > 0; $database->sql_freeresult($result);
	if ($reserved) { message_die(GENERAL_ERROR, $lang['Attachment_shadow_pending']); }
}

function attach_shadow_cleanup($posted_files, $posted_ids)
{
	global $db, $lang;
	$ids = is_array($posted_ids) ? attach_delete_id_array($posted_ids) : false;
	if ($ids === false || !is_array($posted_files)) { message_die(GENERAL_ERROR, $lang['Attachment_selection_invalid']); }
	if (!$ids && !$posted_files) { return; }
	$lock = attach_require_mutation_lock($db);
	try { attach_shadow_cleanup_locked($lock->connection, $posted_files, $ids); }
	finally { $lock->release(); }
}

function attach_shadow_cleanup_locked($database, $posted_files, $ids)
{
	global $lang;
	// Validate the entire selection before any database or filesystem mutation.
	$names = attach_shadow_selected_files($posted_files);
	$files = collect_attachments();
	$expired = attach_shadow_expired_files($names);
	$descriptions = array(); $links = array();
	foreach ($names as $name)
	{
		if (attach_pm_stage_is_claimed($database, $name)) { message_die(GENERAL_ERROR, $lang['Attachment_shadow_pending']); }
		if (!in_array($name, $expired, true)) { message_die(GENERAL_ERROR, $lang['Attachment_shadow_pending']); }
		$result = attach_shadow_query($database, 'SELECT attach_id FROM ' . ATTACHMENTS_DESC_TABLE . " WHERE physical_filename = '" . $database->sql_escape($name) . "' LIMIT 1");
		$registered = $database->sql_numrows($result) > 0; $database->sql_freeresult($result);
		if ($registered) { message_die(GENERAL_ERROR, $lang['Attachment_shadow_changed']); }
	}
	foreach ($ids as $id)
	{
		attach_shadow_require_pm_idle($database, $id);
		$result = attach_shadow_query($database, 'SELECT attach_id, physical_filename, thumbnail FROM ' . ATTACHMENTS_DESC_TABLE . ' WHERE attach_id = ' . $id);
		$description = $database->sql_fetchrow($result); $database->sql_freeresult($result);
		$result = attach_shadow_query($database, 'SELECT post_id, privmsgs_id FROM ' . ATTACHMENTS_TABLE . ' WHERE attach_id = ' . $id);
		$references = $database->sql_fetchrowset($result); $database->sql_freeresult($result);
		if (!$description && !$references) { message_die(GENERAL_ERROR, $lang['Attachment_shadow_changed']); }
		if ($description)
		{
			$name = $description['physical_filename'];
			if (attach_pm_stage_is_claimed($database, $name)) { message_die(GENERAL_ERROR, $lang['Attachment_shadow_pending']); }
			if (attach_ftp_listing_entry($name, '0') === false || !attach_inventory_file($name)) { message_die(GENERAL_ERROR, $lang['Attachment_selection_invalid']); }
			if (in_array($name, $files, true) && entry_exists($id, $database)) { message_die(GENERAL_ERROR, $lang['Attachment_shadow_changed']); }
		}
		$descriptions[$id] = $description; $links[$id] = $references;
	}
	$thumbs = attach_storage_file_entries(MODE_THUMBNAIL, true);
	if ($thumbs === false) { message_die(GENERAL_ERROR, $lang['Attachment_listing_failed']); }
	$thumb_names = array(); foreach ($thumbs as $entry) { $thumb_names[] = $entry['name']; }

	// Reserve unregistered names before touching bytes. If the lock connection
	// dies during unlink, a stale posting form still cannot publish the file.
	foreach ($names as $name)
	{
		if (attach_pm_stage_is_claimed($database, $name)) { message_die(GENERAL_ERROR, $lang['Attachment_shadow_pending']); }
		$thumbnail = in_array('t_' . $name, $thumb_names, true) ? 1 : 0;
		$escaped = $database->sql_escape($name);
		attach_shadow_query($database, 'INSERT INTO ' . ATTACHMENTS_DESC_TABLE . " (physical_filename, real_filename, filesize, filetime, thumbnail) VALUES ('" . $escaped . "', '" . $escaped . "', 0, 0, " . $thumbnail . ')');
		$id = (int) $database->sql_nextid();
		$descriptions[$id] = array('attach_id'=>$id, 'physical_filename'=>$name, 'thumbnail'=>$thumbnail);
		$links[$id] = array();
	}
	$posts = array(); $messages = array(); $incomplete = false;
	foreach ($descriptions as $id => $description)
	{
		if ($description && attach_pm_stage_is_claimed($database, $description['physical_filename'])) { message_die(GENERAL_ERROR, $lang['Attachment_shadow_pending']); }
		attach_shadow_require_pm_idle($database, $id);
		// Remove stale link sources first, so PM copies cannot revive them after
		// loss of this session during subsequent filesystem operations.
		attach_shadow_query($database, 'DELETE FROM ' . ATTACHMENTS_TABLE . ' WHERE attach_id = ' . (int) $id);
		foreach ($links[$id] as $link)
		{
			if ((int) $link['post_id'] > 0) { $posts[(int) $link['post_id']] = (int) $link['post_id']; }
			if ((int) $link['privmsgs_id'] > 0) { $messages[(int) $link['privmsgs_id']] = (int) $link['privmsgs_id']; }
		}
		if (!$description) { continue; }
		$name = $description['physical_filename'];
		$result = attach_shadow_query($database, 'SELECT attach_id FROM ' . ATTACHMENTS_DESC_TABLE . " WHERE physical_filename = '" . $database->sql_escape($name) . "' AND attach_id <> " . (int) $id . ' LIMIT 1');
		$shared = $database->sql_numrows($result) > 0; $database->sql_freeresult($result);
		if (!$shared)
		{
			if ((int) $description['thumbnail'] === 1 || in_array('t_' . $name, $thumb_names, true))
			{
				if (!attach_delete_file($name, MODE_THUMBNAIL)) { $incomplete = true; continue; }
				attach_shadow_query($database, 'UPDATE ' . ATTACHMENTS_DESC_TABLE . ' SET thumbnail = 0 WHERE attach_id = ' . (int) $id);
			}
			if (!attach_delete_file($name)) { $incomplete = true; continue; }
		}
		attach_shadow_query($database, 'DELETE FROM ' . ATTACHMENTS_DESC_TABLE . ' WHERE attach_id = ' . (int) $id);
	}
	foreach ($posts as $id) { attach_sync_message($database, 'post', $id); }
	foreach ($messages as $id) { attach_sync_message($database, 'pm', $id); }
	if ($incomplete) { message_die(GENERAL_ERROR, $lang['Attachment_delete_incomplete']); }
}
