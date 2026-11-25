<?php
// Verify the synthetic board's native ACL, cache and recipient lists after normal membership changes.
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D',$argv[1])) { exit(2); }
define('IN_PHPBB',true);
$phpbb_root_path = './'; $phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
require_once './includes/functions_user.php';
require_once './includes/acp/auth.php';
$user->session_begin();
$user->setup();
$auth->acl($user->data);
function access_row($id)
{
	global $db;
	$r = $db->sql_query('SELECT * FROM ' . USERS_TABLE . ' WHERE user_id=' . (int) $id);
	$row = $db->sql_fetchrow($r); $db->sql_freeresult($r);
	return $row;
}
function access_check($id,$forum,$list,$read,$search = null)
{
	global $auth;
	$search = $search ?? $read;
	$row = access_row($id);
	$auth->acl($row);
	foreach (array('f_list'=>$list,'f_list_topics'=>$read,'f_read'=>$read,'f_search'=>$search) as $key=>$want)
	{
		if ((bool) $auth->acl_get($key,$forum) !== $want || isset($auth->acl_getf($key,true)[$forum]) !== $want)
		{ throw new RuntimeException("Incorrect native $key for fixture account $id in forum $forum"); }
	}
	$bulk = $auth->acl_get_list(array($id),array('f_read','f_search'),array($forum));
	if (in_array($id,$bulk[$forum]['f_read'] ?? array()) !== $read || in_array($id,$bulk[$forum]['f_search'] ?? array()) !== $search)
	{ throw new RuntimeException('Notification recipient ACL does not match the viewer'); }
}
$r = $db->sql_query("SELECT user_id FROM " . USERS_TABLE . " WHERE username='HistoricMember'");
$historic = (int) $db->sql_fetchfield('user_id'); $db->sql_freeresult($r);
if (!$historic || $historic === ANONYMOUS) { throw new RuntimeException('Missing fixture reader'); }
if ($argv[2] === 'disable' || $argv[2] === 'enable')
{
	access_check($historic,10,true,$argv[2] === 'enable');
	access_check($historic,9,true,true);
	return;
}
if ($argv[2] !== 'membership') { exit(2); }
access_check(ANONYMOUS,8,true,false);
access_check(ANONYMOUS,9,false,false);
access_check($historic,9,true,true);
access_check($historic,10,true,true);
foreach (array(false,'f_%') as $filter)
{
	$all = $auth->acl_raw_data(array($historic),$filter,array(10));
	if ((int) ($all[$historic][10]['f_search'] ?? ACL_NO) !== ACL_YES) { throw new RuntimeException('An unfiltered or wildcard recipient query lost a reader'); }
}
$native = new \phpbb\auth\auth();
$record = access_row($historic);
$native->acl($record);
if ($native->acl_get('f_read',10)) { throw new RuntimeException('Conditional membership was persisted as an unconditional native grant'); }
access_check(87,10,true,false);
access_check(89,10,false,false);
access_check(2,8,true,true);
access_check(2,10,true,true);
if (group_user_add(73,array(87),false,'Archive Club',false,0,1)) { throw new RuntimeException('Unable to add fixture reader'); }
access_check(87,10,true,false);
group_user_attributes('approve',73,array(87),false,'Archive Club');
access_check(87,10,true,true);
$admin = new auth_admin();
$admin->acl_set('user',10,87,array('f_search'=>ACL_NEVER));
access_check(87,10,true,true,false);
$admin->acl_set('user',10,87,array('f_search'=>ACL_NO));
group_user_del(73,array(87),false,'Archive Club');
access_check(87,10,true,false);
if (group_user_add(71,array(89),false,'Photo Club',false,0,0)) { throw new RuntimeException('Unable to add personal-grant fixture viewer'); }
access_check(89,10,true,true);
group_user_del(71,array(89),false,'Photo Club');
access_check(89,10,false,false);
