<?php
require __DIR__ . '/statistics-period-fixture.php';
require $sp_root.'includes/functions_post_subject.php';
foreach (array('POST_USERS_URL'=>'u','POST_POST_URL'=>'p','AUTH_READ'=>2,'AUTH_DOWNLOAD'=>20,'AUTH_LIST_ALL'=>0,
    'ATTACH_VERSION'=>'2.4.3','FORUMS_TABLE'=>'fixture_forums','TOPICS_TABLE'=>'fixture_topics',
    'ATTACHMENTS_TABLE'=>'fixture_attachments','ATTACHMENTS_DESC_TABLE'=>'fixture_attachments_desc','CONFIG_TABLE'=>'fixture_config') as $key=>$value) { define($key,$value); }
sp_check(preg_match('/^function get_db_stat\(.*?^\}/ms', $sp_functions, $sr_match) === 1, 'Actual newest-account reader'); eval($sr_match[0]);
$sr_cases = 0;
function sr_text($html) { return html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'); }
function sr_stored_topic($raw){return phpbb_storage_subject(html_entity_decode(htmlspecialchars($raw,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'),ENT_QUOTES,'UTF-8'));}
function sr_context($file) {
    global $board_config,$lang,$phpbb_root_path,$phpEx,$userdata;
    $GLOBALS['sp_clock'] = strtotime('2026-11-08 12:00:00 UTC');
    $board_config = array('default_lang'=>'english','board_timezone'=>0,'default_dateformat'=>'Y-m-d','hide_removed_author_names'=>1);
    $phpbb_root_path = $GLOBALS['sp_root']; $phpEx = 'php'; $lang = array();
    $source = file_get_contents($phpbb_root_path . 'stat_modules/' . $file . '/module.php');
    preg_match_all('/\$lang\[\'([^\']+)\'\]/', $source, $labels);
    foreach ($labels[1] as $key) { $lang[$key] = $key; }
    return $source;
}
function sr_ranking($kind, $cache = null) {
    global $lang,$board_config,$phpbb_root_path;
    $source = sr_context($kind === 'all' ? 'top_posters' : 'site_hist_'.$kind.'_top_posters'); $statistics = new StatisticsPeriodBars(); $template = new StatisticsPeriodTemplate();
    $stat_db = new StatisticsDB(); $stat_db->begin_cached_query($cache !== null, $cache === null ? '' : $cache); $return_limit = 10; $theme = array('td_class1'=>'row1','td_class2'=>'row2');
    eval(sp_freeze(substr($source,5)));
    $GLOBALS['sr_last_cache'] = addslashes(serialize(array('n'=>$stat_db->numrows_data,'fs'=>$stat_db->fetchrowset_data,'f'=>$stat_db->fetchrow_data)));
    return $template;
}
function sr_newest() {
    global $lang,$board_config,$phpEx;
    $source = sr_context('admin_statistics');
    $a = strpos($source, '$newest_userdata = get_db_stat');
    $b = strpos($source, '// Disk Usage,', $a);
    sp_check($a !== false && $b > $a, 'Complete actual newest-account queries and value-array binding');
    $stat_db = new StatisticsDB(); $stat_db->begin_cached_query();
    $total_posts=$posts_per_day=$total_topics=$topics_per_day=$total_users=$users_per_day=$boarddays=1;
    $start_date=$dbsize=$avatar_dir_size='fixture'; $board_config['gzip_compress']=0;
    eval(sp_freeze(substr($source,$a,$b-$a)));
    return array($newest_uid,$newest_user,$value_array[11]);
}
function auth($type,$scope,$viewer) {
    sp_check($scope === AUTH_LIST_ALL && in_array($type,array(AUTH_READ,AUTH_DOWNLOAD),true), 'Actual attachment module requests both ACL sets');
    return $GLOBALS['sr_acl'][$type];
}
function sr_attachments($allowed = true) {
    global $db,$lang,$board_config,$phpbb_root_path,$phpEx,$userdata;
    $source = sr_context('top_attachments'); $template = new StatisticsPeriodTemplate();
    $stat_db = new StatisticsDB(); $stat_db->begin_cached_query(); $return_limit=10; $theme=array('td_class1'=>'row1','td_class2'=>'row2');
    $GLOBALS['sr_acl'] = array(AUTH_READ=>array(),AUTH_DOWNLOAD=>array());
    foreach (array(3,8,9,10) as $forum) {
        $GLOBALS['sr_acl'][AUTH_READ][$forum] = array('auth_read'=>$allowed && in_array($forum,array(3,9),true));
        $GLOBALS['sr_acl'][AUTH_DOWNLOAD][$forum] = array('auth_download'=>$allowed && in_array($forum,array(3,10),true));
    }
    eval(sp_freeze(substr($source,5))); return $template;
}
function sr_name_suite($set_name) {
    global $userdata,$sr_cases;
    foreach (array('A&B',"O'Reilly",'&#39;','&amp;','A<"B','Grüße 😀','0',"A\\'B") as $raw) {
        foreach (array(ENT_QUOTES,ENT_COMPAT) as $flags) {
            $key = phpbb_username_key($raw,$flags); sp_check($key !== '', 'Valid canonical or historical account key'); call_user_func($set_name,$key);
            foreach (array(false,true) as $logged) {
                $userdata = array('user_id'=>$logged?4:-1,'session_logged_in'=>$logged);
                foreach (array('week','month','all') as $kind) {
                    $GLOBALS['sp_clock']=strtotime('2026-11-08 12:00:00 UTC'); $template=sr_ranking($kind);
                    sp_check(count($template->blocks)===2, 'Existing account selection unchanged');
                    $row=$template->blocks[0]; sp_check(sr_text($row['USERNAME'])===$raw, 'Stored identity displays exactly one decoded layer');
                    sp_check(strpos($row['USERNAME'],'<')===false && strpos($row['USERNAME'],'"')===false, 'Account markup and quotes remain escaped');
                    sp_check(strpos($row['URL'],'u=7')!==false && $row['POSTS']===3 && $row['RANK']===1, 'Profile identity/ranking/count unchanged');
                    sp_check(sr_text($template->blocks[1]['USERNAME'])==='Other' && $template->blocks[1]['POSTS']===1, 'Other account remains separate'); $sr_cases++;
                }
                $latest=sr_newest(); sp_check((int)$latest[0]===7 && sr_text($latest[1])===$raw, 'Actual newest-account reader and label preserve identity');
                sp_check(strpos($latest[2],'u=7')!==false && sr_text($latest[2])===$raw, 'Actual administrative value-array link is safe and correct'); $sr_cases++;
            }
        }
    }
}
class StatisticsRenderingForums {
    var $allowed;
    function __construct($allowed){$this->allowed=$allowed;}
    function forum_auth($viewer){return $this->allowed?'3':'';}
}
function sr_topic_list($kind,$allowed=true){
    global $db,$lang,$board_config,$phpbb_root_path,$phpEx,$userdata;
    $source=sr_context($kind);$statistics=new StatisticsRenderingForums($allowed);$template=new StatisticsPeriodTemplate();$return_limit=10;$theme=array('td_class1'=>'row1','td_class2'=>'row2');
    eval(sp_freeze(substr($source,5)));return $template;
}
function sr_topic_title_suite($set_title){
    global $userdata;$cases=0;
    foreach(array(false,true)as$logged){$userdata=array('user_id'=>$logged?4:-1,'session_logged_in'=>$logged);
        foreach(array('A&B','&amp;','&#39;','<b>A "quoted"</b>','Grüße 😀','0','',str_repeat('ä',29).'&😀tail')as$raw){call_user_func($set_title,$raw);
            foreach(array('most_viewed_topics'=>'VIEWS','most_active_topics'=>'REPLIES')as$kind=>$metric){$template=sr_topic_list($kind);sp_check(count($template->blocks)===1,'Actual topic module returns only its authorized row');$row=$template->blocks[0];sp_check(sr_text($row['TITLE'])===$raw&&strpos($row['TITLE'],'<')===false,'Stored topic title displays one layer with escaped markup');sp_check($row[$metric]===($metric==='VIEWS'?9:5)&&$row['RANK']===1&&strpos($row['URL'],'viewtopic.php?t=1')!==false,'Topic ranking, count and numeric link unchanged');$cases++;}
        }
        foreach(array('most_viewed_topics','most_active_topics')as$kind){sp_check(!sr_topic_list($kind,false)->blocks,'No authorized forum means no topic rows');$cases++;}
    }
    echo 'Statistics topic titles: ',$cases," actual-module cases PASS; stored entities, literal entities, quotes, markup, UTF-8, guest/member views, original ranking and forum scope.\n";
}
function sr_excerpt_cases() {
    $short = str_repeat('ä',14).'😀-lange-Datei.txt';
    return array(array('', '',false),array('A&B &#39; &amp; <tag>','A&B &#39; &amp; <tag>',false),
        array(str_repeat('a',32),str_repeat('a',32),false),array(str_repeat('a',33),str_repeat('a',30).'...',true),
        array(str_repeat('ä',32),str_repeat('ä',32),false),array(str_repeat('ä',33),str_repeat('ä',30).'...',true),
        array($short,$short,false),array(str_repeat('a',29).'ä-anderer-Text',str_repeat('a',29).'ä...',true),
        array(str_repeat('漢',20).'😀'.str_repeat('x',20),str_repeat('漢',20).'😀'.str_repeat('x',9).'...',true),
        array(str_repeat('&amp;',7),str_repeat('&amp;',6).'...',true),
        array(str_repeat('x',28).'<img src=x onerror="fixture">',str_repeat('x',28).'<i...',true),
        array(str_repeat("ä\n",17),str_repeat("ä\n",15).'...',true));
}
function sr_cache_suite($rename, $retire, $restore) {
    global $sr_cases,$db;
    foreach (array('week','month','all') as $kind) {
        call_user_func($restore); call_user_func($rename,phpbb_username_key('Old account'));
        sr_ranking($kind); $cache=$GLOBALS['sr_last_cache']; call_user_func($rename,phpbb_username_key('A&B'));
        $renamed=sr_ranking($kind,$cache); sp_check(sr_text($renamed->blocks[0]['USERNAME'])==='A&B', 'Actual old StatisticsDB cache still refreshes renamed account identity');
        sp_check(strpos($renamed->blocks[0]['URL'],'u=7')!==false && $renamed->blocks[0]['POSTS']===3, 'Cached aggregates keep original owner and count'); $sr_cases++;
        call_user_func($retire); $retired=sr_ranking($kind,$cache);
        sp_check(count($retired->blocks)===1 && sr_text($retired->blocks[0]['USERNAME'])==='Other', 'Retired cached owner omitted even if a new account reuses that name');
        sp_check($retired->blocks[0]['RANK']===1 && strpos($retired->blocks[0]['URL'],'u=6')!==false, 'Remaining ranks are contiguous and link to their real owner'); $sr_cases++;
        call_user_func($restore); $db->fail_current=true; $caught=false;
        try { sr_ranking($kind,$cache); } catch (StatisticsPeriodExit $error) { $caught=true; }
        $db->fail_current=false; sp_check($caught,'Current identity read failure cannot fall back to exposing cached names'); $sr_cases++;
    }
    call_user_func($restore);
}
function sr_check_excerpt($template,$case) {
    global $sr_cases;
    sp_check(count($template->blocks)===1, 'Only attachment with both read and download access appears'); $row=$template->blocks[0];
    $full=htmlspecialchars($case[0],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    foreach (array('POST_TITLE','VIEW_ATTACHMENT','COMMENT') as $field) {
        $expected=$case;$field_full=$full;if($field==='POST_TITLE'){$raw=html_entity_decode(sr_stored_topic($case[0]),ENT_QUOTES,'UTF-8');$excerpt=statistics_text_excerpt($raw);$expected=array($raw,$excerpt!==''?$excerpt:$raw,$excerpt!=='');$field_full=htmlspecialchars($raw,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
        $html=$row[$field]; sp_check(sr_text($html)===$expected[1], 'Actual title/file/comment excerpt counts UTF-8 characters');
        sp_check(preg_match('//u',$html)===1 && strpos($html,'<img')===false && strpos($html,'onerror="')===false, 'Actual emitted HTML is valid UTF-8 and cannot turn raw text into attributes/markup');
        sp_check($expected[2] ? strpos($html,'title="'.$field_full.'"')!==false : strpos($html,'title=')===false, 'Complete raw-text tooltip only when shortened');
    }
    sp_check($row['FILENAME']===$full, 'Full filename keeps literal entities and HTML escaping');
    sp_check(strpos($row['POST_TITLE'],'viewtopic.php?p=1#1')!==false && strpos($row['VIEW_ATTACHMENT'],'download.php?id=11')!==false, 'Actual topic/download IDs unchanged');
    sp_check($row['DOWNLOAD_COUNT']===9 && $row['SIZE']===2.0, 'Download statistics unchanged'); $sr_cases++;
}
