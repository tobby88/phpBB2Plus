<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge(isset($lang) ? $lang : array(), array(
	'PLUS_CALENDAR' => 'Calendar',
	'PLUS_CAL_DATE' => 'Date',
	'PLUS_CAL_ALL' => 'All forums',
	'PLUS_CAL_MONTH' => 'Month view',
	'PLUS_CAL_DAY' => 'Day view',
	'PLUS_CAL_HOURS' => 'Choose an hour',
	'PLUS_CAL_BIRTHDAY' => 'Birthday',
	'PLUS_CAL_EMPTY' => 'There are no entries for this period.',
	'ACL_F_PLUS_CALENDAR' => 'Can create and edit calendar events on topics',
	'PLUS_CAL_EVENT' => 'Calendar event',
	'PLUS_CAL_START' => 'Start', 'PLUS_CAL_END' => 'End (optional)',
	'PLUS_CAL_START_TIME' => 'Start time', 'PLUS_CAL_END_TIME' => 'End time',
	'PLUS_CAL_REMOVE' => 'Remove event from the calendar',
	'PLUS_CAL_TIMES_HELP' => 'Leave times empty for an all-day event. The end date is included.',
	'PLUS_CAL_INVALID' => 'Check the dates and times. The end must be at or after the start.',
	'PLUS_CAL_CONFLICT' => 'This event has changed. Reopen the editor to see its current dates.',
));
