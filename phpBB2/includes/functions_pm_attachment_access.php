<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_acl_storage.php';

// A single autocommit SELECT gets a current authorization snapshot without
// joining a caller transaction or holding the attachment writer's mutex.
class PhpbbPmAttachmentReader
{
	var $connection = null;
	function __construct($database)
	{
		if (!is_object($database) || !method_exists($database, 'sql_dedicated_connection')) { phpbb_acl_error('PM_cleanup_failed'); }
		$connection = $database->sql_dedicated_connection();
		if (!is_object($connection) || empty($connection->db_connect_id)) { phpbb_acl_error('PM_cleanup_failed'); }
		if ($connection === $database || (isset($database->db_connect_id)
			&& (is_object($database->db_connect_id) || is_resource($database->db_connect_id))
			&& $connection->db_connect_id === $database->db_connect_id)) { phpbb_acl_error('PM_cleanup_failed'); }
		$this->connection = $connection;
		try {
			$state = $this->rows('SELECT @@SESSION.autocommit AS pm_autocommit,@@SESSION.in_transaction AS pm_transaction');
			if (count($state) !== 1 || (int)$state[0]['pm_autocommit'] !== 1 || (int)$state[0]['pm_transaction'] !== 0) {
				// An adapter returning somebody else's active transaction must not
				// have that work committed, rolled back or closed by this reader.
				$this->connection = null; phpbb_acl_error('PM_cleanup_failed');
			}
		} catch (Exception $error) { $this->release(); throw $error; }
		catch (Error $error) { $this->release(); throw $error; }
		register_shutdown_function(array($this, 'release'));
	}
	function rows($sql)
	{
		if ($this->connection === null || !is_string($sql) || strpos($sql, 'SELECT ') !== 0) { phpbb_acl_error('PM_cleanup_failed'); }
		$result = $this->connection->sql_query($sql);
		if (!$result) { phpbb_acl_error('PM_cleanup_failed'); }
		try { return $this->connection->sql_fetchrowset($result); }
		finally { $this->connection->sql_freeresult($result); }
	}
	function release()
	{
		$connection = $this->connection; $this->connection = null;
		if ($connection !== null) {
			try { $connection->sql_close(); } catch (Exception $ignored) {} catch (Error $ignored) {}
		}
	}
}

function phpbb_pm_attachment_context($database, $message_id, $viewer, $allow_pm_attach, $attachment_id = 0)
{
	global $lang;
	$message_ids = attach_delete_id_array(array($message_id));
	$viewer_ids = is_array($viewer) && isset($viewer['user_id']) ? attach_delete_id_array(array($viewer['user_id'])) : false;
	if (!$message_ids || !$viewer_ids || empty($viewer['session_logged_in']) || !isset($viewer['session_id'])
		|| !is_string($viewer['session_id']) || $viewer['session_id'] === '' || strlen($viewer['session_id']) > 32) { return false; }
	$attachments = $attachment_id === 0 ? array(0) : attach_delete_id_array(array($attachment_id));
	if (!$attachments) { return false; }
	$owner = null;
	try {
		$owner = new PhpbbPmAttachmentReader($database);
		$sid = $owner->connection->sql_escape($viewer['session_id']);
		$mailboxes = array();
		foreach (array('inbox','outbox','sentbox','savebox') as $folder) {
			$mailboxes[] = '(' . str_replace('privmsgs_', 'p.privmsgs_', phpbb_pm_mailbox_condition($viewer_ids[0], $folder)) . ')';
		}
		$sql = 'SELECT ' . ($attachments[0] ? 'd.*,' : 'p.privmsgs_id,') . 'u.user_level AS pm_download_level FROM ' . PRIVMSGS_TABLE . ' p'
			. ' JOIN ' . USERS_TABLE . ' u ON u.user_id=' . $viewer_ids[0] . ' AND u.user_active<>0'
			. ' JOIN ' . SESSIONS_TABLE . ' s ON s.session_user_id=u.user_id AND s.session_logged_in=1'
			. " AND s.session_id='" . $sid . "' AND HEX(s.session_id)=HEX('" . $sid . "')";
		if ($attachments[0]) {
			$sql .= ' JOIN ' . ATTACHMENTS_TABLE . ' a ON a.privmsgs_id=p.privmsgs_id AND a.post_id=0 AND a.attach_id=' . $attachments[0]
				. ' JOIN ' . ATTACHMENTS_DESC_TABLE . ' d ON d.attach_id=a.attach_id';
		}
		$sql .= ' WHERE p.privmsgs_id=' . $message_ids[0] . ' AND p.privmsgs_write_payload IS NULL AND p.privmsgs_type IN ('
			. PRIVMSGS_READ_MAIL . ',' . PRIVMSGS_NEW_MAIL . ',' . PRIVMSGS_SENT_MAIL . ',' . PRIVMSGS_SAVED_IN_MAIL . ',' . PRIVMSGS_SAVED_OUT_MAIL . ',' . PRIVMSGS_UNREAD_MAIL . ')'
			. ' AND (u.user_level=' . (int)ADMIN . ' OR (' . ($allow_pm_attach ? '1' : '0') . '=1 AND EXISTS (SELECT 1 FROM '
			. ATTACH_CONFIG_TABLE . " c WHERE c.config_name='allow_pm_attach' AND c.config_value='1') AND (" . implode(' OR ', $mailboxes) . '))) LIMIT 1';
		$rows = $owner->rows($sql);
		return $rows ? $rows[0] : false;
	} catch (Exception $error) { message_die(GENERAL_ERROR, $lang['PM_cleanup_failed']); }
	catch (Error $error) { message_die(GENERAL_ERROR, $lang['PM_cleanup_failed']); }
	finally { if ($owner !== null) { $owner->release(); } }
}

// Keep the parent-only API for other callers; file delivery always binds the
// specific, currently linked attachment and uses its fresh metadata instead.
function phpbb_pm_attachment_access($database, $message_id, $viewer, $allow_pm_attach)
{
	return (bool)phpbb_pm_attachment_context($database, $message_id, $viewer, $allow_pm_attach);
}
function phpbb_pm_download_attachment($database, $message_id, $viewer, $allow_pm_attach, $attachment_id)
{
	$ids = attach_delete_id_array(array($attachment_id));
	return $ids ? phpbb_pm_attachment_context($database, $message_id, $viewer, $allow_pm_attach, $ids[0]) : false;
}
