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
$phase = $argv[2]; $key = 'plus_test_member_directory'; $text = $phpbb_container->get('config_text');
$state = json_decode((string) $text->get($key), true);
$scopes = array('a_plus_mail_list','a_plus_user_list');
if ($phase === 'older' || $phase === 'upgraded')
{
	$rights = array_column($rows('SELECT auth_option FROM ' . ACL_OPTIONS_TABLE . ' WHERE ' . $db->sql_in_set('auth_option', $scopes) . ' ORDER BY auth_option'), 'auth_option');
	$modes = array_column($rows('SELECT module_mode FROM ' . MODULES_TABLE . " WHERE module_basename='" .
		$db->sql_escape('\phpbb2plus\community\acp\member_directory_module') . "' ORDER BY module_mode"), 'module_mode');
	$installed = $phase === 'upgraded';
	if ($rights !== ($installed ? $scopes : array()) || $modes !== ($installed ? array('emails','members') : array()))
	{ throw new RuntimeException('Both directory modes and permissions must follow their own upgrade'); }
}
elseif ($phase === 'prepare')
{
	if ($state) { throw new RuntimeException('An earlier directory fixture is still active'); }
	$identities = array(277 => 'EmailDirectoryJunior', 278 => 'MemberDirectoryJunior');
	foreach ($identities as $source => $name)
	{
		$records = $rows('SELECT u.user_id, u.username, r.rights_json, r.report_json FROM ' . USERS_TABLE . ' u INNER JOIN ' .
			$table_prefix . 'plus_jr_records r ON r.user_id=u.user_id WHERE r.source_user=' . $source);
		$record = $records[0] ?? array();
		$report = json_decode($record['report_json'] ?? '[]', true);
		$expected = $source === 277 ? 'a_plus_mail_list' : 'a_plus_user_list';
		if (($record['username'] ?? '') !== $name || json_decode($record['rights_json'], true) !== array($expected) ||
			($report[0]['result'] ?? '') !== 'delegated' ||
			($source === 278 && ($report[0]['unmapped_bulk_actions'] ?? null) !== array('activate','deactivate','ban','unban','group')))
		{ throw new RuntimeException("Unexpected converted directory scope for $name"); }
		$state['actors'][$name] = (int) $record['user_id'];
	}
	foreach (array('newest_user_id','newest_username','newest_user_colour') as $name) { $state['newest'][$name] = $config[$name]; }
	$group = (int) $rows('SELECT group_id FROM ' . GROUPS_TABLE . " WHERE group_name='REGISTERED' AND group_type=" . GROUP_SPECIAL)[0]['group_id'];
	$fixtures = array('Alpha' => array('DirFixtureAlpha', USER_NORMAL, 3, 12131212),
		'Beta' => array('DirFixtureBeta', USER_INACTIVE, 5, 0), 'Digit' => array('7Dir%Fixture', USER_NORMAL, 5, 14131212),
		'Amp' => array('DirFixtureAmp&amp;One', USER_INACTIVE, 1, 14131212));
	foreach ($fixtures as $alias => $values)
	{
		list($name, $type, $posts, $visited) = $values;
		$id = user_add(array('username' => $name, 'user_type' => $type, 'group_id' => $group,
			'user_email' => strtolower($alias) . '+list@members.directory.invalid', 'user_regdate' => 1000000000, 'user_colour' => '2167AC'));
		if (!$id) { throw new RuntimeException('Unable to register a synthetic list member'); }
		$state['users'][$alias] = (int) $id;
		$db->sql_query('UPDATE ' . USERS_TABLE . " SET user_posts=$posts,user_lastvisit=$visited,user_colour='2167AC' WHERE user_id=" . (int) $id);
	}
	foreach (array(array('Alpha',0,0), array('Beta',time()-10,0), array('Digit',0,0), array('Digit',0,1)) as $ban)
	{
		$db->sql_query('INSERT INTO ' . BANLIST_TABLE . ' ' . $db->sql_build_array('INSERT', array('ban_userid' => $state['users'][$ban[0]],
			'ban_start'=>time()-3600, 'ban_end'=>$ban[1], 'ban_exclude'=>$ban[2], 'ban_reason'=>'Local directory fixture', 'ban_give_reason'=>'')));
	}
	$text->set($key, json_encode($state, JSON_THROW_ON_ERROR));
	echo json_encode($state['users'], JSON_THROW_ON_ERROR);
}
elseif (!$state) { throw new RuntimeException('Missing synthetic directory fixture'); }
elseif ($phase === 'account-right')
{
	(new auth_admin())->acl_set('user', 0, $state['actors']['MemberDirectoryJunior'], array('a_user' => ACL_YES));
}
elseif ($phase === 'global-right')
{
	(new auth_admin())->acl_set('user', 0, $state['actors']['MemberDirectoryJunior'], array('a_authusers' => ACL_YES, 'a_uauth' => ACL_YES));
}
elseif ($phase === 'local-right')
{
	(new auth_admin())->acl_set('user', 0, $state['actors']['MemberDirectoryJunior'], array('a_fauth' => ACL_YES));
}
elseif ($phase === 'revoke')
{
	(new auth_admin())->acl_set('user', 0, $state['actors']['EmailDirectoryJunior'], array('a_plus_mail_list' => ACL_NEVER));
	(new auth_admin())->acl_set('user', 0, $state['actors']['MemberDirectoryJunior'], array('a_plus_user_list' => ACL_NEVER));
}
elseif ($phase === 'clean')
{
	(new auth_admin())->acl_set('user', 0, $state['actors']['EmailDirectoryJunior'], array('a_plus_mail_list' => ACL_NO));
	(new auth_admin())->acl_set('user', 0, $state['actors']['MemberDirectoryJunior'], array_fill_keys(array('a_plus_user_list','a_user','a_authusers','a_uauth','a_fauth'), ACL_NO));
	$ids = array_column($rows('SELECT user_id FROM ' . USERS_TABLE . " WHERE user_email LIKE '%@members.directory.invalid' AND " .
		$db->sql_in_set('user_id', $state['users'])), 'user_id');
	if (count($ids) !== count($state['users'])) { throw new RuntimeException('A directory read changed membership'); }
	$db->sql_query('DELETE FROM ' . BANLIST_TABLE . " WHERE ban_reason='Local directory fixture' AND " . $db->sql_in_set('ban_userid', $ids));
	user_delete('retain', $ids, true);
	$db->sql_query('DELETE FROM ' . $table_prefix . 'plus_former_names WHERE ' . $db->sql_in_set('native_id', $ids));
	foreach ($state['newest'] as $name => $value) { $config->set($name, $value, false); }
	$text->delete($key);
}
else { throw new RuntimeException('Unknown directory fixture phase'); }
