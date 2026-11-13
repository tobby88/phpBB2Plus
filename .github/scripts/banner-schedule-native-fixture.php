<?php
if (PHP_SAPI !== 'cli' || !defined('IN_PHPBB')) { return; }
eval('namespace BannerScheduleClock; function time(){return $GLOBALS["bs_clock"];}');

function bs_selection($header, $changes, $stamp, $timezone, $expected, $locale)
{
    global $db, $userdata, $phpbb_root_path, $phpEx, $board_config, $bs_clock;
    ob_prepare($locale);
    $board_config['board_timezone'] = $timezone;
    $bs_clock = strtotime($stamp . ' UTC');
    $assignments = array();
    foreach ($changes as $field=>$value) { $assignments[] = $field . '=' . (int)$value; }
    if ($assignments) { tn_sql('UPDATE fixture_banner SET ' . implode(',', $assignments)); }
    $before = tp_snapshot();
    // Freeze only the clock input; execute the actual header's complete banner
    // query construction, including its call into the shared schedule helper.
    eval('namespace BannerScheduleClock;' . $header);
    $result = $db->sql_query($sql);
    if ($result === false) { $error = $db->sql_error(); throw new RuntimeException('Actual banner header selection query: ' . $error['code'] . ' ' . $error['message']); }
    try { $banners = $db->sql_fetchrowset($result); }
    finally { $db->sql_freeresult($result); }
    $reader = new PhpbbOnlineReader($db);
    try {
        $viewer = $reader->authenticated_user($userdata);
        nt_check($viewer !== false, 'Current own session for actual online projection');
        $locations = $reader->banners(array(array('session_page'=>PAGE_REDIRECT, 'session_topic'=>1,
            'session_logged_in'=>1, 'user_allow_viewonline'=>1)), $viewer, array(3,4), $bs_clock);
    } finally { $reader->release(); }
    nt_check((count($banners) === 1) === $expected && isset($locations[1]) === $expected,
        'Header and online projection agree with the expected civil interval: ' . $stamp);
    nt_check(tp_snapshot() === $before && tp_released(), 'Schedule selectors preserve all content, counters and caller work');
}

function tp_banner_schedule()
{
    global $tn_app;
    $source = file_get_contents($tn_app . 'includes/page_header.php');
    $a = strpos($source, "\trequire_once(\$phpbb_root_path . 'includes/functions_banner_schedule.'");
    $b = strpos($source, 'if ( !($result = $db->sql_query($sql)) )', $a);
    nt_check($a !== false && $b > $a, 'Actual full header selection and shared schedule helper call');
    $header = substr($source, $a, $b-$a);
    $cases = array();
    foreach (array(array('21:59',false),array('22:00',true),array('23:59',true),array('00:00',true),array('02:00',true),array('02:01',false),array('12:00',false)) as $sample) {
        $cases[] = array(array('banner_timetype'=>2,'time_begin'=>2200,'time_end'=>200), '2026-11-13 '.$sample[0].':00',0,$sample[1]);
    }
    foreach (array(array('08:59',false),array('09:00',true),array('12:00',true),array('17:00',true),array('17:01',false)) as $sample) {
        $cases[] = array(array('banner_timetype'=>2,'time_begin'=>900,'time_end'=>1700), '2026-11-13 '.$sample[0].':00',0,$sample[1]);
    }
    foreach (array(array('11:59',false),array('12:00',true),array('12:01',false)) as $sample) {
        $cases[] = array(array('banner_timetype'=>2,'time_begin'=>1200,'time_end'=>1200), '2026-11-13 '.$sample[0].':00',0,$sample[1]);
    }
    foreach (array(array('00:00',true),array('00:01',false)) as $sample) {
        $cases[] = array(array('banner_timetype'=>2,'time_begin'=>0,'time_end'=>0), '2026-11-13 '.$sample[0].':00',0,$sample[1]);
    }
    foreach (array(array('2026-11-12',false),array('2026-11-13',true),array('2026-11-14',true),array('2026-11-15',true),array('2026-11-16',true),array('2026-11-17',false)) as $sample) {
        $cases[] = array(array('banner_timetype'=>4,'date_begin'=>5,'date_end'=>1),$sample[0].' 12:00:00',0,$sample[1]);
    }
    $cases[] = array(array('banner_timetype'=>4,'date_begin'=>0,'date_end'=>0),'2026-11-15 12:00:00',0,true);
    $cases[] = array(array('banner_timetype'=>4,'date_begin'=>0,'date_end'=>0),'2026-11-14 12:00:00',0,false);
    $cases[] = array(array('banner_timetype'=>4,'date_begin'=>1,'date_end'=>5),'2026-11-13 12:00:00',0,true);
    $cases[] = array(array('banner_timetype'=>4,'date_begin'=>1,'date_end'=>5),'2026-11-14 12:00:00',0,false);
    foreach (array(array('2026-11-13 23:00:00',true),array('2026-11-14 01:00:00',true),array('2026-11-16 01:00:00',true),array('2026-11-16 23:00:00',true),array('2026-11-17 01:00:00',false)) as $sample) {
        $cases[] = array(array('banner_timetype'=>4,'date_begin'=>5,'date_end'=>1,'time_begin'=>2200,'time_end'=>200),$sample[0],0,$sample[1]);
    }
    foreach (array(array('2026-11-12 23:00:00',false),array('2026-11-13 01:00:00',true),array('2026-11-13 12:00:00',false),array('2026-11-15 23:00:00',true),array('2026-11-16 01:00:00',false)) as $sample) {
        $cases[] = array(array('banner_timetype'=>6,'date_begin'=>20261113,'date_end'=>20261115,'time_begin'=>2200,'time_end'=>200),$sample[0],0,$sample[1]);
    }
    $cases[] = array(array('banner_timetype'=>6,'date_begin'=>20261115,'date_end'=>20261113),'2026-11-14 12:00:00',0,false);
    foreach (array(array('time_begin'=>2399),array('time_end'=>2360),array('time_begin'=>-1),array('time_end'=>2400)) as $invalid) {
        $cases[] = array(array_merge(array('banner_timetype'=>2),$invalid),'2026-11-13 12:00:00',0,false);
    }
    $cases[] = array(array('banner_timetype'=>4,'date_begin'=>-1,'date_end'=>6),'2026-11-13 12:00:00',0,false);
    $cases[] = array(array('banner_timetype'=>4,'date_begin'=>0,'date_end'=>7),'2026-11-13 12:00:00',0,false);
    $cases[] = array(array('banner_timetype'=>99),'2026-11-13 12:00:00',0,false);
    $cases[] = array(array('banner_timetype'=>0,'time_begin'=>-1,'time_end'=>9999,'date_begin'=>-1,'date_end'=>99),'2026-11-13 12:00:00',0,true);
    $cases[] = array(array('banner_timetype'=>4,'date_begin'=>6,'date_end'=>6,'time_begin'=>2200,'time_end'=>200),'2026-11-13 20:30:00',5.5,true);
    $cases[] = array(array('banner_timetype'=>4,'date_begin'=>5,'date_end'=>5,'time_begin'=>2200,'time_end'=>200),'2026-11-14 03:30:00',-5.5,true);
    $cases[] = array(array('banner_timetype'=>2,'time_begin'=>2200,'time_end'=>200),'2026-11-13 20:15:00',5.75,true);
    $pairs = 0;
    foreach (array('english','german') as $locale) {
        foreach ($cases as $case) { bs_selection($header,$case[0],$case[1],$case[2],$case[3],$locale); $pairs++; }
    }
    foreach (array(array('900',5,20261113),array(1260,5,20261113),array(900,7,20261113),array(900,5,20260230),array(900,5,'20261113 OR 1=1')) as $invalid) {
        nt_check(call_user_func_array('phpbb_banner_schedule_sql',$invalid)==='1=0','Invalid civil clock inputs never form an active or interpolated predicate');
    }
    echo 'Native banner schedule: '.$pairs.' actual selection pairs, 5 invalid clock cases PASS; overnight and cyclic weekdays, inclusive endpoints, literal dates, current civil day, board timezones, unchanged counters and EN/DE.' . "\n";
}
