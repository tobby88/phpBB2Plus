<?php

function calendar_runtime_assert($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, "Calendar runtime safety test failed: $message\n");
		exit(1);
	}
}

$root = dirname(dirname(__DIR__));
$functions = file_get_contents($root . '/phpBB2/includes/functions_calendar.php');
$calendar = file_get_contents($root . '/phpBB2/calendar.php');
$scheduler = file_get_contents($root . '/phpBB2/calendar_scheduler.php');
$birthday = file_get_contents($root . '/phpBB2/birthday_popup.php');
$mini_cal_english = file_get_contents($root . '/phpBB2/language/lang_english/lang_main_mini_cal.php');
$mini_cal_german = file_get_contents($root . '/phpBB2/language/lang_german/lang_main_mini_cal.php');

calendar_runtime_assert(strpos($functions, 'function calendar_normalize_forum_filter') !== false, 'forum filters need one strict normalizer');
calendar_runtime_assert(strpos($functions, "preg_match('/^([") !== false, 'forum filters must enforce a type-and-ID grammar');
calendar_runtime_assert(substr_count($functions, "htmlspecialchars(\$row[") >= 2, 'forum and category labels must be escaped');
calendar_runtime_assert(strpos($calendar, 'checkdate($month, $day, $year)') !== false, 'month view dates must be real calendar dates');
calendar_runtime_assert(strpos($calendar, 'calendar_normalize_forum_filter') !== false, 'month view forum filters must be normalized');
calendar_runtime_assert(strpos($scheduler, 'intval($_POST[\'selected_id\'])') === false, 'scheduler must not destroy typed forum IDs before validation');
calendar_runtime_assert(strpos($scheduler, 'calendar_normalize_forum_filter') !== false, 'scheduler forum filters must be normalized');
calendar_runtime_assert(strpos($scheduler, 'min(1000000, intval($start_value))') !== false, 'scheduler pagination must be bounded');
calendar_runtime_assert(strpos($birthday, "!\$userdata['session_logged_in']") !== false, 'birthday popup must require a signed-in user');
calendar_runtime_assert(strpos($birthday, 'checkdate((int) $birthday_match[2]') !== false, 'birthday popup must reject impossible stored dates');
calendar_runtime_assert(strpos($mini_cal_english, '?>') === false && strpos($mini_cal_german, '?>') === false, 'mini-calendar language includes must not emit trailing output before headers');

echo "Calendar runtime safety tests passed.\n";
