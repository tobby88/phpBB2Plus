<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
require_once './includes/acp/auth.php';
$result = $db->sql_query('SELECT user_id FROM ' . USERS_TABLE . " WHERE username='DownloadFilesJunior' AND plus_jr_delegate=1");
$id = (int) $db->sql_fetchfield('user_id');
$db->sql_freeresult($result);
if ($id !== 56) { throw new RuntimeException('Missing converted file-management operator'); }
$files = $table_prefix . 'plus_dl_files';
switch ($argv[2])
{
	case 'setup':
		$result = $db->sql_query("SELECT * FROM $files WHERE file_id=51");
		$row = $db->sql_fetchrow($result);
		$db->sql_freeresult($result);
		if (!$row) { throw new RuntimeException('Missing restricted source download'); }
		$copy = array('file_id'=>10801, 'title'=>'Delegated file fixture', 'summary'=>'Starting summary', 'approved'=>0) + $row;
		$db->sql_query("INSERT INTO $files " . $db->sql_build_array('INSERT', $copy));
		break;
	case 'deny-delete':
		(new auth_admin())->acl_set('user', 0, $id, array('a_plus_dl_delete'=>ACL_NEVER));
		break;
	case 'verified':
		$result = $db->sql_query("SELECT summary,approved FROM $files WHERE file_id=10801");
		$row = $db->sql_fetchrow($result);
		$db->sql_freeresult($result);
		if (!$row || $row['summary'] !== 'Changed by file operator' || (int) $row['approved'] !== 1)
		{ throw new RuntimeException('With deletion denied, the retained private file should contain the approved edit'); }
		break;
	case 'cleanup':
		(new auth_admin())->acl_set('user', 0, $id, array('a_plus_dl_delete'=>ACL_NO));
		$db->sql_query('DELETE FROM ' . $table_prefix . 'plus_dl_field_values WHERE file_id=10801');
		$db->sql_query("DELETE FROM $files WHERE file_id=10801");
		break;
	default: exit(2);
}
