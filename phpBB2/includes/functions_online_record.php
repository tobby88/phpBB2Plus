<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_owned_storage.php';

function phpbb_online_record_values($database, $locked = false)
{
	$rows = $database->rows('SELECT config_name,config_value FROM ' . CONFIG_TABLE
		. " WHERE config_name IN ('record_online_users','record_online_date') ORDER BY config_name" . ($locked ? ' FOR UPDATE' : ''));
	if (count($rows) !== 2) { return false; } $values = array();
	foreach ($rows as $row)
	{
		if (!in_array($row['config_name'], array('record_online_users','record_online_date'), true)
			|| !preg_match('/^(?:0|[1-9][0-9]{0,9})$/D', (string)$row['config_value'])
			|| (float)$row['config_value'] > 2147483647) { return false; }
		$values[$row['config_name']] = (int)$row['config_value'];
	}
	return isset($values['record_online_users'], $values['record_online_date'])
		? array('count'=>$values['record_online_users'], 'date'=>$values['record_online_date']) : false;
}

class PhpbbOnlineRecordStore extends PhpbbOwnedStore
{
	var $commit_attempted = false;
	var $changed = false;
	protected function storage_tables() { return array(CONFIG_TABLE); }
	function apply_record()
	{
		$record = phpbb_online_record_values($this, true);
		if ($record === false) { $this->storage_fail(); }
		if ($this->actor['count'] > $record['count'])
		{
			$this->changed = true;
			$record = array('count'=>$this->actor['count'], 'date'=>$this->actor['date']);
			foreach (array('record_online_users'=>'count','record_online_date'=>'date') as $key => $field)
			{
				$this->query('UPDATE ' . CONFIG_TABLE . ' SET config_value=\'' . $record[$field]
					. "' WHERE config_name='$key' AND HEX(config_name)=HEX('$key')");
			}
			if (phpbb_online_record_values($this) !== $record) { $this->storage_fail(); }
		}
		$this->commit_attempted = true; $this->commit();
		return $record;
	}
}

// Only a server-observed global cohort supplies these values, never request
// parameters. The peak/date pair may only advance together, under one lock.
function phpbb_online_record_update($database, $count, $observed_at)
{
	$failed = array('confirmed'=>false, 'record'=>false, 'invalidate'=>false);
	if (!is_int($count) || $count < 0 || $count > 2147483647 || !is_int($observed_at)
		|| $observed_at <= 0 || $observed_at > 2147483647 || $observed_at > time() + 1) { return $failed; }
	$store = null;
	try
	{
		$store = new PhpbbOnlineRecordStore($database, array('count'=>$count, 'date'=>$observed_at));
		$record = $store->apply_record();
		return array('confirmed'=>true, 'record'=>$record, 'invalidate'=>$store->changed);
	}
	catch (Exception $error) { $failed['invalidate'] = $store && $store->commit_attempted && $store->changed; return $failed; }
	catch (Error $error) { $failed['invalidate'] = $store && $store->commit_attempted && $store->changed; return $failed; }
	finally { if ($store) { $store->release(); } }
}
