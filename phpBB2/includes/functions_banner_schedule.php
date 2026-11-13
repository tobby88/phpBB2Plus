<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }

// Both consumers supply the same civil clock in the board's configured zone.
// Weekday/date restrictions apply to that current civil day, including when
// a daily hour range spans midnight. Equal endpoints retain one minute/day.
function phpbb_banner_schedule_sql($hour, $weekday, $date)
{
	if (!is_int($hour) || $hour < 0 || $hour > 2359 || $hour % 100 > 59
		|| !is_int($weekday) || $weekday < 0 || $weekday > 6
		|| !is_int($date) || $date < 10000101 || $date > 99991231
		|| !checkdate((int)(($date % 10000) / 100), $date % 100, (int)($date / 10000))) { return '1=0'; }
	$hours = 'time_begin BETWEEN 0 AND 2359 AND time_end BETWEEN 0 AND 2359'
		. ' AND MOD(time_begin,100)<=59 AND MOD(time_end,100)<=59'
		. ' AND ((time_begin<=time_end AND ' . $hour . ' BETWEEN time_begin AND time_end)'
		. ' OR (time_begin>time_end AND (' . $hour . '>=time_begin OR ' . $hour . '<=time_end)))';
	$weekdays = 'date_begin BETWEEN 0 AND 6 AND date_end BETWEEN 0 AND 6'
		. ' AND ((date_begin<=date_end AND ' . $weekday . ' BETWEEN date_begin AND date_end)'
		. ' OR (date_begin>date_end AND (' . $weekday . '>=date_begin OR ' . $weekday . '<=date_end)))';
	return '(banner_timetype=0 OR ((' . $hours . ') AND (banner_timetype=2'
		. ' OR (banner_timetype=4 AND (' . $weekdays . '))'
		. ' OR (banner_timetype=6 AND ' . $date . ' BETWEEN date_begin AND date_end))))';
}
