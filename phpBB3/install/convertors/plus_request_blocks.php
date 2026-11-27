<?php
if (!defined('IN_PHPBB')) { exit; }

use phpbb2plus\community\service\request_blocks;
use phpbb2plus\community\service\request_pattern;
use phpbb2plus\community\service\tracker_settings;

function plus_import_request_blocks()
{
	global $convert, $db, $src_db, $table_prefix, $config;
	$tools = (new \phpbb\db\tools\factory())->get($src_db);
	$source = $convert->src_table_prefix . 'ctracker_ipblocker';
	$settings = $convert->src_table_prefix . 'ctracker_config';
	// The installer builds a separate container without the board's extension services.
	$blocks = new request_blocks($db, $table_prefix);
	$rules = $table_prefix . 'plus_ct_blocks';
	$state_table = $table_prefix . 'plus_ct_block_state';
	$has_rules = $tools->sql_table_exists($source);
	$has_settings = $tools->sql_table_exists($settings);
	$state = $blocks->state();
	if (($has_rules && !$state['rules_imported']) || ($has_settings && !$state['settings_imported']))
	{
		$db->sql_transaction('begin');
		try
		{
			$state = $blocks->state(true);
			$load_rules = $has_rules && !$state['rules_imported'];
			$load_settings = $has_settings && !$state['settings_imported'];
			if ($load_rules || $load_settings)
			{
				// A native installation can already have its own rules when its source becomes available.
				// Original identifiers have no external references. Retain them on a clean import.
				$result = $db->sql_query("SELECT COUNT(*) AS n FROM $rules");
				$existing = (int) $db->sql_fetchfield('n');
				$db->sql_freeresult($result);
				if ($load_rules)
				{
					plus_copy_block_rules($source, $rules, !$existing);
					$db->sql_query("UPDATE $state_table SET rules_imported=1,rules_version=rules_version+1 WHERE state_id=1");
				}
				if ($load_settings)
				{
					$local_rules = $existing && (!$state['rules_imported'] || (int) $state['rules_version'] > 1);
					if (!$local_rules) { plus_copy_block_settings($settings); }
					$db->sql_query("UPDATE $state_table SET settings_imported=1 WHERE state_id=1");
				}
			}
			$db->sql_transaction('commit');
		}
		catch (\Throwable $error) { $db->sql_transaction('rollback'); throw $error; }
	}
	if ($blocks->state()['log_imported']) { return; }
	$limit = max(1, min(400, (int) $config['plus_ct_block_limit']));
	$log = plus_block_log_snapshot($convert->options['forum_path'], $limit);
	if ($log !== null) { plus_import_block_log($blocks, $log, $limit); }
}

function plus_copy_block_rules($source, $target, $keep_ids)
{
	global $src_db, $db;
	$last = -1;
	do
	{
		$result = $src_db->sql_query_limit("SELECT id,ct_blocker_value FROM $source WHERE id>$last ORDER BY id", 200);
		$rows = $src_db->sql_fetchrowset($result);
		$src_db->sql_freeresult($result);
		$batch = array();
		foreach ($rows as $row)
		{
			$last = (int) $row['id'];
			$value = (string) $row['ct_blocker_value'];
			$rule = array('rule_active' => (int) (request_pattern::normalize($value) !== ''),
				'rule_value' => preg_match('//u', $value) ? $value : 'hex: ' . bin2hex($value));
			if ($keep_ids)
			{
				$rule['rule_id'] = $last;
				if (!$last)
				{
					// NO_AUTO_VALUE_ON_ZERO permits an old rule numbered zero.
					$maximum = $src_db->sql_query("SELECT MAX(id) AS max_id FROM $source");
					$rule['rule_id'] = 1 + (int) $src_db->sql_fetchfield('max_id');
					$src_db->sql_freeresult($maximum);
				}
			}
			$batch[] = $rule;
		}
		if ($batch) { $db->sql_multi_insert($target, $batch); }
	} while (count($rows) === 200);
}

function plus_copy_block_settings($source)
{
	global $src_db, $config;
	$keys = array('ipblock_enabled' => array('plus_ct_block_enabled', '1'), 'ipblock_logsize' => array('plus_ct_block_limit', '100'));
	$result = $src_db->sql_query("SELECT ct_config_name,ct_config_value FROM $source WHERE " . $src_db->sql_in_set('ct_config_name', array_keys($keys)));
	while ($row = $src_db->sql_fetchrow($result))
	{
		list($key, $default) = $keys[$row['ct_config_name']];
		$value = (string) $row['ct_config_value'];
		if ((string) $config[$key] === $default && tracker_settings::accepts($key, $value)) { $config->set($key, $value); }
	}
	$src_db->sql_freeresult($result);
}

/** Read an optional offline log, keeping at most one configured window of rows in memory. */
function plus_block_log_snapshot($root, $limit)
{
	$relative = 'ctracker/logfiles/logfile_blocklist.txt';
	$path = realpath($root);
	if (!$path) { return null; }
	foreach (explode('/', $relative) as $part)
	{
		$path .= DIRECTORY_SEPARATOR . $part;
		if (is_link($path)) { throw new \RuntimeException('The offline CrackerTracker log must be a regular file in the source forum.'); }
	}
	if (!file_exists($path)) { return null; }
	$path = plus_media_path($root, $relative);
	$handle = fopen($path, 'rb');
	if (!$handle) { throw new \RuntimeException('Unable to read the offline CrackerTracker log.'); }
	$count = 0;
	$log = array('archived' => 0, 'reset_kind' => '', 'reset_time' => 0, 'events' => array());
	try
	{
		if (!flock($handle, LOCK_SH)) { throw new \RuntimeException('Unable to lock the offline CrackerTracker log.'); }
		$first = true;
		while (($line = fgets($handle, 8193)) !== false)
		{
			if (substr($line, -1) !== "\n" && !feof($handle))
			{
				// Skip the whole malformed line, including any apparent record embedded in its remainder.
				while (($line = fgets($handle, 8193)) !== false && substr($line, -1) !== "\n") {}
				$first = false;
				continue;
			}
			$fields = explode('|||', rtrim($line, "\r\n"));
			if ($first && count($fields) >= 7 && $fields[0] === '1' && ctype_digit($fields[1]) && array_slice($fields, 2, 5) === array_fill(0, 5, 'null'))
			{
				$kind = $fields[7] ?? '';
				$reset = in_array($kind, array('rotated', 'cleared'), true);
				if ($reset && isset($fields[8]) && ctype_digit($fields[8])) { $log['archived'] = (int) $fields[8]; }
				if ((int) $fields[1] > 0 && (int) $fields[1] <= 4294967295)
				{
					$log['reset_time'] = (int) $fields[1];
					$log['reset_kind'] = $reset || $kind === 'created' ? $kind : 'legacy';
				}
			}
			elseif (count($fields) === 7 && $fields[0] === '0' && ctype_digit($fields[1]) && (int) $fields[1] > 0 && (int) $fields[1] <= 4294967295)
			{
				$log['events'][$count % $limit] = request_blocks::event_fields($fields[1], $fields[5], $fields[4],
					$fields[6] === 'null' ? '' : $fields[6], $fields[2], $fields[3]);
				++$count;
			}
			$first = false;
		}
		if (!feof($handle)) { throw new \RuntimeException('Unable to finish reading the offline CrackerTracker log.'); }
	}
	finally { fclose($handle); }
	if ($count > $limit)
	{
		$first = $count % $limit;
		$log['events'] = array_merge(array_slice($log['events'], $first), array_slice($log['events'], 0, $first));
		$log['archived'] = plus_block_count_sum($log['archived'], $count - $limit);
	}
	return $log;
}

function plus_block_count_sum($a, $b)
{
	return $a > PHP_INT_MAX - $b ? PHP_INT_MAX : $a + $b;
}

function plus_import_block_log($blocks, array $log, $limit)
{
	global $db, $table_prefix;
	$events = $table_prefix . 'plus_ct_block_events';
	$state_table = $table_prefix . 'plus_ct_block_state';
	$db->sql_transaction('begin');
	try
	{
		$state = $blocks->state(true);
		if (!$state['log_imported'])
		{
			$total = plus_block_count_sum((int) $state['archived'], $log['archived']);
			foreach ($log['events'] as $row)
			{
				if ($row['event_time'] <= (int) $state['reset_time']) { $total = plus_block_count_sum($total, 1); continue; }
				$db->sql_query("INSERT INTO $events " . $db->sql_build_array('INSERT', $row));
			}
			$result = $db->sql_query_limit("SELECT event_id FROM $events ORDER BY event_time DESC,event_id DESC", $limit);
			$keep = array_column($db->sql_fetchrowset($result), 'event_id');
			$db->sql_freeresult($result);
			if ($keep)
			{
				$db->sql_query("DELETE FROM $events WHERE " . $db->sql_in_set('event_id', $keep, true));
				$total = plus_block_count_sum($total, (int) $db->sql_affectedrows());
			}
			$changes = array('log_imported' => 1, 'archived' => $total);
			if ($log['reset_time'] > (int) $state['reset_time'])
			{
				$changes += array('reset_time' => $log['reset_time'], 'reset_kind' => $log['reset_kind']);
			}
			$db->sql_query("UPDATE $state_table SET " . $db->sql_build_array('UPDATE', $changes) . ' WHERE state_id=1');
		}
		$db->sql_transaction('commit');
	}
	catch (\Throwable $error) { $db->sql_transaction('rollback'); throw $error; }
}
