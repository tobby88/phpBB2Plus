<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 || !preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './'; $phpEx = 'php';
require './common.php';
if ($dbname !== $argv[1] || $dbhost !== '127.0.0.1') { exit(2); }
require_once './includes/functions_user.php';
require_once './includes/acp/auth.php';
$rows = static function ($sql) use ($db) {
	$r = $db->sql_query($sql); $rows = $db->sql_fetchrowset($r); $db->sql_freeresult($r); return $rows;
};
$phase = $argv[2]; $key = 'plus_test_reg_network'; $text = $phpbb_container->get('config_text');
$state = json_decode((string) $text->get($key), true) ?: array();
$tools = (new \phpbb\db\tools\factory())->get($db);
if ($phase === 'pre-upgrade')
{
	if ($tools->sql_column_exists(USERS_TABLE, 'plus_reg_host')) { throw new RuntimeException('Expected a pre-v163 schema'); }
	$db->sql_query('UPDATE ' . USERS_TABLE . " SET user_ip='2001:0db8:0000:0000:0000:0000:0000:ece1' WHERE user_type=" . USER_FOUNDER);
}
elseif ($phase === 'upgraded')
{
	$admin = $rows('SELECT user_ip,plus_reg_host FROM ' . USERS_TABLE . ' WHERE user_type=' . USER_FOUNDER)[0];
	if ($admin['user_ip'] !== '2001:0db8:0000:0000:0000:0000:0000:ece1' || $admin['plus_reg_host'] !== '')
	{ throw new RuntimeException('Upgrading changed a prior native address or invented an archived name'); }
}
elseif ($phase === 'prepare')
{
	if ($state) { throw new RuntimeException('Registration lookup fixture already active'); }
	$source = array_column($rows('SELECT user_id,username,user_ip,plus_reg_host FROM ' . USERS_TABLE .
		" WHERE username IN ('ModernMember','HistoricMember','RegistrationNetworkJunior','CountryFlagJunior')"), null, 'username');
	if (count($source) !== 4 || (int) $source['HistoricMember']['user_id'] === ANONYMOUS)
	{ throw new RuntimeException('A source lookup account was not converted'); }
	foreach (array('ModernMember' => array('plus_reg_host' => 'archived-board.example.invalid'),
		'HistoricMember' => array('plus_reg_host' => 'archived<&>.example.invalid', 'user_ip' => '2001:db8::7'),
		'RegistrationNetworkJunior' => array('user_ip' => '192.0.2.107', 'plus_reg_host' => '')) as $name => $fields)
	{
		foreach ($fields as $field => $expected)
		{
			if ($source[$name][$field] !== $expected)
			{ throw new RuntimeException("Unexpected synthetic registration value for $name/$field: " . $source[$name][$field]); }
		}
	}
	$state['actors'] = array_map('intval', array_column($source, 'user_id', 'username'));
	$state['newest'] = array();
	foreach (array('newest_user_id','newest_username','newest_user_colour') as $name) { $state['newest'][$name] = $config[$name]; }
	$group = (int) $rows('SELECT group_id FROM ' . GROUPS_TABLE . " WHERE group_name='REGISTERED' AND group_type=" . GROUP_SPECIAL)[0]['group_id'];
	// Native accounts which never had a stored hostname, including IPv6 spellings
	// from earlier conversions that did not canonicalise every native address.
	$addresses = array('Compressed' => '2001:db8::7', 'Expanded' => '2001:0DB8:0000:0000:0000:0000:0000:0007',
		'NoAddress' => '', 'Zero' => '0.0.0.0', 'Other' => '2001:db8::8', 'Mapped' => '::ffff:192.0.2.107', 'WithHost' => '192.0.2.107');
	for ($i = 0; $i < 25; $i++) { $addresses['V4' . $i] = '192.0.2.107'; }
	$state['users'] = array();
	foreach ($addresses as $suffix => $address)
	{
		$name = 'NetFixture' . $suffix;
		$id = user_add(array('username' => $name, 'user_type' => USER_INACTIVE, 'group_id' => $group, 'user_ip' => $address,
			'user_email' => strtolower($name) . '@example.invalid'));
		if (!$id) { throw new RuntimeException('Unable to add local lookup fixture'); }
		$state['users'][$suffix] = (int) $id;
	}
	$db->sql_query('UPDATE ' . USERS_TABLE . " SET plus_reg_host='erase-with-this-account.invalid' WHERE user_id=" . $state['users']['WithHost']);
	(new auth_admin())->acl_set('user', 0, $state['actors']['CountryFlagJunior'], array('a_user' => ACL_YES));
	$text->set($key, json_encode($state, JSON_THROW_ON_ERROR));
	$checkIds = array($state['actors']['HistoricMember'], $state['actors']['ModernMember'], $state['users']['Compressed'], $state['users']['Expanded']);
	$expected = array_map('intval', array_column($rows('SELECT user_id FROM ' . USERS_TABLE . ' WHERE ' .
		$db->sql_in_set('user_id', $checkIds) . ' ORDER BY user_regdate,user_id'), 'user_id'));
	echo json_encode(array_merge($state['actors'], $state['users'], array('ordered_ipv6' => $expected)), JSON_THROW_ON_ERROR);
}
elseif ($phase === 'seed-disabled-delete')
{
	if ($state || $text->get('plus_test_disabled_host')) { throw new RuntimeException('Unexpected registration fixture state'); }
	$group = (int) $rows('SELECT group_id FROM ' . GROUPS_TABLE . " WHERE group_name='REGISTERED' AND group_type=" . GROUP_SPECIAL)[0]['group_id'];
	$id = user_add(array('username' => 'NetFixtureOff', 'user_email' => 'netfixtureoff@example.invalid', 'user_type' => USER_INACTIVE, 'group_id' => $group,
		'plus_reg_host' => 'remove-disabled.example.invalid'));
	if (!$id) { throw new RuntimeException('No account for the extension-disabled cleanup'); }
	$text->set('plus_test_disabled_host', (string) $id);
}
elseif ($phase === 'disabled-delete')
{
	$id = (int) $text->get('plus_test_disabled_host');
	if ($id <= ANONYMOUS || $phpbb_container->get('ext.manager')->is_enabled('phpbb2plus/community') ||
		!$rows('SELECT user_id FROM ' . USERS_TABLE . " WHERE user_id=$id AND username='NetFixtureOff' AND plus_reg_host='remove-disabled.example.invalid'"))
	{ throw new RuntimeException('The disabled-extension deletion did not start with the intended account'); }
	user_delete('retain', array($id), true);
	if ($rows('SELECT user_id FROM ' . USERS_TABLE . " WHERE user_id=$id OR plus_reg_host='remove-disabled.example.invalid'"))
	{ throw new RuntimeException('The account hostname survived deletion with the community extension disabled'); }
	$text->delete('plus_test_disabled_host');
}
elseif (!$state) { throw new RuntimeException('Missing local fixture state'); }
elseif ($phase === 'revoke' || $phase === 'allow')
{
	(new auth_admin())->acl_set('user', 0, $state['actors']['RegistrationNetworkJunior'], array('a_plus_reg_network' => $phase === 'revoke' ? ACL_NEVER : ACL_NO));
}
elseif ($phase === 'native')
{
	(new auth_admin())->acl_set('user', 0, $state['actors']['CountryFlagJunior'], array('a_plus_reg_network' => ACL_YES));
}
elseif ($phase === 'delete')
{
	$id = $state['users']['WithHost'];
	user_delete('retain', array($id), true);
	if ($rows('SELECT user_id FROM ' . USERS_TABLE . " WHERE user_id=$id OR plus_reg_host='erase-with-this-account.invalid'"))
	{ throw new RuntimeException('A deleted account retained its registration hostname'); }
}
elseif ($phase === 'clean')
{
	(new auth_admin())->acl_set('user', 0, $state['actors']['CountryFlagJunior'], array('a_user' => ACL_NO, 'a_plus_reg_network' => ACL_NO));
	(new auth_admin())->acl_set('user', 0, $state['actors']['RegistrationNetworkJunior'], array('a_plus_reg_network' => ACL_NO));
	$members = array_column($rows('SELECT user_id FROM ' . USERS_TABLE . " WHERE username_clean LIKE 'netfixture%' AND " .
		$db->sql_in_set('user_id', $state['users'])), 'user_id');
	if ($members) { user_delete('retain', $members, true); }
	$db->sql_query('DELETE FROM ' . $table_prefix . 'plus_former_names WHERE ' . $db->sql_in_set('native_id', $state['users']));
	foreach ($state['newest'] as $name => $value) { $config->set($name, $value, false); }
	$text->delete($key);
}
else { throw new RuntimeException('Unknown registration lookup fixture phase'); }
