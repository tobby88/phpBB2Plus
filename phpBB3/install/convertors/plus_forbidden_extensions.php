<?php
if (!defined('IN_PHPBB')) { exit; }

function plus_import_forbidden_extensions()
{
	global $convert, $src_db, $db, $table_prefix;
	$table = $convert->src_table_prefix . 'forbidden_extensions';
	$result = $src_db->sql_query("SELECT COUNT(*) AS present FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" .
		$src_db->sql_escape($table) . "'");
	$present = (int) $src_db->sql_fetchfield('present');
	$src_db->sql_freeresult($result);
	if (!$present) { return; }
	$target = $table_prefix . 'plus_attach_forbidden';
	$db->sql_transaction('begin');
	try
	{
		// Some source installations held duplicate, malformed or conflicting rows.
		// Keep each source record and leave separately configured native extensions alone.
		$db->sql_query("DELETE FROM $target WHERE source_id>0");
		$after = 0;
		do
		{
			// Read in the column's declared charset even when the stock converter uses a binary client connection.
			$result = $src_db->sql_query_limit("SELECT ext_id,BINARY CONVERT(extension USING utf8mb4) AS extension FROM $table WHERE ext_id>$after ORDER BY ext_id", 500);
			$rows = $src_db->sql_fetchrowset($result);
			$src_db->sql_freeresult($result);
			$batch = array();
			foreach ($rows as $row)
			{
				$after = (int) $row['ext_id'];
				$name = (string) $row['extension'];
				$batch[] = array('source_id'=>$after, 'extension_name'=>$name,
					'extension_key'=>\phpbb2plus\community\attachment\forbidden_extensions::key($name));
			}
			if ($batch) { $db->sql_multi_insert($target, $batch); }
		} while (count($rows) === 500);
		$db->sql_query('UPDATE ' . CONFIG_TABLE . " SET config_value=config_value+1 WHERE config_name='plus_attach_block_rev'");
		$db->sql_transaction('commit');
	}
	catch (\Throwable $e) { $db->sql_transaction('rollback'); throw $e; }
}
