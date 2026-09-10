<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_pm_write.php';

// This capability exists only for the duration of the attachment parser and
// uses the same owning connection as message read/write and attachment cleanup.
class PhpbbPmComposeDatabase extends PhpbbPmWriteDatabase
{
	var $message_id = 0;
	var $writable = false;
	function __construct($connection, $mode, $message_id)
	{
		$this->message_id = phpbb_pm_compose_attachment_source(new PhpbbAclDatabase($connection, 'PM_cleanup_failed'), $mode, $message_id);
		$this->writable = isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST';
		parent::__construct($connection);
	}
	function authority()
	{
		if ($this->writable) { phpbb_mailbox_post_request(); }
		$guard = parent::authority();
		if ($this->message_id)
		{
			$guard .= ' AND EXISTS (SELECT 1 FROM (SELECT DISTINCT privmsgs_id FROM ' . PRIVMSGS_TABLE
				. ' WHERE privmsgs_id = ' . $this->message_id . ' AND (' . phpbb_pm_mailbox_condition($this->actor, 'outbox')
				. ') AND privmsgs_write_payload IS NULL) compose_source)';
		}
		$check = new PhpbbAclDatabase($this->connection, 'PM_cleanup_failed');
		if (!phpbb_acl_rows($check, 'SELECT 1 AS allowed WHERE ' . $guard)) { phpbb_acl_error('Not_Authorised'); }
		return $guard;
	}
	function require_write()
	{
		if (!$this->writable) { phpbb_acl_error('Session_invalid'); }
		return $this->authority();
	}
	function sql_query($sql, $transaction = false)
	{
		if (!preg_match('/^\s*SELECT\b/i', $sql)) { $this->require_write(); }
		return parent::sql_query($sql, $transaction);
	}
	function insert_select($table, $columns, $select, $from, $where)
	{
		$this->require_write();
		return parent::insert_select($table, $columns, $select, $from, $where);
	}
}
