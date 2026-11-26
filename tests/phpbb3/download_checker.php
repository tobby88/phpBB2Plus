<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
require_once './includes/functions_user.php';
require_once './includes/acp/auth.php';
$done = false;
$db->sql_return_on_error(true);
register_shutdown_function(static function () use (&$done, $db) {
	if (!$done || $db->get_sql_error_triggered()) { fwrite(STDERR, "Download file check fixture failed: " .
		($db->get_sql_error_returned()['message'] ?? 'incomplete') . "\n"); exit(1); }
});
$p = $table_prefix . 'plus_dl_';
$dir = './' . $config['upload_path'] . '/';
$name = static function ($label) { return 'download_' . hash('sha256', 'checker-' . $label); };
$rows = static function ($sql) use ($db) {
	$r = $db->sql_query($sql);
	if ($r === false) { throw new RuntimeException($db->get_sql_error_returned()['message']); }
	$data = $db->sql_fetchrowset($r);
	$db->sql_freeresult($r);
	return $data;
};
$insert = static function ($table, $data) use ($db) { $db->sql_query("INSERT INTO $table " . $db->sql_build_array('INSERT', $data)); };
$scope = 'file_id BETWEEN 11080 AND 11085';
$contents = array('shared'=>'shared content', 'size'=>'short', 'mirror'=>'mirror', 'queue'=>'pending', 'attachment'=>'native', 'orphan'=>str_repeat('a', 137));
$fingerprint = static function () use ($dir, $p, $scope, $rows, $contents, $name) {
	$data = array($rows("SELECT * FROM {$p}files WHERE $scope ORDER BY file_id"), $rows("SELECT * FROM {$p}mirrors WHERE $scope ORDER BY mirror_id"),
		$rows("SELECT * FROM {$p}media_queue WHERE storage_name='" . $name('queue') . "'"),
		$rows("SELECT * FROM " . ATTACHMENTS_TABLE . " WHERE physical_filename='" . $name('attachment') . "'"));
	foreach ($contents as $label=>$body) { $data[] = hash_file('sha256', $dir . $name($label)); }
	return hash('sha256', serialize($data));
};
switch ($argv[2])
{
	case 'converted':
	case 'upgraded':
		$flags = array_column($rows("SELECT file_id,preview_unavailable FROM {$p}files WHERE file_id IN (50,53,54)"), 'preview_unavailable', 'file_id');
		if ((int) $flags[50] || (int) $flags[53] !== 1 || (int) $flags[54] ||
			count($rows('SELECT module_id FROM ' . MODULES_TABLE . " WHERE module_class='acp' AND module_langname='ACP_PLUS_DL_CHECKER'")) !== 1 ||
			count($rows('SELECT auth_option_id FROM ' . ACL_OPTIONS_TABLE . " WHERE auth_option='a_plus_dl_checker'")) !== 1)
		{ throw new RuntimeException('Converted or upgraded download preview provenance and checker module did not match'); }
		break;
	case 'older':
		if ($rows('SELECT module_id FROM ' . MODULES_TABLE . " WHERE module_langname='ACP_PLUS_DL_CHECKER'") ||
			$phpbb_container->get('dbal.tools')->sql_column_exists($p . 'files', 'preview_unavailable'))
		{ throw new RuntimeException('The preceding download schema still contains the new checker'); }
		break;
	case 'seed':
		$f = $rows("SELECT * FROM {$p}files WHERE file_id=50")[0];
		$m = $rows("SELECT * FROM {$p}mirrors ORDER BY mirror_id LIMIT 1")[0];
		if ($rows("SELECT file_id FROM {$p}files WHERE $scope") || is_dir($dir . $name('directory'))) { throw new RuntimeException('Download checker fixture collision'); }
		foreach ($contents as $label=>$body)
		{
			$path = $dir . $name($label);
			if (file_exists($path) || file_put_contents($path, $body) !== strlen($body)) { throw new RuntimeException('Could not create isolated checker file'); }
		}
		mkdir($dir . $name('directory'));
		$foreign = $dir . 'gallery_checker_fixture.dat';
		if (file_exists($foreign) || file_put_contents($foreign, 'foreign') !== 7) { throw new RuntimeException('Foreign test object already present'); }
		foreach (array(
			11080=>array('Shared original', 'ready', 'shared', 14), 11081=>array('Pending original', 'ready', 'missing', 3),
			11082=>array('Size difference', 'ready', 'size', 99), 11083=>array('Remote fixture title', 'remote', '', 0),
			11084=>array('Location to review', 'review', '', 0), 11085=>array('Shared copy', 'ready', 'shared', 14)) as $id=>$item)
		{
			$insert($p . 'files', array('file_id'=>$id, 'approved'=>(int) ($id !== 11081), 'title'=>$item[0], 'asset_status'=>$item[1],
				'storage_name'=>$item[2] === '' ? '' : $name($item[2]), 'file_bytes'=>$item[3], 'preview_name'=>$id === 11080 ? $name('missing-preview') : '',
				'preview_unavailable'=>0, 'source_json'=>json_encode(array('file_dlurl'=>'https://example.invalid/remote'))) + $f);
		}
		foreach (array(11091=>array('Valid local mirror', 'mirror'), 11092=>array('Missing mirror', 'missing-mirror')) as $id=>$item)
		{
			$insert($p . 'mirrors', array('mirror_id'=>$id, 'file_id'=>11080, 'location'=>$item[0], 'storage_name'=>$name($item[1]), 'file_bytes'=>6, 'asset_status'=>'ready') + $m);
		}
		$native = $rows('SELECT * FROM ' . ATTACHMENTS_TABLE . ' ORDER BY attach_id LIMIT 1')[0];
		unset($native['attach_id']);
		$insert(ATTACHMENTS_TABLE, array('physical_filename'=>$name('attachment')) + $native);
		$insert($p . 'media_queue', array('storage_name'=>$name('queue'), 'queued_at'=>time()));
		$group = $rows('SELECT group_id FROM ' . GROUPS_TABLE . " WHERE group_name='REGISTERED'")[0];
		$pass = $rows('SELECT user_password FROM ' . USERS_TABLE . " WHERE username='ModernMember'")[0];
		$id = user_add(array('username'=>'DownloadCheckDelegate', 'user_password'=>$pass['user_password'], 'group_id'=>(int) $group['group_id'],
			'user_type'=>USER_NORMAL, 'user_email'=>'dl-check@example.invalid', 'user_lang'=>'en'));
		if (!$id) { throw new RuntimeException('Could not create the single-permission checker'); }
		(new auth_admin())->acl_set('user', 0, $id, array('a_plus_dl_checker'=>ACL_YES));
		$config->set('plus_test_dl_check_digest', $fingerprint(), false);
		break;
	case 'unchanged':
		if ($config['plus_test_dl_check_digest'] !== $fingerprint() || !is_dir($dir . $name('directory')) ||
			file_get_contents($dir . 'gallery_checker_fixture.dat') !== 'foreign') { throw new RuntimeException('The file report changed storage or database records'); }
		break;
	case 'revoke':
		$id = (int) $rows('SELECT user_id FROM ' . USERS_TABLE . " WHERE username='DownloadCheckDelegate'")[0]['user_id'];
		(new auth_admin())->acl_set('user', 0, $id, array('a_plus_dl_checker'=>ACL_NO));
		break;
	case 'cleanup':
		foreach ($contents as $label=>$body) { unlink($dir . $name($label)); }
		rmdir($dir . $name('directory'));
		unlink($dir . 'gallery_checker_fixture.dat');
		$db->sql_query("DELETE FROM {$p}mirrors WHERE $scope");
		$db->sql_query("DELETE FROM {$p}files WHERE $scope");
		$db->sql_query("DELETE FROM {$p}media_queue WHERE storage_name='" . $name('queue') . "'");
		$db->sql_query('DELETE FROM ' . ATTACHMENTS_TABLE . " WHERE physical_filename='" . $name('attachment') . "'");
		$actors = $rows('SELECT user_id FROM ' . USERS_TABLE . " WHERE username='DownloadCheckDelegate'");
		if ($actors) { user_delete('retain', $actors[0]['user_id']); }
		$config->delete('plus_test_dl_check_digest');
		break;
	default: throw new RuntimeException('Unknown download checker test phase');
}
$done = true;
