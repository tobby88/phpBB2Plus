<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_maintenance_dates.php';

// This adapter is private to the ACP user-repair workflow. Reads cannot hide
// mutations; every write must use sql_write with an explicit current-source
// WHERE predicate. All top-level OR conditions must be parenthesized there.
class PhpbbMaintenanceUserDatabase extends PhpbbAclDatabase
{
	var $affected = 0;
	var $insert_id = 0;
	function actor()
	{
		// Use an unwrapped observer so authority checks cannot recurse.
		return dbmtnc_date_actor(new PhpbbAclDatabase($this->connection, $this->failure_key));
	}
	function sql_query($sql, $transaction = false)
	{
		if (strpos($sql, 'SELECT ') !== 0) { phpbb_acl_error($this->failure_key); }
		$this->actor();
		$result = parent::sql_query($sql, $transaction);
		$allowed = false;
		try { $this->actor(); $allowed = true; }
		finally { if (!$allowed) { $this->connection->sql_freeresult($result); } }
		return $result;
	}
	function sql_write($sql)
	{
		if (!preg_match('/^(?:UPDATE |DELETE FROM |INSERT INTO )/', $sql)) { phpbb_acl_error($this->failure_key); }
		$actor = $this->actor();
		$result = parent::sql_query($sql . ' AND (' . $actor['guard'] . ')');
		// Subsequent authority SELECTs must not replace the write's row count or
		// generated group ID, particularly on older MySQL drivers.
		$this->affected = (int) $this->connection->sql_affectedrows();
		$this->insert_id = strpos($sql, 'INSERT INTO ') === 0 ? $this->connection->sql_nextid() : 0;
		$this->actor();
		return $result;
	}
	function sql_affectedrows() { return $this->affected; }
	function sql_nextid() { return $this->insert_id; }
}

function dbmtnc_user_begin(&$database, $request)
{
	global $userdata;
	if (!is_array($request) || !isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST'
		|| empty($userdata['session_id']) || !is_string($userdata['session_id'])
		|| !isset($request['sid']) || !is_string($request['sid']) || !hash_equals($userdata['session_id'], $request['sid']))
	{ phpbb_acl_error('Session_invalid'); }
	$lock = new attach_mutation_lock($database);
	if (!$lock->acquired) { phpbb_acl_error('Attachment_storage_busy'); }
	$ready = false;
	try
	{
		$protected = new PhpbbMaintenanceUserDatabase($lock->connection, 'Maintenance_user_failed');
		$protected->actor();
		$ready = true;
	}
	finally { if (!$ready) { $lock->release(); } }
	$scope = array($database, $lock);
	$database = $protected;
	return $scope;
}
function dbmtnc_user_end(&$database, $scope)
{
	$database = $scope[0];
	$scope[1]->release();
}
function dbmtnc_user_error($message)
{
	// Unlike the legacy renderer this unwinds the owning scope before exiting.
	throw new PhpbbAclException($message);
}
