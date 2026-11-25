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
$row = static function ($query) use ($db) {
	$result = $db->sql_query($query);
	$value = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);
	return $value;
};
$phase = $argv[2];
$state = $phpbb_container->get('config_text');
if ($phase === 'begin' || $phase === 'editing')
{
	$before = $row("SELECT MAX(job_id) AS latest FROM {$p}media_queue");
	$state->set('plus_fixture_dl_queue_start', (string) (int) $before['latest']);
}
if (preg_match('/^record-assets-([1-9][0-9]*)$/D', $phase, $match))
{
	$assets = $row("SELECT storage_name,preview_name FROM {$p}files WHERE file_id=" . (int) $match[1]);
	if (!$assets || !$assets['storage_name'] || !$assets['preview_name']) { throw new RuntimeException('Missing submitted original or preview'); }
	$state->set('plus_fixture_dl_assets', json_encode(array_values($assets)));
	return;
}
if ($phase === 'cleanup' || $phase === 'edited_cleanup')
{
	$before = $state->get('plus_fixture_dl_queue_start');
	if (!is_string($before) || !ctype_digit($before)) { throw new RuntimeException('Missing cleanup fixture starting point'); }
	$scope = ' WHERE job_id > ' . (int) $before;
}
switch ($phase)
{
	case 'begin': break;
	case 'editing':
		$owner = $row('SELECT user_id FROM ' . USERS_TABLE . " WHERE username='DepartingFixture'");
		if (!$owner) { throw new RuntimeException('Missing synthetic download owner'); }
		$db->sql_query("UPDATE {$p}settings SET config_value='validator_admin' WHERE config_name='validator'");
		foreach (array(21=>array('upload'=>5,'edit_file'=>1,'delete_file'=>1),25=>array('edit_file'=>1,'delete_file'=>3)) as $cat=>$actions)
		{
			$category = $row("SELECT access_levels FROM {$p}categories WHERE cat_id=$cat");
			$levels = $actions + json_decode($category['access_levels'], true);
			$db->sql_query("UPDATE {$p}categories SET " . $db->sql_build_array('UPDATE',array('access_levels'=>json_encode($levels))) . " WHERE cat_id=$cat");
		}
		$file = $row("SELECT * FROM {$p}files WHERE file_id=50");
		$file['owner_id'] = (int) $owner['user_id'];
		$file['approved'] = 0;
		$file['downloads'] = 17;
		$file['pinned'] = 1;
		$file['review_revision'] = 0;
		foreach (array(8021,8022) as $id)
		{
			$file['file_id'] = $id;
			$db->sql_query("INSERT INTO {$p}files " . $db->sql_build_array('INSERT',$file));
		}
		$custom = $row("SELECT * FROM {$p}field_values WHERE file_id=50 AND field_id=81");
		unset($custom['row_id']);
		$custom['file_id'] = 8021;
		$db->sql_query("INSERT INTO {$p}field_values " . $db->sql_build_array('INSERT',$custom));
		break;
	case 'moved':
		$file = $row("SELECT * FROM {$p}files WHERE file_id=8021");
		$owner = $row('SELECT user_id FROM ' . USERS_TABLE . " WHERE username='DepartingFixture'");
		if (!$file || (int) $file['cat_id'] !== 25 || (int) $file['owner_id'] !== (int) $owner['user_id'] || (int) $file['approved'] !== 1 ||
			(int) $file['pinned'] !== 1 || (int) $file['downloads'] < 17 || $file['asset_status'] !== 'remote' ||
			$file['storage_name'] !== '' || $file['preview_name'] !== '' || (int) $file['license_id'] !== 31 ||
			(json_decode($file['source_json'],true)['file_dlurl'] ?? '') !== 'https://example.invalid/revised')
		{ throw new RuntimeException('Editing or moving a download changed ownership, approval, statistics, licence or sources'); }
		$custom = $row("SELECT source_json FROM {$p}field_values WHERE file_id=8021 AND field_id=81");
		$original = $row("SELECT source_json FROM {$p}field_values WHERE file_id=50 AND field_id=81");
		if (!$custom || $custom !== $original) { throw new RuntimeException('Editing the file rewrote an untouched imported custom field'); }
		break;
	case 'edited_cleanup':
		$files = $row("SELECT COUNT(*) AS total FROM {$p}files WHERE file_id IN(8021,8022)");
		$custom = $row("SELECT COUNT(*) AS total FROM {$p}field_values WHERE file_id=8021");
		if ((int) $files['total'] || (int) $custom['total']) { throw new RuntimeException('A confirmed deletion left file metadata'); }
		$dir = $phpbb_root_path . $config['upload_path'] . '/';
		$keep = $row("SELECT storage_name,preview_name FROM {$p}files WHERE file_id=50");
		$result = $db->sql_query("SELECT DISTINCT storage_name FROM {$p}media_queue" . $scope);
		$jobs = $db->sql_fetchrowset($result);
		$db->sql_freeresult($result);
		if (count($jobs) !== 4) { throw new RuntimeException('Lost or leaked a media reservation during replacement'); }
		$db->sql_query("UPDATE {$p}media_queue SET queued_at=0" . $scope);
		$phpbb_container->get('phpbb2plus.downloads.cleanup')->run();
		foreach ($jobs as $job)
		{
			$name = $job['storage_name'];
			if (is_file($dir . $name) !== in_array($name,$keep,true)) { throw new RuntimeException('Replaced or shared media was cleaned incorrectly'); }
		}
		break;
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
		$result = $db->sql_query("SELECT storage_name FROM {$p}media_queue" . $scope . " ORDER BY job_id");
		$jobs = $db->sql_fetchrowset($result);
		$db->sql_freeresult($result);
		$expected = json_decode((string) $state->get('plus_fixture_dl_assets'), true);
		$actual = array_column($jobs, 'storage_name');
		if (!is_array($expected) || count($expected) !== 2) { throw new RuntimeException('Missing expected cleanup assets'); }
		sort($expected);
		sort($actual);
		if ($actual !== $expected) { throw new RuntimeException('A retry leaked media or rejection missed the submitted original or preview'); }
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
		$db->sql_query("UPDATE {$p}media_queue SET queued_at=0" . $scope);
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
