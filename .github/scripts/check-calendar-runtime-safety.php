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
$mini_cal_suite = file_get_contents($root . '/phpBB2/includes/mini_cal/calendarSuite.php');
$mini_cal_runtime = file_get_contents($root . '/phpBB2/includes/mini_cal/mini_cal.php');
$mini_cal_common = file_get_contents($root . '/phpBB2/includes/mini_cal/mini_cal_common.php');

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
calendar_runtime_assert(strpos($mini_cal_suite, 'strftime(') === false, 'Mini Calendar must not use the PHP 8.1-deprecated strftime function');
calendar_runtime_assert(strpos($mini_cal_suite, 'setlocale(') === false, 'Mini Calendar rendering must not mutate the process-wide locale');
calendar_runtime_assert(strpos($mini_cal_runtime, "max(-1200, min(1200") !== false, 'Mini Calendar month offsets must be bounded');
calendar_runtime_assert(strpos($mini_cal_runtime, "is_scalar(\$_SERVER['SCRIPT_NAME'])") !== false, 'Mini Calendar navigation must validate the current script name');
calendar_runtime_assert(strpos($mini_cal_runtime, "['PHP_SELF']") === false, 'Mini Calendar links must not reflect attacker-controlled PHP_SELF values');
calendar_runtime_assert(strpos($mini_cal_common, "http_build_query(\$params") !== false, 'Mini Calendar navigation must rebuild an allowlisted query string');
calendar_runtime_assert(strpos($mini_cal_common, "htmlspecialchars(substr((string) \$row['forum_name']") !== false, 'Mini Calendar forum options must escape stored names');

if (!defined('POST_USERS_URL'))
{
	define('POST_USERS_URL', 'u');
}
require_once $root . '/phpBB2/includes/mini_cal/mini_cal_common.php';
$_POST = array();
$_GET = array('mode' => 'personal', 'u' => '7', 'month' => '1', 'unsafe' => '\"><script>alert(1)</script>');
calendar_runtime_assert(setQueryStringVal('month', 2) === '?mode=personal&amp;u=7&amp;month=2', 'Mini Calendar query rebuilding must preserve only its allowlisted context');
$_GET = array('mode' => array('personal'), 'u' => array('7'));
calendar_runtime_assert(setQueryStringVal('month', -1) === '?month=-1', 'Mini Calendar query rebuilding must reject array-valued context');

define('IN_MINI_CAL', true);
require_once $root . '/phpBB2/includes/mini_cal/calendarSuite.php';
date_default_timezone_set('UTC');
$mini_cal = new calendarSuite();
calendar_runtime_assert($mini_cal->dayOfYear(mktime(12, 0, 0, 1, 1, 2024)) === '001', 'day-of-year formatting must retain the legacy three-digit format');
calendar_runtime_assert($mini_cal->sundayWeek(mktime(12, 0, 0, 1, 1, 2023)) === '01', 'a year beginning on Sunday must start in week 01');
calendar_runtime_assert($mini_cal->sundayWeek(mktime(12, 0, 0, 1, 1, 2024)) === '00', 'days before the first Sunday must remain in week 00');
calendar_runtime_assert($mini_cal->sundayWeek(mktime(12, 0, 0, 1, 7, 2024)) === '01', 'the first Sunday must begin week 01');
if (PHP_VERSION_ID < 80100)
{
	$comparison_start = gmmktime(12, 0, 0, 1, 1, 2023);
	for ($day = 0; $day < 731; $day++)
	{
		$timestamp = $comparison_start + ($day * 86400);
		calendar_runtime_assert($mini_cal->dayOfYear($timestamp) === strftime('%j', $timestamp), 'day-of-year compatibility differs from the legacy formatter');
		calendar_runtime_assert($mini_cal->sundayWeek($timestamp) === strftime('%U', $timestamp), 'Sunday-week compatibility differs from the legacy formatter');
	}
}

echo "Calendar runtime safety tests passed.\n";
