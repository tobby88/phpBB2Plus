<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }

function attach_pm_stage_filename($filename)
{
	return is_string($filename) && preg_match('/^pm_[1-9][0-9]*_[a-f0-9]{32}\.[a-z0-9]{1,20}$/D', $filename) === 1;
}

function attach_require_post_temporary_file($filename)
{
	global $lang;
	if (is_string($filename) && attach_pm_stage_filename(basename($filename)))
	{
		message_die(GENERAL_ERROR, $lang['Attachment_private_upload']);
	}
}

// Call under the attachment mutation lock. An accepted write already owns its
// new files before their attachment descriptions exist. Filename ownership alone
// must not let an old form or orphan cleanup remove/reuse these pending uploads.
function attach_pm_stage_is_claimed($database, $filename)
{
	global $lang;
	if (!is_string($filename) || !preg_match('/^pm_([1-9][0-9]*)_[a-f0-9]{32}\.[a-z0-9]{1,20}$/D', $filename, $match)) { return false; }
	$actor = (int)$match[1];
	if ((string)$actor !== $match[1]) { return true; }
	$cursor = 0;
	do
	{
		$result = $database->sql_query('SELECT privmsgs_id,privmsgs_write_payload FROM ' . PRIVMSGS_TABLE
			. ' WHERE privmsgs_from_userid = ' . $actor . ' AND privmsgs_write_payload IS NOT NULL AND privmsgs_id > ' . $cursor
			. ' ORDER BY privmsgs_id LIMIT 100');
		if (!$result)
		{
			message_die(GENERAL_ERROR, isset($lang['PM_cleanup_failed']) ? $lang['PM_cleanup_failed'] : $lang['Attachment_publish_unavailable']);
		}
		$rows = $database->sql_fetchrowset($result); $database->sql_freeresult($result);
		foreach ($rows as $row)
		{
			if ((int)$row['privmsgs_id'] <= $cursor) { return true; }
			$cursor = (int)$row['privmsgs_id'];
			$intent = json_decode($row['privmsgs_write_payload'], true);
			// Corrupt/unknown pending state is not evidence that the uploader's
			// files are abandoned. Preserve them for recovery or administrator review.
			if (!is_array($intent) || !isset($intent['version'],$intent['content']) || $intent['version'] !== 1 || !is_array($intent['content'])) { return true; }
			if (!isset($intent['content']['attachments'])) { continue; }
			if (!is_array($intent['content']['attachments'])) { return true; }
			foreach ($intent['content']['attachments'] as $entry)
			{
				if (!is_array($entry) || !isset($entry['id']) || !is_int($entry['id']) || $entry['id'] < 0) { return true; }
				if ($entry['id'] !== 0) { continue; }
				if (!isset($entry['physical_filename']) || !is_string($entry['physical_filename'])) { return true; }
				if ($entry['physical_filename'] === $filename) { return true; }
			}
		}
	} while (count($rows) === 100);
	return false;
}
