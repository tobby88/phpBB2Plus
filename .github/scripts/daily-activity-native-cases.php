<?php
namespace RecentTopicsFixture;

class BirthdayTemplate
{
	public $labels = array();
	public $blocks = array();
	function assign_vars($labels) { $this->labels = $labels; }
	function assign_block_vars($name, $values) { $this->blocks[] = $name; }
}

function check_daily_birthdays($db)
{
	global $phpbb_root_path, $lang;
	$viewer = array('session_logged_in' => true, 'user_id' => 7);
	$now = gmmktime(18, 14, 59, 12, 31, 2025);
	$zone = 5.75;
	$template = new BirthdayTemplate();
	foreach (array(array('session_logged_in' => false, 'user_id' => -1), array('session_logged_in' => true, 'user_id' => -1)) as $guest)
	{
		\phpbb_display_daily_birthdays(null, $template, $guest, $now, $zone, 7);
	}
	\phpbb_display_daily_birthdays(null, $template, $viewer, $now, $zone, 0);
	check(!$template->blocks && !$template->labels, 'Guests and disabled birthday lists never read birthday rows or create template labels');

	$utc = new \DateTimeZone('UTC');
	$epoch = new \DateTimeImmutable('1970-01-01', $utc);
	foreach (array(71 => '2000-12-31', 72 => '1960-01-01', 73 => '1987-01-02', 74 => '2000-02-29', 75 => '2090-01-01') as $id => $date)
	{
		$days = (int) $epoch->diff(new \DateTimeImmutable($date, $utc))->format('%r%a');
		seed('fixture_users', array('user_id' => $id, 'username' => "Birthday \\ " . $id, 'user_birthday' => $days));
	}
	$rows = \phpbb_daily_birthdays($db, $viewer, $now, $zone, 1);
	check(array_column($rows['today'], 'id') === array(71) && array_column($rows['today'], 'age') === array(25)
		&& $rows['upcoming'] === array(array('id' => 72, 'username' => 'Birthday \\ 72', 'age' => 66)),
		'Local New Year gives the next age, preserves names, and skips future birth years');
	$rows = \phpbb_daily_birthdays($db, $viewer, $now, $zone, 2);
	check(array_column($rows['upcoming'], 'id') === array(72, 73), 'Changing the configured day window takes effect immediately');
	$rows = \phpbb_daily_birthdays($db, $viewer, $now + 1, $zone, 2);
	check(array_column($rows['today'], 'id') === array(72), 'Birthdays change at local midnight');
	foreach (array(array(2023,3,1,23), array(2024,2,29,24)) as $anniversary)
	{
		list($year, $month, $day, $age) = $anniversary;
		$rows = \phpbb_daily_birthdays($db, $viewer, gmmktime(12,0,0,$month,$day,$year), 0, 7);
		check(array_column($rows['today'], 'id') === array(74) && $rows['today'][0]['age'] === $age,
			'Leap-day birthdays match the calendar in leap and ordinary years');
	}
	foreach (array('english', 'german') as $locale)
	{
		require $phpbb_root_path . 'language/lang_' . $locale . '/lang_main.php';
		$template = new BirthdayTemplate();
		\phpbb_display_daily_birthdays($db, $template, $viewer, gmmktime(12,0,0,7,1,2026), 0, 7);
		check($template->blocks === array('switch_show_birthday') && $template->labels['L_WHOSBIRTHDAY_TODAY'] === $lang['Nobirthday_today']
			&& $template->labels['L_WHOSBIRTHDAY_WEEK'] === sprintf($lang['Nobirthday_week'], 7),
			'Member birthday section renders both empty labels in ' . $locale);
	}
}

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
	check_daily_birthdays($db);
	echo "Daily activity, registration dates and member birthdays passed.\n";
}
