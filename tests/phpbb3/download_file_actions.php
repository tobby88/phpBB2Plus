<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D',$argv[1])) { exit(2); }
define('IN_PHPBB',true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$phase = $argv[2];
$complete = false;
$db->sql_return_on_error(true);
register_shutdown_function(static function () use (&$complete,$phase,$db) {
	if (!$complete || $db->get_sql_error_triggered())
	{
		$error = $db->get_sql_error_returned();
		fwrite(STDERR,"Incomplete download file action fixture $phase: " . ($error['message'] ?? 'interrupted') . "\n");
		exit(1);
	}
});
$p = $table_prefix . 'plus_dl_';
$rows = static function ($sql) use ($db) {
	$result = $db->sql_query($sql);
	if ($result === false) { throw new RuntimeException('The file action fixture query failed'); }
	$rows = $db->sql_fetchrowset($result);
	$db->sql_freeresult($result);
	return $rows;
};
$insert = static function ($table,$row) use ($db) {
	if (!$db->sql_query("INSERT INTO $table " . $db->sql_build_array('INSERT',$row))) { throw new RuntimeException('Could not insert fixture row'); }
};
$unique = 'download_' . hash('sha256',$dbname . ' distinct fixture mirror');
$path = './' . $config['upload_path'] . '/' . $unique;
$ids = array(10101,10102,10104);
if ($phase === 'switch' || $phase === 'rollback')
{
	define('IN_ADMIN',true);
	require_once './includes/acp/auth.php';
	$delegate = $rows('SELECT * FROM ' . USERS_TABLE . " WHERE username='DownloadFileDelegate'")[0];
	$actor = (int) $delegate['user_id'];
	$session = $rows('SELECT session_id,session_admin FROM ' . SESSIONS_TABLE . " WHERE session_user_id=$actor AND session_admin=1 ORDER BY session_time DESC")[0];
	$user->session_id = $session['session_id'];
	$user->data = $delegate + $session + array('is_registered'=>true);
	$auth->acl($user->data);
	$access = $phpbb_container->get('phpbb2plus.downloads.access');
	$manager = $phpbb_container->get('phpbb2plus.downloads.file_actions');
}
switch ($phase)
{
	case 'installed':
	case 'uninstalled':
		$count = (int) ($phase === 'installed');
		if (count($rows('SELECT auth_option FROM ' . ACL_OPTIONS_TABLE . " WHERE auth_option IN ('a_plus_dl_approval','a_plus_dl_delete')"))!==2*$count ||
			count($rows('SELECT module_id FROM ' . MODULES_TABLE . " WHERE module_langname='ACP_PLUS_DL_FILE_ACTIONS' AND module_class='acp' AND module_mode='manage'"))!==$count ||
			!$rows('SELECT auth_option FROM ' . ACL_OPTIONS_TABLE . " WHERE auth_option='a_plus_dl_mirrors'"))
		{ throw new RuntimeException('The download file action migration did not change just its module and two grants'); }
		break;
	case 'seed':
		require_once './includes/functions_user.php';
		require_once './includes/acp/auth.php';
		$group = $rows('SELECT group_id FROM ' . GROUPS_TABLE . " WHERE group_name='REGISTERED'")[0];
		$pwd = $rows('SELECT user_password FROM ' . USERS_TABLE . " WHERE username='ModernMember'")[0];
		$actor = user_add(array('username'=>'DownloadFileDelegate','user_password'=>$pwd['user_password'],'group_id'=>(int) $group['group_id'],
			'user_type'=>USER_NORMAL,'user_email'=>'download-file-actions@example.invalid','user_lang'=>'en'));
		if (!$actor) { throw new RuntimeException('Could not create the limited download file administrator'); }
		(new auth_admin())->acl_set('user',0,$actor,array('a_plus_dl_approval'=>ACL_YES));
		$insert($p.'grants',array('cat_id'=>23,'legacy_group'=>0,'subject_type'=>'u','subject_id'=>$actor,
			'allowed'=>'["historical_fixture_marker"]','source_json'=>'{"origin":"preserved"}'));
		foreach (array(10101=>50,10102=>51,10103=>50,10104=>50) as $id=>$from)
		{
			$f = $rows("SELECT * FROM {$p}files WHERE file_id=$from")[0];
			$insert($p.'files',array('file_id'=>$id,'title'=>'Batch document ' . $id,'cat_id'=>$id===10104 ? 999999 : $f['cat_id'],
				'review_revision'=>4,'broken'=>(int) ($id===10103),'approved'=>(int) ($id===10103))+$f);
		}
		$page_file = $rows("SELECT * FROM {$p}files WHERE file_id=50")[0];
		foreach (range(10200,10225) as $id)
		{ $insert($p.'files',array('file_id'=>$id,'title'=>'Pagination fixture ' . $id,'added_at'=>40000+$id)+$page_file); }
		foreach (array('comments'=>'comment_id','field_values'=>'row_id','votes'=>'row_id','visitors'=>'row_id','mirrors'=>'mirror_id') as $name=>$pk)
		{
			$original = $rows("SELECT * FROM $p$name WHERE file_id=50 ORDER BY $pk")[0];
			unset($original[$pk]);
			$insert($p.$name,array('file_id'=>10101)+($name === 'mirrors' ? array('storage_name'=>$unique) : array())+$original);
		}
		if (file_put_contents($path,"Private local fixture mirror\n")===false) { throw new RuntimeException('Could not create a disposable mirror'); }
		break;
	case 'approved':
	case 'withdrawn':
		$status = (int) ($phase === 'approved');
		$cols = 'owner_id,license_id,added_at,updated_at,cat_id,source_json,storage_name,preview_name';
		foreach (array(10101=>50,10102=>51,10103=>50) as $id=>$from)
		{
			$state = $rows("SELECT approved,review_revision FROM {$p}files WHERE file_id=$id")[0];
			if ((int) $state['approved']!==($id===10103 ? 1 : $status) || (int) $state['review_revision']!==($id===10103 ? 4 : 6-$status) ||
				$rows("SELECT $cols FROM {$p}files WHERE file_id=$id")!==$rows("SELECT $cols FROM {$p}files WHERE file_id=$from"))
			{ throw new RuntimeException('The mixed approval selection changed ownership, storage, or an already matching revision'); }
		}
		break;
	case 'switch':
		$view = $manager->review('approve',$ids);
		(new auth_admin())->acl_set('user',0,$actor,array('a_plus_dl_approval'=>ACL_NEVER,'a_plus_dl_delete'=>ACL_YES));
		try { $manager->save('approve',$ids,$view['state'],true); }
		catch (DomainException $e)
		{
			if ($e->getMessage() === 'NO_AUTH_OPERATION' && $access->file_admin('delete',true) && !$access->admin() && !$access->mirror_admin()) { break; }
			throw $e;
		}
		throw new RuntimeException('An approval with a revoked ACL still saved');
	case 'new_comment':
		$c = $rows("SELECT * FROM {$p}comments WHERE file_id=10101")[0];
		unset($c['comment_id']);
		$insert($p.'comments',array('title'=>'New contribution during review')+$c);
		break;
	case 'rollback':
		// Simulate an unavailable audit writer after the real DB work but before COMMIT.
		$unavailable = new class {
			public function add() { throw new RuntimeException('Fixture audit unavailable'); }
		};
		$broken = new \phpbb2plus\downloads\service\file_actions($db,$access,$phpbb_container->get('phpbb2plus.downloads.submissions'),$user,$unavailable,$p);
		$view = $broken->review('delete',$ids);
		try { $broken->save('delete',$ids,$view['state'],true); throw new RuntimeException('Missing audit did not interrupt removal'); }
		catch (RuntimeException $e) { if ($e->getMessage() !== 'Fixture audit unavailable') { throw $e; } }
		if (count($rows("SELECT file_id FROM {$p}files WHERE file_id IN (10101,10102,10104)"))!==3 ||
			count($rows("SELECT comment_id FROM {$p}comments WHERE file_id=10101"))!==2 ||
			$rows("SELECT job_id FROM {$p}media_queue WHERE storage_name='$unique'") || !is_file($path))
		{ throw new RuntimeException('A failed batch removal changed files, comments or physical cleanup intents'); }
		break;
	case 'finished':
		foreach (array('files','mirrors','comments','votes','field_values','visitors') as $table)
		{
			if ($rows("SELECT file_id FROM $p$table WHERE file_id IN (10101,10102,10104)") || !$rows("SELECT file_id FROM $p$table WHERE file_id=50"))
			{ throw new RuntimeException('Removing only the selected downloads did not preserve unrelated ' . $table); }
		}
		$shared = $rows("SELECT storage_name,preview_name FROM {$p}files WHERE file_id=10103")[0];
		$club = $rows("SELECT storage_name FROM {$p}files WHERE file_id=51")[0]['storage_name'];
		if (!$rows("SELECT job_id FROM {$p}media_queue WHERE storage_name='" . $db->sql_escape($club) . "'") ||
			!is_file('./' . $config['upload_path'] . '/' . $club))
		{ throw new RuntimeException('A removed private copy lost its cleanup receipt or its still-referenced original'); }
		$jobs = $rows("SELECT job_id,storage_name FROM {$p}media_queue WHERE " . $db->sql_in_set('storage_name',array($unique,$shared['storage_name'],$shared['preview_name'])));
		$media = $phpbb_container->get('phpbb2plus.downloads.media');
		foreach ($jobs as $job) { $media->collect((int) $job['job_id']); }
		if (is_file($path) || !is_file('./' . $config['upload_path'] . '/' . $shared['storage_name']) || !is_file('./' . $config['upload_path'] . '/' . $shared['preview_name']))
		{ throw new RuntimeException('Batch cleanup either lost shared bytes or kept an unreferenced mirror'); }
		$actor = (int) $rows('SELECT user_id FROM ' . USERS_TABLE . " WHERE username='DownloadFileDelegate'")[0]['user_id'];
		$grants = $rows("SELECT allowed,source_json FROM {$p}grants WHERE cat_id=23 AND subject_type='u' AND subject_id=$actor");
		if ($grants!==array(array('allowed'=>'["historical_fixture_marker"]','source_json'=>'{"origin":"preserved"}')) ||
			count($rows('SELECT DISTINCT log_operation FROM ' . LOG_TABLE . " WHERE log_operation IN ('LOG_PLUS_DL_FILES_APPROVE','LOG_PLUS_DL_FILES_WITHDRAW','LOG_PLUS_DL_FILES_DELETE')"))!==3)
		{ throw new RuntimeException('Batch file management changed an independent grant or lost an audit entry'); }
		$db->sql_query("DELETE FROM {$p}files WHERE file_id=10103");
		$db->sql_query("DELETE FROM {$p}files WHERE file_id BETWEEN 10200 AND 10225 AND title LIKE 'Pagination fixture %'");
		require_once './includes/functions_user.php';
		user_delete('retain',$actor);
		break;
	default: exit(2);
}
$complete = true;
