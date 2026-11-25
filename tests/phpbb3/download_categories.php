<?php
// Category grants and content used only by the temporary local migration fixture.
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
$insert = static function ($table,$data) use ($db,$p) {
	$db->sql_query('INSERT INTO ' . $p . $table . ' ' . $db->sql_build_array('INSERT',$data));
};
$file = $row("SELECT * FROM {$p}files WHERE file_id=50");
$dir = $phpbb_root_path . $config['upload_path'] . '/';
switch ($argv[2])
{
	case 'installed':
	case 'uninstalled':
		$expected = $argv[2] === 'installed' ? 1 : 0;
		$permission = $row('SELECT COUNT(*) AS n FROM ' . ACL_OPTIONS_TABLE . " WHERE auth_option='a_plus_dl_categories'");
		$module = $row('SELECT COUNT(*) AS n FROM ' . MODULES_TABLE . " WHERE module_class='acp' AND module_langname='ACP_PLUS_DL_CATEGORIES' AND module_mode='manage'");
		if ((int) $permission['n'] !== $expected || (int) $module['n'] !== $expected)
		{ throw new RuntimeException('Category migration did not install or remove its module and narrow permission exactly once'); }
		break;
	case 'seed':
		$cat = $row("SELECT * FROM {$p}categories WHERE cat_id=21");
		$grant = $row("SELECT * FROM {$p}grants WHERE cat_id=22 AND subject_type='g'");
		unset($grant['row_id']);
		$parents = array(9101=>0,9102=>9101,9103=>9102,9104=>0,9105=>0,9106=>9105,9107=>0,9108=>9107);
		foreach ($parents as $id=>$parent)
		{
			$levels = json_decode($cat['access_levels'],true);
			$is_private = in_array($id,array(9101,9102),true);
			$levels['view'] = $levels['read'] = $is_private ? 2 : 0;
			$insert('categories',array('cat_id'=>$id,'parent_id'=>$parent,'title'=>'Archivordner ' . $id,'position'=>$id,
				'access_levels'=>json_encode($levels),'allow_files'=>1) + $cat);
			if ($is_private) { $insert('grants',array('cat_id'=>$id)+$grant); }
			$insert('files',array('file_id'=>$id+100,'cat_id'=>$id,'approved'=>$id === 9102 ? 0 : 1,'pinned'=>1,
				'downloads'=>8,'review_revision'=>0)+$file);
		}
		// A dedicated binary and shared originals exercise both branches of the same cleanup.
		$asset = 'download_' . hash('sha256','paFileDB disposable category media');
		if (file_exists($dir . $asset) || file_put_contents($dir . $asset,'Synthetic category file') !== 23)
		{ throw new RuntimeException('Could not seed isolated download category media'); }
		$db->sql_query("UPDATE {$p}files SET storage_name='$asset',file_bytes=23 WHERE file_id=9205");
		$mirror = $row("SELECT * FROM {$p}mirrors WHERE mirror_id=8");
		$insert('mirrors',array('mirror_id'=>9241,'file_id'=>9205)+$mirror);
		foreach (array('comments'=>'comment_id','votes'=>'row_id','visitors'=>'row_id','field_values'=>'row_id') as $name=>$key)
		{
			$related = $row("SELECT * FROM $p$name WHERE file_id=50 ORDER BY $key LIMIT 1");
			if (!$related) { throw new RuntimeException('Missing synthetic child row for ' . $name); }
			unset($related[$key]);
			$insert($name,array('file_id'=>9205)+$related);
		}
		break;
	case 'additional':
		$insert('files',array('file_id'=>9299,'cat_id'=>9101,'approved'=>0)+$file);
		break;
	case 'discussion':
		$comment = $row("SELECT * FROM {$p}comments WHERE file_id=50 ORDER BY comment_id LIMIT 1");
		unset($comment['comment_id']);
		$insert('comments',array('file_id'=>9205)+$comment);
		break;
	case 'moved':
		$child = $row("SELECT parent_id FROM {$p}categories WHERE cat_id=9102");
		$desc = $row("SELECT parent_id FROM {$p}categories WHERE cat_id=9103");
		$kept = $row("SELECT cat_id,approved,owner_id,downloads,pinned,review_revision FROM {$p}files WHERE file_id=9201");
		$grants = $row("SELECT COUNT(*) AS n FROM {$p}grants WHERE cat_id=9102");
		$old = $row("SELECT COUNT(*) AS n FROM {$p}grants WHERE cat_id=9101");
		if (!$child || (int) $child['parent_id'] !== 9104 || (int) $desc['parent_id'] !== 9102 || !$kept || (int) $kept['cat_id'] !== 9104 ||
			(int) $kept['approved'] !== 1 || (int) $kept['owner_id'] !== (int) $file['owner_id'] || (int) $kept['downloads'] !== 8 ||
			(int) $kept['pinned'] !== 1 || (int) $kept['review_revision'] !== 1 || (int) $grants['n'] !== 1 || (int) $old['n'])
		{ throw new RuntimeException('Moving categories and files lost a grant, subtree, owner or file state'); }
		break;
	case 'complete':
		$count = $row("SELECT COUNT(*) AS n FROM {$p}categories WHERE cat_id IN(9101,9102,9103,9105,9106,9107)");
		$child = $row("SELECT parent_id FROM {$p}categories WHERE cat_id=9108");
		$moved = $row("SELECT COUNT(*) AS n FROM {$p}files WHERE cat_id=9104 AND file_id IN(9201,9202,9203,9204,9299)");
		$pending = $row("SELECT approved FROM {$p}files WHERE file_id=9202");
		$grants = $row("SELECT COUNT(*) AS n FROM {$p}grants WHERE cat_id IN(9101,9102)");
		if ($count['n'] || !$child || (int) $child['parent_id'] !== 0 || (int) $moved['n'] !== 5 || !$pending || (int) $pending['approved'] || $grants['n'])
		{ throw new RuntimeException('A category removal lost its destination files, pending status or surviving children'); }
		foreach (array('files','mirrors','comments','votes','visitors','field_values') as $name)
		{
			$count = $row("SELECT COUNT(*) AS n FROM $p$name WHERE file_id IN(9205,9206,9207)");
			if ($count['n']) { throw new RuntimeException('A removed download still has ' . $name); }
		}
		$db->sql_query("UPDATE {$p}media_queue SET queued_at=0");
		$phpbb_container->get('phpbb2plus.downloads.cleanup')->run();
		if (file_exists($dir . 'download_' . hash('sha256','paFileDB disposable category media')) || !is_file($dir . $file['storage_name']) ||
			!is_file($dir . $file['preview_name'])) { throw new RuntimeException('Removing a category mishandled unique or shared media'); }
		break;
	case 'roles':
		require_once './includes/acp/auth.php';
		require_once './includes/functions_module.php';
		$account = $row('SELECT * FROM ' . USERS_TABLE . " WHERE username='DepartingFixture'");
		$option = $row('SELECT auth_option_id FROM ' . ACL_OPTIONS_TABLE . " WHERE auth_option='a_plus_dl_categories'");
		$id = (int) $account['user_id'];
		if (!$option || !$id) { throw new RuntimeException('Missing category role fixture'); }
		$db->sql_transaction('begin');
		try
		{
			$writer = new auth_admin();
			$writer->acl_set('user',0,$id,array('a_plus_dl_categories'=>ACL_YES));
			$auth->acl_clear_prefetch($id);
			$user->data = $row('SELECT * FROM ' . USERS_TABLE . " WHERE user_id=$id") + array('is_registered'=>true);
			$user->data['session_admin'] = 1;
			$auth->acl($user->data);
			define('IN_ADMIN',true);
			$module = (new \phpbb2plus\downloads\acp\categories_info())->module()['modes']['manage']['auth'];
			if (!p_master::module_auth($module,0) || !$phpbb_container->get('phpbb2plus.downloads.access')->catalogue_admin() ||
				$phpbb_container->get('phpbb2plus.downloads.access')->admin()) { throw new RuntimeException('Category delegation granted the wrong download powers'); }
		}
		finally { $db->sql_transaction('rollback'); $auth->acl_clear_prefetch($id); }
		break;
	default: exit(2);
}
