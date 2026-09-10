<?php
// Shared isolated fixture schema, derived from the real installer DDL.
if (!defined('PM_DELETE_JOBS_TABLE')) { define('PM_DELETE_JOBS_TABLE','fixture_pm_delete_jobs'); }
if (!defined('PM_DELETE_ITEMS_TABLE')) { define('PM_DELETE_ITEMS_TABLE','fixture_pm_delete_items'); }
function pm_mailbox_fixture_tables($pdo, $engine = 'SQLite')
{
	$schema = file_get_contents(dirname(dirname(__DIR__)) . '/phpBB2/install/schemas/mysql_schema.sql');
	foreach (array('pm_delete_jobs'=>PM_DELETE_JOBS_TABLE,'pm_delete_items'=>PM_DELETE_ITEMS_TABLE) as $source=>$target)
	{
		if (!preg_match('/^fixture_pm_delete_(jobs|items)$/D',$target)) { throw new RuntimeException('Fixture table boundary'); }
		if (!preg_match('/CREATE TABLE phpbb_' . $source . ' \(.*?\) ENGINE=InnoDB ROW_FORMAT=DYNAMIC[^;]*;/s',$schema,$match)) { throw new RuntimeException('Canonical journal DDL missing'); }
		$sql = str_replace('phpbb_' . $source,$target,$match[0]);
		if ($engine === 'SQLite')
		{
			$sql = preg_replace('/\b(?:int|mediumint|smallint)\([0-9]+\)(?: unsigned)?/i','INTEGER',$sql);
			$sql = preg_replace('/,\s*KEY owner_folder \(owner_id, folder\)/', '', $sql);
			$sql = preg_replace('/UNIQUE KEY [a-z_]+ /i','UNIQUE ',$sql);
			$sql = preg_replace('/ ENGINE=InnoDB ROW_FORMAT=DYNAMIC[^;]*;/',';',$sql);
		}
		elseif (in_array($engine,array('MyISAM','InnoDB'),true)) { $sql = str_replace('ENGINE=InnoDB ROW_FORMAT=DYNAMIC','ENGINE=' . $engine,$sql); }
		else { throw new RuntimeException('Unknown fixture engine'); }
		$pdo->exec('DROP TABLE IF EXISTS ' . $target); $pdo->exec($sql);
	}
}
