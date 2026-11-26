<?php
// Check converted native identities and Attachment MOD storage allowances on the disposable fixture.
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 || !preg_match('/^plus3_test_[a-f0-9]{24}$/D',$argv[1])) { exit(2); }
define('IN_PHPBB',true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
function quota_fixture_scalar($sql,$name)
{
	global $db;
	$r = $db->sql_query($sql);
	$value = $db->sql_fetchfield($name);
	$db->sql_freeresult($r);
	return $value;
}
$id = (int) quota_fixture_scalar("SELECT user_id FROM " . USERS_TABLE . " WHERE username='HistoricMember'",'user_id');
if (!$id || $id === ANONYMOUS) { throw new RuntimeException('Missing converted quota fixture member'); }
$policy = $phpbb_container->get('phpbb2plus.community.attachment_quotas');
if ($argv[2] === 'before')
{
	foreach (array(array(0,0,1,1,false,false,false),array(0,1,0,1,false,false,false),
		array(0,1,1,0,false,true,false),array(1,0,1,1,true,false,true)) as $settings)
	{
		list($posts,$pm,$messages,$limits,$post_help,$pm_help,$quota_help) = $settings;
		$settings = new \phpbb\config\config(array('allow_attachments'=>$posts,'allow_pm_attach'=>$pm,
			'allow_privmsg'=>$messages,'plus_attach_quotas'=>$limits));
		$help = new \phpbb2plus\community\event\attachment_help($settings, $phpbb_container->get('language'));
		$event = new \phpbb\event\data(array('block_name'=>'HELP_FAQ_BLOCK_ATTACHMENTS','questions'=>array('native'=>'native_answer')));
		$help->extend_attachments($event);
		$questions = $event['questions'];
		if ($questions['native'] !== 'native_answer' || isset($questions['PLUS_HELP_FILE_EDIT']) !== $post_help
			|| isset($questions['PLUS_HELP_FILE_PM']) !== $pm_help || isset($questions['PLUS_HELP_FILE_QUOTA']) !== $quota_help)
		{ throw new RuntimeException('The attachment FAQ ignored board settings or removed a native question'); }
	}
}
$p = $table_prefix . 'plus_attach_';
if ($argv[2] === 'before')
{
	$orphan_owner = 'SELECT poster_id FROM ' . ATTACHMENTS_TABLE . ' WHERE attach_id=80 AND is_orphan=1';
	if ((string) quota_fixture_scalar($orphan_owner, 'poster_id') !== '0') { throw new RuntimeException('An unassociated source file acquired a guest owner'); }
	$db->sql_transaction('begin');
	try
	{
		$upgrade = new \phpbb2plus\community\migrations\v129_unowned_attachments($config, $db, $phpbb_container->get('dbal.tools'), $phpbb_root_path, $phpEx, $table_prefix);
		$db->sql_query('UPDATE ' . ATTACHMENTS_TABLE . ' SET poster_id=' . ANONYMOUS . ' WHERE attach_id=80');
		$upgrade->clear_guest_owner();
		$upgrade->clear_guest_owner();
		if ((string) quota_fixture_scalar($orphan_owner, 'poster_id') !== '0') { throw new RuntimeException('The earlier converted orphan still has a guest owner'); }
		$db->sql_query('UPDATE ' . ATTACHMENTS_TABLE . ' SET poster_id=' . $id . ' WHERE attach_id=80');
		$upgrade->clear_guest_owner();
		if ((int) quota_fixture_scalar($orphan_owner, 'poster_id') !== $id) { throw new RuntimeException('The upgrade removed an assigned owner'); }
		$db->sql_query('UPDATE ' . ATTACHMENTS_TABLE . " SET poster_id=" . ANONYMOUS . ",physical_filename='native_fixture_file' WHERE attach_id=80");
		$upgrade->clear_guest_owner();
		if ((int) quota_fixture_scalar($orphan_owner, 'poster_id') !== ANONYMOUS) { throw new RuntimeException('The upgrade changed an unrelated native file'); }
	}
	finally { $db->sql_transaction('rollback'); }
	if ((string) $config['plus_attach_base_pm'] !== '257' || (string) $config['max_filesize_pm'] !== '1048576' ||
		(string) $config['max_filesize'] !== '1048576') { throw new RuntimeException('The converter confused mailbox capacity and maximum individual file size'); }
	if (!$policy->enabled() || $policy->allowance($id,false) !== '50' || $policy->allowance($id,true) !== '300' ||
		$policy->allowance(87,false) !== '262144' || $policy->allowance(87,true) !== '50' ||
		$policy->allowance(2,true) !== '0' || $policy->allowance(2,false) !== '0')
	{
		throw new RuntimeException('User, group, default or administrator attachment allowance changed');
	}
	if (quota_fixture_scalar("SELECT bytes FROM {$p}limits WHERE limit_id=12",'bytes') !== '18446744073709551615.00' ||
		(int) quota_fixture_scalar("SELECT COUNT(*) AS n FROM {$p}quotas WHERE (source_user=999 AND user_id=0) OR (source_group=72 AND group_id=0)",'n') !== 2)
	{
		throw new RuntimeException('A quota lost precision or an old identity gained a native assignment');
	}
	$gif = quota_fixture_scalar('SELECT filesize FROM ' . ATTACHMENTS_TABLE . ' WHERE attach_id=50','filesize');
	foreach (array($id=>true,87=>false) as $who=>$attach)
	{
		$r = $db->sql_query('SELECT * FROM ' . USERS_TABLE . ' WHERE user_id=' . (int) $who);
		$data = $db->sql_fetchrow($r); $db->sql_freeresult($r);
		$auth->acl($data);
		if ((bool) $auth->acl_get('f_attach',14) !== $attach || !$auth->acl_get('f_download',14))
		{
			throw new RuntimeException('Converted upload and download permissions or pending memberships were conflated');
		}
	}
	$roles = quota_fixture_scalar('SELECT COUNT(*) AS n FROM ' . ACL_ROLES_TABLE . ' r JOIN ' . ACL_ROLES_DATA_TABLE .
		' d ON d.role_id=r.role_id JOIN ' . ACL_OPTIONS_TABLE . " o ON o.auth_option_id=d.auth_option_id
		WHERE r.role_name='ROLE_FORUM_FULL' AND o.auth_option IN ('f_download','f_attach') AND d.auth_setting=" . ACL_YES,'n');
	if ((int) $roles !== 2) { throw new RuntimeException('Converting local rights removed options from the reusable native Full Access role'); }
	$png = quota_fixture_scalar('SELECT filesize FROM ' . ATTACHMENTS_TABLE . ' WHERE attach_id=63','filesize');
	if ($policy->occupied(ANONYMOUS,false) !== (string) $gif || $policy->occupied(2,false) !== (string) $gif ||
		$policy->occupied($id,false) !== '0' || $policy->occupied(2,true) !== (string) $png || $policy->occupied($id,true,true) !== (string) $png)
	{
		throw new RuntimeException('A shared file, old sent box or orphan changed attachment usage');
	}
	// Enable actual group delivery for the two synthetic PM recipient groups.
	require_once './includes/acp/auth.php';
	$writer = new auth_admin();
	$writer->acl_set('user',0,2,array('u_masspm'=>ACL_YES,'u_masspm_group'=>ACL_YES));
	$config->set('allow_mass_pm',1);
	$db->sql_query('UPDATE ' . GROUPS_TABLE . ' SET group_receive_pm=1 WHERE group_id IN (71,74)');
	$auth->acl_clear_prefetch();
}
elseif ($argv[2] === 'after')
{
	if ((int) quota_fixture_scalar("SELECT COUNT(*) AS n FROM {$p}pending",'n') !== 0 ||
			(int) quota_fixture_scalar("SELECT COUNT(*) AS n FROM " . ATTACHMENTS_TABLE . " WHERE real_filename='Quota-second.gif'",'n') !== 0 ||
			(int) quota_fixture_scalar("SELECT COUNT(*) AS n FROM " . ATTACHMENTS_TABLE . " WHERE real_filename='Quota-replacement.gif'",'n') !== 1)
	{
		throw new RuntimeException('A rejected or removed attachment consumed storage');
	}
}
elseif ($argv[2] === 'messages')
{
	$result = $db->sql_query("SELECT msg_id FROM " . PRIVMSGS_TABLE . " WHERE message_subject='Anhang mit Kontingent'");
	$messages = array_column($db->sql_fetchrowset($result),'msg_id');
	$db->sql_freeresult($result);
	if (count($messages) !== 1) { throw new RuntimeException('The recipient quota lost or duplicated a delivery'); }
	$message = (int) $messages[0];
	$recipients = quota_fixture_scalar('SELECT COUNT(*) AS n FROM ' . PRIVMSGS_TO_TABLE . " WHERE msg_id=$message AND user_id=87",'n');
	$valid = quota_fixture_scalar('SELECT COUNT(*) AS n FROM ' . PRIVMSGS_TO_TABLE . " WHERE msg_id=$message AND user_id=$id",'n');
	$blind = quota_fixture_scalar('SELECT bcc_address FROM ' . PRIVMSGS_TABLE . " WHERE msg_id=$message",'bcc_address');
	$bytes = (int) quota_fixture_scalar('SELECT filesize FROM ' . ATTACHMENTS_TABLE . " WHERE post_msg_id=$message AND in_message=1 AND is_orphan=0",'filesize');
	if ($recipients || (int) $valid !== 1 || $blind !== 'g_71' || !$bytes || $policy->occupied($id,true,true) !== (string) ($bytes*2))
	{
		throw new RuntimeException('Private attachment usage diverged from the actual delivery');
	}
}
elseif ($argv[2] === 'assigned')
{
	if ($policy->allowance(87,false) !== '128' || $policy->allowance(87,true) !== '128' ||
		(string) $config['plus_attach_base_pm'] !== '4096' || (string) $config['max_filesize_pm'] !== '1048576')
	{ throw new RuntimeException('The ACP assignments did not become effective on the native attachment policy'); }
}
elseif ($argv[2] === 'inherited')
{
	if ($policy->allowance(87,false) !== '100' || $policy->allowance(87,true) !== '4096' ||
		(int) $config['plus_attach_default_post'] || (int) $config['plus_attach_default_pm'] ||
		(int) quota_fixture_scalar("SELECT COUNT(*) AS n FROM " . ATTACHMENTS_TABLE . ' WHERE attach_id IN (50,63)','n') !== 2)
	{ throw new RuntimeException('Removing a level failed to restore group and board inheritance or affected stored files'); }
}
else { exit(2); }
