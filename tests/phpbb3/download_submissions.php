<?php
// Check and advance the file-review workflow only in the disposable integration board.
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$p = $table_prefix . 'plus_dl_';
switch ($argv[2])
{
	case 'offline':
		$config->set('upload_path', $config['upload_path'] . '/unavailable-fixture');
		break;
	case 'online':
		if (basename($config['upload_path']) !== 'unavailable-fixture') { throw new RuntimeException('Unexpected fixture storage path'); }
		$config->set('upload_path', dirname($config['upload_path']));
		break;
	case 'moderators':
		$db->sql_query("UPDATE {$p}settings SET config_value='validator_mod' WHERE config_name='validator'");
		break;
	case 'automatic':
		$db->sql_query("UPDATE {$p}settings SET config_value='0' WHERE config_name='need_validation'");
		break;
	case 'cleanup':
		$result = $db->sql_query("SELECT storage_name FROM {$p}media_queue ORDER BY job_id");
		$jobs = $db->sql_fetchrowset($result);
		$db->sql_freeresult($result);
		if (count($jobs) !== 2) { throw new RuntimeException('A retry leaked media or rejection missed a protected asset'); }
		$dir = $phpbb_root_path . $config['upload_path'] . '/';
		foreach ($jobs as $job)
		{
			if (!preg_match('/^download_[a-f0-9]{64}$/D', $job['storage_name']) || !is_file($dir . $job['storage_name']))
			{ throw new RuntimeException('An in-flight download did not receive its cleanup grace period'); }
		}
		$result = $db->sql_query("SELECT storage_name FROM {$p}files WHERE file_id=50");
		$keep = $db->sql_fetchfield('storage_name');
		$db->sql_freeresult($result);
		$phpbb_container->get('phpbb2plus.downloads.media')->queue($keep);
		// A host interruption can leave the private binary after its metadata transaction rolled back.
		$interrupted = 'download_' . bin2hex(random_bytes(32));
		$phpbb_container->get('phpbb2plus.downloads.media')->queue($interrupted);
		file_put_contents($dir . $interrupted, 'Incomplete synthetic submission');
		$db->sql_query("UPDATE {$p}media_queue SET queued_at=0");
		$phpbb_container->get('phpbb2plus.downloads.cleanup')->run();
		foreach ($jobs as $job) { if (file_exists($dir . $job['storage_name'])) { throw new RuntimeException('Rejected original or screenshot survived cleanup'); } }
		if (!is_file($dir . $keep)) { throw new RuntimeException('A queued but referenced file was removed'); }
		if (is_file($dir . $interrupted)) { throw new RuntimeException('An interrupted submission left an unreferenced file'); }
		$result = $db->sql_query("SELECT COUNT(*) AS total FROM {$p}field_values v LEFT JOIN {$p}files f ON f.file_id=v.file_id WHERE f.file_id IS NULL");
		$orphaned = (int) $db->sql_fetchfield('total');
		$db->sql_freeresult($result);
		if ($orphaned) { throw new RuntimeException('Rejection left detached custom fields'); }
		break;
	default: exit(2);
}
