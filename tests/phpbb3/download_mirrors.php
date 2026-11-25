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
register_shutdown_function(static function () use (&$finished,$phase,$db) {
	if (!$finished || $db->get_sql_error_triggered())
	{
		$error = $db->get_sql_error_returned();
		fwrite(STDERR,"Incomplete mirror fixture phase $phase: " . ($error['message'] ?? 'interrupted') . "\n");
		exit(1);
	}
});
$p = $table_prefix . 'plus_dl_';
$rows = static function ($sql) use ($db) {
	$r = $db->sql_query($sql);
	if ($r === false) { throw new RuntimeException('The mirror fixture query failed'); }
	$data = $db->sql_fetchrowset($r);
	$db->sql_freeresult($r);
	return $data;
};
$insert = static function ($table,$data) use ($db) {
	if (!$db->sql_query("INSERT INTO $table " . $db->sql_build_array('INSERT',$data)))
	{ throw new RuntimeException('The mirror fixture insert failed'); }
};
switch ($argv[2])
{
	case 'installed':
	case 'uninstalled':
		$count = (int) ($argv[2] === 'installed');
		if (count($rows('SELECT auth_option FROM ' . ACL_OPTIONS_TABLE . " WHERE auth_option='a_plus_dl_mirrors'"))!==$count ||
			count($rows('SELECT module_id FROM ' . MODULES_TABLE . " WHERE module_class='acp' AND module_langname='ACP_PLUS_DL_MIRRORS' AND module_mode='manage'"))!==$count)
		{ throw new RuntimeException('The mirror module migration did not install or remove exactly its native permission and entry'); }
		break;
	case 'seed':
		foreach (array('de'=>array('Download-Spiegelserver verwalten','Spiegelserver 8'),
			'en'=>array('Manage download mirrors','Mirror 8')) as $locale=>$expected)
		{
			$translations = new \phpbb\language\language($phpbb_container->get('language.loader'));
			$translations->set_user_language($locale);
			$translations->add_lang(array('downloads','info_acp_downloads'),'phpbb2plus/downloads');
			if (array($translations->lang('ACP_PLUS_DL_MIRRORS'),$translations->lang('PLUS_DL_MIRROR_NUMBER',8))!==$expected)
			{ throw new RuntimeException('The native mirror language pack did not load'); }
		}
		foreach (array(9900=>50,9903=>51,9904=>52,9905=>50) as $id=>$from)
		{
			$f = $rows("SELECT * FROM {$p}files WHERE file_id=$from")[0];
			$insert($p.'files',array('file_id'=>$id,'cat_id'=>$id===9905 ? 999999 : $f['cat_id'],'review_revision'=>4)+$f);
		}
		foreach (array(9901=>array(8,9900),9902=>array(9,9900),9907=>array(8,9905),9908=>array(9,9903)) as $id=>$source)
		{
			$m = $rows("SELECT * FROM {$p}mirrors WHERE mirror_id={$source[0]}")[0];
			$insert($p.'mirrors',array('mirror_id'=>$id,'file_id'=>$source[1])+$m);
		}
		require_once './includes/acp/auth.php';
		require_once './includes/functions_module.php';
		$subject = $rows('SELECT * FROM ' . USERS_TABLE . " WHERE username='DepartingFixture'")[0];
		$actor = (int) $subject['user_id'];
		$db->sql_transaction('begin');
		try
		{
			(new auth_admin())->acl_set('user',0,$actor,array('a_plus_dl_mirrors'=>ACL_YES));
			$sid = bin2hex(random_bytes(16));
			$issued = $rows('SELECT * FROM ' . SESSIONS_TABLE . " WHERE session_user_id=$actor ORDER BY session_time DESC LIMIT 1");
			if (!$issued) { throw new RuntimeException('The synthetic mirror delegate has no issued phpBB session'); }
			$insert(SESSIONS_TABLE,array('session_id'=>$sid,'session_admin'=>1,'session_time'=>time())+$issued[0]);
			$user->session_id = $sid;
			$user->data = $rows('SELECT * FROM ' . USERS_TABLE . " WHERE user_id=$actor")[0] + array('is_registered'=>true,'session_admin'=>1);
			$auth->acl($user->data);
			define('IN_ADMIN',true);
			$access = $phpbb_container->get('phpbb2plus.downloads.access');
			$expression = (new \phpbb2plus\downloads\acp\mirrors_info())->module()['modes']['manage']['auth'];
			$private = $phpbb_container->get('phpbb2plus.downloads.mirrors')->review('edit',9903,array(9908));
			if (!$access->mirror_admin(true) || !p_master::module_auth($expression,0) || !$private['file']['eligible'] ||
				$access->admin() || $access->field_admin() || $access->license_admin() || $access->visible(23,true))
			{ throw new RuntimeException('Mirror delegation lost the private file ACP or granted public download access'); }
			$db->sql_query('UPDATE ' . SESSIONS_TABLE . " SET session_admin=0 WHERE session_id='$sid'");
			if ($access->mirror_admin(true)) { throw new RuntimeException('The previous administrative session could still write'); }
		}
		finally { $db->sql_transaction('rollback'); $auth->acl_clear_prefetch($actor); }
		break;
	case 'unchanged':
		$unchanged = $rows("SELECT * FROM {$p}mirrors WHERE mirror_id IN (9901,9902) ORDER BY mirror_id");
		if ($unchanged[0]['location']!==$rows("SELECT location FROM {$p}mirrors WHERE mirror_id=8")[0]['location'] ||
			$unchanged[0]['asset_status']!=='ready' || $unchanged[1]['asset_status']!=='remote')
		{ throw new RuntimeException('An incomplete multi-mirror edit changed a source or left a reservation'); }
		break;
	case 'replaced':
		$changed = $rows("SELECT * FROM {$p}mirrors WHERE file_id=9900 ORDER BY mirror_id");
		$original = $rows("SELECT * FROM {$p}mirrors WHERE mirror_id=8")[0];
		if (count($changed)!==3 || $changed[0]['storage_name']!==$original['storage_name'] ||
			\phpbb2plus\downloads\service\mirrors::label($changed[0])!=='Archiv &amp; 📡' ||
			json_decode($changed[0]['source_json'],true)['mirror_location']!==json_decode($original['source_json'],true)['mirror_location'] ||
			$changed[1]['asset_status']!=='ready' || json_decode($changed[1]['source_json'],true)['file_dlurl']!=='https://example.invalid/club.txt' ||
			$changed[2]['asset_status']!=='ready' || count(array_unique(array_column($changed,'storage_name')))!==3)
		{ throw new RuntimeException('A mixed rename and local replacement lost a reference or imported metadata'); }
		$insert($p.'settings',array('config_name'=>'mirror_fixture_names','config_value'=>json_encode(array_column($changed,'storage_name'))));
		break;
	case 'finished':
		$memo = $rows("SELECT config_value FROM {$p}settings WHERE config_name='mirror_fixture_names'")[0];
		$names = json_decode($memo['config_value'],true);
		$media = $phpbb_container->get('phpbb2plus.downloads.media');
		$jobs = $rows("SELECT job_id FROM {$p}media_queue WHERE " . $db->sql_in_set('storage_name',$names));
		if (!$jobs) { throw new RuntimeException('Removing mirror copies left no cleanup receipts'); }
		foreach ($jobs as $job) { $media->collect((int) $job['job_id']); }
		foreach ($names as $i=>$name)
		{
			if (!preg_match('/^download_[a-f0-9]{64}$/D',$name) || is_file('./' . $config['upload_path'] . '/' . $name)!==($i===0))
			{ throw new RuntimeException('Mirror cleanup removed shared bytes or left an unreferenced upload behind'); }
		}
		if ($rows("SELECT mirror_id FROM {$p}mirrors WHERE file_id IN (9900,9905)") ||
			count($rows("SELECT mirror_id FROM {$p}mirrors WHERE mirror_id IN (8,9,10,9908)"))!==4 ||
			count($rows('SELECT DISTINCT log_operation FROM ' . LOG_TABLE . " WHERE log_operation IN ('LOG_PLUS_DL_MIRROR_CREATE','LOG_PLUS_DL_MIRROR_EDIT','LOG_PLUS_DL_MIRROR_DELETE')"))!==3)
		{ throw new RuntimeException('Mirror removal changed unrelated copies or lost its audit records'); }
		$cols = 'cat_id,approved,license_id,owner_id,storage_name,file_bytes,preview_name,source_json';
		foreach (array(9900=>50,9903=>51,9904=>52) as $id=>$from)
		{
			if ($rows("SELECT $cols FROM {$p}files WHERE file_id=$id")!==$rows("SELECT $cols FROM {$p}files WHERE file_id=$from"))
			{ throw new RuntimeException('Mirror administration changed the original, its approval or ownership'); }
		}
		$db->sql_query("DELETE FROM {$p}settings WHERE config_name='mirror_fixture_names'");
		$db->sql_query("DELETE FROM {$p}mirrors WHERE mirror_id=9908");
		$db->sql_query("DELETE FROM {$p}visitors WHERE file_id=9900");
		$db->sql_query("DELETE FROM {$p}files WHERE file_id IN (9900,9903,9904,9905)");
		break;
	default: exit(2);
}
$finished = true;
