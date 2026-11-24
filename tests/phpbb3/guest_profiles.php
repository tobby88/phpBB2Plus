<?php
// Enable public profiles in the disposable board via the ACP's native permission writer.
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 2 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
require_once './includes/acp/auth.php';
$admin = new auth_admin();
$result = $db->sql_query("SELECT group_id FROM " . GROUPS_TABLE . " WHERE group_name='GUESTS'");
$id = (int) $db->sql_fetchfield('group_id');
$db->sql_freeresult($result);
if (!$id) { throw new RuntimeException('Missing guest group'); }
$admin->acl_set('group', 0, $id, array('u_' => ACL_YES, 'u_viewprofile' => ACL_YES));
