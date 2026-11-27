<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 || !preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
if ($argv[2] === 'source')
{
	mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
	$old = new mysqli('127.0.0.1', getenv('PLUS_TEST_DB_USER') ?: 'root', getenv('PLUS_TEST_DB_PASSWORD'), $argv[1], (int) (getenv('PLUS_TEST_DB_PORT') ?: 3306));
	$old->set_charset('utf8mb4');
	$old->query("INSERT INTO phpbb_groups (group_id,group_type,group_name,group_description,group_moderator,group_single_user)
		VALUES (125,0,'Schülerrat','Klassen und Kurse',89,0)");
	$old->query("INSERT INTO phpbb_user_group (group_id,user_id,user_pending) VALUES (125,1,0),(125,87,1),(125,89,0)");
	$old->query("INSERT INTO phpbb_forums (forum_id,forum_name,forum_desc,cat_id,forum_order,auth_view,auth_read)
		VALUES (125,'Sitzungen des Schülerrats','',1,140,2,2)");
	$old->query('INSERT INTO phpbb_auth_access (group_id,forum_id,auth_view,auth_read) VALUES (125,125,1,1)');
	return;
}
define('IN_PHPBB', true);
$phpbb_root_path='./'; $phpEx='php'; require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$scalar = static function ($sql, $column) use ($db) {
	$r = $db->sql_query($sql); $value = $db->sql_fetchfield($column); $db->sql_freeresult($r); return $value;
};
$historic = (int) $scalar('SELECT user_id FROM ' . USERS_TABLE . " WHERE username_clean='historicmember'", 'user_id');
if ($historic <= 1 || !$scalar('SELECT plus_legacy_group FROM ' . GROUPS_TABLE . " WHERE group_id=125 AND group_name='Schülerrat'", 'plus_legacy_group')) { exit(2); }
$text = $phpbb_container->get('config_text'); $key = 'test_plus_group_departures';
$read = 'SELECT user_pending FROM ' . USER_GROUP_TABLE . ' WHERE group_id=125 AND user_id=';
switch ($argv[2])
{
	case 'prepare':
		if ($text->get($key) !== null || (string) $scalar($read . $historic, 'user_pending') !== '0' ||
			(string) $scalar($read . '87', 'user_pending') !== '1') { throw new RuntimeException('Unexpected converted student group memberships'); }
		$text->set($key, json_encode($config['email_enable'])); $config->set('email_enable', 0);
		break;
	case 'close': case 'hide':
		$db->sql_query('UPDATE ' . GROUPS_TABLE . ' SET group_type=' . ($argv[2] === 'close' ? GROUP_CLOSED : GROUP_HIDDEN) . ' WHERE group_id=125');
		$cache->destroy('sql', GROUPS_TABLE);
		break;
	case 'direct-never':
		$option = (int) $scalar('SELECT auth_option_id FROM ' . ACL_OPTIONS_TABLE . " WHERE auth_option='u_hideonline'", 'auth_option_id');
		$db->sql_query('INSERT INTO ' . ACL_GROUPS_TABLE . " (group_id,forum_id,auth_option_id,auth_role_id,auth_setting) VALUES (125,0,$option,0," . ACL_NEVER . ')');
		break;
	case 'role-never':
		$db->sql_query('DELETE FROM ' . ACL_GROUPS_TABLE . ' WHERE group_id=125 AND forum_id=0');
		$role = (int) $scalar('SELECT MIN(r.role_id) AS fixture_role FROM ' . ACL_ROLES_TABLE . ' r JOIN ' . ACL_ROLES_DATA_TABLE . '
			d ON d.role_id=r.role_id WHERE r.role_type=\'u_\' AND d.auth_setting=' . ACL_NEVER, 'fixture_role');
		if (!$role) { throw new RuntimeException('Missing native restrictive role fixture'); }
		$db->sql_query('INSERT INTO ' . ACL_GROUPS_TABLE . " (group_id,forum_id,auth_option_id,auth_role_id,auth_setting) VALUES (125,0,0,$role,0)");
		break;
	case 'clear-native':
		$db->sql_query('DELETE FROM ' . ACL_GROUPS_TABLE . ' WHERE group_id=125 AND forum_id=0');
		break;
	case 'pending-again':
		$db->sql_query('INSERT INTO ' . USER_GROUP_TABLE . ' (group_id,user_id,group_leader,user_pending) VALUES (125,87,0,1)');
		break;
	case 'restore':
		$db->sql_query('DELETE FROM ' . ACL_GROUPS_TABLE . ' WHERE group_id=125 AND forum_id=0');
		$db->sql_query('UPDATE ' . GROUPS_TABLE . ' SET group_type=' . GROUP_OPEN . ' WHERE group_id=125');
		$db->sql_query('DELETE FROM ' . USER_GROUP_TABLE . ' WHERE group_id=125 AND ' . $db->sql_in_set('user_id', array($historic,87)));
		$db->sql_query('INSERT INTO ' . USER_GROUP_TABLE . " (group_id,user_id,group_leader,user_pending) VALUES (125,$historic,0,0),(125,87,0,1)");
		$cache->destroy('sql', GROUPS_TABLE); $auth->acl_clear_prefetch(array($historic,87,89));
		$config->set('email_enable', json_decode($text->get($key), true)); $text->delete($key);
		break;
	default: exit(2);
}
