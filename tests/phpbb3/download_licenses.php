<?php
// The licence workflows use only shared fixture binaries in a disposable local board.
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D',$argv[1])) { exit(2); }
define('IN_PHPBB',true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$p = $table_prefix . 'plus_dl_';
$row = static function ($sql) use ($db) {
	$result = $db->sql_query($sql);
	$value = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);
	return $value;
};
$insert = static function ($table,$data) use ($db,$p) { $db->sql_query("INSERT INTO $p$table " . $db->sql_build_array('INSERT',$data)); };
switch ($argv[2])
{
	case 'installed':
	case 'uninstalled':
		$resolved = $argv[2] === 'installed';
		$perm = $row('SELECT COUNT(*) AS n FROM ' . ACL_OPTIONS_TABLE . " WHERE auth_option='a_plus_dl_licenses'");
		$module = $row('SELECT COUNT(*) AS n FROM ' . MODULES_TABLE . " WHERE module_class='acp' AND module_langname='ACP_PLUS_DL_LICENSES' AND module_mode='manage'");
		if ((int) $perm['n'] !== (int) $resolved || (int) $module['n'] !== (int) $resolved ||
			(bool) $phpbb_container->get('dbal.tools')->sql_index_exists($p . 'files','dl_license') !== $resolved)
		{ throw new RuntimeException('The licence migration did not install/remove exactly its permission, module and file index'); }
		break;
	case 'admin':
		foreach (array(9710=>'Alte &amp; Lizenz 📜',9711=>'Zusatzvereinbarung') as $id=>$name)
		{
			$terms = '<p>Archivtext &lt;Original&gt;</p>';
			$insert('licenses',array('license_id'=>$id,'title'=>utf8_encode_ucr($name),'terms'=>$terms,
				'source_json'=>$id === 9711 ? '' : json_encode(array('license_name'=>$name,'license_text'=>$terms,'archive_note'=>'fixture'))));
		}
		foreach (range(9740,9763) as $id)
		{ $insert('licenses',array('license_id'=>$id,'title'=>'Anlage ' . $id,'terms'=>'','source_json'=>'{}')); }
		$file = $row("SELECT * FROM {$p}files WHERE file_id=50");
		foreach (array(9720=>array(9710,21,1),9721=>array(9710,23,1),9722=>array(9711,21,0)) as $id=>$state)
		{
			list($lic,$cat,$approved) = $state;
			$insert('files',array('file_id'=>$id,'license_id'=>$lic,'cat_id'=>$cat,'approved'=>$approved,'downloads'=>5,'pinned'=>1,'review_revision'=>4)+$file);
		}
		require_once './includes/acp/auth.php';
		require_once './includes/functions_module.php';
		$subject = $row('SELECT * FROM ' . USERS_TABLE . " WHERE username='DepartingFixture'");
		$id = (int) $subject['user_id'];
		$db->sql_transaction('begin');
		try
		{
			(new auth_admin())->acl_set('user',0,$id,array('a_plus_dl_licenses'=>ACL_YES));
			$user->data = $row('SELECT * FROM ' . USERS_TABLE . " WHERE user_id=$id") + array('is_registered'=>true,'session_admin'=>1);
			$auth->acl($user->data);
			define('IN_ADMIN',true);
			$access = $phpbb_container->get('phpbb2plus.downloads.access');
			$expression = (new \phpbb2plus\downloads\acp\licenses_info())->module()['modes']['manage']['auth'];
			if (!$access->license_admin() || !p_master::module_auth($expression,0) || $access->admin() || $access->permissions_admin())
			{ throw new RuntimeException('Licence administration delegated unrelated download powers'); }
		}
		finally { $db->sql_transaction('rollback'); $auth->acl_clear_prefetch($id); }
		break;
	case 'attached':
		$file = $row("SELECT * FROM {$p}files WHERE file_id=9722");
		$insert('files',array('file_id'=>9723)+$file);
		break;
	case 'edited':
		$lic = $row("SELECT * FROM {$p}licenses WHERE license_id=9710");
		$archive = json_decode($lic['source_json'],true);
		$file = $row("SELECT license_id,approved,review_revision,downloads FROM {$p}files WHERE file_id=9720");
		if (($archive['archive_note'] ?? '') !== 'fixture' || $archive['license_text'] !== '<p>Archivtext &lt;Original&gt;</p>' ||
			(int) $file['license_id'] !== 9710 || (int) $file['approved'] !== 1 || (int) $file['review_revision'] !== 4 || (int) $file['downloads'] !== 5)
		{ throw new RuntimeException('Editing licence text altered a file or erased archived source metadata'); }
		break;
	case 'removed':
		$original = $row("SELECT * FROM {$p}files WHERE file_id=50");
		$licenses = $row("SELECT COUNT(*) AS n FROM {$p}licenses WHERE license_id IN (9710,9711) OR license_id BETWEEN 9740 AND 9763");
		$logs = $row('SELECT COUNT(DISTINCT log_operation) AS n FROM ' . LOG_TABLE . " WHERE log_operation IN ('LOG_PLUS_DL_LICENSE_CREATE','LOG_PLUS_DL_LICENSE_EDIT','LOG_PLUS_DL_LICENSE_DELETE')");
		$abandoned = $row("SELECT COUNT(*) AS n FROM {$p}files WHERE title='Überholte Lizenzwahl'");
		if ($licenses['n'] || $abandoned['n'] || (int) $original['license_id'] !== 31 || (int) $logs['n'] !== 3)
		{ throw new RuntimeException('Bulk removal lost an unrelated assignment or its admin history'); }
		foreach (array(9720=>array(21,1),9721=>array(23,1),9722=>array(21,0),9723=>array(21,0)) as $id=>$state)
		{
			$file = $row("SELECT * FROM {$p}files WHERE file_id=$id");
			if (!$file || (int) $file['license_id'] !== 0 || (int) $file['review_revision'] !== 5 || (int) $file['cat_id'] !== $state[0] ||
				(int) $file['approved'] !== $state[1] || (int) $file['pinned'] !== 1 || $file['owner_id'] !== $original['owner_id'] ||
				$file['storage_name'] !== $original['storage_name'] || $file['file_bytes'] !== $original['file_bytes'])
			{ throw new RuntimeException('Detaching a licence changed a file, its ownership or approval'); }
		}
		$db->sql_query("DELETE FROM {$p}visitors WHERE file_id IN(9720,9721,9722,9723)");
		$db->sql_query("DELETE FROM {$p}files WHERE file_id IN(9720,9721,9722,9723)");
		break;
	case 'agreements':
		$name = 'Probe &amp; Regeln 📜';
		$terms = "<p>Grüße <strong>aus dem Archiv</strong> 📜</p><ol><li>Text &lt;Beispiel&gt;</li></ol>\n" .
			'<table><tr><th colspan="2">Abschnitt</th></tr></table><a href="faq.php">Hinweise</a>';
		$insert('licenses',array('license_id'=>9610,'title'=>utf8_encode_ucr($name),'terms'=>utf8_encode_ucr($terms),
			'source_json'=>json_encode(array('license_name'=>$name,'license_text'=>$terms))));
		$file = $row("SELECT * FROM {$p}files WHERE file_id=50");
		$insert('files',array('file_id'=>9611,'license_id'=>9610,'downloads'=>0)+$file);
		$mirror = $row("SELECT * FROM {$p}mirrors WHERE mirror_id=8");
		$insert('mirrors',array('file_id'=>9611,'mirror_id'=>9612)+$mirror);
		break;
	case 'revised':
		$db->sql_query("UPDATE {$p}licenses SET " . $db->sql_build_array('UPDATE',array('terms'=>'<p>Neue Bedingungen</p>')) . ' WHERE license_id=9610');
		break;
	case 'unaccepted':
	case 'accepted':
		$file = $row("SELECT downloads FROM {$p}files WHERE file_id=9611");
		$visitors = $row("SELECT COUNT(*) AS n FROM {$p}visitors WHERE file_id=9611");
		$accepted = $argv[2] === 'accepted';
		if ((int) $file['downloads'] !== ($accepted ? 2 : 0) || (int) $visitors['n'] !== (int) $accepted)
		{ throw new RuntimeException('Licence confirmation counted an unseen revision or lost an accepted delivery'); }
		if ($accepted)
		{
			$db->sql_query("DELETE FROM {$p}visitors WHERE file_id=9611");
			$db->sql_query("DELETE FROM {$p}mirrors WHERE mirror_id=9612");
			$db->sql_query("DELETE FROM {$p}files WHERE file_id=9611");
			$db->sql_query("DELETE FROM {$p}licenses WHERE license_id=9610");
		}
		break;
	default: exit(2);
}
