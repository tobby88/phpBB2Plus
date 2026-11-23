<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }

// Start of the day shown by create_date(), expressed as a UTC timestamp for SQL.
function phpbb_activity_day_start($now, $timezone)
{
	$local = phpbb_date_timestamp($now, $timezone);
	if ($local === null) { $local = $now; }
	$seconds = (($local % 86400) + 86400) % 86400;
	return $now - $seconds;
}

function phpbb_daily_visitor_data($db, $now, $timezone, $with_guests, $show_hidden_names)
{
	$now = (int) $now;
	$start = phpbb_activity_day_start($now, $timezone);
	$hour = $now - 3600;
	$guests = $recent = $visible = $hidden = 0;
	if ($with_guests)
	{
		$sql = 'SELECT COUNT(*) AS guests, COUNT(CASE WHEN last_seen >= ' . $hour . ' THEN 1 END) AS recent
			FROM (SELECT MAX(session_time) AS last_seen FROM ' . SESSIONS_TABLE . '
				WHERE session_user_id = ' . ANONYMOUS . "
				AND session_time BETWEEN $start AND $now GROUP BY session_ip) daily_guests";
		if (!($result = $db->sql_query($sql)))
		{
			message_die(GENERAL_ERROR, 'Could not obtain daily guest totals', '', __LINE__, __FILE__, $sql);
		}
		$row = $db->sql_fetchrow($result);
		$db->sql_freeresult($result);
		$guests = (int) $row['guests'];
		$recent = (int) $row['recent'];
	}
	$sql = 'SELECT user_id, username, user_allow_viewonline, user_session_time FROM ' . USERS_TABLE . "
		WHERE user_id > 0 AND user_session_time BETWEEN $start AND $now ORDER BY username, user_id";
	if (!($result = $db->sql_query($sql)))
	{
		message_die(GENERAL_ERROR, 'Could not obtain daily visitor activity', '', __LINE__, __FILE__, $sql);
	}
	$members = array();
	while ($row = $db->sql_fetchrow($result))
	{
		if ($row['user_session_time'] >= $hour) { $recent++; }
		if ($row['user_allow_viewonline']) { $visible++; }
		else
		{
			$hidden++;
			if (!$show_hidden_names) { continue; }
		}
		$members[] = $row;
	}
	$db->sql_freeresult($result);
	return array('members' => $members, 'recent' => $recent, 'guests' => $guests,
		'visible' => $visible, 'hidden' => $hidden);
}

function phpbb_daily_visitors($db, $now, $timezone, $with_guests, $show_hidden_names)
{
	global $lang;
	$activity = phpbb_daily_visitor_data($db, $now, $timezone, $with_guests, $show_hidden_names);
	$names = array();
	foreach ($activity['members'] as $member)
	{
		$link = color_group_colorize_name($member['user_id'], false, $member['username']);
		$names[] = $member['user_allow_viewonline'] ? $link : '<i>' . $link . '</i>';
	}
	$guests = $activity['guests'];
	$recent = $activity['recent'];
	$visible = $activity['visible'];
	$hidden = $activity['hidden'];
	$total = $guests + $visible + $hidden;
	$total_key = $total === 0 ? 'Users_today_zero_total' : ($total === 1 ? 'User_today_total' : 'Users_today_total');
	$summary = sprintf($lang[$total_key], $total);
	$last_hour = '';
	if ($with_guests)
	{
		$summary .= sprintf($lang[$visible === 0 ? 'Reg_users_zero_total' : ($visible === 1 ? 'Reg_user_total' : 'Reg_users_total')], $visible);
		$summary .= sprintf($lang[$hidden === 0 ? 'Hidden_users_zero_total' : ($hidden === 1 ? 'Hidden_user_total' : 'Hidden_users_total')], $hidden);
		$summary .= sprintf($lang[$guests === 0 ? 'Guest_users_zero_total' : ($guests === 1 ? 'Guest_user_total' : 'Guest_users_total')], $guests);
		$last_hour = $recent ? sprintf($lang['Users_lasthour_explain'], $recent) : $lang['Users_lasthour_none_explain'];
	}
	return array('names' => $names ? implode(', ', $names) : $lang['None'], 'summary' => $summary, 'last_hour' => $last_hour);
}

function phpbb_daily_registrations($db, $now, $timezone)
{
	$now = (int) $now;
	$today = phpbb_activity_day_start($now, $timezone);
	$yesterday = $today - 86400;
	$sql = "SELECT COUNT(CASE WHEN user_regdate >= $today THEN 1 END) AS today,
			COUNT(CASE WHEN user_regdate < $today THEN 1 END) AS yesterday FROM " . USERS_TABLE . "
		WHERE user_id > 0 AND user_regdate BETWEEN $yesterday AND $now";
	if (!($result = $db->sql_query($sql)))
	{
		message_die(GENERAL_ERROR, 'Could not obtain daily registration totals', '', __LINE__, __FILE__, $sql);
	}
	$row = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);
	return array('today' => (int) $row['today'], 'yesterday' => (int) $row['yesterday']);
}

function phpbb_daily_birthdays($db, $viewer, $now, $timezone, $days)
{
	// Profiles and the calendar show birthdays to signed-in members only.
	if (empty($viewer['session_logged_in']) || empty($viewer['user_id']) || (int) $viewer['user_id'] <= 0 || $days < 1)
	{
		return false;
	}
	$local = phpbb_date_timestamp($now, $timezone);
	if ($local === null) { return false; }
	$today = (new DateTimeImmutable('@' . $local))->setTime(0, 0);
	$dates = array();
	// The next occurrence of each birthday is at most one calendar year away.
	$end = $today->modify('+' . min(366, (int) $days) . ' days');
	for ($date = $today; $date <= $end; $date = $date->modify('+1 day'))
	{
		$key = $date->format('md');
		if (!isset($dates[$key])) { $dates[$key] = $date; }
		// The calendar celebrates February 29 on March 1 in ordinary years.
		if ($key === '0301' && $date->format('L') === '0' && !isset($dates['0229']))
		{
			$dates['0229'] = $date;
		}
	}
	// The Birthday MOD stores signed whole days since 1970. Ignore its
	// unspecified-date sentinel and only return members in the requested window.
	$sql = "SELECT user_id, username, YEAR(born) AS birth_year, DATE_FORMAT(born, '%m%d') AS birthday
		FROM (SELECT user_id, username, DATE_ADD('1970-01-01', INTERVAL user_birthday DAY) AS born
			FROM " . USERS_TABLE . " WHERE user_id > 0
			AND user_birthday BETWEEN DATEDIFF('1901-01-01', '1970-01-01') AND DATEDIFF('2099-12-31', '1970-01-01')) members
		WHERE DATE_FORMAT(born, '%m%d') IN ('" . implode("','", array_keys($dates)) . "')
		ORDER BY username, user_id";
	if (!($result = $db->sql_query($sql)))
	{
		message_die(GENERAL_ERROR, 'Could not read user birthdays', '', __LINE__, __FILE__, $sql);
	}
	$birthdays = array('today' => array(), 'upcoming' => array());
	try
	{
		while ($row = $db->sql_fetchrow($result))
		{
			$occurrence = $dates[$row['birthday']];
			$age = (int) $occurrence->format('Y') - (int) $row['birth_year'];
			if ($age < 0) { continue; }
			$period = $occurrence == $today ? 'today' : 'upcoming';
			$birthdays[$period][] = array('id' => (int) $row['user_id'], 'username' => $row['username'], 'age' => $age);
		}
	}
	finally
	{
		$db->sql_freeresult($result);
	}
	return $birthdays;
}

function phpbb_display_daily_birthdays($db, $template, $viewer, $now, $timezone, $days)
{
	global $lang, $phpEx;
	$birthdays = phpbb_daily_birthdays($db, $viewer, $now, $timezone, $days);
	if ($birthdays === false) { return; }
	$lists = array();
	foreach ($birthdays as $period => $members)
	{
		$links = array();
		foreach ($members as $member)
		{
			$url = append_sid('profile.' . $phpEx . '?mode=viewprofile&amp;' . POST_USERS_URL . '=' . $member['id']);
			$name = color_group_colorize_name($member['id'], true, $member['username']);
			$links[] = '<a href="' . $url . '" class="gensmall">' . $name . ' (' . $member['age'] . ')</a>';
		}
		$lists[$period] = implode(', ', $links);
	}
	$template->assign_vars(array(
		'L_WHOSBIRTHDAY_TODAY' => $lists['today'] === '' ? $lang['Nobirthday_today'] : $lang['Birthday_today'] . $lists['today'],
		'L_WHOSBIRTHDAY_WEEK' => $days > 1
			? sprintf($lists['upcoming'] === '' ? $lang['Nobirthday_week'] : $lang['Birthday_week'], $days) . $lists['upcoming'] : ''
	));
	$template->assign_block_vars('switch_show_birthday', array());
}
