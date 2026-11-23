<?php
// Exercise the same read functions as groupcp.php with the canonical forum tables.
class GroupRosterFixtureReader
{
	var $db;
	var $queries = array();
	function __construct($db) { $this->db = $db; }
	function sql_query($sql) { $this->queries[] = $sql; return $this->db->sql_query($sql); }
	function sql_fetchrowset($result) { return $this->db->sql_fetchrowset($result); }
	function sql_freeresult($result) { return $this->db->sql_freeresult($result); }
}

function pp_group_roster_cases($database)
{
	global $ats_source;
	if (!defined('GROUP_HIDDEN')) { define('GROUP_HIDDEN', 2); }
	ats_load_function($ats_source . 'groupcp.php', 'groupcp_roster_rows');
	ats_load_function($ats_source . 'groupcp.php', 'groupcp_roster');
	$db = new GroupRosterFixtureReader($database);
	pp_sql('DELETE FROM fixture_user_group');
	pp_sql('DELETE FROM fixture_users');
	pp_sql("INSERT INTO fixture_users (user_id, username, user_password, user_email, user_absence, user_absence_mode)
		VALUES (1,'Leader','','',0,0),(2,'Alpha','','',0,0),(3,'Bravo','','',0,0),(4,'Charlie','','',0,0),
			(5,'Delta','','',0,0),(6,'Echo','','',1,1),(7,'Other','','',0,0),(8,'Root','','',0,0)");
	pp_sql('INSERT INTO fixture_user_group (group_id, user_id, user_pending)
		VALUES (42,1,0),(42,2,0),(42,3,0),(42,3,0),(42,4,0),(42,5,0),(42,5,1),
			(42,6,1),(42,6,1),(42,99,0),(42,98,1),(43,7,0)');
	$viewer = array('session_logged_in'=>true, 'user_id'=>7, 'user_level'=>0);
	$hidden = groupcp_roster($db, 42, GROUP_HIDDEN, 1, $viewer, 80, 2);
	ats_check(!$hidden['visible'] && !$hidden['member'] && !$hidden['pending_member']
		&& $hidden['members'] === array() && $hidden['total'] === 0 && count($db->queries) === 1,
		'Hidden group reads only the viewer’s membership, with no roster or size-dependent page links');
	$viewer['user_id'] = 6;
	$pending = groupcp_roster($db, 42, GROUP_HIDDEN, 1, $viewer, 0, 2);
	ats_check(!$pending['visible'] && $pending['pending_member'] && $pending['requests'] === array(),
		'Applicant sees their own status and cannot see the roster');
	$viewer['user_id'] = 5;
	$db->queries = array();
	$first = groupcp_roster($db, 42, GROUP_HIDDEN, 1, $viewer, 0, 2);
	ats_check($first['visible'] && $first['member'] && !$first['pending_member'] && !$first['can_manage']
		&& $first['total'] === 4 && $first['pages'] === 2 && $first['requests'] === array()
		&& array_map('intval', array_column($first['members'], 'user_id')) === array(2,3)
		&& count($db->queries) === 3, 'Approved member on another page sees a distinct, bounded roster');
	$last = groupcp_roster($db, 42, GROUP_HIDDEN, 1, $viewer, 999, 2);
	ats_check($last['page'] === 2 && $last['start'] === 2
		&& array_map('intval', array_column($last['members'], 'user_id')) === array(4,5),
		'Last page clamps to real members and duplicate legacy rows do not shift its boundaries');
	foreach (array(1=>0, 8=>ADMIN) as $id=>$level)
	{
		$viewer['user_id'] = $id;
		$viewer['user_level'] = $level;
		$page = groupcp_roster($db, 42, GROUP_HIDDEN, 1, $viewer, -4, 0);
		ats_check($page['can_manage'] && $page['per_page'] > 0 && $page['page'] === 1 && $page['pages'] === 1
			&& array_map('intval', array_column($page['requests'], 'user_id')) === array(6)
			&& (int) $page['requests'][0]['user_absence_mode'] === 1,
			'Leader and site admin see pending members with absence details, excluding approvals and orphan records');
	}
	$viewer = array('session_logged_in'=>true, 'user_id'=>7, 'user_level'=>0);
	$open = groupcp_roster($db, 42, 0, 1, $viewer, 0, 15);
	ats_check($open['visible'] && !$open['member'] && $open['total'] === 4 && $open['requests'] === array(),
		'Open roster remains visible to a logged-in nonmember');
	echo "Group roster: hidden groups, membership status, page bounds and moderator queues passed.\n";
}
