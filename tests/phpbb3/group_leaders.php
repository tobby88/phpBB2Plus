<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 || !preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path='./'; $phpEx='php'; require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$state = static function () use ($db) {
	$r = $db->sql_query('SELECT u.user_id, ug.group_leader, ug.user_pending FROM ' . USER_GROUP_TABLE . ' ug
		JOIN ' . USERS_TABLE . ' u ON u.user_id=ug.user_id WHERE ug.group_id=77 AND u.user_id IN (87,89) ORDER BY u.user_id');
	$rows = array();
	while ($row = $db->sql_fetchrow($r)) { $rows[(int) $row['user_id']] = array((int) $row['group_leader'], (int) $row['user_pending']); }
	$db->sql_freeresult($r);
	return $rows;
};
$text = $phpbb_container->get('config_text');
$key = 'test_plus_detached_leader';
switch ($argv[2])
{
	case 'prepare':
		if (!$text->get($key))
		{
			if ($state() !== array(87=>array(0,1),89=>array(1,1))) { throw new RuntimeException('Unexpected initial converted memberships'); }
			$text->set($key, json_encode($config['email_enable']));
		}
		$config->set('email_enable', 0);
		break;
	case 'approved':
		if ($state() !== array(87=>array(0,0),89=>array(1,1))) { throw new RuntimeException('The member action changed the wrong membership or granted the leader access'); }
		break;
	case 'founders':
		$db->sql_query('UPDATE ' . GROUPS_TABLE . ' SET group_founder_manage=1 WHERE group_id=77 AND plus_legacy_group=1');
		$r = $db->sql_query('SELECT user_lang FROM ' . USERS_TABLE . ' WHERE user_id=87');
		$text->set($key . '_language', $db->sql_fetchfield('user_lang'));
		$db->sql_freeresult($r);
		$db->sql_query('UPDATE ' . USERS_TABLE . " SET user_lang='en' WHERE user_id=87");
		break;
	case 'restore':
		$db->sql_query('UPDATE ' . GROUPS_TABLE . ' SET group_type=' . GROUP_HIDDEN . ',group_founder_manage=0 WHERE group_id=77 AND plus_legacy_group=1');
		$db->sql_query('DELETE FROM ' . USER_GROUP_TABLE . ' WHERE group_id=77 AND user_id=87');
		$db->sql_query('INSERT INTO ' . USER_GROUP_TABLE . ' (group_id,user_id,group_leader,user_pending) VALUES (77,87,0,1)');
		$auth->acl_clear_prefetch(array(87,89));
		if ($text->get($key) !== null) { $config->set('email_enable', json_decode($text->get($key), true)); $text->delete($key); }
		$previous_language = $text->get($key . '_language');
		if ($previous_language !== null)
		{
			$db->sql_query('UPDATE ' . USERS_TABLE . " SET user_lang='" . $db->sql_escape($previous_language) . "' WHERE user_id=87");
			$text->delete($key . '_language');
		}
		break;
	default: exit(2);
}
