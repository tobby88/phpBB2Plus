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
		fwrite(STDERR,"Incomplete download settings fixture $phase: " . ($error['message'] ?? 'interrupted') . "\n");
		exit(1);
	}
});
$p = $table_prefix . 'plus_dl_';
$rows = static function ($sql) use ($db) {
	$result = $db->sql_query($sql);
	if ($result === false) { throw new RuntimeException('The settings fixture query failed'); }
	$data = $db->sql_fetchrowset($result);
	$db->sql_freeresult($result);
	return $data;
};
$insert = static function ($table,$data) use ($db) {
	if (!$db->sql_query("INSERT INTO $table " . $db->sql_build_array('INSERT',$data))) { throw new RuntimeException('The settings fixture insert failed'); }
};
$settings = static function () use ($rows,$p) {
	return array_column($rows("SELECT config_name,config_value FROM {$p}settings ORDER BY config_name"),'config_value','config_name');
};
$grant_sql = "SELECT * FROM {$p}grants WHERE cat_id IN (22,23,24) ORDER BY row_id";
$lookup = 'SELECT * FROM ' . USERS_TABLE . " WHERE username='DownloadSettingsDelegate'";
switch ($phase)
{
	case 'installed':
	case 'uninstalled':
		$count = (int) ($phase === 'installed');
		if (count($rows('SELECT auth_option FROM ' . ACL_OPTIONS_TABLE . " WHERE auth_option='a_plus_dl_settings'"))!==$count ||
			count($rows('SELECT module_id FROM ' . MODULES_TABLE . " WHERE module_langname='ACP_PLUS_DL_SETTINGS' AND module_class='acp' AND module_mode='manage'"))!==$count ||
			!$rows('SELECT auth_option FROM ' . ACL_OPTIONS_TABLE . " WHERE auth_option='a_plus_dl_edit'"))
		{ throw new RuntimeException('The settings migration did not change just its native module and permission'); }
		break;
	case 'seed':
		require_once './includes/functions_user.php';
		require_once './includes/acp/auth.php';
		$state = $settings();
		$insert($p.'settings',array('config_name'=>'test_acp_settings_source','config_value'=>json_encode(array($state,$rows($grant_sql)))));
		$insert($p.'settings',array('config_name'=>'test_acp_unrelated','config_value'=>'Unrelated original setting'));
		$db->sql_query("UPDATE {$p}settings SET config_value='old/downloads/' WHERE config_name='upload_dir'");
		$db->sql_query("UPDATE {$p}settings SET config_value='historic_mode' WHERE config_name='validator'");
		$db->sql_query("UPDATE {$p}settings SET config_value='2000000' WHERE config_name='settings_newdays'");
		$db->sql_query("DELETE FROM {$p}settings WHERE config_name='no_comment_image_message'");
		$group = $rows('SELECT group_id FROM ' . GROUPS_TABLE . " WHERE group_name='REGISTERED'")[0];
		$pwd = $rows('SELECT user_password FROM ' . USERS_TABLE . " WHERE username='ModernMember'")[0];
		$actor = user_add(array('username'=>'DownloadSettingsDelegate','user_password'=>$pwd['user_password'],'group_id'=>(int) $group['group_id'],
			'user_type'=>USER_NORMAL,'user_email'=>'download-settings@example.invalid','user_lang'=>'en'));
		if (!$actor) { throw new RuntimeException('Could not create the settings-only delegate'); }
		(new auth_admin())->acl_set('user',0,$actor,array('a_plus_dl_settings'=>ACL_YES));
		$cat = $rows("SELECT * FROM {$p}categories WHERE cat_id=21")[0];
		$insert($p.'categories',array('cat_id'=>10630,'title'=>'Sort fixture')+$cat);
		$f = $rows("SELECT * FROM {$p}files WHERE file_id=50")[0];
		foreach (array(10641=>'Zulu oldest',10642=>'Alpha recent',10643=>'Pinned fixture') as $id=>$name)
		{
			$insert($p.'files',array('file_id'=>$id,'cat_id'=>10630,'title'=>$name,'pinned'=>(int) ($id===10643),'downloads'=>10643-$id,
				'added_at'=>time()-($id===10642 ? 300 : 10*86400),'updated_at'=>time()-($id===10641 ? 100 : 600))+$f);
		}
		$vote = $rows("SELECT * FROM {$p}votes WHERE file_id=50 ORDER BY row_id")[0];
		unset($vote['row_id']);
		foreach (array(10641=>6,10642=>9) as $id=>$points) { $insert($p.'votes',array('file_id'=>$id,'points'=>$points)+$vote); }
		break;
	case 'unchanged':
	case 'saved':
		$values = $settings();
		list($before,$grants) = json_decode($values['test_acp_settings_source'],true);
		if ($values['test_acp_unrelated']!=='Unrelated original setting' || $values['upload_dir']!=='old/downloads/' ||
			$rows($grant_sql)!==$grants || $values['validator']!=='historic_mode' || $values['tpl_php']!==$before['tpl_php'] ||
			($phase==='unchanged' && ($values['settings_dbname']!==$before['settings_dbname'] || isset($values['no_comment_image_message']) ||
				$values['settings_newdays']!=='2000000')) || ($phase==='saved' && ($values['forbidden_extensions']!=='abc, xyz' ||
				$values['auth_search']!=='1' || $values['max_file_size']!=='32' || $values['settings_newdays']!=='1' || $values['settings_topnumber']!=='1' ||
				\phpbb2plus\downloads\service\access::plain($values['no_comment_image_message'])!=='Archivbild & Hinweis 📷')))
		{ throw new RuntimeException('Changing settings lost a historic value or membership, introduced a missing default, or partially saved'); }
		break;
	case 'revoke':
		define('IN_ADMIN',true);
		require_once './includes/acp/auth.php';
		$subject = $rows($lookup)[0];
		$id = (int) $subject['user_id'];
		$session = $rows('SELECT session_id,session_admin FROM ' . SESSIONS_TABLE . " WHERE session_user_id=$id AND session_admin=1 ORDER BY session_time DESC")[0];
		$user->session_id = $session['session_id'];
		$user->data = $subject + $session + array('is_registered'=>true);
		$auth->acl($user->data);
		$access = $phpbb_container->get('phpbb2plus.downloads.access');
		if (!$access->settings_admin() || $access->admin() || $access->file_admin('edit') || $access->permissions_admin())
		{ throw new RuntimeException('The settings grant opened another administrative area'); }
		$store = $phpbb_container->get('phpbb2plus.downloads.settings');
		$view = $store->review();
		$values = $view['values'];
		$values['settings_disable'] = '0';
		(new auth_admin())->acl_set('user',0,$id,array('a_plus_dl_settings'=>ACL_NEVER,'a_plus_dl_licenses'=>ACL_YES));
		try { $store->save($view['state'],$values); }
		catch (DomainException $e) { if ($e->getMessage()==='NO_AUTH_OPERATION') { break; } throw $e; }
		throw new RuntimeException('A stale settings-only ACP role changed the catalogue');
	case 'restore':
		$values = $settings();
		list($before,$grants) = json_decode($values['test_acp_settings_source'],true);
		if ($values['settings_disable']!=='1' || $rows($grant_sql)!==$grants ||
			!$rows('SELECT log_id FROM ' . LOG_TABLE . " WHERE log_operation='LOG_PLUS_DL_SETTINGS'"))
		{ throw new RuntimeException('The closed library lost its settings, independent grants or audit history'); }
		$changed = array_merge(array_keys(\phpbb2plus\downloads\service\settings::DEFAULTS),array('upload_dir','test_acp_unrelated','test_acp_settings_source'));
		$db->sql_transaction('begin');
		$db->sql_query("DELETE FROM {$p}settings WHERE " . $db->sql_in_set('config_name',$changed));
		foreach (array_intersect_key($before,array_fill_keys($changed,true)) as $key=>$value)
		{ $insert($p.'settings',array('config_name'=>$key,'config_value'=>$value)); }
		$db->sql_query("DELETE FROM {$p}votes WHERE file_id IN (10641,10642)");
		$db->sql_query("DELETE FROM {$p}files WHERE file_id IN (10641,10642,10643)");
		$db->sql_query("DELETE FROM {$p}categories WHERE cat_id=10630");
		$db->sql_transaction('commit');
		require_once './includes/functions_user.php';
		user_delete('retain',(int) $rows($lookup)[0]['user_id']);
		break;
	default: exit(2);
}
$complete = true;
