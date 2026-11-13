<?php
if (PHP_SAPI !== 'cli' || !defined('IN_PHPBB')) { return; }

function ob_prepare($locale = 'english')
{
    global $tn_tables, $tn_app, $board_config, $lang;
    on_prepare($locale);
    if (!in_array('banner', $tn_tables, true)) {
        $canonical = file_get_contents($tn_app . 'install/schemas/mysql_schema.sql');
        nt_check(preg_match('/CREATE TABLE phpbb_banner\s*\(.*?\) ENGINE=InnoDB[^;]*;/s', $canonical, $match) === 1, 'Actual canonical banner schema');
        tn_sql(str_replace('phpbb_banner', 'fixture_banner', $match[0]));
        $tn_tables[] = 'banner';
    }
    tn_sql('DELETE FROM fixture_banner');
    ob_add(1);
    tn_sql('UPDATE fixture_sessions SET session_page=' . (int)PAGE_REDIRECT . ',session_topic=1 WHERE session_user_id=9');
    $board_config['default_lang'] = $locale;
    require $tn_app . 'language/lang_' . $locale . '/lang_banner.php';
}

function ob_add($id, $changes = array())
{
    $values = array_merge(array('banner_id'=>$id, 'banner_name'=>'Synthetic', 'banner_spot'=>16, 'banner_forum'=>0,
        'banner_description'=>'Public &amp; Grüße 😀', 'banner_url'=>'https://example.invalid/', 'banner_owner'=>7,
        'banner_click'=>0, 'banner_view'=>0, 'banner_weigth'=>50, 'banner_active'=>1, 'banner_timetype'=>0,
        'time_begin'=>0, 'time_end'=>2359, 'date_begin'=>0, 'date_end'=>0, 'banner_level'=>-1,
        'banner_level_type'=>2, 'banner_comment'=>'Private administrator note', 'banner_type'=>2,
        'banner_width'=>0, 'banner_height'=>0, 'banner_filter'=>0, 'banner_filter_time'=>600), $changes);
    $sql = array();
    foreach ($values as $key=>$value) { $sql[] = $key . '=' . (is_int($value) ? $value : "'" . $GLOBALS['tn_peer']->sql_escape($value) . "'"); }
    tn_sql('INSERT INTO fixture_banner SET ' . implode(',', $sql));
}

function ob_render($controller, $allowed)
{
    $before = tp_snapshot();
    $context = on_call();
    $body = on_controller($controller);
    nt_check(isset($context['banners'][1]) === $allowed, 'Current banner audience projection');
    nt_check((strpos($body['html'], 'Public &amp; Grüße 😀') !== false) === $allowed, 'Actual XS banner description follows current audience');
    nt_check((strpos($body['html'], 'redirect.php?banner_id=1') !== false) === $allowed, 'No link to an unavailable banner');
    nt_check(strpos($body['html'], 'Private administrator note') === false && strpos($body['html'], '&amp;amp;') === false, 'No internal note or double-escaped stored text');
    nt_check(tp_snapshot() === $before && tp_released(), 'Actual banner view is read-only and releases its owner');
}

function tp_online_banners()
{
    global $userdata, $tn_app, $tp_hook, $db, $board_config;
    $source = file_get_contents($tn_app . 'viewonline.php');
    $a = strpos($source, '// Read sessions, current viewer rights');
    $b = strpos($source, "include(\$phpbb_root_path . 'includes/page_tail.'", $a);
    nt_check($a !== false && $b > $a, 'Actual complete online controller and XS template');
    $controller = substr($source, $a, $b-$a);
    $cases = $faults = $views = 0;
    foreach (array('guest', USER, MOD, ADMIN) as $role) {
        foreach (array(0,1,2,3) as $comparison) {
            foreach (array(-1,0,1,2) as $level) {
                ob_prepare();
                if ($role === 'guest') { $userdata = array('user_id'=>ANONYMOUS, 'user_level'=>ADMIN, 'session_logged_in'=>false); $actual = -1; }
                else { tn_sql('UPDATE fixture_users SET user_level=' . $role . ' WHERE user_id=7'); $actual = $role === ADMIN ? MOD : ($role === MOD ? ADMIN : USER); }
                tn_sql('UPDATE fixture_banner SET banner_level=' . $level . ',banner_level_type=' . $comparison);
                $allowed = $comparison === 0 ? $actual === $level : ($comparison === 1 ? $actual <= $level : ($comparison === 2 ? $actual >= $level : $actual !== $level));
                ob_render($controller, $allowed); $cases++; $views++;
            }
        }
    }
    foreach (array('english','german') as $locale) {
        foreach (array('ordinary','inactive','deleted','missing','private','no-view','no-read','unknown-forum','public-forum','demoted','promoted','logged-out','banned','blocked','invalid-actor','empty-description') as $kind) {
            ob_prepare($locale);
            $allowed = in_array($kind, array('ordinary','public-forum','promoted','logged-out','banned','blocked','invalid-actor','empty-description'), true);
            if ($kind === 'inactive') { tn_sql('UPDATE fixture_banner SET banner_active=0'); }
            elseif ($kind === 'deleted') { tn_sql('DELETE FROM fixture_banner'); }
            elseif ($kind === 'missing') { tn_sql('UPDATE fixture_sessions SET session_topic=99 WHERE session_user_id=9'); }
            elseif (in_array($kind,array('private','no-view','no-read','unknown-forum','public-forum'),true)) {
                tn_sql('UPDATE fixture_banner SET banner_forum=' . ($kind==='private'?9:($kind==='unknown-forum'?88:3)));
                if ($kind==='no-view') { tn_sql('UPDATE fixture_forums SET auth_view=5 WHERE forum_id=3'); }
                if ($kind==='no-read') { tn_sql('UPDATE fixture_forums SET auth_read=5 WHERE forum_id=3'); }
            }
            elseif ($kind==='demoted' || $kind==='promoted') {
                $userdata['user_level'] = $kind==='demoted'?ADMIN:USER;
                tn_sql('UPDATE fixture_users SET user_level=' . ($kind==='promoted'?ADMIN:USER) . ' WHERE user_id=7');
                tn_sql('UPDATE fixture_banner SET banner_level=' . MOD . ',banner_level_type=0');
            }
            elseif ($kind==='logged-out') { tn_sql('UPDATE fixture_sessions SET session_logged_in=0 WHERE session_user_id=7'); }
            elseif ($kind==='banned') { tn_sql("INSERT INTO fixture_banlist(ban_userid,ban_ip,ban_email) VALUES(7,'','')"); }
            elseif ($kind==='blocked') { tn_sql('UPDATE fixture_users SET user_blocktime=' . (time()+3600) . ' WHERE user_id=7'); }
            elseif ($kind==='invalid-actor') { $userdata['session_id'] = array('invalid'); }
            elseif ($kind==='empty-description') { tn_sql("UPDATE fixture_banner SET banner_description=''"); }
            if ($kind==='missing' || $kind==='empty-description') {
                $before=tp_snapshot();$context=on_call();$body=on_controller($controller);
                nt_check($kind==='missing' ? !$context['banners'] : isset($context['banners'][1]), 'Absent banner versus legitimate empty description');
                nt_check((strpos($body['html'],'redirect.php?banner_id=1')!==false) === ($kind==='empty-description') && strpos($body['html'],'banner_id=99')===false && tp_snapshot()===$before && tp_released(), 'Actual missing/empty-description fallback stays usable');
            } else { ob_render($controller,$allowed); }
            $cases++;$views++;
            if (in_array($kind,array('logged-out','banned','blocked','invalid-actor'),true)) {
                tn_sql('UPDATE fixture_banner SET banner_level=' . MOD . ',banner_level_type=0');
                ob_render($controller,false);$cases++;$views++;
            }
        }
        ob_prepare($locale);ob_add(2,array('banner_description'=>'Second public link'));tn_sql('UPDATE fixture_users SET user_allow_viewonline=1 WHERE user_id=10');tn_sql('UPDATE fixture_sessions SET session_page=' . PAGE_REDIRECT . ',session_topic=2 WHERE session_user_id=10');
        $before=tp_snapshot();$body=on_controller($controller);
        nt_check(strpos($body['html'],'Second public link')!==false && substr_count($body['html'],'redirect.php?banner_id=1')===1 && substr_count($body['html'],'redirect.php?banner_id=2')===1 && tp_snapshot()===$before && tp_released(), 'Multiple banner activities retain their own current descriptions');$cases++;$views++;
    }
    // Match the existing display schedule in the configured board timezone.
    foreach(array(2,4,6)as$type){foreach(array(false,true)as$within){
        ob_prepare();$board_config['board_timezone']=5.5;$now=time();$hour=(int)create_date('Hi',$now,5.5);$day=(int)create_date($type===4?'w':'Ymd',$now,5.5);
        tn_sql('UPDATE fixture_banner SET banner_timetype='.$type.',time_begin=0,time_end=2359,date_begin='.$day.',date_end='.$day);
        if(!$within){tn_sql('UPDATE fixture_banner SET '.($type===2?'time_begin=2400,time_end=2500':'date_begin='.($day+1).',date_end='.($day+1)));}
        ob_render($controller,$within);$cases++;$views++;
    }}
    foreach(array('hidden','expired','future','guest-retained')as$kind){
        ob_prepare();$allowed=false;
        if($kind==='hidden'){tn_sql('UPDATE fixture_users SET user_allow_viewonline=0 WHERE user_id=9');}
        elseif($kind==='expired'||$kind==='future'){tn_sql('UPDATE fixture_sessions SET session_time='.($kind==='expired'?0:time()+600).' WHERE session_user_id=9');}
        else{tn_sql('UPDATE fixture_sessions SET session_logged_in=0 WHERE session_user_id=9');$allowed=true;}
        ob_render($controller,$allowed);$cases++;$views++;
    }
    foreach(array('engine','collation','missing')as$kind){
        ob_prepare();if($kind==='engine'){tn_sql('ALTER TABLE fixture_banner ENGINE=MyISAM');}elseif($kind==='collation'){tn_sql('ALTER TABLE fixture_banner CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');}else{tn_sql('RENAME TABLE fixture_banner TO fixture_banner_saved');}
        try{$before=$kind==='missing'?null:tp_snapshot();nt_check(on_error()&&($before===null||tp_snapshot()===$before)&&tp_released(),'Optional banner participant rejects noncanonical storage: '.$kind);}
        finally{if($kind==='engine'){tn_sql('ALTER TABLE fixture_banner ENGINE=InnoDB ROW_FORMAT=DYNAMIC');}elseif($kind==='collation'){tn_sql('ALTER TABLE fixture_banner CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');}else{tn_sql('RENAME TABLE fixture_banner_saved TO fixture_banner');}}$cases++;
    }
    ob_prepare();$trace=array();$tp_hook=function($phase,$sql)use(&$trace){if($phase==='before'){$trace[]=$sql;}};on_call();$tp_hook=null;
    $bannerStart=null;foreach($trace as$index=>$sql){if(strpos($sql,'fixture_banner')!==false){$bannerStart=$index-1;break;}}
    nt_check($bannerStart!==null,'Actual optional banner SQL trace');
    foreach($trace as$index=>$sql){if($index<$bannerStart||$sql==='ROLLBACK'){continue;}foreach(array('before','after')as$phase){
        ob_prepare();$before=tp_snapshot();$seen=0;$reached=false;$tp_hook=function($event,$statement)use($index,$phase,&$seen,&$reached){if($event==='before'){$seen++;}if($event===$phase&&$seen===$index+1){$reached=true;return false;}};
        try{nt_check(on_error()&&$reached&&tp_snapshot()===$before&&tp_released(),'Every additional banner pin/metadata/select SQL boundary fails closed');}finally{$tp_hook=null;}$faults++;
    }}
    ob_prepare();$changed=false;$tp_hook=function($phase,$sql)use(&$changed){if($phase==='after'&&strpos($sql,'SELECT a.forum_id,')===0){$GLOBALS['tp_hook']=null;tn_sql('UPDATE fixture_banner SET banner_active=0');$changed=true;}};
    try{$context=on_call();nt_check($changed&&isset($context['banners'][1])&&tp_released(),'Banner participant joins the same preexisting current viewer/session snapshot');nt_check(!on_call()['banners']&&tp_released(),'Next snapshot observes the completed banner deactivation');}finally{$tp_hook=null;}$cases++;
    echo 'Native online banners: '.$cases.' cases, '.$faults.' SQL faults, '.$views." actual views PASS; current audiences, forum ACLs, schedules, canonical optional participants, read-only snapshots and EN/DE labels.\n";
}
