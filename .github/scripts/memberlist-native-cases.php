<?php
// Uses the canonical, disposable user table from the public-profile fixture.
function pp_memberlist_cases($db)
{
	global $ats_source;
	require_once $ats_source . 'includes/functions_memberlist.php';
	pp_sql('DELETE FROM fixture_users');
	$empty = phpbb_memberlist_page($db, false, 'joined', 'ASC', 100, 0, array());
	ats_check($empty['total'] === 0 && $empty['rows'] === array() && $empty['page'] === 1 && $empty['pages'] === 1,
		'Empty member directory has a valid page even with an old zero page size');

	$values = array("(-1,'Guest','','',999,0,NULL)");
	for ($id = 1; $id <= 13; $id++)
	{
		$posts = (int) floor($id / 2);
		$visible = $id > 2 ? 1 : 0;
		$email = sprintf('%02d@example.invalid', 20 - $id);
		$values[] = "($id,'Member $id','','$email',$posts,$visible,'Grüße $id')";
	}
	pp_sql('INSERT INTO fixture_users (user_id, username, user_password, user_email, user_posts, user_viewemail, user_custom_fixture) VALUES ' . implode(',', $values));
	$fields = array(array('field_name' => 'Fixture', 'field_column' => 'user_custom_fixture'));
	$page = phpbb_memberlist_page($db, false, 'lastlogon', '', 999, 6, $fields);
	ats_check(!isset($page['modes']['lastlogon']) && isset($page['modes']['joined']) && $page['mode'] === 'joined'
		&& $page['total'] === 13 && $page['start'] === 12 && $page['page'] === 3 && count($page['rows']) === 1
		&& $page['rows'][0]['user_custom_fixture'] === 'Grüße 13', 'Member sort choices, custom fields and last page agree');
	$page = phpbb_memberlist_page($db, true, 'lastlogon', 'DESC', 0, 6, array());
	ats_check(isset($page['modes']['lastlogon']) && $page['mode'] === 'lastlogon', 'Admin retains last login ordering');

	foreach (array('' => array(12,13,10,11,8,9,6,7,4,5), 'ASC' => array(4,5,6,7,8,9,10,11,12,13)) as $order => $expected)
	{
		$members = array();
		foreach (array(0,3,6,9) as $start)
		{
			$page = phpbb_memberlist_page($db, false, 'topten', $order, $start, 3, array());
			ats_check($page['total'] === 10 && $page['pages'] === 4, 'Top Ten has exactly ten ranked members');
			$members = array_merge($members, array_map('intval', array_column($page['rows'], 'user_id')));
		}
		ats_check($members === $expected, 'Both directions page through the same ten leading members, including ties');
	}
	$page = phpbb_memberlist_page($db, false, 'email', 'ASC', 0, 15, array());
	ats_check(array_slice(array_map('intval', array_column($page['rows'], 'user_id')), 0, 3) === array(1,2,13),
		'Private email preferences keep hidden values out of member-visible ordering');
	echo "Member directory: sort choices, actual Top Ten, display order, custom fields and pagination passed.\n";
}
