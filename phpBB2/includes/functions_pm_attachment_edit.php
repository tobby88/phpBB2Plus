<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_pm_compose_attachments.php';

function phpbb_pm_attachment_snapshot($db, $row, $alias = '')
{
	$prefix = $alias === '' ? '' : $alias . '.';
	$terms = array($prefix . 'attach_id = ' . (int)$row['attach_id']);
	foreach (array('physical_filename','real_filename','comment','extension','mimetype','filesize','filetime','thumbnail','download_count','pm_write_token','pm_write_slot') as $field)
	{
		$terms[] = 'HEX(COALESCE(' . $prefix . $field . ",'')) = HEX('" . $db->sql_escape((string)$row[$field]) . "')";
	}
	return implode(' AND ', $terms);
}

// The caller owns the compose connection. Publish a new description before an
// atomic move of only this message's link; never edit another copy's metadata.
// A failed pre-switch write preserves the original. A lost switch response may
// leave the replacement applied; reloading shows current state, not a rollback.
function phpbb_pm_edit_attachment($db, $attachment_id, $changes, $new_upload = false)
{
	if (!($db instanceof PhpbbPmComposeDatabase) || !$db->message_id) { phpbb_acl_error('Not_Authorised'); }
	$db->require_write(); $id = phpbb_acl_id($attachment_id); $message = $db->message_id;
	$link = 'privmsgs_id = ' . $message . ' AND post_id = 0 AND user_id_1 = ' . $db->actor;
	$owned = 'EXISTS (SELECT 1 FROM ' . ATTACHMENTS_TABLE . ' a WHERE a.attach_id = ' . $id . ' AND ' . $link . ')';
	$rows = phpbb_acl_rows($db, 'SELECT * FROM ' . ATTACHMENTS_DESC_TABLE . ' WHERE attach_id = ' . $id . ' AND ' . $owned);
	if (count($rows) !== 1 || !is_array($changes)) { phpbb_acl_error('PM_journal_changed'); }
	$old = $rows[0]; $entry = array('id'=>0);
	foreach (array('physical_filename','real_filename','comment','extension','mimetype') as $field) { $entry[$field] = (string)$old[$field]; }
	foreach (array('filesize','filetime','thumbnail') as $field) { $entry[$field] = (int)$old[$field]; }
	foreach ($changes as $field=>$value)
	{
		if (!array_key_exists($field,$entry) || $field === 'id' || (!$new_upload && !in_array($field,array('comment','thumbnail'),true))) { phpbb_acl_error('PM_journal_changed'); }
		$entry[$field] = $value;
	}
	$plan = phpbb_pm_write_attachment_plan(array($entry)); $entry = $plan[0];
	if ($new_upload)
	{
		phpbb_pm_staged_attachment_bytes($entry,$db->actor);
		if (attach_pm_stage_is_claimed($db,$entry['physical_filename'])) { phpbb_acl_error('PM_write_pending'); }
		attach_require_unpublished_file($db,$entry['physical_filename']);
	}
	$old_guard = 'EXISTS (SELECT 1 FROM (SELECT DISTINCT * FROM ' . ATTACHMENTS_DESC_TABLE . ' WHERE attach_id = ' . $id . ') original WHERE '
		. phpbb_pm_attachment_snapshot($db,$old,'original') . ')';
	$nonce = bin2hex(phpbb_random_bytes(16));
	$columns = array('pm_write_token','pm_write_slot','download_count');
	$values = array("'" . $nonce . "'",0,(int)$old['download_count']);
	foreach ($entry as $field=>$value)
	{
		if ($field === 'id') { continue; }
		$columns[] = $field; $values[] = is_int($value) ? $value : "'" . $db->sql_escape($value) . "'";
	}
	$unused = $new_upload ? ' AND NOT EXISTS (SELECT 1 FROM (SELECT DISTINCT physical_filename FROM ' . ATTACHMENTS_DESC_TABLE
		. ") names WHERE physical_filename = '" . $db->sql_escape($entry['physical_filename']) . "')" : '';
	$db->insert_select(ATTACHMENTS_DESC_TABLE,implode(',',$columns),implode(',',$values),USERS_TABLE . ' editor',
		'editor.user_id = ' . $db->actor . ' AND ' . $owned . ' AND ' . $old_guard . $unused);
	$rows = phpbb_acl_rows($db,'SELECT * FROM ' . ATTACHMENTS_DESC_TABLE . " WHERE pm_write_token = '" . $nonce . "' AND pm_write_slot = 0");
	if (count($rows) !== 1) { phpbb_acl_error('PM_journal_changed'); }
	$new = $rows[0]; $new_id = (int)$new['attach_id'];
	$new_guard = 'EXISTS (SELECT 1 FROM (SELECT DISTINCT * FROM ' . ATTACHMENTS_DESC_TABLE . ' WHERE attach_id = ' . $new_id . ') ready WHERE ' . phpbb_pm_attachment_snapshot($db,$new,'ready')
		. ' AND ' . phpbb_pm_attachment_metadata($db,$entry,'ready') . ')';
	$private = 'NOT EXISTS (SELECT 1 FROM (SELECT DISTINCT attach_id FROM ' . ATTACHMENTS_TABLE . ') links WHERE attach_id = ' . $new_id . ')';
	// Authority, original metadata, prepared replacement and link ownership are
	// all requalified in the switching statement, not merely in earlier reads.
	$db->sql_query('UPDATE ' . ATTACHMENTS_TABLE . ' SET attach_id = ' . $new_id . ' WHERE attach_id = ' . $id . ' AND ' . $link
		. ' AND ' . $old_guard . ' AND ' . $new_guard . ' AND ' . $private);
	if ($db->sql_affectedrows() < 1) { phpbb_acl_error('PM_journal_changed'); }
	$current = 'EXISTS (SELECT 1 FROM ' . ATTACHMENTS_TABLE . ' a WHERE a.attach_id = ' . $new_id . ' AND ' . $link . ') AND ' . $new_guard;
	phpbb_pm_cleanup_replaced_attachment($db,$old,$current);
	return $new_id;
}

// Cleanup is scoped to the captured, now-detached description. If cleanup is
// interrupted the orphan description retains the filename for the existing ACP
// orphan cleanup; neither another reference nor its bytes are removed.
function phpbb_pm_cleanup_replaced_attachment($db, $old, $current)
{
	$id = (int)$old['attach_id'];
	$unlinked = 'NOT EXISTS (SELECT 1 FROM ' . ATTACHMENTS_TABLE . ' a WHERE a.attach_id = ' . $id . ')';
	$guard = phpbb_pm_attachment_snapshot($db,$old) . ' AND ' . $unlinked . ' AND ' . $current;
	if (!phpbb_acl_rows($db,'SELECT attach_id FROM ' . ATTACHMENTS_DESC_TABLE . ' WHERE ' . $guard)) { return; }
	$shared = phpbb_acl_rows($db,'SELECT attach_id FROM ' . ATTACHMENTS_DESC_TABLE . " WHERE physical_filename = '"
		. $db->sql_escape($old['physical_filename']) . "' AND attach_id <> " . $id . ' LIMIT 1');
	if (!$shared)
	{
		$db->require_write();
		if (!phpbb_acl_rows($db,'SELECT attach_id FROM ' . ATTACHMENTS_DESC_TABLE . ' WHERE ' . $guard)) { phpbb_acl_error('PM_journal_changed'); }
		if ((int)$old['thumbnail'] === 1)
		{
			if (!attach_delete_file($old['physical_filename'],MODE_THUMBNAIL)) { phpbb_acl_error('Attachment_delete_incomplete'); }
			$db->sql_query('UPDATE ' . ATTACHMENTS_DESC_TABLE . ' SET thumbnail = 0 WHERE ' . $guard);
			if ($db->sql_affectedrows() !== 1) { phpbb_acl_error('PM_journal_changed'); }
			$old['thumbnail']=0;
			$guard=phpbb_pm_attachment_snapshot($db,$old) . ' AND ' . $unlinked . ' AND ' . $current;
		}
		$db->require_write();
		if (!phpbb_acl_rows($db,'SELECT attach_id FROM ' . ATTACHMENTS_DESC_TABLE . ' WHERE ' . $guard)) { phpbb_acl_error('PM_journal_changed'); }
		if (!attach_delete_file($old['physical_filename'])) { phpbb_acl_error('Attachment_delete_incomplete'); }
	}
	$db->sql_query('DELETE FROM ' . ATTACHMENTS_DESC_TABLE . ' WHERE ' . $guard);
}
