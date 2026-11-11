<?php
if (PHP_SAPI !== 'cli') { exit(2); }
date_default_timezone_set('UTC');
define('IN_PHPBB', true); define('ANONYMOUS', -1);
define('USERS_TABLE', 'fixture_users'); define('POSTS_TABLE', 'fixture_posts');
define('AUTH_VIEW', 0);
define('GENERAL_ERROR', 202);
error_reporting(E_ALL);
set_error_handler(function($severity, $message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } });
$sp_root = dirname(dirname(__DIR__)) . '/phpBB2/';
require $sp_root . 'includes/php_compat.php';
$sp_cases = $sp_checks = 0;
function sp_check($value, $message) { if (!$value) { throw new RuntimeException($message); } $GLOBALS['sp_checks']++; }
// Freeze only PHP clock calls; execute the actual calendar helper, date display
// function and entire shipped modules, without common.php or a forum session.
function sp_now() { return $GLOBALS['sp_clock']; }
function sp_date($format, $timestamp = null) { return date($format, $timestamp === null ? sp_now() : $timestamp); }
function sp_freeze($source) {
    $tokens = token_get_all('<?php ' . $source); $out = '';
    foreach ($tokens as $token) {
        if (is_array($token)) {
            if ($token[0] === T_OPEN_TAG) { continue; }
            $out .= $token[0] === T_STRING && in_array(strtolower($token[1]), array('time','date'), true)
                ? (strtolower($token[1]) === 'time' ? 'sp_now' : 'sp_date') : $token[1];
        } else { $out .= $token; }
    }
    return $out;
}
$sp_functions = file_get_contents($sp_root . 'includes/functions.php');
foreach (array('phpbb_date_number','phpbb_date_integer_sum','phpbb_date_timestamp','phpbb_date_text','create_date','phpbb_stored_text') as $function) {
    sp_check(preg_match('/^function ' . $function . '\(.*?^\}/ms', $sp_functions, $sp_match) === 1, 'Actual formatter: ' . $function);
    eval(sp_freeze($sp_match[0]));
}
require $sp_root . 'includes/functions_stats.php';
require $sp_root . 'includes/functions_module.php';
class StatisticsPeriodExit extends RuntimeException {}
function message_die($type, $message) { throw new StatisticsPeriodExit($message); }
function append_sid($url) { return $url; }
class StatisticsPeriodTemplate {
    var $vars = array(); var $blocks = array();
    function assign_vars($values) { $this->vars = array_merge($this->vars, $values); }
    function assign_block_vars($name, $values) { $this->blocks[] = $values; }
}
class StatisticsPeriodBars {
    var $percentage = 0; var $bar_percent = 0;
    function init_bars() {}
    function do_math($first, $count, $total) { sp_check($first > 0 && $total > 0 && $count <= $total, 'Valid original bar inputs'); }
}
function sp_expected($period, $now, $timezone) {
    $offset = (int) (3600 * ($timezone + (int) date('I', $now)));
    $local = new DateTimeImmutable('@' . ($now + $offset));
    $start = $period === 'week' ? $local->modify('-' . ((int)$local->format('N') - 1) . ' days')->setTime(0,0,0)
        : $local->modify('first day of this month')->setTime(0,0,0);
    $end = $start->modify($period === 'week' ? '+1 week' : '+1 month');
    return array($start->getTimestamp() - $offset, $end->getTimestamp() - $offset);
}
function sp_module($period, $now, $timezone) {
    global $board_config, $lang, $phpbb_root_path;
    $GLOBALS['sp_clock'] = $now; $board_config = array('default_lang'=>'english', 'board_timezone'=>$timezone);
    $lang = array('module_name'=>'Fixture', 'Week'=>'[%s]', 'Month'=>'[%s]', 'Rank'=>'Rank', 'Percent'=>'Percent', 'Graph'=>'Graph', 'Username'=>'Username', 'Posts'=>'Posts');
    $theme = array('td_class1'=>'row1', 'td_class2'=>'row2'); $phpbb_root_path = './'; $return_limit = 10;
    $statistics = new StatisticsPeriodBars(); $template = new StatisticsPeriodTemplate();
    $stat_db = new StatisticsDB(); $stat_db->begin_cached_query();
    $source = file_get_contents($GLOBALS['sp_root'] . 'stat_modules/site_hist_' . $period . '_top_posters/module.php');
    sp_check(substr($source, 0, 5) === '<?php', 'Actual complete period module');
    eval(sp_freeze(substr($source, 5)));
    return $template;
}
function sp_vectors() {
    $out = array();
    foreach (array('UTC','Europe/Berlin','America/New_York') as $server) {
        foreach (array(-5,0,5.5,14) as $timezone) {
            foreach (array('2026-11-08 12:00:00','2026-11-02 01:00:00','2026-12-01 01:00:00','2026-12-31 23:00:00','2024-02-29 23:59:59','2025-02-28 23:59:59','2026-03-29 01:30:00','2026-10-25 01:30:00','2027-01-04 00:00:00','2026-07-05 23:59:59','2026-01-01 00:00:00','2026-01-31 23:59:59') as $clock) {
                foreach (array('week','month') as $period) { $out[] = array($server,$timezone,strtotime($clock . ' UTC'),$period); }
            }
        }
    }
    return $out;
}
function sp_rows($start, $end) {
    return array(array(7,$start-1),array(7,$start),array(7,$start+1),array(7,$end-1),array(7,$end),array(7,$end+1),array(8,$start),array(-1,$start),array(40,$start));
}
function sp_verify($template, $period, $now, $timezone) {
    $expected = sp_expected($period,$now,$timezone);
    sp_check(statistics_period_window($period,$now,$timezone) === $expected, 'Independent local-calendar interval');
    sp_check(count($template->blocks) === 2, 'Only two existing non-guest accounts in interval');
    foreach (array(3,1) as $i=>$count) {
        $row = $template->blocks[$i];
        sp_check($row['POSTS'] === $count && $row['RANK'] === $i+1, 'Inclusive midnight, exclusive next period and correct counts/order');
        sp_check(strpos($row['URL'], 'u=' . (7+$i)) !== false && $row['USERNAME'] === 'Member ' . (7+$i), 'Correct profile identity and label');
    }
    $label = $period === 'week' ? create_date('D',$expected[0],$timezone) . ' - ' . create_date('D',$now,$timezone)
        : create_date('F',$expected[0],$timezone) . ' ' . create_date('Y',$expected[0],$timezone);
    sp_check($template->vars[$period === 'week' ? 'WEEK' : 'MONTH'] === '[' . $label . ']', 'Displayed period uses the same local calendar as SQL');
    $GLOBALS['sp_cases']++;
}
