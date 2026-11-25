<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D',$argv[1])) { exit(2); }
define('IN_PHPBB',true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$phase = $argv[2];
$finished = false;
$db->sql_return_on_error(true);
register_shutdown_function(static function () use (&$finished,$db,$phase) {
	if (!$finished || $db->get_sql_error_triggered())
	{
		$error = $db->get_sql_error_returned();
		fwrite(STDERR,"Incomplete file editor fixture $phase: " . ($error['message'] ?? 'interrupted') . "\n");
		exit(1);
	}
});
$p = $table_prefix . 'plus_dl_';
$rows = static function ($sql) use ($db) {
	$res = $db->sql_query($sql);
	if ($res === false) { throw new RuntimeException('File editor fixture query failed'); }
	$data = $db->sql_fetchrowset($res);
	$db->sql_freeresult($res);
	return $data;
};
$insert = static function ($table,$data) use ($db) {
	if (!$db->sql_query("INSERT INTO $table " . $db->sql_build_array('INSERT',$data))) { throw new RuntimeException('File editor fixture insert failed'); }
};
$lookup = 'SELECT * FROM ' . USERS_TABLE . " WHERE username='DownloadEditorDelegate'";
$created = "SELECT * FROM {$p}files WHERE title='Editor upload fixture'";
$module = 'SELECT module_auth FROM ' . MODULES_TABLE . " WHERE module_langname='ACP_PLUS_DL_FILE_ACTIONS' AND module_class='acp' AND module_mode='manage'";
switch ($phase)
{
	case 'installed':
	case 'uninstalled':
		$installed = $phase === 'installed';
		$auth_rule = $rows($module);
		if (count($auth_rule)!==1 || (strpos($auth_rule[0]['module_auth'],'acl_a_plus_dl_edit') !== false)!==$installed ||
			count($rows('SELECT auth_option FROM ' . ACL_OPTIONS_TABLE . " WHERE auth_option='a_plus_dl_edit'"))!==(int) $installed ||
			$phpbb_container->get('dbal.tools')->sql_column_exists($p.'files','icon_name')!==$installed)
		{ throw new RuntimeException('The historical native module or editor permission did not survive the migration round trip'); }
		if ($installed)
		{
			$display = $rows("SELECT icon_name,preview_link FROM {$p}files WHERE file_id=53")[0];
			if ($display['icon_name']!=='pdf.gif' || !$display['preview_link']) { throw new RuntimeException('The converted display preferences were lost'); }
		}
		break;
	case 'seed':
		require_once './includes/functions_user.php';
		require_once './includes/acp/auth.php';
		$group = $rows('SELECT group_id FROM ' . GROUPS_TABLE . " WHERE group_name='REGISTERED'")[0];
		$pwd = $rows('SELECT user_password FROM ' . USERS_TABLE . " WHERE username='ModernMember'")[0];
		$actor = user_add(array('username'=>'DownloadEditorDelegate','user_password'=>$pwd['user_password'],'group_id'=>(int) $group['group_id'],
			'user_type'=>USER_NORMAL,'user_email'=>'download-editor@example.invalid','user_lang'=>'en'));
		if (!$actor) { throw new RuntimeException('Could not create the limited file editor'); }
		(new auth_admin())->acl_set('user',0,$actor,array('a_plus_dl_edit'=>ACL_YES));
		$original = $rows("SELECT * FROM {$p}files WHERE file_id=51")[0];
		$insert($p.'files',array('file_id'=>10501,'title'=>'Orphan editor fixture','cat_id'=>999999,'downloads'=>8,'review_revision'=>2)+$original);
		$v = $rows("SELECT * FROM {$p}field_values WHERE file_id=50 AND field_id=81")[0];
		unset($v['row_id']);
		$insert($p.'field_values',array('file_id'=>10501)+$v);
		break;
	case 'created':
		$items = $rows($created);
		$actor = (int) $rows($lookup)[0]['user_id'];
		if (count($items)!==1 || (int) $items[0]['owner_id']!==$actor || (int) $items[0]['cat_id']!==23 || $items[0]['approved'] ||
			!$items[0]['pinned'] || (int) $items[0]['downloads']!==5 || $items[0]['icon_name']!=='pdf.gif' || !$items[0]['preview_link'] ||
			$items[0]['asset_status']!=='ready' || !$items[0]['preview_name'] || (int) $items[0]['license_id']!==31 ||
			count($rows('SELECT log_id FROM ' . LOG_TABLE . " WHERE user_id=$actor AND log_operation='LOG_PLUS_DL_FILE_CREATE'"))!==1)
		{ throw new RuntimeException('Repeated administrative submission, owner, approval, display, binary, licence or counter mismatch'); }
		$insert($p.'settings',array('config_name'=>'editor_test_media','config_value'=>json_encode(array($items[0]['storage_name'],$items[0]['preview_name']))));
		break;
	case 'retained':
		$columns = 'owner_id,added_at,license_id,storage_name,approved';
		if ($rows("SELECT $columns FROM {$p}files WHERE file_id=10501") !== $rows("SELECT $columns FROM {$p}files WHERE file_id=51") ||
			$rows("SELECT values_json,source_json FROM {$p}field_values WHERE file_id=10501 AND field_id=81") !==
			$rows("SELECT values_json,source_json FROM {$p}field_values WHERE file_id=50 AND field_id=81"))
		{ throw new RuntimeException('Repairing the orphan changed its original owner, file, approval or field history'); }
		$row = $rows("SELECT cat_id,downloads,review_revision FROM {$p}files WHERE file_id=10501")[0];
		if ((int) $row['cat_id']!==21 || (int) $row['downloads']!==9 || (int) $row['review_revision']!==4)
		{ throw new RuntimeException('An unrequested counter reset or stale update reached the repaired file'); }
		break;
	case 'revoke':
		define('IN_ADMIN',true);
		require_once './includes/acp/auth.php';
		$subject = $rows($lookup)[0];
		$actor = (int) $subject['user_id'];
		$session = $rows('SELECT session_id,session_admin FROM ' . SESSIONS_TABLE . " WHERE session_user_id=$actor AND session_admin=1 ORDER BY session_time DESC")[0];
		$user->session_id = $session['session_id'];
		$user->data = $subject + $session + array('is_registered'=>true);
		$auth->acl($user->data);
		$access = $phpbb_container->get('phpbb2plus.downloads.access');
		$item = $access->editor_file(10501);
		(new auth_admin())->acl_set('user',0,$actor,array('a_plus_dl_edit'=>ACL_NEVER,'a_plus_dl_approval'=>ACL_YES));
		try { $phpbb_container->get('phpbb2plus.downloads.submissions')->edit(10501,21,(int) $item['review_revision'],array(),array(),array(),array(),false,array()); }
		catch (DomainException $e) { if ($e->getMessage()==='NO_AUTH_OPERATION') { break; } throw $e; }
		throw new RuntimeException('A revoked editor wrote with the previous in-memory role');
	case 'finished':
		$names = json_decode($rows("SELECT config_value FROM {$p}settings WHERE config_name='editor_test_media'")[0]['config_value'],true);
		$shared = $rows("SELECT storage_name FROM {$p}files WHERE file_id=51")[0]['storage_name'];
		$jobs = $rows("SELECT job_id FROM {$p}media_queue WHERE " . $db->sql_in_set('storage_name',array_merge($names,array($shared))));
		foreach ($jobs as $job) { $phpbb_container->get('phpbb2plus.downloads.media')->collect($job['job_id']); }
		foreach ($names as $name)
		{ if (is_file('./' . $config['upload_path'] . '/' . $name)) { throw new RuntimeException('Replacing an administrative upload left private bytes behind'); } }
		if ($rows($created) || $rows("SELECT file_id FROM {$p}files WHERE file_id=10501") || !is_file('./' . $config['upload_path'] . '/' . $shared))
		{ throw new RuntimeException('Disposing the file editor fixtures affected other files'); }
		$db->sql_query("DELETE FROM {$p}settings WHERE config_name='editor_test_media'");
		require_once './includes/functions_user.php';
		user_delete('retain',(int) $rows($lookup)[0]['user_id']);
		break;
	default: exit(2);
}
$finished = true;
