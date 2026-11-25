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
$p = $table_prefix . 'plus_attach_';
if ($argv[2] === 'before')
{
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
else { exit(2); }
