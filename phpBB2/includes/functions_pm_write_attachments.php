<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__DIR__) . '/attach_mod/includes/functions_pm_staging.php';

// PM uploads use a reserved server-generated namespace. Ordinary attachment
// uploads append a separate 12-hex suffix and cannot produce this shape from
// a user filename. The actor prefix binds temporary files to their uploader;
// the random 128-bit component prevents guessing and filename collisions.
function phpbb_pm_staged_attachment_name($filename, $actor)
{
	if (!is_string($filename) || (int)$actor <= 0
		|| !preg_match('/^pm_' . (int)$actor . '_[a-f0-9]{32}\.[a-z0-9]{1,20}$/D', $filename)) { phpbb_acl_error('PM_journal_changed'); }
	return $filename;
}

function phpbb_pm_staged_attachment_bytes($entry, $actor)
{
	global $upload_dir, $attach_config;
	$name = phpbb_pm_staged_attachment_name($entry['physical_filename'], $actor);
	if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== $entry['extension']) { phpbb_acl_error('PM_journal_changed'); }
	$size = false;
	if (empty($attach_config['allow_ftp_upload']))
	{
		$path = $upload_dir . '/' . $name;
		clearstatcache(true, $path);
		if (!is_link($path) && is_file($path)) { $size = @filesize($path); }
	}
	elseif (function_exists('ftp_size'))
	{
		$connection = attach_init_ftp(false, true);
		if ($connection !== false)
		{
			try { $size = @ftp_size($connection, $name); }
			finally { @ftp_close($connection); }
		}
	}
	// Hidden form metadata is not authoritative for quota accounting. A stale
	// or forged size/extension must not publish different metadata for these bytes.
	if (!is_int($size) || $size < 1 || $size !== $entry['filesize']) { phpbb_acl_error('PM_journal_changed'); }
}

// Prepared Attachment MOD values, never raw request arrays. Existing IDs only
// authorize a comment change on this message; new uploads get an operation slot.
function phpbb_pm_write_attachment_plan($items)
{
	if (!is_array($items) || count($items) > 1000) { phpbb_acl_error('PM_journal_changed'); }
	$result = array(); $ids = array(); $names = array();
	foreach ($items as $item)
	{
		if (!is_array($item) || !isset($item['id'], $item['comment']) || !is_string($item['comment'])
			|| !preg_match('//u', $item['comment']) || preg_match_all('/./us', $item['comment'], $unused) > 255) { phpbb_acl_error('PM_journal_changed'); }
		$id = $item['id'] === 0 ? 0 : phpbb_acl_id($item['id']);
		$entry = array('id'=>$id, 'comment'=>$item['comment']);
		if ($id)
		{
			if (isset($ids[$id])) { phpbb_acl_error('PM_journal_changed'); }
			$ids[$id] = true;
		}
		else
		{
			foreach (array('physical_filename'=>255,'real_filename'=>255,'extension'=>100,'mimetype'=>100) as $key=>$max)
			{
				if (!isset($item[$key]) || !is_string($item[$key]) || !preg_match('//u', $item[$key])
					|| preg_match_all('/./us', $item[$key], $unused) > $max || preg_match('/[\x00-\x1f\x7f]/', $item[$key])) { phpbb_acl_error('PM_journal_changed'); }
				$entry[$key] = $item[$key];
			}
			foreach (array('physical_filename','real_filename') as $key)
			{
				if ($entry[$key] === '' || strpos($entry[$key], '/') !== false || strpos($entry[$key], '\\') !== false
					|| $entry[$key] === '.' || $entry[$key] === '..') { phpbb_acl_error('PM_journal_changed'); }
			}
			if (isset($names[$entry['physical_filename']])) { phpbb_acl_error('PM_journal_changed'); }
			$names[$entry['physical_filename']] = true;
			foreach (array('filesize','filetime','thumbnail') as $key)
			{
				if (!isset($item[$key]) || !is_int($item[$key]) || $item[$key] < 0 || $item[$key] > ($key === 'thumbnail' ? 1 : 2147483647)) { phpbb_acl_error('PM_journal_changed'); }
				$entry[$key] = $item[$key];
			}
		}
		$result[] = $entry;
	}
	return $result;
}

function phpbb_pm_validate_new_attachment_plan($db, $message_id, $content)
{
	if (empty($content['attachments'])) { return; }
	foreach ($content['attachments'] as $entry)
	{
		if ($entry['id'] === 0)
		{
			phpbb_pm_staged_attachment_name($entry['physical_filename'], $db->actor);
			if (attach_pm_stage_is_claimed($db, $entry['physical_filename'])) { phpbb_acl_error('PM_write_pending'); }
			attach_require_unpublished_file($db, $entry['physical_filename']);
			phpbb_pm_staged_attachment_bytes($entry, $db->actor);
		}
		elseif (!$message_id || !phpbb_acl_rows($db, 'SELECT a.attach_id FROM ' . ATTACHMENTS_TABLE . ' a,' . ATTACHMENTS_DESC_TABLE
			. ' d WHERE a.attach_id = ' . $entry['id'] . ' AND d.attach_id = a.attach_id AND a.privmsgs_id = ' . (int)$message_id
			. ' AND a.post_id = 0 AND a.user_id_1 = ' . $db->actor)) { phpbb_acl_error('PM_journal_changed'); }
	}
}

function phpbb_pm_attachment_metadata($db, $entry, $alias = '')
{
	$conditions = array(); $prefix = $alias === '' ? '' : $alias . '.';
	foreach ($entry as $key=>$value)
	{
		if ($key === 'id') { continue; }
		$conditions[] = is_int($value) ? $prefix . $key . ' = ' . $value
			: 'HEX(' . $prefix . $key . ") = HEX('" . $db->sql_escape($value) . "')";
	}
	return implode(' AND ', $conditions);
}

function phpbb_pm_write_attachments($db, $row, $content, $source_guard)
{
	$id = (int)$row['privmsgs_id']; $recipient = (int)$content['recipient'];
	$plan = isset($content['attachments']) ? $content['attachments'] : array();
	$ready = array(); $nonce = phpbb_pm_write_nonce($row['privmsgs_write_token']);
	foreach ($plan as $slot=>$entry)
	{
		$reservation_ready = '';
		$attachment_id = $entry['id'];
		if (!$attachment_id)
		{
			phpbb_pm_staged_attachment_name($entry['physical_filename'], $db->actor);
			phpbb_pm_staged_attachment_bytes($entry, $db->actor);
			$reservation = "HEX(pm_write_token) = HEX('" . $nonce . "') AND pm_write_slot = " . $slot;
			$rows = phpbb_acl_rows($db, 'SELECT * FROM ' . ATTACHMENTS_DESC_TABLE . ' WHERE ' . $reservation);
			if (!$rows)
			{
				// A matching physical name is NOT ownership proof. Only the
				// reservation inserted atomically with this description is.
				attach_require_unpublished_file($db, $entry['physical_filename']);
				$columns = array('pm_write_token','pm_write_slot'); $values = array("'" . $nonce . "'", (int)$slot);
				foreach ($entry as $field=>$value)
				{
					if ($field === 'id') { continue; }
					$columns[] = $field; $values[] = is_int($value) ? $value : "'" . $db->sql_escape($value) . "'";
				}
				$db->insert_select(ATTACHMENTS_DESC_TABLE, implode(',', $columns), implode(',', $values), USERS_TABLE . ' writer',
					'writer.user_id = ' . $db->actor . ' AND ' . $source_guard
					. ' AND NOT EXISTS (SELECT 1 FROM (SELECT DISTINCT physical_filename,pm_write_token,pm_write_slot FROM ' . ATTACHMENTS_DESC_TABLE
					. ") reserved WHERE physical_filename = '" . $db->sql_escape($entry['physical_filename']) . "' OR (" . $reservation . '))');
				$rows = phpbb_acl_rows($db, 'SELECT * FROM ' . ATTACHMENTS_DESC_TABLE . ' WHERE ' . $reservation);
			}
			if (count($rows) !== 1) { phpbb_acl_error('PM_journal_changed'); }
			$attachment_id = (int)$rows[0]['attach_id'];
			$no_foreign_links = 'NOT EXISTS (SELECT 1 FROM (SELECT DISTINCT attach_id,post_id,privmsgs_id,user_id_1 FROM ' . ATTACHMENTS_TABLE
				. ') foreign_link WHERE attach_id = ' . $attachment_id . ' AND (post_id <> 0 OR privmsgs_id <> ' . $id . ' OR user_id_1 <> ' . $db->actor . '))';
			$reservation_ready = " AND HEX(d.pm_write_token) = HEX('" . $nonce . "') AND d.pm_write_slot = " . $slot . ' AND ' . $no_foreign_links;
			$metadata = phpbb_pm_attachment_metadata($db, $entry);
			if (!phpbb_acl_rows($db, 'SELECT attach_id FROM ' . ATTACHMENTS_DESC_TABLE . ' WHERE attach_id = ' . $attachment_id
				. ' AND ' . $reservation . ' AND ' . $metadata) || !attachment_exists($entry['physical_filename'])) { phpbb_acl_error('PM_journal_changed'); }
			// A restored/foreign registration must never be adopted on retry.
			if (phpbb_acl_rows($db, 'SELECT attach_id FROM ' . ATTACHMENTS_TABLE . ' WHERE attach_id = ' . $attachment_id
				. ' AND (post_id <> 0 OR privmsgs_id <> ' . $id . ' OR user_id_1 <> ' . $db->actor . ')')) { phpbb_acl_error('PM_journal_changed'); }
			$db->insert_select(ATTACHMENTS_TABLE, 'attach_id,post_id,privmsgs_id,user_id_1,user_id_2',
				$attachment_id . ',0,' . $id . ',' . $db->actor . ',' . $recipient, ATTACHMENTS_DESC_TABLE . ' d',
				'd.attach_id = ' . $attachment_id . ' AND ' . $source_guard . ' AND ' . $reservation . ' AND ' . phpbb_pm_attachment_metadata($db, $entry, 'd') . ' AND ' . $no_foreign_links
				. ' AND NOT EXISTS (SELECT 1 FROM (SELECT DISTINCT attach_id,privmsgs_id FROM ' . ATTACHMENTS_TABLE
				. ') linked WHERE linked.attach_id = ' . $attachment_id . ' AND linked.privmsgs_id = ' . $id . ')');
		}
		else
		{
			$attachment_id = phpbb_pm_write_attachment_comment($db,$id,$entry,$nonce,$slot,$source_guard,$reservation_ready);
		}
		$ready[] = 'EXISTS (SELECT 1 FROM ' . ATTACHMENTS_TABLE . ' a,' . ATTACHMENTS_DESC_TABLE . ' d WHERE a.attach_id = ' . $attachment_id
			. ' AND d.attach_id = a.attach_id AND a.privmsgs_id = ' . $id . ' AND a.post_id = 0 AND a.user_id_1 = ' . $db->actor
			. ' AND a.user_id_2 = ' . $recipient . ' AND ' . phpbb_pm_attachment_metadata($db, $entry, 'd') . $reservation_ready . ')';
	}
	// Preserve attachments not being changed, including their ownership after
	// the author changes the recipient of an undelivered message.
	$db->sql_query('UPDATE ' . ATTACHMENTS_TABLE . ' SET user_id_1 = ' . $db->actor . ',user_id_2 = ' . $recipient
		. ' WHERE privmsgs_id = ' . $id . ' AND post_id = 0 AND ' . $source_guard);
	$flag = 'CASE WHEN EXISTS (SELECT 1 FROM ' . ATTACHMENTS_TABLE . ' a,' . ATTACHMENTS_DESC_TABLE
		. ' d WHERE a.privmsgs_id = ' . $id . ' AND a.attach_id = d.attach_id) THEN 1 ELSE 0 END';
	$db->sql_query('UPDATE ' . PRIVMSGS_TABLE . ' SET privmsgs_attachment = ' . $flag . ' WHERE privmsgs_id = ' . $id . ' AND ' . $source_guard);
	return $ready ? '(' . implode(' AND ', $ready) . ')' : '1 = 1';
}

// Comment edits must not rewrite a shared description. The accepted write's
// existing nonce/slot is also the clone reservation, so interrupted link moves
// resume on the same clone, including after the obsolete description vanished.
function phpbb_pm_write_attachment_comment($db,$message,$entry,$nonce,$slot,$source_guard,&$reservation_ready)
{
	require_once dirname(__FILE__) . '/functions_pm_attachment_edit.php';
	$id=$entry['id']; $link='privmsgs_id = ' . (int)$message . ' AND post_id = 0 AND user_id_1 = ' . $db->actor;
	$reservation="HEX(pm_write_token) = HEX('" . $nonce . "') AND pm_write_slot = " . (int)$slot;
	$clones=phpbb_acl_rows($db,'SELECT * FROM ' . ATTACHMENTS_DESC_TABLE . ' WHERE ' . $reservation);
	$original=phpbb_acl_rows($db,'SELECT * FROM ' . ATTACHMENTS_DESC_TABLE . ' WHERE attach_id = ' . $id);
	$owned='EXISTS (SELECT 1 FROM ' . ATTACHMENTS_TABLE . ' a WHERE a.attach_id = ' . $id . ' AND ' . $link . ')';
	if (!$clones)
	{
		if (!$original || !phpbb_acl_rows($db,'SELECT 1 AS allowed WHERE ' . $owned . ' AND ' . $source_guard)) { phpbb_acl_error('PM_journal_changed'); }
		$old=$original[0];
		if ((string)$old['comment']===$entry['comment']) { return $id; }
		$columns='physical_filename,real_filename,extension,mimetype,filesize,filetime,thumbnail,download_count';
		$old_guard=phpbb_pm_attachment_snapshot($db,$old,'original');
		$db->insert_select(ATTACHMENTS_DESC_TABLE,$columns . ',comment,pm_write_token,pm_write_slot',
			'original.' . str_replace(',',',original.',$columns) . ",'" . $db->sql_escape($entry['comment']) . "','" . $nonce . "'," . (int)$slot,
			'(SELECT DISTINCT * FROM ' . ATTACHMENTS_DESC_TABLE . ' WHERE attach_id = ' . $id . ') original',
			$old_guard . ' AND ' . $owned . ' AND ' . $source_guard . ' AND NOT EXISTS (SELECT 1 FROM (SELECT DISTINCT pm_write_token,pm_write_slot FROM '
			. ATTACHMENTS_DESC_TABLE . ') reserved WHERE ' . $reservation . ')');
		$clones=phpbb_acl_rows($db,'SELECT * FROM ' . ATTACHMENTS_DESC_TABLE . ' WHERE ' . $reservation);
	}
	if (count($clones)!==1 || (string)$clones[0]['comment']!==$entry['comment']) { phpbb_acl_error('PM_journal_changed'); }
	$new=$clones[0]; $new_id=(int)$new['attach_id'];
	$foreign='NOT EXISTS (SELECT 1 FROM (SELECT DISTINCT attach_id,privmsgs_id,post_id,user_id_1 FROM ' . ATTACHMENTS_TABLE
		. ') links WHERE attach_id = ' . $new_id . ' AND NOT (' . $link . '))';
	$current='EXISTS (SELECT 1 FROM (SELECT DISTINCT attach_id,privmsgs_id,post_id,user_id_1 FROM ' . ATTACHMENTS_TABLE
		. ') links WHERE attach_id = ' . $new_id . ' AND ' . $link . ')';
	$new_guard='EXISTS (SELECT 1 FROM (SELECT DISTINCT * FROM ' . ATTACHMENTS_DESC_TABLE . ' WHERE attach_id = ' . $new_id . ') ready WHERE '
		. phpbb_pm_attachment_snapshot($db,$new,'ready') . ') AND ' . $foreign . ' AND ' . $source_guard;
	if (!phpbb_acl_rows($db,'SELECT 1 AS allowed WHERE ' . $current . ' AND ' . $new_guard))
	{
		if (!$original) { phpbb_acl_error('PM_journal_changed'); }
		$old=$original[0];
		foreach (array('physical_filename','real_filename','extension','mimetype','filesize','filetime','thumbnail') as $field)
		{
			if ((string)$old[$field] !== (string)$new[$field]) { phpbb_acl_error('PM_journal_changed'); }
		}
		$old_guard='EXISTS (SELECT 1 FROM ' . ATTACHMENTS_DESC_TABLE . ' original WHERE ' . phpbb_pm_attachment_snapshot($db,$old,'original') . ')';
		$db->sql_query('UPDATE ' . ATTACHMENTS_TABLE . ' SET attach_id = ' . $new_id . ' WHERE attach_id = ' . $id . ' AND ' . $link
			. ' AND ' . $old_guard . ' AND ' . $new_guard . ' AND NOT ' . $current);
		if ($db->sql_affectedrows()<1) { phpbb_acl_error('PM_journal_changed'); }
	}
	if ($original)
	{
		// Both descriptions name the same bytes. Remove only the obsolete
		// metadata, never the shared file or another message's registration.
		$db->sql_query('DELETE FROM ' . ATTACHMENTS_DESC_TABLE . ' WHERE ' . phpbb_pm_attachment_snapshot($db,$original[0])
			. ' AND NOT EXISTS (SELECT 1 FROM ' . ATTACHMENTS_TABLE . ' a WHERE a.attach_id = ' . $id . ') AND ' . $current . ' AND ' . $new_guard);
	}
	$reservation_ready=' AND ' . phpbb_pm_attachment_snapshot($db,$new,'d') . ' AND ' . $foreign;
	return $new_id;
}
