<?php

$root = dirname(dirname(__DIR__));
$schema = file_get_contents($root . '/phpBB2/install/schemas/mysql_schema.sql');
$basic = file_get_contents($root . '/phpBB2/install/schemas/mysql_basic.sql');
$database = file_get_contents($root . '/phpBB2/ctracker/classes/class_ct_database.php');
$maintenance = file_get_contents($root . '/phpBB2/ctracker/admin/acp_module_maintenance.php');
$admin_functions = file_get_contents($root . '/phpBB2/ctracker/classes/class_ct_adminfunctions.php');
$updater = file_get_contents($root . '/update/update_from_153a.php');

if (!preg_match('/CREATE TABLE\s+`phpbb_ctracker_ipblocker`.*?`id`\s+mediumint\(8\)\s+unsigned\s+NOT NULL\s+AUTO_INCREMENT/is', $schema))
{
	fwrite(STDERR, "Fresh installs do not use atomic blocklist IDs.\n");
	exit(1);
}
if (preg_match('/INSERT INTO\s+`?phpbb_ctracker_ipblocker/i', $basic))
{
	fwrite(STDERR, "Fresh installs still seed obsolete User-Agent rules.\n");
	exit(1);
}
if (preg_match('/SELECT\s+COALESCE\s*\(\s*MAX\s*\(\s*id\s*\)/i', $database) ||
	!preg_match('/INSERT INTO\s+"\s*\.\s*CTRACKER_IPBLOCKER.*?`ct_blocker_value`.*?VALUES/s', $database))
{
	fwrite(STDERR, "Runtime blocklist inserts still allocate IDs manually.\n");
	exit(1);
}
$mode_two_start = strpos($maintenance, 'else if ( $mode == \'2\' )');
$mode_three_start = strpos($maintenance, 'else if ( $mode == \'3\' )');
if ($mode_two_start === false || $mode_three_start === false || $mode_three_start <= $mode_two_start)
{
	fwrite(STDERR, "Could not inspect the legacy-rule cleanup action.\n");
	exit(1);
}
$mode_two = substr($maintenance, $mode_two_start, $mode_three_start - $mode_two_start);
if (stripos($mode_two, 'TRUNCATE') !== false || stripos($mode_two, 'INSERT INTO') !== false ||
	stripos($mode_two, 'DELETE FROM') === false || strpos($mode_two, '*ia_archiver*') === false)
{
	fwrite(STDERR, "The maintenance action may replace custom blocklist entries.\n");
	exit(1);
}
if (strpos($updater, "MODIFY `id` MEDIUMINT(8) UNSIGNED NOT NULL AUTO_INCREMENT") === false ||
	strpos($updater, '$legacy_blocklist_values') === false || strpos($updater, 'quoted_legacy_blocklist') === false)
{
	fwrite(STDERR, "The post-1.53a updater lacks the blocklist migration.\n");
	exit(1);
}
if (strpos($admin_functions, '$current < 0 || $current > 2') === false ||
	strpos($admin_functions, '$current < 1 || $current > 9') === false)
{
	fwrite(STDERR, "CrackerTracker select helpers do not tolerate invalid legacy settings.\n");
	exit(1);
}

echo "CrackerTracker blocklist storage safety passed.\n";
