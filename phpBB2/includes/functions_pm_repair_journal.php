<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }

function dbmtnc_pm_journal_ready($db)
{
	if (!defined('PM_REPAIR_JOBS_TABLE') || !defined('PM_REPAIR_ITEMS_TABLE')) { phpbb_acl_error('Maintenance_pm_journal_unavailable'); }
	$auth = new PhpbbAclDatabase($db->connection, 'Maintenance_pm_journal_unavailable');
	phpbb_acl_actor($auth, 'maintenance');
	foreach (array(PM_REPAIR_JOBS_TABLE, PM_REPAIR_ITEMS_TABLE) as $table)
	{
		$result = $auth->sql_query('SELECT job_id FROM ' . $table . ' LIMIT 1');
		$auth->sql_freeresult($result);
	}
	phpbb_acl_actor($auth, 'maintenance');
}

function dbmtnc_pm_job_key($job)
{
	if (!is_array($job) || !isset($job['job_id'],$job['repair_mode'],$job['repair_state']) || !is_string($job['job_id']) || !preg_match('/^[a-f0-9]{32}$/D', $job['job_id'])
		|| !in_array($job['repair_mode'], array('missing_text','deleted_users','abandoned_copy'), true)
		|| !in_array($job['repair_state'], array('planning','prepared'), true)) { phpbb_acl_error('Maintenance_pm_journal_changed'); }
	foreach (array('message_id','from_user_id','to_user_id','message_type','message_date','cutoff') as $field)
	{
		if (!isset($job[$field]) || !(is_int($job[$field]) || is_string($job[$field])) || !preg_match('/^-?[0-9]+$/D',(string)$job[$field])) { phpbb_acl_error('Maintenance_pm_journal_changed'); }
	}
	if ((int)$job['message_id'] <= 0 || (int)$job['message_date'] < 0 || (int)$job['cutoff'] < 0) { phpbb_acl_error('Maintenance_pm_journal_changed'); }
	return "job_id = '" . $job['job_id'] . "'";
}

function dbmtnc_pm_job_guard($job, $state = 'prepared')
{
	return 'EXISTS (SELECT 1 FROM ' . PM_REPAIR_JOBS_TABLE . ' pm_job WHERE ' . dbmtnc_pm_job_identity($job, $state) . ')';
}

function dbmtnc_pm_job_identity($job, $state)
{
	return dbmtnc_pm_job_key($job) . ' AND message_id = ' . (int)$job['message_id']
		. " AND HEX(repair_mode) = HEX('" . $job['repair_mode'] . "') AND HEX(repair_state) = HEX('" . $state . "')"
		. ' AND from_user_id = ' . (int)$job['from_user_id'] . ' AND to_user_id = ' . (int)$job['to_user_id']
		. ' AND message_type = ' . (int)$job['message_type'] . ' AND message_date = ' . (int)$job['message_date']
		. ' AND cutoff = ' . (int)$job['cutoff'];
}

function dbmtnc_pm_job_parent($job)
{
	return 'privmsgs_id = ' . (int)$job['message_id'] . ' AND privmsgs_from_userid = ' . (int)$job['from_user_id']
		. ' AND privmsgs_to_userid = ' . (int)$job['to_user_id'] . ' AND privmsgs_type = ' . (int)$job['message_type']
		. ' AND privmsgs_date = ' . (int)$job['message_date'];
}

function dbmtnc_pm_job_missing($job)
{
	return 'NOT EXISTS (SELECT 1 FROM ' . PRIVMSGS_TABLE . ' pm_current WHERE pm_current.privmsgs_id = ' . (int)$job['message_id'] . ')';
}

function dbmtnc_pm_job_assert($db, $job, $condition)
{
	if (!phpbb_acl_rows($db, 'SELECT 1 AS allowed WHERE ' . dbmtnc_pm_job_guard($job) . ' AND (' . $condition . ')'))
	{
		phpbb_acl_error('Maintenance_pm_journal_changed');
	}
}

function dbmtnc_pm_job_finish($db, $job, $cancelled = false)
{
	$key = dbmtnc_pm_job_key($job);
	$guard = dbmtnc_pm_job_guard($job, $job['repair_state']);
	if (!$cancelled) { $guard .= ' AND ' . dbmtnc_pm_job_missing($job); dbmtnc_pm_job_assert($db, $job, dbmtnc_pm_job_missing($job)); }
	$db->sql_query('DELETE FROM ' . PM_REPAIR_ITEMS_TABLE . ' WHERE ' . $key . ' AND ' . $guard);
	// The journal row itself is the target; do not self-select its table in
	// this DELETE (MySQL-compatible and generation-qualified).
	$db->sql_query('DELETE FROM ' . PM_REPAIR_JOBS_TABLE . ' WHERE ' . dbmtnc_pm_job_identity($job, $job['repair_state'])
		. ($cancelled ? '' : ' AND ' . dbmtnc_pm_job_missing($job)));
	if ((int)$db->sql_affectedrows() !== 1) { phpbb_acl_error('Maintenance_pm_journal_changed'); }
	if ($cancelled) { $db->pm_repair_cancelled++; }
}

function dbmtnc_pm_job_inventory($db, $job, $spec)
{
	$key = dbmtnc_pm_job_key($job); $id = (int)$job['message_id'];
	$parent = 'EXISTS (SELECT 1 FROM ' . PRIVMSGS_TABLE . ' WHERE ' . dbmtnc_pm_job_parent($job) . ' AND (' . $spec['where'] . '))';
	$planning = dbmtnc_pm_job_guard($job, 'planning') . ' AND ' . $parent;
	// Planning has not deleted any source data. An interrupted inventory can
	// therefore be rebuilt from the still-current parent and its references.
	$db->sql_query('DELETE FROM ' . PM_REPAIR_ITEMS_TABLE . ' WHERE ' . $key . ' AND ' . $planning);
	$cursor = 0;
	while (true)
	{
		$rows = phpbb_acl_rows($db, 'SELECT DISTINCT d.attach_id,d.physical_filename FROM ' . ATTACHMENTS_DESC_TABLE . ' d,' . ATTACHMENTS_TABLE
			. ' a WHERE a.privmsgs_id = ' . $id . ' AND a.attach_id = d.attach_id AND d.attach_id > ' . $cursor . ' ORDER BY d.attach_id LIMIT 100');
		if (!$rows) { break; }
		foreach ($rows as $item)
		{
			$cursor = (int)$item['attach_id']; $name = $db->sql_escape($item['physical_filename']);
			$current = 'EXISTS (SELECT 1 FROM ' . ATTACHMENTS_TABLE . ' a,' . ATTACHMENTS_DESC_TABLE . ' d WHERE a.privmsgs_id = ' . $id
				. ' AND a.attach_id = ' . $cursor . " AND d.attach_id = a.attach_id AND HEX(d.physical_filename) = HEX('" . $name . "'))";
			$db->insert_guarded(PM_REPAIR_ITEMS_TABLE, 'job_id,attach_id,physical_filename', "'" . $job['job_id'] . "'," . $cursor . ",'" . $name . "'", $planning . ' AND ' . $current);
		}
	}
	$complete = dbmtnc_pm_inventory_complete($job);
	$db->sql_query('UPDATE ' . PM_REPAIR_JOBS_TABLE . " SET repair_state = 'prepared' WHERE " . dbmtnc_pm_job_identity($job, 'planning') . ' AND ' . $parent . ' AND ' . $complete);
	if ((int)$db->sql_affectedrows() !== 1) { phpbb_acl_error('Maintenance_pm_journal_changed'); }
	$job['repair_state'] = 'prepared'; return $job;
}

function dbmtnc_pm_inventory_complete($job)
{
	return 'NOT EXISTS (SELECT 1 FROM ' . ATTACHMENTS_TABLE . ' a,' . ATTACHMENTS_DESC_TABLE . ' d WHERE a.privmsgs_id = ' . (int)$job['message_id']
		. ' AND d.attach_id = a.attach_id AND NOT EXISTS (SELECT 1 FROM ' . PM_REPAIR_ITEMS_TABLE . " i WHERE i.job_id = '" . $job['job_id']
		. "' AND i.attach_id = d.attach_id AND HEX(i.physical_filename) = HEX(d.physical_filename)))";
}

function dbmtnc_pm_job_attachment($db, $job, $item)
{
	$id = (int)$item['attach_id']; $name = $db->sql_escape($item['physical_filename']);
	$missing = dbmtnc_pm_job_missing($job);
	$item_guard = "EXISTS (SELECT 1 FROM " . PM_REPAIR_ITEMS_TABLE . " i WHERE i.job_id = '" . $job['job_id'] . "' AND i.attach_id = " . $id . " AND HEX(i.physical_filename) = HEX('" . $name . "'))";
	$missing .= ' AND ' . $item_guard;
	dbmtnc_pm_job_assert($db, $job, $missing);
	$rows = phpbb_acl_rows($db, 'SELECT attach_id,physical_filename,thumbnail FROM ' . ATTACHMENTS_DESC_TABLE . ' WHERE attach_id = ' . $id);
	if (!$rows) { return false; }
	if ($rows[0]['physical_filename'] !== $item['physical_filename']) { return true; }
	$unreferenced = 'NOT EXISTS (SELECT 1 FROM ' . ATTACHMENTS_TABLE . ' a WHERE a.attach_id = ' . $id . ')';
	if (!phpbb_acl_rows($db, 'SELECT 1 AS allowed WHERE ' . $unreferenced)) { return false; }
	$same = "EXISTS (SELECT 1 FROM " . ATTACHMENTS_DESC_TABLE . ' d WHERE d.attach_id = ' . $id . " AND HEX(d.physical_filename) = HEX('" . $name . "'))";
	$shared = phpbb_acl_rows($db, 'SELECT attach_id FROM ' . ATTACHMENTS_DESC_TABLE . " WHERE HEX(physical_filename) = HEX('" . $name . "') AND attach_id <> " . $id . ' LIMIT 1');
	if (!$shared)
	{
		$unique = "NOT EXISTS (SELECT 1 FROM " . ATTACHMENTS_DESC_TABLE . " other_file WHERE HEX(other_file.physical_filename) = HEX('" . $name . "') AND other_file.attach_id <> " . $id . ')';
		$bytes = $missing . ' AND ' . $unreferenced . ' AND ' . $same . ' AND ' . $unique;
		dbmtnc_pm_job_assert($db, $job, $bytes);
		if ((int)$rows[0]['thumbnail'] === 1)
		{
			if (!attach_delete_file($item['physical_filename'], MODE_THUMBNAIL)) { phpbb_acl_error('Maintenance_pm_repair_failed'); }
			$db->sql_query('UPDATE ' . ATTACHMENTS_DESC_TABLE . ' SET thumbnail = 0 WHERE attach_id = ' . $id . " AND HEX(physical_filename) = HEX('" . $name . "') AND " . $unreferenced . ' AND ' . $missing . ' AND ' . dbmtnc_pm_job_guard($job));
		}
		dbmtnc_pm_job_assert($db, $job, $bytes);
		if (!attach_delete_file($item['physical_filename'])) { phpbb_acl_error('Maintenance_pm_repair_failed'); }
	}
	$db->sql_query('DELETE FROM ' . ATTACHMENTS_DESC_TABLE . ' WHERE attach_id = ' . $id . " AND HEX(physical_filename) = HEX('" . $name . "') AND " . $unreferenced . ' AND ' . $missing . ' AND ' . dbmtnc_pm_job_guard($job));
	dbmtnc_pm_job_assert($db, $job, $missing);
	return false;
}

function dbmtnc_pm_job_run($db, $job)
{
	$key = dbmtnc_pm_job_key($job); $id = (int)$job['message_id'];
	$spec = phpbb_pm_repair_spec($job['repair_mode'], (int)$job['cutoff'] + 300);
	$parents = phpbb_acl_rows($db, 'SELECT privmsgs_id FROM ' . PRIVMSGS_TABLE . ' WHERE privmsgs_id = ' . $id);
	if ($parents)
	{
		$current = phpbb_acl_rows($db, 'SELECT privmsgs_id FROM ' . PRIVMSGS_TABLE . ' WHERE ' . dbmtnc_pm_job_parent($job) . ' AND (' . $spec['where'] . ')');
		if (!$current) { dbmtnc_pm_job_finish($db, $job, true); return 0; }
	}
	elseif ($job['repair_state'] === 'planning') { dbmtnc_pm_job_finish($db, $job, true); return 0; }
	if ($job['repair_state'] === 'planning') { $job = dbmtnc_pm_job_inventory($db, $job, $spec); }
	$db->sql_query('DELETE FROM ' . PRIVMSGS_TABLE . ' WHERE ' . dbmtnc_pm_job_parent($job) . ' AND (' . $spec['where'] . ') AND ' . dbmtnc_pm_job_guard($job) . ' AND ' . dbmtnc_pm_inventory_complete($job));
	$removed = (int)$db->sql_affectedrows();
	if (phpbb_acl_rows($db, 'SELECT privmsgs_id FROM ' . PRIVMSGS_TABLE . ' WHERE privmsgs_id = ' . $id)) { dbmtnc_pm_job_finish($db, $job, true); return 0; }
	$missing = dbmtnc_pm_job_missing($job); $guard = $missing . ' AND ' . dbmtnc_pm_job_guard($job);
	$db->sql_query('DELETE FROM ' . PRIVMSGS_TEXT_TABLE . ' WHERE privmsgs_text_id = ' . $id . ' AND ' . $guard);
	$inventoried = '(EXISTS (SELECT 1 FROM ' . PM_REPAIR_ITEMS_TABLE . " i WHERE i.job_id = '" . $job['job_id'] . "' AND i.attach_id = " . ATTACHMENTS_TABLE . '.attach_id) OR NOT EXISTS (SELECT 1 FROM ' . ATTACHMENTS_DESC_TABLE . ' d WHERE d.attach_id = ' . ATTACHMENTS_TABLE . '.attach_id))';
	$db->sql_query('DELETE FROM ' . ATTACHMENTS_TABLE . ' WHERE privmsgs_id = ' . $id . ' AND (post_id = 0 OR post_id IS NULL) AND ' . $inventoried . ' AND ' . $guard);
	// A malformed dual-purpose row must not delete another post attachment.
	$db->sql_query('UPDATE ' . ATTACHMENTS_TABLE . ' SET privmsgs_id = 0 WHERE privmsgs_id = ' . $id . ' AND post_id <> 0 AND ' . $inventoried . ' AND ' . $guard);
	phpbb_pm_recount_recipient($db, (int)$job['to_user_id']);
	$cursor = 0; $changed = (bool)phpbb_acl_rows($db, 'SELECT attach_id FROM ' . ATTACHMENTS_TABLE . ' WHERE privmsgs_id = ' . $id . ' LIMIT 1');
	while (true)
	{
		$items = phpbb_acl_rows($db, 'SELECT attach_id,physical_filename FROM ' . PM_REPAIR_ITEMS_TABLE . ' WHERE ' . $key . ' AND attach_id > ' . $cursor . ' ORDER BY attach_id LIMIT 100');
		if (!$items) { break; }
		foreach ($items as $item) { $cursor = (int)$item['attach_id']; $changed = dbmtnc_pm_job_attachment($db, $job, $item) || $changed; }
	}
	dbmtnc_pm_job_finish($db, $job); if ($changed) { $db->pm_repair_cancelled++; }
	return $removed;
}

function dbmtnc_pm_recover_pending($db)
{
	dbmtnc_pm_journal_ready($db); $recovered = 0;
	while (true)
	{
		$jobs = phpbb_acl_rows($db, 'SELECT * FROM ' . PM_REPAIR_JOBS_TABLE . ' ORDER BY job_id LIMIT 100');
		if (!$jobs) { break; }
		foreach ($jobs as $job)
		{
			$cancelled = $db->pm_repair_cancelled; dbmtnc_pm_job_run($db, $job);
			if ($db->pm_repair_cancelled === $cancelled) { $recovered++; }
		}
	}
	return $recovered;
}

function dbmtnc_pm_delete_planned($db, $ids, $spec)
{
	dbmtnc_pm_journal_ready($db); $removed = 0;
	foreach ($ids as $id)
	{
		$id = (int)$id;
		$jobs = phpbb_acl_rows($db, 'SELECT * FROM ' . PM_REPAIR_JOBS_TABLE . ' WHERE message_id = ' . $id);
		if (!$jobs)
		{
			$parents = phpbb_acl_rows($db, 'SELECT privmsgs_id,privmsgs_from_userid,privmsgs_to_userid,privmsgs_type,privmsgs_date FROM ' . PRIVMSGS_TABLE . ' WHERE privmsgs_id = ' . $id . ' AND (' . $spec['where'] . ')');
			if (!$parents) { continue; }
			require_once dirname(__FILE__) . '/php_compat.php';
			$token = bin2hex(phpbb_random_bytes(16)); $p = $parents[0];
			$job = array('job_id'=>$token,'message_id'=>$id,'repair_mode'=>$spec['mode'],'repair_state'=>'planning','from_user_id'=>(int)$p['privmsgs_from_userid'],
				'to_user_id'=>(int)$p['privmsgs_to_userid'],'message_type'=>(int)$p['privmsgs_type'],'message_date'=>(int)$p['privmsgs_date'],'cutoff'=>$spec['cutoff']);
			$actor = phpbb_acl_actor(new PhpbbAclDatabase($db->connection, 'Maintenance_pm_repair_failed'), 'maintenance');
			$db->insert_guarded(PM_REPAIR_JOBS_TABLE, 'job_id,message_id,repair_mode,repair_state,from_user_id,to_user_id,message_type,message_date,cutoff,created_by,created_at',
				"'" . $token . "'," . $id . ",'" . $spec['mode'] . "','planning'," . $job['from_user_id'] . ',' . $job['to_user_id'] . ',' . $job['message_type'] . ',' . $job['message_date'] . ',' . $job['cutoff'] . ',' . (int)$actor['user_id'] . ',' . time() . ' FROM ' . PRIVMSGS_TABLE,
				dbmtnc_pm_job_parent($job) . ' AND (' . $spec['where'] . ') AND NOT EXISTS (SELECT 1 FROM ' . PM_REPAIR_JOBS_TABLE . ' j WHERE j.message_id = ' . $id . ')');
			$jobs = phpbb_acl_rows($db, 'SELECT * FROM ' . PM_REPAIR_JOBS_TABLE . " WHERE job_id = '" . $token . "'");
			if (!$jobs) { continue; }
		}
		$removed += dbmtnc_pm_job_run($db, $jobs[0]);
	}
	return $removed;
}
