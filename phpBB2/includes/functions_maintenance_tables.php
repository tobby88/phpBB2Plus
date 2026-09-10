<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_maintenance_dates.php';

// ACP only. The shared report helper also serves the separately authenticated
// emergency console, which must remain usable when normal login tables fail.
class PhpbbMaintenanceTableDatabase extends PhpbbAclDatabase
{
	function sql_query($sql, $transaction = false)
	{
		$table_command = preg_match('/^(?:CHECK|REPAIR|OPTIMIZE) TABLE /D', $sql) === 1;
		if ($table_command) { dbmtnc_date_actor($this); }
		$result = parent::sql_query($sql, $transaction);
		if ($table_command)
		{
			// Administrative table statements have no WHERE authorization guard.
			// Revocation cannot undo a command already submitted to the server;
			// it does stop reporting success and proceeding to another table.
			$authorized = false;
			try { dbmtnc_date_actor($this); $authorized = true; }
			finally { if (!$authorized) { $this->sql_freeresult($result); } }
		}
		return $result;
	}
}

function dbmtnc_table_begin(&$database, $request)
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
		$protected = new PhpbbMaintenanceTableDatabase($lock->connection, 'Maintenance_query_failed');
		dbmtnc_date_actor($protected);
		$ready = true;
	}
	finally { if (!$ready) { $lock->release(); } }
	$scope = array($database, $lock);
	$database = $protected;
	return $scope;
}

function dbmtnc_table_end(&$database, $scope)
{
	$database = $scope[0];
	$scope[1]->release();
}
