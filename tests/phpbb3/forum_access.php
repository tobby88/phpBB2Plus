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
// Reporting uses its own original permission, including approved groups and personal grants.
foreach (array(ANONYMOUS => array(false,false,true,false), $historic => array(true,false,true,true),
	87 => array(false,false,true,true), 89 => array(false,true,true,true), 2 => array(true,true,true,true)) as $id => $expected)
{
	$auth->acl(access_row($id));
	foreach (array(1,12,14,15) as $index => $forum)
	{
		if ((bool) $auth->acl_get('f_report', $forum) !== $expected[$index])
		{ throw new RuntimeException("Incorrect converted report permission in forum $forum for fixture user $id"); }
	}
}
$r = $db->sql_query('SELECT forum_id FROM ' . FORUMS_TABLE . ' WHERE forum_id=51');
$reply_only = (bool) $db->sql_fetchrow($r);
$db->sql_freeresult($r);
if ($reply_only)
{
	$row = access_row(87);
	$auth->acl($row);
	if (!$auth->acl_get('f_reply',51) || $auth->acl_get('f_post',51) || !$auth->acl_get('f_postcount',51))
	{ throw new RuntimeException('A reply-only member lost credit for replies in a counted forum'); }
}
$r = $db->sql_query('SELECT group_id,user_id,group_leader,user_pending FROM ' . USER_GROUP_TABLE . ' WHERE group_id BETWEEN 77 AND 80 ORDER BY group_id,user_id');
$members = array();
while ($member = $db->sql_fetchrow($r)) { $members[] = array_map('intval',array_values($member)); }
$db->sql_freeresult($r);
$expected = array(array(77,87,0,1),array(77,89,1,1),array(77,$historic,0,0),array(78,87,1,1),array(79,$historic,1,0));
usort($expected,static function ($a,$b) { return $a <=> $b; });
if ($members !== $expected) { throw new RuntimeException('Group management, pending or duplicate membership changed during conversion'); }
foreach (array($historic=>array(true,false,false),87=>array(true,false,false),89=>array(true,true,true),ANONYMOUS=>array(false,false,false)) as $id=>$rights)
{
	$row = access_row($id);
	$auth->acl($row);
	if ($auth->acl_get('f_postcount',15)) { throw new RuntimeException("The excluded forum would credit new posts by member $id"); }
	foreach (array('f_edit','f_reply','m_edit') as $index=>$option)
	{
		if ((bool) $auth->acl_get($option,15) !== $rights[$index]) { throw new RuntimeException("Incorrect personal $option for converted membership $id"); }
	}
}
$r = $db->sql_query('SELECT COUNT(*) AS n FROM ' . ACL_USERS_TABLE . ' WHERE forum_id=15 AND user_id=888888');
if ((int) $db->sql_fetchfield('n') !== 0) { throw new RuntimeException('A retired personal membership survived as a native assignment'); }
$db->sql_freeresult($r);
access_check(ANONYMOUS,8,true,false);
access_check(ANONYMOUS,9,false,false);
access_check(89,9,false,false);
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
