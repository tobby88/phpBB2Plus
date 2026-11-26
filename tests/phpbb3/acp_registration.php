<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './'; $phpEx = 'php'; require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
require_once './includes/functions_user.php';
$row = static function ($sql) use ($db) {
	$r = $db->sql_query($sql); $x = $db->sql_fetchrow($r); $db->sql_freeresult($r); return $x;
};
$store = $phpbb_container->get('config_text');
$key = 'plus_test_reg_before';
$jr = $table_prefix . 'plus_jr_delegations';
$field = "field_ident='phpbb_location'";
$configurations = array('email_enable'=>0, 'email_check_mx'=>0, 'allow_emailreuse'=>0, 'require_activation'=>USER_ACTIVATION_DISABLE,
	'new_member_post_limit'=>3, 'new_member_group_default'=>1, 'plus_pw_rules'=>1, 'plus_pw_min_bytes'=>10,
	'plus_pw_letters_digits'=>1, 'plus_pw_no_name'=>1, 'pass_complex'=>'PASS_TYPE_ANY');
$before = json_decode((string) $store->get($key), true);
switch ($argv[2])
{
	case 'prepare':
		$grant = $row("SELECT rights_json FROM $jr WHERE user_id=276");
		if (!$grant || json_decode($grant['rights_json'], true) !== array('a_plus_user_register') ||
			$row('SELECT user_id FROM ' . USERS_TABLE . " WHERE username_clean IN ('registrationmember','registrationreused','registrationleft','registrationright','registrationdenied')"))
		{ throw new RuntimeException('Registration fixture or narrow historical delegation unavailable'); }
		$before = array('config'=>array(), 'field'=>$row('SELECT field_required,field_show_on_reg,field_active FROM ' . PROFILE_FIELDS_TABLE . " WHERE $field"),
			'count'=>(int) $config['num_users'], 'rights'=>$grant['rights_json']);
		if (!$before['field']) { throw new RuntimeException('Missing native location field'); }
		foreach ($configurations as $name=>$value) { $before['config'][$name] = $config[$name]; $config->set($name, $value); }
		$store->set($key, json_encode($before));
		$db->sql_query('UPDATE ' . PROFILE_FIELDS_TABLE . " SET field_required=1,field_show_on_reg=1,field_active=1 WHERE $field");
		break;
	case 'created':
		$member = $row('SELECT u.*,g.group_name,p.pf_phpbb_location FROM ' . USERS_TABLE . ' u JOIN ' . GROUPS_TABLE .
			' g ON g.group_id=u.group_id JOIN ' . PROFILE_FIELDS_DATA_TABLE . " p ON p.user_id=u.user_id WHERE u.username_clean='registrationmember'");
		$count = $row('SELECT COUNT(*) AS n FROM ' . USER_GROUP_TABLE . ' ug JOIN ' . GROUPS_TABLE . ' g ON g.group_id=ug.group_id WHERE ug.user_id=' .
			(int) ($member['user_id'] ?? 0) . " AND g.group_name IN ('REGISTERED','NEWLY_REGISTERED') AND ug.user_pending=0 AND ug.group_leader=0");
		$total = $row('SELECT COUNT(*) AS n FROM ' . USER_GROUP_TABLE . ' WHERE user_id=' . (int) ($member['user_id'] ?? 0));
		$audit = $row('SELECT user_id,log_data FROM ' . LOG_TABLE . " WHERE log_operation='LOG_PLUS_USER_REGISTER' ORDER BY log_id DESC LIMIT 1");
		if (!$member || (int) $member['user_type'] !== USER_NORMAL || $member['user_new'] != 1 || $member['group_name'] !== 'NEWLY_REGISTERED' ||
			$count['n'] != 2 || $total['n'] != 2 || $member['user_lang'] !== 'de' || $member['user_timezone'] !== 'Europe/Berlin' || $member['user_dateformat'] !== 'Y-m-d H:i' ||
			$member['pf_phpbb_location'] !== 'Düsseldorf' || $member['user_ip'] !== '' || $member['user_actkey'] !== '' || !$member['user_password'] ||
			(int) $config['num_users'] !== $before['count'] + 1 || !$audit || (int) $audit['user_id'] !== 276 ||
			unserialize($audit['log_data'], array('allowed_classes'=>false)) !== array('RegistrationMember'))
		{ throw new RuntimeException('An ordinary account, its profile, groups or count differed from the configured registration'); }
		break;
	case 'reuse': $config->set('allow_emailreuse', 1); break;
	case 'revoke':
		$config->set('allow_emailreuse', 0);
		$db->sql_query("UPDATE $jr SET rights_json='[]',revision=revision+1 WHERE user_id=276");
		$auth->acl_clear_prefetch(276);
		break;
	case 'worker-left':
	case 'worker-right':
		$user->add_lang('ucp');
		$side = $argv[2] === 'worker-left' ? 'Left' : 'Right';
		$group = $row('SELECT group_id FROM ' . GROUPS_TABLE . " WHERE group_name='REGISTERED' AND group_type=" . GROUP_SPECIAL);
		// Both processes use the same insertion path used by the UCP and the CLI after validation.
		try
		{
			$id = user_add(array('username'=>'Registration' . $side,'user_email'=>'registration-concurrent@example.invalid',
				'group_id'=>(int) $group['group_id'], 'user_type'=>USER_NORMAL, 'user_password'=>$phpbb_container->get('passwords.manager')->hash(bin2hex(random_bytes(24)))));
			if (!$id) { throw new RuntimeException('A concurrent registration returned an empty user id'); }
			echo 'created';
		}
		catch (\phpbb\exception\runtime_exception $e)
		{
			if ($e->getMessage() !== $user->lang('EMAIL_TAKEN_EMAIL')) { throw $e; }
			echo 'email already in use';
		}
		break;
	case 'interrupted':
		// An extension can fail after the core wrote profile/group rows. Normal
		// request cleanup must leave neither the account nor a counted member.
		$phpbb_dispatcher->addListener('core.user_add_after', static function () { throw new RuntimeException('Fixture interrupted'); });
		$group = $row('SELECT group_id FROM ' . GROUPS_TABLE . " WHERE group_name='REGISTERED' AND group_type=" . GROUP_SPECIAL);
		try
		{
			user_add(array('username'=>'RegistrationInterrupted','user_email'=>'registration-interrupted@example.invalid',
				'group_id'=>(int) $group['group_id'], 'user_type'=>USER_NORMAL, 'user_password'=>$phpbb_container->get('passwords.manager')->hash(bin2hex(random_bytes(24)))),
				array('pf_phpbb_location'=>'Fixture interrupted'));
			throw new RuntimeException('Fixture listener did not run');
		}
		catch (RuntimeException $e)
		{
			if ($e->getMessage() !== 'Fixture interrupted') { throw $e; }
			$phpbb_dispatcher->dispatch('core.garbage_collection');
		}
		break;
	case 'finish':
		$counts = $row('SELECT COUNT(*) AS n,COUNT(DISTINCT ug.user_id) AS memberships FROM ' . USERS_TABLE . ' u JOIN ' . USER_GROUP_TABLE .
			" ug ON ug.user_id=u.user_id WHERE u.username_clean IN ('registrationleft','registrationright')");
		$reuse = $row('SELECT COUNT(*) AS n FROM ' . USERS_TABLE . " WHERE user_email='registration-member@example.invalid'");
		$denied = $row('SELECT user_id FROM ' . USERS_TABLE . " WHERE username_clean IN ('registrationdenied','registrationinterrupted')");
		$orphan = $row('SELECT user_id FROM ' . PROFILE_FIELDS_DATA_TABLE . " WHERE pf_phpbb_location='Fixture interrupted'");
		if ($counts['n'] != 1 || $counts['memberships'] != 1 || $reuse['n'] != 2 || $denied || $orphan ||
			(int) $config['num_users'] !== $before['count'] + 3) { throw new RuntimeException('Duplicate or denied registrations affected final membership'); }
		$db->sql_query("UPDATE $jr SET rights_json='" . $db->sql_escape($before['rights']) . "',revision=revision+1 WHERE user_id=276");
		$auth->acl_clear_prefetch(276);
		foreach ($before['config'] as $name=>$value) { $config->set($name, $value); }
		$db->sql_query('UPDATE ' . PROFILE_FIELDS_TABLE . ' SET ' . $db->sql_build_array('UPDATE', $before['field']) . " WHERE $field");
		$result = $db->sql_query('SELECT user_id FROM ' . USERS_TABLE . " WHERE username_clean IN ('registrationmember','registrationreused','registrationleft','registrationright')");
		$ids = array_column($db->sql_fetchrowset($result), 'user_id'); $db->sql_freeresult($result);
		user_delete('retain', $ids);
		$store->delete($key);
		break;
	default: throw new RuntimeException('Unknown ACP registration fixture');
}
