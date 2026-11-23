<?php
namespace RecentTopicsFixture;

function check_daily_activity($db)
{
	global $phpbb_root_path, $lang;
	require_once $phpbb_root_path . 'includes/functions_daily_activity.php';
	$zone = 5.75;
	$midnight = gmmktime(18, 15, 0, 2, 28, 2026);
	$now = $midnight + 7200;
	check(\phpbb_activity_day_start($now, $zone) === $midnight, 'Quarter-hour offset yields the local day boundary');
	check(\phpbb_activity_day_start(gmmktime(3, 30, 0, 1, 1, 2026), -3.5) === gmmktime(3, 30, 0, 1, 1, 2026),
		'Negative half-hour offset yields local midnight at New Year');
	foreach (array(
		array(21, 'Visible member', 1, $now - 60, $midnight),
		array(22, 'Hidden member', 0, $midnight, $midnight - 1),
		array(23, 'Earlier member', 1, $midnight - 1, $midnight - 86400),
		array(24, 'Future member', 1, $now + 1, $now + 1)
	) as $member)
	{
		list($id, $name, $visible, $seen, $registered) = $member;
		seed('fixture_users', array('user_id' => $id, 'username' => $name, 'user_allow_viewonline' => $visible,
			'user_session_time' => $seen, 'user_lastlogon' => $midnight - 100, 'user_regdate' => $registered));
	}
	foreach (array(
		array('a', '00000001', $midnight), array('b', '00000001', $now - 3600),
		array('c', '00000002', $midnight), array('d', '00000003', $midnight - 1)
	) as $guest)
	{
		seed('fixture_sessions', array('session_id' => str_repeat($guest[0], 32), 'session_ip' => $guest[1],
			'session_time' => $guest[2], 'session_user_id' => ANONYMOUS));
	}
	$data = \phpbb_daily_visitor_data($db, $now, $zone, true, false);
	check($data['guests'] === 2 && $data['visible'] === 1 && $data['hidden'] === 1 && $data['recent'] === 2,
		'Latest activity counts one member and one distinct guest address within the last hour');
	check(array_column($data['members'], 'username') === array('Visible member'), 'Regular daily list contains visible names');
	$data = \phpbb_daily_visitor_data($db, $now, $zone, false, true);
	check($data['guests'] === 0 && $data['recent'] === 1
		&& array_column($data['members'], 'username') === array('Hidden member', 'Visible member'),
		'Member-only mode omits guest traffic and preserves the admin list order');
	check(\phpbb_daily_registrations($db, $now, $zone) === array('today' => 1, 'yesterday' => 2),
		'Registration totals honor local midnight on both sides of the month boundary');
	foreach (array('english', 'german') as $locale)
	{
		require $phpbb_root_path . 'language/lang_' . $locale . '/lang_main.php';
		$empty = \phpbb_daily_visitors($db, $midnight - 3 * 86400, $zone, true, false);
		check($empty['names'] === $lang['None'] && $empty['last_hour'] === $lang['Users_lasthour_none_explain'],
			'Empty activity is rendered in the current visitor language ' . $locale);
	}
	echo "Daily activity: local midnight, distinct visits, recent activity, registration dates and localized lists passed.\n";
}
