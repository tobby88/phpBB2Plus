<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 || !preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './'; $phpEx = 'php'; require './common.php';
if ($dbname !== $argv[1] || $dbhost !== '127.0.0.1') { exit(2); }
require_once './includes/functions_user.php'; require_once './includes/acp/auth.php';
if ($argv[2] === 'prepare' || $argv[2] === 'clean')
{
	$user->session_begin(); $user->setup(); $auth->acl($user->data);
}
$rows = static function ($sql) use ($db) {
	$r=$db->sql_query($sql); $rows=$db->sql_fetchrowset($r); $db->sql_freeresult($r); return $rows;
};
$phase = $argv[2]; $key = 'plus_test_member_operations'; $text = $phpbb_container->get('config_text');
$s = json_decode((string) $text->get($key), true); $acl = new auth_admin();
if ($phase === 'older' || $phase === 'upgraded')
{
	$present = $rows('SELECT module_mode FROM ' . MODULES_TABLE . " WHERE module_basename='" .
		$db->sql_escape('\phpbb2plus\community\acp\member_directory_module') . "' AND module_mode='batch'");
	if ((bool) $present !== ($phase === 'upgraded')) { throw new RuntimeException('Member operations module upgrade failed'); }
	if ($phase === 'older')
	{
		$db->sql_query('INSERT INTO ' . BANLIST_TABLE . ' ' . $db->sql_build_array('INSERT', array('ban_userid'=>ANONYMOUS,'ban_reason'=>'Local pre-upgrade marker','ban_give_reason'=>'')));
	}
	else
	{
		$ban = $rows('SELECT plus_dir_ban,ban_userid FROM ' . BANLIST_TABLE . " WHERE ban_reason='Local pre-upgrade marker'");
		if (count($ban) !== 1 || (int) $ban[0]['plus_dir_ban'] || (int) $ban[0]['ban_userid'] !== ANONYMOUS) { throw new RuntimeException('Existing native ban changed at upgrade'); }
		$db->sql_query('DELETE FROM ' . BANLIST_TABLE . " WHERE ban_reason='Local pre-upgrade marker'");
	}
}
elseif ($phase === 'source-limits')
{
	require_once '../finished-installer/convertors/plus_junior_admin.php';
	require_once '../finished-installer/convertors/plus_media.php';
	$root = realpath('../legacy');
	if (!$root || !is_file($root . '/staff/admin_users_list.php')) { throw new RuntimeException('Missing local synthetic legacy source'); }
	$convert = (object) array('options'=>array('forum_path'=>$root));
	$writer = $root . '/includes/functions_userlist_storage.php';
	$original = file_get_contents($writer);
	try
	{
		file_put_contents($writer, $original . "\n/* Synthetic installation with a changed batch writer */\n");
		$registry = plus_jr_registry();
		$member = $registry[md5('UsersUsers Listadmin_users_list.php')];
		if (($member['option'] ?? '') !== 'a_plus_user_list' || isset($member['options']) ||
			($member['unmapped_bulk_actions'] ?? array()) !== array('activate','deactivate','ban','unban','group') ||
			$registry[md5('UsersEmail_Listadmin_email_list.php')]['option'] !== 'a_plus_mail_list')
		{ throw new RuntimeException('An unknown writer changed the two read scopes or conferred mutation rights'); }
	}
	finally { file_put_contents($writer, $original); }
}
elseif ($phase === 'prepare')
{
	if ($s) { throw new RuntimeException('A user-list batch fixture is already active'); }
	$s['actors'] = array_map('intval', array_column($rows('SELECT user_id,username FROM ' . USERS_TABLE .
		" WHERE username IN ('MemberDirectoryJunior','EmailDirectoryJunior','ModernMember')"), 'user_id', 'username'));
	$s['group'] = (int) $rows('SELECT group_id FROM ' . GROUPS_TABLE . " WHERE plus_legacy_group=1 AND group_name='Archive Club'")[0]['group_id'];
	$s['ordinary'] = (int) $rows('SELECT group_id FROM ' . GROUPS_TABLE . " WHERE group_type=" . GROUP_SPECIAL . " AND group_name='REGISTERED'")[0]['group_id'];
	foreach (array('newest_user_id','newest_username','newest_user_colour','email_enable') as $name) { $s['restore'][$name]=$config[$name]; }
	$config->set('email_enable', 0, false);
	foreach (array('One','Two','Original','Restricted','Exception','Pending','Admin','Mixed','Inactive') as $name)
	{
		$id = user_add(array('username'=>'BatchFixture'.$name, 'user_type'=>$name === 'Inactive' ? USER_INACTIVE : USER_NORMAL,
			'group_id'=>$s['ordinary'], 'user_email'=>strtolower($name).'@batchfixture.invalid', 'user_warnings'=>2, 'plus_card_count'=>4));
		if (!$id) { throw new RuntimeException('Cannot create a local batch member'); }
		$s['users'][$name]=(int)$id;
	}
	$acl->acl_set('user', 0, $s['users']['Admin'], array('a_user'=>ACL_YES));
	foreach (array(array('Original', 0, 0, 1, '', ''), array('Restricted', time()+86400, 0, 0, '', ''),
		array('Exception', 0, 1, 0, '', ''), array('Mixed', 0, 0, 0, '192.0.2.112', ''), array('Mixed', 0, 0, 1, '', ''),
		array('One', time()-3600, 0, 0, '', ''), array('Two',0,0,0,'','fixture@elsewhere.invalid')) as $b)
	{
		$item = array('ban_userid'=>$s['users'][$b[0]], 'ban_end'=>$b[1], 'ban_exclude'=>$b[2], 'plus_card_legacy'=>$b[3],
			'ban_ip'=>$b[4], 'ban_email'=>$b[5], 'ban_start'=>10, 'ban_reason'=>'Local batch rule '.$b[0], 'ban_give_reason'=>'');
		$db->sql_query('INSERT INTO ' . BANLIST_TABLE . ' ' . $db->sql_build_array('INSERT',$item));
	}
	if (group_user_add($s['group'], array($s['users']['Pending']), false, 'Archive Club', false, 0, 1))
	{ throw new RuntimeException('Could not create a pending group membership'); }
	$text->set($key,json_encode($s, JSON_THROW_ON_ERROR)); echo json_encode($s, JSON_THROW_ON_ERROR);
}
elseif (!$s) { throw new RuntimeException('Missing batch fixture'); }
elseif ($phase === 'state')
{
	$id_set=$db->sql_in_set('user_id',$s['users']);
	$data=array('users'=>array_column($rows('SELECT user_id,user_type,plus_card_count,user_warnings FROM ' . USERS_TABLE . ' WHERE '.$id_set),null,'user_id'),
		'bans'=>$rows('SELECT ban_userid,ban_ip,ban_email,ban_end,ban_exclude,ban_reason,plus_dir_ban,plus_card_legacy FROM ' .
			BANLIST_TABLE . ' WHERE ' . $db->sql_in_set('ban_userid',$s['users']) . ' ORDER BY ban_id'),
		'members'=>array_column($rows('SELECT user_id,user_pending FROM ' . USER_GROUP_TABLE . ' WHERE group_id='.$s['group'].' AND '.$id_set),'user_pending','user_id'));
	echo json_encode($data,JSON_THROW_ON_ERROR);
}
elseif ($phase === 'restricted') { $acl->acl_set('user',0,$s['users']['One'],array('a_user'=>ACL_YES)); }
elseif ($phase === 'ordinary') { $acl->acl_set('user',0,$s['users']['One'],array('a_user'=>ACL_NO)); }
elseif ($phase === 'deny-ban') { $acl->acl_set('user',0,$s['actors']['MemberDirectoryJunior'],array('a_plus_list_bans'=>ACL_NEVER)); }
elseif ($phase === 'allow-ban') { $acl->acl_set('user',0,$s['actors']['MemberDirectoryJunior'],array('a_plus_list_bans'=>ACL_NO)); }
elseif ($phase === 'native') { $acl->acl_set('user',0,$s['actors']['EmailDirectoryJunior'],array('a_plus_user_list'=>ACL_YES,'a_user'=>ACL_YES)); }
elseif ($phase === 'group-protect' || $phase === 'group-restore') { $db->sql_query('UPDATE '.GROUPS_TABLE.' SET group_founder_manage=' . (int)($phase==='group-protect') . ' WHERE group_id='.$s['group']); }
elseif ($phase === 'clean')
{
	$acl->acl_set('user',0,$s['actors']['MemberDirectoryJunior'],array('a_plus_list_bans'=>ACL_NO));
	$acl->acl_set('user',0,$s['actors']['EmailDirectoryJunior'],array('a_plus_user_list'=>ACL_NO,'a_user'=>ACL_NO));
	$db->sql_query('UPDATE '.GROUPS_TABLE.' SET group_founder_manage=0 WHERE group_id='.$s['group']);
	$ids=array_column($rows('SELECT user_id FROM '.USERS_TABLE." WHERE user_email LIKE '%@batchfixture.invalid' AND ".$db->sql_in_set('user_id',$s['users'])),'user_id');
	if (count($ids)!==count($s['users'])) { throw new RuntimeException('A batch operation removed an account'); }
	$db->sql_query('DELETE FROM '.BANLIST_TABLE.' WHERE '.$db->sql_in_set('ban_userid',$ids));
	user_delete('retain',$ids,true);
	$db->sql_query('DELETE FROM '.$table_prefix.'plus_former_names WHERE '.$db->sql_in_set('native_id',$ids));
	foreach ($s['restore'] as $name=>$value) { $config->set($name,$value,false); }
	$text->delete($key);
}
else { throw new RuntimeException('Unknown member batch fixture phase'); }
