<?php
namespace MiniCalendarTopicFixture;

if (PHP_SAPI !== 'cli' || !isset($schema) || !preg_match('/^phpbb_calendar_birthdays_[a-f0-9]{12}$/D', $schema)) {
    throw new \RuntimeException('Owned calendar database required');
}
const MINI_CAL_DAYS_AHEAD = 3;
if (!defined('IN_MINI_CAL')) { define('IN_MINI_CAL', true); }
require_once $app.'includes/mini_cal/mini_cal_config.php';
$source = file_get_contents($app.'includes/mini_cal/mini_cal_TOPIC.php');
foreach (array('getMiniCalForumsAuth','getMiniCalEventDays','getMiniCalEvents','getMiniCalSearchURL','miniCalTopicDate') as $name) {
    eval('namespace MiniCalendarTopicFixture;'.\calendar_fixture_function($source, $name));
}
$source = file_get_contents($app.'includes/mini_cal/mini_cal_common.php');
foreach (array('miniCalForumIds','getFormattedDate') as $name) {
    eval('namespace MiniCalendarTopicFixture;'.\calendar_fixture_function($source, $name));
}
function auth($type, $scope, $user)
{
    \cn_check($type === AUTH_ALL && $scope === AUTH_LIST_ALL, 'Calendar asks for read, view and event posting permissions together');
    return array(60=>array('auth_view'=>1,'auth_read'=>1,'auth_post'=>1,'auth_cal'=>1),
        61=>array('auth_view'=>1,'auth_read'=>0,'auth_post'=>0,'auth_cal'=>0),
        62=>array('auth_view'=>1,'auth_read'=>1,'auth_post'=>1,'auth_cal'=>0));
}
class MiniCalendarTemplate
{
    var $rows = array();
    function assign_block_vars($name, $values) { $this->rows[$name][] = $values; }
}
date_default_timezone_set('Europe/Berlin');
$db->readonly = false;
$values = array();
foreach (array(
    array(801,60,'2025-02-28 22:00','2025-03-02 01:00',0),
    array(802,60,'2025-03-29 23:30','2025-03-31 00:10',0),
    array(803,60,'2025-03-02 07:00','2025-03-07 08:00',TOPIC_MOVED),
    array(804,61,'2025-03-01 06:00','2025-03-08 07:00',0),
    array(805,60,'2025-03-03 12:00','2025-03-03 12:00',0),
    array(806,60,'2025-03-03 12:00','2025-03-03 14:00',0),
    array(807,60,'2025-03-05 00:00','2025-03-05 01:00',0)
) as $event) {
    list($id,$forum,$start_text,$end_text,$status) = $event;
    $start = strtotime($start_text); $duration = $id === 805 ? 'NULL' : strtotime($end_text) - $start;
    $values[] = "($id,$forum,'Calendar $id',$start,$duration,$status)";
}
\cn_check((bool)$db->sql_query('INSERT INTO fixture_topics (topic_id,forum_id,topic_title,topic_calendar_time,topic_calendar_duration,topic_status) VALUES '.implode(',', $values)), 'Seed month and DST boundaries');
$db->readonly = true;
$permissions = getMiniCalForumsAuth(array());
\cn_check($permissions === array('view'=>'60,62','post'=>'60'), 'Mini-calendar offers readable forums and event-composer destinations');
$mini_cal_this_month = 3; $mini_cal_this_year = 2025;
\cn_check(getMiniCalEventDays($permissions['view']) === array(1,2,3,5,29,30,31), 'Grid clips overlaps to March and marks all local dates through the spring clock change');
$mini_cal_this_month = 2;
\cn_check(getMiniCalEventDays($permissions['view']) === array(28), 'The same event marks the last day of February');
$mini_cal_today = '20250301'; $mini_cal_this_year = 2026; $mini_cal_this_month = 11;
$phpbb_root_path = './forum/'; $phpEx = 'php';
require $app.'language/lang_english/lang_main_mini_cal.php';
$template = new MiniCalendarTemplate();
set_error_handler(function ($severity, $message) { if (error_reporting() & $severity) { throw new \RuntimeException($message); } });
try {
    getMiniCalEvents($permissions);
    \cn_check(miniCalTopicDate(phpbb_calendar_clock(strtotime('2025-03-02 00:04:05')), '%a %h:%i:%s %p') === 'Su 12:04:05 AM' &&
        miniCalTopicDate(phpbb_calendar_clock(strtotime('2025-03-03 13:09:00')), '%a %h:%i %p') === 'Mo 01:09 PM' &&
        miniCalTopicDate(phpbb_calendar_clock(2147472000, 172800), '%Y-%m-%d') === '2038-01-21',
        'Sunday, midnight and afternoon event times use the configured weekday labels and clock format');
} finally { restore_error_handler(); }
\cn_check(array_column($template->rows['mini_cal_events'], 'U_MINI_CAL_EVENT') === array(
    './forum/viewtopic.php?t=801','./forum/viewtopic.php?t=805','./forum/viewtopic.php?t=806'),
    'Current and upcoming events use today, retain forum paths and break ties by topic ID');
\cn_check(getMiniCalSearchURL('20250303') === './forum/calendar_scheduler.php?d='.strtotime('2025-03-03'), 'Day link retains the forum directory');
echo "Native mini-calendar: forum choices, month boundaries, DST and upcoming event links passed.\n";
