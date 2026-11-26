<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './'; $phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$result = $db->sql_query("SELECT topic_id FROM " . TOPICS_TABLE . " WHERE topic_id=603 AND forum_id=12 AND poll_title='Locked ballot question'");
$found = $db->sql_fetchrow($result);
$db->sql_freeresult($result);
if (!$found) { throw new RuntimeException('Expected the converted moderator ballot'); }
$phase = $argv[2];
if (!in_array($phase, array('forum', 'expire', 'withdraw', 'restore'), true)) { exit(2); }
$restore = $phase === 'restore';
$db->sql_query('UPDATE ' . TOPICS_TABLE . ' SET topic_status=' . ($restore ? ITEM_LOCKED : ITEM_UNLOCKED) .
	', poll_length=' . ($phase === 'expire' ? 1 : 0) . ' WHERE topic_id=603');
$db->sql_query('UPDATE ' . FORUMS_TABLE . ' SET forum_status=' . ($restore ? ITEM_UNLOCKED : ITEM_LOCKED) . ' WHERE forum_id=12');
$db->sql_query('UPDATE ' . TOPICS_TABLE . ' SET topic_status=' . ($restore ? ITEM_UNLOCKED : ITEM_LOCKED) . ' WHERE topic_id=602');
$cache->destroy('sql', FORUMS_TABLE);
$result = $db->sql_query("SELECT auth_option_id FROM " . ACL_OPTIONS_TABLE . " WHERE auth_option='f_vote'");
$option = (int) $db->sql_fetchfield('auth_option_id');
$db->sql_freeresult($result);
if (!$option) { throw new RuntimeException('Native voting permission missing'); }
$scope = "user_id=89 AND forum_id=12 AND auth_role_id=0 AND auth_option_id=$option AND auth_setting=" . ACL_NEVER;
if ($phase === 'withdraw')
{
	$db->sql_query('INSERT INTO ' . ACL_USERS_TABLE . ' ' . $db->sql_build_array('INSERT', array('user_id'=>89, 'forum_id'=>12,
		'auth_role_id'=>0, 'auth_option_id'=>$option, 'auth_setting'=>ACL_NEVER)));
}
else { $db->sql_query('DELETE FROM ' . ACL_USERS_TABLE . ' WHERE ' . $scope); }
$auth->acl_clear_prefetch(89);
