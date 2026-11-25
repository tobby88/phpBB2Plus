<?php
// Dedicated inputs for the real ACP on a converted, disposable loopback board.
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
	$item = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);
	return $item;
};
$insert = static function ($table,$data) use ($db) { $db->sql_query("INSERT INTO $table " . $db->sql_build_array('INSERT',$data)); };
$delegate = $row('SELECT user_id FROM ' . USERS_TABLE . " WHERE username='DownloadGrantDelegate'");
$applicant = $row('SELECT user_id FROM ' . USERS_TABLE . " WHERE username='DepartingFixture'");
switch ($argv[2])
{
	case 'installed':
	case 'uninstalled':
		$expected = $argv[2] === 'installed' ? 1 : 0;
		$option = $row('SELECT COUNT(*) AS n FROM ' . ACL_OPTIONS_TABLE . " WHERE auth_option='a_plus_dl_perms'");
		$module = $row('SELECT COUNT(*) AS n FROM ' . MODULES_TABLE . " WHERE module_class='acp' AND module_langname='ACP_PLUS_DL_PERMISSIONS' AND module_mode='manage'");
		if ((int) $option['n'] !== $expected || (int) $module['n'] !== $expected)
		{ throw new RuntimeException('Access migration did not install/remove exactly one permission and module'); }
		break;
	case 'seed':
		require_once './includes/functions_user.php';
		require_once './includes/acp/auth.php';
		if ($delegate || !$applicant) { throw new RuntimeException('Unexpected initial download ACL fixture'); }
		if (\phpbb2plus\downloads\service\access::level('unspecified') !== null ||
			\phpbb2plus\downloads\service\access::flags('{"note":"archived"}') !== null ||
			\phpbb2plus\downloads\service\access::flags('["view",null]') !== null)
		{ throw new RuntimeException('An unreadable historical permission was accepted as a current access level or grant'); }
		$sample = $row("SELECT * FROM {$p}categories WHERE cat_id=21");
		$original = json_decode($sample['access_levels'],true);
		foreach (array(9401=>array(0,2,2),9402=>array(0,5,5),9403=>array(9402,0,0),9410=>array(0,0,0)) as $id=>$scope)
		{
			list($parent,$view,$read) = $scope;
			$levels = array('view'=>$view,'read'=>$read,'download'=>2,'upload'=>3,'custom_future_column'=>77) + $original;
			$insert($p . 'categories',array('cat_id'=>$id,'title'=>'ACL-Kategorie ' . $id,'position'=>$id,'parent_id'=>$parent,
				'access_levels'=>json_encode($levels))+$sample);
		}
		foreach (array(array('view','read','download','unknown_legacy_grant'),array('download','view','email')) as $n=>$flags)
		{
			$insert($p . 'grants',array('cat_id'=>9401,'legacy_group'=>71,'subject_type'=>'g','subject_id'=>71,
				'allowed'=>json_encode($flags),'source_json'=>json_encode(array('legacy_row'=>$n))));
		}
		// Preserve the baseline settings, including when run alongside the other converted workflows.
		$prior = $row("SELECT config_value FROM {$p}settings WHERE config_name='auth_search'");
		$insert($p . 'settings',array('config_name'=>'test_prior_search_level') + $prior);
		$db->sql_query("UPDATE {$p}settings SET config_value='2' WHERE config_name='auth_search'");
		$pwd = $row('SELECT user_password FROM ' . USERS_TABLE . " WHERE username='ModernMember'");
		$group = $row('SELECT group_id FROM ' . GROUPS_TABLE . " WHERE group_name='REGISTERED'");
		$id = user_add(array('username'=>'DownloadGrantDelegate','user_password'=>$pwd['user_password'],'group_id'=>(int) $group['group_id'],
			'user_type'=>USER_NORMAL,'user_email'=>'download-delegate@example.invalid','user_lang'=>'de'));
		if (!$id) { throw new RuntimeException('Unable to create the disposable download delegate'); }
		(new auth_admin())->acl_set('user',0,$id,array('a_plus_dl_perms'=>ACL_YES,'a_board'=>ACL_YES));
		break;
	case 'duplicates':
		$result = $db->sql_query("SELECT legacy_group,allowed,source_json FROM {$p}grants WHERE cat_id=9401 AND subject_type='g' AND subject_id=71 ORDER BY row_id");
		$rows = $db->sql_fetchrowset($result);
		$db->sql_freeresult($result);
		if (count($rows) !== 2) { throw new RuntimeException('Editing a grant lost a historical source row'); }
		foreach ($rows as $n=>$item)
		{
			$expected = $n ? array('download','email') : array('read','download','unknown_legacy_grant');
			if (json_decode($item['allowed'],true) !== $expected || (int) $item['legacy_group'] !== 71 || json_decode($item['source_json'],true) !== array('legacy_row'=>$n))
			{ throw new RuntimeException('A duplicate retained the revoked flag or lost an unrelated flag or original metadata'); }
		}
		break;
	case 'revoke':
		require_once './includes/acp/auth.php';
		define('IN_ADMIN',true);
		$actor = (int) $delegate['user_id'];
		$session = $row('SELECT session_id,session_admin FROM ' . SESSIONS_TABLE . " WHERE session_user_id=$actor AND session_admin=1 ORDER BY session_time DESC");
		$user->data = $row('SELECT * FROM ' . USERS_TABLE . " WHERE user_id=$actor") + $session + array('is_registered'=>true);
		$user->session_id = $session['session_id'];
		$auth->acl($user->data);
		$manager = $phpbb_container->get('phpbb2plus.downloads.permissions');
		$view = $manager->review(9410,'',0);
		$values = array_map('strval',$view['levels']);
		$values['read'] = '1';
		(new auth_admin())->acl_set('user',0,(int) $delegate['user_id'],array('a_plus_dl_perms'=>ACL_NO,'a_board'=>ACL_YES));
		try { $manager->save(9410,'',0,$view['state'],$values); }
		catch (DomainException $e)
		{
			if ($e->getMessage() === 'NO_AUTH_OPERATION') { break; }
			throw $e;
		}
		throw new RuntimeException('A stale in-memory ACP grant authorized a category rights change');
	case 'complete':
		$public = $row("SELECT access_levels FROM {$p}categories WHERE cat_id=9410");
		$selected = $row("SELECT access_levels FROM {$p}categories WHERE cat_id=9401");
		$chosen = json_decode($selected['access_levels'],true);
		$grant = $row("SELECT COUNT(*) AS n FROM {$p}grants WHERE cat_id IN(0,9401) AND subject_type='u' AND subject_id=" . (int) $applicant['user_id']);
		$log = $row('SELECT COUNT(DISTINCT log_operation) AS n FROM ' . LOG_TABLE . " WHERE log_operation IN ('LOG_PLUS_DL_PERMS_USER','LOG_PLUS_DL_PERMS_GROUP','LOG_PLUS_DL_PERMS_LEVELS')");
		if (json_decode($public['access_levels'],true)['read'] !== 0 || $chosen['upload'] !== 1 ||
			$chosen['custom_future_column'] !== 77 || $grant['n'] || (int) $log['n'] !== 3)
		{ throw new RuntimeException('A revoked right, retained personal grant, category policy or missing audit entry broke the ACL workflow'); }
		$prior = $row("SELECT config_value FROM {$p}settings WHERE config_name='test_prior_search_level'");
		$db->sql_query("UPDATE {$p}settings SET " . $db->sql_build_array('UPDATE',$prior) . " WHERE config_name='auth_search'");
		$db->sql_query("DELETE FROM {$p}settings WHERE config_name='test_prior_search_level'");
		$db->sql_query("DELETE FROM {$p}grants WHERE cat_id IN(9401,9402,9403,9410)");
		$db->sql_query("DELETE FROM {$p}categories WHERE cat_id IN(9401,9402,9403,9410)");
		require_once './includes/functions_user.php';
		$user->session_begin();
		$user->setup();
		$disposable = $row('SELECT * FROM ' . GROUPS_TABLE . ' WHERE group_id=71');
		$insert(GROUPS_TABLE,array('group_id'=>9490,'group_name'=>'Disposable download group')+$disposable);
		$insert($p . 'grants',array('cat_id'=>0,'legacy_group'=>0,'subject_type'=>'g','subject_id'=>9490,
			'allowed'=>'["search"]','source_json'=>'{}'));
		group_delete(9490,'Disposable download group');
		if ($row("SELECT row_id FROM {$p}grants WHERE subject_type='g' AND subject_id=9490"))
		{ throw new RuntimeException('A deleted group retained an active download grant'); }
		user_delete('retain',(int) $delegate['user_id']);
		break;
	default: exit(2);
}
